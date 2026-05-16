<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Support;

use InvalidArgumentException;

final class CanonicalJson
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function canonicalize(array $payload): array
    {
        return self::canonicalizeValue($payload);
    }

    public static function canonicalizeWithout(array $payload, string $excludedKey): array
    {
        $copy = $payload;
        unset($copy[$excludedKey]);

        return self::canonicalize($copy);
    }

    public static function encode(array $payload): string
    {
        $encoded = json_encode(self::canonicalize($payload), self::ENCODE_FLAGS);

        if ($encoded === false) {
            throw new InvalidArgumentException('Unable to encode canonical JSON: '.json_last_error_msg());
        }

        return $encoded;
    }

    public static function encodeWithout(array $payload, string $excludedKey): string
    {
        return self::encode(self::canonicalizeWithout($payload, $excludedKey));
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::canonicalizeArray($value);
        }

        return $value;
    }

    private static function canonicalizeArray(array $value): array
    {
        if (self::isList($value)) {
            return array_map(self::canonicalizeValue(...), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[(string) $key] = self::canonicalizeValue($value[$key]);
        }

        return $ordered;
    }

    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_is_list($value);
    }
}
