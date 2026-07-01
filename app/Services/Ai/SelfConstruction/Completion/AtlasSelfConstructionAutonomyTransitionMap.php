<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Pure mapper. Converts each remaining BOOTSTRAP/STEADY-STATE dependency surfaced by
 * AtlasSelfConstructionAutonomyDependencyAudit into a concrete Atlas-native replacement capability
 * (replenisher / worker / verifier / rollback / learning_transfer / context_freshness) tied to an
 * owning organ + a self-sufficient task-fabric action.
 *
 * Output: {schema_version, replacements:list<{step_id, owning_organ, replacement_capability,
 *           task_fabric_action}>, untransitioned:list<{step_id, reason}>}
 *
 * A CLEAN audit (no blockers, no steady_state_dependencies) yields an EMPTY replacements list.
 */
final class AtlasSelfConstructionAutonomyTransitionMap
{
    public const SCHEMA = 'atlas.self_construction.autonomy_transition_map.v1';

    public const CAPABILITY_REPLENISHER = 'replenisher';

    public const CAPABILITY_WORKER = 'native_worker';

    public const CAPABILITY_VERIFIER = 'verifier';

    public const CAPABILITY_ROLLBACK = 'rollback';

    public const CAPABILITY_LEARNING_TRANSFER = 'learning_transfer';

    public const CAPABILITY_CONTEXT_FRESHNESS = 'context_freshness';

    /**
     * Maps step_id substrings to (owning_organ, replacement_capability, task_fabric_action).
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    private const STEP_PATTERNS = [
        'observe' => ['cortex', self::CAPABILITY_CONTEXT_FRESHNESS, 'create_task_packets:refresh_context_pack'],
        'replenish' => ['task_fabric', self::CAPABILITY_REPLENISHER, 'create_task_packets:replenish_queue'],
        'verify' => ['verification_court', self::CAPABILITY_VERIFIER, 'create_task_packets:run_verification'],
        'review' => ['verification_court', self::CAPABILITY_VERIFIER, 'create_task_packets:run_verification'],
        'merge' => ['merge_governor', self::CAPABILITY_WORKER, 'create_task_packets:prepare_merge'],
        'rollback' => ['merge_governor', self::CAPABILITY_ROLLBACK, 'create_task_packets:run_rollback'],
        'learn' => ['learning_transfer', self::CAPABILITY_LEARNING_TRANSFER, 'create_task_packets:record_lesson'],
        'context' => ['cortex', self::CAPABILITY_CONTEXT_FRESHNESS, 'create_task_packets:refresh_context_pack'],
        'worker' => ['worker_swarm', self::CAPABILITY_WORKER, 'create_task_packets:schedule_native_worker'],
    ];

    /**
     * @param  array<string,mixed>  $auditVerdict  output of AtlasSelfConstructionAutonomyDependencyAudit::audit()
     * @return array<string,mixed>
     */
    public function transition(array $auditVerdict): array
    {
        $deps = (array) ($auditVerdict['steady_state_dependencies'] ?? []);
        $replacements = [];
        $untransitioned = [];

        foreach ($deps as $dep) {
            if (! is_array($dep)) {
                continue;
            }
            $stepId = strtolower((string) ($dep['step_id'] ?? ''));
            $route = $this->routeFor($stepId);
            if ($route === null) {
                $untransitioned[] = ['step_id' => $stepId, 'reason' => 'no_known_atlas_native_replacement'];

                continue;
            }
            [$organ, $capability, $action] = $route;
            $replacements[] = [
                'step_id' => $stepId,
                'owning_organ' => $organ,
                'replacement_capability' => $capability,
                'task_fabric_action' => $action,
                'task_seed' => [
                    'objective_hint' => 'Implement '.$capability.' for '.$stepId.' owned by '.$organ,
                    'required_capability' => $capability,
                    'acceptance_hint' => 'atlas native '.$action.' executes without provider dependency',
                    'evidence_hint' => 'evidence_kind=capability_demonstration',
                ],
            ];
        }

        // Deterministic order by step_id ASC.
        usort($replacements, static fn (array $a, array $b): int => strcmp($a['step_id'], $b['step_id']));
        usort($untransitioned, static fn (array $a, array $b): int => strcmp($a['step_id'], $b['step_id']));

        return [
            'schema_version' => self::SCHEMA,
            'replacements' => $replacements,
            'untransitioned' => $untransitioned,
        ];
    }

