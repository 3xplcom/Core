<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Cosmos SDK native token transfers.
 *  Supported CometBFT API: https://docs.cometbft.com/main/rpc/
 *  Also supported Cosmos REST API:
 *    https://docs.cosmos.network/main/user/run-node/interact-node#using-the-rest-endpoints
 *    https://v1.cosmos.network/rpc/v0.41.4 */

abstract class CosmosMainModule extends CoreModule
{
    use CosmosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;

    // the-void - special address for sending burnt amounts
    // swap-pool - from/to if swap_transacted event detected in header block
    public ?array $special_addresses = ['the-void', 'swap-pool'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['transaction', 'block', 'time', 'sort_key', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Cosmos-specific
    public ?string $rpc_node = null;
    public ?array $cosmos_special_addresses = null;
    // [denom => exponent] ex. [uatom => 0] means 123uatom = 123 * 10^0 ATOM
    public ?array $cosmos_known_denoms = null;

    // Since this block appeared coin_spent/coin_received events in x/bank module
    // value 0 if there is no such fork
    public ?int $cosmos_coin_events_fork = null;

    public array $extra_features = [];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->cosmos_special_addresses))
            throw new DeveloperError("`cosmos_special_addresses` is not set (developer error)");
        if (!array_key_exists('fee_collector', $this->cosmos_special_addresses))
            $this->cosmos_special_addresses['fee_collector'] = '';

        if (is_null($this->cosmos_known_denoms))
            throw new DeveloperError("`cosmos_known_denoms` is not set (developer error)");

        if (is_null($this->cosmos_coin_events_fork))
            throw new DeveloperError("`cosmos_coin_events_fork` is not set (deleloper error)");

