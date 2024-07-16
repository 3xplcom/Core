<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/**
 * This module processes Solana SPL Token transfers.
 * https://spl.solana.com/token
 */

abstract class SolanaLikeTokenModule extends CoreModule
{
    use SolanaTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::AlphaNumeric;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'currency', 'effect', 'failed'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?bool $ignore_sum_of_all_effects = true;

    public ?array $tokens_list = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        try
        {
            $block = requester_single($this->select_node(),
                params: ['method'  => 'getBlock',
                         'params'  => [$block_id,
                                       ['transactionDetails'             => 'accounts',
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
                flags: [RequesterOption::IgnoreAddingQuotesToNumbers]
            );
        }
        catch (RequesterException $e)
        {
            if (str_contains($e->getMessage(), 'was skipped, or missing due to ledger jump to recent snapshot')) // Empty slot
            {
                $this->block_time = date('Y-m-d H:i:s', 0);
                $this->set_return_events([]);
                $this->set_return_currencies([]);
                return;
            }
            else
            {
                throw $e;
            }
        }

        $this->block_hash = $block['blockhash'];
        $events = [];
        $currencies = [];
        $currencies_to_process = [];
        $sort_key = 0;

        foreach ($block['transactions'] as $tx)
        {
            if (count($tx['meta']['preTokenBalances']) == 0 && count($tx['meta']['postTokenBalances']) == 0)
                continue;

            $failed = false;
            if (isset($tx['meta']['err']))
                $failed = true;

            $pre_token_balances = $post_token_balances = [];

            foreach ($tx['meta']['preTokenBalances'] as $pre)
            {
                $pre_token_balances["{$pre["owner"]}_{$pre["mint"]}"] = [
                    "mint" => $pre["mint"],
                    "amount" => $pre["uiTokenAmount"]["amount"],
                    "decimals" => $pre["uiTokenAmount"]["decimals"],

                ];
            }

            foreach ($tx['meta']['postTokenBalances'] as $post)
            {
                $post_token_balances["{$post["owner"]}_{$post["mint"]}"] = [
                    "mint" => $post["mint"],
                    "amount" => $post["uiTokenAmount"]["amount"],
                    "decimals" => $post["uiTokenAmount"]["decimals"],
                ];
            }

            $keys = array_unique(array_merge(array_keys($pre_token_balances),array_keys($post_token_balances)));

            foreach ($keys as $key)
            {
                $address = explode("_",$key)[0];
                $pre = $pre_token_balances[$key]["amount"] ?? "0";
                $post = $post_token_balances[$key]["amount"] ?? "0";
                $currency = $pre_token_balances[$key]["mint"] ?? $post_token_balances[$key]["mint"];
                $decimals = $pre_token_balances[$key]["decimals"] ?? $post_token_balances[$key]["decimals"];
                $delta = bcsub($post, $pre);
                if ($delta != "0")
                {
                    if ($this->currency_type == CurrencyType::NFT && !in_array($delta,["1","-1"]))
                        continue;
                    $events[] = [
                        'transaction' => $tx['transaction']['signatures']['0'],
                        'address' => $address,
                        "currency" => $currency,
                        'sort_key' => $sort_key++,
                        'effect' => $delta,
                        'failed' => $failed,
                    ];
                    $currencies_to_process[$currency] = $decimals;
                }
            }
        }

        foreach (check_existing_currencies(array_keys($currencies_to_process), $this->currency_format) as $cur)
        {
            $currencies[] = [
                "id" => $cur,
                "decimals"=> $currencies_to_process[$cur]
                ];
        }

        if (count($currencies) > 0)
        {
            $currencies = $this->process_currencies($currencies);
            $currencies_filter = array_column($currencies, 'id');
            $events = $this->filter_events_by_currency($currencies_filter, $events);
        }

        // Post process events

        $this->block_time = date('Y-m-d H:i:s', (int)$block['blockTime']);
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    function api_get_balance(string $address, array $currencies): array
    {
        // Input currencies should be in format like this: solana-spl-token/So11111111111111111111111111111111111111112
        $balances = [];
        $data = [];

        for ($i = 0; $i < count($currencies); $i++)
        {
            $currency = explode('/', $currencies[$i])[1];
            $data[] = [
                'method' => 'getTokenAccountsByOwner',
                'params' => [$address, ['mint' => $currency], ['encoding' => 'jsonParsed']],
                'id' => $i,
                'jsonrpc' => '2.0',
            ];
        }

        $data_chunks = array_chunk($data, 100);

        foreach ($data_chunks as $datai)
        {
            $result = requester_single($this->select_node(), params: $datai);

            reorder_by_id($result);

            foreach ($result as $bit)
            {
                if (is_null($bit['value']))
                {
                    $balances[] = '0';
                    continue;
                }
                // Collect balances from all token accounts
                $balance = '0';
                foreach ($bit['value'] as $value)
                {
                    $balance = bcadd($balance, $value['account']['data']['parsed']['info']['tokenAmount']['amount'] ?? '0');
                }
                $balances[] = $balance;
            }
        }

        return $balances;
    }

    // Getting the token supply from the node
    function api_get_currency_supply(string $currency): string
    {
        try
        {
            $data = requester_single($this->select_node(),
                params: [
                    'method' => 'getAccountInfo',
                    'params' => [$currency, ['encoding' => 'jsonParsed']],
                    'id' => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout,
                flags: [RequesterOption::IgnoreAddingQuotesToNumbers]
            );
        }
        catch (RequesterException)
        {
            return '0';
        }

        return $data['value']['data']['parsed']['info']['supply'] ?? "0";
    }
}
