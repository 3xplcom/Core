<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Ethereum Classic transactions (using block tracing). It requires an archival geth node to run.  */

final class EthereumClassicTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum-classic';
        $this->module = 'ethereum-classic-trace';
        $this->complements = 'ethereum-classic-main';
        $this->is_main = false;
        $this->first_block_date = '2015-07-30';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
