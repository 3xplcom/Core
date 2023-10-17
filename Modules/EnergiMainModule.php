<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Energi module.  */

final class EnergiMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'energi';
        $this->module = 'energi-main';
        $this->is_main = true;
        $this->first_block_date = '2020-03-10';
        $this->currency = 'energi';
        $this->currency_details = ['name' => 'Energi', 'symbol' => 'NRG', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FeesToTreasury];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
