<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Rollux. It requires a geth node to run.  */

final class RolluxERC721Module extends EVMERC721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rollux';
        $this->module = 'rollux-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-06-21';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 9949101, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x4dde8ecfe4f88403569806ce257f3c919f30027f067f70221732d43b2fa5460c";s:8:"currency";s:42:"0x7c18839f857cb5e40e7c163cfab46e4f0dee210b";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:4:"3000";s:5:"block";i:9949101;s:4:"time";s:19:"2024-02-06 23:50:43";}i:1;a:8:{s:11:"transaction";s:66:"0x4dde8ecfe4f88403569806ce257f3c919f30027f067f70221732d43b2fa5460c";s:8:"currency";s:42:"0x7c18839f857cb5e40e7c163cfab46e4f0dee210b";s:7:"address";s:42:"0x5c3ba710db3710f2970bac40e98941c0ec8baac6";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:4:"3000";s:5:"block";i:9949101;s:4:"time";s:19:"2024-02-06 23:50:43";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0x7c18839f857cb5e40e7c163cfab46e4f0dee210b";s:4:"name";s:8:"MudAiSBT";s:6:"symbol";s:5:"MASBT";}}}'],
        ];
    }
}
