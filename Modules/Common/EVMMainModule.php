<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes "external" EVM transactions, block rewards, and withdrawals from the PoS chain.
 *  Supported nodes: geth and Erigon.  */

abstract class EVMMainModule extends CoreModule
{
    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details =
        [EVMSpecialTransactions::FeeToMiner->value           => 'Miner fee',
         EVMSpecialTransactions::Burning->value              => 'Burnt fee',
         EVMSpecialTransactions::BlockReward->value          => 'Block reward',
         EVMSpecialTransactions::UncleInclusionReward->value => 'Uncle inclusion reward',
         EVMSpecialTransactions::UncleReward->value          => 'Uncle reward',
         EVMSpecialTransactions::ContractCreation->value     => 'Contract creation',
         EVMSpecialTransactions::ContractDestruction->value  => 'Contract destruction',
         EVMSpecialTransactions::Withdrawal->value           => 'Withdrawal',
        ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    // EVM-specific

    public ?EVMImplementation $evm_implementation = null;
    public array $extra_features = [];
    public ?string $staking_contract = null;
    public ?Closure $reward_function = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->currency))
            throw new DeveloperError("`currency` is not set (developer error)");

        if (is_null($this->evm_implementation))
            throw new DeveloperError("`evm_implementation` is not set (developer error)");

        if (is_null($this->reward_function))
            throw new DeveloperError("`reward_function` is not set (developer error)");
      
        if (in_array(EVMSpecialFeatures::PoSWithdrawals, $this->extra_features) && is_null($this->staking_contract))
            throw new DeveloperError('`staking_contract` is not set when `PoSWithdrawals` is enabled');

        if (in_array(EVMSpecialFeatures::zkEVM, $this->extra_features) && $this->evm_implementation === EVMImplementation::Erigon)
            throw new DeveloperError("`Erigon` is not supported for `zkEVM` (developer error)");

        if (in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
        {
            $this->forking_implemented = false; // We only process finalized batches
            $this->block_entity_name = 'batch'; // We process batches instead of blocks
            $this->mempool_entity_name = 'queue'; // Unfinalized batches are processed as "mempool"
        }
    }

    final public function pre_process_block($block_id)
    {
        // Erigon RPC docs: https://github.com/ledgerwatch/erigon/blob/devel/cmd/rpcdaemon/README.md

        // How blocks work:
        // 1. Transactions are executed
        // 2. If there are uncles, uncle miners are rewarded
        // 3. Block miner gets block reward + fees + uncle inclusion reward
        // 4. Withdrawals are processed (in case of a PoS chain)

        // How transactions work:
        // 1. Part of the fee is getting burnt (sent to `the-void`)
        // 2. Part of the fee is paid to the miner (or validator)
        // 3. The transfer is executed

        ////////////////////////////////
        // Getting data from the node //
        ////////////////////////////////

        $transaction_data = [];

        if ($block_id !== MEMPOOL)
        {
            if ($this->evm_implementation === EVMImplementation::Erigon) // Erigon is faster as it supports `eth_getBlockReceipts`
            {
                // Retrieving data

                $multi_curl = [];

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method'  => 'eth_getBlockByNumber',
                             'params'  => [to_0xhex_from_int64($block_id), true],
                             'id'      => 0,
                             'jsonrpc' => '2.0',
                    ], timeout: $this->timeout);

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method'  => 'eth_getBlockReceipts',
                             'params'  => [to_0xhex_from_int64($block_id)],
                             'id'      => 1,
                             'jsonrpc' => '2.0',
                    ], timeout: $this->timeout);

                $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

                $r[0] = requester_multi_process($curl_results[0]);
                $r[1] = requester_multi_process($curl_results[1]);
                reorder_by_id($r);
                $r0 = $r[0]['result'];
                $r1 = $r[1]['result'];

                // Processing data

                $general_data = $r0['transactions'];
                $base_fee_per_gas = to_int256_from_0xhex($r0['baseFeePerGas'] ?? null);
                $receipt_data = $r1;
                $block_time = $r0['timestamp'];
                $miner = $r0['miner'];

                if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features))
                {
                    $uncle_count = count($r0['uncles']);
                    $uncles = $r0['uncles'];
                }

                if (in_array(EVMSpecialFeatures::PoSWithdrawals, $this->extra_features))
                {
                    $withdrawals = $r0['withdrawals'];
                }
            }
            else // geth is slower as we have to do eth_getTransactionReceipt for every transaction separately
            {
                $method = (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
                    ? 'eth_getBlockByNumber'
                    : 'zkevm_getBatchByNumber';

                $r1 = requester_single($this->select_node(),
                    params: ['method'  => $method,
                             'params'  => [to_0xhex_from_int64($block_id), true],
                             'id'      => 0,
                             'jsonrpc' => '2.0',
                    ], result_in: 'result', timeout: $this->timeout);

                $block_time = $r1['timestamp'];

                if (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
                {
                    $miner = $r1['miner'];
                }
                else
                {
                    // zkevm_getBatchByNumber doesn't return the sequencer address, so we have to get it from the first block in the batch
                    if (!isset($r1['transactions'][0]['blockNumber']))
                    {
                        $r1['transactions'] = [];
                        $miner = '0x00';
                    }
                    else
                    {
                        $miner = requester_single($this->select_node(),
                            params: ['method'  => 'eth_getBlockByNumber',
                                     'params'  => [$r1['transactions'][0]['blockNumber'],
                                                   true,
                                     ],
                                     'id'      => 0,
                                     'jsonrpc' => '2.0',
                            ], result_in: 'result', timeout: $this->timeout)['miner'];
                    }
                }

                if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features))
                {
                    $uncle_count = count($r1['uncles']);
                    $uncles = $r1['uncles'];
                }

                if (in_array(EVMSpecialFeatures::PoSWithdrawals, $this->extra_features))
                {
                    $withdrawals = $r1['withdrawals'];
                }

                $general_data = $r1['transactions'];
                $base_fee_per_gas = to_int256_from_0xhex($r1['baseFeePerGas'] ?? null);

                $multi_curl = [];
                $ij = 0;

                foreach ($r1['transactions'] as $transaction)
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['method'  => 'eth_getTransactionReceipt',
                                 'params'  => [$transaction['hash']],
                                 'id'      => $ij++,
                                 'jsonrpc' => '2.0',
                        ], timeout: $this->timeout);
                }

                $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout);

                $receipt_data = requester_multi_process_all($curl_results, result_in: 'result');
            }

            if (in_array(EVMSpecialFeatures::BorValidator, $this->extra_features))
            {
                $miner = ($block_id === 0) ? '0x0000000000000000000000000000000000000000' : requester_single($this->select_node(),
                    params: ['method'  => 'bor_getAuthor',
                             'params'  => [to_0xhex_from_int64($block_id)],
                             'id'      => 0,
                             'jsonrpc' => '2.0',
                    ],
                    result_in: 'result', timeout: $this->timeout);
            }

            // Data processing

            $this->block_time = date('Y-m-d H:i:s', to_int64_from_0xhex($block_time));

            if (($ic = count($general_data)) !== count($receipt_data))
            {
                throw new ModuleError('Mismatch in transaction count');
            }

            for ($i = 0; $i < $ic; $i++)
            {
                if ($general_data[$i]['hash'] !== $receipt_data[$i]['transactionHash'])
                {
                    throw new ModuleError('Mismatch in transaction order');
                }

                $transaction_data[($general_data[$i]['hash'])] =
                    [
                        'from' => $general_data[$i]['from'],
                        'to' => $general_data[$i]['to'],
                        'value' => $general_data[$i]['value'],
                        'contractAddress' => $receipt_data[$i]['contractAddress'],
                        'gasUsed' => $receipt_data[$i]['gasUsed'],
                        'effectiveGasPrice' => $receipt_data[$i]['effectiveGasPrice'] ?? $general_data[$i]['gasPrice'], // There's no effectiveGasPrice in some chains
                        'status' => $receipt_data[$i]['status'],
                    ];

                if (in_array(EVMSpecialFeatures::HasSystemTransactions, $this->extra_features))
                    $transaction_data[($general_data[$i]['hash'])]['type'] = $receipt_data[$i]['type'];
            }
        }
        else // Mempool processing
        {
            if (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
            {
                $r = requester_single($this->select_node(),
                    params: ['jsonrpc'=> '2.0', 'method' => 'txpool_content', 'id' => 0],
                    result_in: 'result',
                    timeout: $this->timeout);

                $r_combined = array_merge($r['queued'], $r['pending']);

                $processing_batch_count = 0;
                $break = false;

                foreach ($r_combined as $transactions)
                {
                    foreach ($transactions as $transaction)
                    {
                        if (!isset($this->processed_transactions[($transaction['hash'])]))
                        {
                            $transaction_data[($transaction['hash'])] =
                                [
                                    'from' => $transaction['from'],
                                    'to' => $transaction['to'],
                                    'value' => $transaction['value'],
                                    'contractAddress' => '0x00',
                                    'status' => null,
                                ];

                            $processing_batch_count++;

                            if ($processing_batch_count >= 100) // For debug purposes, we limit the number of mempool transactions to process
                            {
                                $break = true;
                                break;
                            }
                        }
                    }

                    if ($break) break;
                }
            }
            else//if (zkEVM)
            {
                // For zkEVM, we request two latest numbers: zkevm_virtualBatchNumber which is processed as a "block", and
                // zkevm_batchNumber which is the latest batch of "trusted state" transactions (see https://zkevm.polygon.technology/faq/zkevm-protocol-faq/)
                $multi_curl = [];

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['jsonrpc' => '2.0', 'method' => 'zkevm_virtualBatchNumber', 'id' => 0], timeout: $this->timeout);
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['jsonrpc' => '2.0', 'method' => 'zkevm_batchNumber', 'id' => 1], timeout: $this->timeout);

                $multi_curl_results = requester_multi($multi_curl,
                    limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout);

                $latest_numbers = requester_multi_process_all($multi_curl_results,
                    result_in: 'result',
                    post_process: 'to_int64_from_0xhex');

                $multi_curl = [];

                // Then we resuest all these batches and treat them as "mempool"
                for ($i = $latest_numbers[0] + 1; $i <= $latest_numbers[1]; $i++)
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['method'  => 'zkevm_getBatchByNumber',
                                 'params'  => [to_0xhex_from_int64($i),
                                               true,
                                 ],
                                 'id'      => (string)$i,
                                 'jsonrpc' => '2.0',
                        ], timeout: $this->timeout);
                }

                $multi_curl_results = requester_multi($multi_curl,
                    limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout);

                $pending_batches = requester_multi_process_all($multi_curl_results,
                    result_in: 'result',
                    reorder: false);

                foreach ($pending_batches as $pending_batch)
                {
                    if ($pending_batch['transactions'])
                    {
                        foreach ($pending_batch['transactions'] as $transaction)
                        {
                            if (!isset($this->processed_transactions[($transaction['hash'])]))
                            {
                                $transaction_data[($transaction['hash'])] =
                                    [
                                        'from'            => $transaction['from'],
                                        'to'              => $transaction['to'],
                                        'value'           => $transaction['value'],
                                        'contractAddress' => '0x00',
                                        'status'          => null,
                                    ];
                            }
                        }
                    }
                }
            }
        }

        //////////////////////
        // Preparing events //
        //////////////////////

        $events = [];

        $ijk = 0;

        foreach ($transaction_data as $transaction_hash => $transaction)
        {
            if ($block_id !== MEMPOOL)
            {
                $this_gas_used = to_int256_from_0xhex($transaction['gasUsed']);
                $this_burned = (!is_null($base_fee_per_gas)) ? bcmul($base_fee_per_gas, $this_gas_used) : '0';
                $this_to_miner = bcsub(bcmul(to_int256_from_0xhex($transaction['effectiveGasPrice']), $this_gas_used), $this_burned);

                if (in_array(EVMSpecialFeatures::EffectiveGasPriceCanBeZero, $this->extra_features))
                    if ($transaction['effectiveGasPrice'] === '0x0')
                        $this_to_miner = '0';

                // The fee is $this_burned + $this_to_miner

                if (in_array(EVMSpecialFeatures::HasSystemTransactions, $this->extra_features))
                {
                    if ($transaction['type'] === '0x7e')
                    {
                        $this_burned = $this_to_miner = '0';
                    }
                }
            }
            else
            {
                $this_burned = '0';
                $this_to_miner = '0';
            }

            // Burning
            if ($this_burned !== '0')
            {
                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => $transaction['from'],
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 0,
                    'effect' => '-' . $this_burned,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::Burning->value,
                ];

                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => '0x00',
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 1,
                    'effect' => $this_burned,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::Burning->value,
                ];
            }

            // Miner fee
            if ($this_to_miner !== '0')
            {
                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => $transaction['from'],
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 2,
                    'effect' => '-' . $this_to_miner,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::FeeToMiner->value,
                ];

                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => $miner,
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 3,
                    'effect' => $this_to_miner,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::FeeToMiner->value,
                ];
            }

            // The transfer itself

            $events[] = [
                'transaction' => $transaction_hash,
                'address' => $transaction['from'],
                'sort_in_block' => $ijk,
                'sort_in_transaction' => 4,
                'effect' => '-' . to_int256_from_0xhex($transaction['value']),
                'failed' => ($transaction['status'] === '0x1') ? false : true,
                'extra' => null,
            ];

            $extra_bit = null;

            if ($block_id !== MEMPOOL)
            {
                if (isset($transaction['contractAddress']))
                {
                    $extra_bit = EVMSpecialTransactions::ContractCreation->value;
                }
            }
            else
            {
                if ($transaction['contractAddress'] === '0x00' && !isset($transaction['to']))
                {
                    $extra_bit = EVMSpecialTransactions::ContractCreation->value;
                }
            }

            if (in_array(EVMSpecialFeatures::AllowEmptyRecipient, $this->extra_features))
                $recipient = $transaction['to'] ?? $transaction['contractAddress'] ?? '0x00';
            else
                $recipient = $transaction['to'] ?? $transaction['contractAddress'] ?? throw new DeveloperError('No address');

            $events[] = [
                'transaction' => $transaction_hash,
                'address' => $recipient,
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 5,
                'effect' => to_int256_from_0xhex($transaction['value']),
                'failed' => ($transaction['status'] === '0x1') ? false : true,
                'extra' => $extra_bit,
            ];
        }

        // Miner rewards
        // https://ethereum.stackexchange.com/questions/76259/how-to-know-the-current-block-reward-in-ethereum
        // https://medium.com/@ShariHunt/there-are-two-uncle-rewards-a67e06fa17de
        // https://github.com/ethereum/go-ethereum/blob/3a5a5599dd387e70da2df3240fa5553722851bb9/consensus/ethash/consensus.go#L40
        // https://ethereum.stackexchange.com/a/126587

        if ($block_id !== MEMPOOL)
        {
            $base_reward = ($this->reward_function)($block_id);

            // Uncles

            if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features) && $uncle_count)
            {
                $uncle_data = [];
                $multi_curl = [];
                $ij = 0;

                foreach ($uncles as $uncle)
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['jsonrpc' => '2.0',
                                 'method'  => 'eth_getUncleByBlockNumberAndIndex',
                                 'params'  => [to_0xhex_from_int64($block_id),to_0xhex_from_int64($ij)],
                                 'id'      => $ij++,
                        ], timeout: $this->timeout);

                $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

                foreach ($curl_results as $result)
                    $uncle_data[] = requester_multi_process($result);

                reorder_by_id($uncle_data);

                foreach ($uncle_data as &$uncle)
                    $uncle = $uncle['result'];

                unset($uncle);

                $uncle_rewards = [];

                foreach ($uncle_data as $uncle) // (Uncle Number + 8 — Block Number) * Miner’s Reward / 8
                {
                    $uncle_reward = bcmul((string)(to_int64_from_0xhex($uncle['number']) + 8 - $block_id), bcdiv($base_reward, '8'));
                    $uncle_rewards[] = [$uncle['miner'], $uncle_reward];
                }

                foreach ($uncle_rewards as $uncle_reward)
                {
                    $events[] = [
                        'transaction' => null,
                        'address' => '0x00',
                        'sort_in_block' => $ijk,
                        'sort_in_transaction' => 0,
                        'effect' => '-' . $uncle_reward[1],
                        'failed' => false,
                        'extra' => EVMSpecialTransactions::UncleReward->value,
                    ];

                    $events[] = [
                        'transaction' => null,
                        'address' => $uncle_reward[0],
                        'sort_in_block' => $ijk++,
                        'sort_in_transaction' => 1,
                        'effect' => $uncle_reward[1],
                        'failed' => false,
                        'extra' => EVMSpecialTransactions::UncleReward->value,
                    ];
                }

                // Uncle inclusion reward

                $uncle_inclusion_reward = bcmul(bcdiv($base_reward, '32'), (string)$uncle_count);

                $events[] = [
                    'transaction' => null,
                    'address' => '0x00',
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 0,
                    'effect' => '-' . $uncle_inclusion_reward,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::UncleInclusionReward->value,
                ];

                $events[] = [
                    'transaction' => null,
                    'address' => $miner,
                    'sort_in_block' => $ijk++,
                    'sort_in_transaction' => 1,
                    'effect' => $uncle_inclusion_reward,
                    'failed' => false,
                    'extra' => EVMSpecialTransactions::UncleInclusionReward->value,
                ];
            }

            // Base reward

            $events[] = [
                'transaction' => null,
                'address' => '0x00',
                'sort_in_block' => $ijk,
                'sort_in_transaction' => 0,
                'effect' => '-' . $base_reward,
                'failed' => false,
                'extra' => EVMSpecialTransactions::BlockReward->value,
            ];

            $events[] = [
                'transaction' => null,
                'address' => $miner,
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 1,
                'effect' => $base_reward,
                'failed' => false,
                'extra' => EVMSpecialTransactions::BlockReward->value,
            ];

            // Withdrawals come last

            if (in_array(EVMSpecialFeatures::PoSWithdrawals, $this->extra_features))
            {
                foreach ($withdrawals as $withdrawal)
                {
                    $events[] = [
                        'transaction' => null,
                        'address' => $this->staking_contract,
                        'sort_in_block' => $ijk,
                        'sort_in_transaction' => 0,
                        'effect' => '-' . to_int256_from_0xhex($withdrawal['amount']),
                        'failed' => false,
                        'extra' => EVMSpecialTransactions::Withdrawal->value,
                    ];

                    $events[] = [
                        'transaction' => null,
                        'address' => $withdrawal['address'],
                        'sort_in_block' => $ijk++,
                        'sort_in_transaction' => 1,
                        'effect' => to_int256_from_0xhex($withdrawal['amount']),
                        'failed' => false,
                        'extra' => EVMSpecialTransactions::Withdrawal->value,
                    ];
                }
            }
        }

        ////////////////
        // Processing //
        ////////////////

        $this_time = date('Y-m-d H:i:s');

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : $this_time;
        }

        // Resort

        if ($block_id !== MEMPOOL)
        {
            usort($events, function($a, $b) {
                return  [$a['sort_in_block'],
                         $a['sort_in_transaction'],
                    ]
                    <=>
                    [$b['sort_in_block'],
                     $b['sort_in_transaction'],
                    ];
            });
        }

        $sort_key = 0;

        $this_transaction = '';

        foreach ($events as &$event)
        {
            if ($block_id === MEMPOOL)
            {
                if ($this_transaction != $event['transaction'])
                {
                    $this_transaction = $event['transaction'];
                    $sort_key = 0;
                }
            }

            $event['sort_key'] = $sort_key;
            $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        $address = strtolower($address);

        if (!preg_match(StandardPatterns::iHexWith0x40->value, $address))
            return '0';

        return to_int256_from_0xhex(requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0', 'method' => 'eth_getBalance', 'params' => [$address, 'latest'], 'id' => 0],
            result_in: 'result', timeout: $this->timeout));
    }
}
