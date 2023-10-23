<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Moonbeam parachain module. It requires a moonbeam node to run. 
 *  This module only handles EVM-like activity; moonbeam components, that can
 *  only be accessed via Substrate API (such as staking & validator rewards) 
 *  are processed by a separate module. */

final class MoonbeamMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'moonbeam';
        $this->module = 'moonbeam-main';
        $this->is_main = true;
        $this->first_block_date = '2021-12-18';
        $this->currency = 'glimmer';
        $this->currency_details = ['name' => 'Glimmer', 'symbol' => 'GLMR', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FeesToTreasury];
        $this->special_addresses = ['the-void', 'treasury'];
        $this->mempool_implemented = true;
        $this->forking_implemented = true;
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Custom fees
        $this->fee_function_args = ['block_id', 'this_gas_used', 'effective_gas_price'];
        $this->fee_function = function($args)
        {
            // this query can be optimized-away through overloadable post-process-block (default to null, request-if-null, and set-to-null when block is done)
            $prev_block_data = requester_single($this->select_node(),
                params: ['method'  => 'eth_getBlockByNumber',
                         'params'  => [to_0xhex_from_int64($args['block_id'] - 1), false],
                         'id'      => 0,
                         'jsonrpc' => '2.0',
                ], result_in: 'result', timeout: $this->timeout);
            $previous_base_fee_per_gas = to_int256_from_0xhex($prev_block_data['baseFeePerGas'] ?? null);

            $total_fee = bcmul($args['effective_gas_price'], $args['this_gas_used']);
            $base_fee = bcmul($previous_base_fee_per_gas, $args['this_gas_used']);
            $tip_as_fee = bcsub($total_fee, $base_fee);

            // per documentation the 80/20 split is calculated on base & tip separately
            $base_burnt = bcdiv(bcmul($base_fee, '80'), '100');
            $tips_burnt = bcdiv(bcmul($tip_as_fee, '80'), '100');

            $burnt = bcadd($base_burnt, $tips_burnt);
            $treasury = bcsub($total_fee, $burnt);

            return [$burnt, $treasury];
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.dot/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = '0x7d5F0398549C9fDEa03BbDde388361827cb376D5';
            $abi_signature = '0x6352211e';
            $address = $this->ens_get_data_from_resolver($resolver, $hash, $abi_signature, -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };
    }
}