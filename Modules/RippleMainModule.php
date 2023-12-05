<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

final class RippleMainModule extends RippleLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'xrp-ledger';
        $this->module = 'xrp-ledger-main';
        $this->is_main = true;
        $this->currency = 'xrp';
        $this->currency_details = ['name' => 'XRP', 'symbol' => 'XRP', 'decimals' => 6, 'description' => null];
        $this->first_block_date = '2013-01-01';
        $this->first_block_id = 32570;
        $this->extra_data_details = RippleSpecialTransactions::to_assoc_array();

    }
}