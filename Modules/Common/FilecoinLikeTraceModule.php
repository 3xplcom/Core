<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process the internal transfer events for Filecoin blockchain.
 *  API Spec: https://docs.filecoin.io/reference/json-rpc/chain
 *  Note: At Filecoin there are tipsets instead of blocks. One tipset it's a set of blocks
 *  with some height and timestamp produced by different block producers.
 *  In the module $block_id means like tipset id.
 */

abstract class FilecoinLikeTraceModule extends CoreModule
{
    use FilecoinTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::AlphaNumeric;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    // the-void - special address for mint/burn events.
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        FilecoinSpecialTransactions::BlockReward->value => 'Block reward',
    ];

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
        $events = [];
        $sort_key = 0;

        $tipset_header = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.ChainGetTipSetByHeight',
                'params'  => [
                    $block_id,
                    []
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        // Check for empty tipset
        if ((int)$tipset_header['Height'] !== $block_id)
        {
            $this->set_return_events($events);
            return;
        }

        $internal_state = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.StateCompute',
                'params'  => [
                    $block_id,
                    [],
                    $tipset_header['Cids']
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        foreach ($internal_state['Trace'] as $trace)
        {
            $failed = false;
            if ($trace['Error'] !== '')
                $failed = true;

            $this->process_trace($trace['ExecutionTrace'], $failed, $events, $sort_key);
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
