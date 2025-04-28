<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with NFT in Ripple. Requires a Ripple node.  */

final class RippleNFTModule extends RippleLikeNFTModule implements Module, BalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'xrp-ledger';
        $this->module = 'xrp-ledger-nft';
        $this->is_main = false;
        $this->currency = 'xrp-nft';
        $this->currency_details = ['name' => 'XRP NFT', 'symbol' => 'NFT', 'decimals' => 0, 'description' => null];
        $this->first_block_date = '2013-01-01';
        $this->first_block_id = 32570;

        $this->tests = [
        /*NFTokenAcceptOffer*/          ['block' => 86727556, 'result' => 'a:2:{s:6:"events";a:4:{i:0;a:8:{s:11:"transaction";s:64:"356de89390ebb12e621d6ae8ef89f1a4b6184013eb8e94ab84f2a1d8ab9ed3e6";s:7:"address";s:34:"rUU4dn8yMXYRzD59AHqpkQXoPE6GfXaX5i";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"000813887AD00228AFC1C2CD67791597449BC2E6B9AD99CD546CCCDB0521FAD3";s:5:"block";i:86727556;s:4:"time";s:19:"2024-03-19 19:51:40";}i:1;a:8:{s:11:"transaction";s:64:"356de89390ebb12e621d6ae8ef89f1a4b6184013eb8e94ab84f2a1d8ab9ed3e6";s:7:"address";s:34:"rUmmkSsdCYpHy1XHHTwBAhKKnaeWQgcGoo";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"000813887AD00228AFC1C2CD67791597449BC2E6B9AD99CD546CCCDB0521FAD3";s:5:"block";i:86727556;s:4:"time";s:19:"2024-03-19 19:51:40";}i:2;a:8:{s:11:"transaction";s:64:"8700587adb12dedd106324f4fdbf18ca6e85d7adba9d7058be22eab2c310c624";s:7:"address";s:34:"rfx2mVhTZzc6bLXKeYyFKtpha2LHrkNZFT";s:8:"sort_key";i:2;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00081B582A24B5A29CB415BF89A68FC2C44E148DB61D10A633A7821D05022781";s:5:"block";i:86727556;s:4:"time";s:19:"2024-03-19 19:51:40";}i:3;a:8:{s:11:"transaction";s:64:"8700587adb12dedd106324f4fdbf18ca6e85d7adba9d7058be22eab2c310c624";s:7:"address";s:34:"r4zJ9FjK9oP4imstea8gjVx6Kmc58GvHJA";s:8:"sort_key";i:3;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00081B582A24B5A29CB415BF89A68FC2C44E148DB61D10A633A7821D05022781";s:5:"block";i:86727556;s:4:"time";s:19:"2024-03-19 19:51:40";}}s:10:"currencies";N;}'],
        /*NFTokenBurn, NFTokenMint*/    ['block' => 87252395, 'result' => 'a:2:{s:6:"events";a:4:{i:0;a:8:{s:11:"transaction";s:64:"4c78def9ea7028768299c987d63d05104a316aeed97b2564a4bade7c6c69ee0a";s:7:"address";s:8:"the-void";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000413DEC6E282BBCEA55B71140D0CB35F01673BBE02C27385104AC98B6";s:5:"block";i:87252395;s:4:"time";s:19:"2024-04-12 05:10:42";}i:1;a:8:{s:11:"transaction";s:64:"4c78def9ea7028768299c987d63d05104a316aeed97b2564a4bade7c6c69ee0a";s:7:"address";s:34:"raAyazbgEkwzLByXipQuPLWFfnsPS1v1q9";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000413DEC6E282BBCEA55B71140D0CB35F01673BBE02C27385104AC98B6";s:5:"block";i:87252395;s:4:"time";s:19:"2024-04-12 05:10:42";}i:2;a:8:{s:11:"transaction";s:64:"b1331eaab15fcec6bf5645f1a8a29f2fd2d270c3a81e900f7b3e7eb3a8d89af9";s:7:"address";s:34:"rpS1epLNv2Vg4mPjVoJaR8exegzqq4UMHB";s:8:"sort_key";i:2;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"0008138861554D5FC1D746D61B369DF847546C6AFFDF71FBE14961DF000004D8";s:5:"block";i:87252395;s:4:"time";s:19:"2024-04-12 05:10:42";}i:3;a:8:{s:11:"transaction";s:64:"b1331eaab15fcec6bf5645f1a8a29f2fd2d270c3a81e900f7b3e7eb3a8d89af9";s:7:"address";s:8:"the-void";s:8:"sort_key";i:3;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"0008138861554D5FC1D746D61B369DF847546C6AFFDF71FBE14961DF000004D8";s:5:"block";i:87252395;s:4:"time";s:19:"2024-04-12 05:10:42";}}s:10:"currencies";N;}'],
            ['block' => 82477530, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:64:"7b34ae6e6a4c2b562a7de7e1dfe347b3a42195c1e30057ebaa872542f13d72c7";s:7:"address";s:34:"rhssY88ZGmw82A1wXnxxG6ayQgpH3WMnJg";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000214B5618CDD949616DE1F1217B6AC97B60C663F4DEFF4B2600000058";s:5:"block";i:82477530;s:4:"time";s:19:"2023-09-11 23:22:01";}i:1;a:8:{s:11:"transaction";s:64:"7b34ae6e6a4c2b562a7de7e1dfe347b3a42195c1e30057ebaa872542f13d72c7";s:7:"address";s:34:"r4hfwL6FmzfTsFKGctf4VJE9XwBafUmm2N";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000214B5618CDD949616DE1F1217B6AC97B60C663F4DEFF4B2600000058";s:5:"block";i:82477530;s:4:"time";s:19:"2023-09-11 23:22:01";}}s:10:"currencies";N;}'],
            ['block' => 87249376, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:64:"371a4a5f0d4f446f5a41661c937eba74c50afd99b716ab345158325bb6e6fd7d";s:7:"address";s:34:"rJcCJyJkiTXGcxU4Lt4ZvKJz8YmorZXu8r";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00081F40609A31E3C78F708610A91730BB8315A5341355EC173DF7E504ED27E6";s:5:"block";i:87249376;s:4:"time";s:19:"2024-04-12 01:57:31";}i:1;a:8:{s:11:"transaction";s:64:"371a4a5f0d4f446f5a41661c937eba74c50afd99b716ab345158325bb6e6fd7d";s:7:"address";s:34:"rffedbARyZzJsW6CM1D81V3KGreJ4F3ZE3";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00081F40609A31E3C78F708610A91730BB8315A5341355EC173DF7E504ED27E6";s:5:"block";i:87249376;s:4:"time";s:19:"2024-04-12 01:57:31";}}s:10:"currencies";N;}'],
        ];
    }
}
