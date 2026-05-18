<?php

namespace App\Services\Ai\AutomationDomain;

/**
 * Deterministic JSON-stable sha256 hash used by every Automation domain
 * artifact (run, plan, decision, evolution event) to make payloads
 * reproducible across machines and replay-able.
 */
class AutomationCanonicalHash
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
