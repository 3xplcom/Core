<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes token transfers in TON blockchain. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

abstract class TONLikeTokensModule extends CoreModule
{
    use TONTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = null;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra_indexed', 'currency'];
    public ?array $events_table_nullable_fields = ['extra_indexed'];
    public ?SearchableEntity $extra_indexed_hint_entity = SearchableEntity::Other;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::None;

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

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
        if (is_null($this->currency_type)) throw new DeveloperError('`currency_type` is not set');
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        {
            $this->block_time = date('Y-m-d H:i:s', 0);
            $this->set_return_events([]);
            $this->set_return_currencies([]);
            return;
        }

        $block = requester_single(
            $this->select_node(),
            endpoint: 'get_blocks/by_master_height/tokens',
            params: [
                'args' => [
                    $block_id,
                    true
                ]
            ],
            timeout: $this->timeout);

        $events = [];
        $currencies_to_process = [];

        foreach ($block['token_transfers'] as $transaction)
        {
            if (explode(',', substr($transaction['block'], 1), 2)[0] != $this->workchain) // ignore any other chain beside specified
                continue;

            if ($transaction['token_type'] == 'Jetton' && $this->currency_type == CurrencyType::NFT)
                continue;

            if ($transaction['token_type'] == 'NFT' && $this->currency_type == CurrencyType::FT)
                continue;

            [$src, $dst] = $this->remap_participants($transaction);

            // ignore broken transfers and non-std tokens
            if ($src == '' || $dst == '' || $transaction['token'] == 'Unknown')
                continue;

            [$sub, $add] = $this->generate_event_pair(
                $transaction['in_transaction'],
                $src,
                $dst,
                ($transaction['amount'] == "") ? '1' : $transaction['amount'],
                $transaction['lt'],
                1,
                null,
                $transaction['block']
            );
            $sub['currency'] = $transaction['token'];
            $add['currency'] = $transaction['token'];

            array_push($events, $sub, $add);

            $currencies_to_process[] = $transaction['token'];
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
            unset($event['extra']);
        }

        $this->set_return_events($events);

        // Process currencies

        $currencies = [];

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        foreach ($currencies_to_process as $currency_id)
        {
            $currency_data = requester_single(
                $this->select_node(),
                endpoint: 'get_token_info',
                params: [
                    'args' => [$currency_id]
                ],
                timeout: $this->timeout);

            $next_currency = [
                'id' => $currency_id,
                'name' => $currency_data['token_name_pretty'] ?? "",
                'symbol' => $currency_data['symbol'] ?? "",
                'decimals' => $currency_data['decimals'] ?? '0'
            ];

            if (array_key_exists('offchain_metadata', $currency_data))
            {
                try {
                    $offchain_data = requester_single(
                        $currency_data['offchain_metadata'],
                        endpoint: '',
                        timeout: $this->timeout);

                    $next_currency['name'] = $offchain_data['name'] ?? $next_currency['name'];
                    $next_currency['symbol'] = $offchain_data['symbol'] ?? $next_currency['symbol'];
                    $next_currency['decimals'] = $offchain_data['decimals'] ?? $next_currency['decimals'];
                } catch (Exception $e) {}
            }

            if ($next_currency['name'] == 'Unknown' || $next_currency['name'] == '')
               continue;

            $currencies[] = $next_currency;
        }
        $this->set_return_currencies($currencies);

    }

    // Getting balances from the node
    public function api_get_balance($address, $currencies)
    {
        if (!$currencies)
            return [];

        $real_currencies_map = [];
        $return = [];
        $i = 0;
        // Input currencies should be in format like this:
        // `ton-jetton/Ef9VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVbxn` - ton-jetton/<master_contract>
        // `ton-nft/Ef9VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVbxn` - ton-nft/<nft_collection>
        foreach ($currencies as $c)
        {
            $return[] = '0';
            $real_currencies_map[explode('/', $c)[1]] = $i;
            $i++;
        }

        $response = requester_single($this->select_node(),
            endpoint: 'get_account_info',
            params: [
                'args' => [
                    $address, 'tokens', array_keys($real_currencies_map)
                ]
            ],
            timeout: $this->timeout);

        if (!array_key_exists('wallets', $response))
            return $return;

        foreach ($response['wallets'] as $w)
        {
            if (!array_key_exists('master_contract', $w))
                continue;

            if (!array_key_exists('balance', $w))
                continue;

            if (!array_key_exists($w['master_contract'], $real_currencies_map))
                continue;

            $id = $real_currencies_map[$w['master_contract']];

            if ($this->currency_type == CurrencyType::FT && array_key_exists('jetton_balance', $w['balance']))
                $return[$id] = bcadd($return[$id], $w['balance']['jetton_balance']);

            if ($this->currency_type == CurrencyType::NFT && array_key_exists('NFT_index', $w['balance']))
                $return[$id] = bcadd($return[$id], '1');
        }

        return $return;
    }

    final public function remap_participants($transfer)
    {
        switch ($transfer['type'])
        {
            case 'InterWallet':
            case 'TransferNotify':
            case 'OwnershipAssigned':
            case 'JustTransfer':
            case 'Transfer':
            case 'DeployNFT':
                $src = ($transfer['from'] != '') ? $transfer['from'] : $transfer['source'];
                $dst = ($transfer['to'] != '') ? $transfer['to'] : $transfer['destination'];
                return [$src, $dst];
            
            default:
                break;
        }
        return ['', ''];
    }
}