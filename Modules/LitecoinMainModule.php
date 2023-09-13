<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Litecoin module. It requires Litecoin Core (https://github.com/litecoin-project/litecoin)
 *  with `txindex` set to true to run.  */

final class LitecoinMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'litecoin';
        $this->module = 'litecoin-main';
        $this->is_main = true;
        $this->currency = 'litecoin'; // Static
        $this->currency_details = ['name' => 'Litecoin', 'symbol' => 'LTC', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2011-10-07';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::HasMWEB];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '30';
    }
}
