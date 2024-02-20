<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

final class RippleNFTModule extends RippleLikeNFTModule implements Module
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
            ['block' => 82834311, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:64:"a046ff9c68877853e46dac85df2e53ea174cbf38d856f4813ce29f46ff9abadd";s:7:"address";s:34:"rhTEeYTzZn3tL1BrYrxQtS6f5Jf83BYT5z";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000640FD1B918CE562578FE15FBF388ED724CA452B7C13F5C1000000754";s:5:"block";i:82834311;s:4:"time";s:19:"2023-09-27 19:18:21";}i:1;a:8:{s:11:"transaction";s:64:"a046ff9c68877853e46dac85df2e53ea174cbf38d856f4813ce29f46ff9abadd";s:7:"address";s:34:"rpZqTPC8GvrSvEfFsUuHkmPCg29GdQuXhC";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000640FD1B918CE562578FE15FBF388ED724CA452B7C13F5C1000000754";s:5:"block";i:82834311;s:4:"time";s:19:"2023-09-27 19:18:21";}}s:10:"currencies";N;}'],
            ['block' => 82477530, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:64:"7b34ae6e6a4c2b562a7de7e1dfe347b3a42195c1e30057ebaa872542f13d72c7";s:7:"address";s:34:"rhssY88ZGmw82A1wXnxxG6ayQgpH3WMnJg";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000214B5618CDD949616DE1F1217B6AC97B60C663F4DEFF4B2600000058";s:5:"block";i:82477530;s:4:"time";s:19:"2023-09-11 23:22:01";}i:1;a:8:{s:11:"transaction";s:64:"7b34ae6e6a4c2b562a7de7e1dfe347b3a42195c1e30057ebaa872542f13d72c7";s:7:"address";s:34:"r4hfwL6FmzfTsFKGctf4VJE9XwBafUmm2N";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:64:"00080000214B5618CDD949616DE1F1217B6AC97B60C663F4DEFF4B2600000058";s:5:"block";i:82477530;s:4:"time";s:19:"2023-09-11 23:22:01";}}s:10:"currencies";N;}'],
        ];
    }
}
