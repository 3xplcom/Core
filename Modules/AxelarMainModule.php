<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Axelar module. */

final class AxelarMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'axelar';
        $this->module = 'axelar-main';
        $this->is_main = true;
        $this->first_block_date = '2021-12-22';
        $this->currency = 'axelar';
        $this->currency_details = ['name' => 'Axelar', 'symbol' => 'AXL', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'axelar17xpfvakm2amg962yls6f84z3kell8c5l5h4gqu',
        ];
        $this->cosmos_known_denoms = ['uaxl' => 0];
        $this->cosmos_coin_events_fork = 0;
    }
}
