<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Linea module. It requires a geth node to run.  */

final class LineaMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'linea';
        $this->module = 'linea-main';
        $this->is_main = true;
        $this->first_block_date = '2023-07-06';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = true;

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::NoEIP1559BurnFee];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
