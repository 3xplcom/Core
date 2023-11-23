<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Cosmwasm CW721 transfers at Cosmos SDK blockchains.
 *  Supported CometBFT API: https://docs.cometbft.com/main/rpc/
 *  Also supported Cosmos REST API:
 *    https://v1.cosmos.network/rpc/v0.41.4
 *  CW721 spec: https://github.com/CosmWasm/cw-nfts/blob/main/packages/cw721/README.md */

abstract class CosmosCW721Module extends CoreModule
{
    use CosmosTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::NFT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    // the-void - for mint/burn tokens events
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['transaction', 'block', 'time', 'sort_key', 'address', 'currency', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = [];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Identifier;

    public ?bool $should_return_events = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    // Cosmos-specific
    public ?string $rpc_node = null;

    public array $extra_features = [];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
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

            foreach ($tx_result['events'] as $tx_event)
            {
                switch ($tx_event['type'])
                {
                    case 'wasm':
                        $info = $this->parse_wasm_cw721_event($tx_event['attributes']);
                        if (is_null($info))
                            break;

                        $currencies_to_process[] = $info['currency'];

                        $events[] = [
                            'transaction' => $tx_hash,
                            'sort_key' => $sort_key++,
                            'address' => $info['from'],
                            'currency' => $info['currency'],
                            'effect' => '-1',
                            'failed' => $failed,
                            'extra' => $info['extra'],
                        ];

                        $events[] = [
                            'transaction' => $tx_hash,
                            'sort_key' => $sort_key++,
                            'address' => $info['to'],
                            'currency' => $info['currency'],
                            'effect' => '1',
                            'failed' => $failed,
                            'extra' => $info['extra'],
                        ];

                        break;
                }
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
            $request = base64_encode('{"contract_info":{}}');
            $token_info = requester_single($this->rpc_node, endpoint: "cosmwasm/wasm/v1/contract/{$currency}/smart/{$request}", timeout: $this->timeout, result_in: 'data');
            $currencies[] = [
                'id' => $currency,
                'name' => $token_info['name'] ?? '',
                'symbol' => $token_info['symbol'] ?? '',
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