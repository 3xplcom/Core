<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

final class DogecoinMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'dogecoin';
        $this->module = 'dogecoin-main';
        $this->is_main = true;
        $this->currency = 'dogecoin';
        $this->currency_details = ['name' => 'Dogecoin', 'symbol' => 'DOGE', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2013-12-06';

        // UTXOMainModule
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '1e';
    }
}
