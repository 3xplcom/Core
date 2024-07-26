<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Bitfinity transactions (using block tracing). It requires an archival node to run.  */

final class BitfinityTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitfinity';
        $this->module = 'bitfinity-trace';
        $this->complements = 'bitfinity-main';
        $this->is_main = false;
        $this->first_block_date = '2024-06-17';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
