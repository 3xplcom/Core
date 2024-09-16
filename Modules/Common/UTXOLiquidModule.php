<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes UTXO Assets (including L-BTC) transfers for Liquid Blockchain.  */

abstract class UTXOLiquidModule extends CoreModule
{
    use UTXOTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWithout0x;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::LastEventToTheVoid;
    public ?array $special_addresses = ['the-void', 'the-bridge']; // `the-void` is for coinbase/fee events, `the-bridge` is for pegin/pegout events
    public ?PrivacyModel $privacy_model = PrivacyModel::Mixed;

    public ?array $events_table_fields = ['block', 'transaction', 'currency', 'sort_key', 'time', 'address', 'effect', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['currency', 'extra', 'extra_indexed'];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = ['name', 'symbol'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $ignore_sum_of_all_effects = true; // PrivacyModel::Mixed

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;

    public ?array $extra_data_details = [
        'pi' => 'Peg-in transaction',
        'po' => 'Peg-out transaction',
    ];

    // For back compatibility with UTXO traits
    public array $extra_features = [];

    // Liquid-specific

    public ?string $native_asset = null;
    public ? array $native_asset_meta = null;
    public ?string $asset_registry = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->native_asset))
            throw new DeveloperError("Native asset is not set");

        if (is_null($this->native_asset_meta))
            throw new DeveloperError("Native asset meta is not set");

