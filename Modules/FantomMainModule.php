<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Fantom module. It requires a geth node to run.  */

final class FantomMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials, BroadcastTransactionSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'fantom';
        $this->module = 'fantom-main';
        $this->is_main = true;
        $this->first_block_date = '2019-12-27';
        $this->first_block_id = 0;
        $this->currency = 'fantom';
        $this->currency_details = ['name' => 'Fantom', 'symbol' => 'FTM', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::SpecialSenderPaysNoFee];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
