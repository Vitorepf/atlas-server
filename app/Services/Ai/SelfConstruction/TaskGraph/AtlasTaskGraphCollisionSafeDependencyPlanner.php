<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Pure planner that plans dependencies only across non-colliding targets so
 * parallel muscles do not fight over the same allowed_files.
 *
 * Rules:
 *   - Same target dependencies → serialize (must run sequentially)
 *   - Disjoint targets → parallelize (can run in parallel)
 *   - Collision summaries → force pivot (do not plan)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskGraphCollisionSafeDependencyPlanner
{
    public const SCHEMA = 'atlas.self_construction.task_graph_collision_safe_dependency_planner.v1';

    public const PLAN_SERIALIZE = 'serialize';
    public const PLAN_PARALLELIZE = 'parallelize';
    public const PLAN_PIVOT = 'pivot';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $tasks = (array) ($input['tasks'] ?? []);
        $collisionSummaries = (array) ($input['collision_summaries'] ?? []);

        // If there are collision summaries, force pivot.
        if ($collisionSummaries !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'plan' => self::PLAN_PIVOT,
                'reasons' => ['collision_summaries_present:'.count($collisionSummaries)],
                'serialized_groups' => [],
                'parallelized_groups' => [],
                'total_tasks' => count($tasks),
            ];
        }

        // Group tasks by target_family.
        $byTarget = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $targetFamily = (string) ($task['target_family'] ?? '');
            $taskId = (string) ($task['task_id'] ?? '');
            if ($targetFamily === '' || $taskId === '') {
                continue;
            }
            $byTarget[$targetFamily][] = $taskId;
        }

        $serializedGroups = [];
        $parallelizedGroups = [];

        foreach ($byTarget as $targetFamily => $taskIds) {
            if (count($taskIds) > 1) {
                // Same target → serialize.
                $serializedGroups[] = [
                    'target_family' => $targetFamily,
                    'task_ids' => $taskIds,
                    'plan' => self::PLAN_SERIALIZE,
                ];
            } else {
                // Single task → can parallelize.
                $parallelizedGroups[] = [
                    'target_family' => $targetFamily,
                    'task_ids' => $taskIds,
                    'plan' => self::PLAN_PARALLELIZE,
                ];
            }
        }

        // Sort deterministically.
        usort($serializedGroups, static fn (array $a, array $b): int => strcmp($a['target_family'], $b['target_family']));
        usort($parallelizedGroups, static fn (array $a, array $b): int => strcmp($a['target_family'], $b['target_family']));

        $plan = $serializedGroups !== [] ? self::PLAN_SERIALIZE : self::PLAN_PARALLELIZE;
        $reasons = [];
        if ($serializedGroups !== []) {
            $reasons[] = 'same_target_dependencies_serialize:'.count($serializedGroups);
        }
        if ($parallelizedGroups !== []) {
            $reasons[] = 'disjoint_targets_parallelize:'.count($parallelizedGroups);
        }

        return [
            'schema_version' => self::SCHEMA,
            'plan' => $plan,
            'reasons' => $reasons,
            'serialized_groups' => $serializedGroups,
            'parallelized_groups' => $parallelizedGroups,
            'total_tasks' => count($tasks),
        ];
    }
}
