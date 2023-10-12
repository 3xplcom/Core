<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Dash module. It requires Dash Core (https://github.com/dashpay/dash)
 *  with `txindex` set to true to run.  */

final class DashMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'dash';
        $this->module = 'dash-main';
        $this->is_main = true;
        $this->currency = 'dash';
        $this->currency_details = ['name' => 'Dash', 'symbol' => 'DASH', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2014-01-19';

        // UTXOMainModule
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '4c';
    }
}
