<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the EVM ERC1155 Astar module. */

final class AstarEVMERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'astar';
        $this->module = 'astar-evm-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';

        // Extrinsic id has different format
        $this->transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    }
}