<?php

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TRC-20 (requires vm.supportConstant) standard, see
 *  https://github.com/tronprotocol/tips/blob/master/tip-20.md */

abstract class TVMTRC20Module extends CoreModule
{
    use TVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
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

    public ?bool $mempool_implemented = false; // Technically, this is possible
    public ?bool $forking_implemented = true;

    // TVM-specific
    public array $extra_features = [];

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        // Get logs
        try
        {
            $receipt_data = requester_single($this->select_node(),
                endpoint: "/wallet/gettransactioninfobyblocknum?num={$block_id}&visible=true",
                timeout: $this->timeout);
        }
        catch (RequesterEmptyArrayInResponseException)
        {
            $receipt_data = [];
        }

        // Process logs

        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;

        foreach ($receipt_data as $receipt)
        {
            if (!isset($receipt['log']))
                continue;

            foreach ($receipt['log'] as $log)
            {
                if (!isset($log['topics'])) // this also happens block 34998371
                    continue;

                if (count($log['topics']) !== 3 || $log['topics'][0] != 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef')
                    // eth_getLogs works unpredictably in java-tron - doesn't return filtered answer for old blocks,
                    // so we need to filter the logs manually
                    continue;

                $events[] = [
                    'transaction' => $receipt['id'],
                    'currency'    => $log['address'],
                    'address'     => $this->encode_address_to_base58('0x' . substr($log['topics'][1], 24)),
                    'sort_key'    => $sort_key++,
                    'effect'      => '-' . to_int256_from_0xhex('0x' . $log['data']),
                ];

                $events[] = [
                    'transaction' => $receipt['id'],
                    'currency'    => $log['address'],
                    'address'     => $this->encode_address_to_base58('0x' . substr($log['topics'][2], 24)),
                    'sort_key'    => $sort_key++,
                    'effect'      => to_int256_from_0xhex('0x' . $log['data']),
                ];
                $currencies_to_process[] = $log['address'];
            }
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
                $evm_address = '0x' . $this->encode_base58_to_evm_hex(strval($currency_id));
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                        'method'  => 'eth_call',
                        'params'  => [['to'   => $evm_address,
                            'data' => '0x06fdde03',
                        ],
                            'latest',
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout); // Name

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                        'method'  => 'eth_call',
                        'params'  => [['to'   => $evm_address,
                            'data' => '0x95d89b41',
                        ],
                            'latest',
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout); // Symbol

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                        'method'  => 'eth_call',
                        'params'  => [['to'   => $evm_address,
                            'data' => '0x313ce567',
                        ],
                            'latest',
                        ],
                        'id'      => $this_id++,
                    ],
                    timeout: $this->timeout); // Decimals
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            foreach ($curl_results as $v)
                $currency_data[] = requester_multi_process($v, ignore_errors: true);

            reorder_by_id($currency_data);

            foreach ($currency_data as $bit)
            {
                $this_j = intdiv((int)$bit['id'], 3);

                if (!isset($bit['result']) && isset($bit['error']))
                {
                    if (str_starts_with($bit['error']['message'], 'REVERT opcode executed'))
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'Smart contract is not exist.')
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


        // assuming that address received  in base58 format THPvaUhoh2Qn2y9THCZML3H815hhFhn5YC
        // should always be the case
        try
        {
            $address = $this->encode_base58_to_evm_hex($address);
        }
        catch (Exception)
        {
            foreach ($currencies as $ignored)
                $return[] = '0';

            return $return;
        }
         $address = "0x" . $address;

        if (!preg_match(StandardPatterns::iHexWith0x40->value, $address))
        {
            $return = [];

            foreach ($currencies as $ignored)
                $return[] = '0';

            return $return;
        }

        $real_currencies = [];

        // Input currencies should be in format like this: `tron-trc-20/TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $encoded_address = $this->encode_abi("address", substr($address, 2));

        $data = [];

        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++)
        {
            $data[] = ['jsonrpc' => '2.0',
                'id'      => $i,
                'method'  => 'eth_call',
                'params'  => [['to'   =>  '0x' . $this->encode_base58_to_evm_hex($real_currencies[$i]),
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
