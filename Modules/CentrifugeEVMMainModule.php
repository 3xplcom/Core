<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Centrifuge module to process the EVM transactions. */

final class CentrifugeEVMMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'centrifuge';
        $this->module = 'centrifuge-evm-main';
        $this->is_main = false;
        $this->first_block_date = '2022-03-12';
        $this->first_block_id = 3308248;
        $this->currency = 'centrifuge';
        $this->currency_details = ['name' => 'Centrifuge', 'symbol' => 'CFG', 'decimals' => 18, 'description' => null];

        // Extrinsic id has different format
        $this->transaction_hash_format = TransactionHashFormat::AlphaNumeric;

        // EVM-specific
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
