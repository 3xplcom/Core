<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Sei EVM module. */

final class SeiEVMMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei-evm';
        $this->module = 'sei-evm-main';
        $this->is_main = true;
        $this->first_block_date = '2024-01-25'; // This is for the devnet
        $this->first_block_id = 0;
        $this->currency = 'sei-evm';
        $this->currency_details = ['name' => 'Sei', 'symbol' => 'SEI', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FeesToTreasury];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
