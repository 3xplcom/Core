<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Blast. It requires a geth node to run.  */

final class BlastERC721Module extends EVMERC721Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'blast';
        $this->module = 'blast-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2024-02-24';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 6127912, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0xf0f4f280b3c63f45c5b42b4084a1bc7ebcaa6b582d53c85ca84a2814a880382a";s:8:"currency";s:42:"0x4c1bb1e30f500f6fafeb1809fb572290029463da";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:5:"62752";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:1;a:8:{s:11:"transaction";s:66:"0xf0f4f280b3c63f45c5b42b4084a1bc7ebcaa6b582d53c85ca84a2814a880382a";s:8:"currency";s:42:"0x4c1bb1e30f500f6fafeb1809fb572290029463da";s:7:"address";s:42:"0xe325bca338951f94a0de6f1677638f15cb578d29";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:5:"62752";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0x4c1bb1e30f500f6fafeb1809fb572290029463da";s:4:"name";s:4:"seng";s:6:"symbol";s:5:"senga";}}}'],
        ];
    }
}
