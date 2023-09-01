<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Base trace module. It requires a geth node to run.  */

final class BaseTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'base';
        $this->module = 'base-trace';
        $this->complements = 'base-main';
        $this->is_main = false;
        $this->first_block_date = '2023-06-15';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
