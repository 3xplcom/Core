<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Moonbeam parachain module. It requires a moonbeam node to run.  */

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
        $this->extra_features = [];
        $this->reward_function = function($block_id)
        {
            return '0';
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
