<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process the native token XCM transfer events for Substrate SDK blockchains.
 *  Process only native XCM transfers (DOT/KSM) from/to relay chain. So its complements to main module.
 *  UMP - transfer DOT/KSM from para to relay
 *  DMP - transfer DOT/KSM from relay to para
 *  HRMP - transfer Token from para to para
 *  Works with Substrate Sidecar API: https://paritytech.github.io/substrate-api-sidecar/dist/.
 */

abstract class SubstrateXCMMainModule extends CoreModule
{
    use SubstrateTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = [];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
    }

    final public function pre_process_block($block_id)
    {
        $block = requester_single($this->select_node(), endpoint: "blocks/{$block_id}", timeout: $this->timeout);

        $events = [];
        $sort_key = 0;

        // Parse onInitialize xcm events (UMP)
        $this->process_internal_xcm_main_events($block['onInitialize']['events'] ?? [], $sort_key, $events);

        // Parse extrinsics data
        foreach ($block['extrinsics'] ?? [] as $extrinsic_number => $extrinsic)
        {
            $tx_id = $block_id . '-' . $extrinsic_number;

            switch ($extrinsic['method']['pallet'])
            {
                // In old spec UMP not in internal events
                case 'paraInherent':
                    $this->process_xcm_in_parainherent_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                // XCM transfers processed at another module (DMP)
                case 'xcmPallet':
                    $this->process_xcm_pallet($extrinsic, $tx_id, $sort_key, $events);
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
                            case 'xcmPallet':
                                $this->process_xcm_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                        }
                    }
                    break;
            }
        }

        // Parse onFinalize xcm events (UMP)
        $this->process_internal_xcm_main_events($block['onFinalize']['events'] ?? [], $sort_key, $events);

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
