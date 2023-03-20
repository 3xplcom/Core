<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

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
    }
}
