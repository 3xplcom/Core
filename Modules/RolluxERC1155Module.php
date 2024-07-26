<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Rollux. It requires a geth node to run.  */

final class RolluxERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rollux';
        $this->module = 'rollux-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-06-21';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 14850046, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x051fb896c240aa190ddca3d7e88c1467435e876c6d193cbd9beb2346f86a6267";s:8:"currency";s:42:"0xca765f0ffda51b942d5f105f57b7110dc69214a0";s:7:"address";s:42:"0x84bcb98505cdd43ae1ffc86e3a0e803ffbdb803a";s:8:"sort_key";i:0;s:6:"effect";s:2:"-3";s:5:"extra";s:1:"1";s:5:"block";i:14850046;s:4:"time";s:19:"2024-05-30 10:35:33";}i:1;a:8:{s:11:"transaction";s:66:"0x051fb896c240aa190ddca3d7e88c1467435e876c6d193cbd9beb2346f86a6267";s:8:"currency";s:42:"0xca765f0ffda51b942d5f105f57b7110dc69214a0";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:1;s:6:"effect";s:1:"3";s:5:"extra";s:1:"1";s:5:"block";i:14850046;s:4:"time";s:19:"2024-05-30 10:35:33";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0xca765f0ffda51b942d5f105f57b7110dc69214a0";s:4:"name";s:19:"Rollux Arcade Token";s:6:"symbol";s:6:"ARCADE";}}}'],
        ];
    }
}
