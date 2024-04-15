<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Ethereum Classic. It requires a geth node to run. */

final class EthereumClassicERC20Module extends EVMERC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum-classic';
        $this->module = 'ethereum-classic-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2015-07-30';
        $this->first_block_id = 0;
    }
}
