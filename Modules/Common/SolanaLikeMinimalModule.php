<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes Solana transfers. Note that it's very minimal as it only processes basic transfers between accounts.  */

abstract class SolanaLikeMinimalModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::AlphaNumeric;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None; // As this module is minimal, we don't process fees
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?bool $ignore_sum_of_all_effects = true; // As we don't process everything, there can be gaps...

    public string $block_entity_name = 'slot';

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    public function inquire_latest_block()
    {
        return requester_single($this->select_node(),
            params: ['method' => 'getSlot', 'params' => [['commitment' => 'finalized']], 'id' => 0, 'jsonrpc' => '2.0'],
            result_in: 'result',
            timeout: $this->timeout,
            flags: [RequesterOption::IgnoreAddingQuotesToNumbers]); // IgnoreAddingQuotesToNumbers is needed as there may be non-standard
                                                                    // numbers in the output
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $this->block_hash = ''; // As we query finalized slots only, we don't expect anything to get "orphaned"
    }

    final public function pre_process_block($block_id)
    {
        try
        {
            $block = requester_single($this->select_node(),
                params: ['method'  => 'getBlock',
                         'params'  => [$block_id,
                                       ['transactionDetails'             => 'full',
                                        'rewards'                        => false,
                                        'encoding'                       => 'jsonParsed',
                                        'maxSupportedTransactionVersion' => 0,
                                       ],
                         ],
                         'id'      => 0,
                         'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout,
                flags: [RequesterOption::IgnoreAddingQuotesToNumbers]);
        }
        catch (RequesterException $e)
        {
            if (strstr($e->getMessage(), 'was skipped, or missing due to ledger jump to recent snapshot')) // Empty slot
            {
                $this->block_time = date('Y-m-d H:i:s', 0);
                $this->set_return_events([]);
                return;
            }
            else
            {
                throw $e;
            }
        }

        $this->block_hash = $block['blockhash'];

        $events = [];
        $sort_key = 0;

        foreach ($block['transactions'] as $transaction)
        {
            if ($transaction['meta']['err'])
                continue;

            if (count($transaction['transaction']['message']['instructions']) === 1 && $transaction['transaction']['message']['instructions'][0]['programId'] === 'Vote111111111111111111111111111111111111111')
                continue;

            $transaction['meta']['postBalances']['0'] += $transaction['meta']['fee'];

            if (array_diff($transaction['meta']['preBalances'], $transaction['meta']['postBalances']))
            {
                foreach ($transaction['transaction']['message']['accountKeys'] as $akey => $aval)
                {
                    $delta = $transaction['meta']['postBalances'][$akey] - $transaction['meta']['preBalances'][$akey];

                    if ($akey === '0' && !$aval['signer'])
                        throw new ModuleError('First account is not the signer');

                    if ($delta)
                    {
                        $events[] = [
                            'transaction' => $transaction['transaction']['signatures']['0'],
                            'address' => $aval['pubkey'],
                            'sort_key' => $sort_key++,
                            'effect' => (string)$delta,
                        ];
                    }
                }
            }
        }

        $this->block_time = date('Y-m-d H:i:s', (int)$block['blockTime']);

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    function api_get_balance($address)
    {
        if (!preg_match(StandardPatterns::AnySearchable->value, $address))
            return null;

        return requester_single($this->select_node(),
            params: ['method' => 'getBalance',
                     'params' => [$address],
                     'id' => 0,
                     'jsonrpc' => '2.0'],
            result_in: 'result', timeout: $this->timeout)['value'];
    }
}
