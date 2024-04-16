<?php

class Base32
{
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private function isByteArray($data)
    {
        if (is_array($data))
        {
            foreach ($data as $value)
                if (!is_int($value) || $value < 0 || $value > 255)
                    throw new Error('The given data is not a byte array');

            return true;
        }

        throw new Error('The given data is not a byte array');
    }

    public function encode($data): string
    {
        self::isByteArray($data);
        $base32 = '';

        for ($i = 0; $i < count($data); ++$i)
        {
            $value = $data[$i];
            assert(0 <= $value && $value < 32, 'Invalid value: ' . $value . '.');
            $base32 .= self::CHARSET[$value];
        }

        return $base32;
    }

    public function decode($data)
    {
        assert(is_string($data), 'Invalid base32-encoded string: ' . $data . '.');
        $result = [];

        for ($i = 0; $i < strlen($data); ++$i)
        {
            $value = $data[$i];
            assert(str_contains(self::CHARSET, $value), 'Invalid value: ' . $value . '.');
            $result[$i] = strpos(self::CHARSET, $value);
        }

        return $result;
    }
}
