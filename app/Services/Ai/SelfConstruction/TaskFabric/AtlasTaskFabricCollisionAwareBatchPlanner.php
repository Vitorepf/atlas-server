<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Plans Task Fabric batches around queued-target collision pressure
 * so new macro-tasks pivot to non-conflicting implementation organs
 * before enqueue.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskFabricCollisionAwareBatchPlanner
{
    public const SCHEMA = 'atlas.self_construction.task_fabric_collision_aware_batch_planner.v1';

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string>  $collidingTargets
     * @return array<string, mixed>
     */
    public function plan(array $candidates, array $collidingTargets = []): array
    {
        $collidingSet = array_values(array_unique(array_map('strval', $collidingTargets)));
        $collidingLookup = array_flip($collidingSet);

        $admitted = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['id'] ?? '');
            $allowedFiles = (array) ($candidate['allowed_files'] ?? []);

            $conflicting = [];
            foreach ($allowedFiles as $file) {
                $file = (string) $file;
                if (isset($collidingLookup[$file])) {
                    $conflicting[] = $file;
                }
            }

            if ($conflicting !== []) {
                $skipped[] = [
                    'id' => $id,
                    'pivot_reason' => 'target_collision',
                    'conflicting_targets' => $conflicting,
                ];
            } else {
                $admitted[] = $candidate;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'admitted' => $admitted,
            'admitted_count' => count($admitted),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
            'colliding_targets' => $collidingSet,
            'colliding_target_count' => count($collidingSet),
        ];
    }
}
