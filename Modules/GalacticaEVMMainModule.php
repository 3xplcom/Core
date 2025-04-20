<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Galactica module. It requires a geth node to run.  */

final class GalacticaEVMMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'galactica-evm';
        $this->module = 'galactica-evm-main';
        $this->is_main = true;
        $this->first_block_date = '2024-04-08';
        $this->first_block_id = 0;
        $this->currency = 'gnet';
        $this->currency_details = ['name' => 'GNET', 'symbol' => 'GNET', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = []; 
        $this->reward_function = function($block_id)
        {
            return '0';
        };
    }
}
