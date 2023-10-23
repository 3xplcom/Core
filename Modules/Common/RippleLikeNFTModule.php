<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module works with NFT in Ripple. Requires a Ripple node.  */

abstract class RippleLikeNFTModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['currency'];

    public ?array $currencies_table_fields = ['id', 'name', 'decimals', 'description'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [];

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

    final public function pre_process_block($block_id)
    {
        $currencies = [];
        $events = [];
        $sort_key = 0;

        $ledger = requester_single(
            $this->select_node(),
            params: [
                'method' => 'ledger',
                'params' => [[
                    'ledger_index' => "{$block_id}",
                    'accounts' => false,
                    'full' => false,
                    'transactions' => true,
                ]]
            ],
            result_in: 'result',
            timeout: $this->timeout
        )['ledger'];

        $ledgerTxs = $ledger['transactions'];
        $this->block_time = date('Y-m-d H:i:s', $ledger['close_time'] + 946684800);

        $tx_data = [];
        $tx_curl = [];

        foreach ($ledgerTxs as $transactionHash)
        {
            $tx_curl[] = requester_multi_prepare($this->select_node(), params: [ 'method' => 'tx',
            'params' => [[
                    'transaction' => "{$transactionHash}",
                    'binary' => false
            ]]], timeout: $this->timeout);
        }
        $tx_curl_multi = requester_multi(
            $tx_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404]
        );
        foreach ($tx_curl_multi as $v)
            $tx_data[] = requester_multi_process($v, result_in: 'result');

        foreach ($tx_data as $tx) 
        {
            switch ($tx['TransactionType']) 
            {
                case 'NFTokenAcceptOffer': 
                    {
                        $new_owner = null;
                        $prev_owner = null;
                        $nft = null;
                        $broker = isset($tx['NFTokenBrokerFee']);
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) {
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer') 
                                    {
                                        $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                        $new_owner = $tx['Account'];
                                        $nft = $affection['DeletedNode']['FinalFields']['NFTokenID'];
                                        break;
                                    }
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer' && $broker) 
                                    {
                                        if ($affection['DeletedNode']['FinalFields']['Flags'] == 0)     // it means that it's NFT buy offer https://xrpl.org/nftokencreateoffer.html#nftokencreateoffer-flags
                                        {
                                            $new_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                        }
                                        if ($affection['DeletedNode']['FinalFields']['Flags'] == 1)     // it means that it's NFT sell offer https://xrpl.org/nftokencreateoffer.html#nftokencreateoffer-flags
                                        {
                                            $prev_owner = $affection['DeletedNode']['FinalFields']['Owner'];
                                        }
                                        $nft = $affection['DeletedNode']['FinalFields']['NFTokenID'];
                                    }
                                }
                            }
                        }
                        if(is_null($prev_owner) && is_null($new_owner))
                        {   // this is for situation when the transaction fallen
                            // mostly in this situations we don't need to pay
                            // anything in Token module
                            $currency = null;
                            break;
                        }
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,           // what to add as a currency
                            'address'     => $prev_owner,
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'extra'       => $nft,   // index NFT
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,
                            'address'     => $new_owner,
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'extra'       => $nft,
                        ];
                        break;
                    }
                case 'NFTokenMint':
                    {
                        // what to do with issuer in docs?
                        // https://xrpl.org/nftokenmint.html#issuing-on-behalf-of-another-account
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,           // what to add as a currency
                            'address'     => 'the-void',
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'extra'       => $tx['meta']['nftoken_id'],   // index NFT
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,
                            'address'     => $tx['Account'],
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'extra'       => $tx['meta']['nftoken_id'],
                        ];
                        break;
                    }
                case 'NFTokenBurn':
                    {
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,           // what to add as a currency
                            'address'     => $tx['Account'],
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'extra'       => $tx['NFTokenID'],   // index NFT
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'currency'    => null,
                            'address'     => 'the-void',
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'extra'       => $tx['NFTokenID'],
                        ];
                        break;
                    }
            };
            
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

}
