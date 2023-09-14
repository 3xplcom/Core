<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main Aptos transfers.  */

final class AptosMainModule extends AptosLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'aptos';
        $this->module = 'aptos-main';
        $this->is_main = true;
        $this->currency = 'aptos';
        $this->currency_details = ['name' => 'APT', 'symbol' => 'APT', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2022-10-12';
        $this->first_block_id = 0;
    }
}
