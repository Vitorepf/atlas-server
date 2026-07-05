<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure reporter that reports seed credits after enqueue using actual
 * prepared_and_enqueued events plus post-round health and collision checks.
 *
 * Credit is granted ONLY when:
 *   - event = 'prepared_and_enqueued'
 *   - post_round_health = 'healthy'
 *   - no enqueue conflict
 *   - no emitted-target collision (distinct from global pre-existing collisions)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskServingPostEnqueueCreditReporter
{
    public const SCHEMA = 'atlas.task_serving.post_enqueue_credit_reporter.v1';

    public const VERDICT_CREDITED = 'credited';
    public const VERDICT_DENIED = 'denied';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function report(array $input): array
    {
        $event = (string) ($input['event'] ?? '');
        $postRoundHealth = (string) ($input['post_round_health'] ?? '');
        $enqueueConflict = (bool) ($input['enqueue_conflict'] ?? false);
        $emittedTargetCollisions = (array) ($input['emitted_target_collisions'] ?? []);
        $globalPreExistingCollisions = (array) ($input['global_pre_existing_collisions'] ?? []);
        $emittedTargets = (array) ($input['emitted_targets'] ?? []);
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $denialReasons = [];

        if ($event !== 'prepared_and_enqueued') {
            $denialReasons[] = 'event_not_prepared_and_enqueued:'.$event;
        }

        if ($postRoundHealth !== 'healthy') {
            $denialReasons[] = 'post_round_health_not_healthy:'.$postRoundHealth;
        }

        if ($enqueueConflict) {
            $denialReasons[] = 'enqueue_conflict_detected';
        }

        // Check for emitted-target collisions (distinct from global pre-existing).
        $collidingEmittedTargets = [];
        foreach ($emittedTargetCollisions as $collision) {
            $target = is_array($collision) ? (string) ($collision['target_family'] ?? '') : (string) $collision;
            if ($target !== '' && in_array($target, $emittedTargets, true)) {
                $collidingEmittedTargets[] = $target;
            }
        }
        if ($collidingEmittedTargets !== []) {
            $denialReasons[] = 'emitted_target_collision:'.implode(',', array_unique($collidingEmittedTargets));
        }

        $credited = $denialReasons === [];

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'verdict' => $credited ? self::VERDICT_CREDITED : self::VERDICT_DENIED,
            'credits_granted' => $credited ? 1 : 0,
            'denial_reasons' => $denialReasons,
            'event' => $event,
            'post_round_health' => $postRoundHealth,
            'enqueue_conflict' => $enqueueConflict,
            'emitted_target_collisions' => array_values(array_unique($collidingEmittedTargets)),
            'global_pre_existing_collisions' => $globalPreExistingCollisions,
        ];
    }
}
