<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  These constant arrays describe the structure of events and currencies  */

// Events

// List of event fields
const EVENTS_TABLE_COLUMNS = ['block' => [1, 4, 'INT'], // Block id
                              'transaction' => [2, -1, 'BYTEA'], // Transaction id (hash)
                              'sort_key' => [3, 4, 'INT'], // Event position within the block
                              'time' => [4, 8, 'TIMESTAMP'], // Event timestamp
                              'address' => [5, -1, 'BYTEA'], // Affected address
                              'currency' => [6, -1, 'BYTEA'], // Currency id
                              'effect' => [7, -1, 'NUMERIC'], // Amount transferred
                              'failed' => [8, 1, 'BOOLEAN'], // Whether it has been really transferred
                              'extra' => [9, -1, 'BYTEA'], // Extra properties of the event
                              'extra_indexed' => [10, -1, 'BYTEA'], // Extra property which is indexed
    ];

// Which fields are mandatory. Non-mandatory fields may be skipped from the output.
const EVENTS_TABLE_MANDATORY_COLUMNS = ['block', 'sort_key', 'time', 'address', 'effect'];
// `transaction` is not mandatory as there can be events which don't belong to a block rather than to a transaction

// Which fields can not be nulls
const EVENTS_TABLE_NOT_NULLABLE_COLUMNS = ['block', 'sort_key', 'time', 'address', 'effect'];

// Currencies

// List of currency fields
const CURRENCIES_TABLE_COLUMNS = ['id' => [1, -1, 'BYTEA'], // Currency id (e.g. bitcoin)
                                  'name' => [2, -1, 'BYTEA'], // Currency name (e.g. Bitcoin)
                                  'symbol' => [3, -1, 'BYTEA'], // Currency symbol (e.g. BTC)
                                  'decimals' => [4, 2, 'SMALLINT'], // Amount of decimals (e.g. 8)
                                  'description' => [5, -1, 'BYTEA'], // Currency description
    ];

const CURRENCIES_TABLE_MANDATORY_COLUMNS = ['id'];

const CURRENCIES_TABLE_NOT_NULLABLE_COLUMNS = ['id'];
