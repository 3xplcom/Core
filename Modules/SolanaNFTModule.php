<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes NFT SPL Token Solana transfers.  */
Enum SolanaAddressPrograms: string
{
    case SPL_NAME_SERVICE_PROGRAM_ID = 'namesLPneVptA9Z5rqUDD9tMTWEJwofgaYwp8cawRkX';
    case DOMAIN_HASH_PREFIX = "SPL Name Service";
    case TWITTER_ROOT_PARENT_REGISTERY_KEY = "4YcexoW3r78zz16J2aqmukBLRwGq6rAvWzJpkYAXqebv";
    case SOL_TLD_AUTHORITY = "58PwtjSDuFHuUkYjH9BYnnQKHfwo9reZhC2zMJv9JPkx";
    case METAPLEX_ID = "metaqbxxUerdq28cj1RbAWkYQm3ybzjb6a8bt518x1s";
    case TOKEN_2022_PROGRAM_ID = "TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb";
    case TOKEN_PROGRAM_ID = "TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA";
}

final class SolanaNFTModule extends SolanaLikeTokenModule implements Module, MultipleBalanceSpecial, SupplySpecial
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
