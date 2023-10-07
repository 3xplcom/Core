<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes the Aptos Coin (FT) transfers in Aptos Blockchain.  */

final class AptosCoinModule extends AptosLikeCoinModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'aptos';
        $this->module = 'aptos-coin';
        $this->is_main = false;
        $this->first_block_date = '2022-10-12';
        $this->first_block_id = 0;
    }
}
