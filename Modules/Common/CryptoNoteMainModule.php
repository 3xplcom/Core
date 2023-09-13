<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is a parser for Monero-like blockchains. It requires `moneroexamples/onion-monero-blockchain-explorer` as a node to
 *  operate. `--enable-json-api` should be enabled.  */

abstract class CryptoNoteMainModule extends CoreModule
{
    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

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

    public ?PrivacyModel $privacy_model = PrivacyModel::Mixed; // Fees and coinbase rewards are visible for v2 transactions, the rest is not
    public ?bool $ignore_sum_of_all_effects = true; // In mixed transactions we know fee values, but don't know input and output values

    //

    private ?array $block_data = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    //

    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(), 'api/networkinfo')['data']['height'] - 1; // For some reason,
        // `height` returns a +1 value.
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        if (count($this->nodes) === 1)
        {
            $this->block_data = requester_single($this->select_node(), "api/block/{$block_id}", result_in: 'data');
        }
        else
        {
            $multi_curl = [];

            foreach ($this->nodes as $node)
            {
                $multi_curl[] = requester_multi_prepare($node, "api/block/{$block_id}", timeout: $this->timeout);
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

            $this->block_data = requester_multi_process($curl_results[0], result_in: 'data');
            $first_hash = $this->block_data['hash'];

            if (count($curl_results) > 1)
            {
                foreach ($curl_results as $result)
                {
                    if (requester_multi_process($result, result_in: 'data')['hash'] !== $first_hash)
                    {
                        throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                    }
                }
            }
        }

        if (!isset($this->block_data['hash']))
            ddd($this->block_data);

        $this->block_hash = $this->block_data['hash'];
        $this->block_time = $this->block_data['timestamp_utc'];
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id !== MEMPOOL)
        {
            if (is_null($this->block_data))
                $this->ensure_block($block_id);
        }
        else // Processing mempool
        {
            $this->block_data = requester_single($this->select_node(),
                endpoint: 'api/mempool',
                timeout: $this->timeout,
                result_in: 'data');
        }

        $multi_curl = [];

        foreach ($this->block_data['txs'] as $tx)
        {
            if (!isset($this->processed_transactions[$tx['tx_hash']]))
            {
                $multi_curl[] = requester_multi_prepare(
                    $this->select_node(),
                    endpoint: "api/transaction/{$tx['tx_hash']}",
                    timeout: $this->timeout
                );
            }
        }

        $multi_results = requester_multi_process_all(requester_multi($multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout),
            result_in: 'data',
            reorder: false);

        $transaction_details = [];

        foreach ($multi_results as $transaction)
        {
            $transaction_details[($transaction['tx_hash'])] = $transaction;
        }

        $events = []; // This is an array for the results
        $sort_key = 1;
        $coinbase_transactions = 0;

        if ($block_id !== MEMPOOL && !$this->block_data['txs'][0]['coinbase'])
            throw new ModuleError('The first transaction is not coinbase');

        if (count($this->block_data['txs']) !== count($transaction_details) && $block_id !== MEMPOOL)
            throw new ModuleError('Transaction count mismatch');

        foreach ($this->block_data['txs'] as $transaction)
        {
            if (isset($this->processed_transactions[$transaction['tx_hash']]))
                continue;

            if (!in_array($transaction['tx_version'], ['1', '2']))
                throw new ModuleError('Unknown transaction version');

            $hash = $transaction['tx_hash'];

            if ($transaction['coinbase'])
            {
                $coinbase_transactions++;

                if ($transaction['tx_fee'] !== '0')
                    throw new ModuleError('The coinbase transaction fee is not 0');

                $total_coinbase = '0';

                foreach ($transaction_details[$hash]['outputs'] as $output)
                {
                    if ($output['amount'] === '0')
                        throw new ModuleError('Zero coinbase output'); // That'd cause undefined behaviour as
                    // `tx_version` can be both `1` and `2` for transparent coinbase transactions, and we won't have
                    // a way to differ +0 from +?

                    $events[] = ['transaction' => $hash,
                                 'address'     => 'shielded-pool',
                                 'effect'      => $output['amount'],
                                 'sort_key'    => $sort_key++,
                    ];

                    $total_coinbase = bcadd($total_coinbase, $output['amount']);
                }

                $events[] = ['transaction' => $hash,
                             'address'     => 'the-void',
                             'effect'      => '-' . $total_coinbase,
                             'sort_key'    => 0,
                ];
            }
            else
            {
                foreach ($transaction_details[$hash]['inputs'] as $input)
                {
                    $amount = ($transaction['tx_version'] === '1') ? '-' . $input['amount'] : '-?';
                    // In Monero, 1220516 is the latest block with visible transaction amounts

                    $events[] = ['transaction' => $hash,
                                 'address'     => 'shielded-pool',
                                 'effect'      => $amount,
                                 'sort_key'    => $sort_key++,
                    ];
                }

                foreach ($transaction_details[$hash]['outputs'] ?? [] as $output)
                {
                    // Some transactions don't have outputs, thus `?? []` (the entire input amount goes to the fee pool in that case)

                    $amount = ($transaction['tx_version'] === '1') ? $output['amount'] : '+?';

                    $events[] = ['transaction' => $hash,
                                 'address'     => 'shielded-pool',
                                 'effect'      => $amount,
                                 'sort_key'    => $sort_key++,
                    ];
                }

                $events[] = ['transaction' => $hash,
                             'address'     => 'the-void',
                             'effect'      => $transaction['tx_fee'],
                             'sort_key'    => $sort_key++,
                ];
            }
        }

        if ($coinbase_transactions !== 1 && $block_id !== MEMPOOL)
            throw new ModuleError('Wrong number of coinbase transactions');

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = ($this->block_id !== MEMPOOL) ? $this->block_time : date('Y-m-d H:i:s', time());
        }

        usort($events, function($a, $b) {
            return $a['sort_key'] <=> $b['sort_key'];
        });

        $this->set_return_events($events);
    }
}
