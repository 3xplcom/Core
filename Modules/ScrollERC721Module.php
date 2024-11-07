<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Scroll. It requires a geth node to run.  */

final class ScrollERC721Module extends EVMERC721Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'scroll';
        $this->module = 'scroll-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-09-10';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 9781504, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x9f4f1316a31467a657ea9ce6773f3e793a496d125250dba793d56a9ae50ff3cc";s:8:"currency";s:42:"0x5f64a9752228b60bf406c7b2ee77283da4b4c7ed";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:5:"13605";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:1;a:8:{s:11:"transaction";s:66:"0x9f4f1316a31467a657ea9ce6773f3e793a496d125250dba793d56a9ae50ff3cc";s:8:"currency";s:42:"0x5f64a9752228b60bf406c7b2ee77283da4b4c7ed";s:7:"address";s:42:"0x6fcb4542217424f459f0cbc2120cbb3947cf8e53";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:5:"13605";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0x5f64a9752228b60bf406c7b2ee77283da4b4c7ed";s:4:"name";s:12:"OmniHubNames";s:6:"symbol";s:3:"OHN";}}}'],
        ];
    }
}
