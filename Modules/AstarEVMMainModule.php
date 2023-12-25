<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Astar module to process the EVM transactions. */

final class AstarEVMMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'astar';
        $this->module = 'astar-evm-main';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';
        $this->currency = 'astar';
        $this->currency_details = ['name' => 'Astar', 'symbol' => 'ASTR', 'decimals' => 18, 'description' => null];

        // Extrinsic id has different format
        $this->transaction_hash_format = TransactionHashFormat::AlphaNumeric;

        // EVM-specific
        // TODO: checks params
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
