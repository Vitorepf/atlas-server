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
    /** Provider-sensitive / volatile fields always excluded before hashing. @var list<string> */
    private const FIXED_VOLATILE_FIELDS = ['audited_at', 'completion_audit_hash', 'raw_prompt', 'provider_trace'];

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $extraVolatileFields  caller-declared volatile fields to additionally
     *                                              ignore (e.g. a run-specific timestamp key),
     *                                              merged with the fixed provider-safe set.
     */
    public static function stableHash(array $payload, array $extraVolatileFields = []): string
    {
        $payload = self::stripVolatileFields($payload, $extraVolatileFields);

        return hash('sha256', (string) json_encode(self::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * AC4: names which volatile/provider-sensitive fields were actually present in $payload and
     * therefore excluded from the hash — so the redaction is reported, not silent.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $extraVolatileFields
     * @return list<string>
     */
    public static function excludedFields(array $payload, array $extraVolatileFields = []): array
    {
        $allVolatile = array_values(array_unique(array_merge(
            self::FIXED_VOLATILE_FIELDS,
            array_map('strval', $extraVolatileFields),
        )));

        return array_values(array_filter(
            $allVolatile,
            static fn (string $field): bool => array_key_exists($field, $payload),
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $extraVolatileFields
     * @return array<string,mixed>
     */
    private static function stripVolatileFields(array $payload, array $extraVolatileFields): array
    {
        foreach (self::FIXED_VOLATILE_FIELDS as $field) {
            unset($payload[$field]);
        }
        foreach ($extraVolatileFields as $field) {
            unset($payload[(string) $field]);
        }

        return $payload;
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

        // AC2: indexed arrays whose elements are ALL arrays (evidence entries, blockers,
        // proof items) are treated as unordered sets — sort by canonical JSON so reordering
        // is invisible. Scalar lists remain ordered (preserves list-order semantics).
        if ($value !== [] && array_keys($value) === range(0, count($value) - 1) && self::isArrayOfArrays($value)) {
            usort($value, static function ($a, $b): int {
                return strcmp(
                    (string) json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) json_encode($b, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );
            });
        }

        return $value;
    }

    /**
     * Returns true when every element of $value is itself an array (a "list of records").
     * Empty arrays return false — nothing to sort.
     *
     * @param  array<int|string, mixed>  $value
     */
    private static function isArrayOfArrays(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
    }
}