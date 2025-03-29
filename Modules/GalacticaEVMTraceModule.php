<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Galactica transactions (using block tracing). It requires an archival geth node to run.  */

final class GalacticaEVMTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'galactica-evm';
        $this->module = 'galactica-evm-trace';
        $this->complements = 'galactica-evm-main';
        $this->is_main = false;
        $this->first_block_date = '2024-04-08';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
