<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Botanix module. It requires either a geth node to run.  */

final class BotanixMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials, BroadcastTransactionSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'botanix';
        $this->module = 'botanix-main';
        $this->is_main = true;
        $this->first_block_date = '2023-11-22';
        $this->currency = 'botanix-bitcoin';
        $this->currency_details = ['name' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
