<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Sei EVM.  */

final class SeiEVMERC20Module extends EVMERC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei-evm';
        $this->module = 'sei-evm-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2024-01-25'; // This is for the devnet
        $this->first_block_id = 0;
    }
}
