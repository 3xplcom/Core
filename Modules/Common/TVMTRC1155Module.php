<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TRC-1155 MT standard(requires vm.supportConstant), see
 *  https://github.com/tronprotocol/tips/blob/master/tip-1155.md */

abstract class TVMTRC1155Module extends CoreModule
{
    use TVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

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

        $multi_curl = [];


        $r1 = requester_single($this->select_node(),
            endpoint: "/wallet/getblockbynum?num={$block_id}", // no visible=true, because asset_name can be
            timeout: $this->timeout);

        $general_data = $r1['transactions'] ?? [];

        // there can be TRC-10 transfers in internal transactions as well
        try
        {
            $receipt_data = requester_single($this->select_node(),
                endpoint: "/wallet/gettransactioninfobyblocknum?num={$block_id}",
                timeout: $this->timeout);
        }
        catch (RequesterEmptyArrayInResponseException)
        {
            $receipt_data = [];
        }

        $multi_curl[] = requester_multi_prepare($this->select_node(),
            params: ['jsonrpc' => '2.0',
                'method' => 'eth_getLogs',
                'params' =>
                    [['blockhash' => $this->block_hash,
                        'topics' => ['0xc3d58168c5ae7397731d063d5bbf3d657854427343f4c083240f7aacaa2d0f62'],
                    ],
                    ],
                'id' => 0,
            ],
            timeout: $this->timeout); // TransferSingle

        $multi_curl[] = requester_multi_prepare($this->select_node(),
            params: ['jsonrpc' => '2.0',
                'method' => 'eth_getLogs',
                'params' =>
                    [['blockhash' => $this->block_hash,
                        'topics' => ['0x4a39dc06d4c0dbc64b70af90fd698a233a518aa5d07e595d983b8c0526c8f7fb'],
                    ],
                    ],
                'id' => 1,
            ],
            timeout: $this->timeout); // TransferBatch

        // Process logs

        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;
        $logs_batch = [];

        foreach ($receipt_data as $receipt)
        {
            if (!isset($receipt['log']))
                continue;

            foreach ($receipt['log'] as $log) {
                if (!isset($log['topics'])) // this also happens block 34998371
                    continue;
                if (count($log['topics']) !== 4)
                    // eth_getLogs works unpredictably in java-tron - doesn't return filtered answer for old blocks,
                    // so we need to filter the logs manually
                    continue;

                if ($log['topics'][0] === '4a39dc06d4c0dbc64b70af90fd698a233a518aa5d07e595d983b8c0526c8f7fb') {
                    $log['id'] = $receipt['id'];
                    $logs_batch[] = $log;
                    continue;
                }

                if ($log['topics'][0] != 'c3d58168c5ae7397731d063d5bbf3d657854427343f4c083240f7aacaa2d0f62')
                    continue;

                // TransferSingle
                $events[] = [
                    'transaction' => $receipt['id'],
                    'currency' => $this->encode_address_to_base58('0x' . $log['address']),
                    'address' => $this->encode_address_to_base58('0x' . substr($log['topics'][2], 24)),
                    'sort_key' => $sort_key++,
                    'effect' => '-' . to_int256_from_0xhex('0x' . substr($log['data'], 64, 64)),
                    'extra' => to_int256_from_0xhex('0x' . substr($log['data'], 0, 64)),
                ];
                $events[] = [
                    'transaction' => $receipt['id'],
                    'currency' => $this->encode_address_to_base58('0x' . $log['address']),
                    'address' => $this->encode_address_to_base58('0x' . substr($log['topics'][3], 24)),
                    'sort_key' => $sort_key++,
                    'effect' => to_int256_from_0xhex('0x' . substr($log['data'], 64, 64)),
                    'extra' => to_int256_from_0xhex('0x' . substr($log['data'], 0, 64)),
                ];

                $currencies_to_process[] = '0x' . $log['address'];
            }
        }

