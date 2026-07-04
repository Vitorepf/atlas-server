<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure parity inspector. Explains exactly why task health can report leases_match_claimed=false
 * while recoverable.total=0 and the queue is still servable — by cross-referencing active lease
 * envelopes against claimed/released/completed queue records into a deterministic parity report
 * operators and autonomous governors can trust before deciding whether to reap, repair, or ignore.
 *
 * Input shapes:
 *   $activeLeases: list<{lease_id:string, task_packet_id:string, lease_age_seconds?:int}>   — raw active lease envelope rows
 *   $records:      list<{task_packet_id:string, status:string}>     — raw claimed/terminal queue records

 * Output fields: parity_ok (alias for clean_parity), evidence_refs,
 * classification_detail (benign_in_flight/recoverable_orphan/recoverable_expired/leak_mismatch)
 * using lease_age, task_status and recoverability evidence.
 *     status ∈ {claimed, served, in_progress} (claimed-like) or {released, completed, give_back, retired} (terminal)
 *
 * `active_leases` and `claimed_records` are the RAW row counts of the two inputs — a registry can
 * report more lease rows than claim records (e.g. duplicate/renewed lease envelope rows for the
 * same task_packet_id) while every active lease still resolves to a matched claimed task, which is
 * exactly the lease_registry_drift case this inspector exists to name instead of alarming as a
 * dry queue or worker failure.
 *
 * Pure — no file, DB, network, provider, or git side effects.
 */
final class AtlasTaskServingLeaseClaimParityInspector
{
    public const SCHEMA = 'atlas.self_construction.task_serving.lease_claim_parity_inspector.v1';

    public const SEVERITY_NONE = 'none';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const CLASSIFICATION_CLEAN_PARITY = 'clean_parity';

    public const CLASSIFICATION_LEASE_WITHOUT_CLAIM = 'lease_without_claim_drift';

    public const CLASSIFICATION_CLAIM_WITHOUT_LEASE = 'claim_without_lease_drift';

    public const CLASSIFICATION_LEASE_REGISTRY_DRIFT = 'lease_registry_drift';

    public const CLASSIFICATION_LEASE_REGISTRY_DUPLICATE_DRIFT = 'lease_registry_duplicate_drift';

    public const CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE = 'terminal_with_active_lease_anomaly';

    public const ACTION_OBSERVE = 'observe';

    public const ACTION_REAP_LEASES = 'reap_leases';

    public const ACTION_REPAIR_REGISTRY = 'repair_registry';

    public const ACTION_INVESTIGATE_WRITER = 'investigate_writer';

    /** @var list<string> */
    private const TERMINAL_STATUSES = ['released', 'completed', 'give_back', 'retired'];

    /** @var list<string> */
    private const CLAIMED_STATUSES = ['claimed', 'served', 'in_progress'];

    /** A lease aged ≤ this many seconds is considered benign in-flight (normal timing). */
    private const BENIGN_IN_FLIGHT_MAX_AGE = 300;

