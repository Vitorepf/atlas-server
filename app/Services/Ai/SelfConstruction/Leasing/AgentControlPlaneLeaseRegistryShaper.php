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

    public const CLASSIFICATION_LIVE = 'live';
    public const CLASSIFICATION_STALE = 'stale';
    public const CLASSIFICATION_ORPHANED = 'orphaned';
    public const CLASSIFICATION_LEAKED = 'leaked';
    public const CLASSIFICATION_COMPACTABLE = 'compactable';

    /**
     * Classify registry entries and produce leak diagnosis + safe compaction actions.
     *
     * @param  array<string, mixed>  $registry  The lease registry
     * @param  array<string, mixed>  $claimedTasks  Claimed task records keyed by task_packet_id
     * @return array{classifications:array<string,string>,leak_diagnosis:list<string>,leak_diagnostics:list<array{lease_id:string,task_packet_id:string,mismatch_type:string,safe_recovery_hint:string}>,claimed_mismatches:list<string>,safe_compaction_actions:list<string>,compactable_count:int}
     */
    public function diagnoseAndCompact(array $registry, array $claimedTasks): array
    {
        $entries = (array) ($registry['entries'] ?? []);
        $classifications = [];
        $leakDiagnosis = [];
        $leakDiagnostics = [];
        $claimedMismatches = [];
        $safeCompactionActions = [];

        foreach ($entries as $idx => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $leaseId = (string) ($entry['lease_id'] ?? '');
            $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
            $leaseStatus = (string) ($entry['lease_status'] ?? '');
            $expiresAtUnix = (int) ($entry['expires_at_unix'] ?? 0);
            $now = time();

            $classification = $this->classifyEntry($entry, $claimedTasks, $now);
            $classifications[$leaseId] = $classification;

            // Leaked: lease is active but no matching claimed task record
            if ($classification === self::CLASSIFICATION_LEAKED) {
                $leakDiagnosis[] = sprintf(
                    'leaked:lease_id=%s task_packet_id=%s lease_status=%s no_claimed_task_record',
                    $leaseId,
                    $taskPacketId,
                    $leaseStatus,
                );

                $mismatchType = $this->determineMismatchType($entry, $claimedTasks);
                $safeRecoveryHint = $this->safeRecoveryHint($classification, $mismatchType, $leaseId, $taskPacketId);

                $leakDiagnostics[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => $taskPacketId,
                    'mismatch_type' => $mismatchType,
                    'safe_recovery_hint' => $safeRecoveryHint,
                ];
            }

            // Claimed mismatch: lease references a task that exists in claimed records but with different agent
            if ($classification === self::CLASSIFICATION_STALE) {
                $claimedMismatches[] = sprintf(
                    'claimed_mismatch:lease_id=%s task_packet_id=%s lease_expired_at=%d now=%d',
                    $leaseId,
                    $taskPacketId,
                    $expiresAtUnix,
                    $now,
                );
            }

            // Safe compaction: only compact stale, orphaned or compactable entries — never live or leaked
            if (in_array($classification, [self::CLASSIFICATION_STALE, self::CLASSIFICATION_ORPHANED, self::CLASSIFICATION_COMPACTABLE], true)) {
                $safeCompactionActions[] = sprintf(
                    'compact:lease_id=%s classification=%s reason=safe_to_remove_not_live_or_ambiguous',
                    $leaseId,
                    $classification,
                );
            }
        }

        $compactableCount = count(array_filter($classifications, static fn (string $c): bool => in_array($c, [self::CLASSIFICATION_STALE, self::CLASSIFICATION_ORPHANED, self::CLASSIFICATION_COMPACTABLE], true)));

        return [
            'classifications' => $classifications,
            'leak_diagnosis' => $leakDiagnosis,
            'leak_diagnostics' => $leakDiagnostics,
            'claimed_mismatches' => $claimedMismatches,
            'safe_compaction_actions' => $safeCompactionActions,
            'compactable_count' => $compactableCount,
        ];
    }

    /**
     * Determine the specific mismatch type for a leaked entry.
     */
    private function determineMismatchType(array $entry, array $claimedTasks): string
    {
        $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
        $leaseStatus = (string) ($entry['lease_status'] ?? '');
        $expiresAtUnix = (int) ($entry['expires_at_unix'] ?? 0);
        $now = time();

        // Active worker lease with no claimed record — most likely a real leak
        if ($leaseStatus === self::LEASE_STATUS_ACTIVE && $taskPacketId !== '') {
            return 'active_worker_no_claimed_record';
        }

        // Expired lease with no claimed record — stale leak
        if ($expiresAtUnix > 0 && $now > $expiresAtUnix) {
            return 'stale_expired_no_claimed_record';
        }

        // Orphan-like: no task_packet_id
        if ($taskPacketId === '') {
            return 'orphan_no_task_reference';
        }

        return 'unknown_mismatch';
    }

    /**
     * Generate a safe recovery hint for a classification/mismatch combination.
     */
    private function safeRecoveryHint(string $classification, string $mismatchType, string $leaseId, string $taskPacketId): string
    {
        return match ($mismatchType) {
            'active_worker_no_claimed_record' => sprintf(
                'verify_worker_still_active_for_lease=%s task=%s then_recreate_claimed_record_or_release_lease',
                $leaseId,
                $taskPacketId,
            ),
            'stale_expired_no_claimed_record' => sprintf(
                'safe_to_compact_expired_lease=%s no_active_worker_holds_it',
                $leaseId,
            ),
            'orphan_no_task_reference' => sprintf(
                'safe_to_remove_orphan_lease=%s no_task_reference_to_recover',
                $leaseId,
            ),
            default => sprintf(
                'manual_review_required_for_lease=%s classification=%s',
                $leaseId,
                $classification,
            ),
        };
    }

    /**
     * Classify a single registry entry.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $claimedTasks
     */
    private function classifyEntry(array $entry, array $claimedTasks, int $now): string
    {
        $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
        $leaseStatus = (string) ($entry['lease_status'] ?? '');
        $expiresAtUnix = (int) ($entry['expires_at_unix'] ?? 0);

        // Orphaned: no task_packet_id — cannot be matched to any claimed task
        if ($taskPacketId === '') {
            return self::CLASSIFICATION_ORPHANED;
        }

        // Live: active lease with valid claimed task record
        if ($leaseStatus === self::LEASE_STATUS_ACTIVE && isset($claimedTasks[$taskPacketId])) {
            return self::CLASSIFICATION_LIVE;
        }

        // Leaked: active lease but no matching claimed task record
        if ($leaseStatus === self::LEASE_STATUS_ACTIVE && ! isset($claimedTasks[$taskPacketId])) {
            return self::CLASSIFICATION_LEAKED;
        }

        // Stale: expired lease (past expires_at_unix)
        if ($expiresAtUnix > 0 && $now > $expiresAtUnix) {
            return self::CLASSIFICATION_STALE;
        }

        // Orphaned: no matching claimed task and not active
        if (! isset($claimedTasks[$taskPacketId])) {
            return self::CLASSIFICATION_ORPHANED;
        }

        // Compactable: non-active, non-expired entries that can be safely compacted
        return self::CLASSIFICATION_COMPACTABLE;
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    public function registryEntryFromLease(array $lease): array
    {
        $writeSet = $this->normalizePathSet((array) ($lease['write_set'] ?? []));
        $readSet = $this->normalizePathSet((array) ($lease['read_set'] ?? []));

        return [
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'agent_id' => (string) ($lease['agent_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'authority_nonce' => (string) ($lease['authority_nonce'] ?? ''),
            'authority_revoked' => (bool) ($lease['authority_revoked'] ?? false),
            'acquired_at' => (string) ($lease['acquired_at'] ?? ''),
            'expires_at' => (string) ($lease['expires_at'] ?? ''),
            'expires_at_unix' => (int) ($lease['expires_at_unix'] ?? 0),
            'write_set' => $writeSet,
            'write_set_hash' => 'sha256:'.hash('sha256', json_encode($writeSet, JSON_THROW_ON_ERROR)),
            'read_set' => $readSet,
            'read_set_hash' => 'sha256:'.hash('sha256', json_encode($readSet, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<mixed>  $paths
     * @return list<string>
     */
    private function normalizePathSet(array $paths): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('strval', $paths),
            static fn (string $p): bool => $p !== '',
        )));
        sort($normalized);

        return $normalized;
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
            $timeDiff = ((int) ($b['expires_at_unix'] ?? 0)) <=> ((int) ($a['expires_at_unix'] ?? 0));
            if ($timeDiff !== 0) {
                return $timeDiff;
            }
            $idDiff = strcmp((string) ($a['lease_id'] ?? ''), (string) ($b['lease_id'] ?? ''));
            if ($idDiff !== 0) {
                return $idDiff;
            }

            return strcmp((string) ($a['task_packet_id'] ?? ''), (string) ($b['task_packet_id'] ?? ''));
        });

        $registry['entries'] = array_slice($entries, 0, self::MAX_REGISTRY_ENTRIES);
        $registry['compacted'] = true;
        $registry['compacted_from_entry_count'] = count($entries);
        $registry['compacted_at'] = CarbonImmutable::now()->toIso8601String();
        $registry['compaction_policy'] = 'keep_active_leases_first_then_recent_index_entries_without_deleting_lease_files';

        return $registry;
    }
}
