<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the core module. When you create a custom module, it inherits everything from this core module.
 *  The post_initialize() function helps to check whether all the properties are set correctly, that there are
 *  no conflicting properties, etc.  */

abstract class CoreModule
{
    //////////////////////////
    // Module configuration //
    //////////////////////////

    // Main properties

    public ?string $blockchain = null; // Blockchain name, e.g. 'bitcoin'
    public ?string $module = null; // Module name, e.g. 'bitcoin-main'
    public ?int $version = null; // Module version. This needs to be bumped only if some changes affect the output, not the process.

    // Module hierarchy

    public ?bool $is_main = null; // Set to true if this is the primary module for the blockchain
    public ?bool $must_complement = false; // Set to true if this module complements another one. By "complementing" we mean
    // that a module works with the same currencies and addresses as some other one. For example, `ethereum-trace` module
    // complements `ethereum-main` as it tracks ETH balance changes for the same addresses. But `bitcoin-omni` does not
    // complement `bitcoin-main` as it works with Omni balances rather than with BTC balances.
    public ?string $complements = null; // If $must_complement is true, this should contain the name of the module
    // which is being complemented

    // Blockchain features

    public ?bool $mempool_implemented = null; // Will this module process mempool transactions?
    public ?bool $forking_implemented = null; // Can blocks be orphaned on this blockchain?
    public ?PrivacyModel $privacy_model = null; // Sets whether there can be unknown values (`-?` and `+?`)

    // Entity formats

    public ?BlockHashFormat $block_hash_format = null; // Block hash format
    public ?AddressFormat $address_format = null; // Address format
    public ?TransactionHashFormat $transaction_hash_format = null; // Transaction hash format

    // Currency format

    public ?CurrencyFormat $currency_format = null; // Currency format; set to `Static` if the module
    // processes only one currency (e.g. for `bitcoin-main`)
    public ?string $currency = null; // Currency id (this is needed for `Static` only)
    const EMPTY_CURRENCY_DETAILS = ['name' => null, 'symbol' => null, 'decimals' => null, 'description' => null]; // And details
    public array $currency_details = self::EMPTY_CURRENCY_DETAILS;

    // Some additional properties

    public ?TransactionRenderModel $transaction_render_model = null; // How transactions should be rendered or understood (e.g. UTXO)
    public ?CurrencyType $currency_type = null; // Currency type (FT, NFT, or MT). This can influence the `extra` field.
    public ?FeeRenderModel $fee_render_model = null; // How transactions fees should be rendered or understood
    public ?ExtraDataModel $extra_data_model = ExtraDataModel::None; // What's being stored in the `extra` field
    public ?array $extra_data_details = null; // An array of extended descriptions for `extra`
    const DefaultExtraDataArray = ['f' => 'Miner fee', // This is the default array with the most frequent cases
                                   'b' => 'Burnt fee',
                                   'r' => 'Block reward',
                                   'i' => 'Uncle inclusion reward',
                                   'u' => 'Uncle reward',
                                   'c' => 'Contract creation',
                                   'd' => 'Contract destruction',
                                   'n' => 'Nothing',
                                   // Note that null should be used for ordinary events
    ];
    public ?array $special_addresses = []; // An array of special addresses which technically can't be found on blockchains,
    // but are used in the modules. Examples: `the-void` is used for sending fees to and in coinbase transactions in UTXO chains;
    // `sprout-pool`, `sapling-pool`, and `orchard-pool` are used in Zcash for interacting with shielded pools. In case of Zcash,
    // a wildcard can be used like this: `*-pool`. These addresses are only available on 3xpl, but not on other block explorers.

    // What module returns
    // 1. Does it return events?
    // 2. Does it return currency data? E.g. when we process ERC-20 transfers, we also want to get some extra information
    // about currency details (token names, decimals, etc.)

    public ?bool $should_return_events = null; // Does it return events?
    public ?bool $should_return_currencies = null; // Does it return currency data?
    public ?bool $allow_empty_return_events = null; // Is it ok if the module returns no events when processing a block?
    public ?bool $allow_empty_return_currencies = null; // Is it ok if the module returns no currencies when processing a block?

    // Which event fields are being returned by the module

    public ?array $events_table_fields = null; // List of fields for events
    public ?array $events_table_nullable_fields = null; // Which fields can be nulls
    public ?array $mempool_events_table_fields = null; // This shouldn't be redefined as it's calculated automatically for mempool
    public ?array $currencies_table_fields = null; // List of fields for currencies
    public ?array $currencies_table_nullable_fields = null; // Which fields can be nulls

