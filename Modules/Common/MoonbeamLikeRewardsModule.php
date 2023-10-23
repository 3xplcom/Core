<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process staking on the moonbeam blockchain. it requires sidecar API instance and a moonbeam node */

abstract class MoonbeamLikeRewardsModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['treasury'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'd' => 'Delegate', // indicates when collator acts as a passthrough for delegator's assets (both -effect & +effect)
        's' => 'Stake',    // assets being staked into treasury (only the -effect)
        'u' => 'Unstake',  // assets received from treasury from bond reduction (only the +effect)
        'r' => 'Reward'    // assets received from treasury as a reward (only the +effect)
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = false;

    // moonbeam specific
    public ?string $rpc_node = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        $this->rpc_node = envm(
            $this->module,
            'RPC_NODE',
            new DeveloperError('RPC_NODE not set in the config')
        );
    }

    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(), endpoint: 'blocks/head/header?finalized=false', result_in: 'number', timeout: $this->timeout);
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $block = requester_single($this->rpc_node, params: [
            'method' => 'eth_getBlockByNumber',
            'params' => [to_0xhex_from_int64($block_id), false],
            'id' => 0,
            'jsonrpc' => '2.0'], 
            result_in: 'result', timeout: $this->timeout);

        $this->block_hash = $block['hash'];
        $this->block_time = date('Y-m-d H:i:s', to_int64_from_0xhex($block['timestamp']));
    }

    final public function pre_process_block($block_id)
    {
        $block = requester_single($this->select_node(), endpoint: 'blocks/' . $block_id, timeout: $this->timeout);
        $auto_events = $block['onInitialize']['events'] ?? [];
        $extrinsics = $block['extrinsics'] ?? [];

        $events = [];
        $i = 0;
        foreach ($auto_events as $e)
        {
            if ($e['method']['pallet'] !== 'parachainStaking')
                continue;
            switch ($e['method']['method'])
            {
                case 'Rewarded':
                    [$sub, $add, $i] = $this->generate_event_pair(tx: null, src: 'treasury', dst: $e['data'][0], amt: $e['data'][1], sort_key: $i);
                    $add['extra'] = 'r';
                    array_push($events, $sub, $add);
                    break;

                case 'Compounded':
                    // delegator -> candidate (collator)
                    [$sub, $add, $i] = $this->generate_event_pair(tx: null, src: $e['data'][1], dst: $e['data'][0], amt: $e['data'][2], sort_key: $i);
                    $sub['extra'] = 's';
                    $add['extra'] = 'd';
                    array_push($events, $sub, $add);

                    // collator -> treasury
                    [$sub, $add, $i] = $this->generate_event_pair(tx: null, src: $e['data'][0], dst: 'treasury', amt: $e['data'][2], sort_key: $i);
                    $sub['extra'] = 'd';
                    array_push($events, $sub, $add);
                    break;                
            }
        }

        foreach ($extrinsics as $extrinsic)
        {
            if ($extrinsic['success'] !== true)
                continue;

            foreach ($extrinsic['events'] ?? [] as $e)
            { 
                if ($e['method']['pallet'] !== 'parachainStaking')
                    continue;

                switch ($e['method']['method'])
                {
                    case 'JoinedCollatorCandidates':
                    case 'CandidateBondedMore':
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][0], dst: 'treasury', amt: $e['data'][1], sort_key: $i);
                        $sub['extra'] = 's';
                        array_push($events, $sub, $add);
                        break;

                    case 'CandidateLeft':
                    case 'CandidateBondedLess':
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: 'treasury', dst: $e['data'][0], amt: $e['data'][1], sort_key: $i);
                        $add['extra'] = 'u';
                        array_push($events, $sub, $add);
                        break;

                    case 'Delegation':
                        // delegator -> candidate (collator)
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][0], dst: $e['data'][2], amt: $e['data'][1], sort_key: $i);
                        $sub['extra'] = 's';
                        $add['extra'] = 'd';
                        array_push($events, $sub, $add);

                        // collator -> treasury
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][2], dst: 'treasury', amt: $e['data'][1], sort_key: $i);
                        $sub['extra'] = 'd';
                        array_push($events, $sub, $add);
                        break;

                    case 'DelegationIncreased':
                        // delegator -> candidate (collator)
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][0], dst: $e['data'][1], amt: $e['data'][2], sort_key: $i);
                        $sub['extra'] = 's';
                        $add['extra'] = 'd';
                        array_push($events, $sub, $add);

                        // collator -> treasury
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][1], dst: 'treasury', amt: $e['data'][2], sort_key: $i);
                        $sub['extra'] = 'd';
                        array_push($events, $sub, $add);
                        break;

                    case 'DelegationDecreased':
                    case 'DelegationKicked':
                    case 'DelegationRevoked':
                        // treasury -> collator
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: 'treasury', dst: $e['data'][1], amt: $e['data'][2], sort_key: $i);
                        $add['extra'] = 'd';
                        array_push($events, $sub, $add);

                        // collator -> delegator
                        [$sub, $add, $i] = $this->generate_event_pair(tx: $extrinsic['hash'], src: $e['data'][1], dst: $e['data'][0], amt: $e['data'][2], sort_key: $i);
                        $sub['extra'] = 'd';
                        $add['extra'] = 'u';
                        array_push($events, $sub, $add);
                        break;

                    default:
                        break;
                }

            }
        }
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    final public function generate_event_pair($tx, $src, $dst, $amt, $sort_key)
    {
        $sub = [
            'transaction' => $tx,
            'address' => $src,
            'effect' => '-' . $amt,
            'sort_key' => $sort_key++,
            'extra' => null
        ];
        $add = [
            'transaction' => $tx,
            'address' => $dst,
            'effect' => $amt,
            'sort_key' => $sort_key++,
            'extra' => null
        ];
        return [$sub, $add, $sort_key];
    }
}