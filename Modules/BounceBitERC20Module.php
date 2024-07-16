<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in BounceBit.  */

final class BounceBitERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bouncebit';
        $this->module = 'bouncebit-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2024-04-08';
        $this->first_block_id = 1;
        $this->tests = [
            ['block' => 150387, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";N;s:7:"address";s:4:"0x00";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372;s:4:"time";s:19:"2024-04-10 19:24:23";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";N;s:7:"address";s:8:"treasury";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"r";s:5:"block";i:2372;s:4:"time";s:19:"2024-04-10 19:24:23";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
            ['block' => 2369594, 'result' => 'a:2:{s:6:"events";a:4:{i:0;a:7:{s:11:"transaction";s:66:"0x5bec430459b9f2ad54f60dd691aa6f562aa7483e6ea4759cd5829282e953b296";s:8:"currency";s:42:"0x7f150c293c97172c75983bd8ac084c187107ea19";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:16:"-100000000000000";s:5:"block";i:2369594;s:4:"time";s:19:"2024-07-16 14:24:57";}i:1;a:7:{s:11:"transaction";s:66:"0x5bec430459b9f2ad54f60dd691aa6f562aa7483e6ea4759cd5829282e953b296";s:8:"currency";s:42:"0x7f150c293c97172c75983bd8ac084c187107ea19";s:7:"address";s:42:"0x8fbad0655df10e92cca0ecc48a902b170ac7b2b0";s:8:"sort_key";i:1;s:6:"effect";s:15:"100000000000000";s:5:"block";i:2369594;s:4:"time";s:19:"2024-07-16 14:24:57";}i:2;a:7:{s:11:"transaction";s:66:"0x5bec430459b9f2ad54f60dd691aa6f562aa7483e6ea4759cd5829282e953b296";s:8:"currency";s:42:"0xf5e11df1ebcf78b6b6d26e04ff19cd786a1e81dc";s:7:"address";s:42:"0x8fbad0655df10e92cca0ecc48a902b170ac7b2b0";s:8:"sort_key";i:2;s:6:"effect";s:16:"-100000000000000";s:5:"block";i:2369594;s:4:"time";s:19:"2024-07-16 14:24:57";}i:3;a:7:{s:11:"transaction";s:66:"0x5bec430459b9f2ad54f60dd691aa6f562aa7483e6ea4759cd5829282e953b296";s:8:"currency";s:42:"0xf5e11df1ebcf78b6b6d26e04ff19cd786a1e81dc";s:7:"address";s:42:"0x7f150c293c97172c75983bd8ac084c187107ea19";s:8:"sort_key";i:3;s:6:"effect";s:15:"100000000000000";s:5:"block";i:2369594;s:4:"time";s:19:"2024-07-16 14:24:57";}}s:10:"currencies";a:2:{i:0;a:4:{s:2:"id";s:42:"0x7f150c293c97172c75983bd8ac084c187107ea19";s:4:"name";s:18:"Liquid staked BBTC";s:6:"symbol";s:6:"stBBTC";s:8:"decimals";i:18;}i:1;a:4:{s:2:"id";s:42:"0xf5e11df1ebcf78b6b6d26e04ff19cd786a1e81dc";s:4:"name";s:13:"BounceBit BTC";s:6:"symbol";s:4:"BBTC";s:8:"decimals";i:18;}}}'],
        ];
    }
}
