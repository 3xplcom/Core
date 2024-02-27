<?php

// This file has two classes
// * `CashAddress` is derived from https://github.com/Bitcoin-ABC/bitcoin-abc/blob/931e7acbe615ab4c8cce25b83841c86fd2332d75/modules/ecashaddrjs/src/cashaddr.js
// by alexqrid and is used for converting various CashAddr formats into each other.
// * `CashAddressP2PK` is https://github.com/Har01d/CashAddressPHP and is used for converting Bitcoin P2PK addresses (starting with`1`)
// into `bitcoincash:` prefixed CashAddr addresses.

require_once __DIR__ . '/Base32.php';

/**
 * @license
 * https://reviews.bitcoinabc.org
 * Copyright (c) 2017-2020 Emilio Almansi
 * Copyright (c) 2023 Bitcoin ABC
 * Distributed under the MIT software license, see the accompanying
 * file LICENSE or http://www.opensource.org/licenses/mit-license.php.
 */

class CashAddress
{
    function hasSingleCase($string)
    {
        return $string === strtolower($string) || $string === strtoupper($string);
    }

    private function isByteArray($data)
    {
        if (is_array($data)) {
            foreach ($data as $value) {
                if (!is_int($value) || $value < 0 || $value > 255) {
                    throw new Error("The given data is not a byte array");
                }
            }
            return true;
        }
        throw new Error("The given data is not a byte array");
    }

