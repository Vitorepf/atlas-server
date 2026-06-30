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
