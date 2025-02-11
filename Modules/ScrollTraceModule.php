<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Scroll trace module. It requires a geth node to run.  */

final class ScrollTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'scroll';
        $this->module = 'scroll-trace';
        $this->complements = 'scroll-main';
        $this->is_main = false;
        $this->first_block_date = '2023-09-10';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;

        $this->tests = [
            ['block' => 9781504, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:7:"address";s:42:"0x6c403dba21f072e16b7de2b013f8adeae9c2e76e";s:8:"sort_key";i:0;s:6:"effect";s:16:"-100000000000000";s:5:"extra";N;s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}i:1;a:7:{s:11:"transaction";s:66:"0x8185f8a7933e2476765c0cde11fcee641e7a0ec277a1e1074cc8db063c33436a";s:7:"address";s:42:"0x5300000000000000000000000000000000000004";s:8:"sort_key";i:1;s:6:"effect";s:15:"100000000000000";s:5:"extra";N;s:5:"block";i:9781504;s:4:"time";s:19:"2024-10-01 18:01:31";}}s:10:"currencies";N;}'],
        ];
    }
}
