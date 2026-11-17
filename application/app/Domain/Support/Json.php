<?php

namespace App\Domain\Support;

final class Json
{
    public static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return $value === null ? [] : (json_decode($value, true, 64, JSON_THROW_ON_ERROR) ?: []);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalEncode($value));
    }

    public static function canonicalEncode(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return self::encode($sort($value));
    }
}
