<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal BNB transactions (using block tracing). It requires an archival Erigon node to run.  */

final class BNBTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bnb';
        $this->module = 'bnb-trace';
        $this->complements = 'bnb-main';
        $this->is_main = false;
        $this->first_block_date = '2020-08-29';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::Erigon;
    }
}
