<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in BOB. It requires a geth node to run.  */

final class BOBERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bob';
        $this->module = 'bob-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2024-04-11';
        $this->first_block_id = 0;
    }
}
