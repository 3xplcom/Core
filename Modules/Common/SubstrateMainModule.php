<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process the main transfer events for Substrate SDK blockchains.
 *  Works with Substrate Sidecar API: https://paritytech.github.io/substrate-api-sidecar/dist/.
 */
require_once __DIR__ . "/../../Engine/Crypto/SS58.php";

abstract class SubstrateMainModule extends CoreModule
{
    use SubstrateTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    // treasury - special address for paid fee and etc.
    // the-void - special address for mint/burn events.
    // pool - special address for rewards from nomination pool.
    public ?SUBSTRATE_NETWORK_PREFIX $network_prefix = null;
    public ?string $treasury_address = null;
    public ?array $special_addresses = ['the-void', 'pool'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        SubstrateSpecialTransactions::Fee->value => 'Transaction fee',
        SubstrateSpecialTransactions::Reward->value => 'Validator block reward',
        SubstrateSpecialTransactions::StakingReward->value => 'Staking reward',
        SubstrateSpecialTransactions::Slashed->value => 'Validator slashed',
        SubstrateSpecialTransactions::DustLost->value => 'Account deleted and tokens lost',
        SubstrateSpecialTransactions::CreatePool->value => 'Create nomination pool',
        SubstrateSpecialTransactions::JoinPool->value => 'Join the nomination pool',
        SubstrateSpecialTransactions::BondExtra->value => 'Bond extra amount to nomination pool',
        SubstrateSpecialTransactions::ClaimBounty->value => 'Bounty amount claimed',
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Substrate-specific
    public ?SubstrateChainType $chain_type = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->chain_type))
            throw new DeveloperError('Chain type is not set (developer error).');
        if (is_null($this->network_prefix))
            throw new DeveloperError("Chain prefix should be set (developer error).");
        // bin2hex("modlpy/trsry") . str_repeat("0", 64-strlen(bin2hex("modlpy/trsry")))
        $this->treasury_address = SS58::ss58_encode("6d6f646c70792f74727372790000000000000000000000000000000000000000", $this->network_prefix->value);

    }

    final public function pre_process_block($block_id)
    {
        $block = requester_single($this->select_node(), endpoint: "blocks/{$block_id}", timeout: $this->timeout);
        $validator = $block['authorId'];

        $events = [];
        $sort_key = 0;

        // Parse onInitialize monetary events
        $this->process_internal_main_events($block['onInitialize']['events'] ?? [], $sort_key, $events);

        // Parse extrinsics data
        foreach ($block['extrinsics'] ?? [] as $extrinsic_number => $extrinsic)
        {
            $tx_id = $block_id . '-' . $extrinsic_number;

            if ($this->chain_type === SubstrateChainType::Relay)
                $this->process_fee_and_reward($extrinsic, $validator, $tx_id, $sort_key, $events);
            elseif ($this->chain_type === SubstrateChainType::Para)
                $this->process_fee($extrinsic, $tx_id, $sort_key, $events);

            $with_transfer = false;
            switch ($extrinsic['method']['pallet'])
            {
                // XCM transfers processed at another module
                case 'xcmPallet':
                case 'xTokens':
                    break;

                // For some chains may be deposits to System accounts
                case 'timestamp':
                    $this->process_timestamp_pallet_deposits($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'balances':
                    $this->process_balances_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'staking':
                    $this->process_staking_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'nominationPools':
                    $this->process_nomination_pools_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'convictionVoting':
                    $this->process_conviction_voting_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'childBounties':
                case 'bounties':
                    $this->process_bounties_pallet($extrinsic, $tx_id, $sort_key, $events);
                    break;

                case 'multisig':
                case 'utility':
                    $method = $extrinsic['method']['method'];
                    $calls = [];

                    // utility
                    if (in_array($method, ['batch', 'batchAll', 'forceBatch']))
                        $calls = $extrinsic['args']['calls'];
                    // utility
                    elseif ($method === 'asDerivative')
                        $calls[] = $extrinsic['args']['call'];
                    // multisig
                    elseif (in_array($method, ['asMulti', 'asMultiThreshold1']))
                        $calls[] = $extrinsic['args']['call'];

                    foreach ($calls ?? [] as $call)
                    {
                        $call_extrinsic = $extrinsic;
                        $call_extrinsic['method'] = $call['method'];
                        $call_extrinsic['args'] = $call['args'];

                        switch ($call['method']['pallet'])
                        {
                            case 'balances':
                                $this->process_balances_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                            case 'staking':
                                $this->process_staking_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                            case 'nominationPools':
                                $this->process_nomination_pools_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                            case 'convictionVoting':
                                $this->process_conviction_voting_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                            case 'childBounties':
                            case 'bounties':
                                $this->process_bounties_pallet($call_extrinsic, $tx_id, $sort_key, $events);
                                break;
                            default:
                                $with_transfer = true;
                        }
                    }
                    break;

                // For other pallets check for transfer in additional events
                default:
                    $with_transfer = true;
            }

            $failed = !$extrinsic['success'];
            $this->process_additional_main_events($extrinsic['events'] ?? [], $with_transfer, $tx_id, $failed, $sort_key, $events);
        }

        // Parse onFinalize monetary events
        $this->process_internal_main_events($block['onFinalize']['events'] ?? [], $sort_key, $events);

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        return requester_single($this->select_node(), endpoint: "accounts/{$address}/balance-info", timeout: $this->timeout, result_in: 'free');
    }
}
