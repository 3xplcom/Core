<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Scroll. It requires a geth node to run.  */

final class ScrollERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'scroll';
        $this->module = 'scroll-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2023-09-10';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 9781504, 'result' => 'a:2:{s:6:"events";a:8:{i:0;a:7:{s:11:"transaction";s:66:"0x230344f4bad4f6576ebbbe0948e7fb312415b65c3d8079e353289dc2ae90b120";s:8:"currency";s:42:"0x80137510979822322193fc997d400d5a6c747bf7";s:7:"address";s:42:"0x9a412fe6d0a0ff7a0e1b82982d64ede57877f705";s:8:"sort_key";i:0;s:6:"effect";s:17:"-9882841257127068";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:1;a:7:{s:11:"transaction";s:66:"0x230344f4bad4f6576ebbbe0948e7fb312415b65c3d8079e353289dc2ae90b120";s:8:"currency";s:42:"0x80137510979822322193fc997d400d5a6c747bf7";s:7:"address";s:42:"0xaaaaaaaacb71bf2c8cae522ea5fa455571a74106";s:8:"sort_key";i:1;s:6:"effect";s:16:"9882841257127068";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:2;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x5300000000000000000000000000000000000004";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:2;s:6:"effect";s:16:"-100000000000000";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:3;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x5300000000000000000000000000000000000004";s:7:"address";s:42:"0x6c403dba21f072e16b7de2b013f8adeae9c2e76e";s:8:"sort_key";i:3;s:6:"effect";s:15:"100000000000000";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:4;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x06efdbff2a14a7c8e15944d1f4a48f9f95f663a4";s:7:"address";s:42:"0x9e59ecd8d3891afa9d9b9d6562653d5be720cd17";s:8:"sort_key";i:4;s:6:"effect";s:7:"-250716";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:5;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x06efdbff2a14a7c8e15944d1f4a48f9f95f663a4";s:7:"address";s:42:"0x020f3db4ac970c8199144cda9fe6129439e02650";s:8:"sort_key";i:5;s:6:"effect";s:6:"250716";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:6;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x5300000000000000000000000000000000000004";s:7:"address";s:42:"0x6c403dba21f072e16b7de2b013f8adeae9c2e76e";s:8:"sort_key";i:6;s:6:"effect";s:16:"-100000000000000";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:7;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:8:"currency";s:42:"0x5300000000000000000000000000000000000004";s:7:"address";s:42:"0x9e59ecd8d3891afa9d9b9d6562653d5be720cd17";s:8:"sort_key";i:7;s:6:"effect";s:15:"100000000000000";s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}}s:10:"currencies";a:3:{i:0;a:4:{s:2:"id";s:42:"0x80137510979822322193fc997d400d5a6c747bf7";s:4:"name";s:16:"StakeStone Ether";s:6:"symbol";s:5:"STONE";s:8:"decimals";i:18;}i:1;a:4:{s:2:"id";s:42:"0x5300000000000000000000000000000000000004";s:4:"name";s:13:"Wrapped Ether";s:6:"symbol";s:4:"WETH";s:8:"decimals";i:18;}i:2;a:4:{s:2:"id";s:42:"0x06efdbff2a14a7c8e15944d1f4a48f9f95f663a4";s:4:"name";s:8:"USD Coin";s:6:"symbol";s:4:"USDC";s:8:"decimals";i:6;}}}'],
        ];
    }
}
