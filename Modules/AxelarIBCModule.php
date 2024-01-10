<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Axelar module. */

final class AxelarIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'axelar';
        $this->module = 'axelar-ibc';
        $this->is_main = false;
        $this->first_block_date = '2021-12-22';

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        $this->cosmos_coin_events_fork = 0;
    }
}
