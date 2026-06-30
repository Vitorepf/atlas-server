<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

/**
 * Pure policy. Decides how many muscle workers should run concurrently.
 *
 * Prevents BOTH starvation (too few workers while queue is deep) and chaotic
 * over-parallelism (thrashing via give_backs, lock contention, or low quality).
 *
 * Inputs: queue_depth, servable_count, active_leases, conflict_free_scope_ratio,
 *         lock_contention, give_back_rate, worker_quality_scores, poison_pressure.
 *
 * Outputs: recommended_parallelism (≥1), target_change, spawn_allowed, throttle_reasons,
 *          add_worker_reasons.
 *
 * Throttle wins over add: if any throttle condition is met, the recommendation
 * shrinks even if there are many claimable tasks.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroParallelMuscleCoordinationPolicy
{
    public const SCHEMA = 'atlas.maestro.concurrency.parallel_muscle_coordination_policy.v1';

    // Hard ceiling for parallelism (prevents runaway spawning).
    private const MAX_PARALLELISM = 8;
    private const MIN_PARALLELISM = 1;

    // Throttle thresholds.
    private const GIVE_BACK_RATE_THRESHOLD        = 0.30;
    private const LOCK_CONTENTION_THRESHOLD       = 0.25;
    private const AVG_QUALITY_MINIMUM             = 6.0;
    private const DISJOINT_SCOPE_LOW_THRESHOLD    = 0.50;

    // Add-worker thresholds.
    private const DISJOINT_SCOPE_HIGH_THRESHOLD   = 0.75;
    private const QUALITY_HIGH_THRESHOLD          = 8.0;
    private const POISON_PRESSURE_THRESHOLD       = 0.20;

    // Each active throttle condition reduces the base by this fraction.
    private const THROTTLE_REDUCTION_FRACTION = 0.25;

    /**
     * @param  array{
     *   queue_depth?: int,
     *   servable_count?: int,
     *   active_leases?: int,
     *   conflict_free_scope_ratio?: float,
     *   lock_contention?: float,
     *   give_back_rate?: float,
     *   worker_quality_scores?: list<float>,
     *   poison_pressure?: float,
     * }  $signals
     * @return array{
     *   schema: string,
     *   recommended_parallelism: int,
     *   target_change: int,
     *   spawn_allowed: bool,
     *   throttle_reasons: list<string>,
     *   add_worker_reasons: list<string>,
     *   signals_used: array<string,mixed>,
     * }
     */
    public function recommend(array $signals): array
    {
        $queueDepth           = max(0, (int)   ($signals['queue_depth']               ?? 0));
        $servableCount        = max(0, (int)   ($signals['servable_count']            ?? 0));
        $activeLeases         = max(0, (int)   ($signals['active_leases']             ?? 0));
        $conflictFreeRatio    = max(0.0, min(1.0, (float) ($signals['conflict_free_scope_ratio'] ?? 1.0)));
        $lockContention       = max(0.0, min(1.0, (float) ($signals['lock_contention']           ?? 0.0)));
        $giveBackRate         = max(0.0, min(1.0, (float) ($signals['give_back_rate']            ?? 0.0)));
        $poisonPressure       = max(0.0, min(1.0, (float) ($signals['poison_pressure']            ?? 0.0)));
        $qualityScores        = array_values(array_filter(
            array_map('floatval', (array) ($signals['worker_quality_scores'] ?? [])),
            static fn (float $s): bool => $s >= 0.0,
        ));
        $avgQuality = $qualityScores !== [] ? array_sum($qualityScores) / count($qualityScores) : null;

        $base = max(self::MIN_PARALLELISM, min($servableCount, self::MAX_PARALLELISM));

        // ── Throttle detection ───────────────────────────────────────────────
        $throttleReasons = [];

        if ($giveBackRate > self::GIVE_BACK_RATE_THRESHOLD) {
            $throttleReasons[] = 'high_giveback_churn';
        }
        if ($lockContention > self::LOCK_CONTENTION_THRESHOLD) {
            $throttleReasons[] = 'commit_lock_contention';
        }
        if ($avgQuality !== null && $avgQuality < self::AVG_QUALITY_MINIMUM) {
            $throttleReasons[] = 'quality_risk';
        }
        if ($conflictFreeRatio < self::DISJOINT_SCOPE_LOW_THRESHOLD && $activeLeases > 1) {
            $throttleReasons[] = 'low_disjoint_scope_ratio';
        }
        if ($poisonPressure > self::POISON_PRESSURE_THRESHOLD) {
            $throttleReasons[] = 'poison_pressure_elevated';
        }

        // Each throttle condition trims the base by THROTTLE_REDUCTION_FRACTION.
        $reductions = count($throttleReasons);
        $reduceBy   = (int) ceil($base * self::THROTTLE_REDUCTION_FRACTION * $reductions);
        $recommended = max(self::MIN_PARALLELISM, $base - $reduceBy);

        // ── Add-worker signals (informational; throttle still wins) ──────────
        $addWorkerReasons = [];

        if ($queueDepth > $activeLeases * 2 && $servableCount > $activeLeases) {
            $addWorkerReasons[] = 'high_queue_depth';
        }
        if ($conflictFreeRatio >= self::DISJOINT_SCOPE_HIGH_THRESHOLD) {
            $addWorkerReasons[] = 'disjoint_scopes_available';
        }
        if ($avgQuality !== null && $avgQuality >= self::QUALITY_HIGH_THRESHOLD) {
            $addWorkerReasons[] = 'workers_demonstrate_quality';
        }

        $targetChange = $recommended - $activeLeases;

        return [
            'schema'                  => self::SCHEMA,
            'recommended_parallelism' => $recommended,
            'target_change'           => $targetChange,
            'spawn_allowed'           => $targetChange > 0,
            'throttle_reasons'        => $throttleReasons,
            'add_worker_reasons'      => $addWorkerReasons,
            'signals_used'            => [
                'queue_depth'              => $queueDepth,
                'servable_count'           => $servableCount,
                'active_leases'            => $activeLeases,
                'conflict_free_scope_ratio' => $conflictFreeRatio,
                'lock_contention'          => $lockContention,
                'give_back_rate'           => $giveBackRate,
                'poison_pressure'          => $poisonPressure,
                'avg_quality'              => $avgQuality,
            ],
        ];
    }
}
