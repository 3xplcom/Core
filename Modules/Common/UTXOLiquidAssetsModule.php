<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process UTXO Assets transfers for Liquid Blockchain.  */

abstract class UTXOLiquidAssetsModule extends CoreModule
{
    use UTXOTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWithout0x;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Mixed;

    public ?array $events_table_fields = ['block', 'transaction', 'currency', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Liquid-specific

    public ?array $extra_features = [];

    public ?string $native_asset = null;
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
        $this->asset_registry = envm(
            $this->module,
            'ASSETS_REGISTRY',
            new DeveloperError('ASSETS_REGISTRY not set in the env config')
        );
    }

    final public function pre_process_block($block_id)
    {
        $block_hash = $this->block_hash;
        $block = requester_single($this->select_node(), endpoint: "rest/block/{$block_hash}.json", timeout: $this->timeout);
        $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);

        $events = [];
        $currencies = [];

        $currencies_to_process = [];
        $currencies_sums = [];

        $previous_outputs_lib = [];
        $populate_outputs_lib_with = [];
        $GLOBALS['populate_outputs_lib_with_indexes'] = [];
        $inputs_to_check = [];
        $sort_in_block_lib = [];

        $block_n = 0;

        // Process outputs

        foreach ($block['tx'] as $transaction)
        {
            $previous_outputs_lib[($transaction['txid'])] = $transaction['vout'];

            foreach ($transaction['vout'] as $output)
            {
                $asset = $output['asset'] ?? $this->native_asset;
                if ($asset === $this->native_asset)
                    continue; // Skip native assets

                if (isset($output['scriptPubKey']['address']))
                    $address = $output['scriptPubKey']['address'];
                else
                    $address = 'script-' . substr(hash('sha256', $output['scriptPubKey']['hex']), 0, 32);

                $effect = isset($output['value']) ? satoshi($output['value'], $this) : '+?';
                $events[] = [
                    'transaction' => $transaction['txid'],
                    'currency'    => $asset,
                    'address'     => $address,
                    'effect'      => $effect,
                    'sort_in_transaction' => ((int)$output['n'] + 1)
                ];

                $currencies_to_process[] = $asset;
                if (!array_key_exists($asset, $currencies_sums))
                    $currencies_sums[$asset] = ['txid' => $transaction['txid'], 'sum' => $effect];
                else
                    $currencies_sums[$asset]['sum'] = bcadd($currencies_sums[$asset]['sum'], $effect);
            }

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
                    continue;

                $is_pegin = $input['is_pegin'] ?? false;
                if ($is_pegin == true)
                    continue;

                if (!isset($previous_outputs_lib[($input['txid'])]))
                {
                    $populate_outputs_lib_with[] = $input['txid'];
                    $GLOBALS['populate_outputs_lib_with_indexes'][$input['txid']][] = $input['vout'];
                }

                $inputs_to_check[] = ['this_transaction'     => $transaction['txid'],
                                        'previous_transaction' => $input['txid'],
                                        'previous_n'           => $input['vout'],
                                        'this_n'               => -$this_n,
                ];

                $this_n++;
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

            $asset = $previous_output[($input['previous_n'])]['asset'] ?? $this->native_asset;
            if ($asset === $this->native_asset)
                continue; // Skip native assets

            if (isset($previous_output[($input['previous_n'])]['scriptPubKey']['address']))
                $address = $previous_output[($input['previous_n'])]['scriptPubKey']['address'];
            else
                $address = 'script-' . substr(hash('sha256', $previous_output[($input['previous_n'])]['scriptPubKey']['hex']), 0, 32);

            $effect = isset($previous_output[($input['previous_n'])]['value']) ? satoshi($previous_output[($input['previous_n'])]['value'], $this) : '?';
            $events[] = [
                'transaction' => $input['this_transaction'],
                'currency'    => $asset,
                'address'     => $address,
                'effect'      => '-' . $effect,
                'sort_in_transaction' => (int)$input['this_n'],
            ];

            $currencies_to_process[] = $asset;
            if (!array_key_exists($asset, $currencies_sums))
                throw new ModuleError("Currency {$asset} not found in outputs");
            else
                $currencies_sums[$asset]['sum'] = bcsub($currencies_sums[$asset]['sum'], $effect);
        }

        // Process currencies

        // Add mint events
        foreach ($currencies_sums as $asset => $info)
        {
            if ($info['sum'][0] === '-')
                throw new ModuleError("Unexpected minus for {$asset}");
            if ($info['sum'] === '0')
                continue;
            $events[] = [
                'transaction' => $info['txid'],
                'currency'    => $asset,
                'address'     => 'the-void',
                'effect'      => '-' . $info['sum'],
                'sort_in_transaction' => -1,
            ];
        }

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        // Get metadata
        foreach ($currencies_to_process as $currency)
        {
            try
            {
                $meta = requester_single($this->asset_registry, endpoint: $currency, timeout: $this->timeout, valid_codes: [200, 404]);
            }
            catch (RequesterException $e)
            {
                // For unknown assets returns HTML page with 404 code
                if (str_contains($e->getMessage(), 'bad JSON'))
                    $meta = [
                        'name'      => 'unknown',
                        'ticker'    => 'unknown',
                        'precision'  => 0,
                    ];
                else
                    throw $e;
            }

            $currencies[] = [
                'id'    => $currency,
                'name'      => $meta['name'] ?? '',
                'symbol'    => $meta['ticker'] ?? '',
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
        foreach ($events as &$event)
        {
            $event['sort_key'] = $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }
}
