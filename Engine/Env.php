<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Functions to work with the .env file  */

const ENV_FILENAME = '.env';

// Parses the .env file
function parse_env_file()
{
    $env_file_location = __DIR__ . '/../' . ENV_FILENAME;
    $env_file_contents = file_get_contents($env_file_location);
    $_ENV['__HASH'] = hash('crc32b', $env_file_contents);
    $lines = explode("\n", $env_file_contents);

    $line_number = 0;

    foreach ($lines as $line)
    {
        $line_number++;
        $line = trim($line);

        if (str_starts_with($line, '#') || $line === '')
        {
            continue;
        }
        elseif (preg_match('/^([a-zA-Z0-9_\/-]+)=(.+)$/', $line, $m))
        {
            $_ENV[trim($m[1])] = trim($m[2]);
        }
        elseif (preg_match('/^(([a-zA-Z0-9_\/-]+)\[])=(.+)$/', $line, $m))
        {
            if (!isset($_ENV[($m[2])])) $_ENV[($m[2])] = [];
            $_ENV[trim($m[2])][] = trim($m[3]);
        }
        elseif (preg_match('/^(([a-zA-Z0-9_\/-]+)\[([a-zA-Z0-9_\/-]+)])=(.+)$/', $line, $m))
        {
            $_ENV[trim($m[2])][trim($m[3])] = trim($m[4]);
        }
        else
        {
            throw new DeveloperError("Incorrect .env file contents on line {$line_number}");
        }
    }
}

// Reads setting (VAR)
function env(string $key, mixed $default = null): mixed
{
    $return = $_ENV[$key] ?? $default ?? throw new DeveloperError("env variable {$key} is null");

    return env_process_var($return, $default);
}

// Reads module setting (MODULE_module-name_VAR)
function envm(string $module_name, string $key, mixed $default = null): mixed
{
    $return = $_ENV["MODULE_{$module_name}_{$key}"] ?? $default ?? throw new DeveloperError("env variable {$module_name}:{$key} is null");

    return env_process_var($return, $default);
}

// Inner function
function env_process_var(mixed $return, mixed $default): mixed
{
    if ($return instanceof Throwable)
    {
        throw $default;
    }

    if (is_string($return) && (preg_match('/^\d+$/D', $return)))
    {
        if ($return > (string)PHP_INT_MAX)
            throw new DeveloperError("Value {$return} is too big for `int` in the config");

        return (int)$return;
    }

    if (is_string($return) && (preg_match('/^\d+\.\d+$/D', $return)))
    {
        return (float)$return;
    }

    if ($return === 'false' || $return === 'FALSE')
        return false;

    if ($return === 'true' || $return === 'TRUE')
        return true;

    return $return;
}
