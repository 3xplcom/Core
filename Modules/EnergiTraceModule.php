<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Energi transactions (using block tracing).
 *  Processes the block rewards in transfers from special addresses too. */

final class EnergiTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'energi';
        $this->module = 'energi-trace';
        $this->complements = 'energi-main';
        $this->is_main = false;
        $this->first_block_date = '2020-03-10';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
    }
}
