<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Merlin trace module. It requires a geth node to run.  */
/*  DISABLED                                                          */

final class MerlinTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'merlin';
        $this->module = 'merlin-trace';
        $this->complements = 'merlin-main';
        $this->is_main = false;
        $this->first_block_date = '2024-02-02';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::zkEVM];
    }
}
