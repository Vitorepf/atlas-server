<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure governor for continuous originator cycles with explicit continue, pivot
 * and stop facts so sufficient_depth cannot be mistaken for completion.
 *
 * Decisions:
 *   - sufficient_depth → continue_with_smaller_batch (never stop)
 *   - repeated_vein_failure → pivot
 *   - quota_reached → stop
 *   - default → continue
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainContinuousOriginatorGovernor
{
    public const SCHEMA = 'atlas.external_brain.continuous_originator_governor.v1';

    public const DECISION_CONTINUE = 'continue';

    public const DECISION_CONTINUE_WITH_SMALLER_BATCH = 'continue_with_smaller_batch';

    public const DECISION_PIVOT = 'pivot';

    public const DECISION_STOP = 'stop';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function govern(array $input): array
    {
        $claimableDepth = (int) ($input['claimable_depth'] ?? 0);
        $activeWorkers = (int) ($input['active_workers'] ?? 0);
        $repeatedVeinFailures = (int) ($input['repeated_vein_failures'] ?? 0);
        $quotaReached = (bool) ($input['quota_reached'] ?? false);
        $veinFailureThreshold = (int) ($input['vein_failure_threshold'] ?? 3);

        $decision = match (true) {
            $quotaReached => self::DECISION_STOP,
            $repeatedVeinFailures >= $veinFailureThreshold => self::DECISION_PIVOT,
            $claimableDepth > 0 && $activeWorkers > 0 => self::DECISION_CONTINUE_WITH_SMALLER_BATCH,
            default => self::DECISION_CONTINUE,
        };

        $reasons = [];
        if ($decision === self::DECISION_STOP) {
            $reasons[] = 'quota_reached';
        }
        if ($decision === self::DECISION_PIVOT) {
            $reasons[] = 'repeated_vein_failures:'.$repeatedVeinFailures;
        }
        if ($decision === self::DECISION_CONTINUE_WITH_SMALLER_BATCH) {
            $reasons[] = 'sufficient_depth_not_completion';
        }
        if ($decision === self::DECISION_CONTINUE) {
            $reasons[] = 'no_blocking_condition';
        }

        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
            'claimable_depth' => $claimableDepth,
            'active_workers' => $activeWorkers,
            'repeated_vein_failures' => $repeatedVeinFailures,
            'quota_reached' => $quotaReached,
            'is_completion' => false, // sufficient_depth is never completion
        ];
    }
}
