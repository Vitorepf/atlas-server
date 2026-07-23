<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Operational recovery for Agent Control Plane task packets whose owning
 * agent dropped off: lease expired, lease file went missing, or the queue
 * record is stuck in `claimed` without a live lease/heartbeat.
 *
 * The service is the only sanctioned path to bring a claimed-but-abandoned
 * task back to `claimable` so a fresh agent can resume it safely. It never
 * reopens terminal records (`completed_dry_run`, `cancelled`).
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskLeaseRecoveryService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_lease_recovery.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_lease_recovery';

    public const REASON_LEASE_EXPIRED = 'lease_expired';

    public const REASON_LEASE_EXPIRED_ORPHANED = 'lease_expired_orphaned';

    public const RECOVERABILITY_RECOVERABLE_EXPIRED = 'recoverable_expired_lease';

    public const RECOVERABILITY_RECOVERABLE_ORPHAN = 'recoverable_orphaned_claim';

    public const RECOVERABILITY_RECOVERABLE_RELEASED = 'recoverable_released_task';

    public const RECOVERABILITY_ACTIVE_LEASE = 'active_lease_skip';

    public const RECOVERABILITY_TERMINAL = 'terminal_skip';

    public const RECOVERABILITY_CLAIMABLE = 'claimable_ready';

    public const RECOVERABILITY_BLOCKED_NON_RECOVERABLE = 'blocked_non_recoverable';

    public const RECOVERABILITY_OTHER = 'other_state';

    public const RECEIPT_TASK_LEASE_RECOVERY_EXECUTED = 'task_lease_recovery_executed';

    public const RECEIPT_TASK_LEASE_RECOVERY_SKIPPED = 'task_lease_recovery_skipped';

    public const RECEIPT_RELEASED_TASK_REQUEUED = 'released_task_requeued';

    public const RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED = 'released_task_requeue_skipped';

    private const ROOT_CAUSE_SUMMARY_EXAMPLE_LIMIT = 5;

    private const MAX_RELEASED_RECOVERY_RECORDS = 64;

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AgentControlPlaneClaimLeaseRepository $leases = null,
    ) {}

    /**
     * Scan all leases, expire any whose TTL has passed, and for every queue
     * record stuck in `claimed` because of those expiries, return it to
     * `claimable` so another agent can pick it up.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recoverExpiredLeases(array $options = []): array
    {
        $queueRepo = $this->queueRepo();
        $leaseRepo = $this->leaseRepo();
        $actor = $this->actor($options);
        $reasonOverride = $this->reasonOverride($options);
        $taskPacketFilter = trim((string) ($options['packet'] ?? ''));
        $queueTags = $this->queueTags($options);

        $expireResult = $leaseRepo->expireLeases();
        $expiredLeaseIds = array_values((array) ($expireResult['expired_lease_ids'] ?? []));

        $recovered = [];
        $skipped = [];

        foreach ($expiredLeaseIds as $leaseId) {
            $leaseId = (string) $leaseId;
            if ($leaseId === '') {
                continue;
            }
            $lease = $leaseRepo->get($leaseId);
            $taskPacketId = (string) ($lease['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                $skipped[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => '',
                    'skip_reason' => 'lease_has_no_task_packet_id',
                ];

                continue;
            }
            $record = $queueRepo->get($taskPacketId);
            if (! $this->recordMatchesQueueTags($record, $queueTags)) {
                $skipped[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => $taskPacketId,
                    'skip_reason' => 'queue_tag_filter_mismatch',
                    'queue_tags' => $queueTags,
                    'record_queue_tags' => $this->recordQueueTags($record),
                ];

                continue;
            }
            if ($taskPacketFilter !== '' && $taskPacketId !== $taskPacketFilter) {
                $skipped[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => $taskPacketId,
                    'skip_reason' => 'packet_filter_mismatch',
                    'task_packet_filter' => $taskPacketFilter,
                ];

                continue;
            }

            $outcome = $this->returnTaskToClaimable(
                queue: $queueRepo,
                taskPacketId: $taskPacketId,
                leaseId: $leaseId,
                previousAgentId: (string) ($lease['agent_id'] ?? ''),
                recoveryReason: $reasonOverride !== '' ? $reasonOverride : self::REASON_LEASE_EXPIRED,
                actor: $actor,
            );

            if ((string) ($outcome['status'] ?? '') === 'recovered') {
                $recovered[] = $outcome;
            } else {
                $skipped[] = $outcome;
            }
        }

        return $this->envelope([
            'event' => 'recover_expired_leases',
            'expired_lease_count' => count($expiredLeaseIds),
            'recovered_count' => count($recovered),
            'skipped_count' => count($skipped),
            'recovered' => $recovered,
            'skipped' => $skipped,
            'expire_result' => $expireResult,
            'actor' => $actor,
            'queue_tags' => $queueTags,
        ]);
    }

    /**
     * Walk every queue record currently in `claimed` and, when its declared
     * lease has disappeared or is no longer active, return the task to
     * `claimable` with reason `lease_expired_orphaned`.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recoverOrphanedClaims(array $options = []): array
    {
        $queueRepo = $this->queueRepo();
        $leaseRepo = $this->leaseRepo();
        $actor = $this->actor($options);
        $reasonOverride = $this->reasonOverride($options);
        $taskPacketFilter = trim((string) ($options['packet'] ?? ''));
        $queueTags = $this->queueTags($options);

        $claimedRecords = $taskPacketFilter !== ''
            ? array_values(array_filter(
                [$queueRepo->get($taskPacketFilter)],
                static fn ($record): bool => is_array($record) && (string) ($record['status'] ?? '') === 'claimed',
            ))
            : $queueRepo->list(['status' => 'claimed']);
        $claimedRecords = array_values(array_filter(
            $claimedRecords,
            fn (array $record): bool => $this->recordMatchesQueueTags($record, $queueTags),
        ));
        $recovered = [];
        $skipped = [];

        foreach ($claimedRecords as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }
            $leaseId = (string) data_get($record, 'metadata.lease_id', '');
            $previousAgentId = (string) data_get($record, 'metadata.agent_id', '');

            if ($leaseId === '') {
                $outcome = $this->returnTaskToClaimable(
                    queue: $queueRepo,
                    taskPacketId: $taskPacketId,
                    leaseId: '',
                    previousAgentId: $previousAgentId,
                    recoveryReason: $reasonOverride !== '' ? $reasonOverride : self::REASON_LEASE_EXPIRED_ORPHANED,
                    actor: $actor,
                    leaseStatusAtRecovery: 'missing_lease_metadata',
                );

                if ((string) ($outcome['status'] ?? '') === 'recovered') {
                    $recovered[] = $outcome;
                } else {
                    $skipped[] = $outcome;
                }

                continue;
            }

            $lease = $leaseRepo->get($leaseId);
            $leaseStatus = $lease === null ? 'missing' : (string) ($lease['lease_status'] ?? 'unknown');

            if ($lease !== null && $leaseStatus === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
                // Still active — only recoverExpiredLeases is allowed to act on these.
                $skipped[] = [
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'skip_reason' => 'lease_still_active',
                    'lease_status' => $leaseStatus,
                ];

                continue;
            }

            $outcome = $this->returnTaskToClaimable(
                queue: $queueRepo,
                taskPacketId: $taskPacketId,
                leaseId: $leaseId,
                previousAgentId: $previousAgentId,
                recoveryReason: $reasonOverride !== '' ? $reasonOverride : self::REASON_LEASE_EXPIRED_ORPHANED,
                actor: $actor,
                leaseStatusAtRecovery: $leaseStatus,
            );

            if ((string) ($outcome['status'] ?? '') === 'recovered') {
                $recovered[] = $outcome;
            } else {
                $skipped[] = $outcome;
            }
        }

        return $this->envelope([
            'event' => 'recover_orphaned_claims',
            'claimed_record_count' => count($claimedRecords),
            'recovered_count' => count($recovered),
            'skipped_count' => count($skipped),
            'recovered' => $recovered,
            'skipped' => $skipped,
            'actor' => $actor,
            'queue_tags' => $queueTags,
        ]);
    }

    /**
     * Return an exact released packet, or a bounded released inventory, to
     * `claimable` when it was paused/released rather than blocked by a failed
     * worker packet. An oversized unscoped inventory stays untouched.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recoverReleasedTasks(array $options = []): array
    {
        $queueRepo = $this->queueRepo();
        $actor = $this->actor($options);
        $reasonOverride = $this->reasonOverride($options);
        $taskPacketId = trim((string) ($options['packet'] ?? ''));
        $queueTags = $this->queueTags($options);

        if ($taskPacketId === '') {
            $releasedRecordCount = (int) data_get($queueRepo->registry(['status' => 'released'], true), 'entry_count', 0);
            if ($releasedRecordCount > self::MAX_RELEASED_RECOVERY_RECORDS) {
                return $this->envelope([
                    'event' => 'recover_released_tasks',
                    'status' => 'blocked',
                    'reason' => 'queue_scan_limit_exceeded',
                    'released_record_count' => $releasedRecordCount,
                    'candidate_count' => self::MAX_RELEASED_RECOVERY_RECORDS,
                    'minimum_claimable_count' => self::MAX_RELEASED_RECOVERY_RECORDS + 1,
                    'scan_limit' => self::MAX_RELEASED_RECOVERY_RECORDS,
                    'recovered_count' => 0,
                    'skipped_count' => 0,
                    'released_skipped_count' => 0,
                    'recovered' => [],
                    'skipped' => [],
                    'released_skipped' => [],
                    'actor' => $actor,
                    'queue_tags' => $queueTags,
                ]);
            }
        }

        $releasedRecords = $taskPacketId !== ''
            ? array_values(array_filter([$queueRepo->get($taskPacketId)], static fn ($record): bool => is_array($record)))
            : $queueRepo->list(['status' => 'released']);
        $releasedRecords = array_values(array_filter(
            $releasedRecords,
            fn (array $record): bool => $this->recordMatchesQueueTags($record, $queueTags),
        ));
        $recovered = [];
        $skipped = [];
        $releasedSkipped = [];

        foreach ($releasedRecords as $record) {
            $id = (string) ($record['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $status = (string) ($record['status'] ?? '');
            if ($status !== 'released') {
                $skipped[] = [
                    'status' => 'skipped',
                    'task_packet_id' => $id,
                    'queue_status' => $status,
                    'skip_reason' => 'queue_record_not_released',
                ];

                continue;
            }

            $releaseReason = $this->releaseReason($record);
            if (! $this->releasedTaskCanBeRequeued($releaseReason)) {
                $queueRepo->appendReceipt($id, [
                    'receipt_kind' => self::RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED,
                    'release_reason' => $releaseReason,
                    'skip_reason' => 'released_task_requires_operator_investigation',
                    'recovered_by' => $actor,
                ]);
                $releasedSkipReason = [
                    'status' => 'skipped',
                    'task_packet_id' => $id,
                    'queue_status' => $status,
                    'release_reason' => $releaseReason,
                    'skip_reason' => 'released_task_requires_operator_investigation',
                    'receipt_kind' => self::RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED,
                ];
                $skipped[] = $releasedSkipReason;
                $releasedSkipped[] = $releasedSkipReason;

                continue;
            }

            $transition = $queueRepo->updateStatus($id, 'claimable', [
                'previous_status' => 'released',
                'release_reason' => $releaseReason,
                'recovery_reason' => $reasonOverride !== '' ? $reasonOverride : 'released_task_requeued_for_terminal_loop',
                'recovered_by' => $actor,
                'recovery_id' => 'recovery_'.(string) Str::ulid(),
            ]);
            if ((string) ($transition['status'] ?? '') !== 'ok') {
                $queueRepo->appendReceipt($id, [
                    'receipt_kind' => self::RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED,
                    'release_reason' => $releaseReason,
                    'skip_reason' => 'claimable_transition_failed',
                    'recovered_by' => $actor,
                ]);
                $releasedSkipReason = [
                    'status' => 'skipped',
                    'task_packet_id' => $id,
                    'queue_status' => $status,
                    'release_reason' => $releaseReason,
                    'skip_reason' => 'claimable_transition_failed',
                    'receipt_kind' => self::RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED,
                    'transition' => $transition,
                ];
                $skipped[] = $releasedSkipReason;
                $releasedSkipped[] = $releasedSkipReason;

                continue;
            }

            $queueRepo->appendReceipt($id, [
                'receipt_kind' => self::RECEIPT_RELEASED_TASK_REQUEUED,
                'release_reason' => $releaseReason,
                'recovery_reason' => $reasonOverride !== '' ? $reasonOverride : 'released_task_requeued_for_terminal_loop',
                'recovered_by' => $actor,
                'final_queue_status' => 'claimable',
            ]);
            $recovered[] = [
                'status' => 'recovered',
                'task_packet_id' => $id,
                'release_reason' => $releaseReason,
                'recovery_reason' => $reasonOverride !== '' ? $reasonOverride : 'released_task_requeued_for_terminal_loop',
                'recovered_by' => $actor,
                'final_queue_status' => 'claimable',
            ];
        }

        return $this->envelope([
            'event' => 'recover_released_tasks',
            'released_record_count' => count($releasedRecords),
            'recovered_count' => count($recovered),
            'skipped_count' => count($skipped),
            // Distinct from generic skipped totals: a released task that could NOT be requeued is
            // surfaced here with its released_task_requeue_skipped evidence, so recovery reporting
            // never claims a healthy state while a released record sits unrequeued.
            'released_skipped_count' => count($releasedSkipped),
            'released_skipped' => $releasedSkipped,
            'recovered' => $recovered,
            'skipped' => $skipped,
            'actor' => $actor,
            'queue_tags' => $queueTags,
        ]);
    }

    /**
     * Build the human-readable resume packet a new agent needs to pick up
     * an abandoned task. Read-only — never writes receipts and never
     * mutates the queue or the lease.
     *
     * @return array<string, mixed>
     */
    public function buildResumePacket(string $taskPacketId): array
    {
        $taskPacketId = trim($taskPacketId);
        if ($taskPacketId === '') {
            return $this->envelope([
                'event' => 'build_resume_packet_blocked',
                'reason' => 'task_packet_id_missing',
                'task_packet_id' => '',
                'resume_packet' => null,
            ]);
        }

        $queueRepo = $this->queueRepo();
        $leaseRepo = $this->leaseRepo();

        $record = $queueRepo->get($taskPacketId);
        if ($record === null) {
            return $this->envelope([
                'event' => 'build_resume_packet_blocked',
                'reason' => 'task_packet_not_found',
                'task_packet_id' => $taskPacketId,
                'resume_packet' => null,
            ]);
        }

        $status = (string) ($record['status'] ?? '');
        $history = (array) ($record['history'] ?? []);
        $lastHistoryEvent = $history === [] ? null : $history[array_key_last($history)];

        $latestLeaseId = $this->resolveLatestLeaseId($record);
        $previousAgentId = (string) data_get($record, 'metadata.agent_id', '');
        $lease = $latestLeaseId !== '' ? $leaseRepo->get($latestLeaseId) : null;
        if ($previousAgentId === '' && $lease !== null) {
            $previousAgentId = (string) ($lease['agent_id'] ?? '');
        }

        $continuationSummary = $this->extractContinuationSummary($record);
        $receipts = (array) ($record['receipts'] ?? []);

        if (in_array($status, ['completed_dry_run', 'cancelled'], true)) {
            return $this->envelope([
                'event' => 'build_resume_packet_blocked',
                'reason' => 'task_is_terminal',
                'task_packet_id' => $taskPacketId,
                'queue_status' => $status,
                'resume_packet' => [
                    'task_packet_id' => $taskPacketId,
                    'queue_status' => $status,
                    'previous_agent_id' => $previousAgentId,
                    'previous_lease_id' => $latestLeaseId,
                    'last_history_event' => $lastHistoryEvent,
                    'receipts' => $receipts,
                    'continuation_summary' => $continuationSummary,
                    'safe_next_action' => 'do_not_reopen_terminal_task',
                    'recommended_claim_command' => null,
                    'resume_contract' => $this->resumeContract(
                        taskPacketId: $taskPacketId,
                        queueStatus: $status,
                        previousLeaseId: $latestLeaseId,
                        previousAgentId: $previousAgentId,
                        safeNextAction: 'do_not_reopen_terminal_task',
                        recommendedClaimCommand: null,
                    ),
                ],
            ]);
        }

        $safeNextAction = match ($status) {
            'claimable' => 'reclaim_via_orchestrator',
            'claimed' => 'inspect_lease_then_recover_or_renew',
            'lease_expired' => 'transition_to_claimable_via_recovery',
            'released' => $this->releasedTaskCanBeRequeued($this->releaseReason($record)) ? 'requeue_released_task_via_recovery' : 'investigate_release_blocker_before_requeue',
            'blocked' => 'investigate_blocker_then_requeue',
            default => 'inspect_status_before_acting',
        };

        $recommendedClaimCommand = $status === 'claimable'
            ? 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-claim-next-status --actor=<agent-id> --json'
            : ($status === 'claimed'
                ? 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet='.$taskPacketId.' --actor=<actor> --reason=lease_expired_orphaned --json'
                : 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet='.$taskPacketId.' --actor=<actor> --json');

        return $this->envelope([
            'event' => 'build_resume_packet_ready',
            'task_packet_id' => $taskPacketId,
            'queue_status' => $status,
            'resume_packet' => [
                'task_packet_id' => $taskPacketId,
                'queue_status' => $status,
                'previous_agent_id' => $previousAgentId,
                'previous_lease_id' => $latestLeaseId,
                'last_history_event' => $lastHistoryEvent,
                'receipts' => $receipts,
                'continuation_summary' => $continuationSummary,
                'safe_next_action' => $safeNextAction,
                'recommended_claim_command' => $recommendedClaimCommand,
                'resume_contract' => $this->resumeContract(
                    taskPacketId: $taskPacketId,
                    queueStatus: $status,
                    previousLeaseId: $latestLeaseId,
                    previousAgentId: $previousAgentId,
                    safeNextAction: $safeNextAction,
                    recommendedClaimCommand: $recommendedClaimCommand,
                ),
            ],
        ]);
    }

    /**
     * Classify every queue record (or one specific task when --packet is
     * provided) so the operator can see, without writing, what would
     * happen if recovery ran now.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function inspectRecoverability(array $options = []): array
    {
        $queueRepo = $this->queueRepo();
        $leaseRepo = $this->leaseRepo();

        $taskPacketId = trim((string) ($options['packet'] ?? ''));
        $queueTags = $this->queueTags($options);
        $missingRecordIds = [];
        if ($taskPacketId !== '' && $queueRepo->get($taskPacketId) === null) {
            $missingRecordIds[] = $taskPacketId;
        }
        $records = $taskPacketId !== ''
            ? array_filter([$queueRepo->get($taskPacketId)], fn ($r): bool => $r !== null)
            : array_merge(
                $queueRepo->list(['status' => 'claimed']),
                $queueRepo->list(['status' => 'lease_expired']),
                $queueRepo->list(['status' => 'claimable']),
                $queueRepo->list(['status' => 'completed_dry_run']),
                $queueRepo->list(['status' => 'cancelled']),
                $queueRepo->list(['status' => 'released']),
                $queueRepo->list(['status' => 'blocked']),
            );
        $records = array_values(array_filter(
            $records,
            fn (array $record): bool => $this->recordMatchesQueueTags($record, $queueTags),
        ));

        $now = CarbonImmutable::now()->getTimestamp();
        $classifications = [];
        $examplesByClassification = [];
        $totals = [
            self::RECOVERABILITY_RECOVERABLE_EXPIRED => 0,
            self::RECOVERABILITY_RECOVERABLE_ORPHAN => 0,
            self::RECOVERABILITY_RECOVERABLE_RELEASED => 0,
            self::RECOVERABILITY_ACTIVE_LEASE => 0,
            self::RECOVERABILITY_TERMINAL => 0,
            self::RECOVERABILITY_CLAIMABLE => 0,
            self::RECOVERABILITY_BLOCKED_NON_RECOVERABLE => 0,
            self::RECOVERABILITY_OTHER => 0,
        ];

        foreach ($records as $record) {
            $id = (string) ($record['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $status = (string) ($record['status'] ?? '');
            $leaseId = (string) data_get($record, 'metadata.lease_id', '');
            $lease = $leaseId !== '' ? $leaseRepo->get($leaseId) : null;
            $leaseStatus = $lease === null ? 'missing' : (string) ($lease['lease_status'] ?? 'unknown');
            $expiresAt = $lease === null ? 0 : (int) ($lease['expires_at_unix'] ?? 0);
            $leaseExpired = $lease !== null
                && $leaseStatus === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE
                && $expiresAt <= $now;
            $releaseReason = $this->releaseReason($record);

            $classification = match (true) {
                in_array($status, ['completed_dry_run', 'cancelled'], true) => self::RECOVERABILITY_TERMINAL,
                $status === 'blocked' => self::RECOVERABILITY_BLOCKED_NON_RECOVERABLE,
                $status === 'claimable' => self::RECOVERABILITY_CLAIMABLE,
                $status === 'released' && $this->releasedTaskCanBeRequeued($releaseReason) => self::RECOVERABILITY_RECOVERABLE_RELEASED,
                $status === 'claimed' && $lease !== null && $leaseStatus === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE && ! $leaseExpired => self::RECOVERABILITY_ACTIVE_LEASE,
                $status === 'claimed' && $leaseExpired => self::RECOVERABILITY_RECOVERABLE_EXPIRED,
                $status === 'claimed' && ($lease === null || $leaseStatus !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) => self::RECOVERABILITY_RECOVERABLE_ORPHAN,
                default => self::RECOVERABILITY_OTHER,
            };
            $totals[$classification] = ($totals[$classification] ?? 0) + 1;
            $examplesByClassification[$classification] ??= [];
            if (count($examplesByClassification[$classification]) < self::ROOT_CAUSE_SUMMARY_EXAMPLE_LIMIT) {
                $examplesByClassification[$classification][] = $id;
            }

            $classifications[] = [
                'task_packet_id' => $id,
                'queue_status' => $status,
                'queue_tags' => array_values(array_map('strval', (array) ($record['tags'] ?? []))),
                'lease_id' => $leaseId,
                'lease_status' => $leaseStatus,
                'release_reason' => $releaseReason,
                'lease_expires_at_unix' => $expiresAt,
                'classification' => $classification,
                'recoverable' => in_array(
                    $classification,
                    [self::RECOVERABILITY_RECOVERABLE_EXPIRED, self::RECOVERABILITY_RECOVERABLE_ORPHAN, self::RECOVERABILITY_RECOVERABLE_RELEASED],
                    true,
                ),
            ];
        }

        return $this->envelope([
            'event' => 'inspect_recoverability',
            'task_packet_filter' => $taskPacketId,
            'queue_tags' => $queueTags,
            'inspected_count' => count($classifications),
            'recoverable_count' => $totals[self::RECOVERABILITY_RECOVERABLE_EXPIRED] + $totals[self::RECOVERABILITY_RECOVERABLE_ORPHAN] + $totals[self::RECOVERABILITY_RECOVERABLE_RELEASED],
            'totals_by_classification' => $totals,
            'classifications' => $classifications,
            'root_cause_summary' => $this->buildRootCauseSummary($totals, $examplesByClassification, $missingRecordIds),
        ]);
    }

    /**
     * Bucket recoverability totals into the operator-facing root-cause
     * categories so reaping loops can tell *why* a packet needs attention
     * instead of just whether it does.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, list<string>>  $examplesByClassification
     * @param  list<string>  $missingRecordIds
     * @return array<string, array{count: int, examples: list<string>}>
     */
    private function buildRootCauseSummary(array $totals, array $examplesByClassification, array $missingRecordIds): array
    {
        $staleClaimedCount = ($totals[self::RECOVERABILITY_RECOVERABLE_EXPIRED] ?? 0)
            + ($totals[self::RECOVERABILITY_RECOVERABLE_ORPHAN] ?? 0);
        $staleClaimedExamples = array_slice(array_merge(
            $examplesByClassification[self::RECOVERABILITY_RECOVERABLE_EXPIRED] ?? [],
            $examplesByClassification[self::RECOVERABILITY_RECOVERABLE_ORPHAN] ?? [],
        ), 0, self::ROOT_CAUSE_SUMMARY_EXAMPLE_LIMIT);

        return [
            'stale_claimed_leases' => [
                'count' => $staleClaimedCount,
                'examples' => $staleClaimedExamples,
            ],
            'released_returned_to_claimable' => [
                'count' => $totals[self::RECOVERABILITY_RECOVERABLE_RELEASED] ?? 0,
                'examples' => $examplesByClassification[self::RECOVERABILITY_RECOVERABLE_RELEASED] ?? [],
            ],
            'blocked_non_recoverable' => [
                'count' => $totals[self::RECOVERABILITY_BLOCKED_NON_RECOVERABLE] ?? 0,
                'examples' => $examplesByClassification[self::RECOVERABILITY_BLOCKED_NON_RECOVERABLE] ?? [],
            ],
            'terminal' => [
                'count' => $totals[self::RECOVERABILITY_TERMINAL] ?? 0,
                'examples' => $examplesByClassification[self::RECOVERABILITY_TERMINAL] ?? [],
            ],
            'missing_records' => [
                'count' => count($missingRecordIds),
                'examples' => array_slice($missingRecordIds, 0, self::ROOT_CAUSE_SUMMARY_EXAMPLE_LIMIT),
            ],
            'transition_failures' => [
                'count' => 0,
                'examples' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->leaseRepo()->isAvailable() && $this->queueRepo()->isAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    private function returnTaskToClaimable(
        AgentControlPlaneTaskPacketQueueRepository $queue,
        string $taskPacketId,
        string $leaseId,
        string $previousAgentId,
        string $recoveryReason,
        string $actor,
        string $leaseStatusAtRecovery = '',
    ): array {
        $record = $queue->get($taskPacketId);
        if ($record === null) {
            return [
                'status' => 'skipped',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'skip_reason' => 'task_packet_not_found',
            ];
        }

        $queueStatus = (string) ($record['status'] ?? '');
        if (in_array($queueStatus, ['completed_dry_run', 'cancelled'], true)) {
            $queue->appendReceipt($taskPacketId, [
                'receipt_kind' => self::RECEIPT_TASK_LEASE_RECOVERY_SKIPPED,
                'lease_id' => $leaseId,
                'recovery_reason' => $recoveryReason,
                'skip_reason' => 'task_is_terminal',
                'queue_status' => $queueStatus,
                'recovered_by' => $actor,
            ]);

            return [
                'status' => 'skipped',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => $queueStatus,
                'skip_reason' => 'task_is_terminal',
                'recovery_reason' => $recoveryReason,
            ];
        }

        if ($queueStatus !== 'claimed') {
            $queue->appendReceipt($taskPacketId, [
                'receipt_kind' => self::RECEIPT_TASK_LEASE_RECOVERY_SKIPPED,
                'lease_id' => $leaseId,
                'recovery_reason' => $recoveryReason,
                'skip_reason' => 'queue_record_not_claimed',
                'queue_status' => $queueStatus,
                'recovered_by' => $actor,
            ]);

            return [
                'status' => 'skipped',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => $queueStatus,
                'skip_reason' => 'queue_record_not_claimed',
                'recovery_reason' => $recoveryReason,
            ];
        }

        $expireTransition = $queue->updateStatus($taskPacketId, 'lease_expired', [
            'lease_id' => $leaseId,
            'agent_id' => $previousAgentId,
            'recovery_reason' => $recoveryReason,
            'recovered_by' => $actor,
            'lease_status_at_recovery' => $leaseStatusAtRecovery,
        ]);
        if ((string) ($expireTransition['status'] ?? '') !== 'ok') {
            return [
                'status' => 'skipped',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => $queueStatus,
                'skip_reason' => 'expire_transition_failed',
                'transition' => $expireTransition,
            ];
        }

        $claimableTransition = $queue->updateStatus($taskPacketId, 'claimable', [
            'lease_id' => $leaseId,
            'previous_agent_id' => $previousAgentId,
            'recovery_reason' => $recoveryReason,
            'recovered_by' => $actor,
            'recovery_id' => 'recovery_'.(string) Str::ulid(),
        ]);
        if ((string) ($claimableTransition['status'] ?? '') !== 'ok') {
            return [
                'status' => 'skipped',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => 'lease_expired',
                'skip_reason' => 'claimable_transition_failed',
                'transition' => $claimableTransition,
            ];
        }

        $queue->appendReceipt($taskPacketId, [
            'receipt_kind' => self::RECEIPT_TASK_LEASE_RECOVERY_EXECUTED,
            'lease_id' => $leaseId,
            'previous_agent_id' => $previousAgentId,
            'recovery_reason' => $recoveryReason,
            'lease_status_at_recovery' => $leaseStatusAtRecovery,
            'recovered_by' => $actor,
            'final_queue_status' => 'claimable',
        ]);

        return [
            'status' => 'recovered',
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'previous_agent_id' => $previousAgentId,
            'recovery_reason' => $recoveryReason,
            'recovered_by' => $actor,
            'final_queue_status' => 'claimable',
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveLatestLeaseId(array $record): string
    {
        $direct = (string) data_get($record, 'metadata.lease_id', '');
        if ($direct !== '') {
            return $direct;
        }
        $history = array_reverse((array) ($record['history'] ?? []));
        foreach ($history as $entry) {
            $leaseId = (string) data_get($entry, 'metadata.lease_id', '');
            if ($leaseId !== '') {
                return $leaseId;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function releaseReason(array $record): string
    {
        $reason = trim((string) data_get($record, 'metadata.release_reason', ''));
        if ($reason !== '') {
            return $reason;
        }

        $history = array_reverse((array) ($record['history'] ?? []));
        foreach ($history as $entry) {
            $reason = trim((string) data_get($entry, 'metadata.release_reason', ''));
            if ($reason !== '') {
                return $reason;
            }
        }

        return '';
    }

    private function releasedTaskCanBeRequeued(string $releaseReason): bool
    {
        return ! in_array($releaseReason, [
            'worker_packet_blocked_before_handoff',
            'scope_validation_blocked',
            'task_packet_or_validation_blocked',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function extractContinuationSummary(array $record): ?array
    {
        foreach ((array) ($record['receipts'] ?? []) as $receipt) {
            if ((string) ($receipt['receipt_kind'] ?? '') === 'continuation_summary_prepared') {
                return [
                    'continuation_hash' => (string) ($receipt['continuation_hash'] ?? ''),
                    'source_receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumeContract(
        string $taskPacketId,
        string $queueStatus,
        string $previousLeaseId,
        string $previousAgentId,
        string $safeNextAction,
        ?string $recommendedClaimCommand,
    ): array {
        $recoverCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet='.$taskPacketId.' --actor=<actor> --reason=resume_recovery --json';
        $claimCommand = $recommendedClaimCommand
            ?: 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-claim-next-status --actor=<agent-id> --json';

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_resume_contract.v1',
            'task_packet_id' => $taskPacketId,
            'queue_status' => $queueStatus,
            'previous_lease_id' => $previousLeaseId,
            'previous_agent_id' => $previousAgentId,
            'safe_next_action' => $safeNextAction,
            'can_resume_without_new_lease' => false,
            'requires_fresh_claim_before_work' => ! in_array($queueStatus, ['completed_dry_run', 'cancelled'], true),
            'requires_one_shot_packet_regeneration_after_claim' => ! in_array($queueStatus, ['completed_dry_run', 'cancelled'], true),
            'requires_structured_completion_evidence' => true,
            'pre_resume_checks' => [
                'inspect_recoverability_before_reclaim',
                'confirm_queue_status_is_not_terminal',
                'confirm_previous_lease_is_not_active_for_another_actor',
                'claim_new_lease_before_editing_files',
                'regenerate_one_shot_worker_packet_after_claim',
            ],
            'commands' => [
                'inspect_or_recover' => $recoverCommand,
                'claim_after_recovery' => $claimCommand,
                'one_shot_after_claim' => 'php artisan atlas:ai:self-construction --agent-control-plane-one-shot-worker-packet-status --packet='.$taskPacketId.' --lease-id=<fresh-lease-id> --json',
                'renew_fresh_lease' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-renew-lease-status --lease-id=<fresh-lease-id> --actor=<agent-id> --lease-minutes=30 --json',
                'complete_with_evidence' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-complete-dry-run-status --packet='.$taskPacketId.' --lease-id=<fresh-lease-id> --evidence-hash=<sha256-of-final-evidence> --completion-evidence-json=@/path/to/completion-evidence.json --json',
            ],
            'stop_conditions' => [
                'task_is_terminal',
                'lease_still_active_for_another_actor',
                'recoverability_classification_not_recoverable_or_claimable',
                'fresh_claim_not_acquired',
                'one_shot_worker_packet_not_ready',
                'completion_evidence_validation_not_valid',
            ],
            'forbidden_resume_shortcuts' => [
                'do_not_reuse_previous_lease_id_for_new_agent',
                'do_not_complete_without_active_fresh_lease',
                'do_not_skip_one_shot_packet_regeneration',
                'do_not_mark_real_completion',
                'do_not_dispatch_provider_or_adapter',
            ],
            'non_execution_guarantees' => [
                'resume_contract_does_not_start_codex',
                'resume_contract_does_not_call_codex_cli_or_app',
                'resume_contract_does_not_spawn_subprocess',
                'resume_contract_does_not_dispatch_work',
                'resume_contract_does_not_spend_tokens',
                'resume_contract_does_not_mark_real_completion',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function actor(array $options): string
    {
        $actor = trim((string) ($options['actor'] ?? ''));

        return $actor === '' ? 'unknown_actor' : $actor;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function reasonOverride(array $options): string
    {
        return trim((string) ($options['reason'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function queueTags(array $options): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            (array) ($options['queue_tags'] ?? []),
        ), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  list<string>  $queueTags
     */
    private function recordMatchesQueueTags(?array $record, array $queueTags): bool
    {
        if ($queueTags === []) {
            return true;
        }
        if ($record === null) {
            return false;
        }

        return array_intersect($queueTags, $this->recordQueueTags($record)) !== [];
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return list<string>
     */
    private function recordQueueTags(?array $record): array
    {
        if ($record === null) {
            return [];
        }

        return array_values(array_map('strval', (array) ($record['tags'] ?? [])));
    }

    private function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function leaseRepo(): AgentControlPlaneClaimLeaseRepository
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'recovery_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_lease_recovery_does_not_start_codex',
                'task_lease_recovery_does_not_call_codex_cli_or_app',
                'task_lease_recovery_does_not_spawn_subprocess',
                'task_lease_recovery_does_not_invoke_adapter',
                'task_lease_recovery_does_not_call_provider',
                'task_lease_recovery_does_not_dispatch_work',
                'task_lease_recovery_does_not_spend_tokens',
                'task_lease_recovery_does_not_enable_self_programming',
                'task_lease_recovery_does_not_write_ledger',
                'task_lease_recovery_does_not_reopen_terminal_tasks',
                'task_lease_recovery_does_not_mark_real_completion',
            ],
        ], $payload);
    }
}
