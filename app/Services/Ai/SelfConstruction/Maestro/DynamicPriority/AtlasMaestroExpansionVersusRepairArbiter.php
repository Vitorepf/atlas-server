<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\DynamicPriority;

/**
 * Pure arbiter that decides whether the next round should expand capability
 * or repair existing queue damage from health, collision and outcome facts.
 *
 * Decisions:
 *   - Malformed or new collision facts → repair
 *   - Clean high-drain facts → expansion
 *   - Mixed facts → consolidation
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroExpansionVersusRepairArbiter
{
    public const SCHEMA = 'atlas.maestro.expansion_versus_repair_arbiter.v1';

    public const DECISION_REPAIR = 'repair';
    public const DECISION_EXPANSION = 'expansion';
    public const DECISION_CONSOLIDATION = 'consolidation';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function arbitrate(array $input): array
    {
        $healthStatus = (string) ($input['health_status'] ?? 'unknown');
        $malformedBlockers = (array) ($input['malformed_blockers'] ?? []);
        $newCollisions = (array) ($input['new_collisions'] ?? []);
        $giveBackRate = (float) ($input['give_back_rate'] ?? 0.0);
        $successRate = (float) ($input['success_rate'] ?? 0.0);
        $drainRate = (float) ($input['drain_rate'] ?? 0.0);

        $hasMalformed = $malformedBlockers !== [];
        $hasNewCollisions = $newCollisions !== [];
        $isHealthy = $healthStatus === 'healthy';
        $isHighDrain = $drainRate > 0.5;
        $isHighSuccess = $successRate > 0.7;
        $isHighGiveBack = $giveBackRate > 0.3;

        $decision = match (true) {
            $hasMalformed || $hasNewCollisions => self::DECISION_REPAIR,
            $isHealthy && $isHighDrain && $isHighSuccess && ! $isHighGiveBack => self::DECISION_EXPANSION,
            $isHighGiveBack || (! $isHealthy && ! $hasMalformed) => self::DECISION_CONSOLIDATION,
            default => self::DECISION_CONSOLIDATION,
        };

        $reasons = [];
        if ($decision === self::DECISION_REPAIR) {
            if ($hasMalformed) {
                $reasons[] = 'malformed_blockers:'.count($malformedBlockers);
            }
            if ($hasNewCollisions) {
                $reasons[] = 'new_collisions:'.count($newCollisions);
            }
        }
        if ($decision === self::DECISION_EXPANSION) {
            $reasons[] = 'healthy_high_drain_high_success';
        }
        if ($decision === self::DECISION_CONSOLIDATION) {
            $reasons[] = 'mixed_facts_consolidate';
        }

        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
            'health_status' => $healthStatus,
            'has_malformed' => $hasMalformed,
            'has_new_collisions' => $hasNewCollisions,
            'give_back_rate' => round($giveBackRate, 3),
            'success_rate' => round($successRate, 3),
            'drain_rate' => round($drainRate, 3),
        ];
    }
}
