<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Botanix transactions (using block tracing). It requires an archival geth node to run.  */

final class BotanixTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'botanix';
        $this->module = 'botanix-trace';
        $this->complements = 'botanix-main';
        $this->is_main = false;
        $this->first_block_date = '2023-11-22';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