        $this->rpc_node = envm(
            $this->module,
            'RPC_NODE',
            new DeveloperError('RPC_NODE not set in the config')
        );
    }

    final public function pre_process_block($block_id)
    {
        $block_data = requester_single($this->select_node(), endpoint: "block?height={$block_id}", result_in: 'result', timeout: $this->timeout);
        $block_results = requester_single($this->select_node(), endpoint: "block_results?height={$block_id}", result_in: 'result', timeout: $this->timeout);

        if (($tx_count = count($block_data['block']['data']['txs'] ?? [])) !== count($block_results['txs_results'] ?? []))
            throw new ModuleException("TXs count and TXs results count mismatch!");

        $events = [];
        $sort_key = 0;

        // Parsing coin_spent/coin_received events because it is guarantees to find all monetary transactions.

        // Process each transaction results.
        for ($i = 0; $i < $tx_count; $i++)
        {
            $tx_hash = $this->get_tx_hash($block_data['block']['data']['txs'][$i]);
            $tx_result = $block_results['txs_results'][$i];
            $failed = (int)$tx_result['code'] === 0 ? false : true;

            // Need to collect fee and fee_payer before parsing events.
            $fee_event_detected = ['from' => false, 'to' => false]; // To avoid double extra
            $fee_info = $this->try_find_fee_info($tx_result['events']);

            if (in_array(CosmosSpecialFeatures::HasDoublesTxEvents, $this->extra_features))
            {
                $this->erase_double_fee_events($tx_result['events']);
            }

            foreach ($tx_result['events'] as $tx_event)
            {
                switch ($tx_event['type'])
                {
                    case 'coin_spent':
                        $coin_spent_data = $this->parse_coin_spent_event($tx_event['attributes']);
                        if (is_null($coin_spent_data))
                            break;

                        foreach ($coin_spent_data['amount'] as $amount)
                        {
                            $amount = $this->denom_amount_to_amount($amount);
                            if (is_null($amount))
                                continue; // In main module skip unknown denoms

                            $extra = null;
                            if (!is_null($fee_info) && !$fee_event_detected['from'])
                            {
                                if ($coin_spent_data['from'] === $fee_info['fee_payer'] &&
                                    $amount === $fee_info['fee'])
                                {
                                    $extra = 'f';
                                    $fee_event_detected['from'] = true;
                                }
                            }

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $coin_spent_data['from'],
                                'effect' => '-' . $amount,
                                'failed' => $failed,
                                'extra' => $extra,
                            ];
                        }

                        break;

                    case 'coin_received':
                        $coin_received_data = $this->parse_coin_received_event($tx_event['attributes']);
                        if (is_null($coin_received_data))
                            break;

                        foreach ($coin_received_data['amount'] as $amount)
                        {
                            $amount = $this->denom_amount_to_amount($amount);
                            if (is_null($amount))
                                continue; // In main module skip unknown denoms

                            $extra = null;
                            if (!is_null($fee_info) && !$fee_event_detected['to'])
                            {
                                if ($coin_received_data['to'] === $this->cosmos_special_addresses['fee_collector'] &&
                                    $amount === $fee_info['fee'])
                                {
                                    $extra = 'f';
                                    $fee_event_detected['to'] = true;
                                }
                            }

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $coin_received_data['to'],
                                'effect' => $amount,
                                'failed' => $failed,
                                'extra' => $extra,
                            ];
                        }

                        break;

                        case 'burn':
                            $burn_data = $this->parse_burn_event($tx_event['attributes']);
                            if (is_null($burn_data))
                                break;

                            $amount = $this->denom_amount_to_amount($burn_data['amount']);
                            if (is_null($amount))
                                break;

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => 'the-void',
                                'effect' => $amount,
                                'failed' => $failed,
                                'extra' => null,
                            ];

                            break;

                    case 'coinbase':
                        $coinbase_data = $this->parse_coinbase_event($tx_event['attributes']);
                        if (is_null($coinbase_data))
                            break;

                        $amount = $this->denom_amount_to_amount($coinbase_data['amount']);
                        if (is_null($amount))
                            break;

                        $events[] = [
                            'transaction' => $tx_hash,
                            'sort_key' => $sort_key++,
                            'address' => 'the-void',
                            'effect' => '-' . $amount,
                            'failed' => $failed,
                            'extra' => null,
                        ];

                        break;

                    case 'transfer':
                        if ($block_id >= $this->cosmos_coin_events_fork)
                            break;

                        $transfer_data = $this->parse_transfer_event($tx_event['attributes']);
                        if (is_null($transfer_data))
                            break;

                        foreach ($transfer_data['amount'] as $amount)
                        {
                            $amount = $this->denom_amount_to_amount($amount);
                            if (is_null($amount))
                                continue; // In main module skip unknown denoms

                            $extra = null;
                            if ($transfer_data['to'] === $this->cosmos_special_addresses['fee_collector'])
                            {
                                $extra = 'f';
                                $fee_info['fee_payer'] = $transfer_data['from'];
                            }

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $transfer_data['from'] ?? $fee_info['fee_payer'], // fee_payer for multi transfers
                                'effect' => '-' . $amount,
                                'failed' => $failed,
                                'extra' => $extra,
                            ];

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $transfer_data['to'],
                                'effect' => $amount,
                                'failed' => $failed,
                                'extra' => $extra,
                            ];
                        }

                        break;
                }
            }
        }

        // Block header events parsing

        foreach ($block_results['begin_block_events'] ?? [] as $bb_event)
        {
            switch ($bb_event['type'])
            {
                case 'coin_spent':
                    $coin_spent_data = $this->parse_coin_spent_event($bb_event['attributes']);
                    if (is_null($coin_spent_data))
                        break;

                    foreach ($coin_spent_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_spent_data['from'],
                            'effect' => '-' . $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coin_received':
                    $coin_received_data = $this->parse_coin_received_event($bb_event['attributes']);
                    if (is_null($coin_received_data))
                        break;

                    foreach ($coin_received_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_received_data['to'],
                            'effect' => $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coinbase':
                    $coinbase_data = $this->parse_coinbase_event($bb_event['attributes']);
                    if (is_null($coinbase_data))
                        break;

                    $amount = $this->denom_amount_to_amount($coinbase_data['amount']);
                    if (is_null($amount))
                        break;

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'effect' => '-' . $amount,
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

                case 'burn':
                    $burn_data = $this->parse_burn_event($bb_event['attributes']);
                    if (is_null($burn_data))
                        break;

                    $amount = $this->denom_amount_to_amount($burn_data['amount']);
                    if (is_null($amount))
                        break;

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'effect' => $amount,
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

                case 'transfer':
                    if ($block_id >= $this->cosmos_coin_events_fork)
                        break;

                    $transfer_data = $this->parse_transfer_event($bb_event['attributes']);
                    if (is_null($transfer_data))
                        break;

                    foreach ($transfer_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['from'],
                            'effect' => '-' . $amount,
                            'failed' => false,
                            'extra' => null,
                        ];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['to'],
                            'effect' => $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;
            }
        }

        $swap_detected = $this->detect_swap_events($block_results['end_block_events'] ?? []);
        foreach ($block_results['end_block_events'] ?? [] as $eb_event)
        {
            switch($eb_event['type'])
            {
                case 'coin_spent':
                    $coin_spent_data = $this->parse_coin_spent_event($eb_event['attributes']);
                    if (is_null($coin_spent_data))
                        break;

                    foreach ($coin_spent_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_spent_data['from'],
                            'effect' => '-' . $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coin_received':
                    $coin_received_data = $this->parse_coin_received_event($eb_event['attributes']);
                    if (is_null($coin_received_data))
                        break;

                    foreach ($coin_received_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_received_data['to'],
                            'effect' => $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coinbase':
                    $coinbase_data = $this->parse_coinbase_event($eb_event['attributes']);
                    if (is_null($coinbase_data))
                        break;

                    $amount = $this->denom_amount_to_amount($coinbase_data['amount']);
                    if (is_null($amount))
                        break;

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'effect' => '-' . $amount,
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

                case 'burn':
                    $burn_data = $this->parse_burn_event($eb_event['attributes']);
                    if (is_null($burn_data))
                        break;

                    $amount = $this->denom_amount_to_amount($burn_data['amount']);
                    if (is_null($amount))
                        break;

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'effect' => $amount,
                        'failed' => false,
                        'extra' => null,
                    ];

                case 'transfer':
                    if ($block_id >= $this->cosmos_coin_events_fork)
                        break;

                    $transfer_data = $this->parse_transfer_event($eb_event['attributes']);
                    if (is_null($transfer_data))
                        break;

                    if ($swap_detected && is_null($transfer_data['from']))
                        $transfer_data['from'] = 'swap-pool';

                    foreach ($transfer_data['amount'] as $amount)
                    {
                        $amount = $this->denom_amount_to_amount($amount);
                        if (is_null($amount))
                            continue; // In main module skip unknown denoms

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['from'],
                            'effect' => '-' . $amount,
                            'failed' => false,
                            'extra' => null,
                        ];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['to'],
                            'effect' => $amount,
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;
            }
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance(string $address): string
    {
        $data = requester_single($this->rpc_node, endpoint: "cosmos/bank/v1beta1/balances/{$address}", timeout: $this->timeout);
        foreach ($data['balances'] as $balance_data)
        {
            if ($balance_data['denom'] === 'uatom')
                return $balance_data['amount'];
        }

        // Check pagination
        while (!is_null($data['pagination']['next_key']))
        {
            $data = requester_single($this->rpc_node, endpoint: "cosmos/bank/v1beta1/balances/{$address}?pagination.key={$data['pagination']['next_key']}", timeout: $this->timeout);
            foreach ($data['balances'] as $balance_data)
            {
                if ($balance_data['denom'] === 'uatom')
                    return $balance_data['amount'];
            }
        }

        return '0';
    }
}
