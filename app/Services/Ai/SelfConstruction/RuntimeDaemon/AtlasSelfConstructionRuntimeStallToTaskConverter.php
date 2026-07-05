<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Converts runtime stall classifications into concrete implementation task
 * specs with scoped service and test targets.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionRuntimeStallToTaskConverter
{
    public const SCHEMA = 'atlas.self_construction.runtime_stall_to_task_converter.v1';

    private const STALL_TASK_MAP = [
        'unsafe_stop' => [
            'objective' => 'Repair the active safety stop so the autonomy runtime can resume safely.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSafetyStopGate.php',
                'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSafetyStopGateTest.php',
            ],
            'acceptance_criteria' => ['Safety stop gate returns a clear resume/deny verdict with explicit reasons.'],
        ],
        'heartbeat_stale' => [
            'objective' => 'Restore fresh heartbeat emission for the unattended runtime.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedHeartbeatEmitter.php',
                'tests/Unit/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedHeartbeatEmitterTest.php',
            ],
            'acceptance_criteria' => ['Heartbeat emitter produces a fresh timestamp and schema envelope.'],
        ],
        'merge_blocked' => [
            'objective' => 'Unblock the autonomous merge path by resolving merge conflicts or gate failures.',
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php',
                'tests/Unit/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeServiceTest.php',
            ],
            'acceptance_criteria' => ['Merge service reports a clear blocked reason and recovery action.'],
        ],
        'verification_blocked' => [
            'objective' => 'Fix the failing verification gate or attach the missing proof demanded by the harness.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtGateReplayPlan.php',
                'tests/Unit/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtGateReplayPlanTest.php',
            ],
            'acceptance_criteria' => ['Verification replay plan lists required evidence and a rerun command.'],
        ],
        'replenisher_blocked' => [
            'objective' => 'Repair the queue replenisher so it can resume originator top-up.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicy.php',
                'tests/Unit/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicyTest.php',
            ],
            'acceptance_criteria' => ['Top-up policy emits a clear decision, batch size and reason.'],
        ],
        'worker_unavailable' => [
            'objective' => 'Restore native worker readiness or scale worker capacity.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TerminalWorkerBootstrap/AgentControlPlaneWorkerEligibilityGuard.php',
                'tests/Unit/Services/Ai/SelfConstruction/TerminalWorkerBootstrap/AgentControlPlaneWorkerEligibilityGuardTest.php',
            ],
            'acceptance_criteria' => ['Worker eligibility guard reports readiness and blocked reasons.'],
        ],
        'stale_brain_heartbeat' => [
            'objective' => 'Refresh the external brain heartbeat so quota and command loops stay alive.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernor.php',
                'tests/Unit/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernorTest.php',
            ],
            'acceptance_criteria' => ['Continuous originator governor emits a heartbeat timestamp and next action.'],
        ],
        'stalled_before_quota' => [
            'objective' => 'Unblock the brain command loop so it reaches its daily quota.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernor.php',
                'tests/Unit/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernorTest.php',
            ],
            'acceptance_criteria' => ['Governor reports quota state and a concrete next command.'],
        ],
        'temp_spec_already_done' => [
            'objective' => 'Deduplicate temporary specs so the runtime stops re-emitting done work.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainDoneSetDiversityLearner.php',
                'tests/Unit/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainDoneSetDiversityLearnerTest.php',
            ],
            'acceptance_criteria' => ['Done-set learner marks duplicate specs and suggests a novel replacement.'],
        ],
        'zero_active_brain_commands' => [
            'objective' => 'Restart brain command generation so active commands are non-zero.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernor.php',
                'tests/Unit/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainContinuousOriginatorGovernorTest.php',
            ],
            'acceptance_criteria' => ['Governor emits at least one actionable brain command.'],
        ],
        'queue_dry' => [
            'objective' => 'Originate new claimable work so the queue is no longer dry.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicy.php',
                'tests/Unit/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicyTest.php',
            ],
            'acceptance_criteria' => ['Top-up policy requests new originator tasks when the queue is dry.'],
        ],
        'waiting_on_dependencies' => [
            'objective' => 'Resolve pending dependencies so blocked tasks become claimable.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php',
                'tests/Feature/Ai/AtlasTaskCoordinationHealthTest.php',
            ],
            'acceptance_criteria' => ['Queue orchestrator releases tasks whose dependencies are satisfied.'],
        ],
        'lease_leak' => [
            'objective' => 'Repair the lease/claim mismatch by reaping stale leases.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRepository.php',
                'tests/Feature/Ai/AtlasTaskCoordinationHealthTest.php',
            ],
            'acceptance_criteria' => ['Lease repository reaps expired leases and restores lease/claim parity.'],
        ],
        'feed_starvation_risk' => [
            'objective' => 'Increase claimable depth per active worker before starvation occurs.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicy.php',
                'tests/Unit/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicyTest.php',
            ],
            'acceptance_criteria' => ['Top-up policy requests a bounded batch when claimable depth per worker is thin.'],
        ],
        'healthy' => [
            'objective' => 'No recovery task needed; continue normal operation.',
            'allowed_files' => [],
            'acceptance_criteria' => ['Runtime remains healthy.'],
        ],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function convert(array $input): array
    {
        $classification = (string) ($input['classification'] ?? 'healthy');
        $reasons = (array) ($input['reasons'] ?? []);
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $template = self::STALL_TASK_MAP[$classification] ?? self::STALL_TASK_MAP['healthy'];

        $spec = [
            'task_family' => 'runtime_stall_recovery',
            'objective' => $template['objective'],
            'allowed_files' => $template['allowed_files'],
            'acceptance_criteria' => $template['acceptance_criteria'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'stall_classification' => $classification,
            'stall_reasons' => array_values($reasons),
            'originator_id' => $originatorId,
            'round_id' => $roundId,
        ];

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'spec' => $spec,
            'runnable' => $classification !== 'healthy' && $template['allowed_files'] !== [],
        ];
    }
}
