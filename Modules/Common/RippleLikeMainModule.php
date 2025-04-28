<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

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
    public ?array $special_addresses = ['the-void'];
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
            $tx_result = !($tx['meta']['TransactionResult'] === 'tesSUCCESS');

            switch($tx['TransactionType']) { 
                case 'AccountDelete':   
                case 'AMMBid':
                case 'AMMCreate':
                case 'AMMDeposit':
                case 'AMMWithdraw':
                // 371a4a5f0d4f446f5a41661c937eba74c50afd99b716ab345158325bb6e6fd7d -- fee payment for nft trading
                case 'NFTokenAcceptOffer': // we don't care who is broker,seller, buyer because we will glue everything however if Amount is 0 we'll not have any event (only fee)
                case 'PaymentChannelCreate':
                case 'PaymentChannelFund': 
                case 'PaymentChannelClaim':
                case 'AccountDelete':
                case 'EscrowCancel':
                case 'EscrowFinish': 
                case 'OfferCreate':
                case 'Payment':
                case 'CheckCash':
                case 'EscrowCreate':
                    {
                        // https://github.com/XRPLF/rippled/blob/2d1854f354ff8bb2b5671fd51252c5acd837c433/src/ripple/app/tx/impl/AMMVote.cpp#L239
                        // ```cpp
                        //     if (result.second)
                        //          sb.apply(ctx_.rawView());
                        // ```
                        // This part of code means that in any situation if the transaction was fallen -- they just do nothing
                        // So we can ignore fallen transactions and generate null events
                        if ($tx_result)
                        {
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => $tx['Account'],
                                'sort_key' => $sort_key++,
                                'effect' => '-0',
                                'failed' => 't',
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            $events[] = [
                                'transaction' => $tx['hash'],
                                'address' => 'the-void',
                                'sort_key' => $sort_key++,
                                'effect' => '0',
                                'failed' => 't',
                                'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                            ];
                            goto FEES;
                        } 
                        $fee_charged = false;
                        foreach ($tx['meta']['AffectedNodes'] ?? [] as $id => $affection) 
                        {
                            if (!isset($affection['ModifiedNode']) && !isset($affection['DeletedNode']) && !isset($affection['CreatedNode']))  
                            {
                                break;
                            }
                            $affected = $affection['ModifiedNode'] ?? ($affection['DeletedNode'] ?? $affection['CreatedNode']);
                            // AccountRoot is about changing xrp state of account
                            // For AMM Create also will have CreatedNode
                            if($affected['LedgerEntryType'] !== 'AccountRoot')
                            {
                                continue;
                            }
                            if( (!isset($affected['PreviousFields']['Balance']) || !isset($affected['FinalFields']['Balance'])) &&
                                 !isset($affected['NewFields']['Balance']))
                            {
                                continue;
                            }
                            if(isset($affected['NewFields']['Balance']))
                            {
                                $account = $affected['NewFields']['Account'];
                                $amount = '-' . $affected['NewFields']['Balance'];
                            } else {
                                $account = $affected['FinalFields']['Account'];
                                $amount = bcsub($affected['PreviousFields']['Balance'],
                                                $affected['FinalFields']['Balance']);
                            }
                            // negative -- money is credited to account
                            // positive --       is credited from account
                            if($tx['Account'] === $account && !$fee_charged)
                            {
                                if($amount[0] === '-')
                                {
                                    $amount = '-' . bcadd($this->bcabs($amount), $tx['Fee']);
                                } else {
                                    $amount = bcsub($amount, $tx['Fee']);   
                                }
                                $fee_charged = true; // we charge the fee only once
                            }
                            //  Question, do we need to show transfer from 'the-void' 
                            //  to account and vice versa if it's 0
                            if($amount === '0')
                            {
                                continue;
                            }
                            if($amount[0] === '-')
                            {
                                $events[] = [
                                    'transaction' => $tx['hash'],
                                    'address' => 'the-void',
                                    'sort_key' => $sort_key++,
                                    'effect' => $amount,
                                    'failed' => $tx_result,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                                $events[] = [
                                    'transaction' => $tx['hash'],
                                    'address' => $account,
                                    'sort_key' => $sort_key++,
                                    'effect' => substr($amount, 1),
                                    'failed' => $tx_result,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                            } else {
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
                                    'address' => 'the-void',
                                    'sort_key' => $sort_key++,
                                    'effect' => $amount,
                                    'failed' => $tx_result,
                                    'extra' => RippleSpecialTransactions::fromName($tx['TransactionType']),
                                ];
                            }
                            
                        }
                        goto FEES;
                        break;
                    }
                // Yes it's only fees here ;)
                // case 'AMMDelete': Hadn't found in our DB --> will be exception until we find it
                case 'EscrowCancel':
                case 'Payment':
                case 'OfferCancel':
                case 'AMMVote':
                case 'AccountSet':
                case 'CheckCancel':
                case 'CheckCreate':
                case 'DepositPreauth':
                case 'NFTokenBurn':
                case 'NFTokenCancelOffer':
                case 'NFTokenCreateOffer':
                case 'NFTokenMint':
                case 'SetRegularKey':
                case 'SignerListSet':
                case 'TicketCreate':
                case 'TrustSet': 
                case 'UNLModify':
                case 'EnableAmendment':
                case 'Clawback':
                case 'OracleSet':
                case 'OracleDelete':
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
    }

    // Getting balances from the node
    final public function api_get_balance(string $address): string
    {
        $request = requester_single(
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
        );

        if (!isset($request['account_data']))
            return '0';
        else
            return (string)$request['account_data']['Balance'];
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
                // here we should leave fee events and do nothing with them
                if(count($events_by_tx) == 2)
                {
                    $new_events[] = $events_by_tx[0];
                    $new_events[] = $events_by_tx[1];
                    unset($events_by_tx);
                    continue;
                }
                $events_by_tx_amount = count($events_by_tx) - 2;
                $ev1_fee = $events_by_tx[$events_by_tx_amount];
                $ev2_fee = $events_by_tx[$events_by_tx_amount + 1];
                // $events_by_tx = array_diff_key($events_by_tx, [$events_by_tx_amount => 0, $events_by_tx_amount + 1 => 0]);
                array_splice($events_by_tx, $events_by_tx_amount + 1, 1);
                array_splice($events_by_tx, $events_by_tx_amount, 1);
                // events can be:
                // - (-)void - (+)account1 - (-)account2 - (+)void -> (-)account2 - (+)account1
                // - (-)account2 - (+)void - (-)void - (+)account1 -> (-)account2 - (+)account1
                // unfortunately this it's not fully correct, because in chain can be inserted other events with other balances
                // - (-)void - (+)account1 effect1 - (-)account3 - (+)void effect2 - (-)account2 - (+)void effect1 -> (-)account2 - (+)account1
                // in addition it can be some situations 
                // - (-)void - (+)account1 effect1 - (-)account3 - (+)void effect2 - (-)account2 - (+)void effect1 -> (-)account2 - (+)account1
                // where effect1 == effect2, but real event will be (-)account2 - (+)account3
                // I suppose that it should be very rare situation, but can be
                // Now it will be like that: 
                //                              1. Take first pair of events
                //                              2. Search for the first same pair (same balance and opposite void)
                //                              3. Delete this and do (1)
                for($y = 0; $y < count($events_by_tx);)
                {
                    $ev_pair1 = $events_by_tx[$y];
                    $ev_pair2 = $events_by_tx[$y + 1];
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
                            if ($ev_pair1['address'] === 'the-void' &&
                                $events_by_tx[$z + 1]['address'] === 'the-void') 
                            {
                                $new_events[] = $events_by_tx[$z];
                                $new_events[] = $ev_pair2;
                                $found = true;
                                array_splice($events_by_tx, $z + 1, 1);
                                array_splice($events_by_tx, $z, 1);
                                break;
                            }
                            if ($ev_pair2['address'] === 'the-void' &&
                                $events_by_tx[$z]['address'] === 'the-void') 
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
                $new_events[] = $ev1_fee;
                $new_events[] = $ev2_fee;
                unset($events_by_tx);
                continue;
            }
            $events_by_tx[] = $events[$i];
        }
        return $new_events;
    }
}
