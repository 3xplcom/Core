<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Arbitrum One trace module. It requires a geth node ("Arbitrum Nitro") to run with new blocks, and a legacy ("classic") geth node
 *  to populate the database with blocks up to #22207816. Tracing with the old node is a super slow process, so we've decided to drop that.
 *  Please also note, that the correct method for the old node is `debug_block` instead of `debug_traceBlockByNumber` and `evm_implementation`
 *  should be set to `Erigon` (though it's not Erigon) for compatibility with this method.  */

final class ArbitrumOneTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'arbitrum-one';
        $this->module = 'arbitrum-one-trace';
        $this->complements = 'arbitrum-one-main';
        $this->is_main = false;
        $this->first_block_date = '2022-08-31';
        $this->first_block_id = 22207817;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
