<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Sei module. */

final class SeiMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-main';
        $this->is_main = true;
        $this->first_block_date = '2022-05-28';
        $this->currency = 'sei';
        $this->currency_details = ['name' => 'Sei', 'symbol' => 'SEI', 'decimals' => 6, 'description' => null];

        // Cosmos-specific
        // Bench32 converted cosmos addresses
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu',
        ];
        $this->cosmos_known_denoms = ['usei' => 0];
        $this->cosmos_coin_events_fork = 0;
        $this->extra_features = [CosmosSpecialFeatures::HasNotCodeField, CosmosSpecialFeatures::HasNotFeeCollectorRecvEvent];

        $this->tests = [
            // Random block
            ['block' => 53277469, 'result' => 'a:2:{s:6:"events";a:10:{i:0;a:8:{s:11:"transaction";s:64:"2948d04ff5b31e9785b34491acdfc9226d2bd163bc477649101f44fb66ccb243";s:8:"sort_key";i:0;s:7:"address";s:42:"sei1umsz72jtj9n30hkehahhq9mfj5k53apv8s6hsy";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:1;a:8:{s:11:"transaction";s:64:"2948d04ff5b31e9785b34491acdfc9226d2bd163bc477649101f44fb66ccb243";s:8:"sort_key";i:1;s:7:"address";s:42:"sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:2;a:8:{s:11:"transaction";s:64:"bcaf2ba8609b9625cf443d917ae585c0dee79239fb39f47c6fc34199db89e20d";s:8:"sort_key";i:2;s:7:"address";s:42:"sei14n9fhykwk8rk7zln7rzd6uyhm2gzntuw2pv0e9";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:3;a:8:{s:11:"transaction";s:64:"bcaf2ba8609b9625cf443d917ae585c0dee79239fb39f47c6fc34199db89e20d";s:8:"sort_key";i:3;s:7:"address";s:42:"sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:4;a:8:{s:11:"transaction";s:64:"a1cfcfcb86880cafc7a601ed3fbfc3dd7808758654006906a7f28e61b3597a32";s:8:"sort_key";i:4;s:7:"address";s:42:"sei14gcl2gvj4y4zey5j2kpek25wg0f4ns7fpcd8c6";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:5;a:8:{s:11:"transaction";s:64:"a1cfcfcb86880cafc7a601ed3fbfc3dd7808758654006906a7f28e61b3597a32";s:8:"sort_key";i:5;s:7:"address";s:42:"sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:6;a:8:{s:11:"transaction";s:64:"fa47b43d3293bef00c9f78d84c0e3fdcb4f332e03607019a1f1cb8bc08bfff75";s:8:"sort_key";i:6;s:7:"address";s:42:"sei10qa0g0hsne0gk0lnc3y90cql4535cxa2yn9fcm";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:7;a:8:{s:11:"transaction";s:64:"fa47b43d3293bef00c9f78d84c0e3fdcb4f332e03607019a1f1cb8bc08bfff75";s:8:"sort_key";i:7;s:7:"address";s:42:"sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:8;a:8:{s:11:"transaction";s:64:"feae5f13fccc9eb275c48a1350d0f9341f139a3d7a12cb51b25114a02c0bf309";s:8:"sort_key";i:8;s:7:"address";s:42:"sei1ghjz5yxx0s73afsjnpyjas7l437h6fg0fazddn";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}i:9;a:8:{s:11:"transaction";s:64:"feae5f13fccc9eb275c48a1350d0f9341f139a3d7a12cb51b25114a02c0bf309";s:8:"sort_key";i:9;s:7:"address";s:42:"sei17xpfvakm2amg962yls6f84z3kell8c5la4jkdu";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:53277469;s:4:"time";s:19:"2024-01-22 12:07:14";}}s:10:"currencies";N;}']
        ];
    }
}