        $this->asset_registry = envm(
            $this->module,
            'ASSETS_REGISTRY',
            new DeveloperError('ASSETS_REGISTRY not set in the env config')
        );
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id !== MEMPOOL)
        {
            $block_hash = $this->block_hash;
            $block = requester_single($this->select_node(), endpoint: "rest/block/{$block_hash}.json", timeout: $this->timeout);
            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);
        }
        else // Processing mempool
        {
            $block = [];
            $block['tx'] = [];
            $multi_curl = [];

            $mempool = requester_single($this->select_node(), params: ['method' => 'getrawmempool', 'params' => [false]], result_in: 'result', timeout: $this->timeout);

            $islice = 0;

            foreach ($mempool as $tx_hash)
            {
                if (!isset($this->processed_transactions[$tx_hash]))
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['method' => 'getrawtransaction', 'params' => [$tx_hash, 1]],
                        timeout: $this->timeout);

                    $islice++;
                    if ($islice >= 100) break; // For debug purposes, we limit the number of mempool transactions to process
                }
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
            {
                $block['tx'][] = requester_multi_process($v, result_in: 'result');
            }
        }

        $events = [];
        $currencies = [];

        $currencies_to_process = [];

        $previous_outputs_lib = [];
        $populate_outputs_lib_with = [];
        $GLOBALS['populate_outputs_lib_with_indexes'] = [];
        $inputs_to_check = [];
        $sort_in_block_lib = [];
        $fees = [];

        $block_n = 0;
        $coinbase_transaction_output = '0';

        $this_is_coinbase = true; // Coinbase transaction is always the first one
        if ($this->block_id === MEMPOOL) $this_is_coinbase = false;

        // Process outputs

        foreach ($block['tx'] as $transaction)
        {
            $previous_outputs_lib[($transaction['txid'])] = $transaction['vout'];
            $fees[($transaction['txid'])] = ['amount' => '0', 'currency' => null];

            foreach ($transaction['vout'] as $output)
            {
                if (isset($output['scriptPubKey']['address']))
                    $address = $output['scriptPubKey']['address'];
                else
                    $address = 'script-' . substr(hash('sha256', $output['scriptPubKey']['hex']), 0, 32);

                $extra = $extra_indexed = null;

                if (isset($output['scriptPubKey']['pegout_address']))
                {
                    $extra = 'po';
                    $extra_indexed = $output['scriptPubKey']['pegout_address'];
                }

                $asset = $output['asset'] ?? null;
                $effect = isset($output['value']) ? satoshi($output['value'], $this) : '+?';
                $type = $output['scriptPubKey']['type'] ?? '';

                if ($type === 'fee') // Skip additional event for fee out
                {
                    if (is_null($asset))
                        throw new ModuleError('Null asset for fee output type');

                    $fees[($transaction['txid'])]['amount'] = satoshi($output['value'], $this);
                    $fees[($transaction['txid'])]['currency'] = $asset;
                }
                else
                {
                    $address = ($extra === 'po') ? 'the-bridge' : $address;

                    $events[] = [
                        'transaction' => $transaction['txid'],
                        'currency'    => $asset,
                        'address'     => $address,
                        'effect'      => $effect,
                        'sort_in_transaction' => ((int)$output['n'] + 1),
                        'extra' => $extra,
                        'extra_indexed' => $extra_indexed,
                    ];
                }

                if (!is_null($asset))
                    $currencies_to_process[] = $asset;

                if ($this_is_coinbase)
                    $coinbase_transaction_output = bcsub($coinbase_transaction_output, satoshi($output['value'], $this));
            }

            $this_is_coinbase = false;

            $sort_in_block_lib[($transaction['txid'])] = $block_n;
            $block_n++;
        }

        // Process inputs

        foreach ($block['tx'] as $transaction)
        {
            $this_n = 1;

            foreach ($transaction['vin'] as $input)
            {
                if (isset($input['coinbase']))
                {
                    if ($coinbase_transaction_output === '0') $coinbase_transaction_output = '-0'; // E.g. block #501726 in Bitcoin

                    $events[] = [
                        'transaction'   => $transaction['txid'],
                        'currency'      => $this->native_asset,
                        'address'       => 'the-void',
                        'effect'        => $coinbase_transaction_output,
                        'sort_in_transaction' => -1,
                        'extra' => null,
                        'extra_indexed' => null,
                    ];
                }
                else
                {
                    // In LiquidBitcoin we cant get the previous tx vouts for pegin txs
                    if ($input['is_pegin'])
                    {
                        // Calculate exact amount from bitcoin
                        $value = '0';

                        foreach ($transaction['vout'] as $out)
                        {
                            $type = $out['scriptPubKey']['type'] ?? '';

                            if (!isset($out['value'])) // Example: fd36f216be666d43ec861feb756b1c5f48fb54f98bfeed25e5367b05cccc96e8
                                continue;

                            if ($type !== 'fee')
                                $value = bcadd($value, satoshi($out['value'], $this));
                        }

                        // Set event for pegin and skip check for this input
                        $events[] = [
                            'transaction'         => $transaction['txid'],
                            'currency'            => $this->native_asset,
                            'address'             => 'the-bridge',
                            'effect'              => '-' . $value,
                            'sort_in_transaction' => -1,
                            'extra' => 'pi',
                            'extra_indexed' => $input['txid'],
                        ];

                        $currencies_to_process[] = $this->native_asset;
                        continue;
                    }

                    if (!isset($previous_outputs_lib[($input['txid'])]))
                    {
                        $populate_outputs_lib_with[] = $input['txid'];
                        $GLOBALS['populate_outputs_lib_with_indexes'][$input['txid']][] = $input['vout'];
                    }

                    $inputs_to_check[] = [
                        'this_transaction'     => $transaction['txid'],
                        'previous_transaction' => $input['txid'],
                        'previous_n'           => $input['vout'],
                        'this_n'               => -$this_n,
                        'asset'                => $input['asset'] ?? null,
                    ];

                    $this_n++;
                }
            }
        }

        $multi_curl = [];

        $populate_outputs_lib_with = array_unique($populate_outputs_lib_with);

        // Get input data from the node

        foreach ($populate_outputs_lib_with as $tx_hash)
        {
            $multi_curl[] = requester_multi_prepare($this->select_node(),
                params: ['method' => 'getrawtransaction', 'params' => [$tx_hash, 1]],
                timeout: $this->timeout);
        }

        $curl_results = requester_multi($multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout, post_process: function($output) {
                $output = requester_multi_process($output, result_in: 'result');

                foreach ($output['vout'] as $vok => $vov)
                {
                    if (!in_array($vok, $GLOBALS['populate_outputs_lib_with_indexes'][$output['txid']]))
                        unset($output['vout'][$vok]);
                }

                return ['txid' => $output['txid'], 'vout' => $output['vout']];
            });

        foreach ($curl_results as $output)
        {
            $previous_outputs_lib[$output['txid']] = $output['vout'];
        }

        foreach ($inputs_to_check as $input)
        {
            if (!isset($previous_outputs_lib[($input['previous_transaction'])]))
                throw new ModuleError('Input is not in the library');

            $previous_output = $previous_outputs_lib[($input['previous_transaction'])];

            if (isset($previous_output[($input['previous_n'])]['scriptPubKey']['address']))
                $address = $previous_output[($input['previous_n'])]['scriptPubKey']['address'];
            else
                $address = 'script-' . substr(hash('sha256', $previous_output[($input['previous_n'])]['scriptPubKey']['hex']), 0, 32);

            $asset = $previous_output[($input['previous_n'])]['asset'] ?? null;
            $effect = isset($previous_output[($input['previous_n'])]['value']) ? satoshi($previous_output[($input['previous_n'])]['value'], $this) : '?';

            $events[] = [
                'transaction' => $input['this_transaction'],
                'currency'    => $asset,
                'address'     => $address,
                'effect'      => '-' . $effect,
                'sort_in_transaction' => (int)$input['this_n'],
                'extra' => null,
                'extra_indexed' => null,
            ];

            if (!is_null($asset))
                $currencies_to_process[] = $asset;
        }

        foreach ($fees as $txid => $fee_transfer)
        {
            if ($fee_transfer['amount'] !== '0')
            {
                $events[] = [
                    'transaction' => $txid,
                    'currency'    => $fee_transfer['currency'],
                    'address'     => 'the-void',
                    'effect'      => $fee_transfer['amount'],
                    'sort_in_transaction' => PHP_INT_MAX,
                    'extra' => null,
                    'extra_indexed' => null,
                ];
            }
        }

        // Process currencies

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        // Get metadata
        foreach ($currencies_to_process as $currency)
        {
            try
            {
                if ($currency === $this->native_asset)
                    $meta = $this->native_asset_meta;
                else
                    $meta = requester_single($this->asset_registry, endpoint: $currency, timeout: $this->timeout, valid_codes: [200, 404]);
            }
            catch (RequesterException $e)
            {
                // For unknown assets the registry returns an HTML page with 404 code
                if (str_contains($e->getMessage(), 'bad JSON'))
                    $meta = [
                        'name'      => null,
                        'ticker'    => null,
                        'precision' => 0,
                    ];
                else
                    throw $e;
            }

            $currencies[] = [
                'id'    => $currency,
                'name'      => $meta['name'] ?? null,
                'symbol'    => $meta['ticker'] ?? null,
                'decimals'  => $meta['precision'] ?? 0,
            ];
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['sort_in_block'] = $sort_in_block_lib[($event['transaction'])];
            $event['time'] = date('Y-m-d H:i:s', (($this->block_id !== MEMPOOL) ? (int)$block['time'] : time()));
        }

        // Resort

        usort($events, function($a, $b) {
            return  [$a['sort_in_block'],
                     !str_starts_with($a['effect'], '-'),
                     abs($a['sort_in_transaction']),
                ]
                <=>
                [$b['sort_in_block'],
                 !str_starts_with($b['effect'], '-'),
                 abs($b['sort_in_transaction']),
                ];
        });

        $sort_key = 0;
        $latest_tx_hash = ''; // This is for mempool

        foreach ($events as &$event)
        {
            if ($this->block_id === MEMPOOL && $event['transaction'] !== $latest_tx_hash)
            {
                $latest_tx_hash = $event['transaction'];
                $sort_key = 0;
            }

            $event['sort_key'] = $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
        if ($this->block_id !== MEMPOOL) $this->set_return_currencies($currencies);
    }
}
