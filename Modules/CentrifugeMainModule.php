<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Centrifuge module. */

final class CentrifugeMainModule extends SubstrateMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'centrifuge';
        $this->module = 'centrifuge-main';
        $this->is_main = true;
        $this->first_block_date = '2022-03-12';
        $this->currency = 'centrifuge';
        $this->currency_details = ['name' => 'Centrifuge', 'symbol' => 'CFG', 'decimals' => 18, 'description' => null];
        $this->handles_regex = '^([\w\d ]+)?((//?[^/]+)*)$';
        // this is a hack to return centrifuge address if any substrate address provided
        $this->api_get_handle = function ($handle) {
            $address = $this->decode_address($handle);
            if ($address == '')
                return null;
            return $address;
        };
        // Substrate-specific
        $this->chain_type = SubstrateChainType::Para;
        $this->network_prefix = SUBSTRATE_NETWORK_PREFIX::Centrifuge;
    }
}
