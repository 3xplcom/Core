<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Merlin. It requires a geth node to run.  */

final class MerlinERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'merlin';
        $this->module = 'merlin-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2024-02-02';
        $this->first_block_id = 0;

        $this->extra_features = [EVMSpecialFeatures::zkEVM];

        $this->tests = [
            ['block' => 1546384, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:8:"currency";s:42:"0x5e68be9a532eadf5edcbc2bec857d3d4b2e3aec5";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:1:"2";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";}i:1;a:8:{s:11:"transaction";s:66:"0x891e356287d20f100f61e02d9862cd6ee1e4b29f234ab4fa1972ca160e80f3ae";s:8:"currency";s:42:"0x5e68be9a532eadf5edcbc2bec857d3d4b2e3aec5";s:7:"address";s:42:"0xe0a5996cebbc85d5188d8f1e85ccff8c64f2dba3";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:1:"2";s:5:"block";i:1546384;s:4:"time";s:19:"2024-05-15 08:10:21";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0x5e68be9a532eadf5edcbc2bec857d3d4b2e3aec5";s:4:"name";s:0:"";s:6:"symbol";s:0:"";}}}'],
        ];
    }
}
