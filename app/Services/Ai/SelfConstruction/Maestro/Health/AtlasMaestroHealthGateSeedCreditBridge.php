<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure bridge that connects Maestro queue health facts into originator
 * seed-credit decisions so health.healthy, malformed zero and collision-free
 * emitted targets become explicit credit gates.
 *
 * Credit is granted ONLY when ALL of these pass:
 *   - health.healthy = true
 *   - malformed_blockers = [] (zero malformed)
 *   - no emitted-target collision
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroHealthGateSeedCreditBridge
{
    public const SCHEMA = 'atlas.maestro.health_gate_seed_credit_bridge.v1';

    public const VERDICT_CREDITED = 'credited';
    public const VERDICT_DENIED = 'denied';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function bridge(array $input): array
    {
        $health = is_array($input['health'] ?? null) ? $input['health'] : [];
        $malformedBlockers = (array) ($input['malformed_blockers'] ?? []);
        $emittedTargets = (array) ($input['emitted_targets'] ?? []);
        $targetCollisions = (array) ($input['target_collisions'] ?? []);
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $isHealthy = (bool) ($health['healthy'] ?? false);

        $denialReasons = [];

        if (! $isHealthy) {
            $denialReasons[] = 'health_not_healthy:'.(string) ($health['status'] ?? 'unknown');
        }

        if ($malformedBlockers !== []) {
            $denialReasons[] = 'malformed_blockers_present:'.count($malformedBlockers);
        }

        // Check for emitted-target collisions.
        $collidingTargets = [];
        foreach ($targetCollisions as $collision) {
            $target = is_array($collision) ? (string) ($collision['target_family'] ?? '') : (string) $collision;
            if ($target !== '' && in_array($target, $emittedTargets, true)) {
                $collidingTargets[] = $target;
            }
        }
        if ($collidingTargets !== []) {
            $denialReasons[] = 'emitted_target_collision:'.implode(',', array_unique($collidingTargets));
        }

        $credited = $denialReasons === [];

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'verdict' => $credited ? self::VERDICT_CREDITED : self::VERDICT_DENIED,
            'credits_granted' => $credited ? 1 : 0,
            'denial_reasons' => $denialReasons,
            'health_healthy' => $isHealthy,
            'malformed_count' => count($malformedBlockers),
            'colliding_targets' => array_values(array_unique($collidingTargets)),
        ];
    }
}
