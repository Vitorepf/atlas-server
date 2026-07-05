<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Derives seed budgets from worker drain rate and active leases so
 * originator output matches consumption without stopping on
 * sufficient_depth.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasMaestroWorkerDrainToSeedBudgetModel
{
    public const SCHEMA = 'atlas.self_construction.maestro_worker_drain_to_seed_budget.v1';

    public const MAX_SEEDS = 12;
    public const MIN_SEEDS = 1;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function derive(array $input): array
    {
        $claimableDepth = (int) ($input['claimable_depth'] ?? 0);
        $activeLeases = (int) ($input['active_leases'] ?? 0);
        $drainRate = (float) ($input['drain_rate_per_hour'] ?? 0.0);
        $workerCount = (int) ($input['worker_count'] ?? 1);

        // Dry queue requests maximum seeds
        if ($claimableDepth === 0) {
            $budget = self::MAX_SEEDS;
            $reason = 'dry_queue_max_seeds';
        } elseif ($claimableDepth >= 20) {
            // Sufficient depth requests bounded seeds
            $budget = max(self::MIN_SEEDS, min(3, (int) ceil($drainRate / 2)));
            $reason = 'sufficient_depth_bounded';
        } else {
            // Active drain increases budget
            $drainBudget = (int) ceil($drainRate * $workerCount);
            $budget = max(self::MIN_SEEDS, min(self::MAX_SEEDS, $drainBudget));
            $reason = 'drain_based_budget';
        }

        return [
            'schema' => self::SCHEMA,
            'seed_budget' => $budget,
            'reason' => $reason,
            'claimable_depth' => $claimableDepth,
            'active_leases' => $activeLeases,
            'drain_rate_per_hour' => $drainRate,
            'worker_count' => $workerCount,
            'max_seeds' => self::MAX_SEEDS,
            'min_seeds' => self::MIN_SEEDS,
        ];
    }
}
