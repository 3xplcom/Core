<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TEP-62 standard, see
 *  https://github.com/ton-blockchain/TEPs/blob/master/text/0062-nft-standard.md */

abstract class TONLikeNFJettonModule extends CoreModule
{
    use TONTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-abyss', 'the-undefcurr'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra', 'extra_indexed', 'failed'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false; // Technically, this is possible
    public ?bool $forking_implemented = true;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?array $shards = [];
    public ?string $workchain = null; // This should be set in the final module

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
        if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        {
            $this->block_time = date('Y-m-d H:i:s', 0);
            $this->set_return_events([]);
            $this->set_return_currencies([]);
            return;
        }

        $events = [];
        $currencies_to_process = [];
        $sort_key = 0;

        $rq_blocks = [];
        $rq_blocks_data = [];
        $block_times = [];

        foreach ($this->shards as $shard => $shard_data) 
        {
            $this_root_hash = strtoupper($shard_data['roothash']);
            $this_block_hash = strtoupper($shard_data['filehash']);

            $rq_blocks[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: "blockLite?workchain={$this->workchain}&shard={$shard}&seqno={$block_id}&roothash={$this_root_hash}&filehash={$this_block_hash}",
                timeout: $this->timeout
            );
        }
            
        $rq_blocks_multi = requester_multi(
            $rq_blocks,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200]
        );

        foreach ($rq_blocks_multi as $v)
            $rq_blocks_data[] = requester_multi_process($v, flags: [RequesterOption::RecheckUTF8]);

        foreach ($rq_blocks_data as $block)
        {
            $block_times[] = (int)$block['header']['time'];

            foreach ($block['transactions'] as $transaction)
            {
                $transaction['hash'] = strtolower($transaction['hash']);

                if (isset($transaction['messageIn']))
                {
                    $messageIn = $transaction['messageIn'][0]; // by default in TON there is only 1 message IN

                    if (isset($messageIn['transfer'])) 
                    {
                        if (in_array($transaction['messageIn'][0]['transfer']['transfer_type'], ["transfer", "deploy_nft"])
                            && $transaction['messageIn'][0]['transfer']['type'] === 'nft')
                        {
                            $token_info = $this->api_get_nft_info($transaction['messageIn'][0]['transfer']['token']);

                            $events[] = [
                                'transaction' => $transaction['hash'],
                                'currency'    => ($token_info['collection_address'] !== '') ? $token_info['collection_address'] : 'the-undefcurr',
                                'address'     => ($transaction['messageIn'][0]['transfer']['from'] !== '') ? $transaction['messageIn'][0]['transfer']['from'] : 'the-abyss',
                                'sort_key'    => $sort_key++,
                                'effect'      => '-1',
                                'extra'       => $token_info['index'],
                                'extra_indexed' => $transaction['messageIn'][0]['transfer']['token'],
                                'failed'      => $transaction['messageIn'][0]['transfer']['failed'],
                            ];

                            $events[] = [
                                'transaction' => $transaction['hash'],
                                'currency'    => ($token_info['collection_address'] !== '') ? $token_info['collection_address'] : 'the-undefcurr',
                                'address'     => ($transaction['messageIn'][0]['transfer']['to'] !== '') ? $transaction['messageIn'][0]['transfer']['to'] : 'the-abyss',
                                'sort_key'    => $sort_key++,
                                'effect'      => '1',
                                'extra'       => $token_info['index'],
                                'extra_indexed' => $transaction['messageIn'][0]['transfer']['token'],
                                'failed'      => $transaction['messageIn'][0]['transfer']['failed'],
                            ];

                            if ($token_info['collection_address'] !== '')
                                $currencies_to_process[] = $token_info['collection_address'];
                        }
                    }
                }
            }
        }

        // Process currencies

        $currencies = [];

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        if ($currencies_to_process)
        {
            $multi_curl = [];
            $currency_data = [];

            foreach ($currencies_to_process as $currency_id)
            {
                if ($currency_id === 'the-undefcurr') // here we suppose that it will be only 1 undef_curr and no more
                {
                    $currencies[] = [
                        'id'       => 'the-undefcurr',
                        'name'     => '',
                        'symbol'   => '',
                    ];
                    continue;
                }
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    endpoint: "account?account={$currency_id}",
                    timeout: $this->timeout);
            }

            $curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            foreach ($curl_results as $v)
                $currency_data[] = requester_multi_process($v, ignore_errors: true);

            foreach ($currency_data as $account_data)
            {
                $metadata = [];
                if (isset($account_data["contract_state"]["contract_data"]["collection_content"]["metadata"]))
                {
                    $metadata = $account_data["contract_state"]["contract_data"]["collection_content"]["metadata"];
                } 
                $currencies[] = [
                    'id'       => $account_data['account'],
                    'name'     => isset($metadata['name']) ?  mb_convert_encoding($metadata['name'], 'UTF-8', 'UTF-8') : '',
                    'symbol'   => isset($metadata['symbol']) ? mb_convert_encoding($metadata['symbol'], 'UTF-8', 'UTF-8') : '',
                ];
            }
        }

        ////////////////
        // Processing //
        ////////////////

        $max_block_time = date('Y-m-d H:i:s', max($block_times));

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $max_block_time;
        }

        $this->block_time = $max_block_time;

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // get collection address and index of nft
    private function api_get_nft_info($nft)
    {
        try {
            $nft_info = requester_single(
                $this->select_node(),
                endpoint: "account?account={$nft}",
                timeout: $this->timeout,
                flags: [RequesterOption::RecheckUTF8]
            );
        } catch (RequesterException)
        {
            $nft_info = null;
        }

        if (isset($nft_info['contract_state']['contract_data']))
            return [
                'collection_address' => $nft_info['contract_state']['contract_data']['collection_address'],
                'index' => $nft_info['contract_state']['contract_data']['index'],
            ];
        else
            return [
                'collection_address' => 'the-undefcurr',
                'index' => null,
            ];
    }

    // Getting amount of NFTs from the node by collection
    function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        $real_currencies = [];
        $collection_array = "[";

        // Input currencies should be in format like this: `ton-nft/EQDpQ2E8wCsG6OVq_5B3VmCkdD8gRrj124vh-5rh3aKUfDST`
        foreach ($currencies as $c)
        {
            $currency = explode('/', $c)[1];
            $real_currencies[] = $currency;
            $collection_array .= ($currency . ",");
        }

        $collection_array .= "]";

        $return = [];

        $account_info = requester_single(
            $this->select_node(),
            endpoint: "account?account={$address}&nfts_count={$collection_array}",
            timeout: $this->timeout
        )['nfts_count'];

        $account_currencies_info = array_column($account_info, 'balance', 'token');

        foreach ($real_currencies as $c)
        {
            if (isset($account_currencies_info[$c]))
                $return[] = $account_currencies_info[$c];
            else
                $return[] = null;
        }

        return $return;
    }
}