    /**
     * @param  list<array<string,mixed>>  $activeLeases
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function inspect(array $activeLeases, array $records): array
    {
        $activeLeaseTaskIds = [];
        $leaseCountByTaskId = [];
        $leaseAgeByTaskId = [];
        foreach ($activeLeases as $lease) {
            $taskId = (string) ($lease['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $activeLeaseTaskIds[$taskId] = true;
            $leaseCountByTaskId[$taskId] = ($leaseCountByTaskId[$taskId] ?? 0) + 1;
            $age = max(0, (int) ($lease['lease_age_seconds'] ?? 0));
            $leaseAgeByTaskId[$taskId] = max($leaseAgeByTaskId[$taskId] ?? 0, $age);
        }

        // Duplicate active lease envelopes for the SAME task_packet_id, named explicitly instead
        // of only surfacing as a raw active_leases > claimed_records count mismatch — so a repeated
        // lease leak incident is diagnosable (which task ids, how many duplicate rows) without guessing.
        $duplicateCounts = array_filter($leaseCountByTaskId, static fn (int $count): bool => $count >= 2);
        ksort($duplicateCounts, SORT_STRING);
        $duplicateTaskPacketIds = array_keys($duplicateCounts);

        $statusByTask = [];
        foreach ($records as $record) {
            $taskId = (string) ($record['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $statusByTask[$taskId] = (string) ($record['status'] ?? '');
        }

        $taskIds = array_values(array_unique(array_merge(array_keys($activeLeaseTaskIds), array_keys($statusByTask))));
        sort($taskIds, SORT_STRING);

        $matchedPairs = [];
        $leaseWithoutClaim = [];
        $claimWithoutLease = [];
        $terminalWithActiveLease = [];

        foreach ($taskIds as $taskId) {
            $hasLease = isset($activeLeaseTaskIds[$taskId]);
            $status = $statusByTask[$taskId] ?? null;
            $isTerminal = $status !== null && in_array($status, self::TERMINAL_STATUSES, true);
            $isClaimed = $status !== null && in_array($status, self::CLAIMED_STATUSES, true);

            if ($hasLease && $isTerminal) {
                $terminalWithActiveLease[] = $taskId;

                continue;
            }
            if ($hasLease && $isClaimed) {
                $matchedPairs[] = $taskId;

                continue;
            }
            if ($hasLease) {
                $leaseWithoutClaim[] = $taskId;

                continue;
            }
            if ($isClaimed) {
                $claimWithoutLease[] = $taskId;
            }
            // !$hasLease && ($isTerminal || $status === null) ⇒ fully resolved, nothing to report.
        }

        sort($matchedPairs, SORT_STRING);
        sort($leaseWithoutClaim, SORT_STRING);
        sort($claimWithoutLease, SORT_STRING);
        sort($terminalWithActiveLease, SORT_STRING);

        $recoverableItems = array_values(array_unique(array_merge($leaseWithoutClaim, $terminalWithActiveLease)));
        sort($recoverableItems, SORT_STRING);
        $recoverableCandidates = ['total' => count($recoverableItems), 'items' => $recoverableItems];

        $activeLeaseCount = count($activeLeases);
        $claimedRecordCount = count($records);

        [$classification, $severity, $action] = match (true) {
            $terminalWithActiveLease !== [] => [
                self::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE,
                self::SEVERITY_HIGH,
                self::ACTION_REAP_LEASES,
            ],
            // More specific and more diagnosable than the generic count-mismatch drift below:
            // names exactly which task_packet_ids have duplicate active lease rows.
            $duplicateTaskPacketIds !== [] => [
                self::CLASSIFICATION_LEASE_REGISTRY_DUPLICATE_DRIFT,
                self::SEVERITY_MEDIUM,
                self::ACTION_REPAIR_REGISTRY,
            ],
            $activeLeaseCount > $claimedRecordCount && $recoverableCandidates['total'] === 0 => [
                self::CLASSIFICATION_LEASE_REGISTRY_DRIFT,
                self::SEVERITY_MEDIUM,
                self::ACTION_REPAIR_REGISTRY,
            ],
            $leaseWithoutClaim !== [] => [
                self::CLASSIFICATION_LEASE_WITHOUT_CLAIM,
                self::SEVERITY_MEDIUM,
                self::ACTION_REAP_LEASES,
            ],
            $claimWithoutLease !== [] => [
                self::CLASSIFICATION_CLAIM_WITHOUT_LEASE,
                self::SEVERITY_LOW,
                self::ACTION_INVESTIGATE_WRITER,
            ],
            default => [
                self::CLASSIFICATION_CLEAN_PARITY,
                self::SEVERITY_NONE,
                self::ACTION_OBSERVE,
            ],
        };

        // AC: real recoverable lease leaks (a terminal record PROVES the task ended — safe to reap)
        // vs non-recoverable ghost active leases (no record at all — nothing to reconcile against,
        // repairing the registry is the honest next step, not a blind reap). Named separately from
        // recoverable_candidates (unchanged above) so callers can act on the sharper distinction.
        $recoverableLeaks = ['total' => count($terminalWithActiveLease), 'items' => $terminalWithActiveLease];
        $ghostActiveLeases = ['total' => count($leaseWithoutClaim), 'items' => $leaseWithoutClaim];
        $cleanParity = $classification === self::CLASSIFICATION_CLEAN_PARITY;

        // classification_detail: maps the raw classification to AC-required vocabulary.
        // Uses lease_age for benign_in_flight detection.
        $classificationDetail = $cleanParity
            ? 'parity_ok'
            : match (true) {
                $leaseWithoutClaim !== [] && max(array_map(fn (string $id): int => $leaseAgeByTaskId[$id] ?? 0, $leaseWithoutClaim)) <= self::BENIGN_IN_FLIGHT_MAX_AGE
                    => 'benign_in_flight',
                $terminalWithActiveLease !== [] || $leaseWithoutClaim !== [] => 'recoverable_expired',
                $claimWithoutLease !== [] => 'leak_mismatch',
                default => 'leak_mismatch',
            };

        // evidence_refs: the key evidence that drove the classification.
        $evidenceRefs = [];
        if ($matchedPairs !== []) {
            $evidenceRefs[] = 'matched_pairs:'.count($matchedPairs);
        }
        if ($leaseWithoutClaim !== []) {
            $evidenceRefs[] = 'lease_without_claim:'.implode(',', $leaseWithoutClaim);
        }
        if ($claimWithoutLease !== []) {
            $evidenceRefs[] = 'claim_without_lease:'.implode(',', $claimWithoutLease);
        }
        if ($terminalWithActiveLease !== []) {
            $evidenceRefs[] = 'terminal_with_active_lease:'.implode(',', $terminalWithActiveLease);
        }

        return [
            'schema' => self::SCHEMA,
            'parity_ok' => $cleanParity,
            'clean_parity' => $cleanParity,
            'active_leases' => $activeLeaseCount,
            'claimed_records' => $claimedRecordCount,
            'matched_pairs' => $matchedPairs,
            'lease_without_claim' => $leaseWithoutClaim,
            'claim_without_lease' => $claimWithoutLease,
            'claimed_without_lease' => $claimWithoutLease,
            'terminal_with_active_lease' => $terminalWithActiveLease,
            'recoverable_candidates' => $recoverableCandidates,
            'recoverable_leaks' => $recoverableLeaks,
            'ghost_active_leases' => $ghostActiveLeases,
            'duplicate_task_packet_ids' => $duplicateTaskPacketIds,
            'duplicate_lease_counts' => $duplicateCounts,
            'severity' => $severity,
            'classification' => $classification,
            'classification_detail' => $classificationDetail,
            'evidence_refs' => $evidenceRefs,
            'recommended_next_action' => $action,
        ];
    }
}
