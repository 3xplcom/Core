<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Filecoin module. */

final class FilecoinMainModule extends FilecoinLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'filecoin';
        $this->module = 'filecoin-main';
        $this->is_main = true;
        $this->first_block_date = '2020-08-25';
        $this->currency = 'filecoin';
        $this->currency_details = ['name' => 'Filecoin', 'symbol' => 'FIL', 'decimals' => 18, 'description' => null];
    }
}
