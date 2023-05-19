3xpl
====

https://3xpl.com

What is 3xpl?
-------------

3xpl (short for 3xplor3r) is a super-fast, universal explorer for most popular public blockchains.
It offers an easy-to-understand block explorer interface for ordinary crypto users, as well as numerous professional features for developers and analysts.

We release our core modules as open source software. Why?
1. Because it's fun!
2. This makes our data verifiable, i.e. anyone can run our modules and independent nodes on their hardware and verify that they're getting the same data as we show on our explorer.
3. It makes expanding the list of explorers we support faster as anyone can contribute!

Using these modules and your own nodes, you can reconstruct our databases in full.

Philosophy
----------

3xpl is truly universal. It means that we're trying to abstract what happens on blockchains and smooth the technical differences.

Our modules work with three entities:
* **Blocks** - "blocks" is the universal concept (well, almost) as transactions are packed in blocks in all blockchains
* **Events** - instead of "transactions" we use "events" as transaction structures are different in different blockchains. For example, Bitcoin follows the UTXO model, where there can be multiple "senders" (inputs) in a transaction and multiple "recipients" (outputs). In the UTXO model the number of senders and the number of recipients may be not equal. On the other hand, Ethereum follows the account-based model. Every Ethereum transaction (or an internal transaction) is a transfer from exactly one sender to exactly one recipient. So for Ethereum, it would make sense to create a table of events with the "sender" and "recipient" columns, but this won't work for Bitcoin. So instead we have "events" which either adds some value (coins or NFTs) to address or subtracts.
* **Currencies** - every event is about sending money (or collectibles), and, for example, we treat sending ERC-20s the same as sending native ethers. For every event its corresponding currency id should be specified. Modules should gather additional information on currencies as they process blocks.

For example, Bitcoin's genesis transaction generates two events:
* 0
* * `transaction` => `4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b`
* * `address` => `the-void` (special synthetic address)
* * `effect` => `-5000000000` (50 BTC are being generated out of thin air)
* * `currency` => `bitcoin`
* * `block` => `0`
* * `time` => `2009-01-03 18:15:05`
* * `sort_key` => `0`
* 1
* * `transaction` => `4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b`
* * `address` => `1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa` (miner address)
* * `effect` => `5000000000` (50 BTC are being sent to the miner)
* * `currency` => `bitcoin`
* * `block` => `0`
* * `time` => `2009-01-03 18:15:05`
* * `sort_key` => `1`

One of the principles we also follow is atomicity. By atomicity we mean that in order to process a block, we don't need to look into some custom database (i.e., for previous block data), only data from the node should be used.

3xpl's core is split into modules. Each module processes some specific transaction type. For example, for Ethereum we have 5 modules: `ethereum-main` which processes basic transactions, `ethereum-trace` which processes internal transfers, `ethereum-erc-20` which processes ERC-20 transfers and ERC-20 currencies, `ethereum-erc-721`, and `ethereum-erc-1155`.

Why PHP? Because it's fun! Why no Composer? Because it's not fun.

How to run a module?
--------------------

First, you need to run a corresponding node. After the sync is complete, you need to add node credentials to the `.env` file. Afterwards you can start working with the module using `Debug.php`. For example, to track the newest ERC-20 transfers, you'd need to run `php Debug.php ethereum-erc-20 M`. You can write your own script that dumps the data into TSV files or whatever.

How to develop a new module?
----------------------------

1. Look at how the existing modules work
2. Add the new module to `.env` and `.env.example`
3. Implement `inquire_latest_block()` to get the latest block number from the node
4. Implement `ensure_block()` to compare block hashes from different nodes (if you have only one node you can emulate that with duplicating the node in the `.env` file). Modules can work with several nodes simultaneously for faster speed and it's imperative that nodes agree on what blocks they have.
5. Implement `pre_process_block()` to process blocks into event arrays (if there's mempool support, also add the mempool processing logic)
6. Implement `api_get_balance()` if node allows to retrieve balances
7. Implement `api_get_handle()` if node allows to retrieve handle data (see ENS in EthereumMainModule for example)
8. Set `CoreModule` variables
9. Start debugging your module with `Debug.php`. The core module catches many errors (e.g. missing fields in the output).

File structure
--------------

- Engine
- - Database.php
- - DebugHelpers.php
- - Enums.php
- - Env.php
- - Exceptions.php
- - Helpers.php
- - ModuleInterface.php
- - Requester.php
- Modules
- - Common
- - - CoreModule.php
- - - (Abstract*)Module.php ("Parent" modules)
- - Genesis
- - - (Module*).json
- - (Final*)Module.php ("Final" modules)
- .env.example
- CONTRIBUTING.md
- Debug.php
- Init.php
- LICENSE.md
- README.md

License
-------

3xpl modules are released under the terms of the MIT license.
See [LICENSE.md](LICENSE.md) for more information.

By contributing to this repository, you agree to license your work under the MIT license unless specified otherwise at the top of the file itself.
Any work contributed where you are not the original author must contain its license header with the original author and source.