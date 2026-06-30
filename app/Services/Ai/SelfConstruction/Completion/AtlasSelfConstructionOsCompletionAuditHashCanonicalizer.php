<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Deterministic payload-canonicalization for the Atlas self-construction
 * OS completion audit.
 *
 * Extracted from AtlasSelfConstructionOsCompletionAuditService to reduce
 * the god-class. All methods are stateless.
 */
final class AtlasSelfConstructionOsCompletionAuditHashCanonicalizer
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public static function stableHash(array $payload): string
    {
        unset($payload['audited_at'], $payload['completion_audit_hash'], $payload['raw_prompt'], $payload['provider_trace']);

        return hash('sha256', (string) json_encode(self::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
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