<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main TON transfers. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

abstract class TONLikeMainModule extends CoreModule
{
    use TONTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['extra', 'extra_indexed'];
    public ?SearchableEntity $extra_indexed_hint_entity = SearchableEntity::Other;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Blockchain-specific

    public ?string $workchain = null; // This should be set in the final module

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->workchain)) throw new DeveloperError('`workchain` is not set');
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        {
            $this->block_time = date('Y-m-d H:i:s', 0);
            $this->set_return_events([]);
            return;
        }

        $block = requester_single(
            $this->select_node(),
            endpoint: 'get_blocks/by_master_height',
            params: [
                'args' => [
                    $block_id,
                    true
                ]
            ],
            timeout: $this->timeout);

        $events = [];

        foreach ($block['transactions'] as $transaction)
        {
            if (explode(',', substr($transaction['block'], 1), 2)[0] != $this->workchain) // ignore any other chain beside specified
                continue;

            [$sub, $add] = $this->generate_event_pair(
                $transaction['hash'],
                $transaction['account'],
                'the-void',
                $transaction['fee'],
                $transaction['lt'],
                0,
                'f',
                $transaction['block']
            );
            array_push($events, $sub, $add);

            $is_from_nowhere = $transaction['imsg_src'] === 'NOWHERE';
            $is_to_nowhere   = $transaction['imsg_dst'] === 'NOWHERE';

            [$sub, $add] = $this->generate_event_pair(
                $transaction['hash'],
                ($is_from_nowhere) ? 'the-void' : $transaction['imsg_src'],
                ($is_to_nowhere) ? 'the-void' : $transaction['imsg_dst'],
                $transaction['imsg_grams'],
                $transaction['lt'],
                1,
                ($is_from_nowhere || $is_to_nowhere) ? 'e' : null,
                $transaction['block']
            );
            array_push($events, $sub, $add);
        }

        array_multisort(
            array_column($events, 'lt_sort'), SORT_ASC,  // first, sort by lt - chronological order is most important
            array_column($events, 'transaction'), SORT_ASC, // then, if any tx happened in diff shards, BUT at the same lt - arbitrarily, by tx-hash
            array_column($events, 'intra_tx'), SORT_ASC, // lastly, ensure within transaction order: (-fee, +fee, -value, +value)
            $events
        );

        $sort_key = 0;
        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
            $event['sort_key'] = $sort_key++;

            unset($event['lt_sort']);
            unset($event['intra_tx']);
        }

        $this->set_return_events($events);

    }

    // Getting balances from the node
    final public function api_get_balance(string $address): string
    {
        $response = requester_single($this->select_node(),
            endpoint: 'get_account_info',
            params: [
                'args' => [
                    $address, false
                    ]
                ],
            timeout: $this->timeout);
        if (!array_key_exists('balance', $response))
            return "0";

        if (!array_key_exists('grams', $response['balance']))
            return "0";

        return (string)$response['balance']['grams'];
    }
}
