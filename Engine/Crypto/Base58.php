<?php

// This code uses ideas from here: https://github.com/tronprotocol/documentation/blob/master/TRX_CN/index.php
// which are distributed under the GNU Lesser General Public License v3.0: https://github.com/tronprotocol/documentation/blob/master/LICENSE

final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private static function base58_encode($string)
    {
        if (!$string)
            return '';

        $bytes = array_values(unpack('C*', $string));
        $decimal = $bytes[0];

        for ($i = 1, $l = count($bytes); $i < $l; $i++)
        {
            $decimal = bcmul((string)($decimal), '256');
            $decimal = bcadd($decimal, (string)($bytes[$i]));
        }

        $output = '';

        while ($decimal >= 58)
        {
            $div = bcdiv($decimal, '58');
            $mod = bcmod($decimal, '58');
            $output .= self::ALPHABET[(int)$mod];
            $decimal = $div;
        }

        if ($decimal > 0)
        {
            $output .= self::ALPHABET[$decimal];
        }

        $output = strrev($output);

        foreach ($bytes as $byte)
        {
            if ($byte === 0)
            {
                $output = self::ALPHABET[0] . $output;
                continue;
            }
            break;
        }

        return $output;
    }

    private static function base58_decode($base58)
    {
        if (!$base58)
            return '';

        $indexes = array_flip(str_split(self::ALPHABET));
        $chars = str_split($base58);

        foreach ($chars as $char)
            if (isset($indexes[$char]) === false)
                return false;

        $decimal = $indexes[$chars[0]];

        for ($i = 1, $l = count($chars); $i < $l; $i++)
        {
            $decimal = bcmul($decimal, '58');
            $decimal = bcadd($decimal, $indexes[$chars[$i]]);
        }

        $output = '';

        while ($decimal > 0)
        {
            $byte = bcmod($decimal, '256');
            $output = pack('C', $byte) . $output;
            $decimal = bcdiv($decimal, '256');
        }

        foreach ($chars as $char)
        {
            if ($indexes[$char] === 0)
            {
                $output = '\x00' . $output;
                continue;
            }

            break;
        }

        return $output;
    }

    private static function base58_check_encode($address)
    {
        $hash0 = hash('sha256', $address);
        $hash1 = hash('sha256', hex2bin($hash0));
        $checksum = substr($hash1, 0, 8);
        $address = $address . hex2bin($checksum);
        $base58add = self::base58_encode($address);

        return $base58add;
    }

    private static function base58_check_decode($base58add)
    {
        $address = self::base58_decode($base58add);
        $size = strlen($address);

        if ($size != 25)
            return false;

        $checksum = substr($address, 21);
        $address = substr($address, 0, 21);
        $hash0 = hash('sha256', $address);
        $hash1 = hash('sha256', hex2bin($hash0));
        $checksum0 = substr($hash1, 0, 8);
        $checksum1 = bin2hex($checksum);

        if (strcmp($checksum0, $checksum1))
            return false;

        return $address;
    }

    public static function hex_to_base58_check($hex_string)
    {
        return self::base58_check_encode(hex2bin($hex_string));
    }

    public static function base58_check_to_hex($base58)
    {
        return bin2hex(self::base58_check_decode($base58));
    }
}
