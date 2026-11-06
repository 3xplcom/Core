<?php declare(strict_types = 1); // by nikzh@nikzh.com for 3xpl.com, v.3.0.0

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes both transparent and shielded transfers. There are special addresses for the shielded pools
 *  (`sprout-pool`, `sapling-pool`, `orchard-pool`, `ironwood-pool`) and for the NU7 Network Sustainability Mechanism
 *  (`nsm-pool`). If all events for one of these addresses are summed up, one gets the amount of coins in that pool.
 *  This module requires the latest Zcash node version. If one was upgrading from some previous version, they'd need
 *  to use `reindex`.  */

final class ZcashMainModule extends UTXOMainModule implements Module, TransactionSpecials, SupplySpecial, BroadcastTransactionSpecial
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
        $this->extra_features = [UTXOSpecialFeatures::HasShieldedPools, UTXOSpecialFeatures::HasNetworkSustainabilityMechanism];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '1CB8';
        $this->nsm_activation_height = 3_543_405;
        $this->nsm_initial_balance = '36858445520';
        $this->nsm_reissuance_height = 8_939_664;
        $this->nsm_reissuance_numerator = '1375';
        $this->nsm_reissuance_denominator = '10000000000';
    }
}
