<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Ethereum module. It requires either a geth or an Erigon node to run (but the latter is much faster).  */

final class EthereumMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum';
        $this->module = 'ethereum-main';
        $this->is_main = true;
        $this->first_block_date = '2015-07-30';
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running geth, but this would be slower
        $this->extra_features = [EVMSpecialFeatures::HasOrHadUncles];
        $this->reward_function = function($block_id)
        {
            if ($block_id >= 0 && $block_id <= 4_369_999)
            {
                $base_reward = '5000000000000000000';
            }
            elseif ($block_id >= 4_370_000 && $block_id <= 7_279_999)
            {
                $base_reward = '3000000000000000000';
            }
            elseif ($block_id >= 7_280_000 && $block_id <= 15_537_393)
            {
                $base_reward = '2000000000000000000';
            }
            elseif ($block_id >= 15_537_394) // The Merge
            {
                $base_reward = '0';
            }

            return $base_reward;
        };
    }
}
