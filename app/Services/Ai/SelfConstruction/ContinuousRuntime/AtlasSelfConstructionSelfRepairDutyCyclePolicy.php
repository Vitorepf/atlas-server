<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Balances self-repair, replenishment and consolidation duty cycles
 * from real queue pressure and outcome quality.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionSelfRepairDutyCyclePolicy
{
    public const SCHEMA = 'atlas.self_construction.self_repair_duty_cycle_policy.v1';

    public const DUTY_REPAIR = 'repair';
    public const DUTY_REPLENISH = 'replenish';
    public const DUTY_CONSOLIDATE = 'consolidate';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $malformedCount = (int) ($input['malformed_count'] ?? 0);
        $claimableDepth = (int) ($input['claimable_depth'] ?? 10);
        $outcomeQuality = (float) ($input['outcome_quality'] ?? 0.8);
        $queuePressure = (string) ($input['queue_pressure'] ?? 'normal');

        $duty = self::DUTY_REPLENISH;
        $reasons = [];

        // Malformed pressure favors repair
        if ($malformedCount > 0) {
            $duty = self::DUTY_REPAIR;
            $reasons[] = 'malformed_pressure:'.$malformedCount;
        } elseif ($claimableDepth > 20 && $outcomeQuality > 0.7) {
            // Excessive claimable depth favors consolidation
            $duty = self::DUTY_CONSOLIDATE;
            $reasons[] = 'excessive_depth:'.$claimableDepth;
        } elseif ($claimableDepth === 0 || $queuePressure === 'dry') {
            // Dry queue favors replenishment
            $duty = self::DUTY_REPLENISH;
            $reasons[] = 'dry_queue';
        } else {
            $duty = self::DUTY_REPLENISH;
            $reasons[] = 'default';
        }

        return [
            'schema' => self::SCHEMA,
            'duty' => $duty,
            'reasons' => $reasons,
            'malformed_count' => $malformedCount,
            'claimable_depth' => $claimableDepth,
            'outcome_quality' => $outcomeQuality,
            'queue_pressure' => $queuePressure,
        ];
    }
}
