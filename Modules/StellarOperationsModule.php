<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Stellar operations. It requires a Stellar node to run.  */

final class StellarOperationsModule extends StellarLikeOperationsModule implements Module
{
    function initialize()
    {
        $this->blockchain = 'stellar';
        $this->module = 'stellar-operations';
        $this->is_main = false;
        $this->first_block_date = '2015-09-30';
        $this->first_block_id = 0;
        $this->native_currency = 'xlm';
    }
}
