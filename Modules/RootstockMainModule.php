<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Rootstock module.  */

final class RootstockMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rootstock';
        $this->module = 'rootstock-main';
        $this->is_main = true;
        $this->first_block_date = '2018-01-02';
        $this->currency = 'smart-bitcoin';
        $this->currency_details = ['name' => 'Smart Bitcoin', 'symbol' => 'RBTC', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::rskEVM];
        $this->reward_function = function ($block_id) {
            return '0';
        };
    }
}
