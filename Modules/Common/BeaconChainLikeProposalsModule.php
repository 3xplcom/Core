<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes block proposals (including missing blocks) happening on the Beacon Chain.
 *  It requires a Prysm-like node to run.  */

abstract class BeaconChainLikeProposalsModule extends CoreModule
{
    use BeaconChainLikeTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;

    public ?bool $must_complement = true; // The main module is "Deposits"

    public ?bool $ignore_sum_of_all_effects = true; // Essentially, all events here always come in pair with `the-void`, but we don't
                                                    // put that into the database to save space.

    public string $block_entity_name = 'epoch';
    public string $transaction_entity_name = 'slot';
    public string $address_entity_name = 'validator';

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
        $rewards_slots = [];        // [slot] -> [validator, reward]
        $slot_data = [];

        $proposers = requester_single($this->select_node(),
            endpoint: "eth/v1/validator/duties/proposer/{$block}",
            timeout: $this->timeout,
            result_in: 'data',
        );

        foreach ($proposers as $proposer)
        {
            $slots[$proposer['slot']] = null;
            $rewards_slots[$proposer['slot']] = [$proposer['validator_index'], null];
        }

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
        }

        $this->get_epoch_time($block, $slots);

        foreach ($slots as $slot => $tm) 
        {
            $rq_block = requester_single( $this->select_node(), 
                endpoint: "eth/v1/beacon/rewards/blocks/{$slot}",
                timeout: $this->timeout,
                valid_codes: [200, 404]);

            if (isset($rq_block['code']) && $rq_block['code'] === '404')
                continue;
            elseif (isset($rq_block['code']))
                throw new ModuleError('Unexpected response code');

            $rewards_slots[$slot][1] = bcadd($rq_block['data']['attestations'], $rq_block['data']['sync_aggregate']);
        }

        $key_tes = 0;

        foreach ($rewards_slots as $slot => [$validator, $reward])
        {
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => (string)$validator,
                'effect' => ($reward ?? '0'),
                'failed' => ($slots[$slot] === null),
            ];
        }

        $this->set_return_events($events);
    }
}
