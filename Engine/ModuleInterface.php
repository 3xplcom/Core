<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Module interface  */

interface Module
{
    // Initialization functions, post-initialization checks

    public function pre_initialize();
    public function initialize();
    public function post_initialize();
    public function post_post_initialize();

    // Inner functions

    public function select_node(); // Node selection

    public function inquire_latest_block(); // Getting the last block number from the node
    public function ensure_block($block_id, $break_on_first); // Ensuring that block data is same across all nodes

    public function pre_process_block($block_id); // This is the main function to program within modules
    public function process_block($block_id); // This is a top-level function
    public function post_process_block(); // Check if returned data was constructed correctly
}

// Special API interfaces

interface BalanceSpecial
{
    public function api_get_balance(string $address): string;
}

interface MultipleBalanceSpecial
{
    public function api_get_balance(string $address, array $currencies): array;
}

interface TransactionSpecials
{
    public function api_get_transaction_specials(string $transaction): array;
}

interface AddressSpecials
{
    public function api_get_address_specials(string $address): array;
}

interface SupplySpecial
{
    public function api_get_currency_supply(string $currency): string;
}

interface BroadcastTransactionSpecial
{
    public function api_broadcast_transaction(string $data): ?string;
}
