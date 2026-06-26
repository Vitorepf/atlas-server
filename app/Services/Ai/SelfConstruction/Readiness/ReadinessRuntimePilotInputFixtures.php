<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Pure-data fixture arrays used by the Runtime Pilot Simulator and the Agent
 * Runtime Registry orchestrator readiness projections.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * These arrays have zero $this dependencies — fully deterministic, static.
 */
final class ReadinessRuntimePilotInputFixtures
{
    /**
     * Task packet shape understood by the Agent Runtime Registry orchestrator.
     * Returns a registry-specific payload — distinct from defaultRuntimePilotInput()
     * because the registry consumes scalar workspace_policy, required_capabilities[]
     * and requires_lease.
     *
     * @return array<string, mixed>
     */
    public static function defaultAgentRuntimeRegistryTaskPacket(): array
    {
        return [
            'task_packet_id' => 'AGENT-CONTROL-PLANE-REGISTRY-PROBE-0001',
            'objective' => 'Agent Runtime Registry orchestrator readiness projection',
            'risk_level' => 'low',
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'required_capabilities' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRuntimePilotInput(): array
    {
        return [
            'objective' => 'Runtime Pilot Simulator dry-run for Agent Control Plane v1',
            'source' => 'runtime_pilot_self_status',
            'operator_id' => 'operator-runtime-pilot',
            'parent_run_id' => 'AGENT-CONTROL-PLANE-RUNTIME-PILOT-0001',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneClaimLeaseSimulator.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneScopeLockPlanner.php',
            ],
            'forbidden_files' => [
                'routes/api.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php',
            ],
            'acceptance_criteria' => [
                'task_packet_planned',
                'scope_lock_safe',
                'evidence_dry_run_complete',
            ],
            'required_evidence' => [
                'task_packet_created',
                'claim_lease_simulated',
                'scope_lock_planned',
                'continuation_summary_planned',
                'cost_import_planned',
                'work_product_manifest_planned',
                'runtime_pilot_completed',
            ],
            'risk_level' => 'low',
            'max_runtime_seconds' => 1800,
            'max_token_budget' => 0,
            'workspace_policy' => [
                'isolation' => 'simulated_worktree',
            ],
            'continuation_context' => [
                'origin' => 'runtime_pilot_self_status',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRuntimePilotInputSecondary(): array
    {
        return [
            'objective' => 'Secondary Runtime Pilot Simulator dry-run agent (multi-agent test)',
            'source' => 'runtime_pilot_self_status_secondary',
            'operator_id' => 'operator-runtime-pilot-secondary',
            'parent_run_id' => 'AGENT-CONTROL-PLANE-RUNTIME-PILOT-0002',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneEvidenceLedgerDryRun.php',
                'app/Services/Ai/SelfConstruction/AgentControlPlaneContinuationSummaryBuilder.php',
            ],
            'forbidden_files' => [
                'routes/api.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneEvidenceLedgerDryRun.php',
            ],
            'acceptance_criteria' => [
                'task_packet_planned',
                'scope_lock_safe',
            ],
            'required_evidence' => [
                'task_packet_created',
                'claim_lease_simulated',
                'scope_lock_planned',
                'continuation_summary_planned',
                'cost_import_planned',
                'work_product_manifest_planned',
                'runtime_pilot_completed',
            ],
            'risk_level' => 'low',
            'max_runtime_seconds' => 1800,
            'max_token_budget' => 0,
        ];
    }
}