    // Some blockchain properties

    public ?int $first_block_id = 0; // First block number
    public ?string $first_block_date = null; // First block date

    // Checks settings

    public ?bool $ignore_sum_of_all_effects = false; // By default, sum of all events returned for a block by a module should be 0.
    // If that's not the case, change this to true.

    // Handles

    public ?bool $handles_implemented = null; // Is there an API call to convert a handle into address?
    public ?string $handles_regex = null; // Regular expression for the handles
    public ?Closure $api_get_handle = null; // API function that performs conversion

    // Tests
    public ?array $tests = null; // Array for test cases

    // Entity names
    public string $block_entity_name = 'block';
    public string $transaction_entity_name = 'transaction';
    public string $address_entity_name = 'address';
    public string $mempool_entity_name = 'mempool';

    ///////////////////////
    // Runtime variables //
    ///////////////////////

    // Node information

    public ?array $nodes = null; // Array of nodes (this is set in the .env file)
    public ?int $timeout = null; // Timeout (this is set in the .env file)

    // Current block data

    public ?int $block_id = null; // Block number (or MEMPOOL [-1] if the mempool is being processed)
    public ?string $block_hash = null; // Block hash
    public ?string $block_time = null; // Block time
    public ?string $block_extra = null; // Some extra data about the block

    // Data arrays

    public ?array $return_events = null; // Events
    public ?array $return_currencies = null; // Currencies
    public ?array $processed_transactions = []; // This can be used for very large arrays of events when we decide to
    // split the data in parts. This can be useful when processing mempool data.

    ///////////////////////////
    // Module initialization //
    ///////////////////////////

    public function __construct()
    {
        // Initialization

        $this->pre_initialize(); // This is invoked in parent classes such as UTXOMainModule
        $this->initialize(); // This is invoked in final modules such as BitcoinMainModule

        // Nodes

        $this->nodes = envm($this->module, 'NODES', new DeveloperError("Nodes are not set in the config for module {$this->module}"));
        $this->timeout = envm($this->module, 'REQUESTER_TIMEOUT', new DeveloperError("Timeout is not set in the config for module {$this->module}"));

        // Post-initialization. Here we check if all settings are applied correctly.

        $this->post_initialize(); // This is the core class function
        $this->post_post_initialize(); // And this is invoked in parent classes such as UTXOMainModule.
        // It checks whether the module-specific settings set in pre_initialize() and initialize() are correct.
    }

    abstract function pre_initialize();
    abstract function initialize();

    ////////////////////////
    // Check the settings //
    ////////////////////////

