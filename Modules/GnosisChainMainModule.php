<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Gnosis Chain module. It requires either a Nethermind or an Erigon node to run (but the latter is much faster).
 *  Not that for some transactions such as 0x0cd5fc7f3d1ff461a01de546c8c32d4ec74fb66e640dbe4ed537f3a6ab2e27bc, Nethermind returns
 *  the created contract address neither in the transaction info, nor in the transaction receipt. That's probably a bug with
 *  Nethermind which is not observed with Erigon. `No address` exception will be thrown for such transactions.  */

final class GnosisChainMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'gnosis-chain';
        $this->module = 'gnosis-chain-main';
        $this->is_main = true;
        $this->first_block_date = '2018-10-08';
        $this->first_block_id = 0;
        $this->currency = 'xdai';
        $this->currency_details = ['name' => 'xDAI', 'symbol' => 'xDAI', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running Nethermind, but this would be slower
        $this->extra_features = [EVMSpecialFeatures::EffectiveGasPriceCanBeZero];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.(gno|owl)/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0x0178b8bf', '0x4f132a7e39b1d717b39c789eb9ec1e790092042b');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };
    }
}
