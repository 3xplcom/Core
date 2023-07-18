<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Gnosis Chain module. It requires either a Nethermind or an Erigon node to run (but the latter is much faster).  */

final class GnosisChainMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'gnosis-chain';
        $this->module = 'gnosis-chain-main';
        $this->is_main = true;
        $this->first_block_date = '2018-10-08';
        $this->first_block_id = 0;
        $this->currency = 'xdai';
        $this->currency_details = ['name' => 'xDAI', 'symbol' => 'xDAI', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running Nethermind, but this would be slower
        $this->extra_features = [EVMSpecialFeatures::EffectiveGasPriceCanBeZero];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
