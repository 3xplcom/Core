<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Botanix. It requires a geth node to run. */

final class BotanixERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'botanix';
        $this->module = 'botanix-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-11-22';
        $this->first_block_id = 0;
    }
}
