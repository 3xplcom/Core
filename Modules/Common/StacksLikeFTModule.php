<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module works with FT in Stacks. Requires a Stacks node and API.  */

abstract class StacksLikeFTModule extends CoreModule
{
    use StacksLikeTraits;
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'failed'];
    public ?array $events_table_nullable_fields = [];
    public ?array $currencies_table_fields = ['id', 'decimals', 'name', 'symbol'];
    public ?array $currencies_table_nullable_fields = [];


    public ?ExtraDataModel $extra_data_model = ExtraDataModel::None;
    public ?array $extra_data_details = null;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = false;

    public string $block_entity_name = 'block';
    public string $address_entity_name = 'account';
    private int $limit = 50;
    public bool $token_api = true;
    private string $default_caller = 'SP000000000000000000002Q6VF78.get-info'; // address that used to call any functions

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
        $currencies_to_process = [];
        $currencies = [];

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
                $this->block_time = $true_block_time;
                $this->set_return_currencies([]);
                $this->set_return_events([]);
                return;
            }

            $curl_results = [];
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
        
        $transactions = [];
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
            timeout: $this->timeout,
            valid_codes: [200, 500],    # until we are totally sure that `requester_multi_process_all` ignores it
        );

        if ($block_id == MEMPOOL) 
        {
            $output = [];
            foreach ($transactions as $v)
            {
                $out = requester_multi_process($v, ignore_errors: true);
                if(isset($out['statusCode'])) 
                    continue;
                $output[] = $out;
            }
            unset($transactions);
            $transactions = $output;
        }
        else 
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
                        case 'fungible_token_asset':
                            {
                                $contract_pos = strpos($ev['asset']['asset_id'], '::');
                                $currency_id = substr($ev['asset']['asset_id'], 0, $contract_pos);
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => $ev['asset']['sender'] ?: 'the-void',
                                    'currency' => $currency_id,
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $ev['asset']['amount'],
                                    'failed' => !($op['tx_status'] == 'success')
                                ];
                                $events[] = [
                                    'transaction' => $op['tx_id'],
                                    'address' => $ev['asset']['recipient'] ?: 'the-void',
                                    'currency' => $currency_id,
                                    'sort_key' => $sort_key++,
                                    'effect' => $ev['asset']['amount'],
                                    'failed' => !($op['tx_status'] == 'success')
                                ];
                                $currencies_to_process[] = $ev['asset']['asset_id']; // in currency block shall be cut
                                break;
                            }
                        case 'stx_asset': 
                        case 'stx_lock': 
                        case 'smart_contract_log':
                        case 'non_fungible_token_asset':
                            break;
                        default:
                            throw new ModuleException("Unknown event: " . $op['event_type'] . " in transaction: " . $op['tx_id']);
                    }
                }
            }
        }

        if ($block_id == MEMPOOL)
        {
            $currencies_to_process = [];
            $currencies = null;
        }

        foreach ($currencies_to_process as &$c)
            if ($p = strpos($c, '::'))
                $c = substr($c, 0, $p);

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format);

        if ($currencies_to_process)
        {
            if ($this->token_api) 
            {
                $multi_curr = [];
                foreach ($currencies_to_process as $currency_id) 
                {
                    $multi_curr[] = requester_multi_prepare(
                        $this->select_node(),
                        endpoint: "/token/metadata/v1/ft/{$currency_id}",
                        timeout: $this->timeout
                    );
                }
                $results = requester_multi(
                    $multi_curr,
                    limit: envm($this->module, 'REQUESTER_THREADS'),
                    timeout: $this->timeout,
                    valid_codes: [200, 404, 422]
                );
                $output = [];

                foreach ($results as $v)
                    $output[] = requester_multi_process($v, ignore_errors: true);
                foreach ($output as $r) 
                {
                    if (isset($r['error'])) {
                        $currencies[] = [
                            "id" => $currency_id,
                            "decimals" => 0,
                            "name" => "",
                            "symbol" => "",
                        ];
                        continue;
                    }
                    $contract_pos = strpos($r['asset_identifier'], '::');
                    $currency_id = substr($r['asset_identifier'], 0, $contract_pos);
                    $decimals = $r['decimals'] ?? 0;
                    $name = $r['name'] ?? '';
                    $symb = $r['symbol'] ?? '';
                    $currencies[] = [
                        "id" => $currency_id,
                        "decimals" => $decimals,
                        "name" => $name,
                        "symbol" => $symb,
                    ];
                }
            } 
            else 
            {
                $body = [
                    "sender" => "{$this->default_caller}",
                    "arguments" => [],
                ];

                foreach ($currencies_to_process as $currency_id) 
                {
                    $contract_name_pos = strpos($currency_id, '.');

                    $cr_contract = substr($currency_id, 0, $contract_name_pos);
                    $cr_contract_name = substr($currency_id, $contract_name_pos + 1);

                    // decimals
                    $requester_single_dec = requester_single(
                        $this->select_node(),
                        endpoint: "/core/v2/contracts/call-read/{$cr_contract}/{$cr_contract_name}/get-decimals",
                        params: $body,
                        timeout: $this->timeout
                    );

                    if ($requester_single_dec['okay'] == 'true') 
                    {
                        try 
                        {
                            $decimals = to_int64_from_0xhex('0x' . substr($requester_single_dec['result'], 6));
                            if ($decimals > 32767)
                                $decimals = 0;
                        } 
                        catch (MathException) {
                            $decimals = 0;
                        }
                    } 
                    else 
                        $decimals = 0;

                    // name
                    $requester_single_name = requester_single(
                        $this->select_node(),
                        endpoint: "/core/v2/contracts/call-read/{$cr_contract}/{$cr_contract_name}/get-name",
                        params: $body,
                        timeout: $this->timeout
                    ); 

                    if ($requester_single_name['okay'] == 'true')
                    {
                        if ($requester_single_name['result'][5] != 'd') 
                            throw new DeveloperError("No: {$currency_id}");
                        $name = trim(hex2bin(substr($requester_single_name['result'], 6)));
                        $name = preg_replace('/[^\x20-\x7E]/', '', $name);
                    } 
                    else
                        $name = "";

                    // symbol
                    $requester_single_symb = requester_single(
                        $this->select_node(),
                        endpoint: "/core/v2/contracts/call-read/{$cr_contract}/{$cr_contract_name}/get-symbol",
                        params: $body,
                        timeout: $this->timeout
                    );

                    if ($requester_single_symb['okay'] == 'true') 
                    {
                        if ($requester_single_symb['result'][5] != 'd')
                            throw new DeveloperError("No: {$currency_id}");
                        $symb = trim(hex2bin(substr($requester_single_symb['result'], 6)));
                        $symb = preg_replace('/[^\x20-\x7E]/', '', $symb);
                    } 
                    else 
                        $symb = "";

                    $currencies[] = [
                        "id" => $currency_id,
                        "decimals" => $decimals,
                        "name" => $name,
                        "symbol" => $symb,
                    ];
                }
            }
        }

        $this_time = date('Y-m-d H:i:s');
        foreach ($events as &$event) 
        {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : $this_time;
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    final function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        $real_currencies = [];
        $return = [];

        // Input currencies should be in format like this: `stacks-ft/{currency}`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $request = requester_single(
            $this->select_node(),
            endpoint: "/api/extended/v1/address/{$address}/balances",
            result_in: 'fungible_tokens',
            timeout: $this->timeout
        );

        foreach($real_currencies as $currency) 
        {
            $found = false;
            foreach ($request as $token_name => $balances) 
            {
                $contract_pos = strpos($token_name, '::');
                $currency_id = substr($token_name, 0, $contract_pos);
                if ($currency == $currency_id) 
                {
                    $return[] = $balances['balance'];
                    $found = true;
                    break;
                }                
            }
            if (!$found)
                $return[] = '0';
        }
        return $return;
    }
}
