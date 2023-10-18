<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Rootstock module.  */

final class RootstockMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rootstock';
        $this->module = 'rootstock-main';
        $this->is_main = true;
        $this->first_block_date = '2018-01-02';
        $this->currency = 'smart-bitcoin';
        $this->currency_details = ['name' => 'Smart Bitcoin', 'symbol' => 'RBTC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::rskEVM];
        $this->reward_function = function ($block_id) {
            return '0';
        };

        // Handles (RNS)
        // https://dev.rootstock.io/rif/rns/mainnet/
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.rsk/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0x0178b8bf', '0xcb868aeabd31e2b66f74e9a55cf064abb31a4ad5');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };
    }
}
