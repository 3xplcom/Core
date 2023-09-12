<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Polygon zkEVM module. It requires a geth node to run.  */

final class PolygonzkEVMMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon-zkevm';
        $this->module = 'polygon-zkevm-main';
        $this->is_main = true;
        $this->first_block_date = '2023-03-24';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::zkEVM];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
