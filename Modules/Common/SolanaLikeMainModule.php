<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes Solana transfers. Note that it's very minimal as it only processes basic transfers between accounts.  */

abstract class SolanaLikeMainModule extends CoreModule
{
    use SolanaLikeTraits;
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::AlphaNumeric;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF; // As this module is minimal, we don't process fees
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed','extra'];
    public ?array $events_table_nullable_fields = ['extra'];
    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?bool $ignore_sum_of_all_effects = true; // As we don't process everything, there can be gaps...

    public string $block_entity_name = 'slot';
    public ?array $extra_data_details = [
        'f',
        'b'
    ];

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
        try
        {
            $block = requester_single($this->select_node(),
                params: ['method'  => 'getBlock',
                         'params'  => [$block_id,
                                       ['transactionDetails'             => 'full',
                                        'rewards'                        => true,
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
        $total_validator_fee = '0';
        $validator_parsed_fee = '0';
        $validator = '';
        foreach ($block['rewards'] as $reward)
        {
            if ($reward['rewardType'] != 'Fee')
                throw new DeveloperError("unprocessed validator reward in block {$block_id}");
            else
            {
                $total_validator_fee = (string)$reward['lamports'];
                $validator = $reward['pubkey'];
            }
        }
        foreach ($block['transactions'] as $transaction)
        {
            $failed = !is_null($transaction['meta']['err']);

            // These writable signer accounts are serialized first in the list of accounts and
            // the first of these is always used as the "fee payer".
            // https://solana.com/docs/core/fees#fee-collection
            $fee_payer = $transaction['transaction']['message']['accountKeys'][0]['pubkey'];

            $transaction['meta']['postBalances']['0'] += $transaction['meta']['fee'];
            if (array_diff($transaction['meta']['preBalances'], $transaction['meta']['postBalances']))
            {
                foreach ($transaction['transaction']['message']['accountKeys'] as $akey => $aval)
                {
                    $delta = $transaction['meta']['postBalances'][$akey] - $transaction['meta']['preBalances'][$akey];
                    if ($akey === 0 && !$aval['signer'])
                        throw new ModuleError('First account is not the signer');

                    if ($delta)
                    {
                        $events[] = [
                            'transaction' => $transaction['transaction']['signatures']['0'],
                            'address' => $aval['pubkey'],
                            'sort_key' => $sort_key++,
                            'effect' => (string)$delta,
                            'failed' => $failed,
                            'extra' => null,
                        ];
                    }
                }
            }

            if ($fee_payer !== '')
            {
                $transaction['meta']['fee'] = (string)$transaction['meta']['fee'];
                $validator_parsed_fee = bcadd($validator_parsed_fee,$transaction['meta']['fee']);
                $events[] = [
                    'transaction' => $transaction['transaction']['signatures']['0'],
                    'address' => $fee_payer,
                    'sort_key' => $sort_key++,
                    'effect' => "-" . $transaction['meta']['fee'],
                    'failed' => $failed,
                    'extra' => null,

                ];
                $events[] = [
                    'transaction' => $transaction['transaction']['signatures']['0'],
                    'address' => 'the-void',
                    'sort_key' => $sort_key++,
                    'effect' => bcfloor(bcdiv($transaction['meta']['fee'], '2',2)),
                    'failed' => $failed,
                    'extra' => 'b',
                ];
                $events[] = [
                    'transaction' => $transaction['transaction']['signatures']['0'],
                    'address' => $validator,
                    'sort_key' => $sort_key++,
                    'effect' => bcceil(bcdiv($transaction['meta']['fee'], '2',2)),
                    'failed' => $failed,
                    'extra' => 'f',
                ];

            }
        }
        $validator_parsed_fee = bcceil(bcdiv($validator_parsed_fee,'2',2));
        if ($validator_parsed_fee != $total_validator_fee)
            throw new DeveloperError("Miscalculation in transaction fees occured in block: {$block_id}: validator fee: {$total_validator_fee}, actual non-burnt transactions fee: {$validator_parsed_fee}");
        $this->block_time = date('Y-m-d H:i:s', (int)$block['blockTime']);
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    final function api_get_balance($address): string
    {
        return requester_single($this->select_node(),
            params: ['method' => 'getBalance',
                     'params' => [$address],
                     'id' => 0,
                     'jsonrpc' => '2.0'],
            result_in: 'result', timeout: $this->timeout)['value'];
    }
}
