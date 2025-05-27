<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Monero transactions. See `CryptoNoteMainModule.php` for details.  */

final class ZanoMainModule extends ZanoLikeMainModule implements Module, TransactionSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'zano';
        $this->module = 'zano-main';
        $this->is_main = true;
        $this->currency = 'zano';
        $this->currency_details = ['name' => 'Zano', 'symbol' => 'ZANO', 'decimals' => 12, 'description' => null];
        $this->first_block_id = 0;
        $this->first_block_date = '2019-05-08'; // TODO there is a genesis block, it has 0 timestamp

    }
}
