<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Astar module. */

final class AstarMainModule extends SubstrateMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'astar';
        $this->module = 'astar-main';
        $this->is_main = true;
        $this->first_block_date = '2021-12-18';
        $this->currency = 'astar';
        $this->currency_details = ['name' => 'Astar', 'symbol' => 'ASTR', 'decimals' => 18, 'description' => null];

        // Substrate-specific
        $this->chain_type = SubstrateChainType::Para;
    }
}
