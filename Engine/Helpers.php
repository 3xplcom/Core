<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Various useful functions  */

// Simplest debug function
function dd(mixed $output = "Things are only impossible until they are not."): never
{
    var_dump($output) & die();
}

// print_r for exceptions
function print_e(Throwable $e, $return = false): null|string
{
    $message = rmn("{$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");

    if (!$return)
    {
        echo $message . "\n";
        return null;
    }
    else
    {
        return $message;
    }
}

// Removes excess whitespaces, etc.
function rmn($string)
{
    return trim(str_replace("\n", "⏎", preg_replace('/ +/', ' ', $string)));
}

// Return current timestamp
function pg_microtime()
{
    [$usec, $sec] = explode(' ', microtime());
    $usec = number_format((float)$usec, 6);
    $usec = str_replace("0.", ".", $usec);
    return date('Y-m-d H:i:s', (int)$sec) . $usec;
}

// Bold formatting in CLI
function cli_format_bold($string)
{
    return "\033[1m{$string}\033[0m";
}

// Dimmed formatting in CLI
function cli_format_dim($string)
{
    return "\033[2m{$string}\033[0m";
}

// Background formatting in CLI
function cli_format_reverse($string)
{
    return "\033[1;7m{$string}\033[0m";
}

// Error formatting in CLI
function cli_format_error($string)
{
    return "\033[1;37;41m{$string}\033[0m";
}

// Blue background in CLI
function cli_format_blue_reverse($string)
{
    return "\033[1;30;46m{$string}\033[0m";
}

// Convert module name to class
function module_name_to_class(string $name): string
{
    return envm(module_name: $name, key: 'CLASS',
        default: new DeveloperError("module_name_to_class(name: ({$name})): unknown name (is `CLASS` defined in the config?)"));
}

// This is a stub function which supposed to look into some database of already known currencies and return
// the list of currencies that need to be processed
function check_existing_currencies(array $input, CurrencyFormat $module_currency_format): array
{
    return $input;
}

// Math conversions

function hex2dec($num)
{
    $num = strtolower($num);
    $basestr = '0123456789abcdef';
    $base = strlen($basestr);
    $dec = '0';
    $num_arr = str_split((string)$num);
    $cnt = strlen($num);

    for ($i = 0; $i < $cnt; $i++)
    {
        $pos = strpos($basestr, $num_arr[$i]);

        if ($pos === false)
        {
            throw new ErrorException(sprintf('hex2dec: Unknown character %s at offset %d', $num_arr[$i], $i));
        }

        $dec = bcadd(bcmul($dec, (string)$base), (string)$pos);
    }

    return $dec;
}

function dec2hex($number) // https://stackoverflow.com/questions/52995138/php-gives-different-hex-value-than-an-online-tool
{
    $hexvalues = ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F'];
    $hexval = '';

    while ($number != '0')
    {
        $hexval = $hexvalues[bcmod($number, '16')] . $hexval;
        $number = bcdiv($number, '16', 0);
    }

    return $hexval;
}

function to_int64_from_0xhex(string $value): int
{
    if (!str_starts_with($value, '0x')) throw new DeveloperError("to_int64_from_0xhex({$value}): wrong input");
    $rvalue = ltrim(substr($value, 2), '0'); // Removes 0x and excess zeroes

    if (strlen($rvalue) > 16)
        throw new MathException("to_int64_from_0xhex({$value}): would overflow int64");

    return (int)hex2dec($rvalue);
}

function to_0xhex_from_int64(int $value): string
{
    return '0x' . dechex($value);
}

function to_int256_from_0xhex(?string $value): ?string
{
    if (is_null($value)) return null;
    if (!str_starts_with($value, '0x')) throw new DeveloperError("to_int256_from_0xhex({$value}): wrong input");

    return hex2dec(substr($value, 2));
}

function to_int256_from_hex(?string $value): ?string
{
    if (is_null($value)) return null;
    if ($value === '-1') return '-1'; // Special case

    return hex2dec($value);
}

// Reordering JSON-RPC 2.0 response by id
function reorder_by_id(&$curl_results_prepared)
{
    usort($curl_results_prepared, function($a, $b) {
        return  [$a['id'],
            ]
            <=>
            [$b['id'],
            ];
    });
}

// Removing array values
function delete_array_values(array $arr, array $remove): array // https://stackoverflow.com/questions/7225070/php-array-delete-by-value-not-key
{
    return array_filter($arr, fn($e) => !in_array($e, $remove));
}

// Not showing passwords in CLI output
function remove_passwords($url)
{
    $url = parse_url($url);
    return ($url['scheme'] ?? '').'://'.($url['host'] ?? '').($url['path'] ?? '').($url['query'] ?? '');
}

// Returns standard unixtime
function to_timestamp_from_long_unixtime(string $long_unixtime): string
{
    // 1555400628000
    return DateTime::createFromFormat('U.u', bcdiv($long_unixtime, '1000', 3))->format("Y-m-d H:i:s");
}

function remove_0x_safely(string $string): string
{
    if (substr($string, 0, 2) !== '0x')
        throw new DeveloperError("remove_0x_safely({$string}): missing 0x");
    return substr($string, 2);
}
