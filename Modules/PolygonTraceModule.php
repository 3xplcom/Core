<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Polygon transactions (using block tracing). It requires an archival Erigon node to run.
 *  Please note that `gasBailOut` should be set to `true` for Polygon, see https://github.com/ledgerwatch/erigon/pull/7326  */

final class PolygonTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon';
        $this->module = 'polygon-trace';
        $this->complements = 'polygon-main';
        $this->is_main = false;
        $this->first_block_date = '2020-05-30';
        $this->first_block_id = 0;

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::Erigon;
    }
}
