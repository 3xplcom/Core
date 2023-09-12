<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Arbitrum One module. It requires a geth node to run.  */

final class ArbitrumOneMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'arbitrum-one';
        $this->module = 'arbitrum-one-main';
        $this->is_main = true;
        $this->first_block_date = '2021-05-28';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Arbitrum One doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::AllowEmptyRecipient];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.arb/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0x0178b8bf', '0x4a067ee58e73ac5e4a43722e008dfdf65b2bf348');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };
    }
}
