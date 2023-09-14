<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process the Aptos Token (NFT) transfers in Aptos Blockchain. */

abstract class AptosTokenLikeModule extends CoreModule
{
    use AptosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-offered'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;
    public ?bool $ignore_sum_of_all_effects = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?array $currencies_table_fields = ['id', 'name'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        $block = requester_single($this->select_node(), endpoint: "v1/blocks/by_height/{$block_id}?with_transactions=true", timeout: $this->timeout);
        $this->block_time = date('Y-m-d H:i:s', (int) ((int) $block['block_timestamp'] / 1000000));

        $events = [];
        $currencies = [];
        $sort_key = 0;
        $currencies_to_process = [];

        foreach ($block['transactions'] as $trx)
        {
            if ($trx['type'] !== 'user_transaction')
            {
                continue;
            }

            $failed = false;
            if ($trx['vm_status'] !== 'Executed successfully')
            {
                $failed = true;
            }

            $currencies_in_trx = [];
            foreach ($trx['events'] as $trx_event)
            {
                if (!str_starts_with($trx_event['type'], '0x3::token'))
                {
                    continue;
                }

                $address = $trx_event['guid']['account_address'];
                $creator = $trx_event['data']['id']['creator'] ?? $trx_event['data']['id']['token_data_id']['creator'] ?? null;
                $name = $trx_event['data']['id']['name'] ?? $trx_event['data']['id']['token_data_id']['name'] ?? null;
                $collection = $trx_event['data']['id']['collection'] ?? $trx_event['data']['id']['token_data_id']['collection'] ?? null;
                if (is_null($creator) || is_null($name) || is_null($collection))
                {
                    continue; // skip not interested 0x3::token events.
                }

                $currency_process_id = $creator . '::' . bin2hex($collection) . '::' . bin2hex($name);
                $currency_id = $creator . '::' . bin2hex($collection);
                if (!array_key_exists($currency_process_id, $currencies_in_trx))
                {
                    $currencies_in_trx[$currency_process_id] = [
                        'id' => $currency_id,
                        'name' => $name,
                        'collection' => $collection,
                        'deposit_count' => 0,
                        'withdraw_count' => 0,
                    ];
                }

                switch ($trx_event['type'])
                {
                    // Process only Deposit/Withdraw events because other events duplicates this.
                    case '0x3::token::DepositEvent':
                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'currency' => $currency_id,
                            'address' => $address,
                            'sort_key' => $sort_key++,
                            'effect' => '1',
                            'failed' => $failed,
                            'extra' => $name,
                        ];

                        $currencies_in_trx[$currency_process_id]['deposit_count']++;
                        break;

                    case '0x3::token::WithdrawEvent':
                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'currency' => $currency_id,
                            'address' => $address,
                            'sort_key' => $sort_key++,
                            'effect' => '-1',
                            'failed' => $failed,
                            'extra' => $name,
                        ];

                        $currencies_in_trx[$currency_process_id]['withdraw_count']++;
                        break;
                }
            }

            foreach ($currencies_in_trx as $data)
            {
                // Save for final currencies array.
                $currencies_to_process[] = $data['id'];

                // Process Tokens from nowhere (Mint/Claim/Offer).
                $count_diff = $data['deposit_count'] - $data['withdraw_count'];
                // For a specific TokenId must be only one transfer with 'the-offered' address.
                assert(abs($count_diff) === 1 || $count_diff === 0);
                if ($count_diff !== 0)
                {
                    if ($count_diff > 0)
                    {
                        $effect = '-1';
                    }
                    else
                    {
                        $effect = '1';
                    }
                    $events[] = [
                        'block' => $block['block_height'],
                        'transaction' => $trx['hash'],
                        'time' => $this->block_time,
                        'currency' => $data['id'],
                        'address' => 'the-offered',
                        'sort_key' => $sort_key++,
                        'effect' => $effect,
                        'failed' => $failed,
                        'extra' => $data['name'],
                    ];
                }
            }
        }

        $currencies_to_process = array_unique($currencies_to_process);
        foreach ($currencies_to_process as $currency)
        {
            $parts = explode('::', $currency);
            $currencies[] = [
                'id' => $parts[0] . '::' . $parts[1],
                'name' => hex2bin($parts[1]),
            ];
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }
}
