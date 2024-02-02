<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process the tokens transfer events for Substrate SDK blockchains.
 *  Works with Substrate Sidecar API: https://paritytech.github.io/substrate-api-sidecar/dist/.
 */

abstract class SubstrateTokensModule extends CoreModule
{
    use SubstrateTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = [];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'currency', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Tokens-specific
    // Should be set to exclude duplicates transfers for native token.
    public ?string $native_token_id = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->native_token_id))
            throw new DeveloperError("Native token id is not set (developer error).");
    }

    final public function pre_process_block($block_id)
    {
        $block = requester_single($this->select_node(), endpoint: "blocks/{$block_id}", timeout: $this->timeout);

        $events = [];
        $currencies = [];
        $currencies_to_process = [];
        $sort_key = 0;

        // Parse extrinsics data
        foreach ($block['extrinsics'] ?? [] as $extrinsic_number => $extrinsic)
        {
            $tx_id = $block_id . '-' . $extrinsic_number;

            $with_transfer = false;
            switch ($extrinsic['method']['pallet'])
            {
                case 'currencies':
                    $this->process_currencies_pallet($extrinsic, $tx_id, $sort_key, $events, $currencies_to_process);
                    break;

                case 'multisig':
                case 'utility':
                    $method = $extrinsic['method']['method'];
                    $calls = [];

                    // utility
                    if (in_array($method, ['batch', 'batchAll', 'forceBatch']))
                        $calls = $extrinsic['args']['calls'];
                    // utility
                    elseif ($method === 'asDerivative')
                        $calls[] = $extrinsic['args']['call'];
                    // multisig
                    elseif (in_array($method, ['asMulti', 'asMultiThreshold1']))
                        $calls[] = $extrinsic['args']['call'];

                    foreach ($calls ?? [] as $call)
                    {
                        $call_extrinsic = $extrinsic;
                        $call_extrinsic['method'] = $call['method'];
                        $call_extrinsic['args'] = $call['args'];

                        switch ($call['method']['pallet'])
                        {
                            case 'currencies':
                                $this->process_currencies_pallet($call_extrinsic, $tx_id, $sort_key, $events, $currencies_to_process);
                                break;
                            default:
                                $with_transfer = true;
                        }
                    }
                    break;

                // This pallets for XCM so we skip it
                case 'xTokens':
                case 'parachainSystem':
                    break;

                // For other pallets check for transfer in additional events
                default:
                    $with_transfer = true;
            }

            $failed = !$extrinsic['success'];
            $this->process_additional_tokens_events($extrinsic['events'] ?? [], $with_transfer, $tx_id, $failed, $sort_key, $events, $currencies_to_process);
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $currencies_to_process = array_unique($currencies_to_process);
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format);

        $assets_meta = requester_single($this->select_node(), endpoint: "/pallets/asset-registry", result_in: 'items', timeout: $this->timeout);
        foreach ($currencies_to_process as $currency)
        {
            $meta = ['name' => '', 'symbol' => '', 'decimals' => ''];
            if (in_array($currency, $assets_meta))
                $meta = $assets_meta[$currency];
            $currencies[] = [
                'id' => $currency,
                'name' => $meta['name'],
                'symbol' => $meta['symbol'],
                'decimals' => $meta['decimals'],
            ];
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }
}
