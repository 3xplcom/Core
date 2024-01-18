<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-721 token transfers in EVM Sei.  */

final class SeiEVMERC721Module extends EVMERC721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-evm-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2023-12-26'; // TODO: this is block_date for Sei devnet
        $this->first_block_id = 0;
    }
}