    private function ConvertBits(array $data, int $from, int $to, bool $strictMode = false)
    {
        $length = $strictMode ? floor((count($data) * $from) / $to)
            : ceil((count($data) * $from) / $to);
        $mask = (1 << $to) - 1;
        $result = new SplFixedArray($length);
        $index = 0;
        $accumulator = 0;
        $bits = 0;
        for ($i = 0; $i < count($data); ++$i) {
            $value = $data[$i];
            assert(
                0 <= $value && $value >> $from === 0,
                'Invalid value: ' . $value . '.',
            );
            $accumulator = ($accumulator << $from) | $value;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $result[$index] = ($accumulator >> $bits) & $mask;
                ++$index;
            }
        }
        if (!$strictMode) {
            if ($bits > 0) {
                $result[$index] = ($accumulator << ($to - $bits)) & $mask;
                ++$index;
            }
        } else {
            assert(
                $bits < $from && (($accumulator << ($to - $bits)) & $mask) === 0,
                'Input cannot be converted $to ' .
                $to .
                ' bits without padding, but strict mode was used.',
            );
        }
        return $result;
    }

    function toUint5Array($data)
    {
        return self::ConvertBits($data, 8, 5);
    }

    function polymod($data)
    {
        $GENERATOR = [
            0x98f2bc8e61,
            0x79b76d99e2,
            0xf33e5fb3c4,
            0xae2eabe2a8,
            0x1e4f43e470,
        ];
        $checksum = 1;
        for ($i = 0; $i < count($data); ++$i) {
            $value = $data[$i];
            $topBits = $checksum >> 35;
            $checksum = ($checksum & 0x07ffffffff) << 5 ^ $value;
            for ($j = 0; $j < count($GENERATOR); ++$j) {
                if (($topBits >> $j) & 1 == 1) {
                    $checksum ^= $GENERATOR[$j];
                }
            }
        }
        return $checksum ^ 1;
    }

    function stringToUint8Array($string): bool|array
    {
        $binaryString = hex2bin($string);
        $byteArray = unpack('C*', $binaryString);
        return $byteArray;
    }

    function getHashSizeBits($hash)
    {
        $hashLength = count($hash) * 8;
        switch ($hashLength) {
            case 160:
                return 0;
            case 192:
                return 1;
            case 224:
                return 2;
            case 256:
                return 3;
            case 320:
                return 4;
            case 384:
                return 5;
            case 448:
                return 6;
            case 512:
                return 7;
            default:
                throw new Exception('Invalid hash size: ' . $hashLength . '.');
        }
    }

    function Uint8Array($size)
    {
        // Create an array of zeros with the specified size
        return array_fill(0, $size, 0);
    }

    function prefixToUint5Array($prefix)
    {
        $result = [];
        $length = strlen($prefix);
        for ($i = 0; $i < $length; $i++) {
            $result[] = ord($prefix[$i]) & 31;
        }
        return $result;
    }

    function checksumToUint5Array($checksum)
    {
        $result = array_fill(0, 8, 0); // Initialize result array with zeros
        for ($i = 0; $i < 8; ++$i) {
            $result[7 - $i] = $checksum & 31; // Get the least significant 5 bits
            $checksum >>= 5; // Right shift by 5 bits
        }
        return $result;
    }

    function getTypeBits($type)
    {
        switch (strtolower($type)) {
            case 'p2pkh':
                return 0;
            case 'p2sh':
                return 8;
            default:
                throw new Exception('Invalid type: ' . $type . '.');
        }
    }

    function toUint8Array($versionByte)
    {
        $binaryString = pack("C", $versionByte);
        $uint8Array = array_values(unpack("C*", $binaryString));

        return $uint8Array;
    }

    const VALID_PREFIXES_MAINNET = ['ecash', 'bitcoincash', 'simpleledger', 'etoken'];

    const VALID_PREFIXES = [
        'ecash',
        'bitcoincash',
        'simpleledger',
        'etoken',
        'ectest',
        'ecregtest',
        'bchtest',
        'bchreg',
    ];

    private function isValidPrefix($prefix)
    {
        return (
            ($prefix === strtolower($prefix) || $prefix === strtoupper($prefix))
            &&
            in_array(strtolower($prefix), self::VALID_PREFIXES)
        );
    }

    public function encode($prefix, $type, $hash)
    {
        assert(
            is_string($prefix) && $this->isValidPrefix($prefix),
            'Invalid prefix: ' . $prefix . '.',
        );
        assert(is_string($type), 'Invalid type: ' . $type . '.');
        assert(
            self::isByteArray($hash) || is_string($hash),
            'Invalid hash: ' . join(',', $hash) . '. Must be string or Uint8Array.',
        );
        if (is_string($hash)) {
            $hash = $this->stringToUint8Array($hash);
        }
        $prefixData = array_merge($this->prefixToUint5Array($prefix), $this->Uint8Array(1));
        $versionByte = $this->getTypeBits(strtoupper($type)) + $this->getHashSizeBits($hash);
        $payloadData = $this->toUint5Array(array_merge($this->toUint8Array($versionByte), $hash));
        $checksumData = array_merge($prefixData, (array)$payloadData, $this->Uint8Array(8));
        $payload = array_merge(
            (array)$payloadData,
            $this->checksumToUint5Array($this->polymod($checksumData)),
        );
        return $prefix . ':' . (new Base32)->encode($payload);
    }

    function decode($address, $chronikReady = false)
    {
        assert(
            is_string($address) && $this->hasSingleCase($address),
            'Invalid address: ' . $address . '.',
        );
        $pieces = explode(':', strtolower($address));
        // if there is no prefix, it might still be valid
        if (count($pieces) === 1) {
            // Check and see if it has a valid checksum for accepted prefixes
            $hasValidChecksum = false;
            for ($i = 0; $i < count(self::VALID_PREFIXES); $i += 1) {
                $testedPrefix = self::VALID_PREFIXES[$i];
                $prefixlessPayload = (new Base32)->decode($pieces[0]);
                $hasValidChecksum = $this->validChecksum($testedPrefix, $prefixlessPayload);
                if ($hasValidChecksum) {
                    // Here's your prefix
                    $prefix = $testedPrefix;
                    $payload = $prefixlessPayload;
                    // Stop testing other prefixes
                    break;
                }
            }
            assert(
                $hasValidChecksum,
                "Prefixless address {$address} does not have valid checksum for any valid prefix" . join(
                    ',',
                    self::VALID_PREFIXES
                )
            );
        } else {
            assert(count($pieces) === 2, 'Invalid address: ' . $address . '.');
            $prefix = $pieces[0];
            $payload = (new Base32())->decode($pieces[1]);
            assert(
                $this->validChecksum($prefix, $payload),
                'Invalid checksum: ' . $address . '.',
            );
        }
        $payloadData = $this->fromUint5Array(array_slice($payload, 0, -8));
        $versionByte = $payloadData[0];
        $hash = array_slice((array)$payloadData, 1);
        assert(
            $this->getHashSize($versionByte) === count($hash) * 8,
            'Invalid hash size: ' . $address . '.',
        );
        $type = $this->getType($versionByte);
        return [
            "prefix" => $prefix,
            "type"   => $chronikReady ? strtolower($type) : $type,
            "hash"   => $chronikReady ? $this->uint8arraytoString($hash) : $hash,
        ];
    }

    function getHashSize($versionByte)
    {
        switch ($versionByte & 7) {
            case 0:
                return 160;
            case 1:
                return 192;
            case 2:
                return 224;
            case 3:
                return 256;
            case 4:
                return 320;
            case 5:
                return 384;
            case 6:
                return 448;
            case 7:
                return 512;
            default:
                throw new Exception('Invalid version byte: ' . $versionByte);
        }
    }

    function fromUint5Array($data)
    {
        return self::ConvertBits($data, 5, 8, true);
    }

    function validChecksum($prefix, $payload)
    {
        $prefixData = array_merge($this->prefixToUint5Array($prefix), [0]);
        $checksumData = array_merge($prefixData, $payload);
        return $this->polymod($checksumData) == 0;
    }

    function uint8arraytoString($uint8Array)
    {
        $buffer = [];
        foreach ($uint8Array as $value) {
            $buffer[] = $value;
        }
        $hexString = implode('', array_map('dechex', $buffer));
        return $hexString;
    }

    function getType($versionByte)
    {
        switch ($versionByte & 120) {
            case 0:
                return 'P2PKH';
            case 8:
                return 'P2SH';
            default:
                throw new Exception('Invalid address type in version byte: ' . $versionByte . '.');
        }
    }

    function toLegacy($cashaddress)
    {
        // Decode the cash address
        $decoded = $this->decode($cashaddress);
        $prefix = $decoded['prefix'];
        $type = $decoded['type'];
        $hash = $decoded['hash'];

        // Check if it's mainnet
        $isMainnet = in_array($prefix, self::VALID_PREFIXES_MAINNET);

        // Get correct version byte for legacy format
        switch ($type) {
            case 'P2PKH':
                $versionByte = $isMainnet ? 0 : 111;
                break;
            case 'P2SH':
                $versionByte = $isMainnet ? 5 : 196;
                break;
            default:
                throw new Exception('Unsupported address type: ' . $type);
        }

        $buffer = pack('C', $versionByte) . implode('', array_map('chr', $hash));

        return Base58::base58_check_encode($buffer);
    }
}

