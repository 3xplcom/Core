<?php

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes TRC-10 transfers in TRON. It requires a java-tron node.  */

abstract class TVMTRC10Module extends CoreModule
{
    use TVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Numeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;
    public ?array $special_addresses = ['the-void','dex'];

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = ['symbol'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false; // Technically, this is possible
    public ?bool $forking_implemented = true;

    // TVM-specific
    public array $extra_features = [];

    private array $trc10_tokens = [];
    final public function pre_initialize()
    {
        $this->version = 1;

    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {

        try
        {
            $r1 = requester_single($this->select_node(),
                endpoint: "/wallet/getblockbynum?num={$block_id}", // no visible=true, because asset_name can be
                timeout: $this->timeout);
        }
        catch (RequesterEmptyArrayInResponseException)
        {
            $r1 = [];
        }

        $general_data = $r1['transactions'] ?? [];

        try
        {
            // there can be TRC-10 transfers in internal transactions as well
            $r2 = requester_single($this->select_node(),
                endpoint: "/wallet/gettransactioninfobyblocknum?num={$block_id}",
                timeout: $this->timeout);
        }
        catch (RequesterEmptyArrayInResponseException)
        {
            $r2 = [];
        }

        // Process logs
        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;
        $exchange_transaction_types = ['ExchangeCreateContract', 'ExchangeInjectContract', 'ExchangeWithdrawContract', 'ExchangeTransactionContract', 'ParticipateAssetIssueContract'];
        $other_trc10_transactions_data = [];
        $other_trc10_transactions_info = [];

        // main trc10 transfers
        for ($i = 0; $i < count($general_data); $i++)
        {
            $receipt = $general_data[$i];

            $transaction_type = $receipt['raw_data']['contract'][0]['type'] ?? null;
            if (in_array($transaction_type, $exchange_transaction_types))
            {
                $other_trc10_transactions_data[] = $receipt;
                $other_trc10_transactions_info[] = $r2[$i] ?? [];
                continue;
            }

            if (count($other_trc10_transactions_data) > 0 && count($r2) === 0)
                throw new ModuleError("No transaction info for dex trc10 transfers");

            if (($transaction_type) !== 'TransferAssetContract')
                continue;

            if (($receipt['raw_data']['contract'][0]['type'] ?? null) !== 'TransferAssetContract')
                continue;

            $data = $receipt['raw_data']['contract'][0]['parameter']['value'];
            $asset_id = $this->get_asset_info($data['asset_name'], block_id: $block_id);
            $events[] = [
                'transaction' => $receipt['txID'],
                'currency' => $asset_id,
                'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                'sort_key' => $sort_key++,
                'effect' => '-' . $data['amount'],
            ];

            $events[] = [
                'transaction' => $receipt['txID'],
                'currency' => $asset_id,
                'address' => $this->encode_address_to_base58('0x' . substr($data['to_address'], 2)),
                'sort_key' => $sort_key++,
                'effect' => strval($data['amount']),
            ];

            $currencies_to_process[] = $asset_id;
        }

        // process internal trc10 internal transfers
        foreach ($r2 as $transaction)
        {
            if (!isset($transaction['internal_transactions']))
                continue;
            foreach ($transaction['internal_transactions'] as $internal_data)
            {
                foreach ($internal_data['callValueInfo'] as $data)
                {
                    if (isset($data['tokenId']) && isset($data['callValue']))
                    {
                        $asset_id = $this->get_asset_info($data['tokenId'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $transaction['id'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($internal_data['caller_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['callValue'],
                        ];

                        $events[] = [
                            'transaction' => $transaction['id'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($internal_data['transferTo_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => strval($data['callValue']),
                        ];
                        $currencies_to_process[] = $asset_id;
                    }
                }
            }
        }

        // other specific trc10 transfers && asset issue transfers
        for ($i = 0; $i < count($other_trc10_transactions_data); $i++)
        {
            $transaction_type = $other_trc10_transactions_data[$i]['raw_data']['contract'][0]['type'] ?? null;
            $data = $other_trc10_transactions_data[$i]['raw_data']['contract'][0]['parameter']['value'];
            switch ($transaction_type)
            {
                case "ExchangeInjectContract":
                    if ($data['token_id'] != "5f") // '5f' because api queried without `visible=true` query arg, and '_' === '5f' in hex
                    {
                        $asset_id = $this->get_asset_info($data['token_id'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['quant'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => $data['quant'],
                        ];
                        $currencies_to_process[] = $asset_id;
                    }
                    break;
                case "ExchangeTransactionContract":
                    $exchange = $this->get_exchange_by_id($data['exchange_id']);
                    if ($exchange['has_trx'] && $data['token_id'] != "5f") // buying trx for token, example block 4067933
                    {
                        $asset_id = $this->get_asset_info($data['token_id'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['quant'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => strval($data['quant']),
                        ];

                        $currencies_to_process[] = $asset_id;
                    }
                    elseif ($exchange['has_trx'] && $data['token_id'] === "5f") // buying token for trx
                    {
                        $asset_id = $exchange['first_token_id'] != '5f' ? $exchange['first_token_id'] : $exchange['second_token_id'];
                        $asset_id = $this->get_asset_info($asset_id, block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => $other_trc10_transactions_info[$i]['exchange_received_amount'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $other_trc10_transactions_info[$i]['exchange_received_amount'],
                        ];

                        $currencies_to_process[] = $asset_id;
                    }
                    elseif ($data['token_id'] != '5f') // exchanging one token to another
                    {
                        $received_asset_id = $exchange['first_token_id'] != $data['token_id'] ? $exchange['first_token_id'] : $exchange['second_token_id'];
                        $received_asset_id = $this->get_asset_info($received_asset_id, block_id: $block_id);
                        $sold_asset_id = $this->get_asset_info($data['token_id'], block_id: $block_id);
                        $sold_value = $data['quant'];
                        $received_value = $other_trc10_transactions_info[$i]['exchange_received_amount'];

                        // sold
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $sold_asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $sold_value,
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $sold_asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => $sold_value,
                        ];

                        // received
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $received_asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => $received_value,
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $received_asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $received_value,
                        ];

                        $currencies_to_process[] = $received_asset_id;
                        $currencies_to_process[] = $sold_asset_id;
                    }
                    break;
                case "ExchangeWithdrawContract":
                    if ($data['token_id'] != "5f")
                    {
                        $asset_id = $this->get_asset_info($data['token_id'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => $data['quant'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['quant'],
                        ];
                        $currencies_to_process[] = $asset_id;
                    }
                    break;
                case "ExchangeCreateContract":
                    if ($data['first_token_id'] != "5f")
                    {
                        $asset_id = $this->get_asset_info($data['first_token_id'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['first_token_balance'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => $data['first_token_balance'],
                        ];
                        $currencies_to_process[] = $asset_id;
                    }

                    if ($data['second_token_id'] != "5f")
                    {
                        $asset_id = $this->get_asset_info($data['second_token_id'], block_id: $block_id);
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['second_token_balance'],
                        ];
                        $events[] = [
                            'transaction' => $other_trc10_transactions_data[$i]['txID'],
                            'currency' => $asset_id,
                            'address' => 'dex',
                            'sort_key' => $sort_key++,
                            'effect' => $data['second_token_balance'],
                        ];
                        $currencies_to_process[] = $asset_id;
                    }
                    break;

                case "ParticipateAssetIssueContract":
                    $asset_id = $this->get_asset_info($data['asset_name'], block_id: $block_id, id_only: false);
                    $received_value = (int)($data['amount'] * $asset_id['num'] / $asset_id['trx_num']);
                    $events[] = [
                        'transaction' => $other_trc10_transactions_data[$i]['txID'],
                        'currency' => $asset_id['id'],
                        'address' => $this->encode_address_to_base58('0x' . substr($data['owner_address'], 2)),
                        'sort_key' => $sort_key++,
                        'effect' => strval($received_value),
                    ];
                    $events[] = [
                        'transaction' => $other_trc10_transactions_data[$i]['txID'],
                        'currency' => $asset_id['id'],
                        'address' => 'the-void',
                        'sort_key' => $sort_key++,
                        'effect' => '-' . $received_value,
                    ];
                    $currencies_to_process[] = $asset_id['id'];
                    break;
            }
        }

        // Process currencies

        $currencies = [];
        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        if ($currencies_to_process)
        {
            $assets = requester_single($this->select_node(),
                endpoint: "/wallet/getassetissuelist",
                timeout: $this->timeout);

            if (count($assets) < 1)
                throw new ModuleError('No results for /wallet/assetissuelist');

            $assets = $assets['assetIssue'];

            $processed_currencies = [];

            foreach ($assets as $asset)
            {
                if (in_array($asset['id'], $currencies_to_process))
                    $processed_currencies[] = $asset;
            }

            foreach ($processed_currencies as $asset)
            {
                $asset['precision'] = isset($asset['precision']) ? $asset['precision'] : 1;
                $asset['abbr'] = $asset['abbr'] ?? '';
                $currencies[] = [
                    'id' => $asset['id'],
                    'name' => mb_convert_encoding(hex2bin($asset['name']), 'UTF-8', 'UTF-8'),
                    'symbol' => mb_convert_encoding(hex2bin($asset['abbr']), 'UTF-8', 'UTF-8'),
                    'decimals' => $asset['precision'] > 32767 ? 1 : $asset['precision']  // We use SMALLINT for decimals...
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
        // assuming that address received  in base58 format THPvaUhoh2Qn2y9THCZML3H815hhFhn5YC
        // should always be the case

        if (!$currencies)
            return [];

        // let's check that address is really in Base58Check format THPvaUhoh2Qn2y9THCZML3H815hhFhn5YC
        // this helps to not exhaust the node
        try
        {
            $address = '41' . $this->encode_base58_to_evm_hex($address);
        }
        catch (Exception)
        {
            $return = [];

            foreach ($currencies as $ignored)
                $return[] = '0';
            return $return;
        }

        $real_currencies = [];

        // Input currencies should be in format like this: `tron-trc-10/1000001`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        try
        {
            $data = requester_single($this->select_node(),
                endpoint: "/wallet/getaccount?address={$address}",
                timeout: $this->timeout);
        }
        catch (RequesterEmptyArrayInResponseException){
            return [];
        }


        // i.e. address not found
        if (count($data) == 0)
            return [];

        $data = $data['assetV2'];

        $result = [];
        foreach ($data as $asset)
        {
            if (in_array($asset['key'], $real_currencies))
                $result[$asset['key']] = $asset['value'];
        }

        $return = [];
        for ($i = 0, $ids = count($real_currencies); $i < $ids; $i++)
        {
            $return[] = strval($result[$real_currencies[$i]] ?? 0);
        }
        return $return;
    }

    /**
     * The No.14 Committee Proposal
     * allows duplicate token name, therefore, before the proposal takes effect, the token name is used as the unique identifier of the TRC10 token.
     * After it takes effect, the token id will be used as the unique identifier of the TRC10 token.
     * @param string $asset_name_or_id
     * @param bool $id_only - if true will return only token ID
     * @param int $block_id - depending on block number an appropriate api call will be made (see Proposal 14)
     * @return string|array
     * @throws RequesterEmptyResponseException
     * @throws RequesterException
     */
    protected function get_asset_info(string $asset_name_or_id, int $block_id, bool $id_only = true): string|array
    {
        if (array_key_exists($asset_name_or_id, $this->trc10_tokens))
        {
            $result = $this->trc10_tokens[$asset_name_or_id];
        }
        else
        {
            if ($block_id > 5537806)
            {
                if (strlen($asset_name_or_id) % 2 == 0)
                    $asset = hex2bin($asset_name_or_id);
                else
                    $asset = $asset_name_or_id;
                try
                {
                    $asset_by_id = requester_single($this->select_node(),
                        endpoint: "/wallet/getassetissuebyid?value=$asset",
                        timeout: $this->timeout);
                }
                catch (RequesterEmptyArrayInResponseException)
                {
                    $asset_by_id = requester_single($this->select_node(),
                        endpoint: "/wallet/getassetissuebyid?value=$asset_name_or_id",
                        timeout: $this->timeout);
                }
                if (count($asset_by_id) != 0)
                    $result = [
                        'id' => $asset_by_id['id'],
                        'name' => hex2bin($asset_by_id['name']),
                        'symbol' => hex2bin($asset_by_id['abbr'] ?? ''),
                        'decimals' => $asset_by_id['precision'] ?? 1,
                        'num' => $asset_by_id['num'] ?? 1,
                        'trx_num' => $asset_by_id['trx_num'] ?? 1
                    ];
                else
                    throw new DeveloperError(" Could not get id of asset after Prop 14 {$asset_name_or_id}: $asset_by_id");
            }
            else
            {
                $asset_by_id = null;
                try {
                    $asset_by_name = requester_single($this->select_node(),
                        endpoint: "/wallet/getassetissuelistbyname?value=$asset_name_or_id",
                        result_in: 'assetIssue', timeout: $this->timeout);
                }catch (RequesterEmptyArrayInResponseException){
                    // unpredicted behaviour in block 5535307 token_id was the number instead of symbol in ExchangeWithdrawContract
                    if (strlen($asset_name_or_id) % 2 ==0)
                        $asset = hex2bin($asset_name_or_id);
                    else
                        $asset = $asset_name_or_id;
                    $asset_by_id = requester_single($this->select_node(),
                        endpoint: "/wallet/getassetissuebyid?value=" . $asset, timeout: $this->timeout);
                }

                if (is_null($asset_by_id))
                {
                    if (!isset($asset_by_name) || count($asset_by_name) < 1)
                        throw new DeveloperError(" Could not get id of asset {$asset_name_or_id}: $asset_by_name");
                    // if the $asset_name_or_id is name of the token, then this was the firstly created token
                    usort($asset_by_name, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
                    $result = [
                        'id' => $asset_by_name[0]['id'],
                        'name' => $asset_by_name[0]['name'],
                        'symbol' => $asset_by_name[0]['abbr'] ?? '',
                        'decimals' => $asset_by_name[0]['precision'] ?? 1,
                        'num' => $asset_by_name[0]['num'] ?? 1,
                        'trx_num' => $asset_by_name[0]['trx_num'] ?? 1
                    ];
                }
                else
                {
                    $result = [
                        'id' => $asset_by_id['id'],
                        'name' => hex2bin($asset_by_id['name']),
                        'symbol' => hex2bin($asset_by_id['abbr'] ?? ''),
                        'decimals' => $asset_by_id['precision'] ?? 1,
                        'num' => $asset_by_id['num'] ?? 1,
                        'trx_num' => $asset_by_id['trx_num'] ?? 1
                    ];
                }

            }

            // populate cache
            $this->trc10_tokens[$asset_name_or_id] = $result;
        }

        if ($id_only)
            return $result['id'];
        return $result;
    }

}
