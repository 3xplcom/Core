<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is a parser for DIP2 transactions in Dash-like coins.  */

abstract class UTXODIP2Module extends CoreModule
{
    use UTXOTraits;

    //

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['*-tx'];

    public ?PrivacyModel $privacy_model = PrivacyModel::Shielded; // There's nothing really being transferred

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        if ($block_id !== MEMPOOL)
        {
            $block_hash = $this->block_hash;

            $block = requester_single($this->select_node(), endpoint: "rest/block/{$block_hash}.json", timeout: $this->timeout);

            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);
        }
        else // Processing mempool
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

        $events = []; // This is an array for the results
        $sort_key = 0;

        foreach ($block['tx'] as $transaction)
        {
            $found = 0;

            if (isset($transaction['proRegTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'pro-reg-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['proUpServTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'pro-up-serv-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['proUpRegTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'pro-up-reg-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['proUpRevTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'pro-up-rev-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['cbTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'cb-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['qcTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'qc-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if (isset($transaction['mnHfTx']))
            {
                $found++;

                $events[] = ['transaction' => $transaction['txid'],
                             'address'     => 'mn-hf-tx',
                             'effect'      => "+?",
                             'sort_key'    => $sort_key++,
                ];
            }

            if ($found > 1)
                throw new ModuleError('Suspicious transaction: more than 1 special transfer');

            if (isset($transaction['extraPayloadSize']) && !$found)
                throw new ModuleError("There's extra payload, but no special transfers. Is there a new unknown type?");

            if (!$transaction['vin'] && !$transaction['vout'] && !$transaction['extraPayloadSize'])
                throw new ModuleError('Empty transaction');
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = date('Y-m-d H:i:s', (($this->block_id !== MEMPOOL) ? (int)$block['time'] : time()));
        }

        $this->set_return_events($events);
    }
}
