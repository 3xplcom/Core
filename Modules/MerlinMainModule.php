<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Merlin module. It requires a geth node to run.  */

final class MerlinMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'merlin';
        $this->module = 'merlin-main';
        $this->is_main = true;
        $this->first_block_date = '2024-02-02';
        $this->first_block_id = 0;
        $this->currency = 'bitcoin';
        $this->currency_details = ['name' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 18, 'description' => null];    // https://docs.merlinchain.io/merlin-docs/developers/builder-guides/fees/gas#gas-token
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Merlin doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::zkEVM]; // https://docs.merlinchain.io/merlin-docs/developers/builder-guides/fees buy the docs they don't charge fees for L1, so prh, they don't have any system transaction 
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        $this->tests = [
            ['block' => 1546384, 'result' => 'a:2:{s:6:"events";a:10:{i:0;a:8:{s:11:"transaction";s:66:"0x585e896953f29b8c9844080cef0a680467299dc2c07fd20f72e551bcafa456a8";s:7:"address";s:42:"0x432c961e222fc3522fd31af85e84c6240ff0b46f";s:6:"effect";s:15:"-36030360000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:66:"0x585e896953f29b8c9844080cef0a680467299dc2c07fd20f72e551bcafa456a8";s:7:"address";s:42:"0xf73d921aa8f2dbe77adca8466127b392ede89dc9";s:6:"effect";s:14:"36030360000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:66:"0x585e896953f29b8c9844080cef0a680467299dc2c07fd20f72e551bcafa456a8";s:7:"address";s:42:"0x432c961e222fc3522fd31af85e84c6240ff0b46f";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:66:"0x585e896953f29b8c9844080cef0a680467299dc2c07fd20f72e551bcafa456a8";s:7:"address";s:42:"0x5ff137d4b0fdcd49dca30c7cf57e578a026d2789";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:7:"address";s:42:"0xe0a5996cebbc85d5188d8f1e85ccff8c64f2dba3";s:6:"effect";s:15:"-16712400000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:7:"address";s:42:"0xf73d921aa8f2dbe77adca8466127b392ede89dc9";s:6:"effect";s:14:"16712400000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:7:"address";s:42:"0xe0a5996cebbc85d5188d8f1e85ccff8c64f2dba3";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:7:"address";s:42:"0x5e68be9a532eadf5edcbc2bec857d3d4b2e3aec5";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:7;}i:8;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:8;}i:9;a:8:{s:11:"transaction";N;s:7:"address";s:42:"0xf73d921aa8f2dbe77adca8466127b392ede89dc9";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";s:8:"sort_key";i:9;}}s:10:"currencies";N;}'],
        ];
    }
}
