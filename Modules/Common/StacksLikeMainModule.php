<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes main Stacks transfers. Requires a Stacks node and API.  */

abstract class StacksLikeMainModule extends CoreModule
{
    use StacksLikeTraits;
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void', 'locker'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;
    public ?array $extra_data_details = null;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = false;

    public string $block_entity_name = 'block';
    public string $address_entity_name = 'account';
    private int $limit = 50;

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
        $curl_results = [];
        if ($block_id == MEMPOOL) 
        {
            $mempool = requester_single(
                $this->select_node(),
                endpoint: "/api/extended/v1/tx/mempool?limit={$this->limit}",
                timeout: $this->timeout
            );
            $amount = $mempool['total'];
            $curl_results = array_merge($curl_results, $mempool['results']);
            if (($amount - $this->limit) > $this->limit) 
            {
                for ($offset = $this->limit; $offset <= ($amount); $offset += $this->limit) 
                {
                    $multi_curl[] = requester_multi_prepare(
                        $this->select_node(),
                        endpoint: "/api/extended/v1/tx/mempool?limit={$this->limit}&offset=$offset",
                        timeout: $this->timeout
                    );
                }
                $results = requester_multi(
                    $multi_curl,
                    limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout
                );
                $results = requester_multi_process_all($results, reorder: false, result_in: 'results');
                foreach ($results as $r)
                    $curl_results = array_merge($curl_results, $r);
            } 
            else 
            {
                $curl_results = requester_single(
                    $this->select_node(),
                    endpoint: "/api/extended/v1/tx/mempool?limit={$this->limit}",
                    timeout: $this->timeout,
                    result_in: 'results'
                );
            }
        } 
        else 
        {
            $block = requester_single(
                $this->select_node(),
                endpoint: "/api/extended/v2/blocks/{$block_id}",
                timeout: $this->timeout
            );
            $true_block_time = date('Y-m-d H:i:s', (int)$block['block_time']);

            $this->block_time = $true_block_time;
            if ($block['tx_count'] == 0) 
            {
                $this->set_return_currencies([]);
                $this->set_return_events([]);
                return;
            }

            $multi_curl = [];
            if ($block['tx_count'] > $this->limit)
            {
                for ($offset = 0; $offset <= $block['tx_count']; $offset += $this->limit) 
                {
                    $multi_curl[] = requester_multi_prepare(
                        $this->select_node(),
                        endpoint: "/api/extended/v2/blocks/{$block_id}/transactions?limit={$this->limit}&offset=$offset",
                        timeout: $this->timeout
                    );
                }

                $results = requester_multi(
                    $multi_curl,
                    limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout
                );
                $results = requester_multi_process_all($results, reorder: false, result_in: 'results');
                foreach ($results as $r)
                    $curl_results = array_merge($curl_results, $r);
            } 
            else 
            {
                $curl_results = requester_single(
                    $this->select_node(),
                    endpoint: "/api/extended/v2/blocks/{$block_id}/transactions?limit={$this->limit}",
                    timeout: $this->timeout,
                    result_in: 'results'
                );
            }
        }

        $multi_curl = [];
        foreach ($curl_results as $tr)
        {
            $multi_curl[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: "/api/extended/v1/tx/{$tr['tx_id']}",
                timeout: $this->timeout
            );
        }
        $transactions = requester_multi(
            $multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout
        );
        $transactions = requester_multi_process_all($transactions, reorder: false);

        // sort according to tx index in the block
        if ($block_id != MEMPOOL)
            usort($transactions, function ($a, $b) {
                return [$a['tx_index']] <=> [$b['tx_index']];
            });
        

        $events = [];
        $sort_key = 0;

        foreach ($transactions as $op)
        {
            if (isset($op['event_count']) && (int)$op['event_count'] > 0) 
            {
                foreach ($op['events'] as $ev) 
                {
                    switch ($ev['event_type']) 
                    {
                        case 'stx_asset': 
                            {
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => $ev['asset']['sender'] ?: 'the-void',
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $ev['asset']['amount'],
                                    'failed' => !($op['tx_status'] == 'success'),
                                    'extra' => null,
                                ];
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => $ev['asset']['recipient'] ?: 'the-void',
                                    'sort_key' => $sort_key++,
                                    'effect' => $ev['asset']['amount'],
                                    'failed' => !($op['tx_status'] == 'success'),
                                    'extra' => null,
                                ];
                                break;
                            }
                        case 'stx_lock': 
                            {
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => $ev['stx_lock_event']['locked_address'],
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $ev['stx_lock_event']['locked_amount'],
                                    'failed' => !($op['tx_status'] == 'success'),
                                    'extra' => null,
                                ];
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => 'locker',
                                    'sort_key' => $sort_key++,
                                    'effect' => $ev['stx_lock_event']['locked_amount'],
                                    'failed' => !($op['tx_status'] == 'success'),
                                    'extra' => null,
                                ];
                                break;
                            }
                        case 'smart_contract_log':
                        case 'fungible_token_asset':
                        case 'non_fungible_token_asset':
                            break;
                        default:
                            throw new ModuleException("Unknown event: " . $op['event_type'] . " in transaction: " . $op['tx_id']);
                    }
                }

            }
            $events[] = [
                'transaction' => $op['tx_id'],
                'address' => $op['sender_address'],
                'sort_key' => $sort_key++,
                'effect' => '-' . $op['fee_rate'],
                'failed' => false,
                'extra' => 'f',
            ];
            $events[] = [
                'transaction' => $op['tx_id'],
                'address' => 'the-void',
                'sort_key' => $sort_key++,
                'effect' => $op['fee_rate'],
                'failed' => false,
                'extra' => 'f',
            ];
        }

        $this_time = date('Y-m-d H:i:s');

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : $this_time;
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    final public function api_get_balance(string $address): string
    {
        $request = requester_single(
            $this->select_node(),
            endpoint: "/api/extended/v1/address/{$address}/balances",
            result_in: 'stx',
            timeout: $this->timeout
        );

        if (!isset($request['balance']))
            return '0';
        else
            return (string)$request['balance'];
    }
}
