<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is the main Bitcoin module. It requires Bitcoin Core (https://github.com/bitcoin/bitcoin)
 *  with `txindex` set to true to run.  */

final class BitcoinMainModule extends UTXOMainModule implements Module
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
    }
}
