<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Neutron module. */

final class NeutronMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'neutron';
        $this->module = 'neutron-main';
        $this->is_main = true;
        $this->first_block_date = '2023-10-11';
        $this->currency = 'neutron';
        $this->currency_details = ['name' => 'Neutron', 'symbol' => 'NTRN', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'neutron17xpfvakm2amg962yls6f84z3kell8c5l5x2z36',
        ];
        $this->cosmos_known_denoms = ['untrn' => 0];
        $this->cosmos_coin_events_fork = 0;
    }
}