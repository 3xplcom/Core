<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes MWEB Litecoin transactions. It requires Litecoin Core (https://github.com/litecoin-project/litecoin)
 *  with `txindex` set to true to run.  */

final class LitecoinMimbleWimbleModule extends MimbleWimbleModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'litecoin';
        $this->module = 'litecoin-mweb';
        $this->is_main = false;
        $this->currency = 'litecoin';
        $this->currency_details = ['name' => 'Litecoin', 'symbol' => 'LTC', 'decimals' => 8, 'description' => null];
        $this->first_block_id = 2_265_984;
        $this->first_block_date = '2022-05-20';
    }
}
