<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Optimism module. It requires a geth node to run.  */

final class OptimismMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'optimism';
        $this->module = 'optimism-main';
        $this->is_main = true;
        $this->first_block_date = '2021-11-11';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Optimism doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasSystemTransactions];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.box/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $address = $this->ens_get_data_from_resolver('0xf97aac6c8dbaebcb54ff166d79706e3af7a813c8', $hash, '0x3b3b57de', -40);

            if ($address === '0000000000000000000000000000000000000000') // try to call ownerOf on .box NFT contract
                $address = $this->ens_get_data_from_resolver('0xbb7b805b257d7c76ca9435b3ffe780355e4c4b17', $hash, '0x6352211e', -40);

            if ($address && $address !== '0000000000000000000000000000000000000000')
                return '0x' . $address;
            else
                return null;
        };
    }
}
