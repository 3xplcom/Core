<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process UTXO transfers of native tokens for Cardano-like chains.
 *  Requires a fully synced `input-output-hk/cardano-db-sync` database to operate. Database schema for querying:
 *  https://github.com/input-output-hk/cardano-db-sync/blob/master/doc/schema.md  */

abstract class CardanoLikeNativeTokensModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'currency'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $allow_empty_return_events = true;

    public ?array $currencies_table_fields = ['id', 'name', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    //

    public ?\PgSql\Connection $db = null;
    public ?string $block_time = null;
    public ?int $block_db_id = null;
    public ?int $transaction_count = null;

    //

    public ?string $metadata_registry = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        $this->metadata_registry = envm(
            $this->module,
            'METADATA_REGISTRY',
            new DeveloperError('Metadata registry is not set in the config')
        );
    }

    private function db_connect()
    {
        if (is_null($this->db))
        {
            $this->db = pg_pconnect($this->select_node());
            $timeout_ms = envm($this->module, 'REQUESTER_TIMEOUT') * 1000;
            pg_query($this->db, "SET statement_timeout = {$timeout_ms}");
        }
    }

    //

    final public function inquire_latest_block()
    {
        $this->db_connect();
        return (int)pg_fetch_assoc(pg_query($this->db, 'SELECT max(block_no) FROM block'))['max'];
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $this->db_connect();

        $block = pg_fetch_assoc(pg_query_params($this->db, 'SELECT id, hash, time, tx_count FROM block WHERE block_no = $1', [$block_id]));
        $this->block_hash = substr($block['hash'], 2);
        $this->block_time = $block['time'];
        $this->block_db_id = (int)$block['id'];
        $this->transaction_count = (int)$block['tx_count'];

        // We don't really check if all our nodes have the same block here as we always use one db connection to
        // retrieve data within a single block anyways, so this one won't be throwing ConsensusException
    }

    final public function pre_process_block($block_id)
    {
        $events = [];
        $block = [];
        $this->db_connect();

        $transactions = pg_fetch_all(pg_query_params($this->db,
            'SELECT id, hash, out_sum, fee, deposit FROM tx WHERE block_id = $1 ORDER BY block_index', [$this->block_db_id]));
        // or this can be done via a subquery like this: WHERE block_id = (SELECT id FROM block WHERE block_no = $1),
        // and $block_id is the param

        if (count($transactions) !== $this->transaction_count)
            throw new ConsensusException('Transaction count mismatch (orphaned block?)');

        if (!$transactions)
        {
            $this->set_return_events([]);
            $this->set_return_currencies([]);
            return;
        }

        $in_query = [];

        foreach ($transactions as $transaction)
        {
            $block[($transaction['id'])] = $transaction;
            $block[($transaction['id'])]['inputs'] = [];
            $block[($transaction['id'])]['outputs'] = [];
            $in_query[] = $transaction['id'];
        }

        // orphan-guards
        $known_txids = [
            'outs'   => [],
            'ins'    => [],
            'deltas' => [],
        ];
        $total_asset_movements = '0';

        $in_query = implode(', ', $in_query);

        $outs = pg_fetch_all(pg_query($this->db,
            "SELECT tx_out.tx_id, tx_out.address, multi_asset.fingerprint, ma_tx_out.ident AS token, ma_tx_out.quantity
                    FROM tx_out
                    JOIN ma_tx_out ON (ma_tx_out.tx_out_id = tx_out.id)
                    JOIN multi_asset ON ma_tx_out.ident = multi_asset.id
                    WHERE tx_out.tx_id in ({$in_query})
                    ORDER BY tx_out.tx_id, ma_tx_out.id")); // order of sorting: by transaction > by multi-asset component of that transaction

        foreach ($outs as $out)
        {
            $block[($out['tx_id'])]['outputs'][] = $out;
            $known_txids['outs'][($out['tx_id'])] = 1;
            $total_asset_movements = bcsub($total_asset_movements, $out['quantity']);
        }

        // Obtain outputs that were spent in given block
        // Due to the way foreign keys are chosen in relevant tables, 3-table-join ends up 8-10 times slower than this:
        $ins_1 = pg_fetch_all(pg_query($this->db,
            "SELECT tx_in.tx_in_id, tx_out.address, tx_in.id as orderby, tx_out.id as nextkey
                    FROM tx_in
                    JOIN tx_out ON (tx_in.tx_out_id = tx_out.tx_id AND tx_in.tx_out_index = tx_out.index)
                    WHERE tx_in.tx_in_id IN ({$in_query})"));

        if (count($ins_1) < 1)
        {
            $this->set_return_events([]);
            $this->set_return_currencies([]);
            return;
        }

        $ins_dict = [];
        $subquery = [];

        // rearrange spent outputs as a map for improved lookup at a later stage
        foreach ($ins_1 as $aux)
        {
            $ins_dict[$aux['nextkey']] = $aux;
            $subquery[] = $aux['nextkey'];
        }

        $subquery = implode(', ', $subquery);

        // obtain multi-asset components associated to outputs from previous step
        $ins_2 = pg_fetch_all(pg_query($this->db,
            "SELECT ma_tx_out.tx_out_id as id, multi_asset.fingerprint, ident AS token, ma_tx_out.quantity, ma_tx_out.id as order_inner
                    FROM ma_tx_out
                    JOIN multi_asset ON ma_tx_out.ident = multi_asset.id
                    WHERE tx_out_id in ({$subquery})"));

        // merge generic input info with MA-specific

        $ins = [];

        foreach ($ins_2 as $ma_specific)
        {
            $ins[] = [
                'tx_in_id'    => $ins_dict[$ma_specific['id']]['tx_in_id'], // columns ins_1.nextkey and ins_2.id
                'address'     => $ins_dict[$ma_specific['id']]['address'], // act as pivots for that merger
                'orderby'     => $ins_dict[$ma_specific['id']]['orderby'], // equivalent to sql 3-table-join under tx_out.id = ma_tx_out.tx_out_id
                'order_inner' => $ma_specific['order_inner'],
                'fingerprint' => $ma_specific['fingerprint'],
                'token'       => $ma_specific['token'],
                'quantity'    => $ma_specific['quantity'],
            ];
        }

        // manually sort data to achieve consistent ordering with outputs
        array_multisort(
            array_column($ins, 'orderby'), SORT_ASC, // first, by transaction
            array_column($ins, 'order_inner'), SORT_ASC, // then, by multi-asset component within said transaction
            $ins
        );

        unset($ins_1);
        unset($ins_2);
        unset($ins_dict);
        unset($subquery);

        // this works under assumption that one cannot transfer tokens without transferring ada (ie MA-outs are strictly a subset of ADA-outs)

        foreach ($ins as $in)
        {
            $block[($in['tx_in_id'])]['inputs'][] = $in;
            $known_txids['ins'][($in['tx_in_id'])] = 1;
            $total_asset_movements = bcadd($total_asset_movements, $in['quantity']);
        }

        $deltas = pg_fetch_all(pg_query($this->db,
            "SELECT ma_tx_mint.tx_id, multi_asset.fingerprint, ident AS token, ma_tx_mint.quantity
                    FROM ma_tx_mint
                    JOIN multi_asset ON ma_tx_mint.ident = multi_asset.id
                    WHERE tx_id IN ({$in_query})
                    ORDER BY ma_tx_mint.id"));

        foreach ($deltas as $delta)
        {
            if (str_contains($delta['quantity'], '-'))
            {
                $block[($delta['tx_id'])]['burns'][] = $delta;
            }
            else
            {
                $block[($delta['tx_id'])]['mints'][] = $delta;
            }
            $known_txids['deltas'][($delta['tx_id'])] = 1;
            $total_asset_movements = bcadd($total_asset_movements, $delta['quantity']);
        }

        // Checking for Orphans

        // should ins & outs query yeild mismatched data (ie db records get erased between our queries) - throw and retry
        // here, in-db tx-ids are sufficient check, bcz UTXO
        if (count(array_diff_assoc($known_txids['ins'], $known_txids['outs'], $known_txids['deltas'])) +
            count(array_diff_assoc($known_txids['outs'], $known_txids['ins'], $known_txids['deltas'])) > 0) {
            throw new ConsensusException('Transactions used mismatch (orphaned block?)');

        }

        // deltas need special check, as there's no obligation for them to be present, using similar logic to cardano-core - sum over netflow:
        if ($total_asset_movements !== '0')
            throw new ConsensusException('Transactions net flow mismatch (orphaned block?)');

        //

        $sort_key = 0;
        $currencies_used = [];

        foreach ($block as $transaction)
        {
            // Generating events
            foreach ($transaction['mints'] ?? [] as $mint)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => 'the-void',
                             'effect'      => '-' . $mint['quantity'], // inverted representation: mint = void losing assets
                             'currency'    => $mint['fingerprint'],
                             'sort_key'    => $sort_key++,
                ];

                $currencies_used[$mint['token']] = 1;
            }

            foreach ($transaction['inputs'] as $input)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => $input['address'],
                             'effect'      => '-' . $input['quantity'],
                             'currency'    => $input['fingerprint'],
                             'sort_key'    => $sort_key++,
                ];

                $currencies_used[$input['token']] = 1;
            }

            foreach ($transaction['burns'] ?? [] as $burn)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => 'the-void',
                             'effect'      => substr($burn['quantity'], 1), // inverted representation: burn = void gaining assets
                             'currency'    => $burn['fingerprint'],
                             'sort_key'    => $sort_key++,
                ];

                $currencies_used[$burn['token']] = 1;
            }

            foreach ($transaction['outputs'] as $output)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => $output['address'],
                             'effect'      => $output['quantity'],
                             'currency'    => $output['fingerprint'],
                             'sort_key'    => $sort_key++,
                ];

                $currencies_used[$output['token']] = 1;
            }
        }

        //

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);

        // process currencies

        if (count($currencies_used) < 1)
        {
            $this->set_return_currencies([]);
            return;
        }

        $in_query = implode(', ', array_keys($currencies_used));

        $currencies_used = pg_fetch_all(pg_query($this->db,
            "SELECT fingerprint, encode(name, 'hex') as hexname, encode(name, 'escape') as name, encode(policy, 'hex') as policy
                    FROM multi_asset
                    WHERE id in ({$in_query})"));

        foreach ($currencies_used as $r)
            $this_to_check[] = $r['fingerprint'];

        $checking = check_existing_currencies($this_to_check, $this->currency_format); // Removes already known currencies

        $currencies = [];

        foreach ($currencies_used as $currency)
        {
            if (!in_array($currency['fingerprint'], $checking))
                continue;

            // ask off-chain metadata registry for details

            $metadata = [];

            try
            {
                $metadata = requester_single($this->metadata_registry . $currency['policy'] . $currency['hexname']);
            }
            catch (Exception $e)
            {
                // due to nature of GET, 404 is valid for our purpuses (no metadata) but response is not json-encoded, hence this
                // also, for the same reason I cannot use multicurl, the whole batch will die
                if (substr($e->getMessage(), -3) !== "404") {
                    throw $e;
                }
            }

            $decimals = 0;

            if (array_key_exists('decimals', $metadata))
                if (array_key_exists('value', $metadata['decimals']))
                    $decimals = intval($metadata['decimals']['value']);

            // null the gibberish names
            $currency['name'] = preg_replace('/\\\\\d\d\d/', '', $currency['name']);
            $currency['name'] = preg_replace('/[\x00-\x1F\x7F]/u', '', $currency['name']);

            $currencies[] = [
                'id'       => $currency['fingerprint'],
                'name'     => $currency['name'],
                'decimals' => $decimals
            ];
        }

        $this->set_return_currencies($currencies);
    }
}
