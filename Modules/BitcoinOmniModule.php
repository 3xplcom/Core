<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Omni Layer transactions in Bitcoin. It requires Omni Core (https://github.com/OmniLayer/omnicore)
 *  with `txindex` set to true to run.  */

final class BitcoinOmniModule extends UTXOOmniModule implements Module
{
    /*private */function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin';
        $this->module = 'bitcoin-omni';
        $this->is_main = false;
        $this->first_block_id = 252_316;
        $this->first_block_date = '2013-08-15';

        // UTXOOmniModule
        $this->genesis_block = 252_316; // First real transactions are in block 252_317, so we use this one for genesis transactions
        $this->genesis_json = 'BitcoinOmni.json';
    }
}
