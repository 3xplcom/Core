<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module process Staking and Rewards for Cardano chain, specifically post-Shelley activity (epoch 210 and onwards)
 *  Requires a fully synced `input-output-hk/cardano-db-sync` database to operate. 
 *  Database schema for querying:
 *  https://github.com/input-output-hk/cardano-db-sync/blob/master/doc/schema.md  */

final class CardanoRewardsModule extends CardanoLikeRewardsModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cardano';
        $this->module = 'cardano-rewards';
        $this->is_main = false;
        $this->currency = 'cardano';
        $this->currency_details = ['name' => 'Cardano', 'symbol' => 'ADA', 'decimals' => 6, 'description' => null];
        $this->first_block_id = 4533637;
        $this->first_block_date = '2020-08-09';
    }
}
