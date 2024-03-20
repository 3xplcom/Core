<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the assets Liquid Bitcoin module.
 *  Using AssetRegistry: https://docs.liquid.net/docs/blockstream-liquid-asset-registry
 * */

final class LiquidAssetsModule extends UTXOLiquidAssetsModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'liquid';
        $this->module = 'liquid-assets';
        $this->is_main = false;
        $this->first_block_date = '2018-09-27';

        // Liquid-specific
        $this->ignore_sum_of_all_effects = true; // Cause of PrivacyModel::Mixed
        $this->native_asset = '6f0279e9ed041c3d710a9f57d0c02928416460c4b722ae3457a11eec381c526d';

        // Tests
        $this->tests = [
            ['block' => 2774298, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:64:"97d3a20cb85209167de9ff354be7a6b7f10506db35a7ca5dfeae81cdfce73433";s:8:"currency";s:64:"71e30c59c03e78d064757befa459ae7a0e9c4b8a5ae16cc90b3d70fc73b40ce4";s:7:"address";s:8:"the-void";s:6:"effect";s:10:"-101899500";s:5:"block";i:2774298;s:4:"time";s:19:"2024-03-18 20:38:08";s:8:"sort_key";i:0;}i:1;a:7:{s:11:"transaction";s:64:"97d3a20cb85209167de9ff354be7a6b7f10506db35a7ca5dfeae81cdfce73433";s:8:"currency";s:64:"71e30c59c03e78d064757befa459ae7a0e9c4b8a5ae16cc90b3d70fc73b40ce4";s:7:"address";s:42:"ex1qh59jvreqevgl2jwrx325zcp5lmdksaettgr62h";s:6:"effect";s:9:"101899500";s:5:"block";i:2774298;s:4:"time";s:19:"2024-03-18 20:38:08";s:8:"sort_key";i:1;}}s:10:"currencies";a:1:{i:0;a:4:{s:2:"id";s:64:"71e30c59c03e78d064757befa459ae7a0e9c4b8a5ae16cc90b3d70fc73b40ce4";s:4:"name";s:26:"Taxkredit project 68cbc66a";s:6:"symbol";s:5:"tWaZe";s:8:"decimals";s:1:"2";}}}'],
        ];
    }
}
