<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Linea. It requires a geth node to run.  */

final class LineaERC721Module extends EVMERC721Module implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'linea';
        $this->module = 'linea-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-07-06';
        $this->first_block_id = 0;
    }
}
