<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes BRC-20 token transfers on Bitcoin.
 *  It requires https://github.com/hirosystems/ordinals-api and a Bitcoin Core node to run.  */

final class BitcoinBRC20Module extends OrdinalsBRC20Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin';
        $this->module = 'bitcoin-brc-20';
        $this->is_main = false;
        $this->first_block_id = 779_832;
        $this->first_block_date = '2023-03-08';
    }
}
