<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the EVM ERC20 Centrifuge module. */

final class CentrifugeEVMERC20Module extends EVMERC20Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'centrifuge';
        $this->module = 'centrifuge-evm-erc-20';
        $this->is_main = false;
        $this->first_block_date = '2022-03-12';
        $this->first_block_id = 3308248;

        // Extrinsic id has different format
        $this->transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    }
}
