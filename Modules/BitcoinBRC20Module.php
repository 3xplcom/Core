<?php

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes BRC-20 tokens on Bitcoin.
*   It requires https://github.com/hirosystems/ordinals-api */
final class BitcoinBRC20Module extends OrdinalsBRC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin';
        $this->module = 'bitcoin-brc-20';
        $this->is_main = false;
        $this->first_block_id = 700_000;
        $this->first_block_date = '2023-03-08';
    }
}