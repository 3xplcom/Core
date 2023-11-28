<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes deposits happening on the Beacon Chain.
 *  This is the main module for Beacon Chain as it should have at least 1 event for every validator, thus it implements `api_get_balance()`
 *  It requires a Prysm-like node to run. And additional API for getting deposit address */

abstract class BeaconChainLikeDepositsModule extends CoreModule
{
    use BeaconChainLikeTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;
    public ?array $special_addresses = ['-1']; // For validators whose deposit was included in Beacon slot, but depositor didn't become a validator (doesn't have an index)

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra_indexed'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?bool $ignore_sum_of_all_effects = true; // Essentially, all events here always come in pair with `the-void`, but we don't
                                                    // put that into the database to save space.

    public string $block_entity_name = 'epoch';
    public string $transaction_entity_name = 'slot';
    public string $address_entity_name = 'validator';

    public ?string $genesis_json = 'BeaconChainDeposits.json';

    public array $chain_config = [];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (!isset($this->chain_config['BELLATRIX_FORK_EPOCH'])) throw new DeveloperError("`BELLATRIX_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['SLOT_PER_EPOCH'])) throw new DeveloperError("`SLOT_PER_EPOCH` is not set");
        if (!isset($this->chain_config['DELAY'])) throw new DeveloperError("`DELAY` is not set");
    }

    final public function pre_process_block($block) // $block here is an epoch number
    {
        $events = [];
        $rq_slot_time = [];
        $deposits = [];             // [i] -> [validator_index, address, amount, slot, eth1address]
        $slot_data = [];
        $key_tes = 0;
        $deposit_endpoint = envm($this->module, 'DEPOSITS');

        $proposers = requester_single($this->select_node(),
            endpoint: "eth/v1/validator/duties/proposer/{$block}",
            timeout: $this->timeout,
            result_in: 'data',
        );

        foreach ($proposers as $proposer)
            $slots[$proposer['slot']] = null;

        foreach ($slots as $slot => $tm)
            $rq_slot_time[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/blocks/{$slot}");

        $rq_slot_time_multi = requester_multi($rq_slot_time,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );

        foreach ($rq_slot_time_multi as $slot)
            $slot_data[] = requester_multi_process($slot);

        foreach ($slot_data as $slot_info)
        {
            if (isset($slot_info['code']) && $slot_info['code'] === '404')
                continue;
            elseif (isset($slot_info['code']))
                throw new ModuleError('Unexpected response code');

            $slot_id = (string)$slot_info['data']['message']['slot'];

            if (isset($slot_info['data']['message']['body']['execution_payload']))
            {
                $timestamp = (int)$slot_info['data']['message']['body']['execution_payload']['timestamp'];
            }
            else
            {
                $timestamp = 0;
            }

            $slots[$slot_id] = $timestamp;

            $deposit = $slot_info['data']['message']['body']['deposits'];

            foreach ($deposit as $d)
            {
                $pubkey = $d['data']['pubkey'];
                $address = $d['data']['withdrawal_credentials'];
                $amount = $d['data']['amount'];

                $validator_info = requester_single($this->select_node(),
                    endpoint: "eth/v1/beacon/states/{$slot_id}/validators/{$pubkey}",
                    timeout: $this->timeout,
                    valid_codes: [200, 404]);

                if (isset($validator_info['code']) && $validator_info['code'] === '404')
                    $index = '-1';
                elseif (isset($slot_info['code']))
                    throw new ModuleError('Unexpected response code');
                else
                    $index = $validator_info['data']['index'];
                
                $deposit_address = requester_single(
                    $deposit_endpoint[0],
                    endpoint: "api/{$pubkey}",
                    timeout: $this->timeout,
                    result_in: 'validator_address');

                $deposits[] = [$index, $address, $amount, $slot_id, $deposit_address];
            }
        }

        $this->get_epoch_time($block, $slots);

        if ($block === 0) 
        {
            $genesis_info = json_decode(file_get_contents(__DIR__ . '/../Genesis/' . $this->genesis_json), true);
            foreach ($genesis_info as $index => $old_deposits)
            {
                foreach ($old_deposits as $old_deposit) 
                {
                    $events[] = [
                        'block' => $block,
                        'transaction' => '0',
                        'sort_key' => $key_tes++,
                        'time' => $this->block_time,
                        'address' => (string)$index,
                        'effect' => (string)$old_deposit['amount'],
                        'extra_indexed' => (string)$old_deposit['deposit_address'],
                    ];
                }
            }
        }

        foreach ($deposits as [$index, $address, $amount, $slot, $deposit_address])
        {
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => (string)$index,
                'effect' => $amount,
                'extra_indexed' => (string)$deposit_address
            ];
        }

        $this->set_return_events($events);
    }

    public function api_get_balance($index)
    {
        return requester_single($this->select_node(),
            endpoint: "eth/v1/beacon/states/head/validators/{$index}",
            timeout: $this->timeout)['data']['balance'];
    }
}
