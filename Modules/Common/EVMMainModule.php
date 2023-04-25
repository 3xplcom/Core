<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes "extrnal" EVM transactions. Both geth and Erigon are supported.  */

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
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    // EVM-specific

    public ?EVMImplementation $evm_implementation = null;
    public array $extra_features = [];
    public ?Closure $reward_function = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->currency)) throw new DeveloperError("`currency` is not set (developer error)");
        if (is_null($this->evm_implementation)) throw new DeveloperError("`evm_implementation` is not set (developer error)");
        if (is_null($this->reward_function)) throw new DeveloperError("`reward_function` is not set (developer error)");
    }

    final public function pre_process_block($block_id)
    {
        // Erigon RPC docs: https://github.com/ledgerwatch/erigon/blob/devel/cmd/rpcdaemon/README.md

        // How blocks work:
        // 1. Transactions are executed
        // 2. If there are uncles, uncle miners are rewarded
        // 3. Block miner gets block reward + fees + uncle inclusion reward
        // Note that in PoS it's a bit different

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
                $multi_curl = [];

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => "2.0",
                             'method'  => 'eth_getBlockByNumber',
                             'params'  => [to_0xhex_from_int64($block_id),
                                          true,
                             ],
                             'id'      => 0,
                    ], timeout: $this->timeout);

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => "2.0",
                             'method'  => 'eth_getBlockReceipts',
                             'params'  => [to_0xhex_from_int64($block_id)],
                             'id'      => 0,
                    ], timeout: $this->timeout);

                $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

                $r1 = requester_multi_process($curl_results[0], result_in: 'result');
                $r2 = requester_multi_process($curl_results[1], result_in: 'result');
                // This should be rewritten in a better way using request ids

                if (isset($r1['difficulty'])) // $r1 is the response for eth_getBlockByNumber, $r2 is for eth_getBlockReceipts
                {
                    $general_data = $r1['transactions'];
                    $base_fee_per_gas = to_int256_from_0xhex($r1['baseFeePerGas'] ?? null);
                    $receipt_data = $r2;
                    $block_time = $r1['timestamp'];
                    $miner = $r1['miner'];

                    if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features))
                    {
                        $uncle_count = count($r1['uncles']);
                        $uncles = $r1['uncles'];
                    }
                }
                else // $r2 is the response for eth_getBlockByNumber, $r1 is for eth_getBlockReceipts
                {
                    $general_data = $r2['transactions'];
                    $base_fee_per_gas = to_int256_from_0xhex($r2['baseFeePerGas'] ?? null);
                    $receipt_data = $r1;
                    $block_time = $r2['timestamp'];
                    $miner = $r2['miner'];

                    if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features))
                    {
                        $uncle_count = count($r2['uncles']);
                        $uncles = $r2['uncles'];
                    }
                }
            }
            else // geth is slower as we have to do eth_getTransactionReceipt for every transaction separately
            {
                $r1 = requester_single($this->select_node(),
                    params: ['jsonrpc'=> '2.0', 'method' => 'eth_getBlockByNumber', 'params' => [to_0xhex_from_int64($block_id), true], 'id' => 0],
                    result_in: 'result', timeout: $this->timeout);

                $block_time = $r1['timestamp'];
                $miner = $r1['miner'];

                if (in_array(EVMSpecialFeatures::HasOrHadUncles, $this->extra_features))
                {
                    $uncle_count = count($r1['uncles']);
                    $uncles = $r1['uncles'];
                }

                $general_data = $r1['transactions'];
                $base_fee_per_gas = to_int256_from_0xhex($r1['baseFeePerGas'] ?? null);
                $receipt_data = [];

                $multi_curl = [];

                $ij = 0;

                foreach ($r1['transactions'] as $transaction)
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['jsonrpc'=> '2.0', 'method' => 'eth_getTransactionReceipt', 'params' => [$transaction['hash']], 'id' => $ij++], timeout: $this->timeout);
                }

                $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout);

                foreach ($curl_results as $result)
                {
                    $receipt_data[] = requester_multi_process($result);
                }

                reorder_by_id($receipt_data);

                foreach ($receipt_data as &$receipt)
                {
                    $receipt = $receipt['result'];
                }
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
            }
        }
        else // Mempool processing
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
                // The fee is $this_burned + $this_to_miner
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
                    'failed' => 'f',
                    'extra' => EVMSpecialTransactions::Burning->value,
                ];

                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => '0x00',
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 1,
                    'effect' => $this_burned,
                    'failed' => 'f',
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
                    'failed' => 'f',
                    'extra' => EVMSpecialTransactions::FeeToMiner->value,
                ];

                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => $miner,
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 3,
                    'effect' => $this_to_miner,
                    'failed' => 'f',
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
                'failed' => ($transaction['status'] === '0x1') ? 'f' : 't',
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

            $events[] = [
                'transaction' => $transaction_hash,
                'address' => $transaction['to'] ?? $transaction['contractAddress'] ?? throw new DeveloperError('No address'),
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 5,
                'effect' => to_int256_from_0xhex($transaction['value']),
                'failed' => ($transaction['status'] === '0x1') ? 'f' : 't',
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
                        params: ['jsonrpc'=> '2.0', 'method' => 'eth_getUncleByBlockNumberAndIndex', 'params' => [to_0xhex_from_int64($block_id), to_0xhex_from_int64($ij)],
                                 'id' => $ij++], timeout: $this->timeout);

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
                        'failed' => 'f',
                        'extra' => EVMSpecialTransactions::UncleReward->value,
                    ];

                    $events[] = [
                        'transaction' => null,
                        'address' => $uncle_reward[0],
                        'sort_in_block' => $ijk++,
                        'sort_in_transaction' => 1,
                        'effect' => $uncle_reward[1],
                        'failed' => 'f',
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
                    'failed' => 'f',
                    'extra' => EVMSpecialTransactions::UncleInclusionReward->value,
                ];

                $events[] = [
                    'transaction' => null,
                    'address' => $miner,
                    'sort_in_block' => $ijk++,
                    'sort_in_transaction' => 1,
                    'effect' => $uncle_inclusion_reward,
                    'failed' => 'f',
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
                'failed' => 'f',
                'extra' => EVMSpecialTransactions::BlockReward->value,
            ];

            $events[] = [
                'transaction' => null,
                'address' => $miner,
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 1,
                'effect' => $base_reward,
                'failed' => 'f',
                'extra' => EVMSpecialTransactions::BlockReward->value,
            ];
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
            params: ['jsonrpc'=> '2.0', 'method' => 'eth_getBalance', 'params' => [$address, 'latest'], 'id' => 0],
            result_in: 'result', timeout: $this->timeout));
    }
}