// (c) uMCCCS
// with some minor additions from Har01d @ blockchair.com

// This script uses some of the code and ideas from the following repositories:

// https://github.com/deadalnix/cashaddressed
// https://github.com/cryptocoinjs/base-x/blob/master/index.js - base-x encoding
// Forked from https://github.com/cryptocoinjs/bs58
// Originally written by Mike Hearn for BitcoinJ
// Copyright (c) 2011 Google Inc
// Ported to JavaScript by Stefan Thomas
// Merged Buffer refactorings from base58-native by Stephen Pair
// Copyright (c) 2013 BitPay Inc

// The MIT License (MIT)
// Copyright base-x contributors (c) 2016
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE.

// Copyright (c) 2017 Pieter Wuille
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE.

// ISC License
//
// Copyright (c) 2013-2016 The btcsuite developers
//
// Permission to use, copy, modify, and distribute this software for any
// purpose with or without fee is hereby granted, provided that the above
// copyright notice and this permission notice appear in all copies.
//
// THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
// WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
// MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
// ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
// WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
// ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
// OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

// https://github.com/Bit-Wasp/bitcoin-php/blob/master/src/Bech32.php
// This is free and unencumbered software released into the public domain.
//
// Anyone is free to copy, modify, publish, use, compile, sell, or
// distribute this software, either in source code form or as a compiled
// binary, for any purpose, commercial or non-commercial, and by any
// means.
//
// In jurisdictions that recognize copyright laws, the author or authors
// of this software dedicate any and all copyright interest in the
// software to the public domain. We make this dedication for the benefit
// of the public at large and to the detriment of our heirs and
// successors. We intend this dedication to be an overt act of
// relinquishment in perpetuity of all present and future rights to this
// software under copyright law.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
// EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
// IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
// OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
// ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
// OTHER DEALINGS IN THE SOFTWARE.
//
// For more information, please refer to <http://unlicense.org/>

