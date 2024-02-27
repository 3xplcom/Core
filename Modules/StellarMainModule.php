<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Stellar transfers. It requires a Stellar node to run.  */

final class StellarMainModule extends StellarLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'stellar';
        $this->module = 'stellar-main';
        $this->is_main = true;
        $this->currency = 'stellar';
        $this->currency_details = ['name' => 'Stellar Lumens', 'symbol' => 'XLM', 'decimals' => 7, 'description' => null];
        $this->first_block_date = '2015-09-30';
        $this->first_block_id = 0;
    }
}
