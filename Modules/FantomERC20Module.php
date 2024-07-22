<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes ERC-20 token transfers in Fantom. It requires a geth node to run.  */

final class FantomERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'fantom';
        $this->module = 'fantom-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2019-12-27';
        $this->first_block_id = 0;
    }
}
