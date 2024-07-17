<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Bitfinity (EVM-like L2 for Bitcoin).  */

final class BitfinityERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitfinity';
        $this->module = 'bitfinity-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2024-06-17';
        $this->first_block_id = 0;

        $this->tests = []; // currently, only testnet contains erc1155 activity
    }
}
