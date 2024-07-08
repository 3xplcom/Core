<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Bitcoin module. It requires Bitcoin Core (https://github.com/bitcoin/bitcoin)
 *  with `txindex` set to true to run.  */

final class BitcoinMainModule extends UTXOMainModule implements Module, TransactionSpecials, SupplySpecial, BroadcastTransactionSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin';
        $this->module = 'bitcoin-main';
        $this->is_main = true;
        $this->currency = 'bitcoin'; // Static
        $this->currency_details = ['name' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2009-01-03';

        // UTXOMainModule
        $this->p2pk_prefix1 = '1';
        $this->p2pk_prefix2 = '00';

        // Tests
        $this->tests = [
            // First Bitcoin block
            ['block' => 0, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:6:{s:11:"transaction";s:64:"4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b";s:7:"address";s:8:"the-void";s:6:"effect";s:11:"-5000000000";s:5:"block";i:0;s:4:"time";s:19:"2009-01-03 18:15:05";s:8:"sort_key";i:0;}i:1;a:6:{s:11:"transaction";s:64:"4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b";s:7:"address";s:34:"1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa";s:6:"effect";s:10:"5000000000";s:5:"block";i:0;s:4:"time";s:19:"2009-01-03 18:15:05";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
            // First witness_v0_scripthash spending
            ['block' => 482133, 'transaction' => 'cab75da6d7fe1531c881d4efdb4826410a2604aa9e6442ab12a08363f34fb408', 'result' => 'a:1:{s:6:"events";a:3:{i:0;a:6:{s:11:"transaction";s:64:"cab75da6d7fe1531c881d4efdb4826410a2604aa9e6442ab12a08363f34fb408";s:7:"address";s:62:"bc1qj9hlju59t0m4389033r2x8mlxwc86qgqm9flm626sd22cdhfs9jsyrrp6q";s:6:"effect";s:6:"-86591";s:5:"block";i:482133;s:4:"time";s:19:"2017-08-27 02:32:03";s:8:"sort_key";i:9217;}i:1;a:6:{s:11:"transaction";s:64:"cab75da6d7fe1531c881d4efdb4826410a2604aa9e6442ab12a08363f34fb408";s:7:"address";s:42:"bc1qt4hs9aracmzhpy7ly3hrwsk0u83z4dqsln4vg5";s:6:"effect";s:5:"73182";s:5:"block";i:482133;s:4:"time";s:19:"2017-08-27 02:32:03";s:8:"sort_key";i:9218;}i:2;a:6:{s:11:"transaction";s:64:"cab75da6d7fe1531c881d4efdb4826410a2604aa9e6442ab12a08363f34fb408";s:7:"address";s:8:"the-void";s:6:"effect";s:5:"13409";s:5:"block";i:482133;s:4:"time";s:19:"2017-08-27 02:32:03";s:8:"sort_key";i:9219;}}}'],
            // Empty single coinbase transaction (`the-void` should be transferring `-0`, output address is a special starting with `script-`)
            ['block' => 501726, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:6:{s:11:"transaction";s:64:"9bf8853b3a823bbfa1e54017ae11a9e1f4d08a854dcce9f24e08114f2c921182";s:7:"address";s:8:"the-void";s:6:"effect";s:2:"-0";s:5:"block";i:501726;s:4:"time";s:19:"2017-12-30 12:55:20";s:8:"sort_key";i:0;}i:1;a:6:{s:11:"transaction";s:64:"9bf8853b3a823bbfa1e54017ae11a9e1f4d08a854dcce9f24e08114f2c921182";s:7:"address";s:39:"script-04ad42328809c33523c413effb44f42b";s:6:"effect";s:1:"0";s:5:"block";i:501726;s:4:"time";s:19:"2017-12-30 12:55:20";s:8:"sort_key";i:1;}}s:10:"currencies";N;}'],
        ];
    }
}
