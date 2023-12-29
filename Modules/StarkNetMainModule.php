<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main StarkNet module. It requires a geth node to run.  */

final class StarkNetMainModule extends StarkNetLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'starknet';
        $this->module = 'starknet-main';
        $this->is_main = true;
        $this->first_block_date = '2021-11-16';
        $this->first_block_id = 0;
        $this->currency = 'Ether';
        $this->currency_details = ['name' => 'Ether', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; 
    }
}
