<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process main UTXO transfers. Requires an HSD-like node. The difference between this and
 *  UTXOMainModule is the `rest/block` output format from the node, and it also processes Handshake data
 *  in `extra` and `extra_indexed`.  */

abstract class HandshakeLikeMainModule extends CoreModule
{
    use UTXOTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::LastEventToTheVoid;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['extra', 'extra_indexed'];
    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id !== MEMPOOL)
        {
            $block_hash = $this->block_hash;

            $block = requester_single($this->select_node(),
                endpoint: "block/{$block_hash}",
                timeout: $this->timeout,
                flags: [RequesterOption::TrimJSON]);

            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);
        }
        else // Processing mempool
        {
            $block = $block['txs'] = $multi_curl = [];
            $islice = 0;

            $mempool = requester_single($this->select_node(),
                params: ['method' => 'getrawmempool', 'params' => [false]],
                result_in: 'result',
                timeout: $this->timeout);

            foreach ($mempool as $tx_hash)
            {
                if (!isset($this->processed_transactions[$tx_hash]))
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        endpoint: "tx/{$tx_hash}",
                        timeout: $this->timeout);

                    if ($islice++ >= 100) break; // For debug purposes, we limit the number of mempool transactions to process
                }
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
                $block['txs'][] = requester_multi_process($v, flags: [RequesterOption::TrimJSON]);
        }

        $events = $sort_in_block_lib = $fees = [];
        $block_n = 0;
        $coinbase_transaction_output = '0';
        $this_is_coinbase = true; // Coinbase transaction is always the first one
        if ($this->block_id === MEMPOOL) $this_is_coinbase = false;

        foreach ($block['txs'] as $transaction)
        {
            $fees[($transaction['hash'])] = '0';

            foreach ($transaction['outputs'] as $i => $output)
            {
                $events[] = ['transaction'         => $transaction['hash'],
                             'address'             => $output['address'],
                             'effect'              => ($output['value']),
                             'sort_in_transaction' => ($i + 1),
                             'extra'               => ($output['covenant']['action'] !== 'NONE') ? $output['covenant']['action'] : null,
                             'extra_indexed'       => ($output['covenant']['action'] !== 'NONE') ? $output['covenant']['items'][0] : null,
                ];

                if ($this_is_coinbase)
                {
                    $coinbase_transaction_output = bcsub($coinbase_transaction_output, ($output['value']));
                }
                else
                {
                    $fees[($transaction['hash'])] = bcsub($fees[($transaction['hash'])], ($output['value']));
                }
            }

            $this_is_coinbase = false;

            $sort_in_block_lib[($transaction['hash'])] = $block_n;
            $block_n++;
        }

        foreach ($block['txs'] as $transaction)
        {
            foreach ($transaction['inputs'] as $i => $input)
            {
                if ($input['prevout']['hash'] === '0000000000000000000000000000000000000000000000000000000000000000')
                {
                    if ($coinbase_transaction_output === '0') $coinbase_transaction_output = '-0'; // E.g. block #501726 in Bitcoin

                    if ($i === 0) // The rest are airdrops which are already included in $coinbase_transaction_output,
                    {             // example: coinbase transaction in block #2730 in Handshake
                        $events[] = ['transaction'         => $transaction['hash'],
                                     'address'             => 'the-void',
                                     'effect'              => $coinbase_transaction_output,
                                     'sort_in_transaction' => ($i + 1),
                                     'extra'               => null,
                                     'extra_indexed'       => null,
                        ];
                    }

                    if (isset($input['coin']))
                        throw new ModuleError('Suspicious coinbase transaction');
                }
                else
                {
                    $events[] = ['transaction'         => $transaction['hash'],
                                 'address'             => $input['coin']['address'],
                                 'effect'              => '-' . $input['coin']['value'],
                                 'sort_in_transaction' => -1,
                                 'extra'               => ($input['coin']['covenant']['action'] !== 'NONE') ? $input['coin']['covenant']['action'] : null,
                                 'extra_indexed'       => ($input['coin']['covenant']['action'] !== 'NONE') ? $input['coin']['covenant']['items'][0] : null,
                    ];

                    $fees[($transaction['hash'])] = bcadd($fees[($transaction['hash'])], ($input['coin']['value']));
                }
            }
        }

        foreach ($fees as $txid => $fee_transfer)
        {
            if ($fee_transfer !== '0')
            {
                $events[] = ['transaction'         => $txid,
                             'address'             => 'the-void',
                             'effect'              => $fee_transfer,
                             'sort_in_transaction' => PHP_INT_MAX,
                             'extra'               => null,
                             'extra_indexed'       => null,
                ];
            }
        }

        $hashes = [];

        foreach ($events as $event)
            if (!is_null($event['extra_indexed']))
                $hashes[] = $event['extra_indexed'];

        if ($hashes)
        {
            $names = [];
            $hashes = array_unique($hashes);

            $multi_curl = [];

            foreach ($hashes as $hash)
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method' => 'getnamebyhash', 'params' => [$hash], 'id' => $hash],
                    timeout: $this->timeout);

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
            {
                $name = requester_multi_process($v);
                $names[$name['id']] = $name['result'];
            }

            foreach ($events as &$event)
                if (!is_null($event['extra_indexed']))
                    $event['extra_indexed'] = $names[$event['extra_indexed']] . '/'; // Adding a trailing slash
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['sort_in_block'] = $sort_in_block_lib[($event['transaction'])];
            $event['time'] = date('Y-m-d H:i:s', (($this->block_id !== MEMPOOL) ? (int)$block['time'] : time()));
        }

        // Resort

        usort($events, function($a, $b) {
            return [$a['sort_in_block'],
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
