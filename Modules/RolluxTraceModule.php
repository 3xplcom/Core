<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Rollux trace module. It requires a geth node to run.  */

final class RolluxTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rollux';
        $this->module = 'rollux-trace';
        $this->complements = 'rollux-main';
        $this->is_main = false;
        $this->first_block_date = '2023-06-21';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->tests = [
            ['block' => 16682478, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:66:"0x362532acd8e234ab0544ecf5cfcebdf475f877a3a656c3e009b90a31d09129d7";s:7:"address";s:42:"0x35ee5876db071b527dc62fd3ee3c32e4304d8c23";s:8:"sort_key";i:0;s:6:"effect";s:17:"-1000000000000000";s:5:"extra";N;s:5:"block";i:16682478;s:4:"time";s:19:"2024-07-11 20:36:37";}i:1;a:7:{s:11:"transaction";s:66:"0x362532acd8e234ab0544ecf5cfcebdf475f877a3a656c3e009b90a31d09129d7";s:7:"address";s:42:"0xa7e69de356b587ce22157234490612e5b20f29d3";s:8:"sort_key";i:1;s:6:"effect";s:16:"1000000000000000";s:5:"extra";N;s:5:"block";i:16682478;s:4:"time";s:19:"2024-07-11 20:36:37";}}s:10:"currencies";N;}']
        ];
    }
}
