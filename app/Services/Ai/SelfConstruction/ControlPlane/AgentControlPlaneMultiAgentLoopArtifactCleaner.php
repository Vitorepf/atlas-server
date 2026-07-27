<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * ARTIFACT CLEANER + POST-CLEANUP HEALTH PROBE extracted from the
 * god-class {@see AgentControlPlaneMultiAgentLoopCertificationService}.
 *
 * Owns the run-scoped artifact cleanup + post-cleanup health probe
 * (cleanupCertificationArtifacts, postCleanupHealthDigestProbe, and the
 * flattenStrings helper used by them). The runtime service delegates each
 * method to this collaborator through thin byte-identical delegators.
 */
final class AgentControlPlaneMultiAgentLoopArtifactCleaner
{
    public function __construct(
        private readonly mixed $queue,
        private readonly mixed $leases,
    ) {}

public function cleanupCertificationArtifacts(string $runId): array
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


public function postCleanupHealthDigestProbe(array $cleanup, int $targetMin): array
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


public function flattenStrings(mixed $value): array
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings($value);
    }

}
