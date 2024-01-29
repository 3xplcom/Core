<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the ERC1155 Filecoin evm-like module. */

final class FilecoinEVMERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'filecoin';
        $this->module = 'filecoin-evm-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2020-08-25';

        // EVM-specific
        $this->extra_features = [EVMSpecialFeatures::fEVM];
    }
}
