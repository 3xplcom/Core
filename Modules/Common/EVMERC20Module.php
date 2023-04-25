<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module works with the ERC-20 (BEP-20 and similar) standard, see
 *  https://github.com/ethereum/EIPs/blob/master/EIPS/eip-20.md */

abstract class EVMERC20Module extends CoreModule
{
    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWith0x;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false; // Technically, this is possible
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
        // Get logs

        $logs = requester_single($this->select_node(),
            params: ['jsonrpc'=> '2.0',
                     'method' => 'eth_getLogs',
                     'params' =>
                         [['blockhash' => $this->block_hash,
                           'topics'    => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'],
                          ],
                         ],
                     'id' => 0,
            ],
            result_in: 'result', timeout: $this->timeout);

        // Process logs

        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;

        foreach ($logs as $log)
        {
            if (count($log['topics']) !== 3)
                continue; // This is ERC-721

            $events[] = [
                'transaction' => $log['transactionHash'],
                'currency' => $log['address'],
                'address' => '0x' . substr($log['topics'][1], 26),
                'sort_key' => $sort_key++,
                'effect' => '-' . to_int256_from_0xhex($log['data']),
            ];

            $events[] = [
                'transaction' => $log['transactionHash'],
                'currency' => $log['address'],
                'address' => '0x' . substr($log['topics'][2], 26),
                'sort_key' => $sort_key++,
                'effect' => to_int256_from_0xhex($log['data']),
            ];

            $currencies_to_process[] = $log['address'];
        }

        // Process currencies

        $currencies = [];

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        if ($currencies_to_process)
        {
            $multi_curl = [];
            $lib = [];

            $this_id = 0;

            foreach ($currencies_to_process as $currency_id)
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc'=> '2.0', 'method' => 'eth_call', 'params' => [['to' => $currency_id, 'data' => '0x06fdde03'], 'latest'], 'id' => $this_id++],
                    timeout: $this->timeout); // Name

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc'=> '2.0', 'method' => 'eth_call', 'params' => [['to' => $currency_id, 'data' => '0x95d89b41'], 'latest'], 'id' => $this_id++],
                    timeout: $this->timeout); // Symbol

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc'=> '2.0', 'method' => 'eth_call', 'params' => [['to' => $currency_id, 'data' => '0x313ce567'], 'latest'], 'id' => $this_id++],
                    timeout: $this->timeout); // Decimals
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout,
                valid_codes: [200]);

            foreach ($curl_results as $v)
            {
                $currency_data[] = requester_multi_process($v, ignore_errors: true);
            }

            reorder_by_id($currency_data);

            foreach ($currency_data as $bit)
            {
                $this_j = intdiv((int)$bit['id'], 3);

                if (!isset($bit['result']) && isset($bit['error']))
                {
                    if (str_starts_with($bit['error']['message'], 'execution reverted'))
                        $bit['result'] = '0x';
                    elseif (str_starts_with($bit['error']['message'], 'invalid opcode'))
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'out of gas')
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'invalid jump destination')
                        $bit['result'] = '0x';
                    elseif (str_contains($bit['error']['message'], 'Function does not exist'))
                        $bit['result'] = '0x';
                    else
                        throw new RequesterException("Request to the node errored with `{$bit['error']['message']}` for " . print_r($currencies_to_process, true));
                }

                if ((int)$bit['id'] % 3 === 0)
                    $lib[($currencies_to_process[$this_j])]['name'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));
                if ((int)$bit['id'] % 3 === 1)
                    $lib[($currencies_to_process[$this_j])]['symbol'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));

                if ((int)$bit['id'] % 3 === 2)
                {
                    try
                    {
                        $lib[($currencies_to_process[$this_j])]['decimals'] = to_int64_from_0xhex('0x' . substr(substr($bit['result'], 2), -32));
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
                {
                    $l['decimals'] = 0;
                    // We use SMALLINT for decimals...
                }

                // This removes invalid UTF-8 sequences
                $l['name'] = mb_convert_encoding($l['name'], 'UTF-8', 'UTF-8');
                $l['symbol'] = mb_convert_encoding($l['symbol'], 'UTF-8', 'UTF-8');

                $currencies[] = [
                    'id' => $id,
                    'name' => $l['name'],
                    'symbol' => $l['symbol'],
                    'decimals' => $l['decimals'],
                ];
            }
        }

        ////////////////
        // Processing //
        ////////////////

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        if (!preg_match(StandardPatterns::iHexWith0x40->value, $address))
        {
            $return = [];

            foreach ($currencies as $c)
                $return[] = '0';

            return $return;
        }

        $real_currencies = [];

        // Input currencies should be in format like this: `ethereum-erc-20/0x14d1b27d79e97e96622618f9d4fa9b1e1e9ef082`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $encoded_address = $this->encode_abi("address", substr($address, 2));

        $data = [];

        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++)
        {
            $data[] = ['jsonrpc' => '2.0',
                       'id'      => $i,
                       'method'  => 'eth_call',
                       'params'  => [['to'   => $real_currencies[$i],
                                      'data' => '0x70a08231' . $encoded_address,
                                     ],
                                     'latest',
                       ],
            ];
        }

        $return = [];
        $data_chunks = array_chunk($data, 100);

        foreach ($data_chunks as $datai)
        {
            $result = requester_single($this->select_node(), params: $datai);

            reorder_by_id($result);

            foreach ($result as $bit)
            {
                $return[] = to_int256_from_0xhex($bit['result'] ?? null);
            }
        }

        return $return;
    }
}
