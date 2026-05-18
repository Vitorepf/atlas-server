<?php

namespace App\Services\Ai\Mission;

class MissionCanonicalHash
{
    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    public static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            self::sortKeysRecursive($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private static function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $sorted = array_map(static fn ($item) => self::sortKeysRecursive($item), $value);

        if ($isList) {
            return $sorted;
        }

        ksort($sorted);

        return $sorted;
    }
}
