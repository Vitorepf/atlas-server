<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Collision-pressure backoff policy that advises the originator to
 * pivot target families when queued-target collision_count is already
 * nonzero before a round starts.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasMaestroCollisionPressureBackoffPolicy
{
    public const SCHEMA = 'atlas.self_construction.maestro_collision_pressure_backoff_policy.v1';

    public const ACTION_PROCEED = 'proceed';
    public const ACTION_PIVOT = 'pivot';
    public const ACTION_BACKOFF = 'backoff';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $preExistingCollisions = (int) ($input['pre_existing_collision_count'] ?? 0);
        $newEmittedCollisions = (int) ($input['new_emitted_collision_count'] ?? 0);
        $collisionTargets = (array) ($input['collision_targets'] ?? []);

        $action = self::ACTION_PROCEED;
        $reasons = [];

        if ($preExistingCollisions > 0) {
            $action = self::ACTION_PIVOT;
            $reasons[] = 'pre_existing_collisions:'.$preExistingCollisions;
        }

        if ($newEmittedCollisions > 0) {
            $action = self::ACTION_BACKOFF;
            $reasons[] = 'new_emitted_collisions:'.$newEmittedCollisions;
        }

        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reasons' => $reasons,
            'pre_existing_collision_count' => $preExistingCollisions,
            'new_emitted_collision_count' => $newEmittedCollisions,
            'collision_targets' => $collisionTargets,
            'should_pivot' => $action === self::ACTION_PIVOT || $action === self::ACTION_BACKOFF,
            'blames_new_targets' => $newEmittedCollisions > 0 && $preExistingCollisions === 0,
        ];
    }
}
