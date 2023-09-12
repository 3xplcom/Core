<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Ethereum transactions (using block tracing). It requires an archival Erigon node to run.  */

final class EthereumTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum';
        $this->module = 'ethereum-trace';
        $this->complements = 'ethereum-main';
        $this->is_main = false;
        $this->first_block_date = '2015-07-30';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::Erigon;
    }
}
