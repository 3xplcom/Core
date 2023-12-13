<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Cosmos Hub module. */

final class CosmosHubIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cosmos-hub';
        $this->module = 'cosmos-hub-ibc';
        $this->is_main = false;
        $this->first_block_date = '2019-12-11';

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        // https://github.com/cosmos/cosmos-sdk/pull/8656
        $this->cosmos_coin_events_fork = 8695000;
    }
}
