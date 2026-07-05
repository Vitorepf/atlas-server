<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

/**
 * Pure policy that decides unattended queue healing actions from health
 * snapshots without starting background automation or spending tokens.
 *
 * Actions:
 *   - recoverable leases → reap_leases
 *   - malformed blockers → sweep_malformed
 *   - collision pressure → originator_pivot
 *   - healthy queue → observe
 *
 * Pure: no I/O, no side effects, no background processes, no token spend.
 */
final class AtlasSelfConstructionUnattendedQueueHealingPolicy
{
    public const SCHEMA = 'atlas.self_construction.unattended_queue_healing_policy.v1';

    public const ACTION_REAP_LEASES = 'reap_leases';
    public const ACTION_SWEEP_MALFORMED = 'sweep_malformed';
    public const ACTION_ORIGINATOR_PIVOT = 'originator_pivot';
    public const ACTION_OBSERVE = 'observe';

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function decide(array $snapshot): array
    {
        $recoverableTotal = (int) ($snapshot['recoverable_total'] ?? 0);
        $malformedBlockers = (array) ($snapshot['malformed_blockers'] ?? []);
        $collisionPressure = (string) ($snapshot['collision_pressure'] ?? 'none');
        $isHealthy = (bool) ($snapshot['healthy'] ?? false);

        $action = match (true) {
            $recoverableTotal > 0 => self::ACTION_REAP_LEASES,
            $malformedBlockers !== [] => self::ACTION_SWEEP_MALFORMED,
            $collisionPressure === 'high' || $collisionPressure === 'critical' => self::ACTION_ORIGINATOR_PIVOT,
            $isHealthy => self::ACTION_OBSERVE,
            default => self::ACTION_OBSERVE,
        };

        $reasons = [];
        if ($action === self::ACTION_REAP_LEASES) {
            $reasons[] = 'recoverable_leases:'.$recoverableTotal;
        }
        if ($action === self::ACTION_SWEEP_MALFORMED) {
            $reasons[] = 'malformed_blockers:'.count($malformedBlockers);
        }
        if ($action === self::ACTION_ORIGINATOR_PIVOT) {
            $reasons[] = 'collision_pressure:'.$collisionPressure;
        }
        if ($action === self::ACTION_OBSERVE) {
            $reasons[] = 'no_healing_action_required';
        }

        return [
            'schema_version' => self::SCHEMA,
            'action' => $action,
            'reasons' => $reasons,
            'starts_background_automation' => false,
            'spends_tokens' => false,
            'recoverable_total' => $recoverableTotal,
            'malformed_count' => count($malformedBlockers),
            'collision_pressure' => $collisionPressure,
            'healthy' => $isHealthy,
        ];
    }
}
