<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Leasing;

/**
 * ITEM8 — the cohesive pure-canonicalization concern the claim-lease repository uses to normalize
 * scope-lock path sets, list strings, match prune filters, and derive per-lease storage paths.
 *
 * Four methods migrated verbatim from AgentControlPlaneClaimLeaseRepository:
 *
 *  - {@see self::normalizeSet}: trim + drop empties + backslash→slash + dedup + sort, so two
 *    equivalent path strings ("a\b" and "a/b") collapse to the same canonical form.
 *  - {@see self::stringList}: trim + non-empty filter + array_values on any list of mixed values
 *    (the same shape used by {@see \App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer}).
 *  - {@see self::leaseEntryMatchesPruneFilters}: a registry entry matches the prune request when
 *    ANY of its (task_packet_id, agent_id, lease_id) fields starts with ANY of the corresponding
 *    prefixes (empty prefixes are skipped — never accidentally match anything).
 *  - {@see self::leasePath}: derive the canonical storage path for a lease id (the lease id is
 *    sanitized to [A-Za-z0-9_-] so no path traversal can escape the storage prefix).
 *
 * Pure / stateless / zero Laravel surface. STORAGE_PREFIX mirrors the god-class's constant value
 * verbatim so the byte-identical path contract survives the split.
 */
class AgentControlPlaneLeasePathCanonicalizer
{
    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/leases';

    /**
     * @param  list<string>  $set
     * @return list<string>
     */
    public function normalizeSet(array $set): array
    {
        $out = [];
        foreach ($set as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $out[] = str_replace('\\', '/', $value);
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $taskPrefixes
     * @param  list<string>  $agentPrefixes
     * @param  list<string>  $leasePrefixes
     */
    public function leaseEntryMatchesPruneFilters(array $entry, array $taskPrefixes, array $agentPrefixes, array $leasePrefixes): bool
    {
        foreach ($taskPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with((string) ($entry['task_packet_id'] ?? ''), $prefix)) {
                return true;
            }
        }
        foreach ($agentPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with((string) ($entry['agent_id'] ?? ''), $prefix)) {
                return true;
            }
        }
        foreach ($leasePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with((string) ($entry['lease_id'] ?? ''), $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function leasePath(string $leaseId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $leaseId) ?? $leaseId;

        return self::STORAGE_PREFIX.'/'.$safe.'.json';
    }
}
