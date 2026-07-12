<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: the ONE canonical hash of an acceptance-criteria set.
 *
 * Owns: reducing a criteria list to a canonical, order-independent, volatile-id-free form and
 * hashing it, so the frozen_hash PROVABLY binds to the exact criteria that were inspected. This is
 * what closes the "binding without proof-of-binding" hole in BOTH Obra #1 (certify) and Obra #2
 * (freeze): a hash minted here cannot be a pass-through value decoupled from real criteria.
 * Must never own: deciding whether the criteria are good (the floors do that).
 *
 * Canonicalization rules (pinned by golden test — change breaks every freeze):
 *   - drop volatile/derived keys (id) — two lists that differ only in derived ids hash the same;
 *   - keep semantic content (description, verification, verification_ref, case_class, is_backstop);
 *   - collapse internal whitespace and trim string values;
 *   - sort each criterion's keys, then sort the list — order-independent.
 */
final class CriteriaCanonicalizer
{
    private const SEMANTIC_KEYS = ['description', 'verification', 'verification_ref', 'case_class', 'is_backstop'];

    /**
     * @param  array<int,array<string,mixed>>  $criteria
     * @return list<array<string,mixed>>
     */
    public static function canonicalize(array $criteria): array
    {
        $normalized = [];
        foreach ($criteria as $ac) {
            if (! is_array($ac)) {
                continue;
            }
            $record = [];
            foreach (self::SEMANTIC_KEYS as $key) {
                if (! array_key_exists($key, $ac)) {
                    continue;
                }
                $value = $ac[$key];
                $record[$key] = is_string($value) ? self::normalizeString($value) : $value;
            }
            ksort($record);
            $normalized[] = $record;
        }

        // order-independent: sort the list by its canonical JSON so re-ordered criteria hash the same
        usort($normalized, static fn (array $a, array $b): int => strcmp(
            json_encode($a, JSON_THROW_ON_ERROR),
            json_encode($b, JSON_THROW_ON_ERROR),
        ));

        return $normalized;
    }

    /**
     * @param  array<int,array<string,mixed>>  $criteria
     */
    public static function hash(array $criteria): string
    {
        return hash('sha256', json_encode(self::canonicalize($criteria), JSON_THROW_ON_ERROR));
    }

    private static function normalizeString(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
