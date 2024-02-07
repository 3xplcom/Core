<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes BRC-20 tokens on Bitcoin.
*   It requires https://github.com/hirosystems/ordinals-api */

abstract class OrdinalsBRC20Module extends CoreModule
{
    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];
    public ?array $currencies_table_fields = ['id', 'name', 'description', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];
    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::UnsafeAlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    private int $limit = 60;
    private array $btc_nodes = [];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
            endpoint: "/ordinals/v1",
            result_in: 'block_height',
            timeout: $this->timeout);
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $this->btc_nodes = envm($this->module, "BITCOIN_RPC");
        if (count($this->btc_nodes) === 1) {
            $this->block_hash = requester_single($this->btc_nodes[0],
                params: ['method' => 'getblockhash', 'params' => [(int)$block_id]],
                result_in: 'result', timeout: $this->timeout);
        } else {
            $multi_curl = [];

            foreach ($this->btc_nodes as $node) {
                $multi_curl[] = requester_multi_prepare($node, params: ['method' => 'getblockhash', 'params' => [(int)$block_id]],
                    timeout: $this->timeout);
                if ($break_on_first) break;
            }
            try {
                $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
            } catch (RequesterException $e) {
                throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
            }

            $hash = requester_multi_process($curl_results[0], result_in: 'result');
            if (count($curl_results) > 1) {
                foreach ($curl_results as $result) {
                    if (requester_multi_process($result, result_in: 'result') !== $hash) {
                        throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                    }
                }
            }
            $this->block_hash = $hash;
        }

    }

    final public function post_post_initialize()
    {
        //
    }

    final public function pre_process_block($block_id)
    {

        $block_hash = $this->block_hash;
        $block = requester_single($this->btc_nodes[0], endpoint: "/rest/block/notxdetails/{$block_hash}.json", timeout: $this->timeout);
        $true_block_time = date('Y-m-d H:i:s', (int)$block['time']);
        $sorted_tx = array_flip($block['tx']);
        $block = requester_single($this->select_node(),
            endpoint: "/ordinals/v1/brc-20/activity?block_height={$block_id}&limit={$this->limit}",
            timeout: $this->timeout);

        if (count($block['results']) != 0)
        {
            $this->block_time = to_timestamp_from_long_unixtime($block['results'][0]['timestamp']);
            if ($true_block_time !== $this->block_time)
                throw new ModuleError("Timestamp of btc node and ord indexer doesn't match on block {$this->block_id}");
        }
        else
        {
            $this->set_return_currencies([]);
            $this->set_return_events([]);
            return;
        }

        $curl_results[] = $multi_curl = [];

        if ($block['total'] > $this->limit)
        {
            for ($offset = $this->limit; $offset <= $block['total']; $offset += $this->limit)
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    endpoint: "/ordinals/v1/brc-20/activity?block_height={$block_id}&limit={$this->limit}&offset=$offset",
                    timeout: $this->timeout);
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);
            $curl_results = requester_multi_process_all($curl_results, reorder: false, result_in: 'results');
        }

        $curl_results[] = $block["results"];
        $curl_results = array_merge(...$curl_results);

        foreach ($curl_results as &$result)
        {
            $result['tx_index'] = $sorted_tx[$result['tx_id']];
        }

        // sort according to tx index in the block
        usort($curl_results, function ($a, $b)
        {
            return [$a['tx_index'],
                ]
                <=>
                [$b['tx_index'],
                ];
        });

        $events = $currencies_to_process = [];
        $sort_key = 0;

        foreach ($curl_results as $op)
        {
            switch ($op['operation']) {
                case 'deploy':
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => 'the-void',
                        'sort_key' => $sort_key++,
                        'effect' => '-0',
                        'extra' => $op['operation'],
                    ];
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => $op['address'],
                        'sort_key' => $sort_key++,
                        'effect' => '0',
                        'extra' => $op['operation'],
                    ];
                    break;
                case 'mint':
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => 'the-void',
                        'sort_key' => $sort_key++,
                        'effect' => '-' . str_replace('.', '', $op['mint']['amount']),
                        'extra' => $op['operation'],
                    ];
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => $op['address'],
                        'sort_key' => $sort_key++,
                        'effect' => str_replace('.', '', $op['mint']['amount']),
                        'extra' => $op['operation'],
                    ];
                    break;
                case 'transfer_send':
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => $op['transfer_send']['from_address'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . str_replace('.', '', $op['transfer_send']['amount']),
                        'extra' => null,
                    ];
                    $events[] = [
                        'transaction' => $op['tx_id'],
                        'currency' => $op['ticker'],
                        'address' => $op['transfer_send']['to_address'],
                        'sort_key' => $sort_key++,
                        'effect' => str_replace('.', '', $op['transfer_send']['amount']),
                        'extra' => null,
                    ];
                    break;
                default:
                    break;
            }
            $currencies_to_process[] = $op['ticker'];
        }

        $currencies = [];
        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        if ($currencies_to_process)
        {
            $multi_curl = [];
            foreach ($currencies_to_process as $currency)
            {
                $currency = rawurlencode($currency);
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    endpoint: "/ordinals/v1/brc-20/tokens/{$currency}",
                    timeout: $this->timeout);
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);
            $curl_results = requester_multi_process_all($curl_results, reorder: false);

            foreach ($curl_results as $currency)
            {
                $currencies[] = [
                    'id' => $currency['token']['ticker'],
                    'name' => $currency['token']['number'],  // not sure about this key @Har01d
                    'description' => $currency['token']['id'],
                    'decimals' => $currency['token']['decimals'] ?? 18
                ];
            }
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    function api_get_balance(string $address, $currencies): array
    {
        if (!$currencies)
            return [];

        $real_currencies = [];
        foreach ($currencies as $c)
            $real_currencies[] = rawurlencode(explode('/', $c)[1]);

        $data = [];
        $query_params = "ticker=" . implode("&ticker=",$real_currencies);
        $first_query = requester_single($this->select_node(),
            endpoint: "/ordinals/v1/brc-20/balances/{$address}?{$query_params}&limit={$this->limit}",
            timeout: $this->timeout);

        $curl_results = $multi_curl = [];

        if ($first_query['total'] > $this->limit)
        {
            for ($offset = $this->limit; $offset <= $first_query['total']; $offset += $this->limit)
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    endpoint: "/ordinals/v1/brc-20/balances/{$address}?{$query_params}&limit={$this->limit}&offset=$offset",
                    timeout: $this->timeout);
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);
            $curl_results = requester_multi_process_all($curl_results, reorder: false, result_in: 'results');
        }

        $curl_results[] = $first_query['results'];
        $curl_results = array_merge(...$curl_results);
        $curl_results = array_column($curl_results,"overall_balance", "ticker");

        foreach ($real_currencies as $currency)
        {
            $data[] = isset($curl_results[$currency]) ?
                str_replace(".","", $curl_results[$currency]) : '0';
        }
        return $data;
    }
}