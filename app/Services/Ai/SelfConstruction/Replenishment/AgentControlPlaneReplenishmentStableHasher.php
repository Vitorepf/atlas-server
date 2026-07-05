<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenishment;

/**
 * ITEM8 — the cohesive stable-hash canonicalization concern the auto-replenishment service uses
 * to fingerprint replenishment payloads (drop the volatile identity fields, deep ksort, SHA-256
 * the canonical JSON).
 *
 * Three methods migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService}:
 *  - {@see self::normalizeForHash}: clone the payload, drop `generated_at` and
 *    `auto_replenishment_hash` (the volatile identity fields the hash must NOT cover), then
 *    deep ksort so the resulting JSON is bit-identical regardless of key order.
 *  - {@see self::recursivelyKsort}: deep ksort that preserves list vs assoc shape (ksort only
 *    on assoc arrays so list order is semantic, never sorted).
 *  - {@see self::stableHash}: SHA-256 over the canonical JSON (no pretty, no escape slashes,
 *    no escape unicode — the format that makes a hash diff input-order-independent ONLY when
 *    preceded by `normalizeForHash` + `recursivelyKsort`).
 *
 * Pure / stateless / zero Laravel surface — extracted so the auto-replenishment service can
 * split cohesive canonicalization logic out of its public signature without changing ANY
 * caller-visible byte.
 */
class AgentControlPlaneReplenishmentStableHasher
{
    /**
     * Volatile identity / runtime fields that must NEVER participate in the semantic hash.
     *
     * - Timestamps: generated_at, created_at, updated_at, resolved_at, leased_at, expires_at, ts
     * - Lease/process ids: lease_id, process_id, pid, worker_id, session_id
     * - Temp paths: tmp_path, temp_dir, scratch_path
     * - Counters / runtime ids: auto_replenishment_hash, attempt_count, retry_count, sequence
     */
    private const VOLATILE_FIELDS = [
        'generated_at',
        'created_at',
        'updated_at',
        'resolved_at',
        'leased_at',
        'expires_at',
        'ts',
        'timestamp',
        'lease_id',
        'process_id',
        'pid',
        'worker_id',
        'session_id',
        'tmp_path',
        'temp_dir',
        'scratch_path',
        'auto_replenishment_hash',
        'attempt_count',
        'retry_count',
        'sequence',
        'run_id',
        'trace_id',
        'request_id',
        'correlation_id',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        foreach (self::VOLATILE_FIELDS as $field) {
            unset($clone[$field]);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    public function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    public function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
