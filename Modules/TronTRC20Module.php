<?php
declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes TRC-20 token transfers in TRON. It requires java-tron node to run. */

final class TronTRC20Module extends TVMTRC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'tron';
        $this->module = 'tron-trc-20';
        $this->is_main = false;
        $this->first_block_date = '2018-06-25';
        $this->first_block_id = 0;
    }
}
