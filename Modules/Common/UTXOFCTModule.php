<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process CashTokens transfers. Requires a Bitcoin Cash Node to function (https://gitlab.com/bitcoin-cash-node/bitcoin-cash-node)
 *  It processes FT transfers. For NFT transfers see the UTXONFCT module. CashTokens documentation: https://github.com/bitjson/cashtokens
 *  Requesting token details requires a BCMR indexer: https://github.com/paytaca/bcmr-indexer  */

abstract class UTXOFCTModule extends CoreModule
{
    use UTXOTraits;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals', 'description'];
    public ?array $currencies_table_nullable_fields = ['name', 'symbol', 'description'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::UTXO;
    public ?CurrencyFormat $currency_format = CurrencyFormat::HexWithout0x;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void', 'script-*'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

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
        if ($block_id !== MEMPOOL)
        {
            $block = requester_single($this->select_node(), endpoint: "rest/block/{$this->block_hash}.json", timeout: $this->timeout);
            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);
        }
        else
        {
            $block = [];
            $block['tx'] = [];
            $multi_curl = [];

            $mempool = requester_single($this->select_node(), params: ['method' => 'getrawmempool', 'params' => [false]], result_in: 'result', timeout: $this->timeout);

            $islice = 0;

            foreach ($mempool as $tx_hash)
            {
                if (!isset($this->processed_transactions[$tx_hash]))
                {
                    $multi_curl[] = requester_multi_prepare($this->select_node(),
                        params: ['method' => 'getrawtransaction', 'params' => [$tx_hash, 1]],
                        timeout: $this->timeout);

                    $islice++;
                    if ($islice >= 100) break; // For debug purposes, we limit the number of mempool transactions to process
                }
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
            {
                $block['tx'][] = requester_multi_process($v, result_in: 'result');
            }
        }

        $events = $currencies_to_process = [];
        $sort_key = 0;

        foreach ($block['tx'] as $transaction)
        {
            $this_events = [];
            $total_sum = [];

            foreach ($transaction['vin'] as $vin)
            {
                if (isset($vin['prevout']['tokenData']))
                {
                    if (isset($vin['prevout']['scriptPubKey']['address']))
                        $address = explode(':', $vin['prevout']['scriptPubKey']['address'])[1]; // Removes the chain prefix (e.g. `bitcoincash:`)
                    else // We use special `script-...` address format for all outputs which don't have a standard representation
                        $address = 'script-' . substr(hash('sha256', $vin['prevout']['scriptPubKey']['hex']), 0, 32);

                    if ($vin['prevout']['tokenData']['amount'] === '0' && !isset($vin['prevout']['tokenData']['nft']))
                    {
                        throw new ModuleError('Nothing to process');
                    }

                    if ($vin['prevout']['tokenData']['amount'] !== '0') // FT
                    {
                        $this_events[] = [
                            'transaction' => $transaction['txid'],
                            'address' => $address,
                            'currency' => $vin['prevout']['tokenData']['category'],
                            'effect' => '-' . $vin['prevout']['tokenData']['amount'],
//                            'extra' => null,
                        ];

                        if ($block_id !== MEMPOOL)
                            $currencies_to_process[] = $vin['prevout']['tokenData']['category'];

                        if (isset($total_sum['ft'][($vin['prevout']['tokenData']['category'])]))
                        {
                            $total_sum['ft'][($vin['prevout']['tokenData']['category'])] =
                                bcadd($total_sum['ft'][($vin['prevout']['tokenData']['category'])], '-' . $vin['prevout']['tokenData']['amount']);
                        }
                        else
                        {
                            $total_sum['ft'][($vin['prevout']['tokenData']['category'])] = '-' . $vin['prevout']['tokenData']['amount'];
                        }
                    }

                    if (isset($vin['prevout']['tokenData']['nft'])) // NFT
                    {
                        // This is processed in another module...
                    }
                }
            }

            foreach ($transaction['vout'] as $vout)
            {
                if (isset($vout['tokenData']))
                {
                    if (isset($vout['scriptPubKey']['addresses']) && count($vout['scriptPubKey']['addresses']) === 1)
                        $address = explode(':', $vout['scriptPubKey']['addresses'][0])[1]; // Removes the chain prefix (e.g. `bitcoincash:`)
                    else // We use special `script-...` address format for all outputs which don't have a standard representation
                        $address = 'script-' . substr(hash('sha256', $vout['scriptPubKey']['hex']), 0, 32);

                    if ($vout['tokenData']['amount'] === '0' && !isset($vout['tokenData']['nft']))
                    {
                        throw new ModuleError('Nothing to process');
                    }

                    if ($vout['tokenData']['amount'] !== '0') // FT
                    {
                        $this_events[] = [
                            'transaction' => $transaction['txid'],
                            'address' => $address,
                            'currency' => $vout['tokenData']['category'],
                            'effect' => $vout['tokenData']['amount'],
//                            'extra' => null,
                        ];

                        if ($block_id !== MEMPOOL)
                            $currencies_to_process[] = $vout['tokenData']['category'];

                        if (isset($total_sum['ft'][($vout['tokenData']['category'])]))
                        {
                            $total_sum['ft'][($vout['tokenData']['category'])] =
                                bcadd($total_sum['ft'][($vout['tokenData']['category'])], $vout['tokenData']['amount']);
                        }
                        else
                        {
                            $total_sum['ft'][($vout['tokenData']['category'])] = $vout['tokenData']['amount'];
                        }
                    }

                    if (isset($vout['tokenData']['nft'])) // NFT
                    {
                        // This is processed in another module...
                    }
                }
            }

            $this_mints = [];
            $this_burns = [];

            foreach ($total_sum as $type => $total_sum_in_type)
            {
                foreach ($total_sum_in_type as $category => $difference)
                {
                    if ($difference === '0' || $difference === '-0')
                    {
                        //
                    }
                    elseif (str_contains($difference, '-')) // Burning tokens
                    {
                        $this_burns[] = [
                            'transaction' => $transaction['txid'],
                            'address'     => 'the-void',
                            'currency'    => $category,
                            'effect'      => bcmul($difference, '-1'),
//                            'extra'       => ($type === 'ft') ? null : '-1',
                        ];
                    }
                    else // Minting new tokens
                    {
                        $this_mints[] = [
                            'transaction' => $transaction['txid'],
                            'address'     => 'the-void',
                            'currency'    => $category,
                            'effect'      => '-' . $difference,
//                            'extra'       => ($type === 'ft') ? null : '-1',
                        ];
                    }
                }
            }

            foreach ($this_mints as &$mint)
            {
                $mint['sort_key'] = $sort_key++;
                $events[] = $mint;
            }

            foreach ($this_events as &$event)
            {
                $event['sort_key'] = $sort_key++;
                $events[] = $event;
            }

            foreach ($this_burns as &$burn)
            {
                $burn['sort_key'] = $sort_key++;
                $events[] = $burn;
            }
        }

        $latest_tx_hash = ''; // This is for mempool
        $sort_key = 0; // This is too

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = date('Y-m-d H:i:s', (($this->block_id !== MEMPOOL) ? (int)$block['time'] : time()));

            if ($this->block_id === MEMPOOL && $event['transaction'] !== $latest_tx_hash)
            {
                $latest_tx_hash = $event['transaction'];
                $sort_key = 0;
                $event['sort_key'] = $sort_key++;
            }
            elseif ($this->block_id === MEMPOOL)
            {
                $event['sort_key'] = $sort_key++;
            }
        }

        $this->set_return_events($events);

        // Process currencies

        if ($currencies_to_process)
        {
            $currencies = [];

            $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
            $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

            $bcmr_nodes = envm($this->module, 'BCMR_NODES');
            $bcmr_node = $bcmr_nodes[array_rand($bcmr_nodes)];

            // First, we need to check if the BCMR node knows about the latest block

            $latest_bcmr_block = (int)requester_single($bcmr_node,
                endpoint: 'status/latest-block/',
                result_in: 'height',
                flags: [RequesterOption::TrimJSON]);

            if ($latest_bcmr_block < $block_id)
                throw new ModuleException("BCMR node is not ready: reports height {$latest_bcmr_block} while processing block {$block_id}");

            // Then we proceed with fetching currencies

            $multi_curl = [];

            foreach ($currencies_to_process as $currency_id)
            {
                $multi_curl[] = requester_multi_prepare($bcmr_node,
                    endpoint: "tokens/{$currency_id}/",
                    timeout: $this->timeout);
            }

            $multi_curl_results = requester_multi($multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout);

            foreach ($multi_curl_results as $result)
            {
                $result = requester_multi_process($result, ignore_errors: true, flags: [RequesterOption::TrimJSON]);

                if (isset($result['error']))
                {
                    if ($result['error'] === 'no valid metadata found')
                    {
                        //
                    }
                    elseif ($result['error'] === 'category not found')
                    {
                        throw new ModuleError("There's a category in the block, but no details from the BCMR node");
                    }
                    else
                    {
                        throw new ModuleError("Unknown error from the BCMR node: {$result['error']}");
                    }
                }
                else
                {
                    if (!isset($result['token']['decimals']))
                        $this_decimals = 0;
                    else
                        $this_decimals = (int)$result['token']['decimals'];

                    $currencies[] = [
                        'id' => $result['token']['category'],
                        'name' => $result['name'] ?? null,
                        'symbol' => $result['token']['symbol'] ?? null,
                        'decimals' => ($this_decimals <= 32767 ) ? $this_decimals : 0, // We use SMALLINT for decimals...
                        'description' => $result['description'] ?? null,
                    ];
                }
            }

            $this->set_return_currencies($currencies);
        }
        elseif ($this->block_id !== MEMPOOL)
        {
            $this->set_return_currencies([]);
        }
    }
}
