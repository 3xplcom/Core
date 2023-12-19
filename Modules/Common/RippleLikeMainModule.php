<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes main Ripple transfers. Requires a Ripple node.  */

abstract class RippleLikeMainModule extends CoreModule
{
    use RippleTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void', 'payment-channels', 'the-escrow'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = null;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = false;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

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


    final public function pre_process_block($block_id)
    {
        
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
        $this->block_time = date('Y-m-d H:i:s', $ledger['close_time'] + 946684800); // here, close_time sets as the number of seconds since the Ripple Epoch of 2000-01-01 00:00:00

        foreach ($tx_data as $tx) 
        {
            $tx = $tx['result'];
            $amount = '0';
            $account = $tx['Account'];
            $fee = $tx['Fee'];

            if (!isset($tx['meta']))
                throw new ModuleException("Transactions haven't been fully processed by the node yet");
            
            // As we have 'failed' in events - so for us tesSUCCESS ~ false, because it means not 'failed'
            $tx_result = $tx['meta']['TransactionResult'] === 'tesSUCCESS' ? false : true;

            switch($tx['TransactionType']) {
                case 'AccountDelete': 
                    {
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) 
                                {   // If we say that they send all money before being dead - ok
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'AccountRoot') 
                                    {
                                        $amount = $affection['DeletedNode']['PreviousFields']['Balance'] - $fee;
                                        break;
                                    }
                                }
                            }
                        } else {
                            throw new ModuleError("Incorrect flow for AccountDelete");
                        }
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $tx['Destination'],
                            'sort_key' => $sort_key++,
                            'effect' => (string)$amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
                        goto FEES;
                        break;
                    }
                case 'CheckCash':
                    {
                        $account_destination = null;
                        if (is_array($tx['Amount'])) 
                        {
                            // it's a token module work
                            $amount = '0';
                        } else {
                            $amount = $tx['Amount'];
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
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account_destination,
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ]; 
                        goto FEES;
                        break;
                    }
                case 'EscrowCreate':
                    {
                        $amount = $tx['Amount'];
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
            
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => 'the-escrow',
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ]; 
                        goto FEES; 
                        break;
                    }
                case 'EscrowCancel':
                case 'EscrowFinish':        
                    {
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection)
                            {
                                if (isset($affection['DeletedNode'])) 
                                {                                     
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'Escrow')
                                    {
                                        $amount = $affection['DeletedNode']['FinalFields']['Amount'];
                                        break;
                                    }
                                }
                            }
                        } else {
                            throw new ModuleError("Incorrect flow for EscrowFinish and EscrowCancel");
                        }
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => 'the-escrow',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
            
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ]; 
                        goto FEES; 
                        break; 
                    }
                case 'Payment':
                    {
                        if (is_array($tx['Amount'])) 
                        {
                            // it's a token module work
                            $amount = '0';  
                        } else {
                            $amount = $tx['Amount'];
                        }
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
            
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $tx['Destination'],
                            'sort_key' => $sort_key++,
                            'effect' => $amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];  
                        goto FEES;
                        break;
                    }
                case 'PaymentChannelCreate':
                case 'PaymentChannelFund': 
                    {
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $tx['Amount'],
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => 'payment-channels',
                            'sort_key' => $sort_key++,
                            'effect' => $tx['Amount'],
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
                        goto FEES;
                        break;
                    }
                case 'PaymentChannelClaim': {
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) 
                                {
                                    // really it's an interesting question that Claim can close and send money to the creator, but I don't have proofs
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'PayChannel') // it means deleting the channel, it can happened in  this type of transaction
                                    {
                                        $amount = $affection['DeletedNode']['FinalFields']['Balance'] -
                                            $affection['DeletedNode']['PreviousFields']['Balance'];
                                        $account = $affection['DeletedNode']['FinalFields']['Destination'];
                                        break;
                                    }
                                }
                                if (isset($affection['ModifiedNode']['LedgerEntryType'])) 
                                {
                                    if ($affection['ModifiedNode']['LedgerEntryType'] === 'PayChannel') 
                                    {
                                        $amount = $affection['ModifiedNode']['FinalFields']['Balance'] -
                                            $affection['ModifiedNode']['PreviousFields']['Balance'];
                                        $account = $affection['ModifiedNode']['FinalFields']['Destination'];
                                        break;
                                    }
                                }
                            }
                        }

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => 'payment-channels',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . (string)$amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $account,
                            'sort_key' => $sort_key++,
                            'effect' => (string)$amount,
                            'failed' => $tx_result,
                            'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                        ];
                        goto FEES;
                        break;
                    }
                case 'NFTokenAcceptOffer': 
                    {
                        // in NFT we have two ways of selling it:
                        // - p2p
                        // - brokers
                        $broker_op = isset($tx['NFTokenBrokerFee']); 
                        $prev_pay = '0';
                        $pay = '0';
                        $new_owner = null;
                        $broker_fee = '0';
                        $prev_owner = null;
                        if ($broker_op)
                        {
                            if (is_array($tx['NFTokenBrokerFee'])) 
                            {
                                // it's a token module work
                                $broker_fee = '0';
                            } else {
                                $broker_fee = $tx['NFTokenBrokerFee'];
                            }
                        }
                        
                        if (isset($tx['meta']['AffectedNodes'])) {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) {
                                    if (!$broker_op && $affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer') 
                                    {
                                        $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                        $new_owner = $tx['Account'];
                                        if (is_array($affection['DeletedNode']['FinalFields']['Amount'])) 
                                        {
                                            // it's a token module work
                                            $pay = '0';
                                        } else {
                                            $pay = $affection['DeletedNode']['FinalFields']['Amount'];
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
                                                // it's a token module work
                                                $pay = '0';
                                            } else {
                                                $pay = $affection['DeletedNode']['FinalFields']['Amount'];
                                            }
                                        }
                                        if ($affection['DeletedNode']['FinalFields']['Flags'] == 1)     // it means that it's NFT sell offer 
                                        {
                                            $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                            if (is_array($affection['DeletedNode']['FinalFields']['Amount'])) 
                                            {
                                                // it's a token module work
                                                $prev_pay = '0';
                                            } else {
                                                $prev_pay = $affection['DeletedNode']['FinalFields']['Amount'];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        if($broker_op && is_null($prev_owner) && is_null($new_owner))
                        {   // this is for situation when the transaction is fallen
                            // mostly in this situations we don't need to pay
                            // 83084012 - here is error
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
                            goto FEES;
                            break;
                        } elseif(!$broker_op && is_null($prev_owner))
                        {   // this is for situation when the transaction is fallen
                            // mostly in this situations we don't need to pay
                            // 83083328 - here is a second
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
                            goto FEES;
                            break;
                        }
                        if ($broker_op) 
                        {
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $prev_pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $prev_owner,
                                'sort_key' => $sort_key++,
                                'effect' => $prev_pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];

                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $broker_fee,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => $broker_fee,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                        } else {
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $new_owner,
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $prev_owner,
                                'sort_key' => $sort_key++,
                                'effect' => $pay,
                                'failed' => $tx_result,
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                        }
                        goto FEES;
                        break;
                    }
                // Yes it's only fees here ;)
                case 'AccountSet':
                case 'CheckCancel':
                case 'CheckCreate':
                case 'DepositPreauth':
                case 'NFTokenBurn':
                case 'NFTokenCancelOffer':
                case 'NFTokenCreateOffer':
                case 'NFTokenMint':
                case 'OfferCancel':
                case 'OfferCreate':
                case 'SetRegularKey':
                case 'SignerListSet':
                case 'TicketCreate':
                case 'TrustSet': 
                case 'UNLModify':
                   FEES: {
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => $tx['Account'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $fee,
                            'failed' => false,
                            'extra' => 'f',
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address' => 'the-void',
                            'sort_key' => $sort_key++,
                            'effect' => $fee,
                            'failed' => false,
                            'extra' => 'f',
                        ];
                        break;
                    }
                default:
                    throw new ModuleError("Unknown transaction type: " . $tx['TransactionType']);
            };
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
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        return (string)requester_single(
            $this->select_node(),
            params: [
                'method' => 'account_info',
                'params' => [[
                    'account' => "{$address}",
                    'strict' => false,
			        'ledger_index' => "closed",
			        'queue' => false
                ]]
            ],
            result_in: 'result',
            timeout: $this->timeout
        )['account_data']['Balance'];
    }
}
