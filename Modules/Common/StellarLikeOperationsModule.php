<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Stellar operations. Requires a Stellar node.  */

abstract class StellarLikeOperationsModule extends CoreModule
{
    use StellarTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::AlphaNumeric;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void', 'liquidity-pool', 'sdex'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'currency', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['currency'];

    public ?array $currencies_table_fields = ['id', 'name', 'symbol', 'decimals'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = null;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

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
    
    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
        timeout: $this->timeout)['history_latest_ledger'];
    }

    final public function post_post_initialize()
    {
        $this->extra_data_details = StellarSpecialTransactions::to_assoc_array();
    }

    final public function pre_process_block($block_id)
    {
		$sort_key = 0;
        $events = [];
        $currencies = [];
        $currencies_to_process = [];
        $operations = $this->get_operations($this->paging_token, $this->transaction_count, $block_id);
        foreach ($operations as $op) 
        {
            $tx_is_failed = $op['transaction_successful'] === true ? false : true; // yes, it's success but for is_failed it will be correct
            switch ($op['type']) {
                case 'create_claimable_balance': 
                    {
                        // transactions/5f5f84fcee2fa2a5b78bef2da97a7bfd5ba8fa0753f3e6a04cffb8c2e47b49b4/operations?limit=200
                        // they have a flow: claimable_balance_sponsorship_created -> account_debited -> claimable_balance_claimant_created
                        // -> claimable_balance_created
                        // So we skip all empty effects (without any value) and accept only account_debited but we don't ask 4 them
                        // just take data from operation
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset'] === 'native') ? null : $op['asset'],
                            'address' => $op['source_account'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset'] === 'native') ? null : $op['asset'],
                            'address' => 'claim',
                            'sort_key' => $sort_key++,
                            'effect' => $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $currencies_to_process[] = ($op['asset'] === 'native') ? null : $op['asset'];
                        break;
                    }
                // operations/210142562131509348
                case 'claim_claimable_balance': 
                    {
                        
                        $id_op = $op['id'];
                        $effects = $this->get_effects($this->select_node() . "operations/{$id_op}/effects?order=desc&cursor=%s");
                        foreach ($effects as $effect) 
                        {
                            switch ($effect['type']) 
                            {
                                case 'account_credited': 
                                    {
                                        $this->parse_account_credited($op, $tx_is_failed, $effect, $sort_key, $events, $currencies_to_process);
                                        break;
                                    }
                                case 'claimable_balance_claimed': 
                                case 'claimable_balance_sponsorship_removed':
                                    {
                                        break;
                                    }
                                default:
                                    throw new ModuleError("Unknown effect type: " . $effect['type'] . " operation: " . $op['id']);
                            }
                        }
                        break;
                    }
                case 'payment': {
                        // ops/210027809195438087
                        // /operations/210446203434086495#
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'],
                            'address' => $op['from'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];

                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'],
                            'address' => $op['to'],
                            'sort_key' => $sort_key++,
                            'effect' => $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $currencies_to_process[] = ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'];
                        break;
                    }
                // /transactions/374eb2b0971e1df37d3a1993a1190fa55c467b0c1e2eb6d54e5b913de93660a1/operations?limit=200
                // ops/210142562131546113
                case 'path_payment_strict_receive': // As I understood, it's same fields as send
                case 'path_payment_strict_send': {
                        // The idea of this path is to send money and hope that something will be back
                        // /transactions/4ae3a58bd8400c07d14c507d74ca1deee6cb67e4fd55d8e5832e5af8b577f4f1/operations?limit=200
                        $id_op = $op['id'];
                        // false /transactions/3c74c225ab0222bbf45acad529421b07461e4c2d65be1346ebc775df8ffcbc1a/operations?limit=200
                        if ($op['transaction_successful'] === false) {
                            // I don't know wht 2 do if we have source_account and from and to
                            // However we'll send from to sdex
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($op['source_asset_type'] === 'native') ? null : 
                                               $op['source_asset_code'] . ":" . $op['source_asset_issuer'],
                                'address' => $op['from'],
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $this->to_7($op['source_amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($op['source_asset_type'] === 'native') ? null : 
                                               $op['source_asset_code'] . ":" . $op['source_asset_issuer'],
                                'address' => 'sdex',
                                'sort_key' => $sort_key++,
                                'effect' => $this->to_7($op['source_amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $currencies_to_process[] = ($op['source_asset_type'] === 'native') ? null : 
                                                        $op['source_asset_code'] . ":" . $op['source_asset_issuer'];

                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($op['asset_type'] === 'native') ? null : 
                                                $op['asset_code'] . ":" . $op['asset_issuer'],
                                'address' => 'sdex',
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $this->to_7($op['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($op['asset_type'] === 'native') ? null : 
                                                $op['asset_code'] . ":" . $op['asset_issuer'],
                                'address' => $op['to'],
                                'sort_key' => $sort_key++,
                                'effect' => $this->to_7($op['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];      
                            $currencies_to_process[] = ($op['asset_type'] === 'native') ? null : 
                                                        $op['asset_code'] . ":" . $op['asset_issuer'];
                            break;                   
                        }
                        $effects = $this->get_effects($this->select_node() . "operations/{$id_op}/effects?order=desc&cursor=%s");

                        // it's too interesting that first effect is `account_credited` but logically it should be in the end
                        // and the second one is `account_debited`
                        $account_credited = $effects[count($effects) - 1];
                        if ($effects[count($effects) - 2]['type'] === 'account_debited') {
                            $this->parse_account_debited($op, $tx_is_failed, $effects[count($effects) - 2], $sort_key, $events, $currencies_to_process);
                            $effects = array_slice($effects, 0, count($effects) - 2);
                        } else {
                            throw new ModuleError("Unknown effect style: operation_id: " . $op['id']);
                        }
                        $amount_of_effects = count($effects);
                        for ($i = $amount_of_effects - 1; $i >= 0; $i--) {
                            $effect = $effects[$i];
                            if ($effect['type'] === 'liquidity_pool_trade') {
                                // /operations/210457305924390913/effects?cursor=&limit=100&order=desc - a lot of liquidity-pool ops
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['sold']['asset'] === 'native') ? null : $effect['sold']['asset'],
                                    'address' => 'liquidity-pool',
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $this->to_7($effect['sold']['amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['sold']['asset'] === 'native') ? null : $effect['sold']['asset'],
                                    'address' => $effect['account'],
                                    'sort_key' => $sort_key++,
                                    'effect' => $this->to_7($effect['sold']['amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $currencies_to_process[] = ($effect['sold']['asset'] === 'native') ? null : $effect['sold']['asset'];

                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['bought']['asset'] === 'native') ? null : $effect['bought']['asset'],
                                    'address' => $effect['account'],
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $this->to_7($effect['bought']['amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['bought']['asset'] === 'native') ? null : $effect['bought']['asset'],
                                    'address' => 'liquidity-pool',
                                    'sort_key' => $sort_key++,
                                    'effect' => $this->to_7($effect['bought']['amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $currencies_to_process[] = ($effect['bought']['asset'] === 'native') ? null : $effect['bought']['asset'];
                                continue;
                            }
                            // TRADE EFFECT  \\ 
                            // here is no logic, I don't know it looks like the first effect   -> A sells tokens B buys it
                            //                                              the seconde effect ->  B buys tokens A sells 
                            // BUT THEY HAVE SAME TERMINOLOGY INSIDE FOR FIRST AND SECOND EFFECT 
                            // AGAIN
                            // SELLER BOUGHT TOKENS
                            // ACCOUNT SOLD TOKENS
                            // IT"S TERMINOLOGY INSIDE HORIZON
                            // But we have that seller got `sold_amount` and gave `bought_amount`
                            if ($effect['type'] === 'trade') {
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['sold_asset_type'] === 'native') ? null : 
                                                    $effect['sold_asset_code'] . ":" . $effect['sold_asset_issuer'],
                                    'address' => $effect['account'],
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $this->to_7($effect['sold_amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['sold_asset_type'] === 'native') ? null : 
                                                    $effect['sold_asset_code'] . ":" . $effect['sold_asset_issuer'],
                                    'address' => $effect['seller'],
                                    'sort_key' => $sort_key++,
                                    'effect' => $this->to_7($effect['sold_amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $currencies_to_process[] = ($effect['sold_asset_type'] === 'native') ? null : 
                                                            $effect['sold_asset_code'] . ":" . $effect['sold_asset_issuer'];

                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['bought_asset_type'] === 'native') ? null : 
                                                    $effect['bought_asset_code'] . ":" . $effect['bought_asset_issuer'],
                                    'address' => $effect['account'],
                                    'sort_key' => $sort_key++,
                                    'effect' => '-' . $this->to_7($effect['bought_amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $events[] = [
                                    'transaction' => $op['transaction_hash'],
                                    'currency' => ($effect['bought_asset_type'] === 'native') ? null : 
                                                    $effect['bought_asset_code'] . ":" . $effect['bought_asset_issuer'],
                                    'address' => $effect['seller'],
                                    'sort_key' => $sort_key++,
                                    'effect' => $this->to_7($effect['bought_amount']),
                                    'failed' => $tx_is_failed,
                                    'extra' => StellarSpecialTransactions::fromName($op['type']),
                                ];
                                $currencies_to_process[] = ($effect['bought_asset_type'] === 'native') ? null : 
                                                            $effect['bought_asset_code'] . ":" . $effect['bought_asset_issuer'];

                                $i--; // this effect is doubling all the time, so we pass the second one
                            }
                        }
                        if ($account_credited['type'] === 'account_credited') 
                        {
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($account_credited['asset_type'] === 'native') ? null : 
                                $account_credited['asset_code'] . ":" . $account_credited['asset_issuer'],
                                'address' => 'sdex',
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $this->to_7($account_credited['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => ($account_credited['asset_type'] === 'native') ? null : 
                                $account_credited['asset_code'] . ":" . $account_credited['asset_issuer'],
                                'address' =>  $account_credited['account'],
                                'sort_key' => $sort_key++,
                                'effect' => $this->to_7($account_credited['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $currencies_to_process[] = ($account_credited['asset_type'] === 'native') ? null : 
                                                        $account_credited['asset_code'] . ":" . $account_credited['asset_issuer'];
                        } else 
                        {
                            throw new ModuleError("Unknown effect style: operation_id: " . $op['id']);
                        }
                        
                        break;
                    }
                case 'clawback': // 210027804899786754
                    {
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'],
                            'address' => $op['from'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'],
                            'address' => $op['source_account'],
                            'sort_key' => $sort_key++,
                            'effect' => $this->to_7($op['amount']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $currencies_to_process[] = ($op['asset_type'] === 'native') ? null : $op['asset_code'] . ":" . $op['asset_issuer'];
                        break;
                    }
                // /operations/210457215730200577/effects?cursor=&limit=100&order=desc
                case 'account_merge':
                    {
                        $id_op = $op['id'];
                        $effects = $this->get_effects($this->select_node() . "operations/{$id_op}/effects?order=desc&cursor=%s");
                        foreach ($effects as $effect) 
                        {
                            switch ($effect['type']) 
                            {
                                case 'account_credited': 
                                    {
                                        $this->parse_account_credited($op, $tx_is_failed, $effect, $sort_key, $events, $currencies_to_process);
                                        break;
                                    }
                                case 'account_debited': 
                                    {
                                        $this->parse_account_debited($op, $tx_is_failed, $effect, $sort_key, $events, $currencies_to_process);
                                        break;
                                    }
                                case 'account_removed':
                                    {
                                        break;
                                    }
                                default:
                                    throw new ModuleError("Unknown operation type: " . $effect['type'] . " operation: " . $op['id']);
                            }
                        }
                        break;
                    }
                // transactions/7fca2d3a20b65ee6d041a408a6f07a7cdd8673bfe6f224285bb6e5027638b671/operations
                case 'create_account':
                    {
                        $id_op = $op['id'];
                        $effects = $this->get_effects($this->select_node() . "operations/{$id_op}/effects?order=desc&cursor=%s");
                        foreach($effects as $effect) 
                        {
                            switch($effect['type']) 
                            {
                                case 'account_created':
                                    {
                                        $events[] = [
                                            'transaction' => $op['transaction_hash'],
                                            'currency' => null,
                                            'address' => 'the-void',
                                            'sort_key' => $sort_key++,
                                            'effect' => '-' . $this->to_7($effect['starting_balance']),
                                            'failed' => $tx_is_failed,
                                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                                        ];
                                        $events[] = [
                                            'transaction' => $op['transaction_hash'],
                                            'currency' => null,
                                            'address' => $effect['account'],
                                            'sort_key' => $sort_key++,
                                            'effect' => $this->to_7($effect['starting_balance']),
                                            'failed' => $tx_is_failed,
                                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                                        ];
                                        break;
                                    }
                                case 'account_debited':
                                    {
                                        $this->parse_account_debited($op, $tx_is_failed, $effect, $sort_key, $events, $currencies_to_process);
                                        break;
                                    }
                                case 'signer_created':
                                case 'account_sponsorship_created':
                                    {
                                        break;
                                    }
                                default:
                                    throw new ModuleError("Unknown operation type: " . $effect['type'] . " operation: " . $op['id']);
                            }
                        }
                        break;
                    }
                // /transactions/13d81f6c80f63c0c92e8c1a27d2293bc9fa903537ed737b495781bf93a1a160e/operations
                case 'liquidity_pool_withdraw':
                    {
                        // echo "Liquidity-pool deposits: " . " operation: " . $op['id'];
                        $reserves_received = $op['reserves_received'];
                        foreach($reserves_received as $rp) 
                        {
                            $asset = null;
                            if(isset($rp['asset'])) {
                                if($rp['asset'] !== 'native') 
                                {
                                    $asset = $rp['asset'];
                                    $currencies_to_process[] = $rp['asset'];
                                }
                            }
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => $asset,
                                'address' => 'liquidity-pool',
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $this->to_7($rp['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => $asset,
                                'address' => $op['source_account'],
                                'sort_key' => $sort_key++,
                                'effect' => $this->to_7($rp['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                        }
                        // also we have pool shares - that is similar to assets, but we can't send them
                        // unfortunately they don't show us how to deal with name, so let it be pool_id 
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' =>  $op['liquidity_pool_id'],
                            'address' => $op['source_account'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this->to_7($op['shares']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => $op['liquidity_pool_id'],
                            'address' => 'liquidity-pool',
                            'sort_key' => $sort_key++,
                            'effect' => $this->to_7($op['shares']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        // $currencies_to_process[] = $op['liquidity_pool_id'];
                        break;
                    }
                // transactions/13d81f6c80f63c0c92e8c1a27d2293bc9fa903537ed737b495781bf93a1a160e/operations
                case 'liquidity_pool_deposit':
                    {
                        // echo "Liquidity-pool deposits: " . " operation: " . $op['id'];
                        $reserves_deposited = $op['reserves_deposited'];
                        foreach($reserves_deposited as $rp) 
                        {
                            $asset = null;
                            if(isset($rp['asset'])) 
                            {
                                if($rp['asset'] !== 'native') 
                                {
                                    $asset = $rp['asset'];
                                    $currencies_to_process[] = $rp['asset'];
                                }
                            }
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => $asset,
                                'address' => $op['source_account'],
                                'sort_key' => $sort_key++,
                                'effect' => '-' . $this->to_7($rp['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                            $events[] = [
                                'transaction' => $op['transaction_hash'],
                                'currency' => $asset,
                                'address' => 'liquidity-pool',
                                'sort_key' => $sort_key++,
                                'effect' => $this->to_7($rp['amount']),
                                'failed' => $tx_is_failed,
                                'extra' => StellarSpecialTransactions::fromName($op['type']),
                            ];
                        }
                        // also we have pool shares - that is similar to assets, but we can't send them
                        // unfortunately they don't show us how to deal with name, so let it be pool_id 
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' => $op['liquidity_pool_id'],
                            'address' => 'liquidity-pool',
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $this->to_7($op['shares_received']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        $events[] = [
                            'transaction' => $op['transaction_hash'],
                            'currency' =>  $op['liquidity_pool_id'],
                            'address' => $op['source_account'],
                            'sort_key' => $sort_key++,
                            'effect' => $this->to_7($op['shares_received']),
                            'failed' => $tx_is_failed,
                            'extra' => StellarSpecialTransactions::fromName($op['type']),
                        ];
                        break;
                    }
                
                // if operation doesn't have any monetary operations -- we skip it, only fees we pay in Main Module
                case 'create_passive_sell_offer': // 210453732511657987
                case 'change_trust':
                case 'manage_buy_offer':
                case 'manage_sell_offer':
                case 'set_options': // 210027804900413445
                case 'allow_trust': // 210027804899786755
                case 'begin_sponsoring_future_reserves':    // 210027804900413441
                case 'set_trust_line_flags':        // 210027774835036161
                case 'end_sponsoring_future_reserves': // 210457202845294614
                case 'bump_sequence': // 210457151305424897
                case 'revoke_sponsorship': //210965907361755138
                    break;
                default:
                    throw new ModuleError("Unknown operation type: " . $op['type'] . " operation: " . $op['id']);
            }
        }

        $currencies_to_process = array_values(array_unique($currencies_to_process)); // Removing duplicates
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format); // Removes already known currencies

        foreach ($currencies_to_process as $currency) 
        {
            if($currency === null)
            {
                $currencies[] = [
                    'id'       => "XLM",
                    'name'     => "XLM",
                    'symbol'   => "XLM",
                    'decimals' => 7,
                ];
                continue;
            }
            $currencies[] = [
                'id'       => $currency,
                'name'     => explode(':', $currency)[0],
                'symbol'   => explode(':', $currency)[1],
                'decimals' => 7,
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
        $this->set_return_currencies($currencies);
    }

    // Getting balances from the node
    // Getting balances from the node
    function api_get_balance(string $address, array $currencies): array
    {
        if (!$currencies)
            return [];

        $real_currencies = [];

        // Input currencies should be in format like this: `stellar-operations/{name:stellar-like-address}`
        foreach ($currencies as $c)
            $real_currencies[] = explode('/', $c)[1];

        $account_currencies = requester_single(
            $this->select_node() . "accounts/{$address}",
            params: [],
            result_in: 'balances',
            timeout: $this->timeout
        );

        $account_balances = [];
        foreach($account_currencies as $currency)
        {
            if($currency['asset_type'] !== 'native')
            {
                $account_balances[$currency['asset_code'] . ":" . $currency['asset_issuer']] = $this->to_7($currency['balance']);
            }
        }

        $return = [];
        foreach($real_currencies as $currency)
        {
            if (isset($account_balances[$currency])) 
            {
                $return[] = $account_balances[$currency];
            } else {
                $return[] = '0';
            }
        }

        return $return;
    }

    private function parse_account_credited($op, $is_failed, $effect, &$sort_key, &$events, &$currencies_to_process) 
    {
        $events[] = [
            'transaction' => $op['transaction_hash'],
            'currency' => ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'],
            'address' => 'the-void',
            'sort_key' => $sort_key++,
            'effect' => '-' . $this->to_7($effect['amount']),
            'failed' => $is_failed,
            'extra' => StellarSpecialTransactions::fromName($op['type']),
        ];

        $events[] = [
            'transaction' => $op['transaction_hash'],
            'currency' => ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'],
            'address' => $effect['account'],
            'sort_key' => $sort_key++,
            'effect' => $this->to_7($effect['amount']),
            'failed' => $is_failed,
            'extra' => StellarSpecialTransactions::fromName($op['type']),
        ];
        $currencies_to_process[] = ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'];
    }

    private function parse_account_debited($op, $is_failed, $effect, &$sort_key, &$events, &$currencies_to_process) 
    {
        $events[] = [
            'transaction' => $op['transaction_hash'],
            'currency' => ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'],
            'address' => $effect['account'],
            'sort_key' => $sort_key++,
            'effect' => '-' . $this->to_7($effect['amount']),
            'failed' => $is_failed,
            'extra' => StellarSpecialTransactions::fromName($op['type']),
        ];

        $events[] = [
            'transaction' => $op['transaction_hash'],
            'currency' => ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'],
            'address' => 'the-void',
            'sort_key' => $sort_key++,
            'effect' => $this->to_7($effect['amount']),
            'failed' => $is_failed,
            'extra' => StellarSpecialTransactions::fromName($op['type']),
        ];
        $currencies_to_process[] = ($effect['asset_type'] === 'native') ? null : $effect['asset_code'] . ":" . $effect['asset_issuer'];
    }

    private function get_operations($paging_token, $count, $block_id) 
    {
        $transactions = [];
        $diff_200 = (string)-819200; // 200 * (Diff) = 200 * (-4096) = -819200;
        $paging_token = bcadd($paging_token, $diff_200); // for escaping a lot of ifs 
        $tx_path = "ledgers/{$block_id}/transactions?order=asc&limit=%s&include_failed=true&cursor=%s";
        $operation_path = "transactions/%s/operations?order=asc&limit=%s&include_failed=true&cursor=%s";
        $operations = [];
        for ($i = $count; $i > 0;) 
        {
            $limit = 200;
            if ($limit < $i) 
            {
                $i -= $limit;
                $paging_token = bcsub($paging_token, $diff_200);
            } else {
                $limit = $i;
                $i = 0;
                $diff_limit = (string)($limit * (-4096));
                $paging_token = bcsub($paging_token, $diff_limit);
            }
            $path_formed = sprintf($tx_path, $limit, $paging_token);
            $multi_curl[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: $path_formed,
                timeout: $this->timeout
            );
        }
        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }
        foreach ($curl_results as $v)
                $transactions = array_merge($transactions, requester_multi_process($v, ignore_errors: true)['_embedded']['records']);

        // here we are not sure that paging_token was lost somewhere
        if($this->transaction_count != count($transactions)) 
        {
            unset($transactions);
            $transactions = $this->get_data_with_cursor(
                $this->select_node() . "ledgers/{$block_id}/transactions?order=desc&limit=%s&include_failed=true&cursor=%s", 
                $this->transaction_count);
        }

        $operations_mc = [];
        $operation_results = [];
        $operations_count = 0;
        foreach($transactions as $tx) 
        {
            $paging_token = $tx['paging_token'];
            $limit = $tx['operation_count'];
            $operations_count += $limit;   // $limit is always no more than 100                                     
            $path_formed = sprintf($operation_path, $tx['id'], $limit, $paging_token);
            $operations_mc[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: $path_formed,
                timeout: $this->timeout
            );
        }
        try
        {
            $operation_results = requester_multi($operations_mc, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }
        foreach ($operation_results as $v)
            $operations = array_merge($operations, requester_multi_process($v, ignore_errors: true)['_embedded']['records']);

        usort($operations, function ($a, $b) {
            return  [
                $a['paging_token'],
            ]
                <=>
                [
                    $b['paging_token'],
                ];
        });
        // here just check the multi_curl function
        if($operations_count != count($operations))
            throw new ModuleError("Incorrect amount of operations in block: {$block_id}");

        return $operations;
    }
}
