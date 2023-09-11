<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes "internal" EVM transactions (this requires tracing). Both Erigon and geth are supported.  */

abstract class EVMTraceModule extends CoreModule
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

    // EVM-specific

    public ?EVMImplementation $evm_implementation = null;
    public array $extra_features = [];

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->evm_implementation)) throw new DeveloperError("`evm_implementation` is not set (developer error)");
    }

    final public function pre_process_block($block_id)
    {
        if ($this->evm_implementation === EVMImplementation::geth)
        {
            // We have to make two requests: `eth_getBlockByNumber` just to get transaction hashes, and
            // `debug_traceBlockByNumber` to get the actual traces. That's because `callTracer` doesn't
            // yield transaction hashed. Theoretically, it's faster to come up with a custom tracer
            // for this task.

            $multi_curl = [];

            if (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method'  => 'eth_getBlockByNumber',
                             'params'  => [to_0xhex_from_int64($block_id), false],
                             'id'      => 0,
                             'jsonrpc' => '2.0',
                    ], timeout: $this->timeout);

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method'  => 'debug_traceBlockByNumber',
                             'params'  => [to_0xhex_from_int64($block_id), ['tracer' => 'callTracer']],
                             'id'      => 1,
                             'jsonrpc' => '2.0',
                    ], timeout: $this->timeout);
            }
            else//if zkEVM
            {
                $blocks = requester_single($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                             'method'  => 'zkevm_getBatchByNumber',
                             'params'  => [to_0xhex_from_int64($block_id), true],
                             'id'      => 0,
                    ],
                    result_in: 'result',
                    timeout: $this->timeout);

                if (!$blocks['transactions'])
                {
                    $this->set_return_events([]);
                    return;
                }
                else
                {
                    $this_i = 0;

                    foreach ($blocks['transactions'] as $transaction)
                    {
                        $multi_curl[] = requester_multi_prepare($this->select_node(),
                            params: ['method'  => 'eth_getBlockByNumber',
                                     'params'  => [$transaction['blockNumber'], false],
                                     'id'      => $this_i++,
                                     'jsonrpc' => '2.0',
                            ], timeout: $this->timeout);

                        $multi_curl[] = requester_multi_prepare($this->select_node(),
                            params: ['method'  => 'debug_traceBlockByNumber',
                                     'params'  => [$transaction['blockNumber'], ['tracer' => 'callTracer']],
                                     'id'      => $this_i++,
                                     'jsonrpc' => '2.0',
                            ], timeout: $this->timeout);
                    }
                }
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            $curl_results_prepared = [];

            foreach ($curl_results as $curl_result)
            {
                $curl_results_prepared[] = requester_multi_process($curl_result);
            }

            reorder_by_id($curl_results_prepared);

            //

            $events = [];
            $sort_key = 0;

            for ($gi = 0; $gi < count($curl_results_prepared); $gi+=2)
            {
                // In case of non-zkEVM chain this loop will only run once, because count($curl_results_prepared) === 2

                $transaction_hashes = $curl_results_prepared[$gi]['result']['transactions'];

                if (count($curl_results_prepared[$gi]['result']['transactions']) !== count($curl_results_prepared[$gi+1]['result']))
                    throw new ModuleError('Transaction count mismatch');

                $this_i = 0;

                foreach ($curl_results_prepared[$gi+1]['result'] as $this_trace)
                {
                    $this_transaction_hash = $transaction_hashes[$this_i++];

                    if (!isset($this_trace['result']['calls']))
                        continue; // No internal txs

                    if (isset($this_trace['result']['error']))
                        continue; // Root failed

                    $this_calls = [];

                    evm_trace($this_trace['result']['calls'], $this_calls);

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
        }
        else//if ($this->evm_implementation === EVMImplementation::Erigon)
        {
            $trace = requester_single($this->select_node(),
                params: ['jsonrpc' => '2.0',
                         'method' => 'trace_block',
                         'params' => [to_0xhex_from_int64($block_id)],
                         'id' => 0],
                result_in: 'result', timeout: $this->timeout);

            $events = [];

            $current_transaction = '';
            $sort_key = 0;

            foreach ($trace as $bit)
            {
                // We don't need block reward data as this is already processed by the main module
                if (!isset($bit['transactionHash']) && ($bit['type'] === 'reward'))
                {
                    continue;
                }

                // New transaction is being processed
                if ($bit['transactionHash'] !== $current_transaction)
                {
                    // We don't need the root call as it is already processed by the main module
                    $current_transaction = $bit['transactionHash'];

                    $root_failed = isset($bit['error']);

                    continue;
                }

                // There's an error, but the root call hasn't failed
                if (isset($bit['error']) && !$root_failed)
                {
                    continue;
                }

                // Everything has failed, we're not adding any data to save the disk space
                if ($root_failed)
                {
                    continue;
                }

                // Now we have several call types
                // `call` transfers ethers
                // `create`/`create2` creates a contract and transfers ethers
                // `callcode`/`delegatecall`/`staticcall` don't transfer ethers
                // `selfdestruct` destroys the contract and gets ethers out of it

                if ($bit['type'] === 'create')
                {
                    // from: action.form, to: result.address, value: action.value

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['action']['from'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . to_int256_from_0xhex($bit['action']['value']),
                        'extra' => EVMSpecialTransactions::ContractCreation->value,
                    ];

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['result']['address'],
                        'sort_key' => $sort_key++,
                        'effect' => to_int256_from_0xhex($bit['action']['value']),
                        'extra' => EVMSpecialTransactions::ContractCreation->value,
                    ];
                }
                elseif ($bit['type'] === 'suicide')
                {
                    // from: action.address, to: action.refundAddress, value: action.balance

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['action']['address'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . to_int256_from_0xhex($bit['action']['balance']),
                        'extra' => EVMSpecialTransactions::ContractDestruction->value,
                    ];

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['action']['refundAddress'],
                        'sort_key' => $sort_key++,
                        'effect' => to_int256_from_0xhex($bit['action']['balance']),
                        'extra' => EVMSpecialTransactions::ContractDestruction->value,
                    ];
                }
                elseif ($bit['action']['callType'] === 'call')
                {
                    // from: action.from, to: action.to, value: action.value

                    if ($bit['action']['value'] === '0x0')
                        continue;

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['action']['from'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . to_int256_from_0xhex($bit['action']['value']),
                        'extra' => null,
                    ];

                    $events[] = [
                        'transaction' => $bit['transactionHash'],
                        'address' => $bit['action']['to'],
                        'sort_key' => $sort_key++,
                        'effect' => to_int256_from_0xhex($bit['action']['value']),
                        'extra' => null,
                    ];
                }
                elseif (in_array($bit['action']['callType'], ['staticcall', 'delegatecall', 'callcode']))
                {
                    // These types don't transfer ethers
                    continue;
                }
                else
                {
                    throw new ModuleError("Unknown call type for {$bit['transactionHash']}: {$bit['action']['callType']}");
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