class CashAddressP2PK {

    const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    const ALPHABET_MAP =
        [-1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1,  0,  1,  2,  3,  4,  5,  6,  7,  8, -1, -1, -1, -1, -1, -1,
         -1,  9, 10, 11, 12, 13, 14, 15, 16, -1, 17, 18, 19, 20, 21, -1,
         22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, -1, -1, -1, -1, -1,
         -1, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, -1, 44, 45, 46,
         47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, -1, -1, -1, -1, -1];
    const BECH_ALPHABET =
        [-1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         15, -1, 10, 17, 21, 20, 26, 30,  7,  5, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
         -1, 29, -1, 24, 13, 25,  9,  8, 23, -1, 18, 22, 31, 27, 19, -1,
         1, 0, 3, 16, 11, 28, 12, 14, 6, 4, 2, -1, -1, -1, -1, -1];
    const EXPAND_PREFIX_UNPROCESSED = [2, 9, 20, 3, 15, 9, 14, 3, 1, 19, 8, 0];
    const EXPAND_PREFIX_TESTNET_UNPROCESSED = [2, 3, 8, 20, 5, 19, 20, 0];
    const EXPAND_PREFIX = 1058337025301;
    const EXPAND_PREFIX_TESTNET = 584719417569;
    const BASE16 = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, -1, -1, -1, -1, -1, -1, -1, -1,
                    -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
                    -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, 10, 11, 12,
                    13, 14, 15];

    public function __construct()
    {
        if (PHP_INT_SIZE < 5) {

            // Requires x64 system and PHP!
            throw new Error('Run it on a x64 system (+ 64 bit PHP)');
        }
    }
    /**
     * convertBits is the internal function to convert 256-based bytes
     * to base-32 grouped bit arrays and vice versa.
     * @param  array $data Data whose bits to be re-grouped
     * @param  integer $fromBits Bits per input group of the $data
     * @param  integer $toBits Bits to be put to each output group
     * @param  boolean $pad Whether to add extra zeroes
     * @return array $ret
     */
    static private function convertBits(array $data, $fromBits, $toBits, $pad = true)
    {
        $acc    = 0;
        $bits   = 0;
        $ret    = [];
        $maxv   = (1 << $toBits) - 1;
        $maxacc = (1 << ($fromBits + $toBits - 1)) - 1;

        $datalen = sizeof($data);
        for ($i = 0; $i < $datalen; $i++)
        {
            $value = $data[$i];

            if ($value < 0 || $value >> $fromBits !== 0)
            {
                throw new Error('Error!');
            }

            $acc  = (($acc << $fromBits) | $value) & $maxacc;
            $bits += $fromBits;

            while ($bits >= $toBits)
            {
                $bits  -= $toBits;
                $ret[] = (($acc >> $bits) & $maxv);
            }
        }

        if ($pad)
        {
            if ($bits)
            {
                $ret[] = ($acc << $toBits - $bits) & $maxv;
            }
        }
        else if ($bits >= $fromBits || ((($acc << ($toBits - $bits))) & $maxv))
        {
            throw new Error('Error!');
        }

        return $ret;
    }

    /**
     * polyMod is the internal function create BCH codes.
     * @param  array $var 5-bit grouped data array whose polyMod to be calculated.
     * @param  integer c Starting value, 1 if the prefix is appended to the array.
     * @return integer $polymodValue polymod result
     */
    static private function polyMod($var, $c = 1)
    {
        $varlen = sizeof($var);;
        for ($i = 0; $i < $varlen; $i++)
        {
            $c0 = $c >> 35;
            $c = (($c & 0x07ffffffff) << 5) ^
                ($var[$i]) ^
                (-($c0 & 1) & 0x98f2bc8e61) ^
                (-($c0 & 2) & 0x79b76d99e2) ^
                (-($c0 & 4) & 0xf33e5fb3c4) ^
                (-($c0 & 8) & 0xae2eabe2a8) ^
                (-($c0 & 16) & 0x1e4f43e470);
        }

        return $c ^ 1;
    }

    /**
     * rebuildAddress is the internal function to recreate error
     * corrected addresses.
     * @param  array $addressBytes
     * @return string $correctedAddress
     */
    static private function rebuildAddress($addressBytes)
    {
        $ret = '';
        $i   = 0;

        while ($addressBytes[$i] !== 0)
        {
            // 96 = ord('a') & 0xe0
            $ret .= chr(96 + $addressBytes[$i]);
            $i++;
        }

        $ret .= ':';
        $len = sizeof($addressBytes);
        for ($i++; $i < $len; $i++)
        {
            $ret .= self::CHARSET[$addressBytes[$i]];
        }

        return $ret;
    }

    /**
     * old2new converts an address in old format to the new Cash Address format.
     * @param  string $oldAddress (either Mainnet or Testnet)
     * @return string $newAddress Cash Address result
     */
    static public function old2new($oldAddress)
    {
        if (strlen($oldAddress) < 33)
            $oldAddress = str_pad($oldAddress, 33, '1', STR_PAD_LEFT);

        beginning:

        $bytes = [0];

        for ($x = 0; $x < strlen($oldAddress); $x++)
        {
            $carry = ord($oldAddress[$x]);
            if ($carry > 127 || ((($carry = self::ALPHABET_MAP[$carry]) === -1)))
            {
                throw new Error('Unexpected character in address!');
            }

            $bytes_len = sizeof($bytes);
            for ($j = 0; $j < $bytes_len; $j++)
            {
                $carry     += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry     >>= 8;
            }

            while ($carry !== 0)
            {
                array_push($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }

        for ($numZeros = 0; $numZeros < strlen($oldAddress) && $oldAddress[$numZeros] === '1'; $numZeros++)
        {
            array_push($bytes, 0);
        }

        // reverse array
        $answer = [];

        for ($i = sizeof($bytes) - 1; $i >= 0; $i--)
        {
            array_push($answer, $bytes[$i]);
        }

        $version = $answer[0];
        $payload = array_slice($answer, 1, sizeof($answer) - 5);

        if (sizeof($payload) % 4 !== 0)
        {
            if (strlen($oldAddress) === 33) {
                $oldAddress = '1' . $oldAddress;
                goto beginning;
            }

             throw new Error('Unexpected address length!' . $oldAddress);
        }

        // Assume the checksum of the old address is right
        // Here, the Cash Address conversion starts
        if ($version === 0x00)
        {
            // P2PKH
            $addressType = 0;
            $realNet = true;
        }
        else if ($version === 0x05)
        {
            // P2SH
            $addressType = 1;
            $realNet = true;
        }
        else if ($version === 0x6f)
        {
            // Testnet P2PKH
            $addressType = 0;
            $realNet = false;
        }
        else if ($version === 0xc4)
        {
            // Testnet P2SH
            $addressType = 1;
            $realNet = false;
        }
        else if ($version === 0x1c)
        {
            // BitPay P2PKH
            $addressType = 0;
            $realNet = true;
        }
        else if ($version === 0x28)
        {
            // BitPay P2SH
            $addressType = 1;
            $realNet = true;
        }
        else
        {
            throw new Error('Unknown address type!');
        }

        $encodedSize = (sizeof($payload) - 20) / 4;

        $versionByte      = ($addressType << 3) | $encodedSize;
        $data             = array_merge([$versionByte], $payload);
        $payloadConverted = self::convertBits($data, 8, 5, true);
        $arr              = array_merge($payloadConverted, [0, 0, 0, 0, 0, 0, 0, 0]);
        if ($realNet) {
            $expand_prefix = self::EXPAND_PREFIX;
            $ret = 'bitcoincash:';
        } else {
            $expand_prefix = self::EXPAND_PREFIX_TESTNET;
            $ret = 'bchtest:';
        }
        $mod          = self::polymod($arr, $expand_prefix);
        $checksum     = [0, 0, 0, 0, 0, 0, 0, 0];

        for ($i = 0; $i < 8; $i++)
        {
            // Convert the 5-bit groups in mod to checksum values.
            // $checksum[$i] = ($mod >> 5*(7-$i)) & 0x1f;
            $checksum[$i] = ($mod >> (5 * (7 - $i))) & 0x1f;
        }

        $combined     = array_merge($payloadConverted, $checksum);
        $combined_len = sizeof($combined);
        for ($i = 0; $i < $combined_len; $i++)
        {
            $ret .= self::CHARSET[$combined[$i]];
        }

        return $ret;
    }

    /**
     * Decodes Cash Address.
     * @param  string $inputNew New address to be decoded.
     * @param  boolean $shouldFixErrors Whether to fix typing errors.
     * @param  boolean &$isTestnetAddressResult Is pointer, set to whether it's
     * a testnet address.
     * @return array|string $decoded Returns decoded byte array if it can be decoded.
     * @return string|array $correctedAddress Returns the corrected address if there's
     * a typing error.
     */
    static public function decodeNewAddr($inputNew, $shouldFixErrors, &$isTestnetAddressResult) {
        $inputNew = strtolower($inputNew);
        if (strpos($inputNew, ':') === false) {
            $afterPrefix = 0;
            $expand_prefix = self::EXPAND_PREFIX;
            $isTestnetAddressResult = false;
        }
        else if (substr($inputNew, 0, 12) === 'bitcoincash:')
        {
            $afterPrefix = 12;
            $expand_prefix = self::EXPAND_PREFIX;
            $isTestnetAddressResult = false;
        }
        else if (substr($inputNew, 0, 8) === 'bchtest:')
        {
            $afterPrefix = 8;
            $expand_prefix = self::EXPAND_PREFIX_TESTNET;
            $isTestnetAddressResult = true;
        }
        else
        {
            throw new Error('Unknown address type');
        }

        $data = [];
        $len  = strlen($inputNew);
        for (; $afterPrefix < $len; $afterPrefix++)
        {
            $i = ord($inputNew[$afterPrefix]);
            if ($i > 127 || (($i = self::BECH_ALPHABET[$i]) === -1))
            {
                throw new Error('Unexpected character in address!');
            }
            array_push($data, $i);
        }

        $checksum = self::polyMod($data, $expand_prefix);

        if ($checksum !== 0)
        {
            if ($expand_prefix === self::EXPAND_PREFIX_TESTNET) {
                $unexpand_prefix = self::EXPAND_PREFIX_TESTNET_UNPROCESSED;
            } else {
                $unexpand_prefix = self::EXPAND_PREFIX_UNPROCESSED;
            }
            // Checksum is wrong!
            // Try to fix up to two errors
            if ($shouldFixErrors) {
                $syndromes = Array();
                $datalen = sizeof($data);
                for ($p = 0; $p < $datalen; $p++)
                {
                    for ($e = 1; $e < 32; $e++)
                    {
                        $data[$p] ^= $e;
                        $c        = self::polyMod($data, $expand_prefix);
                        if ($c === 0)
                        {
                            return self::rebuildAddress(array_merge($unexpand_prefix, $data));
                        }
                        $syndromes[$c ^ $checksum] = $p * 32 + $e;
                        $data[$p]                  ^= $e;
                    }
                }

                foreach ($syndromes as $s0 => $pe)
                {
                    if (array_key_exists($s0 ^ $checksum, $syndromes))
                    {
                        $data[$pe >> 5]                         ^= $pe % 32;
                        $data[$syndromes[$s0 ^ $checksum] >> 5] ^= $syndromes[$s0 ^ $checksum] % 32;
                        return self::rebuildAddress(array_merge($unexpand_prefix, $data));
                    }
                }
                throw new Error('Can\'t correct typing errors!');
            }
        }
        return $data;
    }

    /**
     * Corrects Cash Address typing errors.
     * @param  string $inputNew Cash Address to be corrected.
     * @return string $correctedAddress Error corrected address, or the input itself
     * if there are no errors.
     */
    static public function fixCashAddrErrors($inputNew) {
        try {
            $corrected = self::decodeNewAddr($inputNew, true, $isTestnet);
            if (gettype($corrected) === 'array') {
                return $inputNew;
            } else {
                return $corrected;
            }
        }
        catch(Error $e) {
            throw $e;
        }
    }


    /**
     * new2old converts an address in the Cash Address format to the old format.
     * @param  string $inputNew Cash Address (either mainnet or testnet)
     * @param  boolean $shouldFixErrors Whether to fix typing errors.
     * @return string $oldAddress Old style 1... or 3... address
     */
    static public function new2old($inputNew, $shouldFixErrors)
    {
        try {
            $corrected = self::decodeNewAddr($inputNew, $shouldFixErrors, $isTestnet);
            if (gettype($corrected) === 'array') {
                $values = $corrected;
            } else {
                $values = self::decodeNewAddr($corrected, false, $isTestnet);
            }
        }
        catch(Exception $e) {
            throw new Error('Error');
        }

        $values      = self::convertBits(array_slice($values, 0, sizeof($values) - 8), 5, 8, false);
        $addressType = $values[0] >> 3;
        $addressHash = array_slice($values, 1, 21);

        // Encode Address
        if ($isTestnet) {
            if ($addressType) {
                $bytes = [0xc4];
            } else {
                $bytes = [0x6f];
            }
        } else {
            if ($addressType) {
                $bytes = [0x05];
            } else {
                $bytes = [0x00];
            }
        }
        $bytes      = array_merge($bytes, $addressHash);
        $merged     = array_merge($bytes, self::doubleSha256ByteArray($bytes));
        $digits     = [0];
        $merged_len = sizeof($merged);
        for ($i = 0; $i < $merged_len; $i++)
        {
            $carry = $merged[$i];
            $digits_len = sizeof($digits);
            for ($j = 0; $j < $digits_len; $j++)
            {
                $carry      += $digits[$j] << 8;
                $digits[$j] = $carry % 58;
                $carry      = intdiv($carry, 58);
            }

            while ($carry !== 0)
            {
                array_push($digits, $carry % 58);
                $carry = intdiv($carry, 58);
            }
        }

        // leading zero bytes
        for ($i = 0; $i < $merged_len && $merged[$i] === 0; $i++)
        {
            array_push($digits, 0);
        }

        // reverse
        $converted = '';
        for ($i = sizeof($digits) - 1; $i >= 0; $i--)
        {
            if ($digits[$i] > strlen(self::ALPHABET))
            {
                throw new Error('Error!');
            }
            $converted .= self::ALPHABET[$digits[$i]];
        }

        return $converted;
    }

    /**
     * internal function to calculate sha256
     * @param  array $byteArray Byte array of data to be hashed
     * @return array $hashResult First four bytes of sha256 result
     */
    private static function doubleSha256ByteArray($byteArray) {
        $stringToBeHashed = '';
        $byteArrayLen = sizeof($byteArray);
        for ($i = 0; $i < $byteArrayLen; $i++)
        {
            $stringToBeHashed .= chr($byteArray[$i]);
        }
        $hash = hash('sha256', $stringToBeHashed);
        $hashArray = [];
        for ($i = 0; $i < 32; $i++)
        {
            array_push($hashArray, self::BASE16[ord($hash[2 * $i]) - 48] * 16 + self::BASE16[ord($hash[2 * $i + 1]) - 48]);
        }
        $stringToBeHashed = '';
        for ($i = 0; $i < 32; $i++)
        {
            $stringToBeHashed .= chr($hashArray[$i]);
        }

        $hashArray = [];
        $hash      = hash('sha256', $stringToBeHashed);
        for ($i = 0; $i < 4; $i++)
        {
            array_push($hashArray, self::BASE16[ord($hash[2 * $i]) - 48] * 16 + self::BASE16[ord($hash[2 * $i + 1]) - 48]);
        }
        return $hashArray;
    }
}
