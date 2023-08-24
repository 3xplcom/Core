<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes BEP-721 (a.k.a. ERC-721) NFT transfers in BNB. It requires either a geth or
 *  an Erigon node to run.  */

final class BNBBEP721Module extends EVMERC721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bnb';
        $this->module = 'bnb-bep-721';
        $this->is_main = false;
        $this->first_block_date = '2020-08-29';
        $this->first_block_id = 0;
    }
}
