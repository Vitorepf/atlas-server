<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

/**
 * Stateless canonicalization/hashing helpers for the final operator evidence
 * closure corridor service.
 *
 * Extracted from AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService
 * to reduce the god-class. All methods are pure — no instance state.
 */
final class ClosureCorridorCanonicalHasher
{
    public static function storageAppPath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : 'storage/app/'.$path;
    }

    public static function privateStorageAppPath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : 'storage/app/private/'.$path;
    }

    /** @param array<string, mixed> $payload */
    public static function stableHash(array $payload): string
    {
        $payload = self::stripVolatileKeys($payload);
        unset($payload['generated_at'], $payload['closure_corridor_hash'], $payload['operator_submission_envelopes_hash']);

        return hash('sha256', (string) json_encode(self::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stripVolatileKeys(array $payload): array
    {
        foreach ([
            'generated_at',
            'audited_at',
            'verified_at',
            'certified_at',
            'persisted_at',
            'assessed_at',
            'closure_corridor_hash',
            'operator_submission_envelopes_hash',
        ] as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::stripVolatileKeys($value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $value */
    public static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
