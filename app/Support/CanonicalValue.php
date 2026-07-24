<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Recursively canonicalize arrays by sorting associative keys (lists preserved).
 *
 * Full-pass reuse: de-duplicates private canonicalize() across Specialist/Router/
 * Compounding/LongHorizon services.
 */
final class CanonicalValue
{
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalize($item);
        }

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
