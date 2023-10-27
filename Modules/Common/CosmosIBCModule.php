<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Cosmos SDK IBC transfers.
 *  Supported CometBFT API: https://docs.cometbft.com/main/rpc/
 *  Also supported Cosmos REST API:
 *    https://docs.cosmos.network/main/user/run-node/interact-node#using-the-rest-endpoints
 *    https://v1.cosmos.network/rpc/v0.41.4 */

abstract class CosmosIBCModule extends CoreModule
{
    use CosmosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    // the-ibc-channel - from/to this special address transfers bridged ibc tokens
    // swap-pool - from/to if swap_transacted event detected in header block
    public ?array $special_addresses = ['the-ibc-channel', 'swap-pool'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['transaction', 'block', 'time', 'sort_key', 'address', 'currency', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?array $currencies_table_fields = ['id', 'name', 'description', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Cosmos-specific
    public ?string $rpc_node = null;

    public ?array $cosmos_special_addresses = null;

    // Since this block appeared coin_spent/coin_received events in x/bank module
    // value 0 if there is no such fork
    public ?int $cosmos_coin_events_fork = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->cosmos_special_addresses))
            throw new DeveloperError("`cosmos_special_addresses` is not set (deleloper error)");

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
        $currencies = [];
        $currencies_to_process = [];
        $sort_key = 0;

        // Process each transaction results.
        for ($i = 0; $i < $tx_count; $i++)
        {
            $tx_hash = $this->get_tx_hash($block_data['block']['data']['txs'][$i]);
            $tx_result = $block_results['txs_results'][$i];
            $failed = (int)$tx_result['code'] === 0 ? false : true;

            // Need to collect fee and fee_payer before parsing events.
            $fee_event_detected = ['from' => false, 'to' => false]; // To avoid double extra
            $fee_info = $this->try_find_ibc_fee_info($tx_result['events']);

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
                            $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                            if (is_null($ibc_amount))
                                continue; // Skip none ibc amounts

                            $currencies_to_process[] = $ibc_amount['currency'];

                            $extra = null;
                            if (!is_null($fee_info) && !$fee_event_detected['from'])
                            {
                                if ($coin_spent_data['from'] === $fee_info['fee_payer'] &&
                                    $ibc_amount['amount'] === $fee_info['fee'])
                                {
                                    $extra = 'f';
                                    $fee_event_detected['from'] = true;
                                }
                            }

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $coin_spent_data['from'],
                                'currency' => $ibc_amount['currency'],
                                'effect' => '-' . $ibc_amount['amount'],
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
                            $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                            if (is_null($ibc_amount))
                                continue; // Skip none ibc amounts

                            $currencies_to_process[] = $ibc_amount['currency'];

                            $extra = null;
                            if (!is_null($fee_info) && !$fee_event_detected['to'])
                            {
                                if ($ibc_amount['amount'] === $fee_info['fee'])
                                {
                                    $extra = 'f';
                                    $fee_event_detected['to'] = true;
                                }
                            }

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $coin_received_data['to'],
                                'currency' => $ibc_amount['currency'],
                                'effect' => $ibc_amount['amount'],
                                'failed' => $failed,
                                'extra' => null,
                            ];
                        }

                        break;

                    case 'coinbase':
                        $coinbase_data = $this->parse_coinbase_event($tx_event['attributes']);
                        if (is_null($coinbase_data))
                            break;

