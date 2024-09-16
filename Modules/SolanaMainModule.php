<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes main Solana transfers.  */

final class SolanaMainModule extends SVMMainModule implements Module, BalanceSpecial
{
    function initialize()
    {
        // General
        $this->blockchain = 'solana';
        $this->module = 'solana-main';
        $this->is_main = true;

        // Special
        $this->first_block_date = '2020-03-16';
        $this->first_block_id = 0;

        // Blockchain-specific
        $this->currency = 'solana';
        $this->currency_details = ['name' => 'Solana', 'symbol' => 'SOL', 'decimals' => 9, 'description' => null];

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.sol/';
        $this->api_get_handle = function ($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;
            return $this->get_domain_owner($handle);
        };
    }
}
