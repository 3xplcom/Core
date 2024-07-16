<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Botanix. It requires a geth node to run. */

final class BotanixERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'botanix';
        $this->module = 'botanix-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2023-11-22';
        $this->first_block_id = 0;
    }
}
