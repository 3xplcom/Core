<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Sei EVM transactions (using block tracing).  */

final class SeiEVMTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei-evm';
        $this->module = 'sei-evm-trace';
        $this->complements = 'sei-evm-main';
        $this->is_main = false;
        $this->first_block_date = '2024-01-25'; // This is for the devnet
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
