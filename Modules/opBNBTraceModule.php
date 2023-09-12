<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the opBNB trace module. It requires a geth node to run.  */

final class opBNBTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'opbnb';
        $this->module = 'opbnb-trace';
        $this->complements = 'opbnb-main';
        $this->is_main = false;
        $this->first_block_date = '2023-08-11';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
