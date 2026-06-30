<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure planner: arranges macro-tasks into ordered dependency waves so that
 * foundation, integration, verification, and simplification tasks unlock each
 * other instead of landing as an unordered bag.
 *
 * Wave assignment by task type:
 *   foundation    → 0
 *   integration   → 1
 *   verification  → 2
 *   simplification → 3
 *   (unknown)     → 99
 *
 * A blocked_edge is emitted when:
 *   - a task's depends_on capability is not in available_capabilities, OR
 *   - the task has blocked_scope=true
 *
 * A recommended_reordering is emitted for every task with missing dependencies.
 */
final class AtlasTaskFabricStrategicWaveDependencyPlanner
{
    public const SCHEMA = 'atlas.task_fabric.strategic_wave_dependency_planner.v1';

    private const WAVE_ORDER = [
        'foundation' => 0,
        'integration' => 1,
        'verification' => 2,
        'simplification' => 3,
    ];

    /**
     * @param  array<string,mixed>  $input  tasks list + available_capabilities list
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $tasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];
        $availableRaw = is_array($input['available_capabilities'] ?? null) ? $input['available_capabilities'] : [];
        $available = array_flip(array_map('strval', $availableRaw));

        $waveSlots = [];
        $dependsOnEdges = [];
        $blockedEdges = [];
        $recommendedReorderings = [];

        foreach ($tasks as $task) {
            $id = (string) ($task['task_id'] ?? '');
            $type = (string) ($task['type'] ?? 'foundation');
            $dependsOn = array_map('strval', is_array($task['depends_on'] ?? null) ? $task['depends_on'] : []);
            $blockedScope = (bool) ($task['blocked_scope'] ?? false);
            $wave = self::WAVE_ORDER[$type] ?? 99;

            $missingCaps = [];
            foreach ($dependsOn as $cap) {
                $dependsOnEdges[] = ['from' => $id, 'to' => $cap, 'type' => 'prerequisite'];
                if (! isset($available[$cap])) {
                    $missingCaps[] = $cap;
                    $blockedEdges[] = [
                        'task_id' => $id,
                        'missing_capability' => $cap,
                        'reason' => 'capability_not_implemented_or_queued',
                    ];
                }
            }

            if ($blockedScope) {
                $blockedEdges[] = [
                    'task_id' => $id,
                    'missing_capability' => null,
                    'reason' => 'forbidden_scope_blocked',
                ];
            }

            if ($missingCaps !== []) {
                $recommendedReorderings[] = [
                    'task_id' => $id,
                    'current_wave' => $wave,
                    'recommended_action' => 'defer_until_missing_capabilities_available',
                    'missing_capabilities' => $missingCaps,
                ];
            }

            $waveSlots[$wave][] = [
                'task_id' => $id,
                'type' => $type,
                'blocked' => $missingCaps !== [] || $blockedScope,
            ];
        }

        ksort($waveSlots);
        $waves = [];
        foreach ($waveSlots as $num => $waveTasks) {
            $waves[] = ['wave' => $num, 'tasks' => $waveTasks];
        }

        return [
            'schema_version' => self::SCHEMA,
            'waves' => $waves,
            'depends_on_edges' => $dependsOnEdges,
            'blocked_edges' => $blockedEdges,
            'recommended_reorderings' => $recommendedReorderings,
        ];
    }
}
