<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

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

echo cli_format_bold('Please select a module (number or name): ') . N;

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
    ', Monitor blockchain ' . cli_format_reverse('<M>') .
    ', Check handle ' . cli_format_reverse('<H>') .
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

if (!in_array($chosen_option, ['L', 'B', 'M', 'H']))
    die(cli_format_error('Wrong choice for 2nd param') . N);

echo N;

if ($chosen_option === 'L')
{
    $best_block = $module->inquire_latest_block();
    ddd($best_block);
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

    echo cli_format_bold("What's next? <D> to show all events, <{:transaction}>|<{:address}> to filter, <T> for 10 first events, or <C> for currencies") . N;

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

    if ($filter === 'D')
    {
        ddd($events);
    }
    elseif ($filter === 'T')
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
