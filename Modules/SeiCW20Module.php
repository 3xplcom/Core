<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the CW20 Sei module. */

final class SeiCW20Module extends CosmosCW20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-cw-20';
        $this->is_main = false;
        $this->first_block_date = '2022-05-28';

        // Cosmos-specific
        $this->extra_features = [CosmosSpecialFeatures::HasNotCodeField];

        $this->tests = [
            // Transfer
            ['block' => 53278048, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:9:{s:11:"transaction";s:64:"42357306c1fb246eec9e56af422e5bd955c37db423e0a422493119d6f8867733";s:8:"sort_key";i:0;s:7:"address";s:42:"sei1es66ct72nnlfrez3w27ymhdy9cvx2s4amtttgx";s:8:"currency";s:62:"sei1nm5l32c209nfswsj4u07h38jrrk9cmx9sy36cq6rsxk2cqczjktq4h8cvc";s:6:"effect";s:14:"-6963203246398";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53278048;s:4:"time";s:19:"2024-01-22 12:11:18";}i:1;a:9:{s:11:"transaction";s:64:"42357306c1fb246eec9e56af422e5bd955c37db423e0a422493119d6f8867733";s:8:"sort_key";i:1;s:7:"address";s:42:"sei15x5289awv4ecc6k8u6k0kuqgdaej243g8vkqqt";s:8:"currency";s:62:"sei1nm5l32c209nfswsj4u07h38jrrk9cmx9sy36cq6rsxk2cqczjktq4h8cvc";s:6:"effect";s:13:"6963203246398";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53278048;s:4:"time";s:19:"2024-01-22 12:11:18";}}s:10:"currencies";a:1:{i:0;a:4:{s:2:"id";s:62:"sei1nm5l32c209nfswsj4u07h38jrrk9cmx9sy36cq6rsxk2cqczjktq4h8cvc";s:4:"name";s:14:"Tensa Zangetsu";s:6:"symbol";s:5:"TENSA";s:8:"decimals";s:1:"6";}}}']
        ];
    }
}
