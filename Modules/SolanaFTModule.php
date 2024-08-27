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
        $this->module = 'solana-ft';
        $this->is_main = false;
        $this->first_block_date = '2020-03-16';
        $this->first_block_id = 0;
        $this->currency_type = CurrencyType::FT;

        $this->programs = [
            'SPL_NAME_SERVICE_PROGRAM_ID' => 'namesLPneVptA9Z5rqUDD9tMTWEJwofgaYwp8cawRkX',
            'DOMAIN_HASH_PREFIX' => 'SPL Name Service',
            'TWITTER_ROOT_PARENT_REGISTERY_KEY' => '4YcexoW3r78zz16J2aqmukBLRwGq6rAvWzJpkYAXqebv',
            'SOL_TLD_AUTHORITY' => '58PwtjSDuFHuUkYjH9BYnnQKHfwo9reZhC2zMJv9JPkx',
            'METAPLEX_ID' => 'metaqbxxUerdq28cj1RbAWkYQm3ybzjb6a8bt518x1s',
            'TOKEN_2022_PROGRAM_ID' => 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb',
            'TOKEN_PROGRAM_ID' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
        ];

        $this->tokens_list = unserialize(file_get_contents(__DIR__ . '/Genesis/SolanaTokenList.data'));
    }
}
