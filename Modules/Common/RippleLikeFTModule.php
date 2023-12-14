<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module works with assets in Ripple. Requires a Ripple node.  */

abstract class RippleLikeFTModule extends CoreModule
{
    use RippleTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::None;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = [];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = null;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    final public function pre_initialize()
    {
        $this->version = 1;
    }
    
    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
        params: ['method' => 'ledger_closed'],
        result_in: 'result',
        timeout: $this->timeout)['ledger_index'];
    }

    final public function post_post_initialize()
    {
        //
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, params: [ 'method' => 'ledger',
            'params' => [[
                    'ledger_index' => "{$block_id}",
                    'accounts' => false,
                    'full' => false,
                    'transactions' => true,
            ]]], timeout: $this->timeout);

            if ($break_on_first)
                break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_ledger(ledger_index: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $hash = requester_multi_process($curl_results[0], result_in: 'result')['ledger_hash'];

        if (count($curl_results) > 1) 
        {
            foreach ($curl_results as $result) 
            {
                if (requester_multi_process($result, result_in: 'result')['ledger_hash'] !== $hash) 
                {
                    throw new ConsensusException("ensure_ledger(ledger_index: {$block_id}): no consensus");
                }
            }
        }

        $this->block_hash = $hash;
    }

    // here is NFT accept offer with assets 83085147
    final public function pre_process_block($block_id)
    {
        $currency_by_id = [];
        $currencies = [];
        $events = [];
        $ledger = requester_single(
            $this->select_node(),
            params: [
                'method' => 'ledger',
                'params' => [[
                    'ledger_index' => "{$block_id}",
                    'account' => false,
                    'full'=> false, 
                    'transactions'=> true, 
                    'expand' => false, 
                    'owner_funds' => false
                ]]
            ],
            result_in: 'result',
            timeout: $this->timeout
        )['ledger'];

        $ledgerTxs = $ledger['transactions'];

        $tx_data = [];
        $tx_curl = [];
        $sort_key = 0;

        foreach ($ledgerTxs as $transactionHash)
        {
            $tx_curl[] = requester_multi_prepare($this->select_node(), params: [ 'method' => 'tx',
            'params' => [[
                    'transaction' => "{$transactionHash}",
                    'binary' => false,
                    'id' => $sort_key++,

            ]]], timeout: $this->timeout);
        }
        $tx_curl_multi = requester_multi(
            $tx_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404]
        );
        foreach ($tx_curl_multi as $v)
            $tx_data[] = requester_multi_process($v);

        reorder_by_id($tx_data);
        $events = [];
        $sort_key = 0;
        $this->block_time = date('Y-m-d H:i:s', $ledger['close_time'] + 946684800);

        foreach ($tx_data as $tx) 
        {
            $tx = $tx['result'];
            $currency = null;
            $amount = '0';
            $issuer = null;
            $account = $tx['Account'];
            $tx_result = $tx['meta']['TransactionResult'] === 'tesSUCCESS' ? false : true; // yes, it's success but for is_failed it will be correct

            switch($tx['TransactionType']) {
                case 'CheckCash':
                    {
                        $account_destination = null;
                        $account = null;
                        if (is_array($tx['Amount'])) 
                        {
                            $currency = $tx['Amount']['currency'];
                            $amount = $this->to_96($tx['Amount']['value']);
                            $issuer = $tx['Amount']['issuer'];
                        } else {
                            break;
                        }
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection)
                            {
                                if (isset($affection['DeletedNode'])) // this means that check that was created by account in meta 
                                {                                     // will be deleted and money will come to Destination.
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'Check')
                                    {   
                                        $account_destination = $affection['DeletedNode']['FinalFields']['Destination'];
                                        $account = $affection['DeletedNode']['FinalFields']['Account'];
                                        break;
                                    }
                                }
                            }
                        } else {
                            throw new ModuleError("Incorrect flow for CheckCash");
                        }
                        $events[] = [
                            'currency' => $currency . '.' . $issuer,
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
                        $events[] = [
                            'currency' => $currency . '.' . $issuer,
                            'transaction' => $tx['hash'],
                            'address' => $account_destination,
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ]; 
                        break;
                    }
                case 'Payment':
                    {
                        if (is_array($tx['Amount'])) 
                        {
                            $currency = $tx['Amount']['currency'];
                            $amount = $this->to_96($tx['Amount']['value']);         // situation is the same
                            $issuer = $tx['Amount']['issuer']; 
                        } else {
                            break;
                        }
                        $events[] = [
                            'currency' => $currency . '.' . $issuer,
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
            
                        $events[] = [
                            'currency' => $currency . '.' . $issuer,
                            'transaction' => $tx['hash'],
                            'address' => $tx['Destination'],
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];  
                        break;
                    }
                case 'NFTokenAcceptOffer': 
                    {
                        // in NFT we have two ways of selling it:
                        // - p2p
                        // - brokers
                        // It's very interesting, if one of the payment (broker fee a.e.) is in tokens and others in XRP?

                        // funny situation with this transfer 015C6C15DED3E76A089321BDA9090B718F32EA3925DB188EC88DA6B77180BF76
                        // here we have a suitable transfer, but it ends with error and doesn't contain meta any offers
                        // so we need to make a third wheel for bike ;(0)
                        $broker_op = isset($tx['NFTokenBrokerFee']);
                        $prev_pay = '0';
                        $pay = '0';
                        $new_owner = null;
                        $prev_owner = null;
                        $broker_fee = '0';
                        $flag_assets = 0;
                        if ($broker_op)
                        {
                            if (is_array($tx['NFTokenBrokerFee'])) 
                            {
                                $currency = $tx['NFTokenBrokerFee']['currency']; 
                                $broker_fee = $this->to_96($tx['NFTokenBrokerFee']['value']);
                                $issuer = $tx['NFTokenBrokerFee']['issuer'];
                                $flag_assets++;
                            } else {
                                $broker_fee = $tx['NFTokenBrokerFee'];
                            }
                        }
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) {
                                    if (!$broker_op && $affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer') 
                                    {
                                        $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                        $new_owner = $tx['Account'];
                                        if (is_array($affection['DeletedNode']['FinalFields']['Amount'])) {
                                            if((!is_null($currency) && !is_null($issuer)) && ($currency . '.' . $issuer) !== $affection['DeletedNode']['FinalFields']['Amount']['currency'] . '.' .
                                                             $affection['DeletedNode']['FinalFields']['Amount']['issuer'])
                                            {
                                                throw new ModuleError("Change of currency in NFTokenAcceptOffer");
                                            }
                                            $pay = $this->to_96($affection['DeletedNode']['FinalFields']['Amount']['value']);
                                            $flag_assets++;
                                        } else {
                                            // $pay = $affection['DeletedNode']['FinalFields']['Amount']; // it was in main module
                                        }
                                        break;
                                    }
                                    if ($broker_op && $affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer') 
                                    {
                                        // https://xrpl.org/nftokencreateoffer.html#nftokencreateoffer-flags
                                        if ($affection['DeletedNode']['FinalFields']['Flags'] == 0)     // it means that it's NFT buy offer
                                        {
                                            $new_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                            if (is_array($affection['DeletedNode']['FinalFields']['Amount'])) 
                                            {    
                                                if((!is_null($currency) && !is_null($issuer)) && 
                                                    ($currency . '.' . $issuer) !== 
                                                    $affection['DeletedNode']['FinalFields']['Amount']['currency'] . '.' . 
                                                                 $affection['DeletedNode']['FinalFields']['Amount']['issuer'])
                                                {
                                                    throw new ModuleError("Change of currency in NFTokenAcceptOffer");
                                                }
                                                $pay = $this->to_96($affection['DeletedNode']['FinalFields']['Amount']['value']);
                                                $flag_assets++;
                                            } else {
                                                // $pay = $affection['DeletedNode']['FinalFields']['Amount']; // it was in main module
                                            }
                                        }
                                        if ($affection['DeletedNode']['FinalFields']['Flags'] == 1)     // it means that it's NFT sell offer 
                                        {
                                            $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                            if (is_array($affection['DeletedNode']['FinalFields']['Amount'])) 
                                            {
                                                if((!is_null($currency) && !is_null($issuer)) && 
                                                    ($currency . '.' . $issuer) !== 
                                                    $affection['DeletedNode']['FinalFields']['Amount']['currency'] . '.' .
                                                                 $affection['DeletedNode']['FinalFields']['Amount']['issuer'])
                                                {
                                                    throw new ModuleError("Change of currency in NFTokenAcceptOffer");
                                                }
                                                $prev_pay = $this->to_96($affection['DeletedNode']['FinalFields']['Amount']['value']);
                                                $flag_assets++;
                                            } else {
                                                // $prev_pay = $affection['DeletedNode']['FinalFields']['Amount']; // it was in main module
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        if(!$flag_assets)
                        {
                            break;
                        }
                        if($broker_op && is_null($prev_owner) && is_null($new_owner))
                        {   // this is for situation when the transaction fallen
                            // mostly in this situations we don't need to pay anything in Token module
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $tx['Account'],
                                'sort_key' => $sort_key++,
                                'effect' => '-0',
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => 'the-void',
                                'sort_key' => $sort_key++,
                                'effect' => '0',
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $currency = null;
                            break;
                        }
                        if ($broker_op) 
                        {
                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $prev_pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $prev_owner,
                                'sort_key' => $sort_key++,
                                'effect' => $prev_pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];

                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $broker_fee,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $tx['Account'],
                                'sort_key' => $sort_key++,
                                'effect' => $broker_fee,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                        } else {
                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'currency' => $currency . '.' . $issuer,
                                'transaction' => $tx['hash'],
                                'address' => $prev_owner,
                                'sort_key' => $sort_key++,
                                'effect' => $pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                        }
                        break;
                    }
                default:
                    break;
            };

            if ($currency != null) 
            {
                if (!isset($currency_by_id[$currency . "." . $issuer])) 
                {
                    $currency_by_id[$currency . "." . $issuer] = [
                        'id'       => $currency . "." . $issuer,
                        'name'     => $currency,
                        'symbol'   => $currency,
                        'decimals' => 96,
                    ];
                }
            }
        }

        $currencies_to_process = check_existing_currencies(array_keys($currency_by_id), $this->currency_format); // Removes already known currencies

        ////////////////
        // Processing //
        ////////////////

        // In Ripple names can be presented as normal text: 'ABC' or in hex representation: '47616C6178790000000000000000000000000000'
        // Unfortunately it can have a text like this: '9A3', that will be recognized by ctype_xdigit() as hex 
        // So firstly we need to check parity with strlen(), after:
        //      1) if it's odd: we just trim and convert encoding
        //      2) if it's even we check for hex inside:
        //          a) if it's hex inside - we do hex2bin and then (1)
        //          b) if no, we do (1)

        foreach ($currencies_to_process as $currency) 
        {
            $name = strlen($currency_by_id[$currency]['name']) % 2 ?
                        trim(mb_convert_encoding($currency_by_id[$currency]['name'], 'UTF-8', 'UTF-8')) : 
                        (ctype_xdigit($currency_by_id[$currency]['name']) ?
                        trim(mb_convert_encoding(hex2bin($currency_by_id[$currency]['name']), 'UTF-8', 'UTF-8')) :
                        trim(mb_convert_encoding($currency_by_id[$currency]['name'], 'UTF-8', 'UTF-8')));
            $symbol = strlen($currency_by_id[$currency]['symbol']) % 2 ?
                        trim(mb_convert_encoding($currency_by_id[$currency]['symbol'], 'UTF-8', 'UTF-8')) : 
                        (ctype_xdigit($currency_by_id[$currency]['symbol']) ?
                        trim(mb_convert_encoding(hex2bin($currency_by_id[$currency]['symbol']), 'UTF-8', 'UTF-8')) :
                        trim(mb_convert_encoding($currency_by_id[$currency]['symbol'], 'UTF-8', 'UTF-8')));
            $currencies[] = [
                'id'       => $currency,
                'name'     => $name,
                'symbol'   => $symbol,
                'decimals' => 96,
            ];
        }

        ////////////////
        // Processing //
        ////////////////

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
            $event['transaction'] = strtolower($event['transaction']);
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        $real_currencies = [];

        // Input currencies should be in format like this: `ripple-token/{name.ripple-like-address}`
        foreach ($currencies as $c)
            $real_currencies[] = explode(".",explode('/', $c)[1]);

        $account_currencies = requester_single(
            $this->select_node(),
            params: [
                'method' => 'gateway_balances',
                'params' => [[
                    'account' => "{$address}",
                    'ledger_index' => 'closed',
                    'strict' => true
                ]]
            ],
            result_in: 'result',
            timeout: $this->timeout
        )['assets'];

        $return = [];
        foreach($real_currencies as $currency) 
        {
            if(isset($account_currencies[$currency[1]]))
            {
                $found_asset = false;
                foreach ($account_currencies[$currency[1]] as $asset)
                {
                    if ($asset["currency"] === $currency[0])
                    {
                        $found_asset = true;
                        $return[] = $this->to_96($asset['value']);
                    }
                }

                if (!$found_asset)
                {
                    $return[] = '0';
                }
            }
            else {
                $return[] = '0';
            }
        }

        return $return;
    }
}
