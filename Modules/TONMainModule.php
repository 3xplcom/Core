<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main TON transfers for the BaseChain. Special Node API by Blockchair is needed (see https://github.com/Blockchair).  */

final class TONMainModule extends TONLikeMainModule implements Module, BalanceSpecial
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

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(0|-1):[0-9a-fA-F]{64}|(EQ|UQ)[0-9a-zA-Z_\-]{46}/'; // wc:raw-hex or b64-bounceable (EQ..) or b64-non-bounceable (UQ..)
        $this->api_get_handle = function($handle)
        {
            $response = requester_single($this->select_node(),
                endpoint: 'account_serialize',
                params: [
                    'args' => [
                        $handle
                    ]
                ],
                timeout: $this->timeout);

            if (!array_key_exists('valid', $response) || !array_key_exists('base64-bounceable', $response))
                return null;

            if (!$response['valid'])
                return null;

            return $response['base64-bounceable'];
        };

        // Tests
        $this->tests = [
            // early low activity block with int and ext messages
            ['block' => 2000, 'result' => 'a:2:{s:6:"events";a:8:{i:0;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:48:"EQCIUuaay7U5Sum5NPtF0e3iAAAvcR_IkOdtMP8MPNiyscnY";s:6:"effect";s:8:"-5469072";s:5:"extra";s:1:"f";s:13:"extra_indexed";N;s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:8:"the-void";s:6:"effect";s:7:"5469072";s:5:"extra";s:1:"f";s:13:"extra_indexed";N;s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:8:"the-void";s:6:"effect";s:2:"-0";s:5:"extra";s:1:"e";s:13:"extra_indexed";N;s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:7:"address";s:48:"EQCIUuaay7U5Sum5NPtF0e3iAAAvcR_IkOdtMP8MPNiyscnY";s:6:"effect";s:1:"0";s:5:"extra";s:1:"e";s:13:"extra_indexed";N;s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:48:"EQC51IjX9oRE0R3mALFJMl_IPw2TEXQDuS3b5N5B9mMv_15k";s:6:"effect";s:8:"-5469072";s:5:"extra";s:1:"f";s:13:"extra_indexed";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:8:"the-void";s:6:"effect";s:7:"5469072";s:5:"extra";s:1:"f";s:13:"extra_indexed";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:48:"EQCIUuaay7U5Sum5NPtF0e3iAAAvcR_IkOdtMP8MPNiyscnY";s:6:"effect";s:17:"-1000000000000000";s:5:"extra";N;s:13:"extra_indexed";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";s:64:"c50629714cdad802b5e4f4372c293207b4de5801e8d0bfa7ea173bf0c04ba518";s:7:"address";s:48:"EQC51IjX9oRE0R3mALFJMl_IPw2TEXQDuS3b5N5B9mMv_15k";s:6:"effect";s:16:"1000000000000000";s:5:"extra";N;s:13:"extra_indexed";s:64:"b907dff4091671be391946fcd05de044283aa17e3fac9d92c87e85c96a5dacf7";s:5:"block";i:2000;s:4:"time";s:19:"2019-11-15 14:32:06";s:8:"sort_key";i:7;}}s:10:"currencies";N;}'],
        ];
    }
}
