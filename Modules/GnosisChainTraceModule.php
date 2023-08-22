<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Gnosis Chain transactions (using block tracing).
 *  It requires an archival Nethermind or Erigon node to run (the latter is faster).  */

final class GnosisChainTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'gnosis-chain';
        $this->module = 'gnosis-chain-trace';
        $this->complements = 'gnosis-chain-main';
        $this->is_main = false;
        $this->first_block_date = '2018-10-08';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running Nethermind, but this would be slower
    }
}
