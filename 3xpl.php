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
    ', Currency supply ' . cli_format_reverse('<CS>') .
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

if (!in_array($chosen_option, ['L', 'B', 'PB', 'PR', 'M', 'H', 'T', 'AT', 'CS']))
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
        // TSV format: blockchain <tab> module <tab> block <tab> transaction <tab> sort_key <tab> time <tab>
        //             address <tab> currency <tab> sign <tab> effect <tab> valid <tab> extra <?tab> ?extra_indexed

        $tsv_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'currency', 'sign', 'effect', 'valid', 'extra'];

        $tsv = '';

        foreach ($events as $event)
        {
            $this_tsv = [];

            if ($event['address'] === '0x00')
                $event['address'] = 'the-void';

            if ($module->currency_format === CurrencyFormat::Static)
                $event['currency'] = $module->currency;
            else
                $event['currency'] = ($module->complements ?? $module->module) . '/' . $event['currency'];

            $event['sign'] = (str_contains($event['effect'], '-')) ? '-1' : '1';

            if ($module->privacy_model === PrivacyModel::Transparent)
            {
                $event['effect'] = str_replace('-', '', $event['effect']);
            }
            elseif ($module->privacy_model === PrivacyModel::Mixed)
            {
                if (in_array($event['effect'], ['-?', '+?']))
                    $event['effect'] = null;
                else
                    $event['effect'] = str_replace('-', '', $event['effect']);
            }
            else // Shielded
            {
                $event['effect'] = null;
            }

            if (isset($event['failed']))
                $event['valid'] = ($event['failed'] === true || $event['failed'] === 't') ? '-1' : '1';
            else
                $event['valid'] = '1';

            if (isset($event['extra']))
                $event['extra'] = '\\\\x' . bin2hex($event['extra']);

            $this_tsv[] = $module->blockchain;
            $this_tsv[] = $module->module;

            foreach ($tsv_fields as $f)
                $this_tsv[] = (isset($event[$f])) ? $event[$f] : '\N';

            if (in_array('extra_indexed', $module->events_table_fields))
                $this_tsv[] = (!is_null($event['extra_indexed'])) ? '\\\\x' . bin2hex($event['extra_indexed']) : '\N';

            $tsv .= join(T, $this_tsv) . N;
        }

        $fname = "Dumps/3xplor3r_{$module->blockchain}_{$module->module}_events_{$chosen_block_id}.tsv";

        if (!file_exists('Dumps'))
            mkdir('Dumps', 0777);

        $f = fopen($fname, 'w');
        fwrite($f, $tsv);
        fclose($f);

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

    $start_block_id = $chosen_block_id > 0 ? $chosen_block_id : $module->inquire_latest_block();

    if ($start_block_id != $chosen_block_id)
        echo cli_format_bold('Processing blocks from latest down to genesis...');
    else
        echo cli_format_bold("Processing blocks from {$start_block_id} up to genesis...");
        for ($i = $start_block_id; $i != 0; $i--)
        {
            echo "\nProcessing block #{$i} ";

            $t0 = microtime(true);

            try
            {
                $module->process_block($i);
            }
            catch (RequesterException)
            {
                echo cli_format_error('Requested exception');
                usleep(250000);
            }

            $event_count = count($module->get_return_events() ?? []);
            $currency_count = count($module->get_return_currencies() ?? []);

            $time = number_format(microtime(true) - $t0, 4);

            echo "with {$event_count} events and {$currency_count} currencies in {$time} seconds";
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
    echo N;

    echo cli_format_bold('Processing range of blocks...');
    $increment = $start_block_id > $end_block_id ? -1 : 1;
    for ($i = $start_block_id; $i != $end_block_id; $i=$i+$increment)
        {
            echo "\nProcessing block #{$i} ";

            $t0 = microtime(true);

            try
            {
                $module->process_block($i);
            }
            catch (RequesterException)
            {
                echo cli_format_error('Requested exception');
                usleep(250000);
            }

            $event_count = count($module->get_return_events() ?? []);
            $currency_count = count($module->get_return_currencies() ?? []);

            $time = number_format(microtime(true) - $t0, 4);

            echo "with {$event_count} events and {$currency_count} currencies in {$time} seconds";
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

    if (!method_exists($module, 'api_get_transaction_specials'))
        ddd('This function is undefined');
    else
        ddd($module->api_get_transaction_specials($transaction));
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

    ddd($module->api_get_currency_supply($currency));
}
