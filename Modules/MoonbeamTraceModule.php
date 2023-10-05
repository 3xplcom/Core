<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes internal Moonbeam (Polkadot parachain) transactions (using block tracing). It requires an archival node to run. */

final class MoonbeamTraceModule extends EVMTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'moonbeam';
        $this->module = 'moonbeam-trace';
        $this->complements = 'moonbeam-main';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';

        // EVMTraceModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::FlattenedTraces];
    }
}
