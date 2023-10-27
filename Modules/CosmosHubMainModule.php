<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Cosmos Hub module. */

final class CosmosHubMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cosmoshub';
        $this->module = 'cosmoshub-main';
        $this->is_main = true;
        $this->first_block_date = '2019-11-12';
        $this->currency = 'atom';
        $this->currency_details = ['name' => 'Atom', 'symbol' => 'ATOM', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'cosmos17xpfvakm2amg962yls6f84z3kell8c5lserqta'
        ];
        $this->cosmos_known_denoms = ['uatom'];
        $this->cosmos_known_denoms_exp = [0];
        // https://github.com/cosmos/cosmos-sdk/pull/8656
        $this->cosmos_coin_events_fork = 8695000;
    }
}
