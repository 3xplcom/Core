<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module process CashTokens transfers. Requires a Bitcoin Cash Node to function (https://gitlab.com/bitcoin-cash-node/bitcoin-cash-node)
 *  It processes NFT transfers. `extra_data` contains the id of the relevant token. For FT transfers see the UTXOFCT module.
 *  Note that this is WIP as there's no good mechanism for retrieving token data yet (see the TODO below).
 *
 *  CashTokens documentation: https://github.com/bitjson/cashtokens  */

abstract class UTXONFCTModule extends CoreModule
{
    use UTXOTraits;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];
    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals', 'description'];
    public ?array $currencies_table_nullable_fields = [];

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
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?bool $hidden_values_only = false;

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

        $events = [];
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
                        // This is processed in another module...
                    }

                    if (isset($vin['prevout']['tokenData']['nft'])) // NFT
                    {
                        $this_events[] = [
                            'transaction' => $transaction['txid'],
                            'address' => $address,
                            'currency' => $vin['prevout']['tokenData']['category'],
                            'effect' => '-1',
                            'extra' => to_int256_from_hex(
                                ($vin['prevout']['tokenData']['nft']['commitment'] === '') ?
                                    '-1' : $vin['prevout']['tokenData']['nft']['commitment']),
                        ];

                        if (isset($total_sum['nft'][($vin['prevout']['tokenData']['category'])]))
                        {
                            $total_sum['nft'][($vin['prevout']['tokenData']['category'])] =
                                bcadd($total_sum['nft'][($vin['prevout']['tokenData']['category'])], '-1');
                        }
                        else
                        {
                            $total_sum['nft'][($vin['prevout']['tokenData']['category'])] = '-1';
                        }
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
                        // This is processed in another module...
                    }

                    if (isset($vout['tokenData']['nft'])) // NFT
                    {
                        $this_events[] = [
                            'transaction' => $transaction['txid'],
                            'address' => $address,
                            'currency' => $vout['tokenData']['category'],
                            'effect' => '1',
                            'extra' => to_int256_from_hex(
                                ($vout['tokenData']['nft']['commitment'] === '') ?
                                    '-1' : $vout['tokenData']['nft']['commitment']),
                        ];

                        if (isset($total_sum['nft'][($vout['tokenData']['category'])]))
                        {
                            $total_sum['nft'][($vout['tokenData']['category'])] =
                                bcadd($total_sum['nft'][($vout['tokenData']['category'])], '1');
                        }
                        else
                        {
                            $total_sum['nft'][($vout['tokenData']['category'])] = '1';
                        }
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
                            'extra'       => ($type === 'ft') ? null : '-1',
                        ];
                    }
                    else // Minting new tokens
                    {
                        $this_mints[] = [
                            'transaction' => $transaction['txid'],
                            'address'     => 'the-void',
                            'currency'    => $category,
                            'effect'      => '-' . $difference,
                            'extra'       => ($type === 'ft') ? null : '-1',
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

        if ($block_id !== MEMPOOL)
            $this->set_return_currencies([]); // TODO: this is WIP! Once the Metadata Registry (BCMR) is ready, we'll revamp this
    }
}
