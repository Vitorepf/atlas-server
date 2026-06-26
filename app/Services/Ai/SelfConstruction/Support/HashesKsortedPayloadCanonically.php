<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Hashes a payload deterministically after recursively ksort'ing it.
 *
 * COUPLING: the body of {@see stableHash} calls `$this->recursivelyKsort($payload)`,
 * so every class that uses this trait MUST also expose a
 * `private function recursivelyKsort(array $value): array` member (the four
 * swap targets — ClosureCorridorCanonicalHasher and its peers — already do).
 * No self::CONST or $this->prop is referenced, so any concrete class can drop
 * this trait in once the ksort helper exists.
 */
trait HashesKsortedPayloadCanonically
{
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
