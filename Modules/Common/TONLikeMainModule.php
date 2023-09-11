<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main TON transfers. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

abstract class TONLikeMainModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    // Blockchain-specific

    public ?array $shards = [];
    public ?string $workchain = null; // This should be set in the final module

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->workchain)) throw new DeveloperError("`workchain` is not set");
    }

    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(), endpoint: 'lastNum', timeout: $this->timeout);

        return (int)$result[($this->workchain)][(array_key_first($result[($this->workchain)]))]['seqno'];
        // This may be not the best solution in case there are several shard with different heights
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "getHashByHeight?workchain={$this->workchain}&seqno={$block_id}", timeout: $this->timeout);

            if ($break_on_first)
                break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $hashes = requester_multi_process($curl_results[0]);
        ksort($hashes, SORT_STRING);

        $shard_list = $final_filehash = [];

        foreach ($hashes as $shard => $shard_hashes)
        {
            $shard_list[] = $shard;
            $final_filehash[] = $shard_hashes['filehash'];

            $this->shards[$shard] = ['filehash' => $shard_hashes['filehash'], 'roothash' => $shard_hashes['roothash']];
        }

        $this->block_hash = strtolower(implode($final_filehash));

        $this->block_extra = strtolower(implode('/', $shard_list));

        if (count($curl_results) > 0)
        {
            foreach ($curl_results as $result)
            {
                $this_hashes = requester_multi_process($result);
                ksort($this_hashes, SORT_STRING);

                $this_final_filehash = [];

                foreach ($this_hashes as $shard => $shard_hashes)
                {
                    $this_final_filehash[] = $shard_hashes['filehash'];
                }

                if (strtolower(implode($this_final_filehash)) !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        {
            $this->block_time = date('Y-m-d H:i:s', 0);
            $this->set_return_events([]);
            return;
        }

        $block_times = [];

        $events = [];
        $sort_key = 0;

        foreach ($this->shards as $shard => $shard_data)
        {
            $this_root_hash = strtoupper($shard_data['roothash']);
            $this_block_hash = strtoupper($shard_data['filehash']);

            $block = requester_single($this->select_node(),
                endpoint: "blockLite?workchain={$this->workchain}&shard={$shard}&seqno={$block_id}&roothash={$this_root_hash}&filehash={$this_block_hash}",
                timeout: $this->timeout,
                flags: [RequesterOption::RecheckUTF8]); // TODO: requester_multi for shards

            $block_times[] = (int)$block['header']['time'];

            foreach ($block['transactions'] as $transaction)
            {
                $transaction['hash'] = strtolower($transaction['hash']);

                if (!isset($transaction['messageIn']))
                {
                    if (isset($transaction['fee']))
                        throw new ModuleError("There's fee, but no messageIn");

                    $events[] = [
                        'transaction' => $transaction['hash'],
                        'address' => $transaction['addr'],
                        'sort_key' => $sort_key++,
                        'effect' => '-0',
                        'extra' => 'n',
                    ];

                    $events[] = [
                        'transaction' => $transaction['hash'],
                        'address' => 'the-void',
                        'sort_key' => $sort_key++,
                        'effect' => '0',
                        'extra' => 'n',
                    ];
                }
                else
                {
                    if (count($transaction['messageIn']) > 1)
                        throw new ModuleError('count(messageIn) > 1');

                    $this_message_in = $transaction['messageIn']['0'];

                    // Transaction fee

                    $events[] = [
                        'transaction' => $transaction['hash'],
                        'address' => $this_message_in['source'] ?? $transaction['address'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . $transaction['fee'],
                        'extra' => 'f',
                    ];

                    $events[] = [
                        'transaction' => $transaction['hash'],
                        'address' => 'the-void',
                        'sort_key' => $sort_key++,
                        'effect' => $transaction['fee'],
                        'extra' => 'f',
                    ];

                    // The transfer itself

                    if (isset($this_message_in['value']))
                    {
                        $events[] = [
                            'transaction' => $transaction['hash'],
                            'address' => $this_message_in['source'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this_message_in['value'],
                            'extra' => null,
                        ];

                        $events[] = [
                            'transaction' => $transaction['hash'],
                            'address' => $this_message_in['destination'],
                            'sort_key' => $sort_key++,
                            'effect' => $this_message_in['value'],
                            'extra' => null,
                        ];
                    }
                }
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
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        return (string)requester_single($this->select_node(),
            endpoint: "account?account={$address}",
            timeout: $this->timeout)['balance'];
    }
}
