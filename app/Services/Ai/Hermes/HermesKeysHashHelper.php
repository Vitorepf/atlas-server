<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (keysHash).
 */
trait HermesKeysHashHelper
{
    private function keysHash(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $keys = array_values(array_filter(array_map(
            fn (mixed $key): ?string => is_string($key) ? $key : (is_numeric($key) ? (string) $key : null),
            array_keys($value),
        )));

        if ($keys === []) {
            return null;
        }

        sort($keys);

        return hash('sha256', implode(',', $keys));
    }
}
