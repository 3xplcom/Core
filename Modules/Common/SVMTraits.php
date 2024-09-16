<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Solana functions and enums  */

require_once __DIR__ . '/../../Engine/Crypto/Base58.php';
require_once __DIR__ . '/../../Engine/Crypto/Sodium.php';

trait SVMTraits
{
    public function inquire_latest_block()
    {
        return requester_single($this->select_node(),
            params: ['method' => 'getSlot', 'params' => [['commitment' => 'finalized']], 'id' => 0, 'jsonrpc' => '2.0'],
            result_in: 'result',
            timeout: $this->timeout,
            flags: [RequesterOption::IgnoreAddingQuotesToNumbers]); // IgnoreAddingQuotesToNumbers is needed as there may be non-standard
                                                                    // numbers in the output
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $this->block_hash = ''; // As we query finalized slots only, we don't expect anything to get "orphaned"
    }

    // Gets currency metadata
    function process_currencies(array $currencies)
    {
        $result = [];
        $tokens_supply = $this->get_token_supply($currencies);
        if ($this->currency_type == CurrencyType::NFT)
            $currencies = array_filter($currencies,
                function ($currency, $i) use ($tokens_supply) { return $currency["decimals"] == "0" && ($tokens_supply[$currency['id']] == "1" || ($currency['burn'] && $tokens_supply[$currency['id']] == "0") );},
                ARRAY_FILTER_USE_BOTH);
        else
            $currencies = array_filter($currencies,
                function ($currency, $i) use ($tokens_supply) { return (!in_array($tokens_supply[$currency['id']],["1","0"]) || ($tokens_supply[$currency['id']] === "0" && !$currency['burn']));},
                ARRAY_FILTER_USE_BOTH);

        // 1. Try to get the metadata from tokens_list file
        // 2. Try to get Token2022 metadata
        // 3. Try to get MetaPlex token metadata

        foreach ($currencies as $currency)
        {
            // 1. Try to get the metadata from tokens_list file
            $meta = $this->tokens_list['tokens'][$currency["id"]] ?? [];
            if (count($meta) > 0)
            {
                $result[] = [
                    'id' => $currency["id"],
                    'name' => $meta['name'],
                    'symbol' => $meta['symbol'],
                    'decimals' => $currency['decimals'],
                ];
                continue;
            }

            //  2. Try to get Token2022 metadata

            $meta['symbol'] = $meta['name'] = null;

            // Check whether mint address still exists
            // as it may not exist if creation transaction fails or
            // it's somehow expired
            $token_meta_info = requester_single($this->select_node(),
                params: [
                    'method'  => 'getAccountInfo',
                    'params'  => [$currency["id"], ['encoding' => 'jsonParsed']],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout,
                flags: [RequesterOption::IgnoreAddingQuotesToNumbers]
            );
            if (is_null($token_meta_info['value']))
            {
                $result[] = [
                    'id' => $currency["id"],
                    'name' => '',
                    'symbol' => '',
                    'decimals' => $currency["decimals"],
                ];
                continue;
            }

            $token_meta_info = $token_meta_info['value']['data']['parsed']['info'];
            // Try to get Token2022 metadata first
            if (isset($token_meta_info['extensions']))
            {
                foreach ($token_meta_info['extensions'] as $ext)
                {
                    if ($ext['extension'] !== 'tokenMetadata')
                        continue;
                    $meta['name'] = $ext['state']['name'] ?? null;
                    $meta['symbol'] = $ext['state']['symbol'] ?? null;
                }
            }

            // If Token2022 meta exists
            if (!is_null($meta['name']) && !is_null($meta['symbol']))
            {
                $result[] = [
                    'id' => $currency["id"],
                    'name' => $meta['name'],
                    'symbol' => $meta['symbol'],
                    'decimals' => $currency["decimals"],
                ];
                continue;
            }

            // 3. Try to get meta from Metaplex

            $mpl_metadata_pda = $this->find_metaplex_meta_pda($currency["id"]);
            if (is_null($mpl_metadata_pda))
            {
                $result[] = [
                    'id' => $currency["id"],
                    'name' => '',
                    'symbol' => '',
                    'decimals' => $currency['decimals'],
                ];
                continue;
            }

            // 3. Try to get MetaPlex token metadata
            $metaplex_meta = requester_single($this->select_node(),
                params: [
                    'method'  => 'getAccountInfo',
                    'params'  => [$mpl_metadata_pda, ['encoding' => 'base64']],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout,
                flags: [RequesterOption::IgnoreAddingQuotesToNumbers]
            );

            if (is_null($metaplex_meta['value']))
            {
                $result[] = [
                    'id' => $currency["id"],
                    'name' => '',
                    'symbol' => '',
                    'decimals' => $currency['decimals'],
                ];
                continue;
            }

            $metadata = base64_decode($metaplex_meta['value']['data'][0]);
            if (strlen($metadata) < 73) // see metaplex_meta_deserialize
                [$name, $symbol] = ["pda_{$mpl_metadata_pda}", ""];
            else
                [$name, $symbol] = $this->metaplex_meta_deserialize($metadata);

            $result[] = [
                'id' => $currency["id"],
                'name' => $name,
                'symbol' => $symbol,
                'decimals' => $currency['decimals'],
            ];
        }
        return $result;
    }

    // Returns only events with currencies from list
    function filter_events_by_currency(array $currencies_filter, array $events): array
    {
        $filtered = [];
        $sort_key = 0;
        foreach ($events as &$event)
        {
            if (!in_array($event['currency'], $currencies_filter))
                continue;
            if ($this->currency_type === CurrencyType::NFT)
                if ($event['effect'] !== '1' && $event['effect'] !== '-1')
                    throw new ModuleError("Invalid effect for NFT token transfer");

            $event['sort_key'] = $sort_key++;
            $filtered[] = $event;
        }

        return $filtered;
    }

    function get_token_supply($currencies): array
    {
        $tokens_supply = [];
        for ($i = 0; $i < count($currencies); $i++)
        {
            $data[] = [
                'method' => 'getTokenSupply',
                'params' => [$currencies[$i]["id"]],
                'id' => $i,
                'jsonrpc' => '2.0',
            ];
        }

        $data_chunks = array_chunk($data, 100);

        foreach ($data_chunks as $datai)
        {
            $result = requester_single($this->select_node(), params: $datai, flags: [RequesterOption::IgnoreAddingQuotesToNumbers]);

            foreach ($result as $bit)
            {
                if (isset($bit['error']))
                    throw new RequesterException('An error occured within a nested request');
                $tokens_supply[$currencies[$bit['id']]['id']] = $bit['result']['value']['amount'] ?? "0";
            }
        }
        return $tokens_supply;
    }

    // Returns new program derived address of Metaplex Metadata from mint address
    function find_metaplex_meta_pda(string $mint): ?string
    {
        $fullseeds = 'metadata';
        $fullseeds .= Base58::base58_nocheck_decode($this->programs['METAPLEX_ID']);
        $fullseeds .= Base58::base58_nocheck_decode($mint);

        return $this->find_program_address($fullseeds, $this->programs['METAPLEX_ID']);
    }

// Returns new program derived address of Metaplex Master Edition from mint address
    function find_program_address($seeds, $program_id): ?string
    {
        for ($bump = 255; $bump != 0; $bump--)
        {
            $fullseeds_copy = $seeds;
            $fullseeds_copy .= chr($bump);
            $fullseeds_copy .= Base58::base58_nocheck_decode($program_id);
            $fullseeds_copy .= 'ProgramDerivedAddress';
            $binhash = hash('sha256', $fullseeds_copy, true);

            // Valid program addresses must fall off the ed25519 curve
            if (!$this->is_on_curve($binhash))
                return Base58::base58_nocheck_encode($binhash);
        }

        // Cannot find metaplex pda for this mint
        return null;
    }

// Checks that public key is on ed25519 curve. Uses sodium extension.
    function is_on_curve(string $pk_bytes): bool
    {
        try
        {
            ParagonIE_Sodium_Compat::crypto_sign_ed25519_pk_to_curve25519($pk_bytes);
            return true;
        }
        catch (Throwable)
        {
            return false;
        }
    }

// Raw deserialization metaplex meta (name, symbol, uri) from data bytes array
// Refs: https://github.com/metaplex-foundation/mpl-token-metadata/blob/e86de64101fc386dd4cc97b6f107da3de258833a/programs/token-metadata/program/src/state/metadata.rs#L65
// https://github.com/metaplex-foundation/python-api/blob/441c2ba9be76962d234d7700405358c72ee1b35b/metaplex/metadata.py#L123
// Serialization: https://borsh.io/
// Returns array (name, symbol)
    function metaplex_meta_deserialize(string $bytes): array
    {
        $max_data_size = 4 + 32 + 4 + 10 + 4 + 200 + 2 + 1 + 4 + (5 * (32 + 1 + 1));
        $bytes = substr($bytes, 1 + 32 + 32, $max_data_size);

        // Deserialize name and symbol from bytes
        $offset = 0;
        $length = unpack("V", substr($bytes, $offset, 4))[1];
        $offset += 4;
        $name = trim(substr($bytes, $offset, $length));
        $offset += (int)$length;

        $length = unpack("V", substr($bytes, $offset, 4))[1];
        $offset += 4;
        $symbol = trim(substr($bytes, $offset, $length));
//    get uri, may be we will need it
//    $offset += (int)$length;
//    $length = unpack("V", substr($bytes, $offset, 4))[1];
//    $offset += 4;
//    $uri = trim(substr($bytes, $offset, $length));
        return [$name, $symbol];
    }

    function domain_deserialize(string $bytes): ?string
    {
        $min_data_size = 96;
        if (strlen($bytes) < $min_data_size)
        {
            return null;
        }
//        $parent = substr($bytes,0, 32);
        $owner = substr($bytes, 32,32);
//        $class = substr($bytes, 64,32);
        return Base58::base58_nocheck_encode($owner);
    }

    /** finds account key of the domain name
     * @param $name string domain with .sol
     * @param $name_class string|null the name class public key
     * @param $parent_name string the name parent public key (actually TLD)
     * @return string|null
     */
    function get_name_account_key(string $name, string $name_class = null, ?string $parent_name = null): ?string
    {
        if (is_null($parent_name))
            $parent_name = $this->programs['SOL_TLD_AUTHORITY'];

        $input = $this->programs['DOMAIN_HASH_PREFIX'] . $name;
        $hashed_name = hash('sha256', $input, true);

        $name_class = $name_class ?? str_repeat("\x00", 32);
        $parent_name = Base58::base58_nocheck_decode($parent_name);
        return $this->find_program_address($hashed_name . $name_class . $parent_name, $this->programs['SPL_NAME_SERVICE_PROGRAM_ID']);
    }

    function get_domain_owner(string $domain): ?string
    {
        $parts = explode('.', $domain);
        switch (count($parts)) {
            case 2:
                $account_key = $this->get_name_account_key($parts[0]);
                break;
            case 3:
                $account_key = $this->get_name_account_key("\x00" . $parts[0], parent_name: $this->get_name_account_key($parts[1]));
                break;
            default:
                return null;
        }
        if (strlen($account_key) > 0)
        {
            try
            {
                $account_data = requester_single($this->select_node(),
                    params: [
                        'method'  => 'getAccountInfo',
                        'params'  => [$account_key, ['encoding' => 'base64']],
                        'id'      => 0,
                        'jsonrpc' => '2.0',
                    ],
                    result_in: 'result',
                    timeout: $this->timeout,
                    flags: [RequesterOption::IgnoreAddingQuotesToNumbers]
                );
            }
            catch (Exception $e)
            {
                return null;
            }
            $account_data = $account_data['value']['data'] ?? null;
            if (is_null($account_data))
                return null;
            $owner = $this->domain_deserialize(base64_decode($account_data[0]));
            return $owner;
        }
        return null;
    }
}