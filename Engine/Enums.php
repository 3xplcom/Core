<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  These enums describe variations of different formats used by the engine  */

// Block hash format variations (the idea is that hex values can be stored not as string, but as blobs, thus saving space)
enum BlockHashFormat: string
{
    case AlphaNumeric = 'AlphaNumeric'; // E.g. Solana: HQxcHkcekvZmg3BfpBH7pGEBv5KK9cptZpF5XiyBWQVW
    case HexWithout0x = 'HexWithout0x'; // E.g. Bitcoin: 00000000000000000007c716584b550f74c02dd40f28a5c4bcd3a2f508349f58
    case HexWith0x = 'HexWith0x'; // E.g. Ethereum: 0xda2b5b2da95ca01029b30db20f8147416d9e3393b0258c8cdabd4b34a938be33
}

// Transaction hash format
enum TransactionHashFormat: string
{
    case AlphaNumeric = 'AlphaNumeric';
    case HexWithout0x = 'HexWithout0x';
    case HexWith0x = 'HexWith0x';
    case None = 'Zilch'; // This can be used if there are no individual transactions in blocks
}

// How transaction should be rendered or understood
enum TransactionRenderModel: string
{
    case UTXO = 'UTXO'; // UTXO model (e.g. Bitcoin): first "negative" events (inputs), then "positive" events (outputs)
    case Even = 'Even'; // Account-based model (e.g. Ethereum): transactions always have an even number of events:
                        // and events come in "negative-positive" pairs
    case EvenOrMixed = 'EvenOrMixed'; // Try `Even` model if possible (if events come in pairs), if not, just show the list of events
    case Mixed = 'Mixed'; // No particular order of events
    case None = 'Zilch'; // No individual transactions in blocks, so there's no need to render transactions
}

// Address formats
enum AddressFormat: string
{
    case AlphaNumeric = 'AlphaNumeric';
    case HexWithout0x = 'HexWithout0x';
    case HexWith0x = 'HexWith0x';
}

// Currency formats
// Module can either work with one currency (`Static`), or with multiple currencies (e.g. ERC-20 contracts)
enum CurrencyFormat: string
{
    case Static = 'Static'; // There's only one currency processed by the module (e.g. this is the case for `bitcoin-main` where
                            // Bitcoin is the only currency
    case Numeric = 'Numeric'; // Currency identifiers are numbers (e.g. Omni layer)
    case AlphaNumeric = 'AlphaNumeric';
    case HexWithout0x = 'HexWithout0x';
    case HexWith0x = 'HexWith0x'; // E.g. ERC-20 currency identifiers are their contract addresses
}

// Currency types
enum CurrencyType: string
{
    case FT = 'FT'; // Fungible tokens, e.g. Bitcoin, ERC-20 tokens
    case NFT = 'NFT'; // Non-fungible tokens, e.g. ERC-721
    case MT = 'MT'; // Multi tokens, e.g. ERC-1155
}

// How fees should be rendered or understood
enum FeeRenderModel: string
{
    case None = 'Zilch'; // Transactions don't pay fees within the module. For example, there are two Bitcoin modules:
                         // `bitcoin-main` which contains fee payments, and `bitcoin-omni` which doesn't as the fees are
                         // being paid in the main module only
    case LastEventToTheVoid = 'LastEventToTheVoid'; // The fee is the last event to the special `the-void` address (see `bitcoin-main`)
                                                    // If the fee is 0, there's no event to `the-void`
    case ExtraF = 'ExtraF'; // If transaction has an event with `f` in extra data, this is the fee
    case ExtraBF = 'ExtraBF'; // Same as `ExtraF`, but there can be two events: `f` is the fee paid to the miner, and
                              // `b` is the part which is being burnt (see `ethereum-main`)
}

// We use virtual block number for processing mempool
const MEMPOOL = -1;

// Standard regexes
enum StandardPatterns: string
{
    case ModuleName = '/^[\da-z\-]+$/D';
    case AnySearchable = '/^[a-zA-Z0-9_\-]+$/D';
    case PositiveNumber = '/^\d+$/D';
    case PlusMinusNumber = '/^-?\d+$/D';
    case CommaSeparatedBits = '/^[a-zA-Z0-9_\/\-,\(\)]+$/D';
    case HexWithout0x = '/^[a-f0-9]+$/D';
    case iHexWithout0x = '/^[a-fA-F0-9]+$/D';
    case HexWith0x = '/^0x[a-f0-9]+$/D';
    case HexWith0x40 = '/^0x[a-f0-9]{40}$/D';
    case iHexWith0x = '/^0x[a-fA-F0-9]+$/D';
    case iHexWith0x40 = '/^0x[a-fA-F0-9]{40}$/D';
    case Date = '/^\d\d\d\d-\d\d-\d\d$/D';
    case YMDhis = '/^\d\d\d\d\-\d\d\-\d\d\s\d\d\:\d\d\:\d\d$/D';
    case YMDhisu = '/^\d\d\d\d\-\d\d\-\d\d\s\d\d\:\d\d\:\d\d\.\d\d\d\d\d\d$/D';
}

// What's being stored in `extra` field
enum ExtraDataModel: string
{
    case Default = 'Default'; // One of the standard event types: `f`, `b`, `r`, `i`, `u`, `c`, `d`, `n`
    case Type = 'Type'; // Special event type (can be null for ordinary events), e.g. for Omni we use Omni types
    case Identifier = 'Identifier'; // This is reserved for NFT transfers: the NFT id is stored here
    case None = 'Zilch'; // There's no extra data for the module
}

enum PrivacyModel
{
    case Transparent; // Every event has a known value
    case Mixed; // Any value is acceptable, including `-?` and `+?`
    case Shielded; // The only allowed values are `-?` and `+?`
}
