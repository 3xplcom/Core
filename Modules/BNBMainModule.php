<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main BNB module. It requires either a geth or an Erigon node to run (but the latter is much faster).  */

final class BNBMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bnb';
        $this->module = 'bnb-main';
        $this->is_main = true;
        $this->first_block_date = '2020-08-29';
        $this->first_block_id = 0;

        // EVMMainModule
        $this->currency = 'bnb';
        $this->currency_details = ['name' => 'BNB', 'symbol' => 'BNB', 'decimals' => 18, 'description' => null];
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running geth, but this would be slower
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.bnb/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0x0178b8bf', '0x08ced32a7f3eec915ba84415e9c07a7286977956');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };
    }
}
