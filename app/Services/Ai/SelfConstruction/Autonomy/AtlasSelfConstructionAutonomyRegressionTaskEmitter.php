<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Emits focused repair task specs when autonomy regressions appear in health,
 * outcome or readiness snapshots.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionAutonomyRegressionTaskEmitter
{
    public const SCHEMA = 'atlas.self_construction.autonomy.regression_task_emitter.v1';

    private const REGRESSION_CATALOG = [
        'health_not_healthy' => [
            'task_family' => 'autonomy_health_repair',
            'objective' => 'Restore autonomy health to healthy status.',
            'service' => 'AtlasSelfConstructionAutonomyStopGoGovernor',
            'test' => 'AtlasSelfConstructionAutonomyStopGoGovernorTest',
            'path' => 'Autonomy',
        ],
        'high_give_back_rate' => [
            'task_family' => 'give_back_repair',
            'objective' => 'Reduce give-back rate by improving task packet quality.',
            'service' => 'AtlasTaskPacketQualityInspector',
            'test' => 'AtlasTaskPacketQualityInspectorTest',
            'path' => '',
        ],
        'queue_dry' => [
            'task_family' => 'queue_top_up_repair',
            'objective' => 'Replenish the queue so workers have claimable tasks.',
            'service' => 'AtlasSelfConstructionQueueTopUpPolicy',
            'test' => 'AtlasSelfConstructionQueueTopUpPolicyTest',
            'path' => 'Replenisher',
        ],
        'lease_leak_detected' => [
            'task_family' => 'lease_leak_repair',
            'objective' => 'Repair lease/claim mismatch by reaping stale leases.',
            'service' => 'AgentControlPlaneClaimLeaseRepository',
            'test' => 'AtlasTaskCoordinationHealthTest',
            'path' => '',
        ],
        'malformed_blockers_present' => [
            'task_family' => 'malformed_sweep_repair',
            'objective' => 'Sweep malformed packets and repair their root causes.',
            'service' => 'AgentControlPlaneTaskQueueOrchestrator',
            'test' => 'AtlasTaskCoordinationHealthTest',
            'path' => '',
        ],
        'stale_proof_detected' => [
            'task_family' => 'proof_refresh_repair',
            'objective' => 'Refresh stale implementation proof and verification evidence.',
            'service' => 'AtlasExternalBrainImplementationProofDemand',
            'test' => 'AtlasExternalBrainImplementationProofDemandTest',
            'path' => 'ExternalBrain',
        ],
        'sensing_degraded' => [
            'task_family' => 'sensing_repair',
            'objective' => 'Restore degraded autonomy sensing.',
            'service' => 'AtlasAutonomousRuntimeSafetyStopGate',
            'test' => 'AtlasAutonomousRuntimeSafetyStopGateTest',
            'path' => 'AutonomousRuntime',
        ],
        'worker_unavailable' => [
            'task_family' => 'worker_readiness_repair',
            'objective' => 'Restore native worker readiness.',
            'service' => 'AgentControlPlaneWorkerEligibilityGuard',
            'test' => 'AgentControlPlaneWorkerEligibilityGuardTest',
            'path' => 'TerminalWorkerBootstrap',
        ],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function emit(array $input): array
    {
        $regressions = is_array($input['regressions'] ?? null) ? $input['regressions'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $tasks = [];
        $seen = [];

        foreach ($regressions as $regression) {
            if (! is_array($regression)) {
                continue;
            }

            $kind = (string) ($regression['kind'] ?? '');
            if (! isset(self::REGRESSION_CATALOG[$kind])) {
                continue;
            }

            $catalog = self::REGRESSION_CATALOG[$kind];
            $key = $catalog['task_family'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $path = $catalog['path'] !== '' ? $catalog['path'].'/' : '';
            $serviceFile = "app/Services/Ai/SelfConstruction/{$path}{$catalog['service']}.php";
            $testPath = str_starts_with($catalog['test'], 'Atlas') && str_ends_with($catalog['test'], 'Test')
                && ! str_starts_with($catalog['test'], 'AtlasTaskCoordination')
                ? "tests/Unit/Services/Ai/SelfConstruction/{$path}{$catalog['test']}.php"
                : "tests/Feature/Ai/{$catalog['test']}.php";

            $tasks[] = [
                'task_family' => $catalog['task_family'],
                'objective' => $catalog['objective'],
                'allowed_files' => [$serviceFile, $testPath],
                'acceptance_criteria' => [
                    "Implement or repair {$catalog['service']} to resolve the regression.",
                    "Run the corresponding test to prove the fix.",
                ],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
                'regression_kind' => $kind,
                'regression_reason' => (string) ($regression['reason'] ?? 'regression detected'),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'regression_count' => count($regressions),
        ];
    }
}
