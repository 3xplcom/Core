<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with NFT in Ripple. Requires a Ripple node.  */

abstract class RippleLikeNFTModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static; // XRPL doesn't have contracts for NFTs
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed' ,'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

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

    final public function pre_process_block($block_id)
    {
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
            if (!isset($tx['meta']))
                throw new ModuleException("Transactions haven't been fully processed by the node yet");

            $tx_result = !($tx['meta']['TransactionResult'] === 'tesSUCCESS');

            switch ($tx['TransactionType']) 
            {
                case 'NFTokenAcceptOffer': 
                    {
                        $new_owner = null;
                        $prev_owner = null;
                        $nft = null;
                        $broker_op = isset($tx['NFTokenBrokerFee']); // if set this -> we must have 2 offers
                        if (isset($tx['meta']['AffectedNodes'])) 
                        {
                            $affected_nodes = $tx['meta']['AffectedNodes'];
                            foreach ($affected_nodes as $id => $affection) 
                            {
                                if (isset($affection['DeletedNode'])) 
                                {
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer' && !$broker_op) 
                                    {
                                        $isSell = $affection['DeletedNode']['FinalFields']['Flags'];
                                        $prev_owner = $isSell ? $affection['DeletedNode']['FinalFields']['Owner'] : $tx['Account']; // seller
                                        $new_owner = $isSell ? $tx['Account'] : $affection['DeletedNode']['FinalFields']['Owner']; // buyer
                                        $nft = $affection['DeletedNode']['FinalFields']['NFTokenID'];
                                        break;
                                    }
                                    if ($affection['DeletedNode']['LedgerEntryType'] === 'NFTokenOffer' && $broker_op) 
                                    {
                                        switch ($affection['DeletedNode']['FinalFields']['Flags'])
                                        {
                                            case 0: // flag = 0 -- buying
                                                $new_owner = $affection['DeletedNode']['FinalFields']['Owner']; // buyer
                                                break;
                                            case 1: // flag = 1 -- selling
                                                $prev_owner = $affection['DeletedNode']['FinalFields']['Owner']; // seller
                                                break;
                                        }
                                        $nft = $affection['DeletedNode']['FinalFields']['NFTokenID']; 
                                    }
                                }
                            }
                        }
                        if(is_null($prev_owner) && is_null($new_owner))
                        {   // this is for a fallen transaction
                            // in this situations we don't need to pay
                            break;
                        }
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => $prev_owner,
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'failed'      => $tx_result,
                            'extra'       => $nft,
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => $new_owner,
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'failed'      => $tx_result,
                            'extra'       => $nft,
                        ];
                        break;
                    }
                case 'NFTokenMint':
                    {
                        $nft = null;
                        if (isset($tx['meta']['nftoken_id']))
                            $nft = $tx['meta']['nftoken_id'];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => 'the-void',
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'failed'      => $tx_result,
                            'extra'       => $nft,
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => $tx['Account'],
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'failed'      => $tx_result,
                            'extra'       => $nft,
                        ];
                        break;
                    }
                case 'NFTokenBurn':
                    {
                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => $tx['Owner'] ?? $tx['Account'],
                            'sort_key'    => $sort_key++,
                            'effect'      => '-1',
                            'failed'      => $tx_result,
                            'extra'       => $tx['NFTokenID'],
                        ];

                        $events[] = [
                            'transaction' => $tx['hash'],
                            'address'     => 'the-void',
                            'sort_key'    => $sort_key++,
                            'effect'      => '1',
                            'failed'      => $tx_result,
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
            $event['transaction'] = strtolower($event['transaction']);
        }

        $this->set_return_events($events);
    }

    // This just counts the total number of NFTs an address has
    final function api_get_balance(string $address): string
    {
        $nft_amount = '0';
        $marker = null;

        do
        {
            if($marker === null)
            {
                $params = [
                    'method' => 'account_nfts',
                    'params' => [[
                        'account' => "{$address}",
                        'ledger_index' => 'closed',
                        'strict' => true,
                        'limit' => 100,
                    ]]
                    ];
            }
            else
            {
                $params = [
                    'method' => 'account_nfts',
                    'params' => [[
                        'account' => "{$address}",
                        'ledger_index' => 'closed',
                        'strict' => true,
                        'limit' => 100,
                        'marker' => $marker,
                    ]]
                    ];
            }

            $account_nfts = requester_single(
                $this->select_node(),
                params: $params,
                result_in: 'result',
                timeout: $this->timeout
            );

            $nft_amount = bcadd($nft_amount, (string)count($account_nfts['account_nfts']));
            $marker = $account_nfts['marker'] ?? null;
        }
        while ($marker);

        return $nft_amount;
    }
}
