<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-20 token transfers in Blast. It requires a geth node to run.  */

final class BlastERC20Module extends EVMERC20Module implements Module, MultipleBalanceSpecial, SupplySpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'blast';
        $this->module = 'blast-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2024-02-24';
        $this->first_block_id = 0;

        $this->tests = [
            ['block' => 6127912, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:7:{s:11:"transaction";s:66:"0xc085fccb448e9af99942dc2484f009246c2f82eea96b219aaa69a1d64b6d0598";s:8:"currency";s:42:"0x4300000000000000000000000000000000000003";s:7:"address";s:42:"0x5430561b09c627264549fdb3a6154c34f5cabea7";s:8:"sort_key";i:0;s:6:"effect";s:22:"-140011454880034479561";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:1;a:7:{s:11:"transaction";s:66:"0xc085fccb448e9af99942dc2484f009246c2f82eea96b219aaa69a1d64b6d0598";s:8:"currency";s:42:"0x4300000000000000000000000000000000000003";s:7:"address";s:42:"0x6a372dbc1968f4a07cf2ce352f410962a972c257";s:8:"sort_key";i:1;s:6:"effect";s:21:"140011454880034479561";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:2;a:7:{s:11:"transaction";s:66:"0xf70808a043c2ee7acf9a07a15bd77dddeadd8fc8d003c2c6a5212956b7a703bc";s:8:"currency";s:42:"0x113f0f20d851906b310abd1e5afba1639938347f";s:7:"address";s:42:"0x337827814155ecbf24d20231fca4444f530c0555";s:8:"sort_key";i:2;s:6:"effect";s:2:"-1";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:3;a:7:{s:11:"transaction";s:66:"0xf70808a043c2ee7acf9a07a15bd77dddeadd8fc8d003c2c6a5212956b7a703bc";s:8:"currency";s:42:"0x113f0f20d851906b310abd1e5afba1639938347f";s:7:"address";s:42:"0xe79fce9f014b4eebb156213407dbbd20ac61dd78";s:8:"sort_key";i:3;s:6:"effect";s:1:"1";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:4;a:7:{s:11:"transaction";s:66:"0xf70808a043c2ee7acf9a07a15bd77dddeadd8fc8d003c2c6a5212956b7a703bc";s:8:"currency";s:42:"0x113f0f20d851906b310abd1e5afba1639938347f";s:7:"address";s:42:"0x337827814155ecbf24d20231fca4444f530c0555";s:8:"sort_key";i:4;s:6:"effect";s:2:"-1";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}i:5;a:7:{s:11:"transaction";s:66:"0xf70808a043c2ee7acf9a07a15bd77dddeadd8fc8d003c2c6a5212956b7a703bc";s:8:"currency";s:42:"0x113f0f20d851906b310abd1e5afba1639938347f";s:7:"address";s:42:"0xb3a57ef4e5c5f12fa7d5a34d939ec3dbc82b994d";s:8:"sort_key";i:5;s:6:"effect";s:1:"1";s:5:"block";i:6127912;s:4:"time";s:19:"2024-07-15 17:47:19";}}s:10:"currencies";a:2:{i:0;a:4:{s:2:"id";s:42:"0x4300000000000000000000000000000000000003";s:4:"name";s:4:"USDB";s:6:"symbol";s:4:"USDB";s:8:"decimals";i:18;}i:1;a:4:{s:2:"id";s:42:"0x113f0f20d851906b310abd1e5afba1639938347f";s:4:"name";s:11:"OriginEther";s:6:"symbol";s:11:"tkether.org";s:8:"decimals";i:0;}}}'],
        ];
    }
}
