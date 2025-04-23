<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Merlin module. It requires a geth node to run.  */

final class MerlinMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'merlin';
        $this->module = 'merlin-main';
        $this->is_main = true;
        $this->first_block_date = '2024-02-02';
        $this->first_block_id = 0;
        $this->currency = 'merlin-bitcoin';
        $this->currency_details = ['name' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 18, 'description' => null];    // https://docs.merlinchain.io/merlin-docs/developers/builder-guides/fees/gas#gas-token

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = []; // https://docs.merlinchain.io/merlin-docs/developers/builder-guides/fees
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
