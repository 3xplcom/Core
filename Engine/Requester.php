<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Various curl functions for requesting data from nodes  */

// Just a single curl request
function requester_single($daemon, $endpoint = '', $params = [], $result_in = '', $timeout = 600, $valid_codes = [200], $no_json_encode = false, $flags = [])
{
    static $curl = null;

    if (is_null($curl))
    {
        $curl = curl_init();
    }

    // We send a POST request if $params is set, GET otherwise

    if (!$params) // GET
    {
        $options = [CURLOPT_URL            => $daemon . $endpoint,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPGET        => true, // There's an issue: if previously we've used (static) $curl for POST, it doesn't go back to the default GET method
                    CURLOPT_TIMEOUT        => $timeout,
        ];
    }
    else
    {
        if (!$no_json_encode)
        {
            $options = [CURLOPT_URL            => $daemon . $endpoint,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($params),
                        CURLOPT_TIMEOUT        => $timeout,
            ];
        }
        else // Send params directly
        {
            $options = [CURLOPT_URL            => $daemon . $endpoint,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $params,
                        CURLOPT_TIMEOUT        => $timeout,
            ];
        }
    }

    curl_setopt_array($curl, $options);

    $output = curl_exec($curl);

    $in = curl_getinfo($curl);

    $daemon_clean = remove_passwords($daemon);

    if (env('DEBUG_REQUESTER_FULL_OUTPUT_ON_EXCEPTION', false))
        $params_log = json_encode($params);
    else
        $params_log = substr(json_encode($params), 0, 100);

    if (!in_array($in['http_code'], $valid_codes))
    {
        if ($in['http_code'] === 0)
            throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: code 0 (timeout?)");
        else
            throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: wrong code: {$in['http_code']}");
    }

    curl_close($curl);
    if (is_null($output))
        throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: output is `null`");
    if ($output === '')
        throw new RequesterEmptyResponseException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: output is an empty string");
    if ($output === false)
        throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: output is false (timeout?)");
    if (trim($output) === '{}' || trim($output) === '[]')
        throw new RequesterEmptyArrayInResponseException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: output is an empty array");

    // Here we add quotes to all numeric values not to lose precision if some are larger than int64.
    // Note that this doesn't work good with values like `2.5e-8`, so there's the IgnoreAddingQuotesToNumbers option
    // Also it doesn't work with negative numbers, and doesn't process integer arrays (e.g. `[4359895000,2039280]`)
    // TODO: this should be rewritten to support all the aforementioned cases

    if (in_array(RequesterOption::RecheckUTF8, $flags)) // Some nodes may return invalid UTF-8 sequences which lead to invalid JSON
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8'); // Technically, this is invalid JSON, but ¯\_(ツ)_/¯

    if (in_array(RequesterOption::TrimJSON, $flags)) // Some nodes output something like `"type": 0` instead of `"type":0` ¯\_(ツ)_/¯
        $output = preg_replace('/("\w+"):((\s?)(-?[\d.]+))/', '\\1:"\\4"', $output);

    if (!in_array(RequesterOption::IgnoreAddingQuotesToNumbers, $flags))
        $output = preg_replace('/("\w+"):(-?[\d.]+)/', '\\1:"\\2"', $output);

    if (!($output = json_decode($output, associative: true, depth: 4096, flags: JSON_BIGINT_AS_STRING)))
    {
        $e_json = json_last_error_msg();
        $e_preg = preg_last_error_msg();

        throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: bad JSON; preg error: {$e_preg}, json error: {$e_json}"); //  . print_r($output, true)
    }

    if (isset($output['error']))
    {
        throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) errored: " . print_r($output['error'], true));
    }

    if ($result_in)
        if (!array_key_exists($result_in, $output))
            throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: no result key");
        elseif (!isset($output[$result_in]))
            throw new RequesterException("requester_request(daemon:({$daemon_clean}), endpoint:({$endpoint}), params:({$params_log}), result_in:({$result_in})) failed: result is null");
        else
            return $output[$result_in];
    else
        return $output;
}

// Multiple curl requests
// $limit is the limit on the number of concurrent requests.
function requester_multi($single_set, $limit, $timeout = 600, $valid_codes = [200], $post_process = false)
{
    $valid_codes_with_0 = array_merge($valid_codes, [0]);

    $curl_results = [];
    $current_shift = $limit;

    $expected_valid_results = count($single_set);
    $got_valid_results = 0;

    $mh = curl_multi_init();

    $current_chunk = array_slice($single_set, 0, $limit);

    foreach ($current_chunk as $m)
    {
        curl_multi_add_handle($mh, $m);
    }

    do
    {
        $status = curl_multi_exec($mh, $active);

        foreach ($current_chunk as $k => $ci)
        {
            $in = curl_getinfo($ci);

            $daemon_clean = remove_passwords($in['url']);

            if (!in_array($in['http_code'], $valid_codes_with_0))
            {
                try
                {
                    $error_content = curl_multi_getcontent($ci);
                }
                catch (Exception)
                {
                    $error_content = '/Exception/';
                }

                throw new RequesterException("requester_multi(daemon:({$daemon_clean})) wrong code: {$in['http_code']}, content: " . $error_content);
            }

            if (($in['http_code'] === 0) && ($in['total_time'] >= $timeout))
            {
                throw new RequesterException("requester_multi(daemon:({$daemon_clean})) failed: timeout");
            }

            if (in_array($in['http_code'], $valid_codes) && ($in['size_download'] === $in['download_content_length']))
            {
                got_valid_result:

                $curl_results[] = ($post_process) ? $post_process(curl_multi_getcontent($ci)) : curl_multi_getcontent($ci);
                curl_multi_remove_handle($mh, $ci);
                curl_close($ci);

                unset($current_chunk[$k]);

                if (isset($single_set[$current_shift]))
                {
                    curl_multi_add_handle($mh, $single_set[$current_shift]);
                    $current_chunk[] = $single_set[$current_shift];
                    if (!$active) $active = true;
                }

                $current_shift++;
                $got_valid_results++;
            }
            elseif (in_array($in['http_code'], $valid_codes) && $in['download_content_length'] === -1.0)
            {
                $pause_status = curl_pause($ci, CURLPAUSE_ALL);

                if ($pause_status !== 0)
                {
                    goto got_valid_result;
                }
                else
                {
                    curl_pause($ci, CURLPAUSE_CONT);
                }
            }
        }
    }
    while ($active && $status == CURLM_OK);

    curl_multi_close($mh);

    if ($expected_valid_results !== $got_valid_results)
    {
        throw new RequesterException("requester_multi() failed: some results are missing ({$got_valid_results}/{$expected_valid_results})");
    }

    return $curl_results;
}

