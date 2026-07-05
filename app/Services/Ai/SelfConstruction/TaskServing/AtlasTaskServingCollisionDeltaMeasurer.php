<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Measures collision delta caused by a round instead of flattening
 * pre-existing queued-target collisions into new seed failure.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskServingCollisionDeltaMeasurer
{
    public const SCHEMA = 'atlas.self_construction.task_serving_collision_delta_measurer.v1';

    /**
     * @param  array<string>  $baselineCollisions
     * @param  array<string>  $postRoundCollisions
     * @param  array<string>  $emittedTargets
     * @return array<string, mixed>
     */
    public function measure(array $baselineCollisions, array $postRoundCollisions, array $emittedTargets = []): array
    {
        $baselineSet = array_flip($baselineCollisions);
        $postSet = array_flip($postRoundCollisions);
        $emittedSet = array_flip($emittedTargets);

        // New collisions introduced by this round
        $newCollisions = array_keys(array_diff_key($postSet, $baselineSet));

        // Collisions on emitted targets specifically
        $emittedCollisions = array_keys(array_intersect_key($emittedSet, $postSet));

        // Pre-existing collisions that are still present
        $carriedBaseline = array_keys(array_intersect_key($baselineSet, $postSet));

        // Unrelated seeds: emitted targets that don't collide
        $cleanEmitted = array_keys(array_diff_key($emittedSet, $postSet));

        return [
            'schema' => self::SCHEMA,
            'baseline_collision_count' => count($baselineCollisions),
            'post_round_collision_count' => count($postRoundCollisions),
            'new_collision_count' => count($newCollisions),
            'new_collisions' => $newCollisions,
            'emitted_collision_count' => count($emittedCollisions),
            'emitted_collisions' => $emittedCollisions,
            'carried_baseline_collisions' => $carriedBaseline,
            'clean_emitted_targets' => $cleanEmitted,
            'round_caused_collisions' => count($newCollisions) > 0,
            'unrelated_seeds_invalidated' => false,
        ];
    }
}
