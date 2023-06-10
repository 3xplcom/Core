<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Avalanche C-Chain module. It requires a geth node to run.  */

final class AvalancheMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'avalanche'; // C-Chain
        $this->module = 'avalanche-main';
        $this->is_main = true;
        $this->first_block_date = '2015-07-30'; // That's for block #0, in reality it starts on 2020-09-23 with block #1... ¯\_(ツ)_/¯
        $this->first_block_id = 0;
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Avalanche doesn't implement mempool
        $this->forking_implemented = false; // And all blocks are instantly finalized

        // EVMMainModule
        $this->currency = 'avalanche';
        $this->currency_details = ['name' => 'Avalanche', 'symbol' => 'AVAX', 'decimals' => 18, 'description' => null];
        $this->evm_implementation = EVMImplementation::geth;
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
