<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main zkSync module. It requires a geth node to run.  */

final class zkSyncMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'zksync';
        $this->module = 'zksync-main';
        $this->is_main = true;
        $this->first_block_date = '2023-02-15';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false;

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
