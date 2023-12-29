<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes all Transfer events in StarkNet */

abstract class StarkNetLikeTokenModule extends CoreModule
{
    use StarkNetTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWith0x;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

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
        $logs = [];
        $continuation_token = "{$block_id}-0";
        ASK_REQ: 
        {
            $response = requester_single(
                $this->select_node(),
                params: [
                    'jsonrpc' => '2.0',
                    'method'  => 'starknet_getEvents',
                    'params'  =>
                    [
                        "filter" =>
                        [
                            'from_block' => ['block_number' => $this->block_id],
                            'to_block' => ['block_number' => $this->block_id],
                            'keys'    => [['0x99cd8bde557814842a3121e8ddfd433a539b8c9f14bf31ebf108d12e6196e9']],
                            'chunk_size' => 1000,
                            'continuation_token' => $continuation_token,
                        ],
                    ],
                    'id'      => 0,
                ],
                result_in: 'result',
                timeout: $this->timeout
            );
            $logs = array_merge($logs, $response['events']);
            if (isset($response['continuation_token'])) {
                $continuation_token = $response['continuation_token'];
                goto ASK_REQ;
            }
        }


        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;

        foreach ($logs as $log)
        {
            if ($log['block_hash'] !== $this->block_hash)
                throw new ModuleError("The node returned wrong data for {$this->block_hash}: {$log['blockHash']}");

            if (count($log['data']) !== 4) // need to test it
            {
                continue; // This is ERC-721
            }

            // StarkNet ETH
            if($log['from_address'] === '0x49d36570d4e46f48e99674bd3fcc84644ddd6b96f7c741b1562b82f9e004dc7')
            {
                continue;
            }

            $events[] = [
                'transaction' => $log['transaction_hash'],
                'currency'    => $log['from_address'],
                'address'     => $log['data'][0],
                'sort_key'    => $sort_key++,
                'effect'      => '-' . to_int256_from_0xhex($log['data'][2]),
            ];

            $events[] = [
                'transaction' => $log['transaction_hash'],
                'currency'    => $log['from_address'],
                'address'     => $log['data'][1],
                'sort_key'    => $sort_key++,
                'effect'      => to_int256_from_0xhex($log['data'][2]),
            ];

            $currencies_to_process[] = $log['from_address'];
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
                $multi_curl[] = requester_multi_prepare(
                    $this->select_node(),
                    params: [
                        'jsonrpc' => '2.0',
                        'method'  => 'starknet_call',
                        'params'  => [
                            'block_id' => 'latest',
                            'request' => [
                                'contract_address' => $currency_id,
                                'entry_point_selector' => "0x361458367e696363fbcc70777d07ebbd2394e89fd0adcaf147faccd1d294d60",
                                'calldata' => []
                            ]
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout
                ); // Name

                $multi_curl[] = requester_multi_prepare(
                    $this->select_node(),
                    params: [
                        'jsonrpc' => '2.0',
                        'method'  => 'starknet_call',
                        'params'  => [
                            'block_id' => 'latest',
                            'request' => [
                                'contract_address' => $currency_id,
                                'entry_point_selector' => "0x216b05c387bab9ac31918a3e61672f4618601f3c598a2f3f2710f37053e1ea4",
                                'calldata' => []
                            ]
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout
                ); // Symbol

                $multi_curl[] = requester_multi_prepare(
                    $this->select_node(),
                    params: [
                        'jsonrpc' => '2.0',
                        'method'  => 'starknet_call',
                        'params'  => [
                            'block_id' => 'latest',
                            'request' => [
                                'contract_address' => $currency_id,
                                'entry_point_selector' => "0x4c4fb1ab068f6039d5780c68dd0fa2f8742cceb3426d19667778ca7f3518a9",
                                'calldata' => []
                            ]
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout
                ); // Decimals
            }

            $curl_results = requester_multi(
                $multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout
            );

            foreach ($curl_results as $v)
                $currency_data[] = requester_multi_process($v, ignore_errors: true);

            reorder_by_id($currency_data);

            foreach ($currency_data as $bit)
            {
                $this_j = intdiv((int)$bit['id'], 3);

                if (!isset($bit['result'][0]) && isset($bit['error']) && (int)$bit['id'] % 3 != 2)
                {
                    if (str_starts_with($bit['error']['message'], 'execution reverted'))
                        $bit['result'][0] = '0x';
                    elseif (str_starts_with($bit['error']['message'], 'invalid opcode'))
                        $bit['result'][0] = '0x';
                    elseif ($bit['error']['message'] === 'out of gas')
                        $bit['result'][0] = '0x';
                    elseif ($bit['error']['message'] === 'invalid jump destination')
                        $bit['result'][0] = '0x';
                    elseif (str_contains($bit['error']['message'], 'Function does not exist'))
                        $bit['result'][0] = '0x';
                    else
                        throw new RequesterException("Request to the node errored with `{$bit['error']['message']}` for " . print_r($currencies_to_process, true));
                }


                if ((int)$bit['id'] % 3 === 0)
                    $lib[($currencies_to_process[$this_j])]['name'] = trim(substr(hex2bin(substr($bit['result'][0], 2)), -32));
                if ((int)$bit['id'] % 3 === 1)
                    $lib[($currencies_to_process[$this_j])]['symbol'] = trim(substr(hex2bin(substr($bit['result'][0], 2)), -32));

                if ((int)$bit['id'] % 3 === 2)
                {
                    try
                    {
                        if(isset($bit['error'])) 
                        {
                            $lib[($currencies_to_process[$this_j])]['decimals'] = 0;
                        } else 
                        {
                            $lib[($currencies_to_process[$this_j])]['decimals'] = to_int64_from_0xhex('0x' . substr(substr($bit['result'][0], 2), -32));
                        }
                    }
                    catch (MathException)
                    {
                        $lib[($currencies_to_process[$this_j])]['decimals'] = 0;
                    }
                }
            }

            foreach ($lib as $id => $l)
            {
                if ($l['decimals'] > 32767)
                    $l['decimals'] = 0; // We use SMALLINT for decimals...

                // This removes invalid UTF-8 sequences
                $l['name'] = mb_convert_encoding($l['name'], 'UTF-8', 'UTF-8');
                $l['symbol'] = mb_convert_encoding($l['symbol'], 'UTF-8', 'UTF-8');

                $currencies[] = [
                    'id'       => $id,
                    'name'     => $l['name'],
                    'symbol'   => $l['symbol'],
                    'decimals' => $l['decimals'],
                ];
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

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    public function api_get_balance($address, array $currencies): array
    {
        if (!$currencies)
            return [];

        if (!preg_match(StandardPatterns::HexWith0x->value, $address))
        {
            $return = [];

            foreach ($currencies as $ignoreme)
                $return[] = '0';

            return $return;
        }

        $real_currencies = [];

        // Input currencies should be in format like this: `starknet-token/0x058d4802f643d07692ca540dc51a8a33ad1cc364986ad938033e8b89f7b805a0`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $return = [];
        $data = [];

        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++) {
            $data[] = requester_multi_prepare(
                $this->select_node(),
                params: [
                    'jsonrpc' => '2.0',
                    'id'      => $i,
                    'method'  => 'starknet_call',
                    'params' => [
                        [
                            'calldata' => ["{$address}"],
                            'contract_address' => $real_currencies[$i],
                            'entry_point_selector' => "0x2e4263afad30923c891518314c3c95dbe830a16874e8abc5777a9a20b54c76e"
                        ],
                        'latest',
                    ],
                ],
                timeout: $this->timeout
            );
        }

        $curl_results = requester_multi(
            $data,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout
        );

        foreach ($curl_results as $v)
            $currency_data[] = requester_multi_process($v, ignore_errors: true);

        reorder_by_id($currency_data);

        foreach ($currency_data as $cur_da) 
        {
            $val = isset($cur_da['result'][0]) ? substr($cur_da['result'][0], 0, 66) : null;
            $return[] = to_int256_from_0xhex($val);
        }
        
        return $return;
    }
}
