<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This utility should be used to debug the modules.
 *  Usage: `php 3xpl.php`, or
 *         `php 3xpl.php <module> <action> <...params>`
 *  You can either input arguments as you go, or invoke the script with them.
 *  Example: `php 3xpl.php bnb-bep-20 M` to monitor for new BEP-20 transfers  */

require_once __DIR__ . '/Init.php';
require_once __DIR__ . '/Engine/DebugHelpers.php';

$input_argv = [];

// Greeting

echo cli_format_blue_reverse('  HELLO  ') . N;

// Selecting the module

echo cli_format_bold('Please select a module (number or name) or ' . cli_format_reverse('<T>') . ' for tests: ') . N;

$available_modules = env('MODULES');
$i_to_module = $to_echo = [];
$i = 0;

foreach ($available_modules as $available_module)
{
    $to_echo[] = $available_module . ' ' . cli_format_reverse('<' . $i. '>');
    $i_to_module[$i++] = $available_module;
}

echo join(', ', $to_echo) . N;

if (isset($argv[1]))
{
    $chosen_module = $argv[1];
    echo ":> {$chosen_module}\n";
}
else
{
    $chosen_module = (string)readline(':> ');
}

if ($chosen_module === 'T')
{
    $input_argv[] = 'T';
    echo N;

    $wins = [];
    $errors = [];

    foreach ($available_modules as $module_name)
    {
        echo "Running tests for {$module_name}...\n";
        $module = new (module_name_to_class($module_name))();

        try
        {
            $module->test();
            $wins[] = "Module {$module_name} is ok";
        }
        catch (Throwable $e)
        {
            $errors[] = "Module {$module_name}: {$e->getMessage()}";
        }
    }

    echo N;

    ddd($wins, $errors);
}

$input_argv[] = $chosen_module;

if (is_numeric($chosen_module)) // Allow both selecting by number or by full module name
{
    if (!isset($i_to_module[$chosen_module]))
        die(cli_format_error('Wrong choice for 1st param') . N);

    $module = new (module_name_to_class($i_to_module[$chosen_module]))();
}
else
{
    if (!in_array($chosen_module, $available_modules))
        die(cli_format_error('Wrong choice for 1st param') . N);

    $module = new (module_name_to_class($chosen_module))();
}

// Module actions

echo N . cli_format_bold('Please select an action: ') . N;
echo 'Get latest block number ' . cli_format_reverse('<L>') .
    ', Process block ' . cli_format_reverse('<B>') .
    ', Process back ' . cli_format_reverse('<PB>') .
    ', Process range ' . cli_format_reverse('<PR>') .
    ', Monitor blockchain ' . cli_format_reverse('<M>') .
    ', Check handle ' . cli_format_reverse('<H>') .
    ', Run tests ' . cli_format_reverse('<T>') .
    ', Transaction extras ' . cli_format_reverse('<AT>') .
    ', Address extras ' . cli_format_reverse('<AA>') .
    ', Currency supply ' . cli_format_reverse('<CS>') .
    ', Broadcast transaction ' . cli_format_reverse('<BT>') .
    ', Automatic test generation ' . cli_format_reverse('<AG>') . 
    N;

if (isset($argv[2]))
{
    $chosen_option = $argv[2];
    echo ":> {$chosen_option}\n";
}
else
{
    $chosen_option = (string)readline(':> ');
}

$input_argv[] = $chosen_option;

if (!in_array($chosen_option, ['L', 'B', 'PB', 'PR', 'M', 'H', 'T', 'AT', 'AA', 'CS', 'BT', 'AG']))
    die(cli_format_error('Wrong choice for 2nd param') . N);

echo N;

