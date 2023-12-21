<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes internal Fantom transactions (using block tracing). It requires an archival geth node to run.  */

final class FantomTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'fantom';
        $this->module = 'fantom-trace';
        $this->complements = 'fantom-main';
        $this->is_main = false;
        $this->first_block_date = '2019-12-27';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
