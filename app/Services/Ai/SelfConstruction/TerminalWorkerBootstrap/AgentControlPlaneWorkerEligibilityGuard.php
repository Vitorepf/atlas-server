<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;

/**
 * ITEM8 — the cohesive worker-eligibility validation concern the terminal-worker bootstrap service
 * uses to assert every claimable record satisfies the "one-terminal-runs-one-packet-at-a-time"
 * contract (worker-executable / not operator-only / no runtime-allowed flags / etc.).
 *
 * One method migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService}:
 *  - {@see self::workerEligibilityGuard}: read the current claimable records from the queue
 *    repository, scan each for the seven violation codes (`claimable_task_not_worker_executable`,
 *    `claimable_task_requires_operator_handoff`,
 *    `claimable_task_references_operator_only_completion_blocker`, and the seven
 *    `claimable_task_runtime_flag_true` variants for the runtime-allowed flags), and emit the
 *    guard envelope (status + eligible count + violations + stable hash + non-execution guarantees).
 *
 * The queue repository is constructor-injected so the guard reads the canonical claimable records
 * directly (no need to thread them through the bootstrap service). The `OPERATOR_ONLY_COMPLETION_CRITERIA`
 * constant mirrors the bootstrap service's constant verbatim so the byte-identical contract survives
 * the split. Pure / zero Laravel surface / stableHash is local to keep cross-class coupling minimal.
 */
class AgentControlPlaneWorkerEligibilityGuard
{
    /**
     * The references that, when present in `continuation_context.auto_replenishment_reference`,
     * make a claimable task ineligible for direct worker execution (the operator must sign the
     * completion offline).
     */
    private const OPERATOR_ONLY_COMPLETION_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    /**
     * The seven runtime-allowed flags on a task packet that, when true, disqualify it from direct
     * worker execution (the worker lane is read-only; any of these set on a claimable packet is
     * a forged-bytes / wrong-queue signal).
     */
    private const RUNTIME_FLAG_NAMES = [
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'self_programming_allowed',
        'ledger_write_allowed',
        'runtime_execution_allowed',
        'completion_real_allowed',
    ];

    public function __construct(
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
    ) {}

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    public function workerEligibilityGuard(array $queueTags): array
    {
        $records = $this->readClaimableRecords($queueTags);
        $violations = [];
        $ineligibleTaskIds = [];

        foreach ($records as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            $recordViolationCountBefore = count($violations);
            $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', '');
            if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
                $violations[] = ['code' => 'claimable_task_not_worker_executable', 'task_packet_id' => $taskPacketId];
            }
            if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
                $violations[] = ['code' => 'claimable_task_requires_operator_handoff', 'task_packet_id' => $taskPacketId];
            }
            if (in_array($reference, self::OPERATOR_ONLY_COMPLETION_CRITERIA, true)) {
                $violations[] = [
                    'code' => 'claimable_task_references_operator_only_completion_blocker',
                    'task_packet_id' => $taskPacketId,
                    'reference' => $reference,
                ];
            }
            foreach (self::RUNTIME_FLAG_NAMES as $flag) {
                if ((bool) data_get($record, $flag, false)) {
                    $violations[] = [
                        'code' => 'claimable_task_runtime_flag_true',
                        'task_packet_id' => $taskPacketId,
                        'flag' => $flag,
                    ];
                }
            }
            if (count($violations) > $recordViolationCountBefore && $taskPacketId !== '') {
                $ineligibleTaskIds[$taskPacketId] = true;
            }
        }
        $eligibleClaimableCount = count(array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($ineligibleTaskIds[(string) ($record['task_packet_id'] ?? '')]),
        )));
        $status = $eligibleClaimableCount > 0
            ? ($violations === [] ? 'available' : 'available_with_non_worker_candidates')
            : ($violations === [] ? 'available' : 'blocked');

        $guard = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap_worker_task_eligibility_guard.v1',
            'status' => $status,
            'queue_tags' => $queueTags,
            'checked_claimable_task_count' => count($records),
            'eligible_claimable_task_count' => $eligibleClaimableCount,
            'blocked_reasons' => array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['code'] ?? ''),
                $violations,
            ))),
            'violations' => $violations,
            'violation_count' => count($violations),
            'can_claim_after_guard' => $eligibleClaimableCount > 0,
            'non_execution_guarantees' => [
                'worker_task_eligibility_guard_does_not_claim_tasks',
                'worker_task_eligibility_guard_does_not_create_or_renew_leases',
                'worker_task_eligibility_guard_does_not_call_provider',
                'worker_task_eligibility_guard_does_not_spend_tokens',
                'worker_task_eligibility_guard_does_not_dispatch_work',
            ],
        ];
        $guard['worker_task_eligibility_guard_hash'] = $this->stableHash($guard);

        return $guard;
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    private function claimableRecords(array $queueTags, int $limit = 0): array
    {
        $filters = [
            'status' => 'claimable',
        ];
        if ($queueTags !== []) {
            $filters['tag'] = $queueTags[0];
            $filters['tags'] = $queueTags;
        }
        if ($limit > 0) {
            $filters['limit'] = $limit;
        }

        return $this->queue->list($filters);
    }

    /**
     * Read-only seam for tests: subclasses can override this hook to inject pre-canned claimable
     * records without touching the {@see AgentControlPlaneTaskPacketQueueRepository} instance. The
     * default delegates to the queue repo's `list()` exactly as the bootstrap service's original
     * `claimableRecords()` did, so production behaviour is byte-identical.
     *
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    protected function readClaimableRecords(array $queueTags, int $limit = 0): array
    {
        return $this->claimableRecords($queueTags, $limit);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
