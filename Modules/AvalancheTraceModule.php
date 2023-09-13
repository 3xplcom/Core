<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Avalanche C-Chain trace module. It requires a geth node to run.  */

final class AvalancheTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'avalanche';
        $this->module = 'avalanche-trace';
        $this->complements = 'avalanche-main';
        $this->is_main = false;
        $this->first_block_date = '2015-07-30'; // That's for block #0, in reality it starts on 2020-09-23 with block #1... ¯\_(ツ)_/¯
        $this->first_block_id = 0;
        $this->forking_implemented = false; // All blocks are instantly finalized

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
