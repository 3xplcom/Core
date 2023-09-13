<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common functions for UTXO-based modules (UTXOMainModule, UTXOOmniModule)  */

trait UTXOTraits
{
    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
            params: ['method' => 'getblockcount'],
            result_in: 'result',
            timeout: $this->timeout);
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        if (count($this->nodes) === 1)
        {
            $this->block_hash = requester_single($this->nodes[0], params: ['method' => 'getblockhash', 'params' => [(int)$block_id]],
                timeout: $this->timeout, result_in: 'result');
        }
        else
        {
            $multi_curl = [];

            foreach ($this->nodes as $node)
            {
                $multi_curl[] = requester_multi_prepare($node, params: ['method' => 'getblockhash', 'params' => [(int)$block_id]],
                    timeout: $this->timeout);
                if ($break_on_first) break;
            }

            try
            {
                $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
            }
            catch (RequesterException $e)
            {
                throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
            }

            $hash = requester_multi_process($curl_results[0], result_in: 'result');

            if (count($curl_results) > 1)
            {
                foreach ($curl_results as $result)
                {
                    if (requester_multi_process($result, result_in: 'result') !== $hash)
                    {
                        throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                    }
                }
            }

            $this->block_hash = $hash;
        }
    }
}

// Some blockchains (or full node clients) has some extra features which affect their JSON RPC output
enum UTXOSpecialFeatures
{
    case IgnorePubKeyConversion; // Bitcoin Cash node shows P2PK addresses correctly
    case HasAddressPrefixes; // Bitcoin Cash node uses `bitcoincash:` prefix for all standard addresses
    case HasMWEB; // Litecoin Core has some additional MWEB data
    case HasShieldedPools; // Shielded pool processing in Zcash
    case Not8Decimals; // There's a "non-standard" number of decimals, i.e. not 8; e.g. Peercoin
    case OneAddressInScriptPubKey; // There's no "addresses" array in scriptPubKey
}

// See https://stackoverflow.com/questions/19233053/hashing-from-a-public-key-to-a-bitcoin-address-in-php
// This is needed for converting standard P2PK (not P2PKH!) scripts into addresses
class CryptoP2PK
{
    public static function process($asm, $P2PK_prefix1, $P2PK_prefix2)
    {
        $asm = explode(' ', $asm);
        $script = $asm[0];
        $step1 = self::hex_string_to_byte_string($script);
        $step2 = hash("sha256", $step1);
        $step3 = hash('ripemd160', self::hex_string_to_byte_string($step2));
        $step4 = $P2PK_prefix2 . $step3;
        $step5 = hash("sha256", self::hex_string_to_byte_string($step4));
        $step6 = hash("sha256", self::hex_string_to_byte_string($step5));
        $checksum = substr($step6, 0, 8);
        $step8 = $step4 . $checksum;
        $step9 = $P2PK_prefix1 . self::bc_base58_encode(self::bc_hexdec($step8));
        return $step9;
    }

    private static function hex_string_to_byte_string($hex_string)
    {
        $len = strlen($hex_string);

        $byte_string = "";

        for ($i = 0; $i < $len; $i = $i + 2)
        {
            $char_num = hexdec(substr($hex_string, $i, 2));
            $byte_string .= chr($char_num);
        }

        return $byte_string;
    }

    private static function bc_arb_encode($num, $base_string)
    {
        $base = strlen($base_string);
        $rep = '';

        while (true)
        {
            if (strlen($num) < 2)
            {
                if (intval($num) <= 0)
                {
                    break;
                }
            }

            $rem = bcmod($num, (string)$base);
            $rep = $base_string[intval($rem)] . $rep;
            $num = bcdiv(bcsub($num, $rem), (string)$base);
        }

        return $rep;
    }

    private static function bc_arb_decode($num, $base_string)
    {
        $base = strlen($base_string);
        $dec = '0';

        $num_arr = str_split((string)$num);
        $cnt = strlen($num);

        for ($i = 0; $i < $cnt; $i++)
        {
            $pos = strpos($base_string, $num_arr[$i]);

            if ($pos === false)
            {
                throw new Exception(sprintf('Unknown character %s at offset %d', $num_arr[$i], $i));
            }

            $dec = bcadd(bcmul($dec, (string)$base), (string)$pos);
        }

        return $dec;
    }

    private static function bc_base58_encode($num)
    {
        return self::bc_arb_encode($num, '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz');
    }

    private static function bc_hexdec($num)
    {
        return self::bc_arb_decode(strtolower($num), '0123456789abcdef');
    }
}

// Carefully converts floats from the node to numerics
function satoshi(string $value, CoreModule|false $module = false): string
{
    if ($module === false || !in_array(UTXOSpecialFeatures::Not8Decimals, $module->extra_features))
    {
        if ($value === '0.00000000') return '0';
        if (strlen(explode('.', $value)[1]) !== 8) throw new ModuleError("satoshi(value: ({$value})): incorrect value");
        return ltrim(str_replace('.', '', $value), '0');
    }
    else
    {
        $decimals = $module->currency_details['decimals'];
        if ($value === '0.' . str_repeat('0', $decimals)) return '0';
        $value_parts = explode('.', $value);
        if (strlen($value_parts[1]) !== $decimals) throw new ModuleError("satoshi(value: ({$value})): incorrect number of decimals");
        return ltrim(str_replace('.', '', $value), '0');
    }
}
