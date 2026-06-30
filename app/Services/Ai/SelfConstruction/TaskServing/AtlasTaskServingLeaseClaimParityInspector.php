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
 *   $activeLeases: list<{lease_id:string, task_packet_id:string}>   — raw active lease envelope rows
 *   $records:      list<{task_packet_id:string, status:string}>     — raw claimed/terminal queue records
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

    public const CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE = 'terminal_with_active_lease_anomaly';

    public const ACTION_OBSERVE = 'observe';

    public const ACTION_REAP_LEASES = 'reap_leases';

    public const ACTION_REPAIR_REGISTRY = 'repair_registry';

    public const ACTION_INVESTIGATE_WRITER = 'investigate_writer';

    /** @var list<string> */
    private const TERMINAL_STATUSES = ['released', 'completed', 'give_back', 'retired'];

    /** @var list<string> */
    private const CLAIMED_STATUSES = ['claimed', 'served', 'in_progress'];

    /**
     * @param  list<array<string,mixed>>  $activeLeases
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function inspect(array $activeLeases, array $records): array
    {
        $activeLeaseTaskIds = [];
        foreach ($activeLeases as $lease) {
            $taskId = (string) ($lease['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $activeLeaseTaskIds[$taskId] = true;
        }

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

        return [
            'schema' => self::SCHEMA,
            'active_leases' => $activeLeaseCount,
            'claimed_records' => $claimedRecordCount,
            'matched_pairs' => $matchedPairs,
            'lease_without_claim' => $leaseWithoutClaim,
            'claim_without_lease' => $claimWithoutLease,
            'terminal_with_active_lease' => $terminalWithActiveLease,
            'recoverable_candidates' => $recoverableCandidates,
            'severity' => $severity,
            'classification' => $classification,
            'recommended_next_action' => $action,
        ];
    }
}
