<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module works with the ERC-1155 MT (BEP-1155 and similar) standard, see
 *  https://github.com/ethereum/EIPs/blob/master/EIPS/eip-1155.md */

abstract class EVMERC1155Module extends CoreModule
{
    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWith0x;
    public ?CurrencyType $currency_type = CurrencyType::MT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false; // Technically, this is possible
    public ?bool $forking_implemented = true;

    // EVM-specific
    public array $extra_features = [];

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
        {
            $this->forking_implemented = false; // We only process finalized batches
            $this->block_entity_name = 'batch'; // We process batches instead of blocks
            $this->mempool_entity_name = 'queue'; // Unfinalized batches are processed as "mempool"
        }
    }

    final public function pre_process_block($block_id)
    {
        // Get logs

        $multi_curl = $log_data = [];

        if ((!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features)))
        {
            $multi_curl[] = requester_multi_prepare($this->select_node(),
                params: ['jsonrpc' => '2.0',
                         'method'  => 'eth_getLogs',
                         'params'  =>
                             [['blockHash' => $this->block_hash,
                               'topics'    => ['0xc3d58168c5ae7397731d063d5bbf3d657854427343f4c083240f7aacaa2d0f62'],
                              ],
                             ],
                         'id'      => 0,
                ],
                timeout: $this->timeout); // TransferSingle

            $multi_curl[] = requester_multi_prepare($this->select_node(),
                params: ['jsonrpc' => '2.0',
                         'method'  => 'eth_getLogs',
                         'params'  =>
                             [['blockHash' => $this->block_hash,
                               'topics'    => ['0x4a39dc06d4c0dbc64b70af90fd698a233a518aa5d07e595d983b8c0526c8f7fb'],
                              ],
                             ],
                         'id'      => 1,
                ],
                timeout: $this->timeout); // TransferBatch
        }
        else//if (zkEVM)
        {
            // We need to get the block range for the batch

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
                $this->set_return_currencies([]);
                return;
            }
            else
            {
                $first_block = $blocks['transactions'][0]['blockNumber'];
                $last_block = end($blocks['transactions'])['blockNumber'];

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                             'method'  => 'eth_getLogs',
                             'params'  =>
                                 [['fromBlock' => $first_block,
                                   'toBlock'   => $last_block,
                                   'topics'    => ['0xc3d58168c5ae7397731d063d5bbf3d657854427343f4c083240f7aacaa2d0f62'],
                                  ],
                                 ],
                             'id'      => 0,
                    ],
                    timeout: $this->timeout); // TransferSingle

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                             'method'  => 'eth_getLogs',
                             'params'  =>
                                 [['fromBlock' => $first_block,
                                   'toBlock'   => $last_block,
                                   'topics'    => ['0x4a39dc06d4c0dbc64b70af90fd698a233a518aa5d07e595d983b8c0526c8f7fb'],
                                  ],
                                 ],
                             'id'      => 1,
                    ],
                    timeout: $this->timeout); // TransferBatch
            }
        }

        $curl_results = requester_multi($multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout);

        foreach ($curl_results as $v)
            $log_data[] = requester_multi_process($v);

        reorder_by_id($log_data);

        $logs_single = $log_data[0]['result'];
        $logs_batch = $log_data[1]['result'];

        // Process logs

        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;

        foreach ($logs_single as $log)
        {
            if ($log['blockHash'] !== $this->block_hash && !in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
                throw new ModuleError("The node returned wrong data for {$this->block_hash}: {$log['blockHash']}");

            if (count($log['topics']) !== 4)
                continue; // This is ERC-20

            $events[] = [
                'transaction' => $log['transactionHash'],
                'currency'    => $log['address'],
                'address'     => '0x' . substr($log['topics'][2], 26),
                'sort_key'    => $sort_key++,
                'effect'      => '-' . to_int256_from_0xhex('0x' . substr($log['data'], 66, 64)),
                'extra'       => to_int256_from_0xhex('0x' . substr($log['data'], 2, 64)),
            ];

            $events[] = [
                'transaction' => $log['transactionHash'],
                'currency'    => $log['address'],
                'address'     => '0x' . substr($log['topics'][3], 26),
                'sort_key'    => $sort_key++,
                'effect'      => to_int256_from_0xhex('0x' . substr($log['data'], 66, 64)),
                'extra'       => to_int256_from_0xhex('0x' . substr($log['data'], 2, 64)),
            ];

            $currencies_to_process[] = $log['address'];
        }

        foreach ($logs_batch as $log)
        {
            if ($log['blockHash'] !== $this->block_hash && !in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
                throw new ModuleError("The node returned wrong data for {$this->block_hash}: {$log['blockHash']}");

            if (count($log['topics']) !== 4)
                continue; // This is ERC-20

            $n = str_split(substr($log['data'], 2), 64);

            if (((count($n) - 4) % 2) !== 0) // Some contract may yield invalid `data`, e.g. two token ids, but just one value
                continue;

            $n_count = intdiv(count($n) - 4, 2);

            if (!$n_count) // Example: Avalanche C-Chain transaction 0x9facbf18cf0be5525459383dcce0b523dc6b62272318d220688c60d2019ee736
                continue;

            $first_5th = 4 + $n_count;

            for ($this_n = 0; $this_n < $n_count; $this_n++)
            {
                $events[] = [
                    'transaction' => $log['transactionHash'],
                    'currency'    => $log['address'],
                    'address'     => '0x' . substr($log['topics'][2], 26),
                    'sort_key'    => $sort_key++,
                    'effect'      => '-' . to_int256_from_0xhex('0x' . $n[$first_5th + $this_n]),
                    'extra'       => to_int256_from_0xhex('0x' . $n[3 + $this_n]),
                ];

                $events[] = [
                    'transaction' => $log['transactionHash'],
                    'currency'    => $log['address'],
                    'address'     => '0x' . substr($log['topics'][3], 26),
                    'sort_key'    => $sort_key++,
                    'effect'      => to_int256_from_0xhex('0x' . $n[$first_5th + $this_n]),
                    'extra'       => to_int256_from_0xhex('0x' . $n[3 + $this_n]),
                ];
            }

            $currencies_to_process[] = $log['address'];
        }

        // Process currencies

        $currencies = [];

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        if ($currencies_to_process)
        {
            $multi_curl = $lib = [];
            $this_id = 0;

            foreach ($currencies_to_process as $currency_id)
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                             'method'  => 'eth_call',
                             'params'  => [['to'   => $currency_id,
                                            'data' => '0x06fdde03',
                                           ],
                                           'latest',
                             ],
                             'id'      => $this_id++,
                    ],
                    timeout: $this->timeout); // Name

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                             'method'  => 'eth_call',
                             'params'  => [['to'   => $currency_id,
                                            'data' => '0x95d89b41',
                                           ],
                                           'latest',
                             ],
                             'id'      => $this_id++,
                    ],
                    timeout: $this->timeout); // Symbol
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            foreach ($curl_results as $v)
                $currency_data[] = requester_multi_process($v, ignore_errors: true);

            reorder_by_id($currency_data);

            foreach ($currency_data as $bit)
            {
                $this_j = intdiv((int)$bit['id'], 2);

                if (!isset($bit['result']) && isset($bit['error']))
                {
                    if (str_starts_with($bit['error']['message'], 'execution reverted'))
                        $bit['result'] = '0x';
                    elseif (str_starts_with($bit['error']['message'], 'invalid opcode'))
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'out of gas')
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'invalid jump destination')
                        $bit['result'] = '0x';
                    elseif (str_contains($bit['error']['message'], 'Function does not exist'))
                        $bit['result'] = '0x';
                    elseif (str_contains($bit['error']['message'], 'VM execution error.'))
                        $bit['result'] = '0x';
                    else
                        throw new RequesterException("Request to the node errored with `{$bit['error']['message']}`: " . print_r($bit['error'], true));
                }

                if ((int)$bit['id'] % 2 === 0)
                    $lib[($currencies_to_process[$this_j])]['name'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));
                if ((int)$bit['id'] % 2 === 1)
                    $lib[($currencies_to_process[$this_j])]['symbol'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));
            }

            foreach ($lib as $id => $l)
            {
                // This removes invalid UTF-8 sequences
                $l['name'] = mb_convert_encoding($l['name'], 'UTF-8', 'UTF-8');
                $l['symbol'] = mb_convert_encoding($l['symbol'], 'UTF-8', 'UTF-8');

                $currencies[] = [
                    'id'     => $id,
                    'name'   => $l['name'],
                    'symbol' => $l['symbol'],
                ];
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
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        if (!preg_match(StandardPatterns::iHexWith0x40->value, $address))
        {
            $return = [];

            foreach ($currencies as $ignored)
                $return[] = '0';

            return $return;
        }

        $real_currencies = [];

        // Input currencies should be in format like this: `ethereum-erc-20/0x14d1b27d79e97e96622618f9d4fa9b1e1e9ef082`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $encoded_address = $this->encode_abi("address", substr($address, 2));

        $data = $return = [];

        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++)
        {
            $data[] = ['jsonrpc' => '2.0',
                       'id'      => $i,
                       'method'  => 'eth_call',
                       'params'  => [['to'   => $real_currencies[$i],
                                      'data' => '0x70a08231' . $encoded_address,
                                     ],
                                     'latest',
                       ],
            ];
        }

        $data_chunks = array_chunk($data, 100);

        foreach ($data_chunks as $datai)
        {
            $result = requester_single($this->select_node(), params: $datai);

            reorder_by_id($result);

            foreach ($result as $bit)
            {
                $return[] = to_int256_from_0xhex($bit['result'] ?? null);
            }
        }

        return $return;
    }
}
