<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes DIP2 Dash transactions. It requires Dash Core (https://github.com/dashpay/dash)
 *  with `txindex` set to true to run.  */

final class DashDIP2Module extends UTXODIP2Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'dash';
        $this->module = 'dash-dip-2';
        $this->is_main = false;
        $this->currency = 'dash';
        $this->currency_details = ['name' => 'Dash', 'symbol' => 'DASH', 'decimals' => 8, 'description' => null];
        $this->first_block_id = 1028160;
        $this->first_block_date = '2019-02-27';
    }
}
