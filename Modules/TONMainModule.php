<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main TON transfers for the BaseChain. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

final class TONMainModule extends TONLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ton';
        $this->module = 'ton-main';
        $this->is_main = true;
        $this->currency = 'ton';
        $this->currency_details = ['name' => 'TON', 'symbol' => '💎', 'decimals' => 9, 'description' => null];
        $this->first_block_date = '2015-07-30';
        $this->first_block_id = 0;

        // TONLikeMainModule
        $this->workchain = '0'; // BaseChain
    }
}
