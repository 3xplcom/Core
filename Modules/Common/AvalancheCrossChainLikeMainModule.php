<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes avalanche cross-chain transfers that involve c-chain on either side. Special microservice API by Blockchair is needed (see https://github.com/Blockchair/avax-atomic-unpacker).  */

abstract class AvalancheCrossChainLikeMainModule extends CoreModule
{

    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'x' => 'Export',
        'i' => 'Import'
    ];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = ['symbol'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // EVM-inherited
    public array $extra_features = [];

    // Avalanche-specific
    public ?string $unpacker_handle = null;
    public ?string $asset_info_handle = null;
    public ?array $main_token_descr = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        $this->unpacker_handle = envm(
            $this->module,
            'UNPACKER_SERVICE',
            new DeveloperError('Unpacker service is not set in the config')
        );

        $this->asset_info_handle = envm(
            $this->module,
            'ASSETS_SERVICE',
            new DeveloperError('Assets service is not set in the config')
        );

        if (is_null($this->main_token_descr))
            throw new DeveloperError('`main_token_descr` is not set (developer error)');
    }

    final public function pre_process_block($block_id)
    {
        $multi_curl = [];

        $multi_curl[] = requester_multi_prepare($this->select_node(),
            params: ['method'  => 'eth_getBlockByNumber',
                     'params'  => [to_0xhex_from_int64($block_id), true],
                     'id'      => 0,
                     'jsonrpc' => '2.0',
            ], timeout: $this->timeout);

        $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);
        $r1 = requester_multi_process($curl_results[0], result_in: 'result');

        $block_time = $r1['timestamp'];

        $atomic_transactions = [];
        if (array_key_exists('blockExtraData', $r1)) {
            if (str_starts_with($r1['blockExtraData'], '0x') && strlen($r1['blockExtraData']) > 2) {
                $atomic_transactions = $this->middleware_unpack_atomics($block_time, $r1['blockExtraData']);
            }
        }

        [$events, $currencies_used] = $this->prepare_atomic_events($atomic_transactions);

        $this->process_events($block_id, $events, $block_time);

        $currencies_used = check_existing_currencies($currencies_used, $this->currency_format);

        $this->process_currencies($currencies_used);
    }

    private function middleware_unpack_atomics($timestamp, $blob)
    {
        return requester_single($this->unpacker_handle, params: [
            'method' => 'getAtomics',
            'params' => [
                'timestamp'      => $timestamp,
                'blockExtraData' => $blob
            ],
            'id' => 0,
            'jsonrpc' => '2.0',
        ],
        result_in: 'result', timeout: $this->timeout);
    }

    private function parse_transaction_legs(
        &$tx_legs,
        $hash,
        $sort_outer,
        $sort_inner,
        $signum,
        $extra,
        &$atomic_events,
        &$currencies_used
    )
    {
        foreach ($tx_legs as $tx_leg)
        {
            $atomic_events[] = [
                'transaction'         => $hash,
                'address'             => (is_null($extra)) ? $tx_leg['addr'] : '0x00',
                'sort_in_block'       => $sort_outer,
                'sort_in_transaction' => $sort_inner,
                'effect'              => $signum . to_int256_from_0xhex($tx_leg['amount']),
                'currency'            => $tx_leg['asset'],
                'extra'               => $extra,
            ];
            $currencies_used[$tx_leg['asset']] = 1;
        }
    }

    private function prepare_atomic_events($block_extra_atomics)
    {
        $atomic_events = [];
        $currencies_used = [];
        $ittr = 0;

        // enforcing per-tx sorting: burning -> inputs -> imports -> outputs -> exports
        foreach ($block_extra_atomics as $atomic_transaction)
        {
            $atomic_events[] = [
                'transaction'         => $atomic_transaction['hash'],
                'address'             => '0x00',
                'sort_in_block'       => $ittr,
                'sort_in_transaction' => 1,
                'effect'              => to_int256_from_0xhex($atomic_transaction['burnt']),
                'currency'            => $this->main_token_descr['id'],
                'extra'               => EVMSpecialTransactions::Burning->value,
            ];

            if (array_key_exists('inputs', $atomic_transaction))
            {
                $this->parse_transaction_legs(
                    $atomic_transaction['inputs'],
                    $atomic_transaction['hash'],
                    $ittr,
                    2,
                    '-',
                    null,
                    $atomic_events,
                    $currencies_used
                );
            }

            if (array_key_exists('imports', $atomic_transaction))
            {
                $this->parse_transaction_legs(
                    $atomic_transaction['imports'],
                    $atomic_transaction['hash'],
                    $ittr,
                    3,
                    '-',
                    'i',
                    $atomic_events,
                    $currencies_used
                );
            }

            if (array_key_exists('outputs', $atomic_transaction))
            {
                $this->parse_transaction_legs(
                    $atomic_transaction['outputs'],
                    $atomic_transaction['hash'],
                    $ittr,
                    4,
                    "",
                    null,
                    $atomic_events,
                    $currencies_used
                );
            }

            if (array_key_exists('exports', $atomic_transaction))
            {
                $this->parse_transaction_legs(
                    $atomic_transaction['exports'],
                    $atomic_transaction['hash'],
                    $ittr,
                    5,
                    "",
                    'x',
                    $atomic_events,
                    $currencies_used
                );

            }

            $ittr++;
        }

        return [$atomic_events, $currencies_used];
    }

    private function process_events($block_id, $events, $block_time)
    {
        $hmr_time = date('Y-m-d H:i:s', to_int64_from_0xhex($block_time));
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $hmr_time;
        }

        // Re-sort
        usort($events, function($a, $b)
        {
            return
                [$a['sort_in_block'], $a['sort_in_transaction'],]
                <=>
                [$b['sort_in_block'], $b['sort_in_transaction'],];
        });

        $sort_key = 0;

        foreach ($events as &$event)
        {
            $event['sort_key'] = $sort_key;
            $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
    }

    private function fetch_currency_description($currency)
    {
        return requester_single($this->asset_info_handle, params: [
            'method' => 'avm.getAssetDescription',
            'params' => [
                'assetID' => $currency
            ],
            'id' => 0,
            'jsonrpc' => '2.0',
        ],
        result_in: 'result', timeout: $this->timeout);
    }

    private function process_currencies($currencies_used)
    {
        $currencies = [];

        foreach ($currencies_used as $id => $_)
        {
            if ($id === $this->main_token_descr['id'])
            {
                $currencies[] = [
                    'id'       => $this->main_token_descr['id'],
                    'name'     => $this->main_token_descr['name'],
                    'symbol'   => $this->main_token_descr['symbol'],
                    'decimals' => $this->main_token_descr['decimals']
                ];
            } 
            else
            {
                $cur_data = $this->fetch_currency_description($id);

                $currencies[] = [
                    'id'       => $id,
                    'name'     => $cur_data['name'],
                    'symbol'   => $cur_data['symbol'],
                    'decimals' => $cur_data['denomination']
                ];
            }
        }
        $this->set_return_currencies($currencies);
    }
}