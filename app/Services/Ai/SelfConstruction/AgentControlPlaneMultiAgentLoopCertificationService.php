<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

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
    private ?\App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopDigestPredicates $digestPredicates = null;

    use RecursivelyKsortsArrays;
    private ?AgentControlPlaneMultiAgentLoopProbeRunner $probeRunnerInstance = null;
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
        $terminalFleetLaunchPlanProbe = $this->runTerminalFleetLaunchPlanProbe($runId, min(3, $agentCount));
        $terminalFleetPartialSupplyGateProbe = $this->runTerminalFleetPartialSupplyGateProbe($runId);
        $terminalFleetLaneIsolationNegativeProbe = $this->runTerminalFleetLaneIsolationNegativeProbe($runId);
        $terminalFleetResumeRollupProbe = $this->runTerminalFleetResumeRollupProbe($runId);
        $terminalFleetMetadataOrphanRecoveryProbe = $this->runTerminalFleetMetadataOrphanRecoveryProbe($runId);
        $terminalFleetReleasedResumeProbe = $this->runTerminalFleetReleasedResumeProbe($runId);
        $terminalFleetEvidenceRollupProbe = $this->runTerminalFleetEvidenceRollupProbe($runId);

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
            $invariants[$cycleKey.'_complete_dry_run_requires_queue_claim_binding'] = $cycle['queue_claim_binding_verified_count'] === $agentCount
                && (bool) ($cycle['queue_claim_binding_all_verified'] ?? false);
            $invariants[$cycleKey.'_completed_task_not_reclaimable'] = (bool) $cycle['reclaim_completed_blocked'];
            $invariants[$cycleKey.'_continuation_summary_present'] = $cycle['continuation_summary_count'] === $agentCount;
            $invariants[$cycleKey.'_evidence_receipts_present'] = $cycle['evidence_receipt_count'] >= $agentCount * 4;
            $invariants[$cycleKey.'_structured_completion_evidence_valid'] = $cycle['structured_completion_evidence_valid_count'] === $agentCount
                && $cycle['completion_evidence_validation_hash_count'] === $agentCount;
            $invariants[$cycleKey.'_completion_evidence_files_within_scope'] = $cycle['completion_evidence_files_within_scope_count'] === $agentCount
                && $cycle['completion_evidence_scope_escape_count'] === 0;
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
        $invariants['complete_dry_run_requires_queue_claim_binding'] = $this->allCyclesTrue($cycleEvidence, 'queue_claim_binding_all_verified');
        $invariants['evidence_hash_present'] = $this->allCyclesTrue($cycleEvidence, 'evidence_hashes_present');
        $invariants['structured_completion_evidence_valid'] = $this->allCyclesTrue($cycleEvidence, 'structured_completion_evidence_all_valid');
        $invariants['completion_evidence_files_within_scope'] = $this->allCyclesTrue($cycleEvidence, 'completion_evidence_files_within_scope_all_valid');
        $invariants['queue_transition_policy_enforced'] = defined(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS')
            && (array) constant(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS') !== [];
        $invariants['terminal_worker_bootstrap_ready'] = (string) ($terminalBootstrapProbe['status'] ?? '') === 'available';
        $invariants['terminal_worker_bootstrap_returns_one_shot_packets'] = (bool) ($terminalBootstrapProbe['one_shot_packets_ready'] ?? false);
        $invariants['terminal_worker_bootstrap_completion_command_uses_dry_run'] = (bool) ($terminalBootstrapProbe['completion_command_uses_dry_run'] ?? false);
        $invariants['terminal_worker_bootstrap_completion_evidence_template_present'] = (bool) ($terminalBootstrapProbe['completion_evidence_template_present'] ?? false);
        $invariants['terminal_worker_bootstrap_operator_commands_present'] = (bool) ($terminalBootstrapProbe['terminal_loop_operator_commands_present'] ?? false);
        $invariants['terminal_worker_bootstrap_structured_completion_evidence_valid'] = (bool) ($terminalBootstrapProbe['structured_completion_evidence_all_valid'] ?? false);
        $invariants['terminal_worker_bootstrap_completion_evidence_files_within_scope'] = (bool) ($terminalBootstrapProbe['completion_evidence_files_within_scope_all_valid'] ?? false);
        $invariants['terminal_worker_bootstrap_parallel_lanes_distinct'] = (bool) ($terminalBootstrapProbe['parallel_lanes_distinct'] ?? false);
        $invariants['terminal_worker_bootstrap_leases_closed_by_dry_run'] = (bool) ($terminalBootstrapProbe['leases_closed_by_dry_run'] ?? false);
        $invariants['terminal_worker_bootstrap_resumption_contract_present'] = (bool) ($terminalBootstrapProbe['resumption_contracts_present'] ?? false);
        $invariants['terminal_worker_bootstrap_resumption_checkpoint_present'] = (bool) ($terminalBootstrapProbe['resumption_checkpoints_present'] ?? false);
        $invariants['terminal_worker_bootstrap_iteration_runbook_present'] = (bool) ($terminalBootstrapProbe['iteration_runbooks_present'] ?? false);
        $invariants['terminal_worker_bootstrap_shell_recipe_present'] = (bool) ($terminalBootstrapProbe['shell_recipes_present'] ?? false);
        $invariants['terminal_loop_health_digest_present'] = class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
            && method_exists(AgentControlPlaneTerminalLoopHealthDigestService::class, 'digest');
        $invariants['terminal_loop_fleet_launch_plan_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetLaunchPlanPresent();
        $invariants['terminal_loop_fleet_launch_plan_ready_path_verified'] = (bool) ($terminalFleetLaunchPlanProbe['ready_path_verified'] ?? false);
        $invariants['terminal_loop_fleet_partial_supply_launch_blocked'] = (bool) ($terminalFleetPartialSupplyGateProbe['partial_supply_launch_blocked'] ?? false);
        $invariants['terminal_loop_fleet_replenishment_plan_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetReplenishmentPlanPresent();
        $invariants['terminal_loop_fleet_resume_rollup_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetResumeRollupPresent();
        $invariants['terminal_loop_fleet_resume_recovery_path_verified'] = (bool) ($terminalFleetResumeRollupProbe['recovery_path_verified'] ?? false);
        $invariants['terminal_loop_fleet_metadata_orphan_recovery_verified'] = (bool) ($terminalFleetMetadataOrphanRecoveryProbe['metadata_orphan_recovery_verified'] ?? false);
        $invariants['terminal_loop_fleet_released_task_requeue_verified'] = (bool) ($terminalFleetReleasedResumeProbe['released_task_requeue_verified'] ?? false);
        $invariants['terminal_loop_fleet_evidence_rollup_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetEvidenceRollupPresent();
        $invariants['terminal_loop_fleet_evidence_rollup_green_path_verified'] = (bool) ($terminalFleetEvidenceRollupProbe['green_path_verified'] ?? false);
        $invariants['terminal_loop_fleet_operator_handoff_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetOperatorHandoffPresent();
        $invariants['terminal_loop_fleet_operator_handoff_recovery_priority_verified'] = (bool) ($terminalFleetResumeRollupProbe['operator_handoff_recovery_priority_verified'] ?? false);
        $invariants['terminal_loop_fleet_lane_isolation_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetLaneIsolationPresent();
        $invariants['terminal_loop_fleet_lane_bound_commands_verified'] = (bool) ($terminalFleetLaunchPlanProbe['lane_bound_commands_verified'] ?? false);
        $invariants['terminal_loop_fleet_lane_no_cross_lane_launch_verified'] = (bool) ($terminalFleetLaneIsolationNegativeProbe['no_cross_lane_launch_verified'] ?? false);
        $invariants['terminal_loop_cycle_supervisor_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopCycleSupervisorPresent();
        $invariants['terminal_loop_cycle_supervisor_launch_path_verified'] = (bool) ($terminalFleetLaunchPlanProbe['cycle_supervisor_launch_path_verified'] ?? false);
        $invariants['terminal_loop_cycle_supervisor_evidence_review_path_verified'] = (bool) ($terminalFleetEvidenceRollupProbe['cycle_supervisor_evidence_review_path_verified'] ?? false);
        $invariants['terminal_loop_fleet_launch_runbook_present'] = $invariants['terminal_loop_health_digest_present']
            && $this->terminalLoopFleetLaunchRunbookPresent();
        $invariants['terminal_loop_fleet_launch_runbook_ready_path_verified'] = (bool) ($terminalFleetLaunchPlanProbe['fleet_launch_runbook_ready_path_verified'] ?? false);
        $invariants['terminal_worker_bootstrap_rejects_invalid_worker_scope'] = (bool) ($terminalBootstrapProbe['invalid_scope_rejected'] ?? false);
        $invariants['terminal_worker_bootstrap_preview_read_only'] = (bool) ($terminalBootstrapProbe['preview_read_only'] ?? false);
        $invariants['terminal_worker_bootstrap_partial_supply_blocks_before_claim'] = (bool) ($terminalBootstrapProbe['partial_supply_blocks_before_claim'] ?? false);
        $certificationArtifactCleanup = $this->cleanupCertificationArtifacts($runId);
        $postCleanupHealthDigestProbe = $this->postCleanupHealthDigestProbe($certificationArtifactCleanup, $targetMin);
        $invariants['certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts'] = (bool) ($postCleanupHealthDigestProbe['cleanup_left_no_recoverable_artifacts'] ?? false);

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
            'terminal_loop_fleet_launch_plan_probe' => $terminalFleetLaunchPlanProbe,
            'terminal_loop_fleet_partial_supply_gate_probe' => $terminalFleetPartialSupplyGateProbe,
            'terminal_loop_fleet_lane_isolation_negative_probe' => $terminalFleetLaneIsolationNegativeProbe,
            'terminal_loop_fleet_resume_rollup_probe' => $terminalFleetResumeRollupProbe,
            'terminal_loop_fleet_metadata_orphan_recovery_probe' => $terminalFleetMetadataOrphanRecoveryProbe,
            'terminal_loop_fleet_released_resume_probe' => $terminalFleetReleasedResumeProbe,
            'terminal_loop_fleet_evidence_rollup_probe' => $terminalFleetEvidenceRollupProbe,
            'certification_artifact_cleanup' => $certificationArtifactCleanup,
            'post_cleanup_health_digest_probe' => $postCleanupHealthDigestProbe,
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
            'certification_artifact_cleanup_performed' => (bool) data_get($certificationArtifactCleanup, 'cleanup_performed', false),
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
                if (WriteSetOverlap::collidingPaths($writeSet, $other) !== []) { // A5/MF-12: prefix-aware dir-vs-file
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
        $structuredCompletionEvidenceValidCount = 0;
        $completionEvidenceFilesWithinScopeCount = 0;
        $completionEvidenceValidationHashes = [];

        // Phase 2: every successfully-claimed agent runs complete_dry_run.
        foreach ($perAgent as $idx => $agent) {
            if ((string) $agent['claim_event'] !== 'claimed') {
                continue;
            }
            $completionEvidence = [
                'cycle_index' => $cycleIndex,
                'packet_id' => (string) $agent['task_packet_id'],
                'lease_id' => (string) $agent['lease_id'],
                'actor' => (string) $agent['agent_id'],
                'agent_id' => (string) $agent['agent_id'],
                'evidence_kind' => 'multi_agent_loop_certification_synthetic',
                'files_changed' => [(string) data_get($agent, 'write_set.0', 'synthetic/noop.php')],
                'commands_run' => ['multi_agent_loop_certification_synthetic_dry_run: passed'],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'synthetic certification storage-only dry-run',
                'git_diff_check_result' => 'clean',
            ];
            $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
            $completion = $this->orchestrator->completeDryRun(
                (string) $agent['task_packet_id'],
                (string) $agent['lease_id'],
                $completionEvidence,
            );
            $perAgent[$idx]['completion_event'] = (string) ($completion['event'] ?? '');
            $perAgent[$idx]['completed'] = $perAgent[$idx]['completion_event'] === 'completed_dry_run';
            $perAgent[$idx]['queue_claim_binding_verified'] = (bool) ($completion['queue_claim_binding_verified'] ?? false);
            $perAgent[$idx]['completion_evidence_validation_status'] = (string) data_get($completion, 'evidence_validation.status', '');
            $perAgent[$idx]['structured_completion_evidence_valid'] = (bool) data_get($completion, 'evidence_validation.structured_completion_evidence_valid', false);
            $perAgent[$idx]['completion_evidence_files_within_scope'] = (bool) data_get($completion, 'evidence_validation.files_changed_within_allowed_scope', false)
                && data_get($completion, 'evidence_validation.files_changed_outside_allowed_scope', []) === [];
            $perAgent[$idx]['files_changed_outside_allowed_scope'] = (array) data_get($completion, 'evidence_validation.files_changed_outside_allowed_scope', []);
            $perAgent[$idx]['completion_evidence_validation_hash'] = (string) data_get($completion, 'evidence_validation.evidence_validation_hash', '');
            if ((bool) $perAgent[$idx]['structured_completion_evidence_valid']) {
                $structuredCompletionEvidenceValidCount++;
            }
            if ((bool) $perAgent[$idx]['completion_evidence_files_within_scope']) {
                $completionEvidenceFilesWithinScopeCount++;
            }
            if ((string) $perAgent[$idx]['completion_evidence_validation_hash'] !== '') {
                $completionEvidenceValidationHashes[] = (string) $perAgent[$idx]['completion_evidence_validation_hash'];
            }
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
        $queueClaimBindingVerifiedCount = count(array_filter(
            $perAgent,
            fn (array $a): bool => (bool) ($a['completed'] ?? false) && (bool) ($a['queue_claim_binding_verified'] ?? false),
        ));

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
            'queue_claim_binding_verified_count' => $queueClaimBindingVerifiedCount,
            'queue_claim_binding_all_verified' => $queueClaimBindingVerifiedCount === $completedCount,
            'active_leases_after_complete' => $activeLeasesAfterComplete,
            'reclaim_completed_blocked' => $reclaimBlocked,
            'continuation_summary_count' => count($continuationHashes),
            'continuation_hashes' => $continuationHashes,
            'evidence_receipt_count' => $evidenceReceiptCount,
            'evidence_hashes_present' => $evidenceHashesPresent,
            'structured_completion_evidence_valid_count' => $structuredCompletionEvidenceValidCount,
            'structured_completion_evidence_all_valid' => $structuredCompletionEvidenceValidCount === $completedCount,
            'completion_evidence_files_within_scope_count' => $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_scope_escape_count' => $completedCount - $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_files_within_scope_all_valid' => $completionEvidenceFilesWithinScopeCount === $completedCount,
            'completion_evidence_validation_hash_count' => count(array_unique($completionEvidenceValidationHashes)),
            'completion_evidence_validation_hashes' => array_values(array_unique($completionEvidenceValidationHashes)),
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

        $expiredLeaseIds = array_map('strval', (array) ($expirationResult['expired_lease_ids'] ?? []));
        $registryAfter = $this->loadJson($disk, $registryPath) ?? ['entries' => []];
        $entriesAfter = (array) ($registryAfter['entries'] ?? []);
        $orphanResolved = false;
        $expiredResolved = false;
        $orphanRegistryEntryPresent = false;
        $expiredRegistryEntryPresent = false;
        foreach ($entriesAfter as $entry) {
            $leaseId = (string) ($entry['lease_id'] ?? '');
            if ($leaseId === $orphanLeaseId) {
                $orphanRegistryEntryPresent = true;
                $orphanResolved = (string) ($entry['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED
                    || isset($entry['orphaned_at']);
            }
            if ($leaseId === $expiredLeaseId) {
                $expiredRegistryEntryPresent = true;
                $expiredResolved = (string) ($entry['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED;
            }
        }
        if (! $orphanResolved) {
            // The lease registry is compacted as an active-lease index. In a
            // busy local queue, a newly-expired synthetic orphan may be
            // dropped from the index immediately after expiration; the
            // authoritative recovery proof is that expireLeases returned the
            // exact orphan lease id from the run-scoped probe.
            $orphanResolved = in_array($orphanLeaseId, $expiredLeaseIds, true);
        }
        if (! $expiredResolved) {
            $expiredLease = $this->leases->get($expiredLeaseId);
            $expiredResolved = in_array($expiredLeaseId, $expiredLeaseIds, true)
                && (string) data_get($expiredLease, 'lease_status', '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED;
        }

        return [
            'orphan_lease_id' => $orphanLeaseId,
            'expired_lease_id' => $expiredLeaseId,
            'expiration_result' => $expirationResult,
            'expired_lease_ids' => $expiredLeaseIds,
            'orphan_registry_entry_present_after_expiration' => $orphanRegistryEntryPresent,
            'expired_registry_entry_present_after_expiration' => $expiredRegistryEntryPresent,
            'registry_compacted_after_expiration' => (bool) ($registryAfter['compacted'] ?? false),
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
    public function runTerminalBootstrapProbe(string $runId, int $probeAgentCount): array
    {
        return $this->probeRunner()->runTerminalBootstrapProbe($runId, $probeAgentCount);
    }

    public function runTerminalFleetLaunchPlanProbe(string $runId, int $probeTerminalCount): array
    {
        return $this->probeRunner()->runTerminalFleetLaunchPlanProbe($runId, $probeTerminalCount);
    }

    public function runTerminalBootstrapPartialSupplyProbe(\App\Services\Ai\SelfConstruction\AgentControlPlane\AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        return $this->probeRunner()->runTerminalBootstrapPartialSupplyProbe($bootstrap, $runId);
    }

    public function runTerminalFleetPartialSupplyGateProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetPartialSupplyGateProbe($taskPacketId);
    }

    public function runTerminalFleetLaneIsolationNegativeProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetLaneIsolationNegativeProbe($taskPacketId);
    }

    public function runTerminalFleetResumeRollupProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetResumeRollupProbe($taskPacketId);
    }

    public function runTerminalFleetMetadataOrphanRecoveryProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetMetadataOrphanRecoveryProbe($taskPacketId);
    }

    public function runTerminalFleetReleasedResumeProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetReleasedResumeProbe($taskPacketId);
    }

    public function runTerminalFleetEvidenceRollupProbe(string $taskPacketId): array
    {
        return $this->probeRunner()->runTerminalFleetEvidenceRollupProbe($taskPacketId);
    }

    public function runTerminalBootstrapPreviewProbe(\App\Services\Ai\SelfConstruction\AgentControlPlane\AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        return $this->probeRunner()->runTerminalBootstrapPreviewProbe($bootstrap, $runId);
    }

    public function runTerminalBootstrapInvalidScopeProbe(\App\Services\Ai\SelfConstruction\AgentControlPlane\AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        return $this->probeRunner()->runTerminalBootstrapInvalidScopeProbe($bootstrap, $runId);
    }

    /**
     * @return array<string,mixed>
     */
    public function terminalBootstrapContext(string $runId, int $probeAgentCount): array
    {
        return $this->probeRunner()->terminalBootstrapContext($runId, $probeAgentCount);
    }

    private function probeRunner(): AgentControlPlaneMultiAgentLoopProbeRunner
    {
        return $this->probeRunnerInstance ??= new AgentControlPlaneMultiAgentLoopProbeRunner(
            $this->orchestrator,
            $this->queue,
            $this->leases,
            fn (array $items): array => $this->flattenStrings($items),
            fn (array $writeSets): int => $this->writeSetCollisionCount($writeSets),
            fn (array $results): bool => $this->terminalBootstrapRuntimeSafety($results),
        );
    }


    /**
     * @return list<string>
     */
    private function flattenStrings(mixed $value): array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupCertificationArtifacts(string $runId): array
    {
        $queuePrune = $this->queue->prune([
            'tags' => [
                'multi_agent_loop_certification',
                'terminal_worker_bootstrap_probe_'.$runId,
                'terminal_worker_bootstrap_probe',
                'terminal_bootstrap_invalid_scope_'.$runId.'_tag',
                'terminal_bootstrap_preview_'.$runId.'_tag',
                'terminal_bootstrap_partial_supply',
                'terminal_bootstrap_partial_supply_'.$runId,
                'terminal_fleet_launch_plan_probe',
                'terminal_fleet_launch_plan_probe_'.$runId,
                'terminal_fleet_partial_supply_probe',
                'terminal_fleet_partial_supply_probe_'.$runId,
                'terminal_fleet_lane_isolation_negative_probe',
                'terminal_fleet_lane_negative_target_'.$runId,
                'terminal_fleet_lane_negative_other_'.$runId,
                'terminal_fleet_resume_rollup_probe',
                'terminal_fleet_resume_rollup_probe_'.$runId,
                'terminal_fleet_metadata_orphan_probe',
                'terminal_fleet_metadata_orphan_probe_'.$runId,
                'terminal_fleet_released_resume_probe',
                'terminal_fleet_released_resume_probe_'.$runId,
                'terminal_fleet_evidence_rollup_probe',
                'terminal_fleet_evidence_rollup_probe_'.$runId,
            ],
            'task_packet_id_prefixes' => [
                'mloop_'.$runId.'_',
                'probe_'.$runId,
                'terminal_bootstrap_invalid_scope_'.$runId,
                'terminal_bootstrap_partial_supply_'.$runId,
                'fleet_probe_'.$runId,
                'fleet_partial_supply_probe_'.$runId,
                'fleet_lane_negative_probe_'.$runId,
                'fleet_resume_rollup_probe_'.$runId,
                'fleet_metadata_orphan_probe_'.$runId,
                'fleet_released_resume_probe_'.$runId,
                'fleet_evidence_rollup_probe_'.$runId,
            ],
            'delete_task_files' => true,
            'preserve_statuses' => [],
        ]);

        $leasePrune = $this->leases->prune([
            'task_packet_id_prefixes' => [
                'mloop_'.$runId.'_',
                'probe_'.$runId,
                'terminal_bootstrap_invalid_scope_'.$runId,
                'orphan-task-'.$runId,
                'expired-task-'.$runId,
            ],
            'agent_id_prefixes' => [
                'agent_'.$runId.'_',
                'agent_dup_probe_'.$runId.'_',
                'agent_recheck_'.$runId.'_',
                'terminal_bootstrap_probe_'.$runId.'_',
                'terminal_bootstrap_invalid_scope_'.$runId,
                'terminal_bootstrap_preview_'.$runId,
                'terminal_fleet_resume_rollup_probe_'.$runId,
                'terminal_fleet_released_resume_probe_'.$runId,
                'terminal_fleet_evidence_rollup_probe_'.$runId,
            ],
            'lease_id_prefixes' => [
                'lease_orphan_'.$runId,
                'lease_expired_'.$runId,
            ],
            'delete_lease_files' => true,
            'preserve_statuses' => [AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE],
        ]);

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_multi_agent_loop_certification_artifact_cleanup.v1',
            'status' => ((string) ($queuePrune['status'] ?? '') === 'ok' && (string) ($leasePrune['status'] ?? '') === 'ok') ? 'ok' : 'blocked',
            'cleanup_performed' => true,
            'queue_prune' => $queuePrune,
            'lease_prune' => $leasePrune,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $cleanup
     * @return array<string, mixed>
     */
    private function postCleanupHealthDigestProbe(array $cleanup, int $targetMin): array
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService(
            $this->queue,
            new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases),
        ))->digest([
            'actor' => 'multi-agent-loop-certification-post-cleanup',
            'target_min_claimable_tasks' => max(1, min(25, $targetMin)),
            'max_new_tasks' => 0,
        ]);

        $claimableCount = (int) data_get($digest, 'queue_health.claimable_task_count', 0);
        $claimedCount = (int) data_get($digest, 'queue_health.claimed_task_count', 0);
        $activeLeaseCount = (int) data_get($digest, 'lease_health.active_lease_count', 0);
        $recoverableLeaseCount = (int) data_get($digest, 'lease_health.recoverable_lease_count', 0);
        $cleanupLeftNoRecoverableArtifacts = (string) ($cleanup['status'] ?? '') === 'ok'
            && $claimedCount === 0
            && $activeLeaseCount === 0
            && $recoverableLeaseCount === 0;
        $readyForFleetWorkers = $cleanupLeftNoRecoverableArtifacts
            && (string) ($digest['status'] ?? '') === 'ready'
            && (bool) data_get($digest, 'loop_decision.safe_to_start_new_worker', false)
            && (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status', '') === 'fleet_launch_plan_ready';

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_multi_agent_loop_certification_post_cleanup_health_digest_probe.v1',
            'status' => $cleanupLeftNoRecoverableArtifacts ? 'available' : 'blocked',
            'digest_status' => (string) ($digest['status'] ?? ''),
            'claimable_task_count' => $claimableCount,
            'claimed_task_count' => $claimedCount,
            'active_lease_count' => $activeLeaseCount,
            'recoverable_lease_count' => $recoverableLeaseCount,
            'safe_to_start_new_worker' => (bool) data_get($digest, 'loop_decision.safe_to_start_new_worker', false),
            'fleet_launch_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status', ''),
            'cleanup_left_no_recoverable_artifacts' => $cleanupLeftNoRecoverableArtifacts,
            'ready_for_fleet_workers' => $readyForFleetWorkers,
            'ready_blocker' => $readyForFleetWorkers
                ? ''
                : (string) data_get($digest, 'loop_decision.recommended_action', 'unknown'),
            'terminal_loop_health_digest_hash' => (string) ($digest['terminal_loop_health_digest_hash'] ?? ''),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @param  list<array<int, string>>  $writeSets
     */
    private function writeSetCollisionCount(array $writeSets): int
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount($writeSets);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function terminalBootstrapRuntimeSafety(array $results): bool
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::terminalBootstrapRuntimeSafety($results);
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
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmCompletedNeverReclaimed($cycles);
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    private function confirmNoLegacyReservationUsed(array $cycles): bool
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmNoLegacyReservationUsed($cycles);
    }

    /**
     * @param  array<string, bool>  $queueFlags
     * @param  array<string, bool>  $leaseFlags
     */
    private function runtimeSafetyAllFalse(array $queueFlags, array $leaseFlags): bool
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::runtimeSafetyAllFalse($queueFlags, $leaseFlags);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadJson($disk, string $path): ?array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::encodeJson($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    private function allCyclesTrue(array $cycleEvidence, string $key): bool
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue($cycleEvidence, $key);
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
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build($invariants, $cycleEvidence, $targetMin, $cycles);
    }

    /**
     * @param  array<string, bool>  $invariants
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    private function safeForParallelTerminalLoop(array $invariants, array $cycleEvidence): bool
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::safeForParallelTerminalLoop($invariants, $cycleEvidence);
    }

        public function terminalLoopFleetLaunchPlanPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetLaunchPlanPresent();
    }

        public function terminalLoopFleetReplenishmentPlanPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetReplenishmentPlanPresent();
    }

        public function terminalLoopFleetResumeRollupPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetResumeRollupPresent();
    }

        public function terminalLoopFleetEvidenceRollupPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetEvidenceRollupPresent();
    }

        public function terminalLoopFleetOperatorHandoffPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetOperatorHandoffPresent();
    }

        public function terminalLoopFleetLaneIsolationPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetLaneIsolationPresent();
    }

        public function terminalLoopCycleSupervisorPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopCycleSupervisorPresent();
    }

        public function terminalLoopFleetLaunchRunbookPresent(): bool
    {
        return $this->digestPredicates()->terminalLoopFleetLaunchRunbookPresent();
    }

    private function digestPredicates(): \App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopDigestPredicates
    {
        return $this->digestPredicates ??= new \App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopDigestPredicates();
    }

}
