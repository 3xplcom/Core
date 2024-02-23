<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Polygon zkEVM. It requires a geth node to run.  */

final class PolygonzkEVMERC721Module extends EVMERC721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon-zkevm';
        $this->module = 'polygon-zkevm-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-03-24';
        $this->first_block_id = 0;
        $this->mempool_implemented = true;

        // EVMTraceModule
        $this->extra_features = [EVMSpecialFeatures::zkEVM];

        // Tests
        $this->tests = [
            ['block' => 1992120, 'result' => 'a:2:{s:6:"events";a:8:{i:0;a:8:{s:11:"transaction";s:66:"0x32c3a978bf9e713b4394bddb09fe452d1a2c0ab4062128f26e51dccc42f74a3f";s:8:"currency";s:42:"0x4285a87412ef2d382f774ba12cfa8bb8e70e58be";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:2:"81";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:1;a:8:{s:11:"transaction";s:66:"0x32c3a978bf9e713b4394bddb09fe452d1a2c0ab4062128f26e51dccc42f74a3f";s:8:"currency";s:42:"0x4285a87412ef2d382f774ba12cfa8bb8e70e58be";s:7:"address";s:42:"0x984d99c7047dfb87dc0b81af279e32c3949d09cd";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:2:"81";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:2;a:8:{s:11:"transaction";s:66:"0x628fdd77a6d0bf0d3b95b06dd2d84087af44366bd8129a4239ce1ebbdff52730";s:8:"currency";s:42:"0x7dac480d20f322d2ef108a59a465ccb5749371c4";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:2;s:6:"effect";s:2:"-1";s:5:"extra";s:9:"230018550";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:3;a:8:{s:11:"transaction";s:66:"0x628fdd77a6d0bf0d3b95b06dd2d84087af44366bd8129a4239ce1ebbdff52730";s:8:"currency";s:42:"0x7dac480d20f322d2ef108a59a465ccb5749371c4";s:7:"address";s:42:"0x8dd214dc8a8160eca81f72e5c163e54b2c8973d9";s:8:"sort_key";i:3;s:6:"effect";s:1:"1";s:5:"extra";s:9:"230018550";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:4;a:8:{s:11:"transaction";s:66:"0x271b1772dfa3343df7d4242206d5ab2bdad471aa765230c6bf4697be25d23931";s:8:"currency";s:42:"0x5e0a2f84622bf77d26573638643f48a0fb75050a";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:4;s:6:"effect";s:2:"-1";s:5:"extra";s:2:"62";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:5;a:8:{s:11:"transaction";s:66:"0x271b1772dfa3343df7d4242206d5ab2bdad471aa765230c6bf4697be25d23931";s:8:"currency";s:42:"0x5e0a2f84622bf77d26573638643f48a0fb75050a";s:7:"address";s:42:"0x984d99c7047dfb87dc0b81af279e32c3949d09cd";s:8:"sort_key";i:5;s:6:"effect";s:1:"1";s:5:"extra";s:2:"62";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:6;a:8:{s:11:"transaction";s:66:"0x8611f5d4e7917b2ce465aa5fb1c6bd4ee0c594a2fb6f2054ba4bc0834540e683";s:8:"currency";s:42:"0xde0198e92781900860fa6f17f29d6896de64a05a";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:6;s:6:"effect";s:2:"-1";s:5:"extra";s:2:"90";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}i:7;a:8:{s:11:"transaction";s:66:"0x8611f5d4e7917b2ce465aa5fb1c6bd4ee0c594a2fb6f2054ba4bc0834540e683";s:8:"currency";s:42:"0xde0198e92781900860fa6f17f29d6896de64a05a";s:7:"address";s:42:"0x984d99c7047dfb87dc0b81af279e32c3949d09cd";s:8:"sort_key";i:7;s:6:"effect";s:1:"1";s:5:"extra";s:2:"90";s:5:"block";i:1992120;s:4:"time";s:19:"2024-02-23 09:07:47";}}s:10:"currencies";a:4:{i:0;a:3:{s:2:"id";s:42:"0x4285a87412ef2d382f774ba12cfa8bb8e70e58be";s:4:"name";s:10:"Ronaldinho";s:6:"symbol";s:2:"Ro";}i:1;a:3:{s:2:"id";s:42:"0x7dac480d20f322d2ef108a59a465ccb5749371c4";s:4:"name";s:20:"Merkly Hyperlane NFT";s:6:"symbol";s:5:"hMERK";}i:2;a:3:{s:2:"id";s:42:"0x5e0a2f84622bf77d26573638643f48a0fb75050a";s:4:"name";s:6:"Haland";s:6:"symbol";s:3:"hal";}i:3;a:3:{s:2:"id";s:42:"0xde0198e92781900860fa6f17f29d6896de64a05a";s:4:"name";s:8:"KaoZkevm";s:6:"symbol";s:2:"KZ";}}}'],
        ];
    }
}
