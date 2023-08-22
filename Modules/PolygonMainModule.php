<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Polygon module. It requires either a geth or an Erigon node to run (but the latter is much faster).  */

final class PolygonMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon';
        $this->module = 'polygon-main';
        $this->is_main = true;
        $this->first_block_date = '2020-05-30';
        $this->first_block_id = 0;
        $this->currency = 'matic';
        $this->currency_details = ['name' => 'MATIC', 'symbol' => 'MATIC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::Erigon;
        $this->extra_features = [EVMSpecialFeatures::BorValidator];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
