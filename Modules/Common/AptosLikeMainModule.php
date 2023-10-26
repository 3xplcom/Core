<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process the APT transfers in Aptos Blockchain. */

abstract class AptosLikeMainModule extends CoreModule
{
    use AptosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void' /* 0x00 */, 'the-contract' /* 0x01 */];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;
    public ?bool $ignore_sum_of_all_effects = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        AptosSpecialTransactions::ValidatorReward->value => 'Validator reward',
        AptosSpecialTransactions::Fee->value => 'Fee',
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

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
        $sort_key = 0;

        foreach ($block['transactions'] as $trx)
        {
            $failed = false;

            if ($trx['vm_status'] !== 'Executed successfully')
            {
                $failed = true;
            }

            switch ($trx['type'])
            {
                case 'genesis_transaction':
                    // Process initial APT balances in changes at genesis block.
                    foreach ($trx['changes'] as $change)
                    {
                        $changed_resource = $change['data']['type'] ?? null;

                        if ($changed_resource !== '0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>')
                        {
                            continue;
                        }

                        $address = $change['address'];
                        $balance = $change['data']['data']['coin']['value'] ?? '0';

                        if ($balance === '0')
                        {
                            continue;
                        }

                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'address' => $address,
                            'sort_key' => $sort_key++,
                            'effect' => $balance,
                            'failed' => $failed,
                            'extra' => null,
                        ];

                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'address' => '0x00', // the-void
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $balance,
                            'failed' => $failed,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'block_metadata_transaction':
                    // Process the validators rewards for past epoch.
                    // Currently, slashing is not implemented: https://aptos.dev/concepts/staking/
                    foreach ($trx['events'] as $trx_event)
                    {
                        if ($trx_event['type'] === '0x1::stake::DistributeRewardsEvent')
                        {
                            $events[] = [
                                'block' => $block['block_height'],
                                'transaction' => $trx['hash'],
                                'time' => $this->block_time,
                                'address' => $trx_event['data']['pool_address'],
                                'sort_key' => $sort_key++,
                                'effect' => $trx_event['data']['rewards_amount'],
                                'failed' => $failed,
                                'extra' => AptosSpecialTransactions::ValidatorReward->value,
                            ];

                            $events[] = [
                                'block' => $block['block_height'],
                                'transaction' => $trx['hash'],
                                'time' => $this->block_time,
                                'address' => '0x00', // the-void
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $trx_event['data']['rewards_amount'],
                                'failed' => $failed,
                                'extra' => AptosSpecialTransactions::ValidatorReward->value,
                            ];
                        }
                    }

                    break;

                case 'user_transaction':
                    // There are two ways to transfer APT: 0x1::coin::transfer and 0x1::aptos_account::transfer.
                    // Function 0x1::coin::transfer allow to transfer any coins includes APT and CoinStore must exists in destination account.
                    // Function 0x1::aptos_account::transfer allow to transfer APT only and automatically creates CoinStore for destination account.
                    $fee = bcmul($trx['gas_used'], $trx['gas_unit_price']);
                    $trx['payload']['function'] = $trx['payload']['function'] ?? ''; // function is null for smart-contract deploys

                    if (
                        $trx['payload']['function'] === '0x1::aptos_account::transfer' ||
                        ($trx['payload']['function'] === '0x1::coin::transfer' &&
                            $trx['payload']['type_arguments'][0] === '0x1::aptos_coin::AptosCoin')
                    )
                    {
                        $effect = $this->try_convert_hex($trx['payload']['arguments'][1]);

                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'address' => $trx['sender'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $effect,
                            'failed' => $failed,
                            'extra' => null,
                        ];

                        $events[] = [
                            'block' => $block['block_height'],
                            'transaction' => $trx['hash'],
                            'time' => $this->block_time,
                            'address' => $trx['payload']['arguments'][0],
                            'sort_key' => $sort_key++,
                            'effect' => $effect,
                            'failed' => $failed,
                            'extra' => null,
                        ];
                    }
                    else
                    {
                        $diff_sum = '0';

                        foreach ($trx['changes'] as $change)
                        {
                            $changed_resource = $change['data']['type'] ?? null;

                            if ($changed_resource !== '0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>')
                            {
                                continue;
                            }

                            $address = $change['address'];
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

                            if ($address === $trx['sender'])
                            {
                                if ($diff[0] === '-')
                                {
                                    $diff = bcadd($diff, $fee);
                                }
                                else
                                {
                                    $diff = bcsub($diff, $fee);
                                }
                            }

                            // Check APT diff without fee
                            if ($diff === '0')
                            {
                                continue;
                            }

                            $diff_sum = bcadd($diff_sum, $diff);

                            $events[] = [
                                'block' => $block['block_height'],
                                'transaction' => $trx['hash'],
                                'time' => $this->block_time,
                                'address' => $address,
                                'sort_key' => $sort_key++,
                                'effect' => $diff,
                                'failed' => $failed,
                                'extra' => null,
                            ];
                        }

                        if ($diff_sum !== '0')
                        {
                            $events[] = [
                                'block' => $block['block_height'],
                                'transaction' => $trx['hash'],
                                'time' => $this->block_time,
                                'address' => '0x01', // the-contract
                                'sort_key' => $sort_key++,
                                'effect' => bcmul($diff_sum, '-1'),
                                'failed' => $failed,
                                'extra' => null,
                            ];
                        }
                    }

                    // Process transaction Fee
                    $events[] = [
                        'block' => $block['block_height'],
                        'transaction' => $trx['hash'],
                        'time' => $this->block_time,
                        'address' => $trx['sender'],
                        'sort_key' => $sort_key++,
                        'effect' => '-' . $fee,
                        'failed' => $failed,
                        'extra' => AptosSpecialTransactions::Fee->value,
                    ];

                    $events[] = [
                        'block' => $block['block_height'],
                        'transaction' => $trx['hash'],
                        'time' => $this->block_time,
                        'address' => '0x00', // the-void
                        'sort_key' => $sort_key++,
                        'effect' => $fee,
                        'failed' => $failed,
                        'extra' => AptosSpecialTransactions::Fee->value,
                    ];

                    break;
            }
        }

        foreach ($events as &$event)
        {
            if (!in_array($event['address'], ['0x00', '0x01']))
            {
                if (strlen($event['address']) !== 66) // This is the only known blockchain stripping leading zeroes in addresses ¯\_(ツ)_/¯
                {
                    $event['address'] = '0x' . str_pad(substr($event['address'], 2), 64, '0', STR_PAD_LEFT);
                }
            }
        }

        $this->set_return_events($events);
    }

    public function api_get_balance(string $address): string
    {
        $resource = urlencode("0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>");
        $result = requester_single($this->select_node(), endpoint: "v1/accounts/{$address}/resource/$resource", timeout: $this->timeout, valid_codes: [200, 404]);

        // Code 404 and no field 'data' in response if there is no resource for address
        if (!isset($result['data']))
        {
            return '0';
        }

        return (string) $result['data']['coin']['value'];
    }
}