                        $ibc_amount = $this->denom_amount_to_ibc_amount($coinbase_data['amount']);
                        if (is_null($ibc_amount))
                            break; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => $tx_hash,
                            'sort_key' => $sort_key++,
                            'address' => 'the-ibc-channel',
                            'currency' => $ibc_amount['currency'],
                            'effect' => '-' . $ibc_amount['amount'],
                            'failed' => $failed,
                            'extra' => null,
                        ];

                        break;

                    case 'burn':
                        $burn_data = $this->parse_burn_event($tx_event['attributes']);
                        if (is_null($burn_data))
                            break;

                        $ibc_amount = $this->denom_amount_to_ibc_amount($burn_data['amount']);
                        if (is_null($ibc_amount))
                            break; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => $tx_hash,
                            'sort_key' => $sort_key++,
                            'address' => 'the-ibc-channel',
                            'currency' => $ibc_amount['currency'],
                            'effect' => $ibc_amount['amount'],
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
                            $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                            if (is_null($ibc_amount))
                                continue; // Skip none ibc amounts

                            $currencies_to_process[] = $ibc_amount['currency'];

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $transfer_data['from'],
                                'currency' => $ibc_amount['currency'],
                                'effect' => '-' . $ibc_amount['amount'],
                                'failed' => $failed,
                                'extra' => null,
                            ];

                            $events[] = [
                                'transaction' => $tx_hash,
                                'sort_key' => $sort_key++,
                                'address' => $transfer_data['to'],
                                'currency' => $ibc_amount['currency'],
                                'effect' => $ibc_amount['amount'],
                                'failed' => $failed,
                                'extra' => null,
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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_spent_data['from'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => '-' . $ibc_amount['amount'],
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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_received_data['to'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => $ibc_amount['amount'],
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coinbase':
                    $coinbase_data = $this->parse_coinbase_event($bb_event['attributes']);
                    if (is_null($coinbase_data))
                        break;

                    $ibc_amount = $this->denom_amount_to_ibc_amount($coinbase_data['amount']);
                    if (is_null($ibc_amount))
                        break;

                    $currencies_to_process[] = $ibc_amount['currency'];

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'currency' => $ibc_amount['currency'],
                        'effect' => '-' . $ibc_amount['amount'],
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

                case 'burn':
                    $burn_data = $this->parse_burn_event($bb_event['attributes']);
                    if (is_null($burn_data))
                        break;

                    $ibc_amount = $this->denom_amount_to_ibc_amount($burn_data['amount']);
                    if (is_null($ibc_amount))
                        break;

                    $currencies_to_process[] = $ibc_amount['currency'];

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'currency' => $ibc_amount['currency'],
                        'effect' => $ibc_amount['amount'],
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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['from'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => '-' . $ibc_amount['amount'],
                            'failed' => false,
                            'extra' => null,
                        ];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['to'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => $ibc_amount['amount'],
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;
            }
        }

        $swap_detected = $swap_detected = $this->detect_swap_events($block_results['end_block_events'] ?? []);
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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_spent_data['from'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => '-' . $ibc_amount['amount'],
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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $coin_received_data['to'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => $ibc_amount['amount'],
                            'failed' => false,
                            'extra' => null,
                        ];
                    }

                    break;

                case 'coinbase':
                    $coinbase_data = $this->parse_coinbase_event($eb_event['attributes']);
                    if (is_null($coinbase_data))
                        break;

                    $ibc_amount = $this->denom_amount_to_ibc_amount($coinbase_data['amount']);
                    if (is_null($ibc_amount))
                        break;

                    $currencies_to_process[] = $ibc_amount['currency'];

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'currency' => $ibc_amount['currency'],
                        'effect' => '-' . $ibc_amount['amount'],
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

                case 'burn':
                    $burn_data = $this->parse_burn_event($eb_event['attributes']);
                    if (is_null($burn_data))
                        break;

                    $ibc_amount = $this->denom_amount_to_ibc_amount($burn_data['amount']);
                    if (is_null($ibc_amount))
                        break;

                    $currencies_to_process[] = $ibc_amount['currency'];

                    $events[] = [
                        'transaction' => null,
                        'sort_key' => $sort_key++,
                        'address' => 'the-void',
                        'currency' => $ibc_amount['currency'],
                        'effect' => $ibc_amount['amount'],
                        'failed' => false,
                        'extra' => null,
                    ];

                    break;

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
                        $ibc_amount = $this->denom_amount_to_ibc_amount($amount);
                        if (is_null($ibc_amount))
                            continue; // Skip none ibc amounts

                        $currencies_to_process[] = $ibc_amount['currency'];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['from'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => '-' . $ibc_amount['amount'],
                            'failed' => false,
                            'extra' => null,
                        ];

                        $events[] = [
                            'transaction' => null,
                            'sort_key' => $sort_key++,
                            'address' => $transfer_data['to'],
                            'currency' => $ibc_amount['currency'],
                            'effect' => $ibc_amount['amount'],
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

        $currencies_to_process = array_unique($currencies_to_process);
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format);

        foreach ($currencies_to_process as $currency)
        {
            // Not possible to get IBC token info from Tendermint API.
            $ibc_hash = explode("_", $currency)[1];
            $denom_trace = requester_single($this->rpc_node, endpoint: "/ibc/apps/transfer/v1/denom_traces/{$ibc_hash}", timeout: $this->timeout);
            $currencies[] = [
                'id' => $currency,
                'name' => $denom_trace['denom_trace']['base_denom'],
                'description' => $denom_trace['denom_trace']['path'],
                'decimals' => 6, // IBC tokens == native tokens from other cosmos sdk chains
            ];
        }

        $this->set_return_events($events);
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    public function api_get_balance(string $address, array $currencies): array
    {
        // Input currencies should be in format like this: `{module}/ibc_E92E07E68705FAD13305EE9C73684B30A7B66A52F54C9890327E0A4C0F1D22E3`
        $denoms_to_find = [];
        foreach ($currencies as $currency)
        {
            $denoms_to_find[] = explode('/', $currency)[1];
        }

        $data = requester_single($this->rpc_node, endpoint: "cosmos/bank/v1beta1/balances/{$address}", timeout: $this->timeout);

        $balances_from_node = [];
        foreach ($data['balances'] as $balance_data)
        {
            $denom = str_replace('/', '_', $balance_data['denom']);
            $balances_from_node[$denom] = $balance_data['amount'];
        }

        // Check pagination
        while (!is_null($data['pagination']['next_key']))
        {
            $data = requester_single($this->rpc_node, endpoint: "cosmos/bank/v1beta1/balances/{$address}?pagination.key={$data['pagination']['next_key']}", timeout: $this->timeout);
            foreach ($data['balances'] as $balance_data)
            {
                $denom = str_replace('/', '_', $balance_data['denom']);
                $balances_from_node[$denom] = $balance_data['amount'];
            }
        }

        $return = [];
        foreach ($denoms_to_find as $denom)
        {
            $return[] = $balances_from_node[$denom] ?? '0';
        }

        return $return;
    }
}