if ($chosen_option === 'L')
{
    $best_block = $module->inquire_latest_block();
    ddd($best_block);
}
elseif ($chosen_option === 'T')
{
    $module->test();
    ddd('Tests have completed successfully');
}
elseif ($chosen_option === 'B')
{
    echo cli_format_bold('Block number please...') . N;

    if (isset($argv[3]))
    {
        $chosen_block_id = (int)$argv[3];
        echo ":> {$chosen_block_id}\n";
    }
    else
    {
        $chosen_block_id = (int)readline(':> ');
    }

    $input_argv[] = $chosen_block_id;

    if ($chosen_block_id !== MEMPOOL)
        $module->process_block($chosen_block_id);
    else
        $module->process_mempool();

    echo N;

    echo cli_format_bold("What's next?\n" .
            cli_format_reverse('<E>') . ' to show all events, ' .
            cli_format_reverse('<{:transaction}>|<{:address}>') . ' to filter events, ' .
            cli_format_reverse('<10>') . ' for 10 first events, ' .
            cli_format_reverse('<C>') . ' for currencies, ' .
            cli_format_reverse('<D>') . ' to dump events into a TSV file, or ' .
            cli_format_reverse('<T>') . ' to generate a test') . N;

    if (isset($argv[4]))
    {
        $filter = $argv[4];
        echo ":> {$argv[4]}\n\n";
    }
    else
    {
        $filter = readline(':> ');
        echo N;
    }

    $input_argv[] = $filter;

    if ($filter === 'C')
    {
        ddd($module->get_return_currencies());
    }

    $events = $module->get_return_events();

    if ($filter === 'E')
    {
        ddd($events);
    }
    if ($filter === 'T')
    {
        echo cli_format_bold(
                cli_format_reverse('<A>') . ' for all events and currencies, ' .
                cli_format_reverse('<{:transaction}>') . ' for a single transaction\'s events') . N;

        if (isset($argv[5]))
        {
            $transaction = $argv[5];
            echo ":> {$transaction}\n";
        }
        else
        {
            $transaction = readline(':> ');
        }

        $input_argv[] = $transaction;

        if ($transaction === 'A')
        {
            ddd(serialize(['events' => $events, 'currencies' => $module->get_return_currencies()]));
        }
        else
        {
            $filtered_events = [];

            foreach ($events as $event)
            {
                if (!is_null($event['transaction']) && str_contains($event['transaction'], $transaction))
                    $filtered_events[] = $event;
            }

            ddd(serialize(['events' => $filtered_events]));
        }
    }
    elseif ($filter === 'D')
    {
        $fname = dump_tsv($module, $chosen_block_id);
        ddd("Dumped to {$fname}");
    }
    elseif ($filter === '10')
    {
        if (!$events)
            ddd($events);

        ddd(array_chunk($events, 10)[0]);
    }
    else
    {
        $output_events = [];

        foreach ($events as $event)
        {
            if ((!is_null($event['transaction']) && str_contains($event['transaction'], $filter)) ||
                (!is_null($event['address']) && str_contains($event['address'], $filter)))
                    $output_events[] = $event;
        }

        ddd($output_events);
    }
}
elseif ($chosen_option === 'PB')
{
    echo cli_format_bold('Start block number please...') . N;

    if (isset($argv[3]))
    {
        $chosen_block_id = (int)$argv[3];
        echo ":> {$chosen_block_id}\n";
    }
    else
    {
        $chosen_block_id = (int)readline(':> ');
    }

    $input_argv[] = $chosen_block_id;

    $start_block_id = $chosen_block_id > 0 ? $chosen_block_id : $module->inquire_latest_block();

    if ($start_block_id != $chosen_block_id)
        echo cli_format_bold('Processing blocks from latest down to genesis...');
    else
        echo cli_format_bold("Processing blocks from {$start_block_id} up to genesis...");
        for ($i = $start_block_id; $i != 0; $i--)
        {
            echo "\nProcessing block #{$i} ";
            PB:
            $t0 = microtime(true);
            try
            {
                $module->process_block($i);
            }
            catch (RequesterException)
            {
                echo cli_format_error('Requested exception');
                usleep(250000);
                goto PB;
            }

            $event_count = count($module->get_return_events() ?? []);
            $currency_count = count($module->get_return_currencies() ?? []);

            $time = number_format(microtime(true) - $t0, 4);

            echo "with {$event_count} events and {$currency_count} currencies in {$time} seconds";
            if ($event_count > 0)
                dump_tsv($module,$i);
        }
}
elseif ($chosen_option === 'PR')
{
    echo cli_format_bold('Start block number please...') . N;

    if (isset($argv[3]))
    {
        $start_block_id = (int)$argv[3];
        echo ":> {$start_block_id}\n";
    }
    else
    {
        $start_block_id = (int)readline(':> ');
    }

    $input_argv[] = $start_block_id;

    echo cli_format_bold('End block number please...') . N;

    if (isset($argv[4]))
    {
        $end_block_id = (int)$argv[4];
        echo ":> {$end_block_id}\n";
    }
    else
    {
        $end_block_id = (int)readline(':> ');
    }

    $input_argv[] = $end_block_id;

    echo N;
    echo cli_format_bold('Processing range of blocks...');

    $increment = $start_block_id > $end_block_id ? -1 : 1;

    for ($i = $start_block_id; $i != $end_block_id; $i = $i + $increment)
    {
        echo "\nProcessing block #{$i} ";
        PR:
        $t0 = microtime(true);
        try
        {
            $module->process_block($i);
        }
        catch (RequesterException)
        {
            echo cli_format_error('Requested exception');
            usleep(250000);
            goto PR;
        }

        $event_count = count($module->get_return_events() ?? []);
        $currency_count = count($module->get_return_currencies() ?? []);
        $time = number_format(microtime(true) - $t0, 4);

        echo "with {$event_count} events and {$currency_count} currencies in {$time} seconds";

        if ($event_count > 0)
            dump_tsv($module,$i);
    }
}
elseif ($chosen_option === 'M')
{
    $best_known_block = $module->inquire_latest_block();

    echo cli_format_bold('Monitoring the blockchain for new blocks...');

    while (true)
    {
        $current_block = $module->inquire_latest_block();

        if ($current_block > $best_known_block)
        {
            for ($i = $best_known_block + 1; $i <= $current_block; $i++)
            {
                back:
                echo "\nNew block #{$i} ";

                $t0 = microtime(true);

                try
                {
                    $module->process_block($i);
                }
                catch (RequesterException)
                {
                    echo cli_format_error('Requested exception');
                    usleep(250000);
                    goto back;
                }

                $event_count = count($module->get_return_events() ?? []);
                $currency_count = count($module->get_return_currencies() ?? []);

                $time = number_format(microtime(true) - $t0, 4);

                echo "with {$event_count} events and {$currency_count} currencies in {$time} seconds";
            }

            $best_known_block = $current_block;
        }
        else
        {
            echo cli_format_dim('.');
        }

        usleep(250000);
    }
}
elseif ($chosen_option === 'H') // Checking handles
{
    echo cli_format_bold('Handle please...') . N;

    if (isset($argv[3]))
    {
        $handle = $argv[3];
        echo ":> {$handle}\n";
    }
    else
    {
        $handle = readline(':> ');
    }

    $input_argv[] = $handle;

    ddd(($module->api_get_handle)($handle));
}
elseif ($chosen_option === 'AT') // Transaction specials
{
    echo cli_format_bold('Transaction id please...') . N;

    if (isset($argv[3]))
    {
        $transaction = $argv[3];
        echo ":> {$transaction}\n\n";
    }
    else
    {
        $transaction = readline(':> ');
    }

    $input_argv[] = $transaction;

    if (!method_exists($module, 'api_get_transaction_specials'))
        ddd('This function is undefined');
    else
        ddd($module->api_get_transaction_specials($transaction));
}
elseif ($chosen_option === 'AA') // Address specials
{
    echo cli_format_bold('Address please...') . N;

    if (isset($argv[3]))
    {
        $address = $argv[3];
        echo ":> {$address}\n\n";
    }
    else
    {
        $address = readline(':> ');
    }

    $input_argv[] = $address;

    if (!method_exists($module, 'api_get_address_specials'))
        ddd('This function is undefined');
    else
        ddd($module->api_get_address_specials($address));
}
elseif ($chosen_option === 'CS') // Currency supply
{
    echo cli_format_bold('Currency please...') . N;

    if (isset($argv[3]))
    {
        $currency = $argv[3];
        echo ":> {$currency}\n";
    }
    else
    {
        $currency = readline(':> ');
    }

    $input_argv[] = $currency;

    ddd($module->api_get_currency_supply($currency));
}
elseif ($chosen_option === 'BT') // Broadcast a transaction
{
    echo cli_format_bold('Data please...') . N;

    if (isset($argv[3]))
    {
        $data = $argv[3];
        echo ":> {$data}\n";
    }
    else
    {
        $data = readline(':> ');
    }

    $input_argv[] = $data;

    ddd($module->api_broadcast_transaction($data));
}
elseif ($chosen_option === 'AG')
{

    $block_start = 3;
    $chosen_module = $input_argv[0];
    $module_name = '';
    $tests_by_blocks = [];
    $tests_by_blocks_processed = [];
    if (is_numeric($chosen_module)) // Allow both selecting by number or by full module name
    {
        if (!isset($i_to_module[$chosen_module]))
        die(cli_format_error('Wrong choice for the first param') . N);
        $module_name = module_name_to_class($i_to_module[$chosen_module]);
    } 
    else 
    {
        if (!in_array($chosen_module, $available_modules))
        die(cli_format_error('Wrong choice for the 1st param') . N);
        $module_name = module_name_to_class($chosen_module);
    }

    $tests_class_name = $module_name . "Test";
    if (file_exists(__DIR__ . "/Modules/Tests/{$tests_class_name}.php"))
    {
        require_once __DIR__ . "/Modules/Tests/{$tests_class_name}.php";
        $tests_module = new ($tests_class_name)();
        $tests_by_blocks = $tests_module::$tests;
    }
    if (count($tests_by_blocks) > 0) 
    {
        echo cli_format_bold('There are some tests. If you choose the same block or transaction it will be rewritten!!!') . N;
        foreach ($tests_by_blocks as $b => $tests) 
        {
            $block_processed = (string)$tests['block'] . (isset($tests['transaction']) ? $tests['transaction'] : '');
            $tests_by_blocks_processed[$block_processed] = $b;
            echo cli_format_bold($tests['block'] . ((isset($tests['transaction']) ? ' transaction: ' . $tests['transaction'] : '')) . ' is tested') . N;
        }
    }

    do 
    {
        echo cli_format_bold('Block number please...') . N;

        if (isset($argv[$block_start])) 
        {
            $chosen_block_id = (int)$argv[$block_start];
            echo ":> {$chosen_block_id}\n";
        } else 
        {
            $chosen_block_id = (int)readline(':> ');
        }
        $block_start++;
        $input_argv[] = $chosen_block_id;

        if ($chosen_block_id !== MEMPOOL)
            $module->process_block($chosen_block_id);
        else
            return;

        echo N;

        $filter = 'T';
        if ($filter === 'T') 
        {
            echo cli_format_bold(
                cli_format_reverse('<A>') . ' for all events and currencies, ' .
                cli_format_reverse('<{:transaction}>') . ' for a single transaction\'s events') . N;

            if (isset($argv[$block_start])) 
            {
                $transaction = $argv[$block_start];
                echo ":> {$transaction}\n";
            } else {
                $transaction = readline(':> ');
            }
            $block_start++;
            $input_argv[] = $transaction;

            if ($transaction === 'A') 
            {
                if (isset($tests_by_blocks_processed[$chosen_block_id])) 
                {
                    $tests_by_blocks[$tests_by_blocks_processed[$chosen_block_id]] = [
                        'block' => $chosen_block_id, 
                        'result' => serialize(['events' => $module->get_return_events(), 'currencies' => $module->get_return_currencies()]),
                    ];
                } else {
                    $tests_by_blocks[] = [
                        'block' => $chosen_block_id, 
                        'result' => serialize(['events' => $module->get_return_events(), 'currencies' => $module->get_return_currencies()]),
                    ];
                }
            } else {
                $filtered_events = [];
                $events = $module->get_return_events();
                foreach ($events as $event)
                {
                    if (!is_null($event['transaction']) && str_contains($event['transaction'], $transaction))
                        $filtered_events[] = $event;
                }

                $block_transaction_merged = $chosen_block_id . $transaction;
                if (isset($tests_by_blocks_processed[$block_transaction_merged])) 
                {
                    $tests_by_blocks[$tests_by_blocks_processed[$block_transaction_merged]] = [
                        'block' => $chosen_block_id, 
                        'transaction' => $transaction,
                        'events' => serialize(['events' => $filtered_events]),
                    ];
                } else {
                    $tests_by_blocks[] = [
                        'block' => $chosen_block_id, 
                        'transaction' => $transaction,
                        'events' => serialize(['events' => $filtered_events]),
                    ];
                }
            }

        }

        echo cli_format_bold(
            cli_format_reverse('<S>') . ' for break and generate tests file, ' .
            cli_format_reverse('<C>') . ' for continue'
        ) . N;

        if (isset($argv[$block_start])) 
        {
            $chosen_block_id = $argv[$block_start];
            echo ":> {$chosen_block_id}\n";
        } else 
        {
            $chosen_block_id = readline(':> ');
        }
        $block_start++;
        $input_argv[] = $chosen_block_id;

        if ($chosen_block_id === 'S')
        {
            autogen_test($tests_by_blocks, $module_name);
            ddd();
        }
        if ($chosen_block_id === 'C')
        {
            continue;
        }

    }while(true);
}
