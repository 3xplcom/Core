<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main transactions in Stellar. Requires a Stellar node.  */

abstract class StellarLikeMainModule extends CoreModule
{
    use StellarTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?array $special_addresses = ['the-void', 'operations'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = []; 

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'f' => "Fee",
        'o' => "Source account",
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = false;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public string $block_entity_name = 'ledger';

    // Blockchain-specific

    public ?int $transaction_count = null;
    public ?int $operation_count = null;
    public ?string $paging_token = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }


    final public function post_post_initialize()
    {
        //
    }

    final public function pre_process_block($block_id)
    {
        $events = [];
        $transactions = [];

        $transactions = $this->get_data_with_cursor(
            $this->select_node() . "ledgers/{$block_id}/transactions?order=desc&limit=%s&include_failed=true&cursor=%s",
            $this->transaction_count);

        $sort_key = 0;

        foreach ($transactions as $tx)
        {
            $events[] = [
                'transaction' => $tx['id'],
                'address' => $tx['fee_account'],
                'sort_key' => $sort_key++,
                'effect' => '-' . $tx['fee_charged'],
                'failed' => false,
                'extra' => 'f',
            ];

            $events[] = [
                'transaction' => $tx['id'],
                'address' => 'the-void',
                'sort_key' => $sort_key++,
                'effect' => $tx['fee_charged'],
                'failed' => false,
                'extra' => 'f',
            ];

            $events[] = [
                'transaction' => $tx['id'],
                'address' => $tx['source_account'],
                'sort_key' => $sort_key++,
                'effect' => '-0',
                'failed' => false,
                'extra' => 'o', // We need something different here
            ];

            $events[] = [
                'transaction' => $tx['id'],
                'address' => 'operations',
                'sort_key' => $sort_key++,
                'effect' => '0',
                'failed' => false,
                'extra' => 'o',
            ];
        }

        ////////////////
        // Processing //
        ////////////////

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
        $account_balances = requester_single($this->select_node(),
            endpoint: "accounts/{$address}",
            result_in: 'balances',
            timeout: $this->timeout);

        foreach ($account_balances as $balance)
        {
            if ($balance['asset_type'] === 'native')
            {
                return $this->to_7($balance['balance']);
            }
        }

        throw new ModuleError('Unknown code flow');
    }

    // Specials for transaction and addresses 
    final public function api_get_transaction_specials($transaction) 
    {
        $transaction_data = requester_single($this->select_node() . "transactions/{$transaction}", timeout: $this->timeout);

        $specials = new Specials();
        $specials->add('max_fee', $transaction_data['max_fee'],
            function ($raw_value) { return "The maximum fee (in stroops): {{$raw_value}}";});
        $specials->add('memo_type', $transaction_data['memo_type']);

        if(isset($transaction_data['memo_bytes']))
            $specials->add('memo_bytes', $transaction_data['memo_bytes']);

        if(isset($transaction_data['memo']))
            $specials->add('memo', $transaction_data['memo']);

        return $specials->return();
    }

    final public function api_get_address_specials($address) 
    {
        try {
            $account_data = requester_single($this->select_node() . "accounts/{$address}", timeout: $this->timeout);
        } catch (RequesterException) {
            $specials = new Specials();
            return $specials->return();
        }
        

        $specials = new Specials();
        $specials->add('sequence_number', $account_data['sequence']);
        $specials->add('last_modified_ledger', $account_data['last_modified_ledger']);
        $specials->add('deleted', $account_data['deleted'] ?? null, 
            function ($raw_value) { if ($raw_value) return 'deleted ?: {Yes}'; else return 'deleted ?: {No}'; });

        if(isset($transaction_data['home_domain']))
            $specials->add('home_domain', $transaction_data['home_domain']);

        return $specials->return();
    }
}
