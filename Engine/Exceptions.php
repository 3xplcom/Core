<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Errors and exceptions  */

error_reporting(E_ALL);

// Error classes

class DeveloperError extends Error {} // This is for misconfigured modules
class ModuleError extends Error {} // This should be used by module developers in case something is out of order

class RequesterException extends Exception {} // Curl errors
class RequesterEmptyResponseException extends RequesterException {} // This can be caught if an empty response is considered to be a valid response
class RequesterEmptyArrayInResponseException extends RequesterException {} // This can be caught if an empty array response is considered to be a valid response
class MathException extends Exception {} // This is for math exceptions
class ConsensusException extends Exception {} // This is a special exception that should be used in ensure_block() in case
// if different nodes return different block data
class ModuleException extends Exception {} // Unlike ModuleError, this can be used for situations when reprocessing the block may fix the issue

// Error handling
function error_handler($severity, $message, $file, $line): void
{
    if (!(error_reporting() & $severity))
        return;

    $message = rmn($message);
    $stack_trace = (env('STACK_TRACE', false)) ? rmn(print_r(debug_backtrace(), true)) : '[x]';

    $word = cli_format_error(str_pad('*ERROR', 11, pad_type: STR_PAD_BOTH));
    $log_row = cli_format_dim(pg_microtime()) . "     {$word}\tGot level {$severity} error: {$message} in {$file} at line {$line}, st: {$stack_trace}\n";
    $exception_message = "Got level {$severity} error: {$message} in {$file} at line {$line}, st: {$stack_trace}";

    echo $log_row;
    throw new ErrorException($exception_message, 0, $severity, $file, $line);
}

// Exception handling
function exception_handler(Throwable $e): void
{
    $exception_class = get_class($e);

    $word = cli_format_error(str_pad('*UNCAUGHT', 11, pad_type: STR_PAD_BOTH));
    $log_row = cli_format_dim(pg_microtime()) . "     {$word}\t{$exception_class}: {$e->getMessage()}\n";

    echo $log_row;

    throw new Exception('Failed', previous: $e);
}

set_error_handler("error_handler", E_ALL);
set_exception_handler("exception_handler");
