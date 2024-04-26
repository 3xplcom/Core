<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main TON transfers for the BaseChain. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

final class TONMainModule extends TONLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ton';
        $this->module = 'ton-main';
        $this->is_main = true;
        $this->currency = 'ton';
        $this->currency_details = ['name' => 'Toncoin', 'symbol' => 'TON', 'decimals' => 9, 'description' => null];
        $this->first_block_date = '2019-11-15';
        $this->first_block_id = 1;

        // TONLikeMainModule
        $this->workchain = '0'; // BaseChain

        // Tests
        $this->tests = [
            // first block, must be empty
            ['block' => 1, 'result' => 'a:2:{s:6:"events";a:0:{}s:10:"currencies";N;}'],
            // early low activity block with int and ext messages
            ['block' => 2000, 'result' => 'a:2:{s:6:"events";a:8:{i:0;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:64:"8852e69acbb5394ae9b934fb45d1ede200002f711fc890e76d30ff0c3cd8b2b1";s:6:"effect";s:8:"-5469072";s:5:"extra";s:3:"fee";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:8:"the-void";s:6:"effect";s:7:"5469072";s:5:"extra";s:3:"fee";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:8:"the-void";s:6:"effect";s:2:"-0";s:5:"extra";s:3:"ext";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:64:"8852e69acbb5394ae9b934fb45d1ede200002f711fc890e76d30ff0c3cd8b2b1";s:6:"effect";s:1:"0";s:5:"extra";s:3:"ext";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:64:"b9d488d7f68444d11de600b149325fc83f0d93117403b92ddbe4de41f6632fff";s:6:"effect";s:2:"-0";s:5:"extra";s:3:"fee";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:8:"the-void";s:6:"effect";s:1:"0";s:5:"extra";s:3:"fee";s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:64:"8852e69acbb5394ae9b934fb45d1ede200002f711fc890e76d30ff0c3cd8b2b1";s:6:"effect";s:17:"-1000000000000000";s:5:"extra";N;s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:64:"b9d488d7f68444d11de600b149325fc83f0d93117403b92ddbe4de41f6632fff";s:6:"effect";s:16:"1000000000000000";s:5:"extra";N;s:13:"extra_indexed";s:25:"(0,a000000000000000,2013)";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:7;}}s:10:"currencies";N;}'],
        ];
    }
}
