<?php

namespace App\Services\Ai\SelfConstruction;

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

    public const RECOVERABILITY_ACTIVE_LEASE = 'active_lease_skip';

    public const RECOVERABILITY_TERMINAL = 'terminal_skip';

    public const RECOVERABILITY_CLAIMABLE = 'claimable_ready';

    public const RECOVERABILITY_OTHER = 'other_state';

    public const RECEIPT_TASK_LEASE_RECOVERY_EXECUTED = 'task_lease_recovery_executed';

    public const RECEIPT_TASK_LEASE_RECOVERY_SKIPPED = 'task_lease_recovery_skipped';

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

        $claimedRecords = $queueRepo->list(['status' => 'claimed']);
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
                $skipped[] = [
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => '',
                    'skip_reason' => 'claimed_without_lease_metadata',
                ];
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
                ],
            ]);
        }

        $safeNextAction = match ($status) {
            'claimable' => 'reclaim_via_orchestrator',
            'claimed' => 'inspect_lease_then_recover_or_renew',
            'lease_expired' => 'transition_to_claimable_via_recovery',
            'released', 'blocked' => 'investigate_blocker_then_requeue',
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

        $now = CarbonImmutable::now()->getTimestamp();
        $classifications = [];
        $totals = [
            self::RECOVERABILITY_RECOVERABLE_EXPIRED => 0,
            self::RECOVERABILITY_RECOVERABLE_ORPHAN => 0,
            self::RECOVERABILITY_ACTIVE_LEASE => 0,
            self::RECOVERABILITY_TERMINAL => 0,
            self::RECOVERABILITY_CLAIMABLE => 0,
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
                && $expiresAt > 0 && $expiresAt <= $now;

            $classification = match (true) {
                in_array($status, ['completed_dry_run', 'cancelled'], true) => self::RECOVERABILITY_TERMINAL,
                $status === 'claimable' => self::RECOVERABILITY_CLAIMABLE,
                $status === 'claimed' && $lease !== null && $leaseStatus === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE && ! $leaseExpired => self::RECOVERABILITY_ACTIVE_LEASE,
                $status === 'claimed' && $leaseExpired => self::RECOVERABILITY_RECOVERABLE_EXPIRED,
                $status === 'claimed' && ($lease === null || $leaseStatus !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) => self::RECOVERABILITY_RECOVERABLE_ORPHAN,
                default => self::RECOVERABILITY_OTHER,
            };
            $totals[$classification] = ($totals[$classification] ?? 0) + 1;

            $classifications[] = [
                'task_packet_id' => $id,
                'queue_status' => $status,
                'lease_id' => $leaseId,
                'lease_status' => $leaseStatus,
                'lease_expires_at_unix' => $expiresAt,
                'classification' => $classification,
                'recoverable' => in_array(
                    $classification,
                    [self::RECOVERABILITY_RECOVERABLE_EXPIRED, self::RECOVERABILITY_RECOVERABLE_ORPHAN],
                    true,
                ),
            ];
        }

        return $this->envelope([
            'event' => 'inspect_recoverability',
            'task_packet_filter' => $taskPacketId,
            'inspected_count' => count($classifications),
            'recoverable_count' => $totals[self::RECOVERABILITY_RECOVERABLE_EXPIRED] + $totals[self::RECOVERABILITY_RECOVERABLE_ORPHAN],
            'totals_by_classification' => $totals,
            'classifications' => $classifications,
        ]);
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
