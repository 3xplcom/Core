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
    }
}
