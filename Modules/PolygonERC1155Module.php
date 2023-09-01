<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Polygon. It requires either a geth or
 *  an Erigon node to run.  */

final class PolygonERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon';
        $this->module = 'polygon-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2020-05-30';
        $this->first_block_id = 0;
    }
}
