<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes various penalties happening on the Beacon Chain.
 *  It requires a Prysm-like node to run.  */

abstract class BeaconChainLikePenaltiesModule extends CoreModule
{
    use BeaconChainLikeTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Mixed;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['extra_indexed'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'sa' => 'Reward for slashing attestor',
        'sp' => 'Reward for slashing proposer',
        'ap' => 'Attestor penalty',
        'pp' => 'Proposer penalty',
    ];

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
        if (!isset($this->chain_config['PHASE0_FORK_EPOCH'])) throw new DeveloperError("`PHASE0_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['ALTAIR_FORK_EPOCH'])) throw new DeveloperError("`ALTAIR_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['BELLATRIX_FORK_EPOCH'])) throw new DeveloperError("`BELLATRIX_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT'])) throw new DeveloperError("`MIN_SLASHING_PENALTY_QUOTIENT` is not set");
        if (!isset($this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR'])) throw new DeveloperError("`MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR` is not set");
        if (!isset($this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX'])) throw new DeveloperError("`MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX` is not set");
        if (!isset($this->chain_config['WHISTLEBLOWER_REWARD_QUOTIENT'])) throw new DeveloperError("`WHISTLEBLOWER_REWARD_QUOTIENT` is not set");
        if (!isset($this->chain_config['SLOT_PER_EPOCH'])) throw new DeveloperError("`SLOT_PER_EPOCH` is not set");
        if (!isset($this->chain_config['DELAY'])) throw new DeveloperError("`DELAY` is not set");
    }

    final public function pre_process_block($block) // $block here is an epoch number
    {
        $events = [];
        $rewards = [];
        $rq_blocks = [];
        $rq_committees = [];
        $rq_blocks_data = [];
        $rq_committees_data = [];
        $rq_slot_time = [];
        $attestors_slashing = [];   // [validator] -> [[validator_index => penalty], slot, amount]
        $proposers_slashing = [];   // [validator] -> [[validator_index => penalty], slot, amount]
        $slot_data = [];

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

            $proposer = $slot_info['data']['message']['proposer_index'];
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

            $attester_slashings = $slot_info['data']['message']['body']['attester_slashings'];

            foreach ($attester_slashings as $as)
            {
                $attestation_1 = $as['attestation_1']['attesting_indices'];
                $attestation_2 = $as['attestation_2']['attesting_indices'];

                $slashed_1 = $this->ask_slashed_validators(attestationGroup: $attestation_1, slot: $slot_id);
                $slashed_2 = $this->ask_slashed_validators(attestationGroup: $attestation_2, slot: $slot_id);

                $slashed = $slashed_1 + $slashed_2;

                if (count($slashed) > 0)
                {
                    if (isset($attestors_slashing[$proposer]))
                    {
                        $attestors_slashing[$proposer][0] = $attestors_slashing[$proposer][0] + $slashed;
                    }
                    else
                    {
                        $attestors_slashing[$proposer][0] = $slashed;
                        $attestors_slashing[$proposer][1] = $slot_id;
                    }
                }
            }

            $proposer_slashings = $slot_info['data']['message']['body']['proposer_slashings'];

            foreach($proposer_slashings as $as)
            {
                $attestation_1 = $as['signed_header_1']['message']['proposer_index'];
                $attestation_2 = $as['signed_header_2']['message']['proposer_index'];

                $slashed_1 = $this->ask_slashed_validators(attestationGroup: [$attestation_1], slot: $slot_id);
                $slashed_2 = $this->ask_slashed_validators(attestationGroup: [$attestation_2], slot: $slot_id);

                $slashed = $slashed_1 + $slashed_2;

                if (count($slashed) > 0)
                {
                    if (isset($proposers_slashing[$proposer]))
                    {
                        $proposers_slashing[$proposer][0] = $proposers_slashing[$proposer][0] + $slashed;
                    }
                    else
                    {
                        $proposers_slashing[$proposer][0] = $slashed;
                        $proposers_slashing[$proposer][1] = $slot_id;
                    }
                }
            }
        }

        $this->get_epoch_time($block, $slots);

        foreach ($slots as $slot => $tm)
            $rq_blocks[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/rewards/blocks/{$slot}");

        $rq_blocks_multi = requester_multi($rq_blocks,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404]
        );

        foreach ($rq_blocks_multi as $v)
            $rq_blocks_data[] = requester_multi_process($v);

        if ($block >= $this->chain_config['ALTAIR_FORK_EPOCH'])
        {
            foreach ($slots as $slot => $tm)
                $rq_committees[] = requester_multi_prepare(
                    $this->select_node(),
                    endpoint: "eth/v1/beacon/rewards/sync_committee/{$slot}",
                    params: '[]',
                    no_json_encode: true
                );

            $rq_committees_multi = requester_multi(
                $rq_committees,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout,
                valid_codes: [200, 404]
            );
            foreach ($rq_committees_multi as $v)
                $rq_committees_data[] = requester_multi_process($v);

            foreach ($rq_committees_data as $slot_rewards) 
            {
                if (isset($slot_rewards['code']) && $slot_rewards['code'] === '404')
                    continue;
                elseif (isset($slot_rewards['code']))
                    throw new ModuleError('Unexpected response code');

                $slot_rewards = $slot_rewards['data'];

                foreach ($slot_rewards as $rw) 
                {
                    if (isset($rewards[$rw['validator_index']]))
                        $rewards[$rw['validator_index']] = bcadd($rw['reward'], $rewards[$rw['validator_index']]);
                    else
                        $rewards[$rw['validator_index']] = $rw['reward'];
                }
            }
        }

        foreach ($rq_blocks_data as $rq)
        {
            if (isset($rq['code']) && $rq['code'] === '404')
                continue;
            elseif (isset($rq['code']))
                throw new ModuleError('Unexpected response code');

            $proposer_index = $rq['data']['proposer_index'];

            if (isset($attestors_slashing[$proposer_index]))
            {
                $attestors_slashing[$proposer_index][2] = $rq['data']['attester_slashings'];
            }

            if (isset($proposers_slashing[$proposer_index]))
            {
                $proposers_slashing[$proposer_index][2] = $rq['data']['proposer_slashings'];
            }
        }

        $key_tes = 0;

        foreach ($proposers_slashing as $index => [$slashed, $slot, $reward])
        {
            foreach ($slashed as $validator_index => [$reward_for_slashing, $penalty])
            {
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$index,
                    'effect' => $reward_for_slashing,
                    'extra' => 'sp',
                    'extra_indexed' => (string)$validator_index
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$validator_index,
                    'effect' => '-' . $penalty,
                    'extra' => 'pp',
                    'extra_indexed' => null
                ];
            }
        }

        foreach ($attestors_slashing as $index => [$slashed, $slot, $reward])
        {
            foreach ($slashed as $validator_index => [$reward_for_slashing, $penalty])
            {
                // As *all* events come in pairs with `the-void` and validators don't interact with each other, we
                // technically don't need transfers to or from `the-void`. At the current rate, this saves more than
                // 1.500 events per second!
    
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$index,
                    'effect' => $reward_for_slashing,
                    'extra' => 'sa',
                    'extra_indexed' => (string)$validator_index
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$validator_index,
                    'effect' => '-' . $penalty,
                    'extra' => 'ap',
                    'extra_indexed' => null
                ];
            }
        }

        $this->set_return_events($events);
    }

    // Private module functions

    private function ask_slashed_validators($attestationGroup = [], $slot = 'head')
    {
        $slashed_validators = [];

        foreach ($attestationGroup as $at)
            $rq_validator_info[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/states/{$slot}/validators/{$at}");

        $rq_validator_info_multi = requester_multi(
            $rq_validator_info,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout
        );

        foreach ($rq_validator_info_multi as $v)
        {
            $validator_info = requester_multi_process($v, result_in: 'data');

            if ($validator_info['validator']['slashed'] === true && !$this->check_validator_slashed($validator_info['index'], $slot))
            {
                if ((int)($slot / $this->chain_config['SLOT_PER_EPOCH']) >= $this->chain_config['PHASE0_FORK_EPOCH'] && (int)($slot / $this->chain_config['SLOT_PER_EPOCH']) < $this->chain_config['ALTAIR_FORK_EPOCH']) // Phase0
                {
                    $reward = (int)($validator_info['validator']['effective_balance'] / $this->chain_config['WHISTLEBLOWER_REWARD_QUOTIENT']);
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / $this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT']);
                }
                elseif ((int)($slot / $this->chain_config['SLOT_PER_EPOCH']) >= $this->chain_config['ALTAIR_FORK_EPOCH'] && (int)($slot / $this->chain_config['SLOT_PER_EPOCH']) < $this->chain_config['BELLATRIX_FORK_EPOCH']) // Altair
                { 
                    $reward = (int)(($validator_info['validator']['effective_balance'] / $this->chain_config['WHISTLEBLOWER_REWARD_QUOTIENT']));
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / $this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR']);
                }
                else    // if ((int)($slot / $this->chain_config['SLOT_PER_EPOCH']) >= $this->chain_config['BELLATRIX_FORK_EPOCH']) // Bellatrix+
                {
                    $reward = (int)(($validator_info['validator']['effective_balance'] / $this->chain_config['WHISTLEBLOWER_REWARD_QUOTIENT']));
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / $this->chain_config['MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX']);
                }

                $slashed_validators[$validator_info['index']] = [strval($reward), strval($slash_penalty)];
            }
        }

        return $slashed_validators;
    }

    private function check_validator_slashed($validator_index, $slot_id)
    {
        $slot_id -= 1;

        $state = requester_single(
            $this->select_node(),
            endpoint: "/eth/v1/beacon/states/{$slot_id}/validators/{$validator_index}",
            timeout: $this->timeout,
            result_in: 'data',
        );

        if ($state['validator']['slashed'] === true)
            return true;

        return false;
    }
}