    final public function post_initialize()
    {
        if (is_null($this->is_main))
            throw new DeveloperError("`is_main` is not set");

        if (!is_null($this->complements))
        {
            if ($this->is_main)
                throw new DeveloperError("Complementing module can't be main");

            if (isset($this->currency))
                throw new DeveloperError("Can't set custom `currency` when complementing");

            if (isset($this->currency_type))
                throw new DeveloperError("Can't set custom `currency_type` when complementing");

            if (isset($this->currency_format))
                throw new DeveloperError("Can't set custom `currency_format` when complementing");

            if ($this->currency_details !== self::EMPTY_CURRENCY_DETAILS)
                throw new DeveloperError("Can't set custom `currency_details` when complementing");

            $complemented = new (module_name_to_class($this->complements))();

            $this->currency = $complemented->currency;
            $this->currency_type = $complemented->currency_type;
            $this->currency_format = $complemented->currency_format;
            $this->currency_details = $complemented->currency_details;

            if (isset($complemented->complements))
                throw new DeveloperError("`complements` can't be chained in module `{$this->complements}`");

            if ($this->block_hash_format !== $complemented->block_hash_format)
                throw new DeveloperError("`block_hash_format` mismatch for complemented module `{$this->complements}`");

            if ($this->address_format !== $complemented->address_format)
                throw new DeveloperError("`address_format` mismatch for complemented module `{$this->complements}`");

            if ($this->transaction_hash_format !== $complemented->transaction_hash_format)
                throw new DeveloperError("`transaction_hash_format` mismatch for complemented module `{$this->complements}`");

            if ($this->transaction_render_model !== $complemented->transaction_render_model)
                throw new DeveloperError("`transaction_render_model` mismatch for complemented module `{$this->complements}`");

            if ($this->should_return_currencies || $complemented->should_return_currencies)
                throw new DeveloperError("Undefined behaviour for processing currencies in a complemented module");
        }
        else
        {
            if ($this->must_complement)
                throw new DeveloperError("`complements` is not set, but `must_complement` is true");
        }

        if (is_null($this->blockchain))
            throw new DeveloperError("`blockchain` is not set");

        if (substr_count($this->blockchain, '/') > 1)
            throw new DeveloperError("Can't have more than one `/` in `blockchain`");

        if (str_contains($this->blockchain, '--'))
            throw new DeveloperError("Can't have `--` in `blockchain`");

        if (str_contains($this->blockchain, '/'))
        {
            [$raw_ecosystem, $raw_blockchain] = explode('/', $this->blockchain);
            if ($raw_ecosystem === $raw_blockchain)
                throw new DeveloperError("Ecosystem name shouldn't be equal to blockchain name");
        }

        if (is_null($this->module))
            throw new DeveloperError("`module` is not set");

        if (!preg_match('/^[\da-z-]+$/', $this->module))
            throw new DeveloperError("`module` should match ^[\da-z-]+$");

        if (is_null($this->version))
            throw new DeveloperError("`version` is not set");

        if (is_null($this->mempool_implemented))
            throw new DeveloperError("`mempool_implemented` is not set");

        if (is_null($this->forking_implemented))
            throw new DeveloperError("`forking_implemented` is not set");

        if (is_null($this->block_hash_format))
            throw new DeveloperError("`block_hash_format` is not set");

        if (is_null($this->address_format))
            throw new DeveloperError("`address_format` is not set");

        if (is_null($this->transaction_hash_format))
            throw new DeveloperError("`transaction_hash_format` is not set");

        if (is_null($this->transaction_render_model))
            throw new DeveloperError("`transaction_render_model` is not set");

        if (is_null($this->currency_format))
            throw new DeveloperError("`currency_format` is not set");

        if (is_null($this->currency_type))
            throw new DeveloperError("`currency_type` is not set");

        if (is_null($this->fee_render_model))
            throw new DeveloperError("`fee_render_model` is not set");

        if ($this->extra_data_model === ExtraDataModel::None && in_array('extra', $this->events_table_fields))
            throw new DeveloperError("`extra_data_model` is `None` when `extra` is listed among `events_table_fields`");

        if ($this->extra_data_model === ExtraDataModel::None && !is_null($this->extra_data_details))
            throw new DeveloperError("`extra_data_model` is `None`, but `extra_data_details` is not null");

        if ($this->extra_data_model === ExtraDataModel::Default)
        {
            if (!is_null($this->extra_data_details))
                throw new DeveloperError("`extra_data_model` is `Default`, but `extra_data_details` is not null");

            $this->extra_data_details = self::DefaultExtraDataArray;
        }

        if ($this->extra_data_model === ExtraDataModel::Identifier && !is_null($this->extra_data_details))
            throw new DeveloperError("`extra_data_model` is `Identifier` and `extra_data_details` is not null");

        if (is_null($this->privacy_model))
            throw new DeveloperError("`privacy_model` is not set");

        if ($this->currency_format === CurrencyFormat::Static && in_array('currency', $this->events_table_fields))
            throw new DeveloperError("`currency_format` is `Static`, but `currency` is listed among `events_table_fields`");

        if ($this->currency_format === CurrencyFormat::Static && is_null($this->currency))
            throw new DeveloperError("`currency_format` is `Static`, but `currency` is not set");

        if ($this->currency_format !== CurrencyFormat::Static && !is_null($this->currency))
            throw new DeveloperError("`currency_format` is not `Static`, but `currency` is set");

        if (is_null($this->events_table_fields))
            throw new DeveloperError("`events_table_fields` is not set");

        if (is_null($this->events_table_nullable_fields))
            throw new DeveloperError("`events_table_nullable_fields` is not set");

        if (!is_null($this->mempool_events_table_fields))
            throw new DeveloperError("`mempool_events_table_fields` is not developer definable");

        foreach ($this->events_table_fields as $field)
            if (!array_key_exists($field, EVENTS_TABLE_COLUMNS))
                throw new DeveloperError("`{$field}` is set in `database_fields` but is not valid");

        $this->mempool_events_table_fields = delete_array_values($this->events_table_fields, ['block', 'failed']);
        $this->mempool_events_table_fields[] = 'confirmed';

        foreach ($this->events_table_nullable_fields as $field)
            if (!array_key_exists($field, EVENTS_TABLE_COLUMNS))
                throw new DeveloperError("`{$field}` is set in `database_fields` but is not valid");

        foreach (EVENTS_TABLE_MANDATORY_COLUMNS as $field)
            if (!in_array($field, $this->events_table_fields))
                throw new DeveloperError("`{$field}` is mandatory, but not defined in `database_fields`");

        foreach ($this->events_table_nullable_fields as $field)
            if (in_array($field, EVENTS_TABLE_NOT_NULLABLE_COLUMNS))
                throw new DeveloperError("`{$field}` set in `nullable_database_fields` but can't be nullable");

        if (is_null($this->should_return_events))
            throw new DeveloperError("`should_return_events` is not set");

        if (is_null($this->should_return_currencies))
            throw new DeveloperError("`should_return_currencies` is not set");

        if ($this->should_return_events && is_null($this->allow_empty_return_events))
            throw new DeveloperError("`allow_empty_return_events` is not set");

        if ($this->should_return_currencies && is_null($this->allow_empty_return_currencies))
            throw new DeveloperError("`allow_empty_return_currencies` is not set");

        if ($this->should_return_currencies)
        {
            if (is_null($this->currencies_table_fields))
                throw new DeveloperError("`currencies_table_fields` is not set");

            if (is_null($this->currencies_table_nullable_fields))
                throw new DeveloperError("`currencies_table_nullable_fields` is not set");

            foreach ($this->currencies_table_fields as $field)
                if (!array_key_exists($field, CURRENCIES_TABLE_COLUMNS))
                    throw new DeveloperError("`{$field}` is set in `currencies_table_fields` but is not valid");

            foreach ($this->currencies_table_nullable_fields as $field)
                if (!array_key_exists($field, CURRENCIES_TABLE_COLUMNS))
                    throw new DeveloperError("`{$field}` is set in `currencies_table_nullable_fields` but is not valid");

            foreach (CURRENCIES_TABLE_MANDATORY_COLUMNS as $field)
                if (!in_array($field, $this->currencies_table_fields))
                    throw new DeveloperError("`{$field}` is mandatory, but not defined in `currencies_table_fields`");

            foreach ($this->currencies_table_nullable_fields as $field)
                if (in_array($field, CURRENCIES_TABLE_NOT_NULLABLE_COLUMNS))
                    throw new DeveloperError("`{$field}` set in `nullable_database_fields` but can't be nullable");
        }

        if ($this->should_return_currencies && $this->currency_format === CurrencyFormat::Static)
            throw new DeveloperError("`currency_format` can't be `Static` when `should_return_currencies` is set");

        if ($this->should_return_currencies && !in_array('currency', $this->events_table_fields))
            throw new DeveloperError("`currency` should be listed when `should_return_currencies` is set");

        if (is_null($this->first_block_date))
            throw new DeveloperError("`first_block_date` is not set");

        if (!$this->is_main && !is_null($this->handles_implemented))
            throw new DeveloperError("Handles can only be supported in the main module");

        if (!is_null($this->handles_implemented) && $this->handles_implemented)
        {
            if (!isset($this->handles_regex))
                throw new DeveloperError("`handles_regex` is not defined");
            if (!isset($this->api_get_handle))
                throw new DeveloperError("`api_get_handle` is not defined");
        }
    }

