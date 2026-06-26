<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pure sha256 hasher of a payload via JSON-encode with stable flags. The body
 * is byte-identical to the pure sub-family (sub-hash aaf8e4943b9a) of the
 * canonical hasher. It references no $this->, no self::CONST and no class
 * property, so any consumer can drop the trait in without providing extra
 * members. Unlike {@see HashesKsortedPayloadCanonically}, this one does NOT
 * ksort first — reordering top-level keys WILL change the resulting hash.
 */
trait HashesPayloadCanonically
{
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
