<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Leasing;

use Carbon\CarbonImmutable;

/**
 * ITEM8 — the cohesive pure registry-shaping concern the claim-lease repository uses to project
 * a full lease into a compact registry entry and to capacity-bound the in-memory registry.
 *
 * Two methods migrated verbatim from AgentControlPlaneClaimLeaseRepository:
 *  - {@see self::registryEntryFromLease}: project a full lease into a compact 9-key registry
 *    index entry (lease_id, task_packet_id, agent_id, lease_status, acquired_at, expires_at,
 *    expires_at_unix, write_set, read_set). The registry is an index for active conflict
 *    checks, NOT the audit log; full lease payloads remain persisted as individual lease files.
 *  - {@see self::compactRegistry}: capacity-bound the registry by sorting active leases first
 *    (then by `expires_at_unix` DESC), truncating to {@see self::MAX_REGISTRY_ENTRIES}, and
 *    stamping a compaction receipt (compacted + compacted_from_entry_count + compacted_at +
 *    compaction_policy). Truncation is an INDEX-level cap; lease files are never deleted by
 *    this method.
 *
 * Pure / stateless / zero Laravel surface (CarbonImmutable provides the compaction timestamp).
 * MAX_REGISTRY_ENTRIES + LEASE_STATUS_ACTIVE mirror the god-class's constants verbatim so the
 * byte-identical registry contract survives the split.
 */
class AgentControlPlaneLeaseRegistryShaper
{
    public const MAX_REGISTRY_ENTRIES = 1_000;

    public const LEASE_STATUS_ACTIVE = 'active';

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    public function registryEntryFromLease(array $lease): array
    {
        return [
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'agent_id' => (string) ($lease['agent_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'acquired_at' => (string) ($lease['acquired_at'] ?? ''),
            'expires_at' => (string) ($lease['expires_at'] ?? ''),
            'expires_at_unix' => (int) ($lease['expires_at_unix'] ?? 0),
            'write_set' => (array) ($lease['write_set'] ?? []),
            'read_set' => (array) ($lease['read_set'] ?? []),
        ];
    }

    /**
     * The registry is an index for active conflict checks, not the audit log.
     * Full lease payloads remain persisted as individual lease files.
     *
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    public function compactRegistry(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), 'is_array'));
        if (count($entries) <= self::MAX_REGISTRY_ENTRIES) {
            $registry['entries'] = $entries;

            return $registry;
        }

        usort($entries, static function (array $a, array $b): int {
            $aActive = (string) ($a['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE;
            $bActive = (string) ($b['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE;
            if ($aActive !== $bActive) {
                return $aActive ? -1 : 1;
            }

            return ((int) ($b['expires_at_unix'] ?? 0)) <=> ((int) ($a['expires_at_unix'] ?? 0));
        });

        $registry['entries'] = array_slice($entries, 0, self::MAX_REGISTRY_ENTRIES);
        $registry['compacted'] = true;
        $registry['compacted_from_entry_count'] = count($entries);
        $registry['compacted_at'] = CarbonImmutable::now()->toIso8601String();
        $registry['compaction_policy'] = 'keep_active_leases_first_then_recent_index_entries_without_deleting_lease_files';

        return $registry;
    }
}