    abstract function post_post_initialize(); // This is defined in parent classes

    ///////////////////////
    // General functions //
    ///////////////////////

    final public function select_node(): string // Use this to select a random node
    {
        return $this->nodes[array_rand($this->nodes)];
    }

    final public function get_return_events(): ?array
    {
        return $this->return_events;
    }

    final public function set_return_events(?array $return_events): void
    {
        $this->return_events = $return_events;
    }

    final public function get_return_currencies(): ?array
    {
        return $this->return_currencies;
    }

    final public function set_return_currencies(?array $return_currencies): void
    {
        $this->return_currencies = $return_currencies;
    }

    ////////////////////////////////
    // Block processing functions //
    ////////////////////////////////

    abstract function pre_process_block($block_id);

    public function pre_process_mempool()
    {
        $this->pre_process_block(MEMPOOL);
    }

    abstract function ensure_block($block_id);

    final public function process_block($block_id)
    {
        if ($block_id === MEMPOOL)
            throw new DeveloperError("`process_block` can't process mempool");

        $this->block_id = $block_id;

        $this->ensure_block($block_id);

        if (is_null($this->block_hash))
            throw new DeveloperError("`block_hash` is `null` (`ensure_block()` implementation error)");

        $this->pre_process_block($block_id);

        $this->post_process_block();
    }

