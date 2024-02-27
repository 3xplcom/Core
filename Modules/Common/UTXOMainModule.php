<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process main UTXO transfers. Requires a Bitcoin Core-like node.  */

abstract class UTXOMainModule extends CoreModule
{
    use UTXOTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::LastEventToTheVoid;
    public ?array $special_addresses = ['the-void', 'script-*'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent; // This also includes Zcash as we show value changes for the shielded pools

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    // Blockchain-specific

    public array $extra_features = [];
    public ?string $p2pk_prefix1 = null;
    public ?string $p2pk_prefix2 = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->p2pk_prefix1)) throw new DeveloperError("`p2pk_prefix1` is not set");
        if (is_null($this->p2pk_prefix2)) throw new DeveloperError("`p2pk_prefix2` is not set");

        if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
            $this->special_addresses[] = 'hogwarts';
        if (in_array(UTXOSpecialFeatures::HasShieldedPools, $this->extra_features))
            $this->special_addresses[] = '*-pool';
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id !== MEMPOOL)
        {
            $block_hash = $this->block_hash;

            $block = requester_single($this->select_node(), endpoint: "rest/block/{$block_hash}.json", timeout: $this->timeout);

            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);
        }
        else // Processing mempool
        {
            $block = [];
            $block['tx'] = [];
            $multi_curl = [];

            $mempool = requester_single($this->select_node(), params: ['method' => 'getrawmempool', 'params' => [false]], result_in: 'result', timeout: $this->timeout);

            $islice = 0;

            foreach ($mempool as $tx_hash)
            {
                if (!isset($this->processed_transactions[$tx_hash]))
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['method' => 'getrawtransaction', 'params' => [$tx_hash, 1]],
                        timeout: $this->timeout);

                    $islice++;
                    if ($islice >= 100) break; // For debug purposes, we limit the number of mempool transactions to process
                }
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
            {
                $block['tx'][] = requester_multi_process($v, result_in: 'result');
            }
        }

        // The main problem with processing Bitcoin-like blocks is inputs. Currently, Bitcoin Core doesn't show neither addresses, nor
        // values for inputs. The only RPC output is the previous transaction hash and the previous output index. In order to get
        // info on the input, we need to query that previous transaction. This is a resource-consuming operation, so we're doing some
        // optimizations, such as not requesting the previous transaction if it's in the same block.

        $events = []; // This is an array for the results

        $previous_outputs_lib = [];
        $populate_outputs_lib_with = [];
        $GLOBALS['populate_outputs_lib_with_indexes'] = [];
        $inputs_to_check = [];
        $sort_in_block_lib = [];
        $fees = [];

        $block_n = 0;

        $coinbase_transaction_output = '0';

        $this_is_coinbase = true; // Coinbase transaction is always the first one
        if ($this->block_id === MEMPOOL) $this_is_coinbase = false;

        // Litecoin-like MWEB
        if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
            $skip_mweb_txs = [];

        // eCash P2PK
        if (in_array(UTXOSpecialFeatures::ManualCashAddress, $this->extra_features))
            require_once __DIR__ . '/../../Engine/Crypto/CashAddress.php';

        foreach ($block['tx'] as $transaction)
        {
            $previous_outputs_lib[($transaction['txid'])] = $transaction['vout'];
            $fees[($transaction['txid'])] = '0';

            foreach ($transaction['vout'] as $output)
            {
                if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
                {
                    if ($this->block_id === MEMPOOL && !isset($output['scriptPubKey']) && isset($transaction['vkern']))
                    {
                        $skip_mweb_txs[] = $transaction['txid'];
                        continue;
                    }
                }

                if (!in_array(UTXOSpecialFeatures::OneAddressInScriptPubKey, $this->extra_features))
                {
                    if (isset($output['scriptPubKey']['addresses'][0]) && count($output['scriptPubKey']['addresses']) === 1)
                        $address = $output['scriptPubKey']['addresses'][0];
                    else
                        $address = 'script-' . substr(hash('sha256', $output['scriptPubKey']['hex']), 0, 32);
                    // We use special `script-...` address format for all outputs which don't have a standard representation
                }
                else // OneAddressInScriptPubKey
                {
                    if (isset($output['scriptPubKey']['address']))
                        $address = $output['scriptPubKey']['address'];
                    else
                        $address = 'script-' . substr(hash('sha256', $output['scriptPubKey']['hex']), 0, 32);
                }

                if (!in_array(UTXOSpecialFeatures::IgnorePubKeyConversion, $this->extra_features))
                    if ($output['scriptPubKey']['type'] === 'pubkey')
                        $address = CryptoP2PK::process($output['scriptPubKey']['asm'], $this->p2pk_prefix1, $this->p2pk_prefix2);

                if (in_array(UTXOSpecialFeatures::ManualCashAddress, $this->extra_features))
                {
                    if ($output['scriptPubKey']['type'] === 'pubkey')
                    {
                        $address = CashAddressP2PK::old2new($address); // We're getting bitcoincash: here
                        $cashaddr = new CashAddress();
                        $decoded = $cashaddr->decode($address);
                        $address = $cashaddr->encode($this->blockchain, $decoded['type'], $decoded['hash']);
                    }
                }

                if (in_array(UTXOSpecialFeatures::HasAddressPrefixes, $this->extra_features))
                    if (str_contains($address, ':'))
                        $address = explode(':', $address)[1];

                if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
                    if (in_array($output['scriptPubKey']['type'], ['witness_mweb_hogaddr', 'witness_mweb_pegin']))
                        $address = 'hogwarts';

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => $address,
                             'effect'      => satoshi($output['value'], $this),
                             'sort_in_transaction' => ((int)$output['n'] + 1)
                ];

                if ($this_is_coinbase)
                {
                    $coinbase_transaction_output = bcsub($coinbase_transaction_output, satoshi($output['value'], $this));
                }
                else
                {
                    $fees[($transaction['txid'])] = bcsub($fees[($transaction['txid'])], satoshi($output['value'], $this));
                }
            }

            $this_is_coinbase = false;

            // Processing shielded pools (ZK)

            if (in_array(UTXOSpecialFeatures::HasShieldedPools, $this->extra_features))
            {
                if (
                    (
                        count($transaction['vin']) +
                        count($transaction['vout']) +
                        (int)(isset($transaction['vjoinsplit']) && $transaction['vjoinsplit']) +
                        (int)((isset($transaction['vShieldedSpend']) && $transaction['vShieldedSpend']) || (isset($transaction['vShieldedOutput']) && $transaction['vShieldedOutput'])) +
                        (int)(isset($transaction['orchard']) && $transaction['orchard'] && $transaction['orchard']['actions'])
                    ) === 0
                )
                    throw new ModuleError("No events for transaction {$transaction['txid']}");

                // Sprout

                if (isset($transaction['vjoinsplit']) && $transaction['vjoinsplit'])
                {
                    $total_split = '0';

                    foreach ($transaction['vjoinsplit'] as $this_vjoinsplit)
                        $total_split = bcadd($total_split, bcsub($this_vjoinsplit['vpub_oldZat'], $this_vjoinsplit['vpub_newZat']));

                    $events[] = ['transaction' => $transaction['txid'],
                                 'address'     => 'sprout-pool',
                                 'effect'      => $total_split,
                                 'sort_in_transaction' => PHP_INT_MAX - 3,
                    ];

                    $fees[($transaction['txid'])] = bcadd($fees[($transaction['txid'])], bcmul('-1', $total_split));
                }

                // Sapling

                if ((isset($transaction['vShieldedSpend']) && $transaction['vShieldedSpend']) || (isset($transaction['vShieldedOutput']) && $transaction['vShieldedOutput']))
                {
                    $events[] = ['transaction' => $transaction['txid'],
                                 'address'     => 'sapling-pool',
                                 'effect'      => bcmul('-1', $transaction['valueBalanceZat']),
                                 'sort_in_transaction' => PHP_INT_MAX - 2,
                    ];

                    $fees[($transaction['txid'])] = bcadd($fees[($transaction['txid'])], $transaction['valueBalanceZat']);
                }

                // Orchard

                if (isset($transaction['orchard']) && $transaction['orchard'] && $transaction['orchard']['actions'])
                {
                    $events[] = ['transaction' => $transaction['txid'],
                                 'address'     => 'orchard-pool',
                                 'effect'      => bcmul('-1', $transaction['orchard']['valueBalanceZat']),
                                 'sort_in_transaction' => PHP_INT_MAX - 1,
                    ];

                    $fees[($transaction['txid'])] = bcadd($fees[($transaction['txid'])], $transaction['orchard']['valueBalanceZat']);
                }
            }

            $sort_in_block_lib[($transaction['txid'])] = $block_n;
            $block_n++;
        }

        foreach ($block['tx'] as $transaction)
        {
            if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
                if (in_array($transaction['txid'], $skip_mweb_txs))
                    continue;

            $this_n = 1;

            foreach ($transaction['vin'] as $input)
            {
                if (isset($input['coinbase']))
                {
                    if ($coinbase_transaction_output === '0') $coinbase_transaction_output = '-0'; // E.g. block #501726 in Bitcoin

                    $events[] = ['transaction'         => $transaction['txid'],
                                 'address'             => 'the-void',
                                 'effect'              => $coinbase_transaction_output,
                                 'sort_in_transaction' => -1,
                    ];
                }
                else
                {
                    if (!isset($previous_outputs_lib[($input['txid'])]))
                    {
                        $populate_outputs_lib_with[] = $input['txid'];
                        $GLOBALS['populate_outputs_lib_with_indexes'][$input['txid']][] = $input['vout'];
                    }

                    $inputs_to_check[] = ['this_transaction'     => $transaction['txid'],
                                          'previous_transaction' => $input['txid'],
                                          'previous_n'           => $input['vout'],
                                          'this_n'               => -$this_n,
                    ];

                    $this_n++;
                }
            }
        }

        $multi_curl = [];

        $populate_outputs_lib_with = array_unique($populate_outputs_lib_with);

        // Get input data from the node

        foreach ($populate_outputs_lib_with as $tx_hash)
        {
            $multi_curl[] = requester_multi_prepare($this->select_node(),
                params: ['method' => 'getrawtransaction', 'params' => [$tx_hash, 1]],
                timeout: $this->timeout);
        }

        $curl_results = requester_multi($multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout, post_process: function($output) {
                $output = requester_multi_process($output, result_in: 'result');

                foreach ($output['vout'] as $vok => $vov)
                {
                    if (!in_array($vok, $GLOBALS['populate_outputs_lib_with_indexes'][$output['txid']]))
                        unset($output['vout'][$vok]);
                }

                return ['txid' => $output['txid'], 'vout' => $output['vout']];
            });

        foreach ($curl_results as $output)
        {
            $previous_outputs_lib[$output['txid']] = $output['vout'];
        }

        foreach ($inputs_to_check as $input)
        {
            if (isset($previous_outputs_lib[($input['previous_transaction'])]))
            {
                $previous_output = $previous_outputs_lib[($input['previous_transaction'])];

                if (!in_array(UTXOSpecialFeatures::OneAddressInScriptPubKey, $this->extra_features))
                {
                    if (isset($previous_output[($input['previous_n'])]['scriptPubKey']['addresses'][0]) && count($previous_output[($input['previous_n'])]['scriptPubKey']['addresses']) === 1)
                        $address = $previous_output[($input['previous_n'])]['scriptPubKey']['addresses'][0];
                    else
                        $address = 'script-' . substr(hash('sha256', $previous_output[($input['previous_n'])]['scriptPubKey']['hex']), 0, 32);
                }
                else // OneAddressInScriptPubKey
                {
                    if (isset($previous_output[($input['previous_n'])]['scriptPubKey']['address']))
                        $address = $previous_output[($input['previous_n'])]['scriptPubKey']['address'];
                    else
                        $address = 'script-' . substr(hash('sha256', $previous_output[($input['previous_n'])]['scriptPubKey']['hex']), 0, 32);
                }

                if (!in_array(UTXOSpecialFeatures::IgnorePubKeyConversion, $this->extra_features))
                    if ($previous_output[($input['previous_n'])]['scriptPubKey']['type'] === 'pubkey')
                        $address = CryptoP2PK::process($previous_output[($input['previous_n'])]['scriptPubKey']['asm'], $this->p2pk_prefix1, $this->p2pk_prefix2);

                if (in_array(UTXOSpecialFeatures::ManualCashAddress, $this->extra_features))
                {
                    if ($previous_output[($input['previous_n'])]['scriptPubKey']['type'] === 'pubkey')
                    {
                        $address = CashAddressP2PK::old2new($address); // We're getting bitcoincash: here
                        $cashaddr = new CashAddress();
                        $decoded = $cashaddr->decode($address);
                        $address = $cashaddr->encode($this->blockchain, $decoded['type'], $decoded['hash']);
                    }
                }

                if (in_array(UTXOSpecialFeatures::HasMWEB, $this->extra_features))
                    if (in_array($previous_output[($input['previous_n'])]['scriptPubKey']['type'], ['witness_mweb_hogaddr', 'witness_mweb_pegin']))
                        $address = 'hogwarts';

                if (in_array(UTXOSpecialFeatures::HasAddressPrefixes, $this->extra_features))
                    if (str_contains($address, ':'))
                        $address = explode(':', $address)[1];

                $events[] = ['transaction' => $input['this_transaction'],
                             'address'     => $address,
                             'effect'      => "-" . satoshi($previous_output[($input['previous_n'])]['value'], $this),
                             'sort_in_transaction' => (int)$input['this_n'],
                ];

                $fees[($input['this_transaction'])] = bcadd($fees[($input['this_transaction'])], satoshi($previous_output[($input['previous_n'])]['value'], $this));
            }
            else
            {
                throw new ModuleError('Input is not in the library');
            }
        }

        foreach ($fees as $txid => $fee_transfer)
        {
            if ($fee_transfer !== '0')
            {
                $events[] = ['transaction' => $txid,
                             'address'     => 'the-void',
                             'effect'      => $fee_transfer,
                             'sort_in_transaction' => PHP_INT_MAX
                ];
            }
        }

        // Extra checks for the shielded pools

        if (in_array(UTXOSpecialFeatures::HasShieldedPools, $this->extra_features) && $block_id !== MEMPOOL)
        {
            $delta_pools = [];
            $this_pools = ['transparent' => '0', 'sprout' => '0', 'sapling' => '0', 'orchard' => '0'];

            foreach ($block['valuePools'] as $pool)
                $delta_pools[($pool['id'])] = $pool['valueDeltaZat'];

            foreach ($events as $event)
            {
                if ($event['address'] === 'the-void')
                    $this_pools['transparent'] = bcsub($this_pools['transparent'], $event['effect']);
                if ($event['address'] === 'sprout-pool')
                    $this_pools['sprout'] = bcadd($this_pools['sprout'], $event['effect']);
                if ($event['address'] === 'sapling-pool')
                    $this_pools['sapling'] = bcadd($this_pools['sapling'], $event['effect']);
                if ($event['address'] === 'orchard-pool')
                    $this_pools['orchard'] = bcadd($this_pools['orchard'], $event['effect']);
            }

            $this_pools['transparent'] = bcsub($this_pools['transparent'], $this_pools['sprout']);
            $this_pools['transparent'] = bcsub($this_pools['transparent'], $this_pools['sapling']);
            $this_pools['transparent'] = bcsub($this_pools['transparent'], $this_pools['orchard']);

            // Check if the coinbase value is correct

            $check_coinbase_amount = bcmul('-1', $coinbase_transaction_output);

            foreach ($fees as $fee)
                $check_coinbase_amount = bcsub($check_coinbase_amount, $fee);

            if ($check_coinbase_amount !== $block['chainSupply']['valueDeltaZat'])
                throw new ModuleError("Wrong coinbase value: {$check_coinbase_amount} vs. {$block['chainSupply']['valueDeltaZat']}");

            // Check if deltas for shielded pools are correct

            foreach ($delta_pools as $pool => $value)
            {
                if (!isset($this_pools[$pool]))
                    throw new ModuleError("Unknown shielded pool: {$pool}");

                if ($delta_pools[$pool] !== $this_pools[$pool])
                    throw new ModuleError("Pool delta mismatch for {$pool}: should be {$delta_pools[$pool]}, got {$this_pools[$pool]}");
            }
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['sort_in_block'] = $sort_in_block_lib[($event['transaction'])];
            $event['time'] = date('Y-m-d H:i:s', (($this->block_id !== MEMPOOL) ? (int)$block['time'] : time()));
        }

        // Resort

        usort($events, function($a, $b) {
            return  [$a['sort_in_block'],
                     !str_starts_with($a['effect'], '-'),
                     abs($a['sort_in_transaction']),
                ]
                <=>
                [$b['sort_in_block'],
                 !str_starts_with($b['effect'], '-'),
                 abs($b['sort_in_transaction']),
                ];
        });

        $sort_key = 0;
        $latest_tx_hash = ''; // This is for mempool

        foreach ($events as &$event)
        {
            if ($this->block_id === MEMPOOL && $event['transaction'] !== $latest_tx_hash)
            {
                $latest_tx_hash = $event['transaction'];
                $sort_key = 0;
            }

            $event['sort_key'] = $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
    }
}
