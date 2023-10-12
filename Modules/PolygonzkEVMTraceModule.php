<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

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

        // Tests
        $this->tests = [
            // Empty batches
            ['block' => 0, 'result' => 'a:2:{s:6:"events";a:0:{}s:10:"currencies";N;}'],
            ['block' => 1, 'result' => 'a:2:{s:6:"events";a:0:{}s:10:"currencies";N;}'],
            // First non-empty batch
            ['block' => 2, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:7:{s:11:"transaction";s:66:"0x1b1cc77d663d9176b791e94124eecffe49d1c69837ee6e9ed09356f2c70a065d";s:7:"address";s:42:"0x2a3dd3eb832af982ec71669e178424b10dca2ede";s:8:"sort_key";i:0;s:6:"effect";s:19:"-100000000000000000";s:5:"extra";N;s:5:"block";i:2;s:4:"time";s:19:"2023-03-24 17:30:15";}i:1;a:7:{s:11:"transaction";s:66:"0x1b1cc77d663d9176b791e94124eecffe49d1c69837ee6e9ed09356f2c70a065d";s:7:"address";s:42:"0x1dba1131000664b884a1ba238464159892252d3a";s:8:"sort_key";i:1;s:6:"effect";s:18:"100000000000000000";s:5:"extra";N;s:5:"block";i:2;s:4:"time";s:19:"2023-03-24 17:30:15";}}s:10:"currencies";N;}'],
        ];
    }
}
