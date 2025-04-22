<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Mantle. It requires a geth node to run.  */

final class MantleERC721Module extends EVMERC721Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'mantle';
        $this->module = 'mantle-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-07-02';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 66730218, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:8:{s:11:"transaction";s:66:"0xa5548a905a5ec01bc5cf1320e87089b14d6ef6624d6d6ad084c33c641bb8f54f";s:8:"currency";s:42:"0x03ddc4b60d6bbf399a8397d73462060fdfb83476";s:7:"address";s:42:"0xe4ad52868492c281c206520569d90ce977223ab1";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:6:"882330";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}i:1;a:8:{s:11:"transaction";s:66:"0xa5548a905a5ec01bc5cf1320e87089b14d6ef6624d6d6ad084c33c641bb8f54f";s:8:"currency";s:42:"0x03ddc4b60d6bbf399a8397d73462060fdfb83476";s:7:"address";s:42:"0xe4b1b9eb079219d0e2931a396dd3bfab00d04501";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:6:"882330";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}i:2;a:8:{s:11:"transaction";s:66:"0x50665383bf825accbf8f792c92f8a4e4c6d143bb2be335a0b5bb4ed451aaf2cc";s:8:"currency";s:42:"0x1195cf65f83b3a5768f3c496d3a05ad6412c64b7";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:2;s:6:"effect";s:2:"-1";s:5:"extra";s:6:"210923";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}i:3;a:8:{s:11:"transaction";s:66:"0x50665383bf825accbf8f792c92f8a4e4c6d143bb2be335a0b5bb4ed451aaf2cc";s:8:"currency";s:42:"0x1195cf65f83b3a5768f3c496d3a05ad6412c64b7";s:7:"address";s:42:"0xcc7ee56ef12b3426215e125d4284cdd5f3877ede";s:8:"sort_key";i:3;s:6:"effect";s:1:"1";s:5:"extra";s:6:"210923";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}i:4;a:8:{s:11:"transaction";s:66:"0xc26d584e0553b5939984d4f818bdc8f3eda7b0b062971abe5c35c7b722477180";s:8:"currency";s:42:"0x1195cf65f83b3a5768f3c496d3a05ad6412c64b7";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:4;s:6:"effect";s:2:"-1";s:5:"extra";s:6:"210924";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}i:5;a:8:{s:11:"transaction";s:66:"0xc26d584e0553b5939984d4f818bdc8f3eda7b0b062971abe5c35c7b722477180";s:8:"currency";s:42:"0x1195cf65f83b3a5768f3c496d3a05ad6412c64b7";s:7:"address";s:42:"0x6b9967e4b0135948ba2ea871d1d8867417298065";s:8:"sort_key";i:5;s:6:"effect";s:1:"1";s:5:"extra";s:6:"210924";s:5:"block";i:66730218;s:4:"time";s:19:"2024-07-21 19:39:08";}}s:10:"currencies";a:2:{i:0;a:3:{s:2:"id";s:42:"0x03ddc4b60d6bbf399a8397d73462060fdfb83476";s:4:"name";s:21:"Pandra: CodeConqueror";s:6:"symbol";s:21:"Pandra: CodeConqueror";}i:1;a:3:{s:2:"id";s:42:"0x1195cf65f83b3a5768f3c496d3a05ad6412c64b7";s:4:"name";s:11:"Layer3 CUBE";s:6:"symbol";s:4:"CUBE";}}}'],
        ];
    }
}
