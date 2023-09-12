<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Optimism. It requires a geth node to run.  */

final class OptimismERC20Module extends EVMERC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'optimism';
        $this->module = 'optimism-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2021-11-11';
        $this->first_block_id = 0;
    }
}
