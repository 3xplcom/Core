<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the Arbitrum One trace module. It requires a geth node to run with new blocks, and a legacy ("classic") geth node
 *  to populate the database with blocks up to #22207816.  */

final class ArbitrumOneTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'arbitrum-one';
        $this->module = 'arbitrum-one-trace';
        $this->complements = 'arbitrum-one-main';
        $this->is_main = false;
        $this->first_block_date = '2021-05-28';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
