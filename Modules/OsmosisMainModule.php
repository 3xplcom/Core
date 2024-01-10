<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Osmosis module. */

final class OsmosisMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'osmosis';
        $this->module = 'osmosis-main';
        $this->is_main = true;
        $this->first_block_date = '2021-06-18';
        $this->currency = 'osmosis';
        $this->currency_details = ['name' => 'Osmosis', 'symbol' => 'OSMO', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        // Bench32 converted cosmos addresses
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'osmo17xpfvakm2amg962yls6f84z3kell8c5lczssa0',
        ];
        $this->cosmos_known_denoms = ['uosmo' => 0];
        $this->cosmos_coin_events_fork = 0;
        $this->extra_features = [CosmosSpecialFeatures::HasDoublesTxEvents];
    }
}
