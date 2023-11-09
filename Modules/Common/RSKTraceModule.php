<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes "internal" EVM transactions for Rootstock blockchain (this requires tracing). */

abstract class RSKTraceModule extends CoreModule
{
    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $must_complement = true; // Any trace module should complement a main module

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true; // Transaction may have no traces

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    // For EVM traits compatibility

    public ?EVMImplementation $evm_implementation = null;
    public array $extra_features = [];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        $curl_results_prepared = [];

        // Rootstock have only debug_traceBlockByHash and there are other responce format.
        $block = requester_single(
            $this->select_node(),
            params: [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBlockByNumber',
                'params' => [to_0xhex_from_int64($block_id), false],
                'id' => 0,
            ],
            timeout: $this->timeout
        );

        $block_trace = requester_single(
            $this->select_node(),
            params: [
                'jsonrpc' => '2.0',
                'method' => 'debug_traceBlockByHash',
                'params' => [
                    $block['result']['hash'],
                    [
                        'tracer' => 'callTracer',
                        'disableStorage' => true,
                        'disableMemory' => true,
                        'disableStack' => true
                        ]
                    ],
                'id' => 1,
            ],
            timeout: $this->timeout
        );

        $curl_results_prepared[] = $block;
        $curl_results_prepared[] = $block_trace;

        reorder_by_id($curl_results_prepared);

        $events = [];
        $sort_key = 0;

        for ($gi = 0; $gi < count($curl_results_prepared); $gi += 2)
        {
            // This loop will only run once, because count($curl_results_prepared) === 2

            $transaction_hashes = $curl_results_prepared[$gi]['result']['transactions'];

            if (count($curl_results_prepared[$gi]['result']['transactions']) !== count($curl_results_prepared[$gi + 1]['result']))
                throw new ModuleError('Transaction count mismatch');

            $this_i = 0;

            foreach ($curl_results_prepared[$gi + 1]['result'] as $this_trace)
            {
                $this_transaction_hash = $transaction_hashes[$this_i++];

                if (!isset($this_trace['subtraces']))
                    continue;

                $this_calls = [];

                rsk_trace($this_trace['subtraces'], $this_calls);

                foreach ($this_calls as $this_call)
                {
                    $events[] = [
                        'transaction' => $this_transaction_hash,
                        'address' => $this_call['from'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . $this_call['value'],
                        'extra' => $this_call['type'],
                    ];

                    $events[] = [
                        'transaction' => $this_transaction_hash,
                        'address' => $this_call['to'],
                        'sort_key' => $sort_key++,
                        'effect' => $this_call['value'],
                        'extra' => $this_call['type'],
                    ];
                }
            }
        }

        ////////////////
        // Processing //
        ////////////////

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
