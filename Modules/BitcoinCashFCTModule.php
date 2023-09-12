<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes CashTokens in Bitcoin Cash (FT only).  */

final class BitcoinCashFCTModule extends UTXOFCTModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin-cash';
        $this->module = 'bitcoin-cash-fct';
        $this->is_main = false;
        $this->first_block_id = 792_773;
        $this->first_block_date = '2023-05-15';
    }
}
