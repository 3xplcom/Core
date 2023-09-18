<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process the Aptos Coin (ERC20) transfers in Aptos Blockchain. */

abstract class AptosCoinLikeModule extends CoreModule
{
    use AptosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-contract'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;
    public ?bool $ignore_sum_of_all_effects = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
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
        $currencies_to_process = [];
        $sort_key = 0;

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

            $diff_sum = [];
            foreach ($trx['changes'] as $change)
            {
                $changed_resource = $change['data']['type'] ?? '';
                if (!str_starts_with($changed_resource, '0x1::coin::CoinStore<'))
                {
                    continue;
                }
                $coin = str_replace(['0x1::coin::CoinStore<', ' '], '', $changed_resource);
                if (str_ends_with($coin, '>'))
                {
                    $coin = substr($coin, 0, strlen($coin)-1);
                }
                if ($coin === '0x1::aptos_coin::AptosCoin')
                {
                    continue;
                }

                $address = $change['address'];
                $changed_resource = str_replace(' ', '', $changed_resource);
                $changed_resource_encode = urlencode($changed_resource);

                $resource_before = requester_single($this->select_node(), endpoint: "v1/accounts/{$address}/resource/{$changed_resource_encode}?ledger_version={$block['first_version']}", timeout: $this->timeout, valid_codes: [200, 404]);
                if (!isset($resource_before['data']))
                {
                    $resource_before = null;
                }

                $resource_after = requester_single($this->select_node(), endpoint: "v1/accounts/{$address}/resource/{$changed_resource_encode}?ledger_version={$block['last_version']}", timeout: $this->timeout, valid_codes: [200, 404]);
                if (!isset($resource_after['data']))
                {
                    $resource_after = null;
                }

                $diff = bcsub($resource_after['data']['coin']['value'] ?? '0', $resource_before['data']['coin']['value'] ?? '0');
                if ($diff === '0')
                {
                    continue;
                }

                $diff_sum[$coin] = bcadd($diff_sum[$coin] ?? '0', $diff);
                $events[] = [
                    'block' => $block['block_height'],
                    'transaction' => $trx['hash'],
                    'time' => $this->block_time,
                    'currency' => $coin,
                    'address' => $address,
                    'sort_key' => $sort_key++,
                    'effect' => $diff,
                    'failed' => $failed,
                    'extra' => null,
                ];

                $currencies_to_process[] = $coin;
            }

            foreach ($diff_sum as $coin => $diff_coin)
            {
                if ($diff_coin === '0')
                    continue;
                $events[] = [
                    'block' => $block['block_height'],
                    'transaction' => $trx['hash'],
                    'time' => $this->block_time,
                    'currency' => $coin,
                    'address' => 'the-contract',
                    'sort_key' => $sort_key++,
                    'effect' => bcmul($diff_coin, '-1'),
                    'failed' => $failed,
                    'extra' => null,
                ];
            }
        }

        // Process Coins metadata
        $currencies = [];
        $currencies_to_process = array_unique($currencies_to_process);
        foreach ($currencies_to_process as $currency)
        {
            $currency_address = explode('::', $currency)[0];
            $currency_encode = urlencode('0x01::coin::CoinInfo<' . $currency . '>');

            $meta = requester_single($this->select_node(), endpoint: "v1/accounts/{$currency_address}/resource/{$currency_encode}", timeout: $this->timeout);
            $currencies[] = [
                'id' => $currency,
                'name' => $meta['data']['name'] ?? '',
                'symbol' => $meta['data']['symbol'] ?? '',
                'decimals' => $meta['data']['decimals'] ?? '',
            ];
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    public function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        // Input currencies should be in format like this: `aptos-erc-20/0xf22bede237a07e121b56d91a491eb7bcdfd1f5907926a9e58338f964a01b17fa::asset::USDC`
        $balances = [];
        foreach ($currencies as $currency)
        {
            $coin = explode('/', $currency)[1];
            $resource = urlencode("0x1::coin::CoinStore<{$coin}>");
            $result = requester_single($this->select_node(), endpoint: "v1/accounts/{$address}/resource/$resource", timeout: $this->timeout, valid_codes: [200, 404]);
            // Code 404 and no field 'data' in response if there is no resource for address
            if (!isset($result['data']))
            {
                $balances[] = '0';
                continue;
            }

            $balances[] = (string) $result['data']['coin']['value'];
        }

        return $balances;
    }
}