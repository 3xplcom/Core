<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with assets in Ripple. Requires a Ripple node.  */

abstract class RippleLikeFTModule extends CoreModule
{
    use RippleTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
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

    public string $block_entity_name = 'ledger';
    public string $address_entity_name = 'account';

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
            $currency = null; // IN ONE transaction can be SEVERAL currencies
            $amount = null;

            if (!isset($tx['meta']))
                throw new ModuleException("Transactions haven't been fully processed by the node yet");

            $tx_result = !($tx['meta']['TransactionResult'] === 'tesSUCCESS'); // yes, it's success but for is_failed it will be correct

            $currencies_in_tx = []; // an array with currencies from tx in currency.issuer format
            switch($tx['TransactionType']) {
                // https://github.com/XRPLF/XRPL-Standards/tree/master/XLS-0039d-clawback#332-example-clawback-transaction
                // https://github.com/XRPLF/rippled/blob/2d1854f354ff8bb2b5671fd51252c5acd837c433/src/ripple/app/tx/impl/Clawback.cpp#L55
                // In source code we can see that for accounts it's possible to have negative balance
                // However, in our realization we have bcsub(NEW, BEFORE) that returns negative in a case when NEW < BEFORE
                case "Clawback":
                    {
                        foreach ($tx['meta']['AffectedNodes'] ?? [] as $id => $affection) 
                        {
                            if (isset($affection['ModifiedNode']) && $affection['ModifiedNode']['LedgerEntryType'] === 'RippleState') 
                            {
                                // problem that ripple developers don't want add new fields to their API
                                // so we have to take a real issuer/currency from another fields, not from Amount['issuer']
                                // btw in source code they do this:
                                // ```cpp
                                //      AccountID const& issuer = account_;
                                //      STAmount clawAmount = ctx_.tx[sfAmount];
                                //      AccountID const holder = clawAmount.getIssuer();  // cannot be reference
                                // ```
                                $issuer = $tx['Account'];
                                $currency = $tx['Amount']['currency'];
                                // the difference should always be > 0
                                $claw_back = bcsub(
                                    $this->bcabs($this->to_96($affection['ModifiedNode']['PreviousFields']['Balance']['value'])), 
                                    $this->bcabs($this->to_96($affection['ModifiedNode']['FinalFields']['Balance']['value']))
                                );
                                $holder = $tx['Amount']['issuer'];
                                $events[] = [
                                    'currency' => $currency . '.' . $issuer,
                                    'transaction' => $tx['hash'],
                                    'address' => $holder,
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $claw_back,
                                    'failed' => $tx_result,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $events[] = [
                                    'currency' => $currency . '.' . $issuer,
                                    'transaction' => $tx['hash'],
                                    'address' => $issuer,
                                    'sort_key' => $sort_key++,
                                    'effect' => $claw_back,
                                    'failed' => $tx_result,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $this->insert_currency_to_process($currency_by_id, $currency . '.' . $issuer);
                                break;
                            }
                        }
                        break;
                    }
                    // in NFT we have two ways of selling it:
                    // - p2p
                    // - brokers
                    // It's very interesting, if one of the payment (broker fee a.e.) is in tokens and others in XRP? -- No it's imposible by SC
                    // funny situation with this transfer 015C6C15DED3E76A089321BDA9090B718F32EA3925DB188EC88DA6B77180BF76
                    // here we have a suitable transfer, but it ends with error and doesn't contain meta any offers -- Now will not affect us
                    // so we need to make a third wheel for bike ;(0)
                    // sell offer example 356DE89390EBB12E621D6AE8EF89F1A4B6184013EB8E94AB84F2A1D8AB9ED3E6
                    // buy offer haven't found
                    // By the Ripple code it's important to check Flag, but we don't do it because glue_balances magic will solve it
                    // we just need a currency
                    // ```cpp
                    //      bool const isSell = offer->isFlag(lsfSellNFToken);
                    //      AccountID const owner = (*offer)[sfOwner];
                    //      AccountID const& seller = isSell ? owner : account_;
                    //      AccountID const& buyer = isSell ? account_ : owner;
                    // ```
                case 'NFTokenAcceptOffer': 
                    {
                        if(isset($tx['NFTokenBrokerFee']) && is_array($tx['NFTokenBrokerFee']) && count($tx['NFTokenBrokerFee']) > 1) 
                        {
                            $currencies_in_tx[] = $tx['NFTokenBrokerFee']['currency'] . '.' . $tx['NFTokenBrokerFee']['issuer'];
                            break;
                        } else {
                            foreach ($tx['meta']['AffectedNodes'] ?? [] as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode']) && $affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer' &&
                                    is_array($affection['DeletedNode']['FinalFields']['Amount'])) 
                                {
                                    $currencies_in_tx[] = $affection['DeletedNode']['FinalFields']['Amount']['currency'] . '.' . 
                                                          $affection['DeletedNode']['FinalFields']['Amount']['issuer'];
                                    break; 
                                }
                            }
                        }
                        // i'd like to use here goto
                    }
                case 'AMMBid':
                case 'AMMCreate': 
                case 'AMMDeposit': 
                case 'AMMWithdraw': 
                case 'AMMDelete':
                {
                    foreach ($tx['meta']['AffectedNodes'] ?? [] as $id => $affection) 
                    {
                        if (isset($affection['ModifiedNode']) || isset($affection['DeletedNode'])) 
                        {
                            $affected = $affection['ModifiedNode'] ?? $affection['DeletedNode'];
                            if($affected['LedgerEntryType'] === 'AMM')
                            {
                                $currencies_in_tx[] = $affected['FinalFields']['LPTokenBalance']['currency'] . '.' . 
                                                      $affected['FinalFields']['Account'];
                                break;
                            }
                        }
                        if (isset($affection['CreatedNode']) && $affection['LedgerEntryType'] === 'AMM') 
                        {
                            $currencies_in_tx[] = $affection['CreatedNode']['NewFields']['LPTokenBalance']['currency'] . '.' . 
                                                  $affection['CreatedNode']['NewFields']['Account'];
                            break;
                        }
                    }
                }
                case 'Payment':
                {
                    if(isset($tx['SendMax']) && is_array($tx['SendMax']) && count($tx['SendMax']) > 1)
                    {
                        $currencies_in_tx[] = $tx['SendMax']['currency'] . '.' . $tx['SendMax']['issuer'];
                    }
                    if(isset($tx['Paths']) && is_array($tx['Paths']))
                    {
                        foreach($tx['Paths'] as $path) // https://github.com/XRPLF/rippled/blob/2d1854f354ff8bb2b5671fd51252c5acd837c433/src/ripple/app/paths/impl/PaySteps.cpp#L539
                        {
                            for($i = 0; $i < count($path); $i++) // https://github.com/XRPLF/rippled/blob/2d1854f354ff8bb2b5671fd51252c5acd837c433/src/ripple/app/paths/impl/PaySteps.cpp#L286
                            {
                                if(isset($path[$i]['account']))
                                {
                                    continue;
                                }
                                if(isset($path[$i]['issuer']) && isset($path[$i]['currency']))
                                {
                                    $currencies_in_tx[] =$path[$i]['currency'] . '.' . $path[$i]['issuer'];
                                    continue;
                                }
                                if(isset($path[$i]['issuer']) && !isset($path[$i]['currency']))
                                {
                                    if($i === 0)
                                        throw new ModuleError("Unknown situation, needs to be solved in tx: " . $tx['hash']);
                                    $currencies_in_tx[] =$path[$i - 1]['currency'] . '.' . $path[$i]['issuer'];
                                    continue;
                                }
                                if(!isset($path[$i]['issuer']) && isset($path[$i]['currency']))
                                {
                                    if($path[$i]['currency'] !== 'XRP')
                                        throw new ModuleError("Unknown situation, needs to be solved in tx: " . $tx['hash']);
                                }
                            }
                        }
                    }
                    
                }
                case 'CheckCash':
                case 'OfferCreate':
                    {
                        // for correct working we should correctly find currency and issuer
                        // Maybe it's better to make a pool of assets and don't care about what equals to what???
                        // the key currency.issuer should be unique for all of it
                        if(isset($tx['Asset']) && is_array($tx['Asset']) && count($tx['Asset']) > 1)
                        {
                            $currencies_in_tx[] = $tx['Asset']['currency'] . '.' . $tx['Asset']['issuer'];
                        }
                        if(isset($tx['Asset2']) && is_array($tx['Asset2']) && count($tx['Asset2']) > 1)
                        {
                            $currencies_in_tx[] = $tx['Asset2']['currency']. '.'  . $tx['Asset2']['issuer']; 
                        }
                        if(isset($tx['Amount']) && is_array($tx['Amount']) && count($tx['Amount']) > 1)
                        {
                            $currencies_in_tx[] = $tx['Amount']['currency'] . '.' . $tx['Amount']['issuer'];
                        }
                        if(isset($tx['Amount2']) && is_array($tx['Amount2']) && count($tx['Amount2']) > 1)
                        {
                            $currencies_in_tx[] = $tx['Amount2']['currency'] . '.' . $tx['Amount2']['issuer'];
                        }
                        if(isset($tx['TakerGets']) && is_array($tx['TakerGets']) && count($tx['TakerGets']) > 1)
                        {
                            $currencies_in_tx[] = $tx['TakerGets']['currency'] . '.' . $tx['TakerGets']['issuer'];
                        }
                        if(isset($tx['TakerPays']) && is_array($tx['TakerPays']) && count($tx['TakerPays']) > 1) 
                        {
                            $currencies_in_tx[] = $tx['TakerPays']['currency'] . '.' . $tx['TakerPays']['issuer'];
                        }
                        // By documentation it's APIv2 and replace Amount field in Payment tx
                        if(isset($tx['DeliverMax']) && is_array($tx['DeliverMax']) && count($tx['DeliverMax']) > 1) 
                        {
                            $currencies_in_tx[] = $tx['DeliverMax']['currency'] . '.' . $tx['DeliverMax']['issuer'];
                        }
                        if(isset($tx['DeliverMin']) && is_array($tx['DeliverMin']) && count($tx['DeliverMin']) > 1) 
                        {
                            $currencies_in_tx[] = $tx['DeliverMin']['currency'] . '.' . $tx['DeliverMin']['issuer'];
                        }
                        $currencies_in_tx = array_unique($currencies_in_tx, SORT_STRING);
                        if(!count($currencies_in_tx))
                        {
                            // if we haven't found anything -- just pass it 
                            // for be more sure we can scan for RippleState 
                            break;
                        }
                        // maybe for a fallen transactions we can generate a simple event from account to the-void and check failed = true
                        if($tx_result)
                        {
                            // here we will lose effect generated by Payment, but 'Amount' there can be different (it can be approximate)
                            // also we are not sure in asset here, because for 
                            foreach($currencies_in_tx as $cr)
                            {
                                $events[] = [
                                    'currency' => $cr,
                                    'transaction' => $tx['hash'],
                                    'address' => $tx['Account'],
                                    'sort_key' => $sort_key++,
                                    'effect' => '-0',
                                    'failed' => true,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $events[] = [
                                    'currency' => $cr,
                                    'transaction' => $tx['hash'],
                                    'address' => 'the-void',
                                    'sort_key' => $sort_key++,
                                    'effect' => '0',
                                    'failed' => true,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $this->insert_currency_to_process($currency_by_id, $cr);
                            }
                            break;
                        }
                        // And now we can try to map addresses or not
                        // For simple tokens it's possible to find mapping
                        // For LP tokens I think - no
                        foreach ($tx['meta']['AffectedNodes'] ?? [] as $id => $affection) 
                        {
                            $account1 = null;
                            $account2 = null;
                            if (isset($affection['ModifiedNode']) || isset($affection['DeletedNode'])) 
                            {
                                $affected = isset($affection['ModifiedNode']) ? $affection['ModifiedNode'] : $affection['DeletedNode'];
                                if ($affected['LedgerEntryType'] !== 'RippleState') 
                                {
                                    continue;
                                }
                                // we need to check every HL and LL if it's currency or LP
                                // now we always have token account as account1
                                if(in_array($affected['FinalFields']['HighLimit']['currency'] . '.' . 
                                            $affected['FinalFields']['HighLimit']['issuer'], 
                                            $currencies_in_tx, true))
                                {
                                    $currency = $affected['FinalFields']['HighLimit']['currency'] . '.' .
                                                $affected['FinalFields']['HighLimit']['issuer'];
                                    $account1 = $affected['FinalFields']['HighLimit']['issuer']; // token
                                    $account2 = $affected['FinalFields']['LowLimit']['issuer'];  // user
                                }
                                if(in_array($affected['FinalFields']['LowLimit']['currency'] . '.' . 
                                            $affected['FinalFields']['LowLimit']['issuer'], 
                                            $currencies_in_tx, true))
                                {
                                    $currency = $affected['FinalFields']['LowLimit']['currency'] . '.' . 
                                                $affected['FinalFields']['LowLimit']['issuer'];
                                    $account1 = $affected['FinalFields']['LowLimit']['issuer'];  // token
                                    $account2 = $affected['FinalFields']['HighLimit']['issuer']; // user
                                }
                                $amount = bcsub(
                                    $this->bcabs($this->to_96($affected['PreviousFields']['Balance']['value'])),
                                    $this->bcabs($this->to_96($affected['FinalFields']['Balance']['value']))
                                );
                                // Funny thing this TrustLine - in reality it's a connection between account and
                                // account-manager (of token) and difference can be negative, but in reality 
                                // it means that we just need to swap account1 and account2
                                // BUT IT SHOULD BE CHECKED
                                // If diff is negative -- it's from token to user
                                //            positive --      from user to token
                                if($amount[0] !== '-')
                                {
                                    $account_x = $account1;
                                    $account1 = $account2;
                                    $account2 = $account_x;
                                } else {
                                    $amount = substr($amount, 1);
                                }
                                $events[] = [
                                    'currency' => $currency,
                                    'transaction' => $tx['hash'],
                                    'address' => $account1,
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $amount,
                                    'failed' => false,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $events[] = [
                                    'currency' => $currency,
                                    'transaction' => $tx['hash'],
                                    'address' => $account2,
                                    'sort_key' => $sort_key++,
                                    'effect' => $amount,
                                    'failed' => false,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $this->insert_currency_to_process($currency_by_id, $currency);
                            }
                            if (isset($affection['CreatedNode'])) 
                            {
                                $affected = $affection['CreatedNode'];
                                if ($affected['LedgerEntryType'] === 'RippleState') 
                                {
                                    if(in_array($affected['NewFields']['HighLimit']['currency'] . '.' . 
                                                $affected['NewFields']['HighLimit']['issuer'], 
                                                $currencies_in_tx, true))
                                    {
                                        $currency = $affected['NewFields']['HighLimit']['currency'] . '.' . 
                                                    $affected['NewFields']['HighLimit']['issuer'];
                                        $account1 = $affected['NewFields']['HighLimit']['issuer']; // token
                                        $account2 = $affected['NewFields']['LowLimit']['issuer'];  // user
                                    }
                                    if(in_array($affected['NewFields']['LowLimit']['currency'] . '.' . 
                                                $affected['NewFields']['LowLimit']['issuer'], 
                                                $currencies_in_tx, true))
                                    {
                                        $currency = $affected['NewFields']['LowLimit']['currency'] . '.' . 
                                                    $affected['NewFields']['LowLimit']['issuer'];
                                        $account1 = $affected['NewFields']['LowLimit']['issuer'];  // token
                                        $account2 = $affected['NewFields']['HighLimit']['issuer']; // user
                                    }

                                    $amount = bcsub(
                                        "0",
                                        $this->bcabs($this->to_96($affected['NewFields']['Balance']['value']))
                                    );
                                    // Funny thing this TrustLine - in reality it's a connection between account and
                                    // account-manager (of token) and difference can be negative, but in reality 
                                    // it means that we just need to swap account1 and account2
                                    // BUT IT SHOULD BE CHECKED
                                    // If diff is negative -- it's from token to user
                                    //            positive --      from user to token
                                    if($amount[0] !== '-')
                                    {
                                        $account_x = $account1;
                                        $account1 = $account2;
                                        $account2 = $account_x;
                                    } else {
                                        $amount = substr($amount, 1);
                                    }
                                    $events[] = [
                                        'currency' => $currency,
                                        'transaction' => $tx['hash'],
                                        'address' => $account1,
                                        'sort_key' => $sort_key++,
                                        'effect' => '-' . $amount,
                                        'failed' => false,
                                        'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                    ];
                                    $events[] = [
                                        'currency' => $currency,
                                        'transaction' => $tx['hash'],
                                        'address' => $account2,
                                        'sort_key' => $sort_key++,
                                        'effect' => $amount,
                                        'failed' => false,
                                        'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                    ];
                                    $this->insert_currency_to_process($currency_by_id, $currency);
                                }
                            }
                        }
                        break;  
                    }
                default:
                    break;
            };
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

        $events = $this->glue_events($events);
        $sort_key = 0;
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
            $event['sort_key'] = $sort_key++;
            $event['transaction'] = strtolower($event['transaction']);
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
        );

        if(isset($account_currencies['assets']))
        {
            $account_currencies = $account_currencies['assets'];
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
        }

        return $return;
    }

    private function insert_currency_to_process(&$currency_by_id, $currency)
    {
        $currency_composed = explode('.', $currency);
        if (!isset($currency_by_id[$currency])) 
        {
            $currency_by_id[$currency] = [
                'id'       => $currency,
                'name'     => $currency_composed[0],
                'symbol'   => $currency_composed[0],
                'decimals' => 96,
            ];
        }
    }

    private function glue_events($events)
    {
        $events_by_tx = [];
        $new_events = [];
        for($i = 0; $i < count($events); $i++)
        {
            if($i + 1 < count($events) && $events[$i]['transaction'] != $events[$i + 1]['transaction'] || $i + 1 ==  count($events))
            {
                $events_by_tx[] = $events[$i];
                if(count($events_by_tx) == 2)
                {
                    $new_events[] = $events_by_tx[0];
                    $new_events[] = $events_by_tx[1];
                    unset($events_by_tx);
                    continue;
                }
                // IN FT module we don't have fees

                // events can be:
                // - (-)void - (+)account1 - (-)account2 - (+)void -> (-)account2 - (+)account1
                // - (-)account2 - (+)void - (-)void - (+)account1 -> (-)account2 - (+)account1
                // unfortunately this it's not fully correct, because in chain can be inserted other events with other balances
                // - (-)void - (+)account1 effect1 - (-)account3 - (+)void effect2 - (-)account2 - (+)void effect1 -> (-)account2 - (+)account1
                // in addition it can be some situations 
                // - (-)void - (+)account1 effect1 - (-)account3 - (+)void effect2 - (-)account2 - (+)void effect1 -> (-)account2 - (+)account1
                // where effect1 == effect2, but real event will be (-)account2 - (+)account3
                // HERE THE-VOID is a currency issuer (address)
                // I suppose that it should be very rare situation, but can be
                // Now it will be like that: 
                //                              1. Take first pair of events
                //                              2. Search for the first same pair (same balance and opposite void)
                //                              3. Delete this and do (1)
                for($y = 0; $y < count($events_by_tx);)
                {
                    $ev_pair1 = $events_by_tx[$y];
                    $ev_pair2 = $events_by_tx[$y + 1];
                    $the_void_replacement = explode(".", $ev_pair1['currency'])[1];
                    if($the_void_replacement !== explode(".", $ev_pair2['currency'])[1])
                    {
                        throw new ModuleError("Events are not even");
                    }
                    array_splice($events_by_tx, $y + 1, 1);
                    array_splice($events_by_tx, $y, 1);
                    $found = false;
                    // the amount of events should be even 
                    for($z = 0; $z < count($events_by_tx); $z += 2)
                    {
                        if ( $z + 1 < count($events_by_tx) &&
                            $ev_pair1['effect'] === $events_by_tx[$z]['effect'] &&
                            $ev_pair2['effect'] === $events_by_tx[$z + 1]['effect']) 
                        {
                            if ($ev_pair1['address'] === $the_void_replacement &&
                                $events_by_tx[$z + 1]['address'] === $the_void_replacement) 
                            {
                                $new_events[] = $events_by_tx[$z];
                                $new_events[] = $ev_pair2;
                                $found = true;
                                array_splice($events_by_tx, $z + 1, 1);
                                array_splice($events_by_tx, $z, 1);
                                break;
                            }
                            if ($ev_pair2['address'] === $the_void_replacement &&
                                $events_by_tx[$z]['address'] === $the_void_replacement) 
                            {
                                $new_events[] = $ev_pair1;
                                $new_events[] = $events_by_tx[$z + 1];
                                $found = true;
                                array_splice($events_by_tx, $z + 1, 1);
                                array_splice($events_by_tx, $z, 1);
                                break;
                            }
                            // it's possible that nothing will be found here 
                        }
                    } 
                    if(!$found)
                    {
                        $new_events[] = $ev_pair1;
                        $new_events[] = $ev_pair2;
                    }
                }
                unset($events_by_tx);
                continue;
            }
            $events_by_tx[] = $events[$i];
        }
        return $new_events;
    }
}
