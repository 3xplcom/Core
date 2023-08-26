<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main DigiByte module. It requires DigiByte Core (https://github.com/digibyte-core/digibyte)
 *  with `txindex` set to true to run.  */

final class DigiByteMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'digibyte';
        $this->module = 'digibyte-main';
        $this->is_main = true;
        $this->currency = 'digibyte';
        $this->currency_details = ['name' => 'DigiByte', 'symbol' => 'DGB', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2014-01-10';

        // UTXOMainModule
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '1e';
    }
}
