<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main zkSync module. It requires a geth node to run.  */

final class zkSyncEraMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials, BroadcastTransactionSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'zksync-era';
        $this->module = 'zksync-era-main';
        $this->is_main = true;
        $this->first_block_date = '2023-02-15';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false;

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::EffectiveGasPriceCanBeZero];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
        $this->tests = [
            ['block' => 53356379, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:8:{s:11:"transaction";s:66:"0xd3086b71c95ce83e7f3d30ab1890ada2334695a05b65715e56f42d96b22c8674";s:7:"address";s:42:"0x0000000000000000000000000000000000008007";s:6:"effect";s:15:"-63717656250000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:66:"0xd3086b71c95ce83e7f3d30ab1890ada2334695a05b65715e56f42d96b22c8674";s:7:"address";s:4:"0x00";s:6:"effect";s:14:"63717656250000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:66:"0xd3086b71c95ce83e7f3d30ab1890ada2334695a05b65715e56f42d96b22c8674";s:7:"address";s:42:"0x0000000000000000000000000000000000008007";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:66:"0xd3086b71c95ce83e7f3d30ab1890ada2334695a05b65715e56f42d96b22c8674";s:7:"address";s:42:"0x0000000000000000000000000000000000008006";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";N;s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:53356379;s:4:"time";s:19:"2025-01-10 14:25:13";s:8:"sort_key";i:5;}}s:10:"currencies";N;}'],
        ];
    }
}
