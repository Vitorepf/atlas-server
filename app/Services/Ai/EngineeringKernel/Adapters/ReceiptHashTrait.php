<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

/**
 * Canonical receipt hashing: sha256 of a recursive-ksort-normalized JSON payload.
 *
 * Domain classes keep payload shaping; this trait owns the canonicalization and
 * hashing mechanics so md5/sha256 drift and key-order sensitivity cannot creep
 * back in.
 */
trait ReceiptHashTrait
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        $canonical = json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return hash('sha256', (string) $canonical);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function prefixedHashPayload(string $prefix, array $payload, int $length): string
    {
        return $prefix.substr(self::hashPayload($payload), 0, $length);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonicalize($value);
            }
        }

        return $payload;
    }
}
