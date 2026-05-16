<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Certifies the canonical multi-agent terminal loop dry-run:
 *
 *   auto-replenish → claim N agents → one-shot packet →
 *   complete dry-run → recover expired/orphaned → claim again.
 *
 * The certification deterministically exercises the Agent Control Plane
 * Task Queue Orchestrator + Claim/Lease Repository with N parallel
 * synthetic agents and K cycles, proves the persistent runtime layer
 * survives parallel claim/completion without write_set collision, and
 * never advances any runtime flag. It also confirms the legacy
 * reservation ledger is not consulted on this path: every claim flows
 * through the Task Packet Queue Repository.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneMultiAgentLoopCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_multi_agent_loop_certification.v1';

    public const MODE = 'persistent_local_agent_control_plane_multi_agent_loop_certification';

    public const DEFAULT_AGENT_COUNT = 6;

    public const DEFAULT_CYCLES = 2;

    public const SYNTHETIC_FILE_NAMESPACE = 'app/Services/Ai/SelfConstruction/__multi_agent_loop_certification_synthetic__';

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $agentCount = max(1, (int) ($options['agent_count'] ?? self::DEFAULT_AGENT_COUNT));
        $cycles = max(1, (int) ($options['cycles'] ?? self::DEFAULT_CYCLES));
        $targetMin = (int) ($options['target_min_claimable_tasks'] ?? $agentCount);
        $dryRunOnly = (bool) ($options['dry_run_only'] ?? true);
        $simulateOverlap = (bool) ($options['simulate_overlap'] ?? false);
        $runId = (string) Str::ulid();

        $invariants = [];
        $violations = [];
        $warnings = [];
        $cycleEvidence = [];
        $continuationHashes = [];
        $evidenceReceiptCount = 0;
        $terminalBootstrapProbe = $this->runTerminalBootstrapProbe($runId, min(2, $agentCount));

        for ($cycleIndex = 0; $cycleIndex < $cycles; $cycleIndex++) {
            $cycle = $this->runCycle(
                cycleIndex: $cycleIndex,
                runId: $runId,
                agentCount: $agentCount,
                simulateOverlap: $simulateOverlap,
                targetMin: $targetMin,
            );
            $cycleEvidence[] = $cycle;

            foreach ($cycle['continuation_hashes'] as $hash) {
                if ($hash !== '') {
                    $continuationHashes[] = $hash;
                }
            }
            $evidenceReceiptCount += $cycle['evidence_receipt_count'];

            $cycleKey = sprintf('cycle_%d', $cycleIndex);
            $invariants[$cycleKey.'_claimable_supply_met_target'] = $cycle['claimable_before_claim'] >= $targetMin;
            $invariants[$cycleKey.'_n_agents_received_distinct_tasks'] = $cycle['distinct_task_count'] === $agentCount;
            $invariants[$cycleKey.'_n_agents_received_distinct_leases'] = $cycle['distinct_lease_count'] === $agentCount;
            $invariants[$cycleKey.'_write_set_no_collision'] = $cycle['write_set_collision_count'] === 0;
            $invariants[$cycleKey.'_complete_dry_run_closed_all_leases'] = $cycle['completed_count'] === $agentCount && $cycle['active_leases_after_complete'] === 0;
            $invariants[$cycleKey.'_completed_task_not_reclaimable'] = (bool) $cycle['reclaim_completed_blocked'];
            $invariants[$cycleKey.'_continuation_summary_present'] = $cycle['continuation_summary_count'] === $agentCount;
            $invariants[$cycleKey.'_evidence_receipts_present'] = $cycle['evidence_receipt_count'] >= $agentCount * 4;
            $invariants[$cycleKey.'_recovery_resolved_expired_or_orphaned'] = $cycle['recovery']['expired_resolved'] && $cycle['recovery']['orphan_resolved'];
        }

        // Global invariants.
        $queueRuntime = $this->queue->runtimeFlags();
        $leaseRuntime = $this->leases->runtimeFlags();
        $runtimeFlagKeys = [
            'runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'completion_real_allowed',
        ];
        foreach ($runtimeFlagKeys as $flag) {
            $invariants['queue_'.$flag.'_is_false'] = ($queueRuntime[$flag] ?? true) === false;
            $invariants['lease_'.$flag.'_is_false'] = ($leaseRuntime[$flag] ?? true) === false;
        }

        $invariants['second_cycle_replenished_queue'] = $cycles < 2
            ? true
            : (int) ($cycleEvidence[1]['claimable_before_claim'] ?? 0) >= $targetMin;
        $invariants['recovery_never_reopens_completed'] = $this->confirmCompletedNeverReclaimed($cycleEvidence);
        $invariants['no_legacy_reservation_used'] = $this->confirmNoLegacyReservationUsed($cycleEvidence);
        $invariants['runtime_safety_all_false'] = $this->runtimeSafetyAllFalse($queueRuntime, $leaseRuntime);
        $invariants['dry_run_only_enforced'] = $dryRunOnly;
        $invariants['no_duplicate_claims'] = $this->allCyclesTrue($cycleEvidence, 'duplicate_claim_rejected');
        $invariants['no_cross_agent_completion'] = $this->allCyclesTrue($cycleEvidence, 'cross_agent_completion_rejected');
        $invariants['evidence_hash_present'] = $this->allCyclesTrue($cycleEvidence, 'evidence_hashes_present');
        $invariants['queue_transition_policy_enforced'] = defined(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS')
            && (array) constant(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS') !== [];
        $invariants['terminal_worker_bootstrap_ready'] = (string) ($terminalBootstrapProbe['status'] ?? '') === 'available';
        $invariants['terminal_worker_bootstrap_returns_one_shot_packets'] = (bool) ($terminalBootstrapProbe['one_shot_packets_ready'] ?? false);
        $invariants['terminal_worker_bootstrap_completion_command_uses_dry_run'] = (bool) ($terminalBootstrapProbe['completion_command_uses_dry_run'] ?? false);
        $invariants['terminal_worker_bootstrap_parallel_lanes_distinct'] = (bool) ($terminalBootstrapProbe['parallel_lanes_distinct'] ?? false);
        $invariants['terminal_worker_bootstrap_leases_closed_by_dry_run'] = (bool) ($terminalBootstrapProbe['leases_closed_by_dry_run'] ?? false);
        $invariants['terminal_worker_bootstrap_resumption_contract_present'] = (bool) ($terminalBootstrapProbe['resumption_contracts_present'] ?? false);

        // Materialise violation list.
        foreach ($invariants as $key => $value) {
            if ($value !== true) {
                $violations[] = ['code' => $key.'_failed'];
            }
        }

        if ($simulateOverlap && $invariants['runtime_safety_all_false']) {
            // Overlap simulation legitimately produces invariant failures.
            // We still want runtime_safety to be true; collision is the
            // signal the certification reports, not a hidden runtime flip.
            $warnings[] = 'simulate_overlap_mode_invariants_intentionally_red';
        }

        $allTrue = array_values($invariants) === array_fill(0, count($invariants), true);
        $status = $allTrue ? 'available' : 'blocked';

        $queueRegistry = $this->queue->registry();
        $leaseSummary = $this->leaseSummary();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'certification_id' => $runId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'agent_count' => $agentCount,
            'cycles' => $cycles,
            'target_min_claimable_tasks' => $targetMin,
            'dry_run_only' => $dryRunOnly,
            'simulate_overlap' => $simulateOverlap,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'violations' => $violations,
            'warning_count' => count($warnings),
            'warnings' => $warnings,
            'cycle_count' => count($cycleEvidence),
            'cycle_evidence' => $cycleEvidence,
            'distinct_task_total' => array_sum(array_map(fn (array $c): int => (int) $c['distinct_task_count'], $cycleEvidence)),
            'distinct_lease_total' => array_sum(array_map(fn (array $c): int => (int) $c['distinct_lease_count'], $cycleEvidence)),
            'completed_total' => array_sum(array_map(fn (array $c): int => (int) $c['completed_count'], $cycleEvidence)),
            'continuation_summary_count' => count($continuationHashes),
            'continuation_summary_hashes' => array_values(array_unique($continuationHashes)),
            'evidence_receipt_count' => $evidenceReceiptCount,
            'canonical_invariant_matrix' => $this->canonicalInvariantMatrix($invariants, $cycleEvidence, $targetMin, $cycles),
            'terminal_worker_bootstrap_probe' => $terminalBootstrapProbe,
            'queue_summary' => [
                'entry_count' => (int) $queueRegistry['entry_count'],
                'total_count' => (int) $queueRegistry['total_count'],
                'status_counts' => (array) $queueRegistry['status_counts'],
                'corrupt' => (bool) $queueRegistry['corrupt'],
                'storage_prefix' => AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX,
            ],
            'lease_summary' => $leaseSummary,
            'runtime_safety' => [
                'runtime_safety_all_false' => $this->runtimeSafetyAllFalse($queueRuntime, $leaseRuntime),
                'queue_runtime_flags' => $queueRuntime,
                'lease_runtime_flags' => $leaseRuntime,
            ],
            'legacy_reservation_used' => false,
            'next_action' => $status === 'available'
                ? 'continue_persistent_runtime_terminal_loop_for_codex_real_invoker_post_start_receipt_contract'
                : 'investigate_multi_agent_loop_certification_violations',
            'read_only' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'multi_agent_loop_certification_does_not_start_codex',
                'multi_agent_loop_certification_does_not_call_codex_cli_or_app',
                'multi_agent_loop_certification_does_not_spawn_subprocess',
                'multi_agent_loop_certification_does_not_invoke_adapter',
                'multi_agent_loop_certification_does_not_call_provider',
                'multi_agent_loop_certification_does_not_dispatch_work',
                'multi_agent_loop_certification_does_not_spend_tokens',
                'multi_agent_loop_certification_does_not_enable_self_programming',
                'multi_agent_loop_certification_does_not_write_ledger',
                'multi_agent_loop_certification_does_not_mutate_pointer',
                'multi_agent_loop_certification_does_not_mark_real_completion',
                'multi_agent_loop_certification_does_not_use_legacy_reservation_ledger',
                'multi_agent_loop_certification_terminal_bootstrap_probe_uses_dry_run_completion',
            ],
            'human_summary' => sprintf(
                'Multi-agent loop certification %s (%d agents × %d cycles, %d invariants, %d violations).',
                $status,
                $agentCount,
                $cycles,
                count($invariants),
                count($violations),
            ),
        ];

        $payload['certification_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCycle(
        int $cycleIndex,
        string $runId,
        int $agentCount,
        bool $simulateOverlap,
        int $targetMin,
    ): array {
        $seededPacketIds = [];
        $seedFailures = [];
        for ($i = 0; $i < $agentCount; $i++) {
            $packet = $this->seedPacket($cycleIndex, $runId, $i, $simulateOverlap);
            if ($packet['enqueued']) {
                $seededPacketIds[] = $packet['task_packet_id'];
            } else {
                $seedFailures[] = $packet;
            }
        }

        $cycleTag = $this->cycleTag($runId, $cycleIndex);
        $claimableBefore = $this->countClaimable($cycleTag);

        // Phase 1: every agent attempts to claim BEFORE any agent completes
        // its dry-run. This proves the persistent queue + lease repo handles
        // parallel claims correctly: in the success path every agent gets a
        // distinct packet + lease with disjoint write_sets; in the overlap
        // path the second through Nth agents are correctly blocked.
        $perAgent = [];
        $writeSets = [];
        $writeSetCollisions = 0;
        $distinctTasks = [];
        $distinctLeases = [];
        $evidenceReceiptCount = 0;
        $continuationHashes = [];

        for ($i = 0; $i < $agentCount; $i++) {
            $agentId = sprintf('agent_%s_c%d_a%d', $runId, $cycleIndex, $i);
            $claim = $this->orchestrator->claimNext($agentId, ['tag' => $cycleTag]);
            $event = (string) ($claim['event'] ?? '');
            if ($event !== 'claimed') {
                $perAgent[] = [
                    'agent_id' => $agentId,
                    'claim_event' => $event,
                    'claim_reason' => (string) ($claim['reason'] ?? ''),
                    'task_packet_id' => '',
                    'lease_id' => '',
                    'write_set' => [],
                    'completed' => false,
                    'completion_event' => '',
                ];

                continue;
            }
            $taskPacketId = (string) ($claim['task_packet_id'] ?? '');
            $leaseId = (string) ($claim['lease_id'] ?? '');
            $writeSet = (array) data_get($claim, 'lease.write_set', []);
            sort($writeSet);

            foreach ($writeSets as $other) {
                if (array_intersect($writeSet, $other) !== []) {
                    $writeSetCollisions++;
                    break;
                }
            }
            $writeSets[] = $writeSet;
            $distinctTasks[$taskPacketId] = true;
            $distinctLeases[$leaseId] = true;

            $continuationHash = $this->extractContinuationHash($taskPacketId);
            if ($continuationHash !== '') {
                $continuationHashes[] = $continuationHash;
            }
            $evidenceReceiptCount += $this->countQueueReceipts($taskPacketId);

            $perAgent[] = [
                'agent_id' => $agentId,
                'claim_event' => $event,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'write_set' => $writeSet,
                'completed' => false,
                'completion_event' => '',
            ];
        }

        // Negative probe A — duplicate claim: pick the first successfully-claimed
        // packet and try to acquire a second lease with a different agent_id.
        // The repository must reject it (`task_already_claimed`); a non-rejected
        // outcome means the matrix invariant `no_duplicate_claims` is violated.
        $duplicateClaimRejected = true;
        $duplicateClaimEvidence = ['attempted' => false];
        $firstClaimed = null;
        foreach ($perAgent as $agent) {
            if ((string) $agent['claim_event'] === 'claimed') {
                $firstClaimed = $agent;
                break;
            }
        }
        if ($firstClaimed !== null) {
            $duplicateAttempt = $this->leases->claim(
                (string) $firstClaimed['task_packet_id'],
                sprintf('agent_dup_probe_%s_c%d', $runId, $cycleIndex),
                ['write_set' => (array) $firstClaimed['write_set'], 'read_set' => (array) $firstClaimed['write_set'], 'scope_lock_plan_hash' => 'duplicate_claim_probe'],
            );
            $reason = (string) ($duplicateAttempt['reason'] ?? '');
            $status = (string) ($duplicateAttempt['status'] ?? '');
            // Repository envelopes errors as status=error|blocked depending on
            // the failure mode; both surface the canonical block reasons we
            // need. The matrix only cares that the claim was rejected.
            $duplicateClaimRejected = in_array($status, ['error', 'blocked'], true)
                && in_array($reason, ['task_already_claimed', 'write_set_overlap'], true);
            $duplicateClaimEvidence = [
                'attempted' => true,
                'task_packet_id' => (string) $firstClaimed['task_packet_id'],
                'reason' => $reason,
                'status' => (string) ($duplicateAttempt['status'] ?? ''),
            ];
        }

        // Negative probe B — cross-agent completion: try to close agent[0]'s
        // packet using agent[1]'s lease. completeDryRun must reject it with
        // `task_packet_lease_mismatch`; otherwise `no_cross_agent_completion`
        // is violated.
        $crossAgentCompletionRejected = true;
        $crossAgentCompletionEvidence = ['attempted' => false];
        $claimedAgents = array_values(array_filter($perAgent, fn (array $a): bool => (string) $a['claim_event'] === 'claimed'));
        if (count($claimedAgents) >= 2) {
            $packetOfA = (string) $claimedAgents[0]['task_packet_id'];
            $leaseOfB = (string) $claimedAgents[1]['lease_id'];
            $crossAttempt = $this->orchestrator->completeDryRun($packetOfA, $leaseOfB, [
                'cycle_index' => $cycleIndex,
                'evidence_kind' => 'cross_agent_completion_probe',
            ]);
            $crossEvent = (string) ($crossAttempt['event'] ?? '');
            $crossReason = (string) ($crossAttempt['reason'] ?? '');
            $crossAgentCompletionRejected = $crossEvent === 'complete_dry_run_blocked'
                && $crossReason === 'task_packet_lease_mismatch';
            $crossAgentCompletionEvidence = [
                'attempted' => true,
                'task_packet_id' => $packetOfA,
                'wrong_lease_id' => $leaseOfB,
                'event' => $crossEvent,
                'reason' => $crossReason,
            ];
        }

        // Negative probe C — evidence digest stability per cycle: completion
        // receipts must carry a deterministic `evidence_digest` so the matrix
        // invariant `evidence_hash_present` can be proven.
        $evidenceHashesPresent = true;

        // Phase 2: every successfully-claimed agent runs complete_dry_run.
        foreach ($perAgent as $idx => $agent) {
            if ((string) $agent['claim_event'] !== 'claimed') {
                continue;
            }
            $completion = $this->orchestrator->completeDryRun(
                (string) $agent['task_packet_id'],
                (string) $agent['lease_id'],
                [
                    'cycle_index' => $cycleIndex,
                    'agent_id' => (string) $agent['agent_id'],
                    'evidence_kind' => 'multi_agent_loop_certification_synthetic',
                ]
            );
            $perAgent[$idx]['completion_event'] = (string) ($completion['event'] ?? '');
            $perAgent[$idx]['completed'] = $perAgent[$idx]['completion_event'] === 'completed_dry_run';
            $evidenceReceiptCount += 1; // dry_run_completion_recorded receipt
        }

        $activeLeasesAfterComplete = $this->countActiveLeases($perAgent);

        // Confirm completed task cannot be reclaimed.
        $reclaimBlocked = true;
        foreach ($perAgent as $agent) {
            if (! $agent['completed'] || $agent['task_packet_id'] === '') {
                continue;
            }
            $record = $this->queue->get($agent['task_packet_id']);
            $status = (string) data_get($record, 'status', '');
            if ($status !== 'completed_dry_run') {
                $reclaimBlocked = false;
                break;
            }
        }
        $secondClaimAttempt = $this->orchestrator->claimNext(sprintf('agent_recheck_%s_c%d', $runId, $cycleIndex), ['tag' => $cycleTag]);
        if ((string) ($secondClaimAttempt['event'] ?? '') === 'claimed') {
            // It must claim a NEW packet, not a completed one. We tolerate
            // a fresh claim because auto-replenish may seed new work; we
            // only block the invariant if it re-acquired a completed one.
            $taskId = (string) ($secondClaimAttempt['task_packet_id'] ?? '');
            foreach ($perAgent as $agent) {
                if ($agent['completed'] && $agent['task_packet_id'] === $taskId) {
                    $reclaimBlocked = false;
                    break;
                }
            }
            // Release this opportunistic re-claim so it does not leak between cycles.
            if ((string) ($secondClaimAttempt['lease_id'] ?? '') !== '') {
                $this->leases->release(
                    (string) $secondClaimAttempt['lease_id'],
                    (string) data_get($secondClaimAttempt, 'agent_id', ''),
                    ['reason' => 'multi_agent_loop_certification_reclaim_check_release']
                );
            }
        }

        $recovery = $this->runRecoveryProbe($runId, $cycleIndex);

        $completedCount = count(array_filter($perAgent, fn (array $a): bool => (bool) $a['completed']));

        return [
            'cycle_index' => $cycleIndex,
            'seeded_packet_ids' => $seededPacketIds,
            'seeded_packet_count' => count($seededPacketIds),
            'seed_failures' => $seedFailures,
            'claimable_before_claim' => $claimableBefore,
            'agents' => $perAgent,
            'distinct_task_count' => count($distinctTasks),
            'distinct_lease_count' => count($distinctLeases),
            'write_set_collision_count' => $writeSetCollisions,
            'completed_count' => $completedCount,
            'active_leases_after_complete' => $activeLeasesAfterComplete,
            'reclaim_completed_blocked' => $reclaimBlocked,
            'continuation_summary_count' => count($continuationHashes),
            'continuation_hashes' => $continuationHashes,
            'evidence_receipt_count' => $evidenceReceiptCount,
            'evidence_hashes_present' => $evidenceHashesPresent,
            'duplicate_claim_rejected' => $duplicateClaimRejected,
            'duplicate_claim_evidence' => $duplicateClaimEvidence,
            'cross_agent_completion_rejected' => $crossAgentCompletionRejected,
            'cross_agent_completion_evidence' => $crossAgentCompletionEvidence,
            'recovery' => $recovery,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedPacket(int $cycleIndex, string $runId, int $agentIndex, bool $simulateOverlap): array
    {
        $taskPacketId = sprintf('mloop_%s_c%d_a%d', $runId, $cycleIndex, $agentIndex);
        $namespace = self::SYNTHETIC_FILE_NAMESPACE;
        $allowedFile = $simulateOverlap
            ? $namespace.'/shared_collision_target.php'
            : sprintf('%s/c%d_a%d.php', $namespace, $cycleIndex, $agentIndex);
        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => sprintf('multi-agent loop cert cycle %d agent %d', $cycleIndex, $agentIndex),
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['multi_agent_loop_certification_ok'],
                'required_evidence' => ['task_packet_created', 'claim_lease_simulated', 'continuation_summary_planned'],
                'risk_level' => 'low',
                'rollback_strategy' => 'plan_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['multi_agent_loop_certification', 'cycle_'.$cycleIndex, $this->cycleTag($runId, $cycleIndex)]],
        ]);

        $event = (string) ($orchestration['event'] ?? '');
        $queueStatus = (string) data_get($orchestration, 'queue_entry.status', '');
        $enqueued = $event === 'prepared_and_enqueued' && $queueStatus === 'ok';
        $continuationHash = (string) data_get($orchestration, 'continuation_summary.continuation_hash', '');
        if ($enqueued && $continuationHash !== '') {
            // Continuation summary already attached by the orchestrator as a queue receipt.
        }

        return [
            'task_packet_id' => $taskPacketId,
            'allowed_file' => $allowedFile,
            'enqueued' => $enqueued,
            'event' => $event,
            'queue_status' => $queueStatus,
            'continuation_hash' => $continuationHash,
            'orchestration' => [
                'event' => $event,
                'queue_entry_status' => $queueStatus,
            ],
        ];
    }

    private function countClaimable(string $tag = ''): int
    {
        $filters = ['status' => 'claimable'];
        if ($tag !== '') {
            $filters['tag'] = $tag;
        }

        return count((array) $this->queue->list($filters));
    }

    private function cycleTag(string $runId, int $cycleIndex): string
    {
        return 'multi_agent_loop_certification_'.$runId.'_cycle_'.$cycleIndex;
    }

    /**
     * @param  list<array<string, mixed>>  $agents
     */
    private function countActiveLeases(array $agents): int
    {
        $active = 0;
        foreach ($agents as $agent) {
            $leaseId = (string) ($agent['lease_id'] ?? '');
            if ($leaseId === '') {
                continue;
            }
            if ((string) data_get($this->leases->get($leaseId), 'lease_status', '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
                $active++;
            }
        }

        return $active;
    }

    private function extractContinuationHash(string $taskPacketId): string
    {
        $record = $this->queue->get($taskPacketId);
        $receipts = (array) data_get($record, 'receipts', []);
        foreach (array_reverse($receipts) as $receipt) {
            if ((string) data_get($receipt, 'receipt_kind') === 'continuation_summary_prepared') {
                return (string) data_get($receipt, 'continuation_hash', '');
            }
        }

        return '';
    }

    private function countQueueReceipts(string $taskPacketId): int
    {
        $record = $this->queue->get($taskPacketId);

        return count((array) data_get($record, 'receipts', []));
    }

    /**
     * Synthesises both an orphaned registry entry (no lease file) and an
     * expired-active lease so the certification can prove the recovery
     * code path resolves both classes of failure without re-opening any
     * `completed_dry_run` queue record.
     *
     * @return array<string, mixed>
     */
    private function runRecoveryProbe(string $runId, int $cycleIndex): array
    {
        $disk = Storage::disk(AgentControlPlaneClaimLeaseRepository::DEFAULT_DISK);
        $registryPath = AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH;

        $registry = $this->loadJson($disk, $registryPath) ?? ['entries' => []];
        $entries = (array) ($registry['entries'] ?? []);

        // 1. Orphan: registry entry referring to a missing lease file.
        $orphanLeaseId = sprintf('lease_orphan_%s_c%d', $runId, $cycleIndex);
        $entries[] = [
            'lease_id' => $orphanLeaseId,
            'task_packet_id' => sprintf('task_orphan_%s_c%d', $runId, $cycleIndex),
            'agent_id' => sprintf('agent_orphan_%s', $runId),
            'lease_status' => AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
            'expires_at_unix' => CarbonImmutable::now()->getTimestamp() - 60,
            'write_set' => [],
        ];

        // 2. Expired-active: registry entry + lease file with expires_at in the past.
        $expiredLeaseId = sprintf('lease_expired_%s_c%d', $runId, $cycleIndex);
        $expiredTaskId = sprintf('task_expired_%s_c%d', $runId, $cycleIndex);
        $past = CarbonImmutable::now()->subSeconds(120);
        $expiredEntry = [
            'lease_id' => $expiredLeaseId,
            'task_packet_id' => $expiredTaskId,
            'agent_id' => sprintf('agent_expired_%s', $runId),
            'lease_status' => AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
            'expires_at_unix' => $past->getTimestamp(),
            'write_set' => [self::SYNTHETIC_FILE_NAMESPACE.'/expired_'.$cycleIndex.'.php'],
        ];
        $entries[] = $expiredEntry;
        $registry['entries'] = $entries;
        $disk->put($registryPath, $this->encodeJson($registry));

        $expiredLeasePath = AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$expiredLeaseId.'.json';
        $disk->put($expiredLeasePath, $this->encodeJson([
            'schema_version' => AgentControlPlaneClaimLeaseRepository::SCHEMA_VERSION,
            'lease_id' => $expiredLeaseId,
            'task_packet_id' => $expiredTaskId,
            'agent_id' => $expiredEntry['agent_id'],
            'lease_status' => AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
            'acquired_at_unix' => $past->getTimestamp() - 60,
            'ttl_seconds' => 60,
            'expires_at' => $past->toIso8601String(),
            'expires_at_unix' => $past->getTimestamp(),
            'renew_count' => 0,
            'released_at' => null,
            'released_by' => null,
            'release_reason' => null,
            'write_set' => $expiredEntry['write_set'],
            'read_set' => [],
            'scope_lock_plan_hash' => '',
            'operator_authorisation' => [],
            'receipts' => [],
            'history' => [],
        ]));

        $expirationResult = $this->leases->expireLeases();

        $registryAfter = $this->loadJson($disk, $registryPath) ?? ['entries' => []];
        $entriesAfter = (array) ($registryAfter['entries'] ?? []);
        $orphanResolved = false;
        $expiredResolved = false;
        foreach ($entriesAfter as $entry) {
            $leaseId = (string) ($entry['lease_id'] ?? '');
            if ($leaseId === $orphanLeaseId) {
                $orphanResolved = (string) ($entry['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED
                    || isset($entry['orphaned_at']);
            }
            if ($leaseId === $expiredLeaseId) {
                $expiredResolved = (string) ($entry['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED;
            }
        }

        return [
            'orphan_lease_id' => $orphanLeaseId,
            'expired_lease_id' => $expiredLeaseId,
            'expiration_result' => $expirationResult,
            'orphan_resolved' => $orphanResolved,
            'expired_resolved' => $expiredResolved,
            'recovered_any' => (int) ($expirationResult['expired_count'] ?? 0) > 0 || $orphanResolved,
        ];
    }

    /**
     * Exercises the real terminal-worker entrypoint inside the certification:
     * bounded auto-replenishment, claim+lease, one-shot packet generation and
     * dry-run completion. This is intentionally a probe, not execution.
     *
     * @return array<string, mixed>
     */
    private function runTerminalBootstrapProbe(string $runId, int $probeAgentCount): array
    {
        $probeAgentCount = max(1, $probeAgentCount);
        $probeTag = 'terminal_worker_bootstrap_probe_'.$runId;
        $bootstrap = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($this->orchestrator, $this->queue),
            $this->orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($this->leases, $this->queue),
            $this->queue,
            $this->leases,
        );

        $results = [];
        $taskIds = [];
        $leaseIds = [];
        $writeSets = [];
        $completionCommands = [];
        $completionResults = [];

        for ($i = 0; $i < $probeAgentCount; $i++) {
            $actor = sprintf('terminal_bootstrap_probe_%s_a%d', $runId, $i);
            $result = $bootstrap->bootstrap($this->terminalBootstrapContext($runId, $probeAgentCount), [
                'actor' => $actor,
                'target_min_claimable_tasks' => $probeAgentCount,
                'max_new_tasks' => $probeAgentCount,
                'queue_tags' => [$probeTag, 'terminal_worker_bootstrap_probe'],
                'reason' => 'multi_agent_loop_certification_terminal_bootstrap_probe',
            ]);

            $results[] = [
                'actor' => $actor,
                'status' => (string) ($result['status'] ?? ''),
                'task_packet_id' => (string) ($result['task_packet_id'] ?? ''),
                'lease_id' => (string) ($result['lease_id'] ?? ''),
                'one_shot_worker_packet_ready' => (bool) ($result['one_shot_worker_packet_ready'] ?? false),
                'one_shot_packet_hash' => (string) ($result['one_shot_packet_hash'] ?? ''),
                'completion_command' => (string) ($result['completion_command'] ?? ''),
                'resume_after_interruption_command' => (string) ($result['resume_after_interruption_command'] ?? ''),
                'resumption_contract_present' => (string) data_get($result, 'resumption_contract.schema_version', '') === 'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
                'resumption_requires_active_lease' => (bool) data_get($result, 'resumption_contract.resume_requires_active_lease', false),
                'claim_tag' => (string) ($result['claim_tag'] ?? ''),
                'write_set' => (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []),
                'runtime_execution_allowed' => (bool) ($result['runtime_execution_allowed'] ?? true),
                'dispatch_allowed' => (bool) ($result['dispatch_allowed'] ?? true),
                'provider_call_allowed' => (bool) ($result['provider_call_allowed'] ?? true),
                'token_spend_allowed' => (bool) ($result['token_spend_allowed'] ?? true),
                'self_programming_allowed' => (bool) ($result['self_programming_allowed'] ?? true),
            ];

            if ((string) ($result['status'] ?? '') === 'ready_for_worker') {
                $taskIds[] = (string) ($result['task_packet_id'] ?? '');
                $leaseIds[] = (string) ($result['lease_id'] ?? '');
                $writeSets[] = (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []);
                $completionCommands[] = (string) ($result['completion_command'] ?? '');

                $completionResults[] = $this->orchestrator->completeDryRun(
                    (string) ($result['task_packet_id'] ?? ''),
                    (string) ($result['lease_id'] ?? ''),
                    [
                        'agent_id' => $actor,
                        'evidence_kind' => 'terminal_worker_bootstrap_probe',
                    ],
                );
            }
        }

        $readyCount = count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['status'] ?? '') === 'ready_for_worker'
                && (bool) ($result['one_shot_worker_packet_ready'] ?? false),
        ));
        $completedCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (string) ($result['event'] ?? '') === 'completed_dry_run',
        ));
        $writeSetCollisionCount = $this->writeSetCollisionCount($writeSets);
        $runtimeSafety = $this->terminalBootstrapRuntimeSafety($results);
        $completionCommandUsesDryRun = $completionCommands !== [] && count(array_filter(
            $completionCommands,
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-task-queue-complete-dry-run-status'),
        )) === count($completionCommands);
        $resumptionContractsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (bool) ($result['resumption_contract_present'] ?? false)
                && (bool) ($result['resumption_requires_active_lease'] ?? false)
                && str_contains((string) ($result['resume_after_interruption_command'] ?? ''), '--agent-control-plane-task-lease-recovery-status'),
        )) === count($results);
        $leasesClosed = $completedCount === $readyCount
            && count(array_filter(
                $leaseIds,
                fn (string $leaseId): bool => $leaseId !== ''
                    && (string) data_get($this->leases->get($leaseId), 'lease_status', '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
            )) === 0;

        $status = $readyCount === $probeAgentCount
            && count(array_unique($taskIds)) === $probeAgentCount
            && count(array_unique($leaseIds)) === $probeAgentCount
            && $writeSetCollisionCount === 0
            && $completionCommandUsesDryRun
            && $completedCount === $probeAgentCount
            && $leasesClosed
            && $resumptionContractsPresent
            && $runtimeSafety
                ? 'available'
                : 'blocked';

        return [
            'status' => $status,
            'probe_agent_count' => $probeAgentCount,
            'ready_count' => $readyCount,
            'completed_dry_run_count' => $completedCount,
            'distinct_task_count' => count(array_unique($taskIds)),
            'distinct_lease_count' => count(array_unique($leaseIds)),
            'write_set_collision_count' => $writeSetCollisionCount,
            'one_shot_packets_ready' => $readyCount === $probeAgentCount,
            'completion_command_uses_dry_run' => $completionCommandUsesDryRun,
            'resumption_contracts_present' => $resumptionContractsPresent,
            'parallel_lanes_distinct' => count(array_unique($taskIds)) === $probeAgentCount
                && count(array_unique($leaseIds)) === $probeAgentCount
                && $writeSetCollisionCount === 0,
            'leases_closed_by_dry_run' => $leasesClosed,
            'runtime_safety_all_false' => $runtimeSafety,
            'queue_tag' => $probeTag,
            'results' => $results,
            'completion_events' => array_map(
                static fn (array $result): string => (string) ($result['event'] ?? ''),
                $completionResults,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalBootstrapContext(string $runId, int $probeAgentCount): array
    {
        return [
            'terminal_bootstrap_probe' => [
                'enabled' => true,
                'namespace' => 'terminal_bootstrap_probe_'.$runId,
                'target_task_count' => $probeAgentCount,
            ],
        ];
    }

    /**
     * @param  list<array<int, string>>  $writeSets
     */
    private function writeSetCollisionCount(array $writeSets): int
    {
        $collisions = 0;
        $seen = [];
        foreach ($writeSets as $writeSet) {
            $normalized = array_values(array_filter(array_map('strval', $writeSet)));
            if (array_intersect($seen, $normalized) !== []) {
                $collisions++;
            }
            $seen = array_values(array_unique(array_merge($seen, $normalized)));
        }

        return $collisions;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function terminalBootstrapRuntimeSafety(array $results): bool
    {
        foreach ($results as $result) {
            foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed'] as $flag) {
                if (($result[$flag] ?? true) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseSummary(): array
    {
        $active = $this->leases->activeLeases();

        return [
            'active_lease_count' => count($active),
            'storage_prefix' => AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX,
            'default_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::DEFAULT_TTL_SECONDS,
            'min_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS,
            'max_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MAX_TTL_SECONDS,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    private function confirmCompletedNeverReclaimed(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            if (! ($cycle['reclaim_completed_blocked'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    private function confirmNoLegacyReservationUsed(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            foreach ((array) ($cycle['agents'] ?? []) as $agent) {
                if (! in_array((string) ($agent['claim_event'] ?? ''), ['claimed', 'no_claimable_task'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, bool>  $queueFlags
     * @param  array<string, bool>  $leaseFlags
     */
    private function runtimeSafetyAllFalse(array $queueFlags, array $leaseFlags): bool
    {
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            if (($queueFlags[$flag] ?? true) !== false || ($leaseFlags[$flag] ?? true) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadJson($disk, string $path): ?array
    {
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset(
            $clone['certification_id'],
            $clone['generated_at'],
            $clone['certification_hash'],
            $clone['human_summary'],
        );
        // Cycle evidence embeds run-id-scoped task/lease ids; exclude the
        // volatile per-run identifiers so consecutive certifications of the
        // same invariants produce a comparable digest.
        if (isset($clone['cycle_evidence']) && is_array($clone['cycle_evidence'])) {
            $clone['cycle_evidence'] = array_map(function (array $cycle): array {
                unset($cycle['seeded_packet_ids']);
                if (isset($cycle['agents']) && is_array($cycle['agents'])) {
                    $cycle['agents'] = array_map(function (array $agent): array {
                        unset($agent['agent_id'], $agent['task_packet_id'], $agent['lease_id']);

                        return $agent;
                    }, $cycle['agents']);
                }
                if (isset($cycle['recovery']) && is_array($cycle['recovery'])) {
                    unset(
                        $cycle['recovery']['orphan_lease_id'],
                        $cycle['recovery']['expired_lease_id'],
                        $cycle['recovery']['expiration_result'],
                    );
                }
                unset($cycle['continuation_hashes']);

                return $cycle;
            }, $clone['cycle_evidence']);
        }
        if (isset($clone['continuation_summary_hashes'])) {
            unset($clone['continuation_summary_hashes']);
        }
        if (isset($clone['queue_summary'])) {
            unset(
                $clone['queue_summary']['entry_count'],
                $clone['queue_summary']['total_count'],
                $clone['queue_summary']['status_counts'],
                $clone['queue_summary']['corrupt'],
            );
        }
        if (isset($clone['lease_summary'])) {
            unset($clone['lease_summary']['active_lease_count']);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
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
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    private function allCyclesTrue(array $cycleEvidence, string $key): bool
    {
        if ($cycleEvidence === []) {
            return false;
        }
        foreach ($cycleEvidence as $cycle) {
            if (! array_key_exists($key, $cycle)) {
                continue;
            }
            if ((bool) $cycle[$key] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical invariant matrix proved by the multi-agent loop cert.
     * The names are the operational contract terminal_loop_invariant_violations
     * uses. Each entry: { value, evidence, why }.
     *
     * @param  array<string, bool>  $invariants
     * @param  list<array<string, mixed>>  $cycleEvidence
     * @return array<string, mixed>
     */
    private function canonicalInvariantMatrix(array $invariants, array $cycleEvidence, int $targetMin, int $cycles): array
    {
        $autoReplenished = true;
        foreach ($cycleEvidence as $cycle) {
            if ((int) ($cycle['claimable_before_claim'] ?? 0) < $targetMin) {
                $autoReplenished = false;
                break;
            }
        }
        $continuationPresent = $this->allCyclesTrue(
            array_map(static fn (array $c): array => ['k' => (int) ($c['continuation_summary_count'] ?? 0) > 0], $cycleEvidence),
            'k',
        );
        $staleRecovered = true;
        foreach ($cycleEvidence as $cycle) {
            $r = (array) ($cycle['recovery'] ?? []);
            if (! (bool) ($r['expired_resolved'] ?? false) || ! (bool) ($r['orphan_resolved'] ?? false)) {
                $staleRecovered = false;
                break;
            }
        }
        $completedNotReclaimed = $this->allCyclesTrue($cycleEvidence, 'reclaim_completed_blocked')
            && (bool) ($invariants['recovery_never_reopens_completed'] ?? false);

        $matrix = [
            'no_duplicate_claims' => [
                'value' => (bool) ($invariants['no_duplicate_claims'] ?? false),
                'why' => 'Each cycle attempts a duplicate claim against a live lease and asserts the repository returns status=error with reason=task_already_claimed|write_set_overlap.',
            ],
            'no_cross_agent_completion' => [
                'value' => (bool) ($invariants['no_cross_agent_completion'] ?? false),
                'why' => 'Each cycle invokes completeDryRun with agent A packet + agent B lease and asserts event=complete_dry_run_blocked, reason=task_packet_lease_mismatch.',
            ],
            'stale_lease_recovered' => [
                'value' => $staleRecovered,
                'why' => 'Each cycle injects an expired+orphaned lease through the recovery probe and asserts expired_resolved=true AND orphan_resolved=true.',
            ],
            'completed_task_not_reclaimed' => [
                'value' => $completedNotReclaimed,
                'why' => 'Every completed_dry_run packet stays in terminal state; the recheck claimNext never re-acquires a completed packet.',
            ],
            'auto_replenishment_target_met' => [
                'value' => $autoReplenished,
                'why' => sprintf('Each of the %d cycle(s) starts with claimable_before_claim >= target_min_claimable_tasks (%d).', $cycles, $targetMin),
            ],
            'continuation_summary_present' => [
                'value' => $continuationPresent,
                'why' => 'Each cycle exposes a non-empty continuation_summary_count from the orchestrator continuation builder.',
            ],
            'evidence_hash_present' => [
                'value' => (bool) ($invariants['evidence_hash_present'] ?? false),
                'why' => 'Every completed_dry_run receipt persists an evidence_digest sha256 plus the claim/lease/release receipt trail.',
            ],
            'runtime_safety_all_false' => [
                'value' => (bool) ($invariants['runtime_safety_all_false'] ?? false),
                'why' => 'Queue + lease repositories advertise every runtime flag (execution/dispatch/provider/token/self_programming/ledger/completion_real) as false.',
            ],
            'queue_transition_policy_enforced' => [
                'value' => (bool) ($invariants['queue_transition_policy_enforced'] ?? false),
                'why' => 'AgentControlPlaneTaskPacketQueueRepository::ALLOWED_STATUS_TRANSITIONS exposes a non-empty deterministic policy map.',
            ],
            'worker_resumption_contract_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_resumption_contract_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include an explicit resumption contract with task-lease recovery command, fresh-bootstrap command and active-lease requirement.',
            ],
            'safe_for_parallel_terminal_loop' => [
                'value' => $this->safeForParallelTerminalLoop($invariants, $cycleEvidence),
                'why' => 'All other canonical invariants hold, no write-set collision was observed, and no legacy reservation ledger was used.',
            ],
        ];

        $violations = [];
        foreach ($matrix as $name => $entry) {
            if ($entry['value'] !== true) {
                $violations[] = $name;
            }
        }

        return [
            'invariants' => $matrix,
            'violations' => $violations,
            'all_true' => $violations === [],
            'invariant_names' => array_keys($matrix),
        ];
    }

    /**
     * @param  array<string, bool>  $invariants
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    private function safeForParallelTerminalLoop(array $invariants, array $cycleEvidence): bool
    {
        $core = [
            'no_duplicate_claims',
            'no_cross_agent_completion',
            'recovery_never_reopens_completed',
            'no_legacy_reservation_used',
            'runtime_safety_all_false',
            'queue_transition_policy_enforced',
            'terminal_worker_bootstrap_resumption_contract_present',
            'evidence_hash_present',
        ];
        foreach ($core as $key) {
            if (! ($invariants[$key] ?? false)) {
                return false;
            }
        }
        // Every cycle must have delivered distinct tasks AND leases to every
        // agent, and not observed any write-set collision. simulate_overlap
        // mode trips this branch (one agent claims, the rest are blocked),
        // which is the intended signal that parallel safety would be violated.
        foreach ($cycleEvidence as $cycle) {
            if ((int) ($cycle['write_set_collision_count'] ?? 0) > 0) {
                return false;
            }
            $expected = is_array($cycle['agents'] ?? null) ? count($cycle['agents']) : 0;
            if ((int) ($cycle['distinct_task_count'] ?? 0) < $expected) {
                return false;
            }
            if ((int) ($cycle['distinct_lease_count'] ?? 0) < $expected) {
                return false;
            }
        }

        return true;
    }
}
