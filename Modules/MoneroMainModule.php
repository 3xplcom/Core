<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Monero transactions. See `CryptoNoteMainModule.php` for details.  */

final class MoneroMainModule extends CryptoNoteMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'monero';
        $this->module = 'monero-main';
        $this->is_main = true;
        $this->currency = 'monero';
        $this->currency_details = ['name' => 'Monero', 'symbol' => 'XMR', 'decimals' => 12, 'description' => null];
        $this->first_block_id = 0;
        $this->first_block_date = '2014-04-18';

        // Tests
        $this->tests = [
            // First Monero block
            ['block' => 0, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:6:{s:11:"transaction";s:64:"c88ce9783b4f11190d7b9c17a69c1c52200f9faaee8e98dd07e6811175177139";s:7:"address";s:8:"the-void";s:6:"effect";s:15:"-17592186044415";s:8:"sort_key";i:0;s:5:"block";i:0;s:4:"time";s:19:"1970-01-01 00:00:00";}i:1;a:6:{s:11:"transaction";s:64:"c88ce9783b4f11190d7b9c17a69c1c52200f9faaee8e98dd07e6811175177139";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:14:"17592186044415";s:8:"sort_key";i:1;s:5:"block";i:0;s:4:"time";s:19:"1970-01-01 00:00:00";}}s:10:"currencies";N;}'],
            // Last v1 transaction
            ['block' => 1220516, 'transaction' => '2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0', 'result' => 'a:1:{s:6:"events";a:6:{i:0;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:14:"-1000000000000";s:8:"sort_key";i:224;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}i:1;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:10:"1000000000";s:8:"sort_key";i:225;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}i:2;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:10:"9000000000";s:8:"sort_key";i:226;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}i:3;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:11:"50000000000";s:8:"sort_key";i:227;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}i:4;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:12:"900000000000";s:8:"sort_key";i:228;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}i:5;a:6:{s:11:"transaction";s:64:"2210683a0e0eaf8143c9989f45f9b505912b8236dc125dfd70139fe311281be0";s:7:"address";s:8:"the-void";s:6:"effect";s:11:"40000000000";s:8:"sort_key";i:229;s:5:"block";i:1220516;s:4:"time";s:19:"2017-01-10 06:38:49";}}}'],
            // First v2 transation (non-coinbase)
            ['block' => 1220517, 'transaction' => '6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898', 'result' => 'a:1:{s:6:"events";a:5:{i:0;a:6:{s:11:"transaction";s:64:"6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:2:"-?";s:8:"sort_key";i:2;s:5:"block";i:1220517;s:4:"time";s:19:"2017-01-10 06:39:06";}i:1;a:6:{s:11:"transaction";s:64:"6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:2:"-?";s:8:"sort_key";i:3;s:5:"block";i:1220517;s:4:"time";s:19:"2017-01-10 06:39:06";}i:2;a:6:{s:11:"transaction";s:64:"6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:2:"+?";s:8:"sort_key";i:4;s:5:"block";i:1220517;s:4:"time";s:19:"2017-01-10 06:39:06";}i:3;a:6:{s:11:"transaction";s:64:"6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:2:"+?";s:8:"sort_key";i:5;s:5:"block";i:1220517;s:4:"time";s:19:"2017-01-10 06:39:06";}i:4;a:6:{s:11:"transaction";s:64:"6f6f2eea2e549a69ad10246511ccc720193f1d41c9fa2c7aae0e47cb9884d898";s:7:"address";s:8:"the-void";s:6:"effect";s:11:"26000000000";s:8:"sort_key";i:6;s:5:"block";i:1220517;s:4:"time";s:19:"2017-01-10 06:39:06";}}}'],
            // No outputs transaction
            ['block' => 770439, 'transaction' => 'ee55915c178b9961c89d81f0059a9a3b54026e2be069d0d2e6dae12119bc0f69', 'result' => 'a:1:{s:6:"events";a:3:{i:0;a:6:{s:11:"transaction";s:64:"ee55915c178b9961c89d81f0059a9a3b54026e2be069d0d2e6dae12119bc0f69";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:11:"-7928990000";s:8:"sort_key";i:5;s:5:"block";i:770439;s:4:"time";s:19:"2015-10-06 14:54:39";}i:1;a:6:{s:11:"transaction";s:64:"ee55915c178b9961c89d81f0059a9a3b54026e2be069d0d2e6dae12119bc0f69";s:7:"address";s:13:"shielded-pool";s:6:"effect";s:11:"-3336980000";s:8:"sort_key";i:6;s:5:"block";i:770439;s:4:"time";s:19:"2015-10-06 14:54:39";}i:2;a:6:{s:11:"transaction";s:64:"ee55915c178b9961c89d81f0059a9a3b54026e2be069d0d2e6dae12119bc0f69";s:7:"address";s:8:"the-void";s:6:"effect";s:11:"11265970000";s:8:"sort_key";i:7;s:5:"block";i:770439;s:4:"time";s:19:"2015-10-06 14:54:39";}}}'],
        ];
    }
}
