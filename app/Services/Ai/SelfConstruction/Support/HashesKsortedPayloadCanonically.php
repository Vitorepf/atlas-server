<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Hashes a payload deterministically after recursively ksort'ing it.
 * Self-contained: no consuming class needs to provide a recursivelyKsort method.
 */
trait HashesKsortedPayloadCanonically
{
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::ksortPayload($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private static function ksortPayload(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortPayload($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
