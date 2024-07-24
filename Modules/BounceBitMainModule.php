<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main BounceBit module. */

final class BounceBitMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
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
        $this->tests = [
            ['block' => 1, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:1;s:4:"time";s:19:"2024-04-08 06:43:00";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";N;s:7:"address";s:8:"treasury";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:1;s:4:"time";s:19:"2024-04-08 06:43:00";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
            ['block' => 2372, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372;s:4:"time";s:19:"2024-04-10 19:24:23";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";N;s:7:"address";s:8:"treasury";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372;s:4:"time";s:19:"2024-04-10 19:24:23";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
            ['block' => 2372111, 'result' => 'a:2:{s:6:"events";a:8:{i:0;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:42:"0x503c2a26bf64294d7955c929127a29847f7db57a";s:6:"effect";s:16:"-246680000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:4:"0x00";s:6:"effect";s:15:"246680000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:42:"0x503c2a26bf64294d7955c929127a29847f7db57a";s:6:"effect";s:15:"-30835000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:8:"treasury";s:6:"effect";s:14:"30835000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:42:"0x503c2a26bf64294d7955c929127a29847f7db57a";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:66:"0x5c9d15c0918dfd621b4e0e54a70d84c4d1ecacf36cde6190353defe861c24dde";s:7:"address";s:42:"0xc226ff4526afee15d32665f3ef3b291ac1e0c731";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";N;s:7:"address";s:8:"treasury";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372111;s:4:"time";s:19:"2024-07-16 16:56:29";s:8:"sort_key";i:7;}}s:10:"currencies";N;}'],
        ];
    }
}
