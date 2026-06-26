<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * ITEM8 — the canonicalization/hashing utility cluster extracted from
 * {@see \App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService}.
 * Owns the three pure helpers the corridor relies on to produce stable byte-identical hashes for
 * the corridor payload + the per-envelope summaries.
 *
 * Three methods migrated verbatim from the god-class (and its old static helper
 * `FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher`):
 *  - {@see self::stableHash}: SHA-256 of the canonical JSON. The hash is taken AFTER
 *    `stripVolatileKeys` removes the seven volatile identity fields (so the hash is
 *    semantically stable across re-runs that vary only by timestamp or self-hash) AND after
 *    ksort + the in-method unset of `generated_at` / `closure_corridor_hash` /
 *    `operator_submission_envelopes_hash` (defense in depth).
 *  - {@see self::stripVolatileKeys}: recursively remove the volatile identity keys from the
 *    payload (top-level + every nested array).
 *  - {@see self::ksortRecursive}: deep ksort that preserves list vs assoc shape (ksort only
 *    on assoc arrays so list order is semantic, never sorted).
 *
 * Instance form (NOT static) so the god-class can hold it via a constructor-injected / lazy
 * property with the default-to-fresh-instance pattern; all internal logic is pure / stateless so
 * the behaviour is byte-identical to the previous static helper.
 */
class FinalOperatorClosureCorridorHashSupport
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function stableHash(array $payload): string
    {
        $payload = $this->stripVolatileKeys($payload);
        unset($payload['generated_at'], $payload['closure_corridor_hash'], $payload['operator_submission_envelopes_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stripVolatileKeys(array $payload): array
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
                $payload[$key] = $this->stripVolatileKeys($value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $value */
    public function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
