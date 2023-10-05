<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process Staking and Rewards for Cardano-like chains.
 *  Requires a fully synced `input-output-hk/cardano-db-sync` database to operate. Database schema for querying:
 *  https://github.com/input-output-hk/cardano-db-sync/blob/master/doc/schema.md  */

/* Staking & Rewards occur simultaneously in the first block of every epoch with no attachment to specific transactions
 * Hence, events recorded by this module are only anchored to such blocks
 * While every other block corresponds to an empty array of events  */

abstract class CardanoLikeRewardsModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::None;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::None;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    //

    public ?\PgSql\Connection $db = null;
    public ?string $block_time = null;
    public ?int $epoch_no = null;
    public ?int $epoch_of_prev = null;
    public ?int $block_id_db = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    private function db_connect()
    {
        if (is_null($this->db))
        {
            $this->db = pg_pconnect($this->select_node());
            $timeout_ms = envm($this->module, 'REQUESTER_TIMEOUT') * 1000;
            pg_query($this->db, "SET statement_timeout = {$timeout_ms}");
        }
    }

    //

    final public function inquire_latest_block()
    {
        $this->db_connect();
        return (int)pg_fetch_assoc(pg_query($this->db, 'SELECT max(block_no) FROM block'))['max'];
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $this->db_connect();

        $block_and_prev = pg_fetch_all(pg_query_params($this->db, '
            SELECT
                hash, time, epoch_no, block_no, id
            FROM block
            WHERE block_no = $1
            UNION (
                SELECT
                    null, null, epoch_no, block_no, null
                FROM block
                WHERE id = (SELECT previous_id FROM block WHERE block_no = $1)
            )
            ORDER BY block_no ASC', [$block_id]));

        $this->epoch_of_prev = (int)$block_and_prev[0]['epoch_no'];

        $this->block_hash = substr($block_and_prev[1]['hash'], 2);
        $this->block_time = $block_and_prev[1]['time'];
        $this->epoch_no = (int)$block_and_prev[1]['epoch_no'];
        $this->block_id_db = (int)$block_and_prev[1]['id'];

        // We don't really check if all our nodes have the same block here as we always use one db connection to
        // retrieve data within a single block anyways, so this one won't be throwing ConsensusException
    }

    final public function pre_process_block($block_id, $break_on_first = false)
    {
        $events = [];
        $this->db_connect();

        if ($this->epoch_of_prev === $this->epoch_no)
        {
            $this->set_return_events($events);
            return;
        }
        $epoch_events = pg_fetch_all(pg_query_params($this->db, '
            SELECT
                1 as type, epoch_stake.id as inner_id, stake_address.view AS staker, pool_hash.view AS pool, epoch_stake.amount as amount
            FROM epoch_stake
            LEFT JOIN stake_address
                ON epoch_stake.addr_id = stake_address.id
            LEFT JOIN pool_hash
                ON epoch_stake.pool_id = pool_hash.id
            WHERE epoch_no = $1 AND amount > 0
            UNION (
                SELECT
                    2 as type, reward.id as inner_id, stake_address.view as staker, pool_hash.view as pool, reward.amount as amount
                FROM reward
                LEFT JOIN stake_address
                    ON reward.addr_id = stake_address.id
                LEFT JOIN pool_hash
                    ON reward.pool_id = pool_hash.id
                WHERE spendable_epoch = $1
            )
            ORDER BY type, inner_id ASC', [$this->epoch_no]));

        // basic orphan guard. if at this point db-block-id associated to this height is missing or different from expected - block needs to be re-queried
        $redundancy = pg_fetch_assoc(pg_query_params($this->db, 'SELECT id FROM block WHERE block_no = $1', [$block_id]));
        if (is_null($redundancy) || (int)$redundancy['id'] !== $this->block_id_db)
            throw new ConsensusException('Internal transaction identifier mismatch (orphaned block?)');

        if (count($epoch_events) < 1)
        {
            $this->set_return_events($events);
            return;
        }

        $sort_key = 0; // for inter-query consistency only; technically everything happens at the same time

        foreach ($epoch_events as $event)
        {
            if ($event['type'] == 1)
                $epoch_stakes[] = $event;
            else
                $epoch_rewards[] = $event;
        }
        unset($epoch_events);

        $pool_stakes = [];
        foreach ($epoch_stakes as $stake)
        {
            $events[] = [
                'address'  => $stake['staker'],
                'effect'   => '-' . $stake['amount'],
                'sort_key' => $sort_key++,
            ];

            $events[] = [
                'address'  => $stake['pool'],
                'effect'   => $stake['amount'],
                'sort_key' => $sort_key++,
            ];

            $pool_stakes[$stake['pool']] = bcadd($stake['amount'], (array_key_exists($stake['pool'], $pool_stakes) ? $pool_stakes[$stake['pool']] : '0'));
        }

        foreach ($pool_stakes as $pool_id => $pool_stake)
        {
            $events[] = [
                'address'  => $pool_id,
                'effect'   => '-' . $pool_stake,
                'sort_key' => $sort_key++,
            ];

            $events[] = [
                'address'  => 'the-void',
                'effect'   => $pool_stake,
                'sort_key' => $sort_key++,
            ];
        }
        unset($pool_stakes);

        // rewards need to be in reverse order (the-void -> pool -> staker) thus into separate structure first; for same reason +effect goes before -effect here
        $events_aux = [];
        $sort_key_aux = 0;

        $pool_rewards = [];
        foreach ($epoch_rewards as $reward)
        {
            $events_aux[] = [
                'address'  => $reward['staker'],
                'effect'   => $reward['amount'],
                'sort_key' => $sort_key_aux++,
            ];

            $events_aux[] = [
                'address'  => $reward['pool'],
                'effect'   => '-' . $reward['amount'],
                'sort_key' => $sort_key_aux++,
            ];

            $pool_rewards[$reward['pool']] = bcadd($reward['amount'], (array_key_exists($reward['pool'], $pool_rewards) ? $pool_rewards[$reward['pool']] : '0'));;
        }

        foreach ($pool_rewards as $pool_id => $pool_reward)
        {
            $events_aux[] = [
                'address'  => $pool_id,
                'effect'   => $pool_reward,
                'sort_key' => $sort_key_aux++,
            ];

            $events_aux[] = [
                'address'  => 'the-void',
                'effect'   => '-' . $pool_reward,
                'sort_key' => $sort_key_aux++,
            ];
        }
        unset($pool_stakes);

        // invert order for reward events
        array_multisort(
            array_column($events_aux, 'sort_key'), SORT_DESC, $events_aux
        );

        // and merge into events-proper
        foreach ($events_aux as $reward_event)
        {
            $events[] = [
                'address'  => $reward_event['address'],
                'effect'   => $reward_event['effect'],
                'sort_key' => $sort_key++,
            ];
        }

        //

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