        foreach ($logs_batch as $log) {
            if (count($log['topics']) !== 4)
                continue;

            $n = str_split($log['data'], 64);

            if (((count($n)) % 2) !== 0) // Some contract may yield invalid `data`, e.g. two token ids, but just one value
                continue;

            $n_count = intdiv(count($n), 2);

            if (!$n_count) // Example: Avalanche C-Chain transaction 0x9facbf18cf0be5525459383dcce0b523dc6b62272318d220688c60d2019ee736
                continue;

            $first_5th = $n_count;

            for ($this_n = 0; $this_n < $n_count; $this_n++) {
                $events[] = [
                    'transaction' => $log['id'],
                    'currency' => $this->encode_address_to_base58('0x' . $log['address']),
                    'address' => $this->encode_address_to_base58('0x' . substr($log['topics'][2], 24)),
                    'sort_key' => $sort_key++,
                    'effect' => '-' . to_int256_from_0xhex('0x' . $n[$first_5th + $this_n]),
                    'extra' => to_int256_from_0xhex('0x' . $n[$this_n]),
                ];

                $events[] = [
                    'transaction' => $log['id'],
                    'currency' => $this->encode_address_to_base58('0x' . $log['address']),
                    'address' => $this->encode_address_to_base58('0x' . substr($log['topics'][3], 24)),
                    'sort_key' => $sort_key++,
                    'effect' => to_int256_from_0xhex('0x' . $n[$first_5th + $this_n]),
                    'extra' => to_int256_from_0xhex('0x' . $n[$this_n]),
                ];
            }

            $currencies_to_process[] = '0x' . $log['address'];
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
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                        'method' => 'eth_call',
                        'params' => [['to' => $currency_id,
                            'data' => '0x06fdde03',
                        ],
                            'latest',
                        ],
                        'id' => $this_id++,
                    ],
                    timeout: $this->timeout); // Name

                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['jsonrpc' => '2.0',
                        'method' => 'eth_call',
                        'params' => [['to' => $currency_id,
                            'data' => '0x95d89b41',
                        ],
                            'latest',
                        ],
                        'id' => $this_id++,
                    ],
                    timeout: $this->timeout); // Symbol
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            foreach ($curl_results as $v)
                $currency_data[] = requester_multi_process($v, ignore_errors: true);

            reorder_by_id($currency_data);

            foreach ($currency_data as $bit)
            {
                $this_j = intdiv((int)$bit['id'], 2);

                if (!isset($bit['result']) && isset($bit['error']))
                {
                    if (str_starts_with($bit['error']['message'], 'REVERT opcode executed'))
                        $bit['result'] = '0x';
                    elseif ($bit['error']['message'] === 'Smart contract is not exist.')
                        $bit['result'] = '0x';
                    else
                        throw new RequesterException("Request to the node errored with `{$bit['error']['message']}`: " . print_r($bit['error'], true));
                }

                if ((int)$bit['id'] % 2 === 0)
                    $lib[($currencies_to_process[$this_j])]['name'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));
                if ((int)$bit['id'] % 2 === 1)
                    $lib[($currencies_to_process[$this_j])]['symbol'] = trim(substr(hex2bin(substr($bit['result'], 2)), -32));
            }

            foreach ($lib as $id => $l)
            {
                // This removes invalid UTF-8 sequences
                $l['name'] = mb_convert_encoding($l['name'], 'UTF-8', 'UTF-8');
                $l['symbol'] = mb_convert_encoding($l['symbol'], 'UTF-8', 'UTF-8');

                $currencies[] = [
                    'id' => $this->encode_address_to_base58($id),
                    'name' => $l['name'],
                    'symbol' => $l['symbol'],
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
    public function api_get_balance(string $address, array $currencies): array
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

            return $return ;
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

        // Input currencies should be in format like this: `tvm-trc-1155/TXWLT4N9vDcmNHDnSuKv2odhBtizYuEMKJ`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $encoded_address = $this->encode_abi("address", substr($address, 2));

        $data = $return = [];

        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++)
        {
            $data[] = ['jsonrpc' => '2.0',
                       'id'      => $i,
                       'method'  => 'eth_call',
                       'params'  => [['to'   => '0x' . $this->encode_base58_to_evm_hex($real_currencies[$i]),
                                      'data' => '0x70a08231' . $encoded_address,
                                     ],
                                     'latest',
                       ],
            ];
        }

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
