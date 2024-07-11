<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Ethereum transactions (using block tracing). It requires an archival Erigon node to run.  */

final class BounceBitTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bouncebit';
        $this->module = 'bouncebit-trace';
        $this->complements = 'bouncebit-main';
        $this->is_main = false;
        $this->first_block_date = '2024-04-08';
        $this->first_block_id = 1;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
