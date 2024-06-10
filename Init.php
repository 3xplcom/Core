<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the initialization script  */

require_once __DIR__ . '/Engine/Env.php';
require_once __DIR__ . '/Engine/Enums.php';
require_once __DIR__ . '/Engine/Exceptions.php';
require_once __DIR__ . '/Engine/Helpers.php';
require_once __DIR__ . '/Engine/Requester.php';
require_once __DIR__ . '/Engine/Database.php';
require_once __DIR__ . '/Engine/ModuleInterface.php';

spl_autoload_register(function($class)
{
    if (file_exists(__DIR__ . "/Modules/{$class}.php"))
    {
        include __DIR__ . "/Modules/{$class}.php";
    }
    elseif (file_exists(__DIR__ . "/Modules/Common/{$class}.php"))
    {
        include __DIR__ . "/Modules/Common/{$class}.php";
    }
    else
    {
        throw new DeveloperError("Class {$class} not found");
    }
});

try
{
    parse_env_file();
}
catch (Throwable $e)
{
    $error_class = get_class($e);
    echo cli_format_error("CRITICAL: couldn't read the config file. Caught {$error_class}: " . print_e($e, true)) . "\n";
    die();
}
