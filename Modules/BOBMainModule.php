<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main BOB module. It requires a geth node to run.  */

final class BOBMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bob';
        $this->module = 'bob-main';
        $this->is_main = true;
        $this->first_block_date = '2024-04-11';
        $this->first_block_id = 0;
        $this->currency = 'bob-bitcoin';
        $this->currency_details = ['name' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::EffectiveGasPriceCanBeZero];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
