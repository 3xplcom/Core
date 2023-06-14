<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the Polygon zkEVM trace module. It requires a geth node to run.  */

final class PolygonzkEVMTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon-zkevm';
        $this->module = 'polygon-zkevm-trace';
        $this->complements = 'polygon-zkevm-main';
        $this->is_main = false;
        $this->first_block_date = '2023-03-24';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::zkEVM];
    }
}
