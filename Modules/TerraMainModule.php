<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Terra module. */

final class TerraMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'terra';
        $this->module = 'terra-main';
        $this->is_main = true;
        $this->first_block_date = '2022-05-28';
        $this->currency = 'luna';
        $this->currency_details = ['name' => 'Luna', 'symbol' => 'LUNA', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        // Bench32 converted cosmos addresses
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'terra17xpfvakm2amg962yls6f84z3kell8c5lkaeqfa',
        ];
        $this->cosmos_known_denoms = ['uluna' => 0];
        $this->cosmos_coin_events_fork = 0;
        $this->extra_features = [CosmosSpecialFeatures::HasDecodedValues];
    }
}
