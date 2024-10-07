<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Scroll. It requires a geth node to run.  */

final class ScrollERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'scroll';
        $this->module = 'scroll-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-09-10';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 9781613, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x57ff329e870277cc4d96511369c613edba1fbc69244c20d831c1b42970afba75";s:8:"currency";s:42:"0xdc3d8318fbaec2de49281843f5bba22e78338146";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:1:"3";s:5:"block";i:9781613;s:4:"time";s:19:"2024-10-01 18:06:58";}i:1;a:8:{s:11:"transaction";s:66:"0x57ff329e870277cc4d96511369c613edba1fbc69244c20d831c1b42970afba75";s:8:"currency";s:42:"0xdc3d8318fbaec2de49281843f5bba22e78338146";s:7:"address";s:42:"0xb70aac8d62917b2cffbe7f4059ea9d64fc52f215";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:1:"3";s:5:"block";i:9781613;s:4:"time";s:19:"2024-10-01 18:06:58";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0xdc3d8318fbaec2de49281843f5bba22e78338146";s:4:"name";s:16:"Rubyscore_Scroll";s:6:"symbol";s:16:"Rubyscore_Scroll";}}}'],
        ];
    }
}
