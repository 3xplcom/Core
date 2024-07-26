<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Rollux module. It requires a geth node to run.  */

final class RolluxMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rollux';
        $this->module = 'rollux-main';
        $this->is_main = true;
        $this->first_block_date = '2023-06-21';
        $this->first_block_id = 0;
        $this->currency = 'syscoin';
        $this->currency_details = ['name' => 'Syscoin', 'symbol' => 'SYS', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Rollux doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasSystemTransactions, EVMSpecialFeatures::EffectiveGasPriceCanBeZero, EVMSpecialFeatures::OPStack]; // Rollux is a fork of Optimism, so it has the same special txs
        $this->reward_function = function($block_id)
        {
            return '0';
        };
        
        $this->l1_fee_vault = '0x420000000000000000000000000000000000001A';
        $this->base_fee_recipient = '0x4200000000000000000000000000000000000019';

        $this->tests = [
            ['block' => 16725100, 'result' => 'a:2:{s:6:"events";a:12:{i:0;a:8:{s:11:"transaction";s:66:"0x38c04d90612a9aeb78366e5decf3189d01d0fe4bf5dda68dc677ad6f76c6e700";s:7:"address";s:42:"0xdeaddeaddeaddeaddeaddeaddeaddeaddead0001";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:66:"0x38c04d90612a9aeb78366e5decf3189d01d0fe4bf5dda68dc677ad6f76c6e700";s:7:"address";s:42:"0x4200000000000000000000000000000000000015";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x1241f44bfa102ab7386c784959bae3d0fb923734";s:6:"effect";s:8:"-1845267";s:6:"failed";s:1:"f";s:5:"extra";s:3:"l1f";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x420000000000000000000000000000000000001A";s:6:"effect";s:7:"1845267";s:6:"failed";s:1:"f";s:5:"extra";s:3:"l1f";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x1241f44bfa102ab7386c784959bae3d0fb923734";s:6:"effect";s:9:"-74983750";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x4200000000000000000000000000000000000019";s:6:"effect";s:8:"74983750";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x1241f44bfa102ab7386c784959bae3d0fb923734";s:6:"effect";s:16:"-149967500000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x4200000000000000000000000000000000000011";s:6:"effect";s:15:"149967500000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:7;}i:8;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0x1241f44bfa102ab7386c784959bae3d0fb923734";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:8;}i:9;a:8:{s:11:"transaction";s:66:"0xe6d1a85b81005383bb0db6119c0a35d83e2935ccb3c2979cd0fe63194b08de30";s:7:"address";s:42:"0xc1539b7084a9f793cbeada7382443dcdbc4d9add";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:9;}i:10;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:10;}i:11;a:8:{s:11:"transaction";N;s:7:"address";s:42:"0x4200000000000000000000000000000000000011";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:16725100;s:4:"time";s:19:"2024-07-12 20:17:21";s:8:"sort_key";i:11;}}s:10:"currencies";N;}'],
        ];
    }
}
