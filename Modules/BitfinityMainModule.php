<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Bitfinity (EVM-like L2 for Bitcoin) module.  */

final class BitfinityMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
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

        // Tests
        $this->tests = [
            ['block' => 0, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:0;s:4:"time";s:19:"2024-06-17 14:04:47";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";N;s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:0;s:4:"time";s:19:"2024-06-17 14:04:47";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
            ['block' => 583440, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:8:{s:11:"transaction";s:66:"0x767378c389899012ee2052ac43a62af9a18cbe0150ea4243d2dcd9a8728931f9";s:7:"address";s:42:"0xba819e618e7fb8edc2d83763d97b96d94281dc44";s:6:"effect";s:7:"-147000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:66:"0x767378c389899012ee2052ac43a62af9a18cbe0150ea4243d2dcd9a8728931f9";s:7:"address";s:4:"0x00";s:6:"effect";s:6:"147000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:66:"0x767378c389899012ee2052ac43a62af9a18cbe0150ea4243d2dcd9a8728931f9";s:7:"address";s:42:"0xba819e618e7fb8edc2d83763d97b96d94281dc44";s:6:"effect";s:12:"-10000000000";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:66:"0x767378c389899012ee2052ac43a62af9a18cbe0150ea4243d2dcd9a8728931f9";s:7:"address";s:42:"0xc0f8c3ec1b30933a7b7e7df4dfa49324b9598ea9";s:6:"effect";s:11:"10000000000";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";N;s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:583440;s:4:"time";s:19:"2024-07-09 15:38:39";s:8:"sort_key";i:5;}}s:10:"currencies";N;}']
        ];
    }
}
