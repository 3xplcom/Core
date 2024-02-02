<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Cronos POS module. */

final class CronosPOSIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cronos-pos';
        $this->module = 'cronos-pos-ibc';
        $this->is_main = false;
        $this->first_block_id = 1;
        $this->first_block_date = '2021-03-25';

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        // since: https://github.com/crypto-org-chain/chain-main/releases/tag/v3.0.0
        // Cronos POS supports SDK 0.43.0
        $this->cosmos_coin_events_fork = 3526800;
    }
}
