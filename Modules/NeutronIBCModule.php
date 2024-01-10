<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Neutron module. */

final class NeutronIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'neutron';
        $this->module = 'neutron-ibc';
        $this->is_main = false;
        $this->first_block_date = '2019-11-12'; // TODO: need to check date on archive node

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        // TODO: need to check early blocks on archive node
        $this->cosmos_coin_events_fork = 0;
    }
}