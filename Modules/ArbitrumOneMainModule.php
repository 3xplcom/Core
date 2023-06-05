<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Arbitrum One module. It requires a geth node to run.  */

final class ArbitrumOneMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'arbitrum-one';
        $this->module = 'arbitrum-one-main';
        $this->is_main = true;
        $this->first_block_date = '2021-05-28';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::AllowEmptyRecipient];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
