<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Ethereum transactions (using block tracing). It requires an archival Erigon node to run.  */

final class BounceBitTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bouncebit';
        $this->module = 'bouncebit-trace';
        $this->complements = 'bouncebit-main';
        $this->is_main = false;
        $this->first_block_date = '2024-04-08';
        $this->first_block_id = 1;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->tests = [
            ['block' =>2374738, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:66:"0x4254a319a69c866eb701c01086ae23355eaa6a0c2510516f2c6bf46f98e205c9";s:7:"address";s:42:"0x3be72fcc9eaba1283744909b7b8260a4bc8ec956";s:8:"sort_key";i:0;s:6:"effect";s:21:"-83893757661856936691";s:5:"extra";N;s:5:"block";i:2374738;s:4:"time";s:19:"2024-07-16 19:32:06";}i:1;a:7:{s:11:"transaction";s:66:"0x4254a319a69c866eb701c01086ae23355eaa6a0c2510516f2c6bf46f98e205c9";s:7:"address";s:42:"0xd2f2e0cb8aa04235c868acbc9ac9782af0d21595";s:8:"sort_key";i:1;s:6:"effect";s:20:"83893757661856936691";s:5:"extra";N;s:5:"block";i:2374738;s:4:"time";s:19:"2024-07-16 19:32:06";}}s:10:"currencies";N;}'],
        ];
    }
}
