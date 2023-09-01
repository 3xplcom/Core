<?php declare(strict_types = 1); // by nikzh@nikzh.com for 3xpl.com, v.3.0.0

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes both transparent and shielded transfers. There are three special addresses for the shielded
 *  pools: `sprout-pool`, `sapling-pool`, `orchard-pool`. If all events for these addresses are summed up, one will get
 *  the amount of coins in these pools. This module requires the latest Zcash node version. If one was upgrading from
 *  some previous version, they'd need to use `reindex`.  */

final class ZcashMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'zcash';
        $this->module = 'zcash-main';
        $this->is_main = true;
        $this->currency = 'zcash';
        $this->currency_details = ['name' => 'Zcash', 'symbol' => 'ZEC', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2016-10-28';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::HasShieldedPools];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '1CB8';
    }
}
