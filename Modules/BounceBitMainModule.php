<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main BounceBit module. */

final class BounceBitMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bouncebit';
        $this->module = 'bouncebit-main';
        $this->is_main = true;
        $this->first_block_date = '2024-04-08';
        $this->first_block_id = 1;
        $this->currency = 'bouncebit';
        $this->currency_details = ['name' => 'BounceBit', 'symbol' => 'BB', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FeesToTreasury];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = false;
    }
}