    final public function process_mempool()
    {
        $this->block_id = MEMPOOL;

        $this->pre_process_mempool();

        $this->post_process_mempool();
    }

    final public function post_process_mempool()
    {
        $this->post_process_block();
    }

    final public function post_process_block()
    {
        if ($this->should_return_events && is_null($this->return_events))
            throw new DeveloperError("`return_events` is `null`");

        if ($this->should_return_currencies && is_null($this->return_currencies) && $this->block_id !== MEMPOOL)
            throw new DeveloperError("`return_currencies` is `null`");

        if (!is_null($this->return_currencies) && $this->block_id === MEMPOOL)
            throw new DeveloperError("`return_currencies` is not `null` for mempool");

        if (in_array($this->return_events, [0, null]) && !is_null($this->return_currencies) && count($this->return_currencies) > 0)
            throw new DeveloperError("There are `return_currencies`, but no `return_events`");

        if ($this->block_id !== MEMPOOL && $this->should_return_events && !$this->allow_empty_return_events && !$this->return_events)
            throw new ModuleError("`return_events` is empty when `allow_empty_return_events` is false");

        if ($this->block_id !== MEMPOOL && $this->should_return_currencies && !$this->allow_empty_return_currencies && $this->return_events && !$this->return_currencies)
            throw new ModuleError("`return_currencies` is empty when `return_events` is not and `allow_empty_return_currencies` is false");

        if ($this->block_id !== MEMPOOL && is_null($this->block_time))
            throw new DeveloperError("`block_time` is `null`");

        if ($this->should_return_events)
        {
            if ($this->block_id === MEMPOOL)
            {
                foreach ($this->return_events as $k => $v)
                {
                    if (isset($v['block'])) unset ($this->return_events[$k]['block']);
                    if (isset($v['failed'])) unset ($this->return_events[$k]['failed']);
                    $this->return_events[$k]['confirmed'] = 'f';
                }
            }

            $previous_transaction_hash = null;
            $check_sign = '-';
            $check_sums = [];
            $check_sort_key = 0;

            foreach ($this->return_events as $ekey => $event)
            {
                if ($this->transaction_render_model === TransactionRenderModel::UTXO
                    && isset($event['transaction'])
                    && $event['transaction'] !== $previous_transaction_hash)
                {
                    $previous_transaction_hash = $event['transaction'];
                    $check_sign = '-';
                }

                foreach ($event as $field => $value)
                {
                    if (is_bool($value))
                    {
                        $this->return_events[$ekey][$field] = ($value) ? 't' : 'f';
                    }

                    if ($field === 'effect')
                    {
                        if ($this->privacy_model === PrivacyModel::Transparent)
                        {
                            if (!preg_match('/^-?\d+$/D', $value))
                                throw new DeveloperError("`effect` is not a valid number for `Transparent`: {$value}");
                        }
                        elseif ($this->privacy_model === PrivacyModel::Mixed)
                        {
                            if (!preg_match('/^-?\d+$/D', $value) && !in_array($value, ['-?', '+?']))
                                throw new DeveloperError("`effect` is not a valid number for `Mixed`: {$value}");
                        }
                        else // Shielded
                        {
                            if (!in_array($value, ['-?', '+?']))
                                throw new DeveloperError('`-?`, `+?` are the only variants for `effect` when `privacy_model` is `Shielded`');
                        }

                        if ($this->transaction_render_model === TransactionRenderModel::UTXO)
                        {
                            // `UTXO` model transactions should first contain negative events, then positive
                            if (str_contains($value, '-'))
                            {
                                if ($check_sign === '+')
                                    throw new DeveloperError('Wrong effect order for `transaction_render_model` set to `UTXO`');
                            }
                            else // +
                                $check_sign = '+';
                        }

                        if ($this->transaction_render_model === TransactionRenderModel::Even)
                        {
                            // `Even` model transactions should contain "negative-positive" pairs only
                            if (str_contains($value, '-'))
                            {
                                if ($check_sign !== '-')
                                    throw new DeveloperError('Wrong effect order for `transaction_render_model` set to `Even`');
                                else
                                    $check_sign = '+';
                            }
                            else // +
                            {
                                if ($check_sign !== '+')
                                    throw new DeveloperError('Wrong effect order for `transaction_render_model` set to `Even`');
                                else
                                    $check_sign = '-';
                            }
                        }
                    }

                    if ($field === 'time')
                    {
                        if (!(preg_match(StandardPatterns::YMDhis->value, $value) || preg_match(StandardPatterns::YMDhisu->value, $value)))
                        {
                            throw new DeveloperError("Date is in wrong format: {$value}");
                        }
                    }

                    if ($this->block_id !== MEMPOOL)
                    {
                        if (!in_array($field, $this->events_table_fields))
                            throw new DeveloperError("`{$field}` is returned for block, but not a part of `database_fields`");

                        if (is_null($value) && !in_array($field, $this->events_table_nullable_fields))
                            throw new DeveloperError("`{$field}` is null, but it's not present in `nullable_database_fields`");
                    }
                    else
                    {
                        if (!in_array($field, $this->mempool_events_table_fields))
                            throw new DeveloperError("`{$field}` is returned for event, but not a part of `database_mempool_fields`");

                        if (is_null($value) && !in_array($field, $this->events_table_nullable_fields))
                            throw new DeveloperError("`{$field}` is null, but it's not present in `nullable_database_fields`");
                    }
                }

                if ($this->block_id !== MEMPOOL)
                {
                    foreach ($this->events_table_fields as $field)
                        if (!array_key_exists($field, $event))
                            throw new DeveloperError("`{$field}` is a part of `database_fields`, but not present in the event");
                }
                else
                {
                    foreach ($this->mempool_events_table_fields as $field)
                        if (!array_key_exists($field, $event))
                            throw new DeveloperError("`{$field}` is a part of `database_mempool_fields`, but not present in the event");
                }

                if ($this->block_id !== MEMPOOL && !in_array($event['effect'], ['-?', '+?']))
                {
                    $check_sum_key = $event['transaction'] ?? 'block'; // This is for modules that don't have transactions

                    if (!isset($check_sums[$check_sum_key]))
                        $check_sums[$check_sum_key] = $event['effect'];
                    else
                        $check_sums[$check_sum_key] = bcadd($check_sums[$check_sum_key], $event['effect']);
                }

                if ($this->block_id !== MEMPOOL && $event['sort_key'] !== $check_sort_key++)
                    throw new DeveloperError("Sort key is out of order for {$event['sort_key']}");
            }

            if (!$this->ignore_sum_of_all_effects)
                foreach ($check_sums as $transaction => $sum)
                    if (!($sum === '0' || $sum === '-0'))
                        throw new DeveloperError("Sum of all effects is not 0 for {$transaction}: {$sum}");
        }

        if ($this->return_currencies)
        {
            foreach ($this->return_currencies as $currency)
            {
                foreach ($currency as $field => $value)
                {
                    if (!in_array($field, $this->currencies_table_fields))
                        throw new DeveloperError("`{$field}` is returned for currency, but not a part of `currencies_table_fields`");

                    if (is_null($value) && !in_array($field, $this->currencies_table_nullable_fields))
                        throw new DeveloperError("`{$field}` is null, but it's not present in `currencies_table_nullable_fields`");
                }

                foreach ($this->currencies_table_fields as $field)
                    if (!array_key_exists($field, $currency))
                        throw new DeveloperError("`{$field}` is a part of `currencies_table_fields`, but not present in currency");

                if (str_contains((string)$currency['id'], '/'))
                    throw new DeveloperError("Currency ids can't contain slashes");
            }
        }
    }

    ///////////
    // Tests //
    ///////////

    final public function test()
    {
        if (!isset($this->tests))
            throw new DeveloperError('No tests defined for this module');

        $errors = [];

        foreach ($this->tests as $test)
        {
            $block = $test['block'];
            $expected_result = $test['result'];

            $this->process_block($block);
            $events = $this->get_return_events();

            if (!isset($test['transaction']))
            {
                $got_result = serialize(['events' => $events, 'currencies' => $this->get_return_currencies()]);
            }
            else
            {
                $filtered_events = [];

                foreach ($events as $event)
                {
                    if (!is_null($event['transaction']) && str_contains($event['transaction'], $test['transaction']))
                        $filtered_events[] = $event;
                }

                $got_result = serialize(['events' => $filtered_events]);
            }

            if ($expected_result !== $got_result)
                $errors[] = $block;
        }

        if ($errors)
            throw new DeveloperError('Failed tests for blocks: ' . implode(', ', $errors) . ' ¯\_(ツ)_/¯');
    }
}
