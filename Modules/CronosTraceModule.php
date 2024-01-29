<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*   This module processes internal Cronos transactions (using block tracing).  */

final class CronosTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cronos';
        $this->module = 'cronos-trace';
        $this->complements = 'cronos-main';
        $this->is_main = false;
        $this->first_block_id = 1;
        $this->first_block_date = '2021-11-08';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
