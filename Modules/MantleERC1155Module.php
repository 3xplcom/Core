<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Mantle. It requires a geth node to run.  */

final class MantleERC1155Module extends EVMERC1155Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'mantle';
        $this->module = 'mantle-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-07-02';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 66812356, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0x54a4b4de8934ad2868107eaacc9e640d1fddfc0660ec8a338191af26adfc0f0e";s:8:"currency";s:42:"0x47cadd4d96bb9576801daea369e6e2e56fee0d19";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:1:"1";s:5:"block";i:66812356;s:4:"time";s:19:"2024-07-23 17:17:04";}i:1;a:8:{s:11:"transaction";s:66:"0x54a4b4de8934ad2868107eaacc9e640d1fddfc0660ec8a338191af26adfc0f0e";s:8:"currency";s:42:"0x47cadd4d96bb9576801daea369e6e2e56fee0d19";s:7:"address";s:42:"0x27076726568ccf183402b12b9edcdbfb79f38407";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:1:"1";s:5:"block";i:66812356;s:4:"time";s:19:"2024-07-23 17:17:04";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0x47cadd4d96bb9576801daea369e6e2e56fee0d19";s:4:"name";s:0:"";s:6:"symbol";s:0:"";}}}'],
        ];
    }
}
