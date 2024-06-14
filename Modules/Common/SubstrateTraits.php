<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Substrate functions and enums  */
enum SubstrateChainType
{
    case Relay;
    case Para;
}

enum SUBSTRATE_NETWORK_PREFIX: int {
    case Polkadot = 0;
    case Kusama = 2;

    case Astar = 5;

    case Centrifuge = 36;
}

enum SubstrateSpecialTransactions: string
{
    case Fee = 'f';
    case Reward = 'r';
    case StakingReward = 'sr';
    case Slashed = 's';
    case DustLost = 'dl';
    case CreatePool = 'c';
    case JoinPool = 'j';
    case BondExtra = 'be';
    case ClaimBounty = 'cb';
    case BurntFee = 'b';
}

trait SubstrateTraits
{
    public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(), endpoint: 'blocks/head/header?finalized=false', result_in: 'number', timeout: $this->timeout);
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];
        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "blocks/{$block_id}", timeout: $this->timeout);
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        if (count($curl_results) !== 0)
        {
            $result = requester_multi_process($curl_results[0]);
            $this->block_hash = $result['hash'];
            $this->block_time = date('Y-m-d H:i:s', $this->get_block_timestamp($result['extrinsics']));
            foreach ($curl_results as $curl_result)
            {
                if (requester_multi_process($curl_result, result_in: 'hash') !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    // Block timestamp must be at first extrinsic
    // Returns int unix timestamp (seconds) or throw exception
    function get_block_timestamp(?array $extrinsics): int
    {
        if (is_null($extrinsics))
            throw new ModuleException("Invalid (empty) extrinsics array in get_block_timestamp.");

        foreach ($extrinsics as $extrinsic)
        {
            if ($extrinsic['method']['pallet'] === 'timestamp' && $extrinsic['method']['method'] === 'set')
            {
                return (int)((int)$extrinsic['args']['now'] / 1000);
            }
        }

        throw new ModuleException("Cannot get the block timestamp.");
    }

    // Try to collect fee from extrinsic events
    function process_fee(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        if (!$extrinsic['paysFee'])
            return; // No fee and rewards

        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];
        // In some chains there is no partialFee in header
        $partial_fee = $extrinsic['info']['partialFee'] ?? null;

        $fee_detected = false;
        foreach ($extrinsic['events'] as $event)
        {
            // Burnt fee
            if ($event['method']['pallet'] === 'fees' && $event['method']['method'] === 'FeeToBurn')
            {
                $from = $event['data'][0];
                $amount = $event['data'][1];

                [$sub, $add] = $this->generate_event_pair($tx_id, $from, 'the-void', $amount, $failed, $sort_key);
                $sub['extra'] = SubstrateSpecialTransactions::BurntFee->value;
                $add['extra'] = SubstrateSpecialTransactions::BurntFee->value;
                array_push($events, $sub, $add);
            }

            if ($event['method']['pallet'] === 'transactionPayment' && $event['method']['method'] === 'TransactionFeePaid')
            {
                $fee_payer = $event['data'][0];
                $fee = $event['data'][1];

                if ($fee_payer !== $signer)
                    throw new ModuleException("Fee payer is not a signer ({$tx_id}).");
                if ($fee !== ($partial_fee ?? $fee)) // Checks only if its getting from header
                    throw new ModuleException("Fee from transactionPayment and partial_fee missmatch ({$tx_id}).");

                [$sub, $add] = $this->generate_event_pair($tx_id, $signer, $this->treasury_address, $fee, $failed, $sort_key);
                $sub['extra'] = SubstrateSpecialTransactions::Fee->value;
                $add['extra'] = SubstrateSpecialTransactions::Fee->value;
                array_push($events, $sub, $add);
                $fee_detected = true;
            }
        }

        // For any old blocks TransactionFeePaid can not present so we check block header instead
        if ($fee_detected === false)
        {
            $tip = $extrinsic['tip'] ?? '0';
            $fee = bcadd($partial_fee, $tip);

            [$sub, $add] = $this->generate_event_pair($tx_id, $signer, $this->treasury_address, $fee, $failed, $sort_key);
            $sub['extra'] = SubstrateSpecialTransactions::Fee->value;
            $add['extra'] = SubstrateSpecialTransactions::Fee->value;
            array_push($events, $sub, $add);
        }
    }

    // Try to collect fee and block reward from extrinsic events
    function process_fee_and_reward(array $extrinsic, string $validator, string $tx_id, int &$sort_key, array &$events)
    {
        if (!$extrinsic['paysFee'])
            return; // No fee and rewards

        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];
        $partial_fee = $extrinsic['info']['partialFee'];

        $fee_to_treasury = '0';
        $fee_to_validator = '0';
        for ($i = 0; $i < count($extrinsic['events'] ?? []); $i++)
        {
            $pallet = $extrinsic['events'][$i]['method']['pallet'];
            $method = $extrinsic['events'][$i]['method']['method'];
            // Here is 2 cases
            // 1. treasury(Deposit) without treasury address and the next event is balances(Deposit) to validator
            // 2. balances(Deposit) to treasury address "modlpy/trsry" (ss58 encoded) and the next event is balances(Deposit) to validator
            if (($pallet === 'treasury' && $method === 'Deposit' && $fee_to_treasury === '0') ||
                ($pallet === 'balances' && $method === 'Deposit' && $fee_to_treasury === '0' && $extrinsic['events'][$i+1]['method']['pallet'] != 'treasury')
                    && $extrinsic['events'][$i]['data'][0] === $this->treasury_address
            )
            {
                $next_i = $i + 1;
                if ($next_i >= count($extrinsic['events']))
                    throw new ModuleException("Cannot find event for validator reward ({$tx_id}).");

                $pallet_next = $extrinsic['events'][$next_i]['method']['pallet'];
                $method_next = $extrinsic['events'][$next_i]['method']['method'];
                if ($pallet_next === 'balances' && $method_next === 'Deposit')
                {
                    // 80% of fee amount goes to treasury
                    $fee_to_treasury = $pallet == "balances" ? $extrinsic['events'][$i]['data'][1] : $extrinsic['events'][$i]['data'][0] ;
                    // 20% of fee goes to validator
                    $fee_to_validator = $extrinsic['events'][$next_i]['data'][1];

                    if ($extrinsic['events'][$next_i]['data'][0] !== $validator)
                        throw new ModuleException("Invalid event for validator reward ({$tx_id}).");

                    [$sub, $add] = $this->generate_event_pair($tx_id, $signer, $this->treasury_address, $fee_to_treasury, $failed, $sort_key);
                    $sub['extra'] = SubstrateSpecialTransactions::Fee->value;
                    $add['extra'] = SubstrateSpecialTransactions::Fee->value;
                    array_push($events, $sub, $add);

                    [$sub, $add] = $this->generate_event_pair($tx_id, $signer, $validator, $fee_to_validator, $failed, $sort_key);
                    $sub['extra'] = SubstrateSpecialTransactions::Reward->value;
                    $add['extra'] = SubstrateSpecialTransactions::Reward->value;
                    array_push($events, $sub, $add);
                }
            }
            elseif ($pallet === 'transactionPayment' && $method === 'TransactionFeePaid')
            {
                $fee_payer = $extrinsic['events'][$i]['data'][0];
                $fee = $extrinsic['events'][$i]['data'][1];
                if ($fee_payer !== $signer)
                    throw new ModuleException("Fee payer is not a signer ({$tx_id}).");
                if ($fee !== $partial_fee)
                    throw new ModuleException("Fee from transactionPayment and partial_fee missmatch ({$tx_id}).");
                if ($fee !== bcadd($fee_to_treasury, $fee_to_validator))
                    throw new ModuleException("Fee from transactionPayment and parsed fee missmatch ({$tx_id}).");
            }
        }

        // Handle special case for early blocks when fee was paid only to validator
        // example polkadot blocks 4206698, 4206696
        if ($fee_to_treasury === '0' && $fee_to_validator === '0')
        {
            for ($i =count($extrinsic['events'] ?? [1])-1; $i >0 ; $i--)
            {
                if ($extrinsic['events'][$i]['method']['pallet'] === 'balances' && $extrinsic['events'][$i]['method']['method'] === 'Deposit'
                    && $extrinsic['events'][$i]['data'][0] === $validator)
                {
                    $fee_to_validator = $extrinsic['events'][$i]['data'][1];
                    [$sub, $add] = $this->generate_event_pair($tx_id, $signer, $validator, $fee_to_validator, $failed, $sort_key);
                    $sub['extra'] = SubstrateSpecialTransactions::Reward->value;
                    $add['extra'] = SubstrateSpecialTransactions::Reward->value;
                    array_push($events, $sub, $add);
                }
            }
        }
    }

    function process_timestamp_pallet_deposits(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];

        foreach ($extrinsic['events'] ?? [] as $e)
        {
            $pallet = $e['method']['pallet'];
            $method = $e['method']['method'];
            if ($pallet === 'balances' && $method === 'Deposit')
            {
                $to = $e['data'][0];
                $amount = $e['data'][1];
                [$sub, $add] = $this->generate_event_pair($tx_id, 'the-void', $to, $amount, $failed, $sort_key);
                array_push($events, $sub, $add);
            }
        }
    }

    function process_balances_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];

        $data = ['from' => null, 'to' => null, 'amount' => null];
        switch ($extrinsic['method']['method'])
        {
            case 'transfer':
            case 'transferKeepAlive':
            case 'transferAllowDeath':
                $data['from'] = $signer;
                $data['to'] = $extrinsic['args']['dest']['id'] ?? $extrinsic['args']['dest'];
                $data['amount'] = $extrinsic['args']['value'];
                break;

            case 'transferAll':
                $data['from'] = $signer;
                $data['to'] = $extrinsic['args']['dest']['id'] ?? $extrinsic['args']['dest'];
                $data['amount'] = $this->find_transfer_amount_in_events($extrinsic['events']);
                break;

            // Root can force transfer amount from account
            case 'forceTransfer':
                $data['from'] = $extrinsic['args']['source']['id'] ?? $extrinsic['args']['source'];
                $data['to'] = $extrinsic['args']['dest']['id'] ?? $extrinsic['args']['dest'];
                $data['amount'] = $extrinsic['args']['value'];
                break;
        }

        // Invalid transfer data
        if (in_array(null, $data) || !is_string($data['from']) || !is_string($data['to']))
            return;

        [$sub, $add] = $this->generate_event_pair($tx_id, $data['from'], $data['to'], $data['amount'], $failed, $sort_key);
        array_push($events, $sub, $add);
    }

    function process_staking_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];

        switch ($extrinsic['method']['method'])
        {
            // Some small amount deposits to signer
            case 'rebond':
                foreach ($extrinsic['events'] as $e)
                {
                    if ($e['method']['pallet'] !== 'balances')
                        continue;
                    if ($e['method']['method'] === 'Deposit')
                    {
                        $to = $e['data'][0];
                        $amount = $e['data'][1];

                        if ($to === $signer)
                        {
                            [$sub, $add] = $this->generate_event_pair($tx_id, $this->treasury_address, $to, $amount, $failed, $sort_key);
                            array_push($events, $sub, $add);
                        }
                    }
                }
                break;

            case 'payoutStakers':
                $collect_rewards = false;
                for ($i = 0; $i < count($extrinsic['events']); $i++)
                {
                    $current_event = $extrinsic['events'][$i];
                    $current_method = $current_event['method']['method'];
                    $current_pallet = $current_event['method']['pallet'];

                    if ($current_method === 'PayoutStarted')
                        $collect_rewards = true;

                    if ($current_pallet === 'balances' && $current_method === 'Deposit')
                    {
                        $next_event = $extrinsic['events'][$i+1];
                        $next_method = $next_event['method']['method'];
                        $next_pallet = $next_event['method']['pallet'];

                        if ($next_pallet === $this->treasury_address && $next_method === 'Deposit')
                            $collect_rewards = false;

                        if ($collect_rewards === true)
                        {
                            $to = $current_event['data'][0];
                            $amount = $current_event['data'][1];

                            [$sub, $add] = $this->generate_event_pair($tx_id, $this->treasury_address, $to, $amount, $failed, $sort_key);
                            $sub['extra'] = SubstrateSpecialTransactions::StakingReward->value;
                            $add['extra'] = SubstrateSpecialTransactions::StakingReward->value;
                            array_push($events, $sub, $add);
                        }
                    }
                }
                break;
        }
    }

    function process_nomination_pools_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];

        $extra = [
            'from' => ['check' => null, 'value' => null],
            'to' => ['check' => null, 'value' => null],
            'default' => null,
        ];
        switch ($extrinsic['method']['method'])
        {
            case 'createWithPoolId':
            case 'create':
                $extra['from'] = ['check' => $signer, 'value' => SubstrateSpecialTransactions::CreatePool->value];
                break;

            case 'join':
                $extra['default'] = SubstrateSpecialTransactions::JoinPool->value;
                break;

            case 'claimCommission':
            case 'claimPayout':
                $extra['to'] = ['check' => $signer, 'value' => SubstrateSpecialTransactions::StakingReward->value];
                break;

            case 'bondExtra':
                $extra['to'] = ['check' => $signer, 'value' => SubstrateSpecialTransactions::StakingReward->value];
                $extra['from'] = ['check' => $signer, 'value' => SubstrateSpecialTransactions::BondExtra->value];
                break;

            case 'unbond':
            case 'withdrawUnbonded':
                $member = $extrinsic['args']['member_account']['id'] ?? $extrinsic['args']['member_account'];
                $extra['to'] = ['check' => $member, 'value' => SubstrateSpecialTransactions::StakingReward->value];
                break;

            case 'bondExtraOther':
                $member = $extrinsic['args']['member']['id'] ?? $extrinsic['args']['member'];
                $extra['to'] = ['check' => $member, 'value' => SubstrateSpecialTransactions::StakingReward->value];
                $extra['from'] = ['check' => $member, 'value' => SubstrateSpecialTransactions::BondExtra->value];
                break;

            case 'claimPayoutOther':
                $other = $extrinsic['args']['other'];
                $extra['to'] = ['check' => $other, 'value' => SubstrateSpecialTransactions::StakingReward->value];
                break;

            default:
                return; // Skip not monetary events
        }

        // Parse transfer
        foreach ($extrinsic['events'] as $e)
        {
            if ($e['method']['pallet'] !== 'balances')
                continue;

            if ($e['method']['method'] === 'Transfer')
            {
                $from = $e['data'][0];
                $to = $e['data'][1];
                $amount = $e['data'][2];

                [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                if (!is_null($extra['from']['check']) && $extra['from']['check'] === $from)
                {
                    $sub['extra'] = $extra['from']['value'];
                    $add['extra'] = $extra['from']['value'];
                }
                elseif (!is_null($extra['to']['check']) && $extra['to']['check'] === $to)
                {
                    $sub['extra'] = $extra['to']['value'];
                    $add['extra'] = $extra['to']['value'];
                }
                else
                {
                    $sub['extra'] = $extra['default'];
                    $add['extra'] = $extra['default'];
                }
                array_push($events, $sub, $add);
            }
            if ($e['method']['method'] === 'Deposit')
            {
                $from = 'pool';
                $to = $e['data'][0];
                $amount = $e['data'][1];

                if (!is_null($extra['to']['check']) && $extra['to']['check'] === $to)
                {
                    [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                    $sub['extra'] = $extra['to']['value'];
                    $add['extra'] = $extra['to']['value'];
                    array_push($events, $sub, $add);
                }
            }
        }
    }

    function process_conviction_voting_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];

        switch ($extrinsic['method']['method'])
        {
            case 'delegate':
            case 'undelegate':
                foreach ($extrinsic['events'] ?? [] as $e)
                {
                    if ($e['method']['pallet'] !== 'balances')
                        continue;

                    if ($e['method']['method'] === 'Deposit')
                    {
                        $from = $this->treasury_address;
                        $to = $e['data'][0];
                        $amount = $e['data'][1];

                        if ($signer === $to)
                        {
                            [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                            array_push($events, $sub, $add);
                        }
                    }
                }
                break;
        }
    }

    function process_bounties_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        switch ($extrinsic['method']['method'])
        {
            case 'claimChildBounty':
            case 'claimBounty':
                foreach ($extrinsic['events'] ?? [] as $e)
                {
                    if ($e['method']['pallet'] !== 'balances')
                        continue;

                    if ($e['method']['method'] === 'Transfer')
                    {
                        $from = $e['data'][0];
                        $to = $e['data'][1];
                        $amount = $e['data'][2];

                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        $sub['extra'] = SubstrateSpecialTransactions::ClaimBounty->value;
                        $add['extra'] = SubstrateSpecialTransactions::ClaimBounty->value;
                        array_push($events, $sub, $add);
                    }
                }
                break;
        }
    }

    function process_currencies_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events, array &$currencies)
    {
        $failed = !$extrinsic['success'];
        $signer = $extrinsic['signature']['signer']['id'] ?? $extrinsic['signature']['signer'];
        switch ($extrinsic['method']['method'])
        {
            case 'transfer':
                // If extrinsic is failed we need to record failed attempt
                if ($failed)
                {
                    $from = $signer;
                    $to = $extrinsic['args']['dest']['id'] ?? $extrinsic['args']['dest'];
                    $amount = $extrinsic['args']['amount'];
                    $currency = $this->parse_currency_id($extrinsic['args']['currency_id']);
                    if ($currency === $this->native_token_id)
                        break;

                    if (is_string($to) && !is_null($to) && !is_null($amount))
                    {
                        $currencies[] = $currency;
                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        $sub['currency'] = $currency;
                        $add['currency'] = $currency;
                        array_push($events, $sub, $add);
                    }
                }

                // Check events for exact transferred assets
                foreach ($extrinsic['events'] as $event)
                {
                    $pallet = $event['method']['pallet'];
                    $method = $event['method']['method'];
                    if ($pallet === 'currencies' && $method === 'Transferred')
                    {
                        $currency = $this->parse_currency_id($event['data'][0]);
                        if ($currency === $this->native_token_id)
                            continue;
                        $from = $event['data'][1];
                        $to = $event['data'][2];
                        $amount = $event['data'][3];

                        $currencies[] = $currency;
                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        $sub['currency'] = $currency;
                        $add['currency'] = $currency;
                        array_push($events, $sub, $add);
                    }
                }
                break;
        }
    }

    function process_xcm_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        $failed = !$extrinsic['success'];
        switch ($extrinsic['method']['method'])
        {
            case 'reserveTransferAssets':
            case 'limitedReserveTransferAssets':
                foreach ($extrinsic['events'] as $event)
                {
                    $pallet = $event['method']['pallet'];
                    $method = $event['method']['method'];
                    if ($pallet === 'balances' && $method === 'Transfer')
                    {
                        $from = $event['data'][0];
                        $to = $event['data'][1];
                        $amount = $event['data'][2];
                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        array_push($events, $sub, $add);
                    }
                }
                break;

            case 'limitedTeleportAssets':
            case 'teleportAssets':
                $assets = $extrinsic['args']['assets']['v3'] ?? $extrinsic['args']['assets']['v2'] ?? $extrinsic['args']['assets']['v1'] ?? $extrinsic['args']['assets']['v0'];
                $amounts = [];
                foreach ($assets as $asset)
                    $amounts[] = $asset['fun']['fungible'] ?? $asset['concreteFungible']['amount'];

                for ($i = 0; $i < count($extrinsic['events']); $i++)
                {
                    $pallet = $extrinsic['events'][$i]['method']['pallet'];
                    $method = $extrinsic['events'][$i]['method']['method'];
                    if ($pallet === 'xcmPallet' && $method === 'Attempted')
                    {
                        // A situation is possible when the extrinsic is successful but the assets are not actually teleported
                        if ($i < 2)
                            throw new ModuleException("Invalid teleport assets pattern.");

                        $deposit_event = $extrinsic['events'][$i - 1];
                        $withdraw_event = $extrinsic['events'][$i - 2];
                        if ($deposit_event['method']['pallet'] !== 'balances' && $deposit_event['method']['method'] !== 'Deposit')
                            throw new ModuleException("Invalid teleport assets pattern.");
                        if ($withdraw_event['method']['pallet'] === 'balances' && $withdraw_event['method']['pallet'] === 'Withdraw')
                            throw new ModuleException("Invalid teleport assets pattern.");

                        $from = $withdraw_event['data'][0];
                        $to = $deposit_event['data'][0];
                        $amount = $deposit_event['data'][1];
                        if (!in_array($amount, $amounts))
                            throw new ModuleException("Invalid parsed amount for teleport assets.");

                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        array_push($events, $sub, $add);
                    }
                }
                break;
        }
    }

    function process_xtokens_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events, array &$currencies)
    {
        $failed = !$extrinsic['success'];
        switch ($extrinsic['method']['method'])
        {
            case 'transfer':
            case 'transferMultiassets':
            case 'transferMultiasset':
                foreach ($extrinsic['events'] as $event)
                {
                    $pallet = $event['method']['pallet'];
                    $method = $event['method']['method'];

                    // Detected HRMP transfer
                    if ($pallet === 'currencies' && $method === 'Transferred')
                    {
                        $currency_id = $this->parse_currency_id($event['data'][0]);
                        $currencies[] = $currency_id;
                        $from = $event['data'][1];
                        $to = $event['data'][2];
                        $amount = $event['data'][3];

                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                        $sub['currency'] = $currency_id;
                        $add['currency'] = $currency_id;
                        array_push($events, $sub, $add);
                    }
                    // Detected UMP transfer
                    if ($pallet === 'tokens' && $method === 'Withdrawn')
                    {
                        $currency_id = $this->parse_currency_id($event['data'][0]);
                        $currencies[] = $currency_id;
                        $from = $event['data'][1];
                        $amount = $event['data'][2];

                        [$sub, $add] = $this->generate_event_pair($tx_id, $from, 'cross-consensus', $amount, $failed, $sort_key);
                        $sub['currency'] = $currency_id;
                        $add['currency'] = $currency_id;
                        array_push($events, $sub, $add);
                    }
                }

                break;
        }
    }

    function process_parachain_system_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events, array &$currencies)
    {
        $failed = !$extrinsic['success'];
        switch ($extrinsic['method']['method'])
        {
            case 'setValidationData':
                for ($i = 0; $i < count($extrinsic['events']); $i++)
                {
                    $e = $extrinsic['events'][$i];
                    $pallet = $e['method']['pallet'];
                    $method = $e['method']['method'];

                    $from = null;
                    $deposits = [];
                    // Detected HRMP and looking for Deposits
                    if ($pallet === 'xcmpQueue' && $method === 'Success')
                    {
                        for ($j = $i - 1; $j >= 0; $j--)
                        {
                            $e2 = $extrinsic['events'][$j];
                            $pallet2 = $e2['method']['pallet'];
                            $method2 = $e2['method']['method'];

                            // We can find the already processed UMP
                            if ($pallet2 === 'xcmpQueue' && $method2 === 'Success')
                                break;
                            if (count($deposits) >= 2 && !is_null($from))
                                break;

                            if ($pallet2 === 'tokens' && $method2 === 'Deposited')
                            {
                                $currency = $this->parse_currency_id($e2['data'][0]);
                                $currencies[] = $currency;
                                $deposits[] = ['to' => $e2['data'][1], 'amount' => $e2['data'][2], 'currency' => $currency];
                            }
                            if ($pallet2 === 'tokens' && $method2 === 'Withdrawn')
                                $from = $e2['data'][1];
                            // May transferred native tokens
                            if ($pallet2 === 'balances' && $method2 === 'Deposit')
                            {
                                $currency = $this->native_asset_id;
                                $currencies[] = $currency;
                                $deposits[] = ['to' => $e2['data'][0], 'amount' => $e2['data'][1], 'currency' => $currency];
                            }
                            if ($pallet2 === 'balances' && $method2 === 'Withdraw')
                                $from = $e2['data'][0];
                        }
                    }
                    // Detected DMP and looking for Deposits
                    elseif ($pallet === 'dmpQueue' && $method === 'ExecutedDownward')
                    {
                        for ($j = $i - 1; $j >= 0; $j--)
                        {
                            $e2 = $extrinsic['events'][$j];
                            $pallet2 = $e2['method']['pallet'];
                            $method2 = $e2['method']['method'];

                            // We can find the already processed UMP
                            if ($pallet2 === 'dmpQueue' && $method2 === 'ExecutedDownward')
                                break;
                            // End of the message
                            if ($pallet2 === 'parachainSystem' && $method2 === 'DownwardMessagesReceived')
                                break;
                            if (count($deposits) >= 2 && !is_null($from))
                                break;

                            if ($pallet2 === 'tokens' && $method2 === 'Deposited')
                            {
                                $currency = $this->parse_currency_id($e2['data'][0]);
                                $currencies[] = $currency;
                                $deposits[] = ['to' => $e2['data'][1], 'amount' => $e2['data'][2], 'currency' => $currency];
                            }
                            if ($pallet2 === 'tokens' && $method2 === 'Withdrawn')
                                $from = $e2['data'][1];
                        }
                    }

                    foreach ($deposits as $deposit)
                    {
                        [$sub, $add] = $this->generate_event_pair($tx_id, $from ?? 'cross-consensus', $deposit['to'], $deposit['amount'], $failed, $sort_key);
                        $sub['currency'] = $deposit['currency'];
                        $add['currency'] = $deposit['currency'];
                        array_push($events, $sub, $add);
                    }
                }

                break;
        }
    }

    function parse_currency_id(array $cid): string
    {
        if (array_key_exists('token', $cid))
            return 'native-' . $cid['token'];
        elseif (array_key_exists('liquidCrowdloan', $cid))
            return 'native-' . $cid['liquidCrowdloan'];
        elseif (array_key_exists('stableAssetPoolToken', $cid))
            return 'stable-' . $cid['stableAssetPoolToken'];
        elseif (array_key_exists('stableAsset', $cid))
            return 'stable-' . $cid['stableAsset'];
        elseif (array_key_exists('foreignAsset', $cid))
            return 'foreign-' . $cid['foreignAsset'];
        elseif (array_key_exists('Erc20', $cid))
            return 'erc20-' . $cid['Erc20'];
        elseif (array_key_exists('erc20', $cid))
            return 'erc20-' . $cid['erc20'];
        // For unknown or composite tokens from dex we cannot known the id and get the meta
        else
            return 'unknown';
    }

    function process_xcm_in_parainherent_pallet(array $extrinsic, string $tx_id, int &$sort_key, array &$events)
    {
        if ($extrinsic['method']['method'] !== 'enter')
            return;

        $failed = !$extrinsic['success'];
        $xcm_executed = false;
        $withdraw_address = null;
        $deposits = [];
        for ($i = 0; $i < count($extrinsic['events'] ?? []); $i++)
        {
            $e = $extrinsic['events'][$i];
            $pallet = $e['method']['pallet'];
            $method = $e['method']['method'];

            if ($pallet === 'ump' && $method === 'ExecutedUpward' && $xcm_executed)
                throw new ModuleException("Duplicate ExecutedUpward event ({$tx_id})");
            if ($pallet === 'ump' && $method === 'ExecutedUpward')
                $xcm_executed = true;
            if ($pallet === 'balances' && $method === 'Withdraw')
                $withdraw_address = $e['data'][0];

            if ($pallet === 'balances' && $method === 'Deposit')
            {
                if (is_null($withdraw_address))
                    throw new ModuleException("Unknown system account address for XCM deposit ({$tx_id}).");

                $from = $withdraw_address;
                $to = $e['data'][0];
                $amount = $e['data'][1];
                $deposits[] = ['from' => $from, 'to' => $to, 'amount' => $amount];
            }
        }

        if ($xcm_executed)
        {
            foreach ($deposits as $deposit)
            {
                [$sub, $add] = $this->generate_event_pair($tx_id, $deposit['from'], $deposit['to'], $deposit['amount'], $failed, $sort_key);
                array_push($events, $sub, $add);
            }
        }
    }

    // Process some additional events like DustLost etc.
    function process_additional_main_events(array $extrinsic_events, bool $with_transfer, string $tx_id, bool $failed, int &$sort_key, array &$events)
    {
        // Check for additional events.
        foreach ($extrinsic_events as $e)
        {
            if ($e['method']['pallet'] !== 'balances')
                continue;

            switch ($e['method']['method'])
            {
                case 'Transfer':
                    if (!$with_transfer)
                        break;

                    $from = $e['data'][0];
                    $to = $e['data'][1];
                    $amount = $e['data'][2];

                    [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                    array_push($events, $sub, $add);
                    break;

                // Account was killed and amount lost
                case 'DustLost':
                    $from = $e['data'][0];
                    $amount = $e['data'][1];

                    [$sub, $add] = $this->generate_event_pair($tx_id, $from, 'the-void', $amount, $failed, $sort_key);
                    $sub['extra'] = SubstrateSpecialTransactions::DustLost->value;
                    $add['extra'] = SubstrateSpecialTransactions::DustLost->value;
                    array_push($events, $sub, $add);
                    break;

                case 'BalanceSet':
                    $to = $e['data'][0];
                    $amount = $e['data'][1];

                    [$sub, $add] = $this->generate_event_pair($tx_id, 'the-void', $to, $amount, $failed, $sort_key);
                    array_push($events, $sub, $add);
                    break;

                case 'Slashed':
                    $from = $e['data'][0];
                    $amount = $e['data'][1];

                    [$sub, $add] = $this->generate_event_pair(null, $from, $this->treasury_address, $amount, false, $sort_key);
                    $sub['extra'] = SubstrateSpecialTransactions::Slashed->value;
                    $add['extra'] = SubstrateSpecialTransactions::Slashed->value;
                    array_push($events, $sub, $add);
            }
        }
    }

    // Process some additional events like DustLost etc.
    // With none native currencies.
    function process_additional_tokens_events(array $extrinsic_events, bool $with_transfer, string $tx_id, bool $failed, int &$sort_key, array &$events, array &$currencies)
    {
        // Check for additional events.
        foreach ($extrinsic_events as $e)
        {
            $pallet = $e['method']['pallet'];
            $method = $e['method']['method'];
            if ($pallet === 'currencies' && $method === 'Transferred' && $with_transfer)
            {
                $currency = $this->parse_currency_id($e['data'][0]);
                if ($currency === $this->native_token_id)
                    continue;
                $from = $e['data'][1];
                $to = $e['data'][2];
                $amount = $e['data'][3];

                $currencies[] = $currency;
                [$sub, $add] = $this->generate_event_pair($tx_id, $from, $to, $amount, $failed, $sort_key);
                $sub['currency'] = $currency;
                $add['currency'] = $currency;
                array_push($events, $sub, $add);
            }
        }
    }

    // Some monetary operations may be at onInitialize events array.
    // Like Slashing.
    function process_internal_main_events(array $internal_events, int &$sort_key, array &$events)
    {
        foreach ($internal_events as $e)
        {
            $pallet = $e['method']['pallet'];
            $method = $e['method']['method'];
            if ($pallet === 'balances' && $method === 'Slashed')
            {
                $from = $e['data'][0];
                $amount = $e['data'][1];

                [$sub, $add] = $this->generate_event_pair(null, $from, $this->treasury_address, $amount, false, $sort_key);
                $sub['extra'] = SubstrateSpecialTransactions::Slashed->value;
                $add['extra'] = SubstrateSpecialTransactions::Slashed->value;
                array_push($events, $sub, $add);
            }
            if ($pallet === 'balances' && $method === 'Transfer')
            {
                $from = $e['data'][0];
                $to = $e['data'][1];
                $amount = $e['data'][2];
                [$sub, $add] = $this->generate_event_pair(null, $from, $to, $amount, false, $sort_key);
                array_push($events, $sub, $add);
            }
        }
    }

    // Parse XCM events from internal events (onInitialize/onFinalize) for relay chains
    function process_internal_xcm_main_events(array $internal_events, int &$sort_key, array &$events)
    {
        for ($i = 0; $i < count($internal_events); $i++)
        {
            $e = $internal_events[$i];
            $pallet = $e['method']['pallet'];
            $method = $e['method']['method'];

            // Detected UMP and looking for Deposits
            // Looking for Deposit -> Deposit -> Withdraw -> MessageQueueProceed pattern - this is xcm transfer to Relay from Para.
            $from = null;
            $deposits = [];
            if ($pallet === 'messageQueue' && $method === 'Processed')
            {
                for ($j = $i - 1; $j >= 0; $j--)
                {
                    $e2 = $internal_events[$j];
                    $pallet2 = $e2['method']['pallet'];
                    $method2 = $e2['method']['method'];

                    // We can find the already processed UMP
                    if ($pallet2 === 'messageQueue' && $method2 === 'Processed')
                        break;

                    if ($pallet2 === 'balances' && $method2 === 'Deposit')
                        $deposits[] = ['to' => $e2['data'][0], 'amount' => $e2['data'][1]];
                    if ($pallet2 === 'balances' && $method2 === 'Withdraw')
                        $from = $e2['data'][0];
                }
            }

            if (!is_null($from))
            {
                foreach ($deposits as $deposit)
                {
                    [$sub, $add] = $this->generate_event_pair(null, $from, $deposit['to'], $deposit['amount'], false, $sort_key);
                    array_push($events, $sub, $add);
                }
            }
        }
    }

    // Helper function for transferAll etc.
    function find_transfer_amount_in_events(array $extrinsic_events): ?string
    {
        foreach ($extrinsic_events as $event)
        {
            if ($event['method']['pallet'] === 'balances' && $event['method']['method'] === 'Transfer')
            {
                return $event['data'][2];
            }
        }
        return null;
    }

    // Utility functions

    function generate_event_pair(?string $tx, string $src, string $dst, string $amt, bool $fld, int &$sort_key): array
    {
        $sub = [
            'transaction' => $tx,
            'sort_key' => $sort_key++,
            'address' => $src,
            'effect' => '-' . $amt,
            'failed' => $fld,
            'extra' => null
        ];
        $add = [
            'transaction' => $tx,
            'sort_key' => $sort_key++,
            'address' => $dst,
            'effect' => $amt,
            'failed' => $fld,
            'extra' => null
        ];

        return [$sub, $add];
    }

    function decode_address($address): string
    {
        return SS58::ss58_encode($address,$this->network_prefix->value);
    }
}
