<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2025 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main Zano transfers. Requires a Zano node.  */

abstract class ZanoLikeMainModule extends CoreModule
{
    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false; // we always have even a coinbase tx

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric; // There are two addresses only: `the-void` and `shielded-pool`
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::LastEventToTheVoid;
    public ?array $special_addresses = ['the-void', 'shielded-pool'];

    public ?PrivacyModel $privacy_model = PrivacyModel::Mixed; // Fees, coinbase rewards & some amounts are visible
    public ?bool $ignore_sum_of_all_effects = true; // In mixed transactions we know fee values, but don't know input and output values

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    //

    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(), '/getheight')['height'];
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $params = ['jsonrpc'=> '2.0', 'method' => 'getblockheaderbyheight', 'params' => ['height' => $block_id], 'id' => 0];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, params: $params, endpoint: '/json_rpc', timeout: $this->timeout);
            if ($break_on_first) break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $result0 = requester_multi_process($curl_results[0], result_in: 'result');

        $this->block_hash = $result0['block_header']['hash'];
        $this->block_time = date('Y-m-d H:i:s', $result0['block_header']['timestamp']);

        if (count($curl_results) > 1)
        {
            foreach ($curl_results as $result)
            {
                if (requester_multi_process($result, result_in: 'result')['block_header']['hash'] !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    final public function pre_process_block($block_id)
    {
        $transactions = null;
        if ($block_id !== MEMPOOL)
        {
            $output = requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0',
                     'method'  => 'get_main_block_details',
                     'id'      => 0,
                     'params'  => [ 'id' => "{$this->block_hash}" ],
            ],
            endpoint: '/json_rpc',
            result_in: 'result',
            timeout: $this->timeout);
            $transactions['txs'] = $output['block_details']['transactions_details'];
        }
        else // Processing mempool
        {
            $output = requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0',
                     'method'  => 'get_all_pool_tx_list',
                     'id'      => 0,
                     'params'  => [ 'id' => "{$this->block_hash}" ],
            ],
            endpoint: '/json_rpc',
            result_in: 'result',
            timeout: $this->timeout);
            $mp = array_map(fn($id) => ['id' => $id], $output['ids']);
            $transactions['txs'] = $mp;
        }

        $multi_curl = [];
        foreach ($transactions['txs'] as $tx) 
        {
            $multi_curl[] = requester_multi_prepare(
                $this->select_node(),
                params: [
                    'jsonrpc' => '2.0',
                    'method'  => 'get_tx_details',
                    'id'      => 0,
                    'params'  => ['tx_hash' => $tx['id']],
                ],
                endpoint: "/json_rpc",
                timeout: $this->timeout
            );
        }
        $multi_results = requester_multi_process_all(
            requester_multi(
                $multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout
            ),
            result_in: 'result',
            reorder: false
        );
        $transaction_details = [];

        foreach ($multi_results as $transaction) 
        {
            $transaction_details[($transaction['tx_info']['id'])] = $transaction;
        }

        $events = []; // This is an array for the results
        $sort_key = 0;

        foreach ($transactions['txs'] as $transaction)
        {
            $hash = $transaction['id'];

            // if ($transaction['coinbase'])
            //     // there is something to do with such transactions
            //     // we can't trace to whom they are sent
            //     // But each block has a 1 ZANO reward 
            //     // So for PoW blocks we would have 2 outputs and no inputs
            //     // https://explorer.zano.org/transaction/71e54a7fc1f92e9005b3a8d5ac5c92fa6d60cdd38d552c9637824f33d3c9b8df
            //     // For PoS blocks we have 1 input and 1 output
            //     // https://explorer.zano.org/transaction/a4a3ab82fb5d2d5454b45a3c7914d7326f2f9ac3dea9861d3f20c81a68073af4
            foreach ($transaction_details[$hash]['tx_info']['ins'] as $input) 
            {
                $amount = $input['amount'];

                $events[] = [
                    'transaction' => $hash,
                    'address'     => 'shielded-pool',
                    'effect'      => "{$amount}",
                    'sort_key'    => $sort_key++,
                ];
            }

            foreach ($transaction_details[$hash]['tx_info']['outs'] ?? [] as $output) 
            {
                $amount = $output['amount'];

                $events[] = [
                    'transaction' => $hash,
                    'address'     => 'shielded-pool',
                    'effect'      => "{$amount}",
                    'sort_key'    => $sort_key++,
                ];
            }

            // On Zano, all network fees are being burned, meaning that with enough network usage, 
            // the daily fee burning could surpass the emission from block rewards, 
            // resulting in supply becoming deflationary over time.
            // https://docs.zano.org/docs/learn/emission/#emission-motivation
            $events[] = [
                'transaction' => $hash,
                'address'     => 'the-void',
                'effect'      => "{$transaction_details[$hash]['tx_info']['fee']}",
                'sort_key'    => $sort_key++,
            ];
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : date('Y-m-d H:i:s', time());
        }

        usort($events, function($a, $b) {
            return $a['sort_key'] <=> $b['sort_key'];
        });

        $this->set_return_events($events);
    }

    final public function api_get_transaction_specials(string $transaction): array
    {
        $transaction = requester_single(
                $this->select_node(),
                params: [
                    'jsonrpc' => '2.0',
                    'method'  => 'get_tx_details',
                    'id'      => 0,
                    'params'  => ['tx_hash' => $transaction],
                ],
                endpoint: "/json_rpc",
                timeout: $this->timeout
            )['result'];

        $specials = new Specials();

        $specials->add('blob_size', (int)$transaction['tx_info']['blob_size'], function ($raw_value) { return "Blob size: {{$raw_value}} bytes"; });

        $outs = $transaction['tx_info']['outs'];
        $ins = $transaction['tx_info']['ins'];

        for ($i = 0; $i < count($ins); $i++) 
        {
            $global_indexes = implode(",", $ins[$i]['global_indexes']);
            $specials->add(
                'ins_' . ($i+1),
                $ins[$i]['global_indexes'],
                function () use ($global_indexes) {
                    return "Global Indexes: {[{$global_indexes}]}";
                }
            );
        }

        foreach ($outs as $out) 
        {
            $specials->add(
                'spent_global_index_' . $out['global_index'],
                $out['is_spent'],
                function () use ($out) {
                    return "Global Index {{$out['global_index']}} is " . ($out['is_spent'] ? "{spent}" : "{not spent}");
                }
            );
        }

        $specials->add(
                'pub_key',
                $transaction['tx_info']['pub_key'],
                function ($pubkey) {
                    return "Tx pubkey {{$pubkey}}";
                }
            );

        return $specials->return();
    }

}
