<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Celestia module. */

final class CelestiaIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'celestia';
        $this->module = 'celestia-ibc';
        $this->is_main = false;
        $this->first_block_id = 1;
        $this->first_block_date = '2023-10-31';

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        $this->cosmos_coin_events_fork = 0;
    }
}
