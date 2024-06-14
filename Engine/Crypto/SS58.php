<?php

require_once __DIR__ . '/Base58.php';
final class SS58
{
    private const ALLOWED_DECODED_LENGTHS = [1, 2, 4, 8, 32, 33];
    private const ALLOWED_ENCODED_LENGTHS = [3, 4, 6, 10, 35, 36, 37, 38];

    /**
     * @throws SodiumException
     */
    public static function ss58_encode($accountId, $ss58_format = 42): ?string
    {
        $key = SS58::ss58_decode($accountId);
        if (strlen($key) == 64)
            $key = hex2bin($key);
        //there should be also this condition along
        //        || in_array($ss58_format,[46, 47])
        // but we don't care, we just have to decode everything
        // even reserved one
        if (($ss58_format < 0) || ($ss58_format > 16383)) {
            return null;
        }
        elseif (!in_array(strlen($key),self::ALLOWED_DECODED_LENGTHS)) {
            return null;
        }
        $prefix = $ss58_format < 64 ? [$ss58_format] : [
            (($ss58_format & 252) >> 2) | 64,
            ($ss58_format >> 8) | (($ss58_format & 3) << 6)
        ];
        $dataWithPrefix = join(array_map("chr", $prefix)) . $key;
        $checksum_length = in_array(strlen($key),[32,33]) ? 2 : 1;
        $checksum = substr(sodium_crypto_generichash("SS58PRE" . $dataWithPrefix, '', 64), 0, $checksum_length);

        $dataWithChecksum = $dataWithPrefix . $checksum;

        return Base58::base58_encode($dataWithChecksum);
    }

    /**
     * @throws SodiumException
     */
   public static function ss58_decode(string $ss58_address, $ignore_checksum=true): string
   {
        if (strlen($ss58_address) == 64)
        {
            // dirty hack for encode function
            return hex2bin($ss58_address);
        }
        $decoded_data = Base58::base58_decode($ss58_address);
        if (!in_array(strlen($decoded_data), self::ALLOWED_ENCODED_LENGTHS)) {
            return "";
        }
        // dirty hack for \x00 prefix
        if (str_starts_with(bin2hex($decoded_data), '5c783030')) {
            $decoded_data = unpack("C*",hex2bin(str_replace("5c783030","00",bin2hex($decoded_data))));
            $decoded_data = join(array_map("chr",$decoded_data));
        }
        // Calculate address checksum
        $ss58_length = (ord($decoded_data[0]) & 64) ? 2 : 1;
        // prefix of the parachain decoded in $ss58_decoded
//        $ss58_decoded = $ss58_length === 1 ? ord($decoded_data[0])
//            : ((ord($decoded_data[0]) & 63) << 2) | (ord($decoded_data[1]) >> 6) | ((ord($decoded_data[1]) & 63) << 8);
        $isPublicKey = in_array(strlen($decoded_data), [34 + $ss58_length, 35 + $ss58_length]);
        $length = strlen($decoded_data) - ($isPublicKey ? 2 : 1);
        $data = "SS58PRE" . substr($decoded_data, 0, $length);
        $hash = substr(sodium_crypto_generichash($data, '', 64), 0, 2);

        $isValid = (ord($decoded_data[0]) & 128) === 0 && !in_array(ord($decoded_data[0]), [46, 47]) && ($isPublicKey
                ? str_ends_with($decoded_data, $hash)
                : substr($decoded_data, -1) === $hash[0]);
        if (!$isValid && !$ignore_checksum) {
            return "";
        }
        return bin2hex(substr($decoded_data, $ss58_length, $length - $ss58_length));
    }
}