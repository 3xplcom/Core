<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main opBNB module. It requires a geth node to run.  */

final class opBNBMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'opbnb';
        $this->module = 'opbnb-main';
        $this->is_main = true;
        $this->first_block_date = '2023-08-11';
        $this->first_block_id = 0;
        $this->currency = 'bnb';
        $this->currency_details = ['name' => 'BNB', 'symbol' => 'BNB', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->mempool_implemented = false;
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasSystemTransactions, EVMSpecialFeatures::OPStack];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        $this->l1_fee_vault = '0x420000000000000000000000000000000000001A';
        $this->base_fee_recipient = '0x4200000000000000000000000000000000000019';
    }
}
