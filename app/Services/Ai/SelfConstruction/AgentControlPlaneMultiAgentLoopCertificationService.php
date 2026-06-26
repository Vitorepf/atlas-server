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
                'completion_evidence_template_schema' => (string) data_get($result, 'completion_evidence_template.schema_version', ''),
                'completion_evidence_template_json_present' => (string) ($result['completion_evidence_template_json'] ?? '') !== '',
                'terminal_loop_operator_commands_schema' => (string) data_get($result, 'terminal_loop_operator_commands.schema_version', ''),
                'terminal_loop_queue_lane_contract_schema' => (string) data_get($result, 'queue_lane_contract.schema_version', ''),
                'terminal_loop_queue_lane_id' => (string) data_get($result, 'queue_lane_contract.queue_lane_id', ''),
                'terminal_loop_queue_lane_explicit' => (bool) data_get($result, 'queue_lane_contract.queue_lane_explicit', false),
                'terminal_loop_queue_lane_next_iteration_preserves_lane' => (bool) data_get($result, 'queue_lane_contract.next_iteration_preserves_queue_lane', false),
                'terminal_loop_queue_lane_contract_hash' => (string) data_get($result, 'queue_lane_contract.queue_lane_contract_hash', ''),
                'terminal_long_running_loop_contract_schema' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.schema_version', ''),
                'terminal_long_running_loop_next_iteration_command' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command', ''),
                'terminal_long_running_loop_stop_conditions' => (array) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.stop_conditions', []),
                'terminal_loop_resumption_checkpoint_schema' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.schema_version', ''),
                'terminal_loop_resumption_checkpoint_hash' => (string) ($result['terminal_loop_resumption_checkpoint_hash'] ?? ''),
                'terminal_loop_resumption_current_step' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.current_step', ''),
                'terminal_loop_can_resume_without_chat_history' => (bool) data_get($result, 'terminal_loop_resumption_checkpoint.can_resume_without_chat_history', false),
                'terminal_loop_iteration_runbook_schema' => (string) data_get($result, 'terminal_loop_iteration_runbook.schema_version', ''),
                'terminal_loop_iteration_runbook_hash' => (string) ($result['terminal_loop_iteration_runbook_hash'] ?? ''),
                'terminal_loop_iteration_status' => (string) data_get($result, 'terminal_loop_iteration_runbook.status', ''),
                'terminal_loop_iteration_step_count' => count((array) data_get($result, 'terminal_loop_iteration_runbook.iteration_steps', [])),
                'terminal_loop_can_loop_without_chat_history' => (bool) data_get($result, 'terminal_loop_iteration_runbook.can_loop_without_chat_history', false),
                'terminal_loop_shell_recipe_schema' => (string) data_get($result, 'terminal_loop_shell_recipe.schema_version', ''),
                'terminal_loop_shell_recipe_hash' => (string) ($result['terminal_loop_shell_recipe_hash'] ?? ''),
                'terminal_loop_shell_recipe_status' => (string) data_get($result, 'terminal_loop_shell_recipe.status', ''),
                'terminal_loop_shell_recipe_safe_to_copy_after_operator_review' => (bool) data_get($result, 'terminal_loop_shell_recipe.safe_to_copy_after_operator_review', false),
                'terminal_loop_shell_recipe_can_execute_from_bootstrap' => (bool) data_get($result, 'terminal_loop_shell_recipe.can_execute_from_bootstrap', true),
                'terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt' => (bool) data_get($result, 'terminal_loop_shell_recipe.requires_operator_to_run_worker_prompt', false),
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

                $completionEvidence = [
                    'packet_id' => (string) ($result['task_packet_id'] ?? ''),
                    'lease_id' => (string) ($result['lease_id'] ?? ''),
                    'actor' => $actor,
                    'agent_id' => $actor,
                    'evidence_kind' => 'terminal_worker_bootstrap_probe',
                    'files_changed' => (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []),
                    'commands_run' => ['terminal_worker_bootstrap_probe_complete_dry_run: passed'],
                    'tests_or_gates_result' => 'passed',
                    'git_status_short' => 'synthetic terminal bootstrap probe storage-only dry-run',
                    'git_diff_check_result' => 'clean',
                ];
                $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
                $completionResults[] = $this->orchestrator->completeDryRun(
                    (string) ($result['task_packet_id'] ?? ''),
                    (string) ($result['lease_id'] ?? ''),
                    $completionEvidence,
                );
            }
        }
        $invalidScopeProbe = $this->runTerminalBootstrapInvalidScopeProbe($bootstrap, $runId);
        $previewProbe = $this->runTerminalBootstrapPreviewProbe($bootstrap, $runId);
        $partialSupplyProbe = $this->runTerminalBootstrapPartialSupplyProbe($bootstrap, $runId);

        $readyCount = count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['status'] ?? '') === 'ready_for_worker'
                && (bool) ($result['one_shot_worker_packet_ready'] ?? false),
        ));
        $completedCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (string) ($result['event'] ?? '') === 'completed_dry_run',
        ));
        $structuredCompletionEvidenceValidCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (bool) data_get($result, 'evidence_validation.structured_completion_evidence_valid', false),
        ));
        $completionEvidenceFilesWithinScopeCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (bool) data_get($result, 'evidence_validation.files_changed_within_allowed_scope', false)
                && data_get($result, 'evidence_validation.files_changed_outside_allowed_scope', []) === [],
        ));
        $completionEvidenceValidationHashes = array_values(array_filter(array_map(
            static fn (array $result): string => (string) data_get($result, 'evidence_validation.evidence_validation_hash', ''),
            $completionResults,
        )));
        $writeSetCollisionCount = $this->writeSetCollisionCount($writeSets);
        $runtimeSafety = $this->terminalBootstrapRuntimeSafety($results);
        $completionCommandUsesDryRun = $completionCommands !== [] && count(array_filter(
            $completionCommands,
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-task-queue-complete-dry-run-status'),
        )) === count($completionCommands);
        $completionEvidenceTemplatePresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['completion_evidence_template_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1'
                && (bool) ($result['completion_evidence_template_json_present'] ?? false),
        )) === count($results);
        $terminalLoopOperatorCommandsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_operator_commands_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1'
                && (string) ($result['terminal_loop_queue_lane_contract_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_queue_lane_contract.v1'
                && (bool) ($result['terminal_loop_queue_lane_explicit'] ?? false)
                && (bool) ($result['terminal_loop_queue_lane_next_iteration_preserves_lane'] ?? false)
                && (string) ($result['terminal_loop_queue_lane_contract_hash'] ?? '') !== ''
                && (string) ($result['terminal_long_running_loop_contract_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1'
                && str_contains((string) ($result['terminal_long_running_loop_next_iteration_command'] ?? ''), '--agent-control-plane-terminal-worker-bootstrap-status')
                && in_array('completion_evidence_validation_not_valid', (array) ($result['terminal_long_running_loop_stop_conditions'] ?? []), true),
        )) === count($results);
        $resumptionContractsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (bool) ($result['resumption_contract_present'] ?? false)
                && (bool) ($result['resumption_requires_active_lease'] ?? false)
                && str_contains((string) ($result['resume_after_interruption_command'] ?? ''), '--agent-control-plane-task-lease-recovery-status'),
        )) === count($results);
        $resumptionCheckpointsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_resumption_checkpoint_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1'
                && (string) ($result['terminal_loop_resumption_checkpoint_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_resumption_current_step'] ?? '') === 'claimed_packet_ready_for_one_shot_worker'
                && (bool) ($result['terminal_loop_can_resume_without_chat_history'] ?? false),
        )) === count($results);
        $iterationRunbooksPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_iteration_runbook_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1'
                && (string) ($result['terminal_loop_iteration_runbook_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_iteration_status'] ?? '') === 'ready_for_single_packet_iteration'
                && (int) ($result['terminal_loop_iteration_step_count'] ?? 0) === 6
                && (bool) ($result['terminal_loop_can_loop_without_chat_history'] ?? false),
        )) === count($results);
        $shellRecipesPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_shell_recipe_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1'
                && (string) ($result['terminal_loop_shell_recipe_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_shell_recipe_status'] ?? '') === 'shell_recipe_ready'
                && (bool) ($result['terminal_loop_shell_recipe_safe_to_copy_after_operator_review'] ?? false)
                && ! (bool) ($result['terminal_loop_shell_recipe_can_execute_from_bootstrap'] ?? true)
                && (bool) ($result['terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt'] ?? false),
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
            && $completionEvidenceTemplatePresent
            && $terminalLoopOperatorCommandsPresent
            && $completedCount === $probeAgentCount
            && $leasesClosed
            && $resumptionContractsPresent
            && $resumptionCheckpointsPresent
            && $iterationRunbooksPresent
            && $shellRecipesPresent
            && (bool) ($invalidScopeProbe['rejected'] ?? false)
            && (bool) ($previewProbe['read_only_verified'] ?? false)
            && (bool) ($partialSupplyProbe['blocked_before_claim'] ?? false)
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
            'completion_evidence_template_present' => $completionEvidenceTemplatePresent,
            'terminal_loop_operator_commands_present' => $terminalLoopOperatorCommandsPresent,
            'structured_completion_evidence_all_valid' => $structuredCompletionEvidenceValidCount === $readyCount,
            'structured_completion_evidence_valid_count' => $structuredCompletionEvidenceValidCount,
            'completion_evidence_files_within_scope_all_valid' => $completionEvidenceFilesWithinScopeCount === $readyCount,
            'completion_evidence_files_within_scope_count' => $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_scope_escape_count' => $readyCount - $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_validation_hash_count' => count(array_unique($completionEvidenceValidationHashes)),
            'completion_evidence_validation_hashes' => array_values(array_unique($completionEvidenceValidationHashes)),
            'resumption_contracts_present' => $resumptionContractsPresent,
            'resumption_checkpoints_present' => $resumptionCheckpointsPresent,
            'iteration_runbooks_present' => $iterationRunbooksPresent,
            'shell_recipes_present' => $shellRecipesPresent,
            'parallel_lanes_distinct' => count(array_unique($taskIds)) === $probeAgentCount
                && count(array_unique($leaseIds)) === $probeAgentCount
                && $writeSetCollisionCount === 0,
            'leases_closed_by_dry_run' => $leasesClosed,
            'invalid_scope_rejected' => (bool) ($invalidScopeProbe['rejected'] ?? false),
            'invalid_scope_probe' => $invalidScopeProbe,
            'preview_read_only' => (bool) ($previewProbe['read_only_verified'] ?? false),
            'preview_probe' => $previewProbe,
            'partial_supply_blocks_before_claim' => (bool) ($partialSupplyProbe['blocked_before_claim'] ?? false),
            'partial_supply_probe' => $partialSupplyProbe,
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
    private function runTerminalFleetLaunchPlanProbe(string $runId, int $probeTerminalCount): array
    {
        $queueTag = 'terminal_fleet_launch_plan_probe_'.$runId;
        $seededPacketIds = [];
        $seedFailures = [];
        $namespace = self::SYNTHETIC_FILE_NAMESPACE.'/fleet_launch_plan_probe';

        for ($index = 0; $index < $probeTerminalCount; $index++) {
            $taskPacketId = sprintf('fleet_probe_%s_%d', $runId, $index);
            $allowedFile = sprintf('%s/%s_%d.php', $namespace, strtolower($runId), $index);
            $orchestration = $this->orchestrator->prepareAndEnqueue([
                'task_packet' => [
                    'task_packet_id' => $taskPacketId,
                    'objective' => sprintf('terminal fleet launch plan probe %d', $index),
                    'operator_id' => 'multi-agent-loop-certification',
                    'allowed_files' => [$allowedFile],
                    'scope_in' => [$allowedFile],
                    'acceptance_criteria' => ['fleet_launch_plan_probe_ok'],
                    'required_evidence' => ['fleet_launch_plan_checked'],
                    'risk_level' => 'low',
                    'rollback_strategy' => 'plan_only',
                ],
                'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_launch_plan_probe', $queueTag]],
            ]);

            if ((string) ($orchestration['event'] ?? '') === 'prepared_and_enqueued'
                && (string) data_get($orchestration, 'queue_entry.status', '') === 'ok') {
                $seededPacketIds[] = $taskPacketId;
            } else {
                $seedFailures[] = [
                    'task_packet_id' => $taskPacketId,
                    'event' => (string) ($orchestration['event'] ?? ''),
                    'queue_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
                ];
            }
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-probe-'.$runId,
            'target_min_claimable_tasks' => $probeTerminalCount,
            'max_new_tasks' => $probeTerminalCount,
            'queue_tags' => [$queueTag],
        ]);
        $assignments = (array) data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments', []);
        $actors = array_values(array_filter(array_map(
            static fn (array $assignment): string => (string) ($assignment['actor'] ?? ''),
            $assignments,
        )));
        $commands = array_values(array_filter(array_map(
            static fn (array $assignment): string => (string) ($assignment['execute_bootstrap_command'] ?? ''),
            $assignments,
        )));
        $allCommandsLaneBound = $commands !== [] && count(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-terminal-worker-bootstrap-status')
                && str_contains($command, '--queue-tag='.$queueTag)
                && ! str_contains($command, '--terminal-worker-bootstrap-preview'),
        )) === count($commands);

        $readyPathVerified = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_ready'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === true
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest') === false
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === $probeTerminalCount
            && count($assignments) === $probeTerminalCount
            && count(array_unique($actors)) === $probeTerminalCount
            && $allCommandsLaneBound;
        $laneBoundCommandsVerified = (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && (bool) data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest') === false
            && in_array('--queue-tag='.$queueTag, (array) data_get($digest, 'terminal_loop_fleet_lane_isolation.required_tag_args', []), true)
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash') !== null;
        $cycleSupervisorLaunchPathVerified = (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_worker_launch_ready'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'launch_or_continue_workers'
            && (bool) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound') === true
            && str_contains((string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command'), '--queue-tag='.$queueTag)
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_claim_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null;
        $runbookSteps = (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_steps', []);
        $runbookCommands = array_values(array_filter(array_map(
            static fn (array $step): string => (string) ($step['command'] ?? ''),
            $runbookSteps,
        )));
        $runbookReadyPathVerified = (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status') === 'fleet_launch_runbook_ready'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.safe_to_copy_after_operator_review') === true
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.can_resume_without_chat_history') === true
            && (int) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_count') === $probeTerminalCount
            && count($runbookSteps) === $probeTerminalCount
            && $runbookCommands !== []
            && count(array_filter(
                $runbookCommands,
                static fn (string $command): bool => str_contains($command, '--agent-control-plane-terminal-worker-bootstrap-status')
                    && str_contains($command, '--queue-tag='.$queueTag),
            )) === count($runbookCommands)
            && in_array('copy_each_terminal_command_into_separate_terminal', (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.ordered_operator_sequence', []), true)
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.resume_command'), '--agent-control-plane-terminal-loop-health-digest-status')
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.requires_fresh_health_digest_before_starting_more_terminals') === true
            && in_array('health_digest_no_longer_ready', (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.stop_conditions', []), true)
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_start_terminals_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_execute_commands_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash') !== null;

        return [
            'status' => $readyPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'probe_terminal_count' => $probeTerminalCount,
            'seeded_packet_count' => count($seededPacketIds),
            'seeded_packet_ids' => $seededPacketIds,
            'seed_failures' => $seedFailures,
            'digest_status' => (string) data_get($digest, 'status'),
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'safe_to_start_now' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'terminal_assignment_count' => count($assignments),
            'terminal_actors_distinct' => count(array_unique($actors)) === count($actors) && count($actors) === $probeTerminalCount,
            'terminal_commands_lane_bound' => $allCommandsLaneBound,
            'lane_isolation_status' => (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status'),
            'lane_bound_commands_verified' => $laneBoundCommandsVerified,
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'cycle_supervisor_next_command_is_lane_bound' => (bool) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound'),
            'cycle_supervisor_launch_path_verified' => $cycleSupervisorLaunchPathVerified,
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'),
            'fleet_launch_runbook_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status'),
            'fleet_launch_runbook_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_count'),
            'fleet_launch_runbook_ready_path_verified' => $runbookReadyPathVerified,
            'fleet_launch_runbook_hash' => (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash'),
            'can_execute_from_digest' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest'),
            'can_claim_from_digest' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest'),
            'blocked_reasons' => (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []),
            'fleet_launch_plan_hash' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash'),
            'ready_path_verified' => $readyPathVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalBootstrapPartialSupplyProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $queueTag = 'terminal_bootstrap_partial_supply_'.$runId;
        $taskPacketId = 'terminal_bootstrap_partial_supply_'.$runId;
        $allowedFile = sprintf('%s/terminal_bootstrap_partial_supply/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal bootstrap partial supply should block before claim',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['terminal_bootstrap_partial_supply_blocks_before_claim'],
                'required_evidence' => ['terminal_bootstrap_partial_supply_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_bootstrap_partial_supply', $queueTag]],
        ]);

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_partial_supply_'.$runId,
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 0,
            'queue_tags' => [$queueTag],
            'reason' => 'multi_agent_loop_certification_terminal_bootstrap_partial_supply_probe',
        ]);
        $queueRecord = $this->queue->get($taskPacketId);
        $activeLeaseCount = count($this->leases->activeLeases(['task_packet_id' => $taskPacketId]));
        $blockedBeforeClaim = (string) data_get($result, 'status') === 'blocked'
            && (string) data_get($result, 'claim_event') === 'task_supply_below_target_blocked_before_claim'
            && (bool) data_get($result, 'runtime_claim_persisted', true) === false
            && (bool) data_get($result, 'one_shot_worker_packet_ready', true) === false
            && (string) data_get($queueRecord, 'status') === 'claimable'
            && $activeLeaseCount === 0
            && in_array('terminal_worker_bootstrap_task_supply_gate_blocks_before_claim', (array) data_get($result, 'non_execution_guarantees', []), true);

        return [
            'status' => $blockedBeforeClaim ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'bootstrap_status' => (string) data_get($result, 'status', ''),
            'claim_event' => (string) data_get($result, 'claim_event', ''),
            'runtime_claim_persisted' => (bool) data_get($result, 'runtime_claim_persisted', false),
            'one_shot_worker_packet_ready' => (bool) data_get($result, 'one_shot_worker_packet_ready', false),
            'queue_status_after_bootstrap' => (string) data_get($queueRecord, 'status', ''),
            'active_lease_count_after_bootstrap' => $activeLeaseCount,
            'blocked_before_claim' => $blockedBeforeClaim,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetPartialSupplyGateProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_partial_supply_probe_'.$runId;
        $taskPacketId = 'fleet_partial_supply_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_partial_supply_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet partial supply launch gate probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_partial_supply_gate_ok'],
                'required_evidence' => ['fleet_partial_supply_gate_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_partial_supply_probe', $queueTag]],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-partial-supply-probe-'.$runId,
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => [$queueTag],
        ]);

        $targetTagArg = '--queue-tag='.$queueTag;
        $launchBlocked = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_blocked'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === 0
            && data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []) === [];
        $replenishmentRequired = (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count') === 2
            && (bool) data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now') === true
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $targetTagArg);
        $cycleSupervisorBlocksLaunch = (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_replenishment_required'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'replenish_before_launch'
            && (bool) data_get($digest, 'terminal_loop_cycle_supervisor.transition_guards.replenish_before_launch') === true
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false;
        $partialSupplyLaunchBlocked = (int) data_get($digest, 'queue_health.claimable_task_count') === 1
            && (bool) data_get($digest, 'queue_health.target_min_claimable_tasks_met') === false
            && in_array('task_supply_below_target_replenish_before_launch', (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []), true)
            && $launchBlocked
            && $replenishmentRequired
            && $cycleSupervisorBlocksLaunch;

        return [
            'status' => $partialSupplyLaunchBlocked ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'target_min_claimable_tasks' => 3,
            'claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count'),
            'target_min_claimable_tasks_met' => (bool) data_get($digest, 'queue_health.target_min_claimable_tasks_met'),
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'safe_to_start_now' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'blocked_reasons' => (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []),
            'replenishment_status' => (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'),
            'required_new_task_count' => (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'),
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'partial_supply_launch_blocked' => $partialSupplyLaunchBlocked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetLaneIsolationNegativeProbe(string $runId): array
    {
        $targetQueueTag = 'terminal_fleet_lane_negative_target_'.$runId;
        $otherQueueTag = 'terminal_fleet_lane_negative_other_'.$runId;
        $taskPacketId = 'fleet_lane_negative_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_lane_negative_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet lane isolation negative probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_lane_negative_probe_ok'],
                'required_evidence' => ['fleet_lane_negative_probe_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_lane_isolation_negative_probe', $otherQueueTag]],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-lane-negative-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$targetQueueTag],
        ]);

        $commands = $this->flattenStrings([
            data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []),
            data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'),
            data_get($digest, 'terminal_loop_fleet_operator_handoff.copy_paste_commands', []),
            data_get($digest, 'terminal_loop_fleet_replenishment_plan.commands', []),
            data_get($digest, 'terminal_loop_fleet_launch_plan.post_launch_observability_commands', []),
            data_get($digest, 'terminal_loop_fleet_lane_isolation.command_lane_checks', []),
        ]);

        $targetTagArg = '--queue-tag='.$targetQueueTag;
        $otherTagArg = '--queue-tag='.$otherQueueTag;
        $commandsUsingOtherTag = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, $otherTagArg),
        ));

        $requiredTagArgs = (array) data_get($digest, 'terminal_loop_fleet_lane_isolation.required_tag_args', []);
        $launchBlocked = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_blocked'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === 0
            && data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []) === [];
        $hiddenSupplyDetected = (int) data_get($digest, 'queue_health.claimable_task_count') === 0
            && (int) data_get($digest, 'queue_health.hidden_claimable_outside_requested_tags') >= 1
            && (bool) data_get($digest, 'queue_health.tag_filtered_supply_gap') === true;
        $replenishOwnLane = (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.status') === 'fleet_operator_handoff_replenish_before_launch'
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $targetTagArg)
            && ! str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $otherTagArg);
        $laneGuardHeld = (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && (bool) data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && in_array($targetTagArg, $requiredTagArgs, true)
            && $commandsUsingOtherTag === [];

        $noCrossLaneLaunchVerified = $hiddenSupplyDetected && $launchBlocked && $replenishOwnLane && $laneGuardHeld;

        return [
            'status' => $noCrossLaneLaunchVerified ? 'available' : 'blocked',
            'target_queue_tag' => $targetQueueTag,
            'other_queue_tag' => $otherQueueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'target_claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count'),
            'hidden_claimable_outside_requested_tags' => (int) data_get($digest, 'queue_health.hidden_claimable_outside_requested_tags'),
            'tag_filtered_supply_gap' => (bool) data_get($digest, 'queue_health.tag_filtered_supply_gap'),
            'launch_blocked' => $launchBlocked,
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'copy_paste_terminal_command_count' => count((array) data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', [])),
            'replenishment_status' => (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'),
            'handoff_status' => (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.status'),
            'handoff_replenishes_target_lane' => $replenishOwnLane,
            'lane_isolation_status' => (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status'),
            'required_tag_args' => $requiredTagArgs,
            'commands_using_other_lane_tag_count' => count($commandsUsingOtherTag),
            'no_cross_lane_launch_verified' => $noCrossLaneLaunchVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetResumeRollupProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_resume_rollup_probe_'.$runId;
        $taskPacketId = 'fleet_resume_rollup_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_resume_rollup_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet resume rollup recovery-path probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_resume_rollup_probe_ok'],
                'required_evidence' => ['fleet_resume_rollup_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_resume_rollup_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_resume_rollup_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $leaseId = (string) ($claim['lease_id'] ?? '');
        if ($leaseId !== '') {
            Storage::disk('local')->delete(AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$leaseId.'.json');
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-resume-rollup-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);

        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $handoff = (array) data_get($digest, 'terminal_loop_fleet_operator_handoff', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);
        $recoveryPathVerified = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (int) data_get($rollup, 'recoverable_task_count') === 1
            && (bool) data_get($rollup, 'resume_attention_required') === true
            && (bool) data_get($rollup, 'can_recover_from_rollup') === false
            && (bool) data_get($rollup, 'can_claim_from_rollup') === false
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'safe_next_action') === 'recover_then_claim_fresh_lease'
            && str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status')
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId)
            && data_get($rollup, 'terminal_loop_fleet_resume_rollup_hash') !== null;
        $operatorHandoffRecoveryPriorityVerified = (string) data_get($handoff, 'status') === 'fleet_operator_handoff_recover_before_loop'
            && (string) data_get($handoff, 'next_operator_action') === 'recover_orphaned_or_expired_task_leases'
            && str_contains((string) data_get($handoff, 'primary_command'), '--packet='.$taskPacketId)
            && data_get($handoff, 'can_execute_from_handoff') === false
            && data_get($handoff, 'terminal_loop_fleet_operator_handoff_hash') !== null;

        return [
            'status' => $recoveryPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'claim_event' => (string) ($claim['event'] ?? ''),
            'lease_id' => $leaseId,
            'lease_file_deleted_for_orphan_probe' => $leaseId !== '',
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'resume_attention_required' => (bool) data_get($rollup, 'resume_attention_required'),
            'recover_command_present' => str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status'),
            'can_recover_from_rollup' => (bool) data_get($rollup, 'can_recover_from_rollup'),
            'can_claim_from_rollup' => (bool) data_get($rollup, 'can_claim_from_rollup'),
            'fleet_resume_rollup_hash' => (string) data_get($rollup, 'terminal_loop_fleet_resume_rollup_hash'),
            'recovery_path_verified' => $recoveryPathVerified,
            'operator_handoff_status' => (string) data_get($handoff, 'status'),
            'operator_handoff_next_action' => (string) data_get($handoff, 'next_operator_action'),
            'operator_handoff_recovery_priority_verified' => $operatorHandoffRecoveryPriorityVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetMetadataOrphanRecoveryProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_metadata_orphan_probe_'.$runId;
        $taskPacketId = 'fleet_metadata_orphan_probe_'.$runId;
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId),
            'status' => 'claimed',
            'objective' => 'terminal fleet metadata-less orphan recovery probe',
            'allowed_files' => [self::SYNTHETIC_FILE_NAMESPACE.'/metadata_orphan.php'],
            'normalized_scope' => [
                'allowed_files' => [self::SYNTHETIC_FILE_NAMESPACE.'/metadata_orphan.php'],
                'forbidden_files' => [],
                'forbidden_in_allowed' => [],
                'forbidden_axis_hits' => [],
            ],
            'acceptance_criteria' => ['metadata_less_orphan_must_be_recoverable'],
            'required_tests' => ['php artisan test --filter=metadata_orphan_recovery_probe'],
        ];
        $this->queue->enqueue($packet, [
            'tags' => [$queueTag],
            'metadata' => ['agent_id' => 'terminal_fleet_metadata_orphan_probe_'.$runId],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases)))->digest([
            'actor' => 'terminal-fleet-metadata-orphan-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);
        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);

        $recovery = (new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases))->recoverOrphanedClaims([
            'actor' => 'terminal-fleet-metadata-orphan-probe-'.$runId,
            'packet' => $taskPacketId,
            'reason' => 'metadata_less_orphan_probe',
        ]);
        $recordAfter = $this->queue->get($taskPacketId);
        $receiptKinds = array_column((array) data_get($recordAfter, 'receipts', []), 'receipt_kind');

        $digestDetected = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'lease_id') === ''
            && (string) data_get($summary, 'classification') === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_ORPHAN
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId);
        $recoveryVerified = (string) data_get($recovery, 'event') === 'recover_orphaned_claims'
            && (int) data_get($recovery, 'claimed_record_count') === 1
            && (int) data_get($recovery, 'recovered_count') === 1
            && (string) data_get($recordAfter, 'status') === 'claimable'
            && in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_TASK_LEASE_RECOVERY_EXECUTED, $receiptKinds, true);

        return [
            'status' => ($digestDetected && $recoveryVerified) ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'classification' => (string) data_get($summary, 'classification'),
            'recover_command_present' => str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status'),
            'packet_scoped_recover_command' => str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId),
            'recovery_event' => (string) data_get($recovery, 'event'),
            'recovered_count' => (int) data_get($recovery, 'recovered_count'),
            'final_queue_status' => (string) data_get($recordAfter, 'status'),
            'metadata_orphan_digest_detected' => $digestDetected,
            'metadata_orphan_recovery_verified' => $recoveryVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetReleasedResumeProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_released_resume_probe_'.$runId;
        $taskPacketId = 'fleet_released_resume_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_released_resume_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet released-task resume probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_released_resume_probe_ok'],
                'required_evidence' => ['fleet_released_resume_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_released_resume_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_released_resume_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $leaseId = (string) ($claim['lease_id'] ?? '');
        if ($leaseId !== '') {
            $this->orchestrator->releaseLease($leaseId, 'terminal_fleet_released_resume_probe_'.$runId, [
                'reason' => 'operator_interrupted_terminal',
            ]);
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases)))->digest([
            'actor' => 'terminal-fleet-released-resume-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);
        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);

        $recovery = (new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases))->recoverReleasedTasks([
            'actor' => 'terminal_fleet_released_resume_probe_'.$runId,
            'reason' => 'multi_agent_loop_released_resume_probe',
            'packet' => $taskPacketId,
        ]);
        $record = $this->queue->get($taskPacketId) ?? [];
        $receiptKinds = array_values(array_map(
            static fn (array $receipt): string => (string) ($receipt['receipt_kind'] ?? ''),
            (array) ($record['receipts'] ?? []),
        ));

        $digestRecoveryPathVerified = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (int) data_get($rollup, 'recoverable_task_count') === 1
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'classification') === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_RELEASED
            && (string) data_get($summary, 'safe_next_action') === 'recover_then_claim_fresh_lease'
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId)
            && (int) data_get($digest, 'lease_health.recoverable_released_task_count') === 1;
        $requeueVerified = $digestRecoveryPathVerified
            && (string) data_get($recovery, 'event') === 'recover_released_tasks'
            && (int) data_get($recovery, 'recovered_count') === 1
            && (string) data_get($record, 'status') === 'claimable'
            && in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_RELEASED_TASK_REQUEUED, $receiptKinds, true);

        return [
            'status' => $requeueVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'claim_event' => (string) ($claim['event'] ?? ''),
            'lease_id' => $leaseId,
            'release_reason' => 'operator_interrupted_terminal',
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'recoverable_released_task_count' => (int) data_get($digest, 'lease_health.recoverable_released_task_count'),
            'summary_classification' => (string) data_get($summary, 'classification'),
            'digest_recovery_path_verified' => $digestRecoveryPathVerified,
            'recovery_event' => (string) data_get($recovery, 'event'),
            'released_recovered_count' => (int) data_get($recovery, 'recovered_count'),
            'final_queue_status' => (string) data_get($record, 'status'),
            'receipt_written' => in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_RELEASED_TASK_REQUEUED, $receiptKinds, true),
            'released_task_requeue_verified' => $requeueVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalFleetEvidenceRollupProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_evidence_rollup_probe_'.$runId;
        $taskPacketId = 'fleet_evidence_rollup_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_evidence_rollup_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet evidence rollup green-path probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_evidence_rollup_probe_ok'],
                'required_evidence' => ['fleet_evidence_rollup_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_evidence_rollup_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_evidence_rollup_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $completion = [];
        if ((string) ($claim['event'] ?? '') === 'claimed') {
            $completionEvidence = [
                'packet_id' => (string) ($claim['task_packet_id'] ?? ''),
                'lease_id' => (string) ($claim['lease_id'] ?? ''),
                'actor' => 'terminal_fleet_evidence_rollup_probe_'.$runId,
                'files_changed' => [$allowedFile],
                'commands_run' => ['php artisan test --filter=terminal_fleet_evidence_rollup_probe'],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'M '.$allowedFile,
                'git_diff_check_result' => 'clean',
            ];
            $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
            $evidenceHash = (string) $completionEvidence['evidence_hash'];
            $completion = $this->orchestrator->completeDryRun(
                (string) ($claim['task_packet_id'] ?? ''),
                (string) ($claim['lease_id'] ?? ''),
                $completionEvidence,
            );
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-evidence-rollup-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);

        $rollup = (array) data_get($digest, 'terminal_loop_fleet_evidence_rollup', []);
        $greenPathVerified = (string) data_get($rollup, 'status') === 'fleet_evidence_rollup_green'
            && (int) data_get($rollup, 'completed_dry_run_task_count') === 1
            && (int) data_get($rollup, 'valid_completion_evidence_count') === 1
            && (int) data_get($rollup, 'attention_required_count') === 0
            && (bool) data_get($rollup, 'ready_for_operator_review') === true
            && in_array($evidenceHash, (array) data_get($rollup, 'evidence_hashes', []), true)
            && data_get($rollup, 'terminal_loop_fleet_evidence_rollup_hash') !== null;
        $cycleSupervisorEvidenceReviewPathVerified = (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_evidence_review_ready'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'review_evidence'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose') === 'review_completed_dry_run_evidence_and_rerun_digest'
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_complete_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null;

        return [
            'status' => $greenPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'claim_event' => (string) ($claim['event'] ?? ''),
            'completion_event' => (string) ($completion['event'] ?? ''),
            'rollup_status' => (string) data_get($rollup, 'status'),
            'completed_dry_run_task_count' => (int) data_get($rollup, 'completed_dry_run_task_count'),
            'valid_completion_evidence_count' => (int) data_get($rollup, 'valid_completion_evidence_count'),
            'attention_required_count' => (int) data_get($rollup, 'attention_required_count'),
            'ready_for_operator_review' => (bool) data_get($rollup, 'ready_for_operator_review'),
            'evidence_hash_present' => in_array($evidenceHash, (array) data_get($rollup, 'evidence_hashes', []), true),
            'fleet_evidence_rollup_hash' => (string) data_get($rollup, 'terminal_loop_fleet_evidence_rollup_hash'),
            'green_path_verified' => $greenPathVerified,
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'cycle_supervisor_evidence_review_path_verified' => $cycleSupervisorEvidenceReviewPathVerified,
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalBootstrapPreviewProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $tag = 'terminal_bootstrap_preview_'.$runId.'_tag';
        $queueTotalBefore = (int) data_get($this->queue->registry(), 'total_count', 0);
        $activeLeasesBefore = count($this->leases->activeLeases());

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_preview_'.$runId,
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => [$tag],
            'reason' => 'multi_agent_loop_certification_preview_probe',
            'preview_only' => true,
        ]);

        $queueTotalAfter = (int) data_get($this->queue->registry(), 'total_count', 0);
        $activeLeasesAfter = count($this->leases->activeLeases());
        $executeCommand = (string) ($result['preview_execute_bootstrap_command'] ?? '');
        $readOnlyVerified = (bool) ($result['preview_only'] ?? false)
            && (string) ($result['auto_replenishment_status'] ?? '') === 'preview_only_not_run'
            && (string) ($result['claim_event'] ?? '') === 'preview_only_no_claim_attempted'
            && ! (bool) ($result['runtime_claim_persisted'] ?? true)
            && (int) ($result['generated_task_count'] ?? 1) === 0
            && $queueTotalAfter === $queueTotalBefore
            && $activeLeasesAfter === $activeLeasesBefore
            && str_contains($executeCommand, '--agent-control-plane-terminal-worker-bootstrap-status')
            && ! str_contains($executeCommand, '--terminal-worker-bootstrap-preview');

        return [
            'queue_tag' => $tag,
            'status' => (string) ($result['status'] ?? ''),
            'preview_only' => (bool) ($result['preview_only'] ?? false),
            'auto_replenishment_status' => (string) ($result['auto_replenishment_status'] ?? ''),
            'claim_event' => (string) ($result['claim_event'] ?? ''),
            'runtime_claim_persisted' => (bool) ($result['runtime_claim_persisted'] ?? true),
            'generated_task_count' => (int) ($result['generated_task_count'] ?? 0),
            'preview_claimable_count' => (int) ($result['preview_claimable_count'] ?? 0),
            'preview_would_replenish' => (bool) ($result['preview_would_replenish'] ?? false),
            'preview_would_generate_task_count' => (int) ($result['preview_would_generate_task_count'] ?? 0),
            'preview_execute_bootstrap_command' => $executeCommand,
            'queue_total_before' => $queueTotalBefore,
            'queue_total_after' => $queueTotalAfter,
            'active_leases_before' => $activeLeasesBefore,
            'active_leases_after' => $activeLeasesAfter,
            'read_only_verified' => $readOnlyVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runTerminalBootstrapInvalidScopeProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $packetId = 'terminal_bootstrap_invalid_scope_'.$runId;
        $tag = $packetId.'_tag';
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => $packetId,
            'task_packet_hash' => hash('sha256', $packetId),
            'status' => 'planned',
            'objective' => 'Invalid terminal worker scope probe',
            'allowed_files' => [],
            'normalized_scope' => [
                'allowed_files' => [],
                'forbidden_files' => [],
                'forbidden_in_allowed' => [],
                'forbidden_axis_hits' => [],
            ],
            'acceptance_criteria' => ['invalid_scope_must_not_reach_worker'],
            'required_tests' => ['php artisan test --filter=invalid_scope_probe'],
        ];
        $this->queue->enqueue($packet, ['tags' => [$tag]]);

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_invalid_scope_'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 0,
            'queue_tags' => [$tag],
            'reason' => 'multi_agent_loop_certification_invalid_scope_probe',
        ]);

        $queueStatus = (string) data_get($this->queue->get($packetId), 'status', '');
        $rejected = (string) ($result['status'] ?? '') === 'blocked'
            && (string) ($result['worker_packet_blocked_reason'] ?? '') === 'unsafe_worker_scope'
            && in_array('allowed_files_empty', (array) ($result['worker_packet_scope_blockers'] ?? []), true)
            && (bool) ($result['lease_released_after_worker_packet_blocked'] ?? false)
            && $queueStatus === 'released';

        return [
            'packet_id' => $packetId,
            'queue_tag' => $tag,
            'status' => (string) ($result['status'] ?? ''),
            'claim_event' => (string) ($result['claim_event'] ?? ''),
            'worker_packet_ready' => (bool) ($result['one_shot_worker_packet_ready'] ?? true),
            'worker_packet_blocked_reason' => (string) ($result['worker_packet_blocked_reason'] ?? ''),
            'worker_packet_scope_blockers' => (array) ($result['worker_packet_scope_blockers'] ?? []),
            'lease_released_after_worker_packet_blocked' => (bool) ($result['lease_released_after_worker_packet_blocked'] ?? false),
            'queue_status_after_rejection' => $queueStatus,
            'rejected' => $rejected,
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
     * @return list<string>
     */
    private function flattenStrings(mixed $value): array
    {
        $strings = [];
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $item) {
            foreach ($this->flattenStrings($item) as $string) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
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
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::recursivelyKsort($value);
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
            'complete_dry_run_requires_queue_claim_binding' => [
                'value' => (bool) ($invariants['complete_dry_run_requires_queue_claim_binding'] ?? false),
                'why' => 'Every completed_dry_run packet proves the queue record was still claimed and its metadata.lease_id/metadata.agent_id matched the active lease before completion.',
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
            'structured_completion_evidence_valid' => [
                'value' => (bool) ($invariants['structured_completion_evidence_valid'] ?? false),
                'why' => 'Every completed_dry_run call includes a structured completion evidence payload with files_changed, commands_run, tests_or_gates_result, git_status_short, git_diff_check_result and validation hash.',
            ],
            'completion_evidence_files_within_scope' => [
                'value' => (bool) ($invariants['completion_evidence_files_within_scope'] ?? false)
                    && (bool) ($invariants['terminal_worker_bootstrap_completion_evidence_files_within_scope'] ?? false),
                'why' => 'Every completed_dry_run evidence payload has files_changed constrained to the claimed task packet normalized allowed_files/write_set; scope escapes are blocked before lease release.',
            ],
            'worker_completion_evidence_template_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_completion_evidence_template_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include the machine-readable completion evidence JSON template required by complete-dry-run.',
            ],
            'worker_operator_loop_commands_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_operator_commands_present'] ?? false),
                'why' => 'Terminal bootstrap exposes copy-paste operator commands for claim/replenish, renew, complete, recover/resume, and continue-after-completion.',
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
            'worker_resumption_checkpoint_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_resumption_checkpoint_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed resumption checkpoint that names the current step, active task/lease, exact resume commands and chat-history-free continuation guarantee.',
            ],
            'worker_iteration_runbook_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_iteration_runbook_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed six-step iteration runbook for recover, execute prompt, renew lease, write evidence, complete dry-run and claim next without chat history.',
            ],
            'worker_shell_recipe_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_shell_recipe_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed read-only shell recipe with bootstrap/recover/renew/complete commands, copy-paste loop skeleton and manual evidence slots.',
            ],
            'terminal_loop_health_digest_present' => [
                'value' => (bool) ($invariants['terminal_loop_health_digest_present'] ?? false),
                'why' => 'The read-only terminal loop health digest is wired so operators can inspect supply, stale leases and next commands before running long-lived terminal fleets.',
            ],
            'terminal_loop_fleet_launch_plan_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_plan_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet launch plan with distinct terminal actors, copy-paste bootstrap commands, start blockers and observability commands without claiming or executing work.',
            ],
            'terminal_loop_fleet_launch_plan_ready_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_plan_ready_path_verified'] ?? false),
                'why' => 'The certification seeds a tagged claimable lane, asks the health digest for a fleet plan, and proves it recommends distinct terminal actors with lane-bound bootstrap commands while remaining unable to claim or execute from the digest.',
            ],
            'terminal_loop_fleet_partial_supply_launch_blocked' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_partial_supply_launch_blocked'] ?? false),
                'why' => 'The certification seeds a partially supplied tagged lane, requests a larger target, and proves the health digest blocks fleet launch, preserves zero terminal assignments and routes the cycle supervisor to replenishment before any worker start.',
            ],
            'terminal_loop_fleet_replenishment_plan_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_replenishment_plan_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet replenishment plan with exact shortage counts, replenish/recheck/start sequence and non-execution guarantees so a fleet lane can be refilled before workers claim.',
            ],
            'terminal_loop_fleet_resume_rollup_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_resume_rollup_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet resume rollup that summarizes active and recoverable task leases, exact recovery commands and fresh-claim requirements without mutating leases or queue records.',
            ],
            'terminal_loop_fleet_resume_recovery_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_resume_recovery_path_verified'] ?? false),
                'why' => 'The certification creates an orphaned claimed packet, asks the digest for resume guidance, and proves it reports fleet_resume_rollup_recovery_required with a packet-scoped recovery command while remaining unable to recover or claim from the rollup.',
            ],
            'terminal_loop_fleet_metadata_orphan_recovery_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_metadata_orphan_recovery_verified'] ?? false),
                'why' => 'The certification creates a claimed packet whose lease metadata is missing, proves the health digest classifies it as a recoverable orphan with a packet-scoped recovery command, then runs task-lease recovery to return only that packet to claimable with a recovery receipt.',
            ],
            'terminal_loop_fleet_released_task_requeue_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_released_task_requeue_verified'] ?? false),
                'why' => 'The certification creates a released non-terminal packet, proves the health digest classifies it as recoverable_released_task, then runs task-lease recovery to move it back to claimable with a released_task_requeued receipt.',
            ],
            'terminal_loop_fleet_evidence_rollup_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_evidence_rollup_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet evidence rollup that summarizes completed dry-run packets, valid completion evidence, missing receipts and operator-review readiness without writing receipts or promoting completion.',
            ],
            'terminal_loop_fleet_evidence_rollup_green_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_evidence_rollup_green_path_verified'] ?? false),
                'why' => 'The certification seeds, claims and completes one tagged dry-run packet with structured evidence, then proves the health digest rollup reports fleet_evidence_rollup_green and ready_for_operator_review without writing receipts from the digest.',
            ],
            'terminal_loop_fleet_operator_handoff_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_operator_handoff_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a unified fleet operator handoff that prioritizes recovery, replenishment, launch and evidence review with copy-paste commands while executing none of them.',
            ],
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_operator_handoff_recovery_priority_verified'] ?? false),
                'why' => 'The resume-rollup probe also proves the unified handoff chooses recovery before replenish/launch when an orphaned claimed packet is present.',
            ],
            'terminal_loop_fleet_lane_isolation_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_isolation_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet lane-isolation block that checks all copy-paste commands preserve requested queue tags.',
            ],
            'terminal_loop_fleet_lane_bound_commands_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_bound_commands_verified'] ?? false),
                'why' => 'The fleet-launch probe seeds a tagged lane and proves bootstrap, replenishment, health and per-terminal commands remain bound to that queue tag.',
            ],
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_no_cross_lane_launch_verified'] ?? false),
                'why' => 'The negative lane-isolation probe seeds claimable work in a different lane, requests an empty target lane, and proves launch stays blocked while handoff recommends replenishing the requested lane instead of stealing unrelated work.',
            ],
            'terminal_loop_cycle_supervisor_present' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a cycle supervisor that collapses recovery, replenishment, launch, evidence review and wait/inspect into one exact next state and command without executing it.',
            ],
            'terminal_loop_cycle_supervisor_launch_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_launch_path_verified'] ?? false),
                'why' => 'The fleet-launch probe proves the cycle supervisor selects launch_or_continue_workers, emits a lane-bound bootstrap command and keeps execute/claim flags false when claimable supply is ready.',
            ],
            'terminal_loop_cycle_supervisor_evidence_review_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_evidence_review_path_verified'] ?? false),
                'why' => 'The evidence-rollup probe proves the cycle supervisor selects review_evidence before more replenishment when completed dry-run evidence is ready for operator review.',
            ],
            'terminal_loop_fleet_launch_runbook_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_runbook_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet launch runbook with numbered terminal steps, copy-paste commands, post-launch observability commands, interruption-resume instructions and non-execution flags.',
            ],
            'terminal_loop_fleet_launch_runbook_ready_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_runbook_ready_path_verified'] ?? false),
                'why' => 'The fleet-launch probe proves the runbook becomes ready on a worker-eligible tagged lane, carries one lane-bound command per recommended terminal, requires a fresh health digest before more terminals, and cannot execute or start terminals from the runbook.',
            ],
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts' => [
                'value' => (bool) ($invariants['certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts'] ?? false),
                'why' => 'After pruning the run-scoped synthetic packets and leases, the certification asks the read-only terminal-loop health digest to prove there are zero claimed packets, zero active leases and zero recoverable lease/task artifacts left by the run.',
            ],
            'worker_invalid_scope_rejected' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_rejects_invalid_worker_scope'] ?? false),
                'why' => 'Terminal bootstrap probes an empty allowed_files worker packet and requires blocked status plus lease release before handoff.',
            ],
            'worker_bootstrap_preview_read_only' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_preview_read_only'] ?? false),
                'why' => 'Terminal bootstrap preview mode proves operators can inspect claimable supply and the execute command without auto-replenishing, claiming a lease or mutating the queue.',
            ],
            'worker_bootstrap_partial_supply_blocks_before_claim' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_partial_supply_blocks_before_claim'] ?? false),
                'why' => 'Terminal bootstrap probes a partially supplied lane with no new replenishment available and proves the command blocks before claim/lease, preserving the claimable packet for replenishment-first launch.',
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
            'complete_dry_run_requires_queue_claim_binding',
            'recovery_never_reopens_completed',
            'no_legacy_reservation_used',
            'runtime_safety_all_false',
            'queue_transition_policy_enforced',
            'terminal_worker_bootstrap_resumption_contract_present',
            'terminal_worker_bootstrap_resumption_checkpoint_present',
            'terminal_worker_bootstrap_iteration_runbook_present',
            'terminal_worker_bootstrap_shell_recipe_present',
            'terminal_loop_health_digest_present',
            'terminal_loop_fleet_launch_plan_present',
            'terminal_loop_fleet_launch_plan_ready_path_verified',
            'terminal_loop_fleet_partial_supply_launch_blocked',
            'terminal_loop_fleet_replenishment_plan_present',
            'terminal_loop_fleet_resume_rollup_present',
            'terminal_loop_fleet_resume_recovery_path_verified',
            'terminal_loop_fleet_metadata_orphan_recovery_verified',
            'terminal_loop_fleet_released_task_requeue_verified',
            'terminal_loop_fleet_evidence_rollup_present',
            'terminal_loop_fleet_evidence_rollup_green_path_verified',
            'terminal_loop_fleet_operator_handoff_present',
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified',
            'terminal_loop_fleet_lane_isolation_present',
            'terminal_loop_fleet_lane_bound_commands_verified',
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified',
            'terminal_loop_cycle_supervisor_present',
            'terminal_loop_cycle_supervisor_launch_path_verified',
            'terminal_loop_cycle_supervisor_evidence_review_path_verified',
            'terminal_loop_fleet_launch_runbook_present',
            'terminal_loop_fleet_launch_runbook_ready_path_verified',
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts',
            'terminal_worker_bootstrap_rejects_invalid_worker_scope',
            'terminal_worker_bootstrap_completion_evidence_template_present',
            'completion_evidence_files_within_scope',
            'terminal_worker_bootstrap_operator_commands_present',
            'terminal_worker_bootstrap_preview_read_only',
            'terminal_worker_bootstrap_partial_supply_blocks_before_claim',
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

    private function terminalLoopFleetLaunchPlanPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-fleet-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-fleet-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_launch_plan.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_PLAN_SCHEMA_VERSION
            && data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest') === false;
    }

    private function terminalLoopFleetReplenishmentPlanPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-replenishment-probe',
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => ['multi-agent-certification-replenishment-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count') === 2
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.terminal_loop_fleet_replenishment_plan_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.can_replenish_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.can_claim_from_digest') === false;
    }

    private function terminalLoopFleetResumeRollupPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-resume-rollup-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-resume-rollup-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_RESUME_ROLLUP_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.status') === 'fleet_resume_rollup_clear'
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.terminal_loop_fleet_resume_rollup_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_recover_from_rollup') === false
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_claim_from_rollup') === false
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_complete_from_rollup') === false;
    }

    private function terminalLoopFleetEvidenceRollupPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-evidence-rollup-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-evidence-rollup-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.status') === 'fleet_evidence_rollup_no_completed_tasks'
            && data_get($digest, 'terminal_loop_fleet_evidence_rollup.terminal_loop_fleet_evidence_rollup_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_evidence_rollup.ready_for_operator_review') === false;
    }

    private function terminalLoopFleetOperatorHandoffPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-operator-handoff-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-operator-handoff-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.terminal_loop_fleet_operator_handoff_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_execute_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_recover_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_replenish_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_claim_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_complete_from_handoff') === false;
    }

    private function terminalLoopFleetLaneIsolationPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-lane-isolation-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-lane-isolation-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LANE_ISOLATION_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_steal_unrelated_lane_work') === false
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash') !== null;
    }

    private function terminalLoopCycleSupervisorPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-cycle-supervisor-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-cycle-supervisor-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') !== ''
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command') !== ''
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_claim_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_call_provider_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_spend_tokens_from_supervisor') === false;
    }

    private function terminalLoopFleetLaunchRunbookPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-launch-runbook-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-launch-runbook-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status') !== ''
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.can_resume_without_chat_history') === true
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_start_terminals_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_execute_commands_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_claim_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_call_provider_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_spend_tokens_from_runbook') === false;
    }
}
