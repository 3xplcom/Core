<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Bitfinity (EVM-like L2 for Bitcoin) module.  */

final class BitfinityMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitfinity';
        $this->module = 'bitfinity-main';
        $this->is_main = true;
        $this->first_block_date = '2024-06-17';
        $this->currency = 'BITFINITY';
        $this->currency_details = ['name' => 'BITFINITY', 'symbol' => 'BFT', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [
            EVMSpecialFeatures::EffectiveGasPriceCanBeZeroOrNull,
        ];
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = false;
    }
}
