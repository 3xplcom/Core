<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Rootstock transactions (using block tracing). */

final class RootstockTraceModule extends RSKTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'rootstock';
        $this->module = 'rootstock-trace';
        $this->complements = 'rootstock-main';
        $this->is_main = false;
        $this->first_block_date = '2018-01-02';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