function requester_multi_prepare($daemon, $endpoint = '', $params = [], $timeout = 600, $no_json_encode = false)
{
    $curl = curl_init();

    if (!$params) // GET
    {
        $options = [CURLOPT_URL            => $daemon . $endpoint,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPGET        => true,
                    CURLOPT_TIMEOUT        => $timeout,
        ];
    }
    else // POST
    {
        if (!$no_json_encode)
        {
            $options = [CURLOPT_URL            => $daemon . $endpoint,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($params),
                        CURLOPT_TIMEOUT        => $timeout,
            ];
        }
        else // Send params directly
        {
            $options = [CURLOPT_URL            => $daemon . $endpoint,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $params,
                        CURLOPT_TIMEOUT        => $timeout,
            ];
        }
    }

    curl_setopt_array($curl, $options);

    return $curl;
}

function requester_multi_process($output, $result_in = '', $ignore_errors = false, $flags = [])
{
    if (is_null($output))
        throw new RequesterException("requester_multi_process(result_in:({$result_in})) failed: output is `null`");
    if ($output === '')
        throw new RequesterEmptyResponseException("requester_multi_process(result_in:({$result_in})) failed: output is an empty string");

    if (env('DEBUG_REQUESTER_FULL_OUTPUT_ON_EXCEPTION', false))
        $output_log = json_encode($output);
    else
        $output_log = substr(json_encode($output), 0, 100);

    if (in_array(RequesterOption::RecheckUTF8, $flags)) // Some nodes may return invalid UTF-8 sequences which lead to invalid JSON
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

    if (in_array(RequesterOption::TrimJSON, $flags))
        $output = preg_replace('/("\w+"):((\s?)(-?[\d.]+))/', '\\1:"\\4"', $output);

    if (!in_array(RequesterOption::IgnoreAddingQuotesToNumbers, $flags))
        $output = preg_replace('/("\w+"):(-?[\d.]+)/', '\\1:"\\2"', $output);

    if (!($output = json_decode($output, associative: true, depth: 4096, flags: JSON_BIGINT_AS_STRING)))
        throw new RequesterException("requester_multi_process(output:({$output_log}), result_in:({$result_in})) failed: bad JSON");

    if (isset($output['error']) && !$ignore_errors)
        throw new RequesterException("requester_multi_process(output:({$output_log}), result_in:({$result_in})) errored: " . print_r($output['error'], true));

    if ($result_in)
        if (!array_key_exists($result_in, $output))
            throw new RequesterException("requester_multi_process(output:({$output_log}), result_in:({$result_in})) failed: no result key");
        elseif (!isset($output[$result_in]))
            throw new RequesterException("requester_multi_process(output:({$output_log}), result_in:({$result_in})) failed: result is null");
        else
            return $output[$result_in];
    else
        return $output;
}

// Processes results from requester_multi()
function requester_multi_process_all(array $multi_results, string $result_in = '', bool $reorder = true, false|string|Callable $post_process = false, $flags = []): array
{
    $output = [];

    foreach ($multi_results as $v)
        $output[] = requester_multi_process($v, flags: $flags);

    if ($reorder)
        reorder_by_id($output);

    if ($result_in)
    {
        $result_output = [];

        if (env('DEBUG_REQUESTER_FULL_OUTPUT_ON_EXCEPTION', false))
            $output_log = json_encode($output);
        else
            $output_log = substr(json_encode($output), 0, 100);

        foreach ($output as $o)
            if (!array_key_exists($result_in, $o))
                throw new RequesterException("requester_multi_process_all(output:({$output_log}), result_in:({$result_in})) failed: no result key");
            elseif (!isset($o[$result_in]))
                throw new RequesterException("requester_multi_process_all(output:({$output_log}), result_in:({$result_in})) failed: result is null");
            else
                $result_output[] = (!$post_process) ? $o[$result_in] : $post_process($o[$result_in]);

        return $result_output;
    }
    else
    {
        if ($post_process)
            foreach ($output as &$o)
                $o = $post_process($o);

        return $output;
    }
}

enum RequesterOption
{
    case IgnoreAddingQuotesToNumbers;
    case TrimJSON;
    case RecheckUTF8;
}
