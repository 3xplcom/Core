<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Ethereum Classic module. It requires either a geth node to run. Please note that `status` is
 *  not available on geth for some older transactions when requesting receipts and a special fix is required,
 *  see https://github.com/3xplcom/ethereum-classic  */

final class EthereumClassicMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum-classic';
        $this->module = 'ethereum-classic-main';
        $this->is_main = true;
        $this->first_block_date = '2015-07-30';
        $this->currency = 'ethereum-classic';
        $this->currency_details = ['name' => 'Ethereum Classic', 'symbol' => 'ETC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasOrHadUncles];
        $this->reward_function = function($block_id)
        {
            // https://ecips.ethereumclassic.org/ECIPs/ecip-1017
            // https://ethereumclassic.org/why-classic/sound-money#known-future-supply

            $base_reward = '5000000000000000000';
            $reductions = intdiv($block_id - 1, 5_000_000);

            for ($i = 0; $i < $reductions; $i++)
                $base_reward = bcmul($base_reward, '0.8');

            return $base_reward;
        };
    }
}
