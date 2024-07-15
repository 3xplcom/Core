<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Merlin. It requires a geth node to run.  */

final class MerlinERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'merlin';
        $this->module = 'merlin-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2024-02-02';
        $this->first_block_id = 0;

        $this->extra_features = [EVMSpecialFeatures::zkEVM];

        $this->tests = [
            ['block' => 1546383, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:66:"0x6615d37b5f1905b14bf581998e8f0d3dbb553c7de3c1f3a5b4ed5cc0d83eaeb5";s:8:"currency";s:42:"0x32a4b8b10222f85301874837f27f4c416117b811";s:7:"address";s:42:"0x3b248606bc8d7f7101c5a84c2e7b68628dc18aa2";s:8:"sort_key";i:0;s:6:"effect";s:12:"-88980600000";s:5:"block";i:1546383;s:4:"time";s:19:"2024-05-15 08:10:15";}i:1;a:7:{s:11:"transaction";s:66:"0x6615d37b5f1905b14bf581998e8f0d3dbb553c7de3c1f3a5b4ed5cc0d83eaeb5";s:8:"currency";s:42:"0x32a4b8b10222f85301874837f27f4c416117b811";s:7:"address";s:42:"0x778ff94195adde29a05a92b0e9031e44e2efdb8e";s:8:"sort_key";i:1;s:6:"effect";s:11:"88980600000";s:5:"block";i:1546383;s:4:"time";s:19:"2024-05-15 08:10:15";}}s:10:"currencies";a:1:{i:0;a:4:{s:2:"id";s:42:"0x32a4b8b10222f85301874837f27f4c416117b811";s:4:"name";s:8:"E•MOON";s:6:"symbol";s:26:"DOG•GO•TO•THE•MOON";s:8:"decimals";i:5;}}}'],
        ];
    }
}