    public const ALL_LANES = [
        self::CAPABILITY_REPLENISHER,
        self::CAPABILITY_WORKER,
        self::CAPABILITY_VERIFIER,
        self::CAPABILITY_ROLLBACK,
        self::CAPABILITY_LEARNING_TRANSFER,
        self::CAPABILITY_CONTEXT_FRESHNESS,
    ];

    private const LANE_DEFAULT_NEXT_STEP = [
        self::CAPABILITY_REPLENISHER => 'create_task_packets:prove_replenisher_capability_demonstration',
        self::CAPABILITY_WORKER => 'create_task_packets:prove_native_worker_capability_demonstration',
        self::CAPABILITY_VERIFIER => 'create_task_packets:prove_verifier_capability_demonstration',
        self::CAPABILITY_ROLLBACK => 'create_task_packets:prove_rollback_capability_demonstration',
        self::CAPABILITY_LEARNING_TRANSFER => 'create_task_packets:prove_learning_transfer_capability_demonstration',
        self::CAPABILITY_CONTEXT_FRESHNESS => 'create_task_packets:prove_context_freshness_capability_demonstration',
    ];

    /**
     * Maps every required Atlas-native capability lane (replenisher, native_worker, verifier,
     * rollback, learning_transfer, context_freshness) to a status -- lanes are never omitted.
     *
     * A lane is:
     *   - ready:    no steady-state dependency routes to it AND lane_evidence supplies a
     *               non-empty evidence_ref -- carries evidence_ref, never replacement_needed.
     *   - partial:  a steady-state dependency routes to it AND an evidence_ref is supplied
     *               (evidence exists but the gap is not yet closed).
     *   - missing:  a steady-state dependency routes to it with no evidence_ref, OR no
     *               dependency routes to it and no evidence_ref is supplied either (untested
     *               lane; still requires a concrete next step to prove readiness).
     *
     * @param  array<string,mixed>  $auditVerdict  output of AtlasSelfConstructionAutonomyDependencyAudit::audit(),
     *                                              optionally carrying lane_evidence:array<string,string>
     * @return array<string,array<string,mixed>>
     */
    public function laneReadinessMap(array $auditVerdict): array
    {
        $deps = (array) ($auditVerdict['steady_state_dependencies'] ?? []);
        $laneEvidence = (array) ($auditVerdict['lane_evidence'] ?? []);

        $depsByLane = [];
        foreach ($deps as $dep) {
            if (! is_array($dep)) {
                continue;
            }
            $stepId = strtolower((string) ($dep['step_id'] ?? ''));
            $route = $this->routeFor($stepId);
            if ($route === null) {
                continue;
            }
            [, $capability, $action] = $route;
            $depsByLane[$capability][] = ['step_id' => $stepId, 'task_fabric_action' => $action];
        }

        $laneMap = [];
        foreach (self::ALL_LANES as $lane) {
            $evidenceRef = trim((string) ($laneEvidence[$lane] ?? ''));
            $laneDeps = $depsByLane[$lane] ?? [];

            if ($laneDeps === [] && $evidenceRef !== '') {
                $laneMap[$lane] = [
                    'status' => 'ready',
                    'evidence_ref' => $evidenceRef,
                ];

                continue;
            }

            $nextStep = $laneDeps !== []
                ? $laneDeps[0]['task_fabric_action']
                : self::LANE_DEFAULT_NEXT_STEP[$lane];

            $laneMap[$lane] = [
                'status' => $laneDeps !== [] && $evidenceRef !== '' ? 'partial' : 'missing',
                'next_step' => $nextStep,
                'replacement_needed' => true,
            ];
            if ($evidenceRef !== '') {
                $laneMap[$lane]['evidence_ref'] = $evidenceRef;
            }
        }

        return $laneMap;
    }

    /**
     * @return array{0:string, 1:string, 2:string}|null
     */
    private function routeFor(string $stepId): ?array
    {
        foreach (self::STEP_PATTERNS as $needle => $route) {
            if ($needle !== '' && str_contains($stepId, $needle)) {
                return $route;
            }
        }

        return null;
    }
}
