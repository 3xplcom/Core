<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes BEP-1155 MT transfers in opBNB. It requires a geth node to run.  */

final class opBNBBEP1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'opbnb';
        $this->module = 'opbnb-bep-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-08-11';
        $this->first_block_id = 0;
    }
}
