<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes FT SPL Token Solana transfers.  */

final class SolanaFTModule extends SolanaLikeTokenModule implements Module
{
    function initialize()
    {
        $this->blockchain = 'solana';
        $this->module = 'solana-token';
        $this->is_main = false;
        $this->first_block_date = '2020-03-16';
        $this->first_block_id = 0;
        $this->currency_type = CurrencyType::FT;
    }
}
