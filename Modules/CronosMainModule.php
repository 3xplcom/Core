<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Cronos module.  */

final class CronosMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cronos';
        $this->module = 'cronos-main';
        $this->is_main = true;
        $this->currency = 'cronos';
        $this->currency_details = ['name' => 'Cronos', 'symbol' => 'CRO', 'decimals' => 18, 'description' => null];
        $this->first_block_id = 1;
        $this->first_block_date = '2021-11-08';

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FeesToTreasury];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
