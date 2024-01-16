<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process UTXO transfers for Cardano-like chains. It doesn't process staking transactions, if there
 *  is a discrepancy in sum(inputs) - sum(outputs) !== fee, the difference goes to the special `staking-pool` address.
 *  Requires a fully synced `input-output-hk/cardano-db-sync` database to operate. Database schema for querying:
 *  https://github.com/input-output-hk/cardano-db-sync/blob/master/doc/schema.md  */

abstract class CardanoLikeMainModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::LastEventToTheVoid;
    public ?array $special_addresses = ['the-void', 'staking-pool'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    //

    public ?\PgSql\Connection $db = null;
    public ?string $block_time = null;
    public ?int $block_db_id = null;
    public ?int $transaction_count = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
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
        
        $block = pg_fetch_assoc(pg_query_params($this->db,
            'SELECT id, hash, time, tx_count FROM block WHERE block_no = $1', [$block_id]));
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

        $in_query = implode(', ', $in_query);

        $outs = pg_fetch_all(pg_query($this->db,
            "SELECT tx_id, address, value
                    FROM tx_out
                    WHERE tx_id IN ({$in_query})
                    ORDER BY tx_id, index"));

        foreach ($outs as $out)
        {
            $block[($out['tx_id'])]['outputs'][] = $out;
        }

        $ins = pg_fetch_all(pg_query($this->db,
            "SELECT tx_in.tx_in_id, tx_out.address, tx_out.value
                    FROM tx_in
                    JOIN tx_out ON (tx_in.tx_out_id = tx_out.tx_id AND tx_in.tx_out_index = tx_out.index)                         
                    WHERE tx_in.tx_in_id IN ({$in_query})
                    ORDER BY tx_in.id"));

        foreach ($ins as $in)
        {
            $block[($in['tx_in_id'])]['inputs'][] = $in;
        }

        //

        $sort_key = 0;

        foreach ($block as $transaction)
        {
            $check_input_sum = $check_output_sum = '0';

            foreach ($transaction['inputs'] as $input)
                $check_input_sum = bcadd($check_input_sum, $input['value']);

            foreach ($transaction['outputs'] as $output)
                $check_output_sum = bcadd($check_output_sum, $output['value']);

            if ($check_output_sum !== $transaction['out_sum'])
                throw new ConsensusException('Transaction output sum mismatch (orphaned block?)');

            $staking_pool_effect = bcsub(bcsub($check_input_sum, $check_output_sum), $transaction['fee']);

            // Generating events

            foreach ($transaction['inputs'] as $input)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => $input['address'],
                             'effect'      => "-" . $input['value'],
                             'sort_key'    => $sort_key++,
                ];
            }

            if ($staking_pool_effect !== '0' && str_contains($staking_pool_effect, '-'))
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => 'staking-pool',
                             'effect'      => $staking_pool_effect,
                             'sort_key'    => $sort_key++,
                ];
            }

            foreach ($transaction['outputs'] as $output)
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => $output['address'],
                             'effect'      => $output['value'],
                             'sort_key'    => $sort_key++,
                ];
            }

            if ($staking_pool_effect !== '0' && !str_contains($staking_pool_effect, '-'))
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => 'staking-pool',
                             'effect'      => $staking_pool_effect,
                             'sort_key'    => $sort_key++,
                ];
            }

            if ($transaction['fee'] !== '0')
            {
                $events[] = ['transaction' => substr($transaction['hash'], 2),
                             'address'     => 'the-void',
                             'effect'      => $transaction['fee'],
                             'sort_key'    => $sort_key++,
                ];
            }
        }

        //

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
