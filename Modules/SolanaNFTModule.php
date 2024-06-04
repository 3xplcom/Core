<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes NFT SPL Token Solana transfers.  */

final class SolanaNFTModule extends SolanaLikeTokenModule implements Module
{
    function initialize()
    {
        $this->blockchain = 'solana';
        $this->module = 'solana-nft';
        $this->is_main = false;
        $this->first_block_date = '2020-03-16';
        $this->first_block_id = 0;
        $this->currency_type = CurrencyType::NFT;
    }
}
