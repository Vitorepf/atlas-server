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

    /** active_leases at or above this ceiling is unsafe lease pressure — block all new concurrent work. */
    private const DEFAULT_LEASE_CEILING = 8;

    /** worker_fit_score below this is a poor task/worker match — never admitted concurrently. */
    private const WORKER_FIT_MINIMUM = 0.50;

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
        $medianQuality = match (true) {
            $qualityScores === [] => null,
            count($qualityScores) === 1 => $qualityScores[0],
            default => (function () use ($qualityScores): float {
                $sorted = $qualityScores;
                sort($sorted, SORT_NUMERIC);
                $n = count($sorted);
                $mid = intdiv($n, 2);

                return $n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
            })(),
        };

        $base = max(self::MIN_PARALLELISM, min($servableCount, self::MAX_PARALLELISM));

        // ── Throttle detection ───────────────────────────────────────────────
        $throttleReasons = [];

        if ($giveBackRate > self::GIVE_BACK_RATE_THRESHOLD) {
            $throttleReasons[] = 'high_giveback_churn';
        }
        if ($lockContention > self::LOCK_CONTENTION_THRESHOLD) {
            $throttleReasons[] = 'commit_lock_contention';
        }
        if ($medianQuality !== null && $medianQuality < self::AVG_QUALITY_MINIMUM) {
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
        if ($medianQuality !== null && $medianQuality >= self::QUALITY_HIGH_THRESHOLD) {
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
                'median_quality'          => $medianQuality,
            ],
        ];
    }

    /**
     * Coordinates a concrete batch of candidate muscle claims: admits concurrent work ONLY when
     * each candidate's allowed_files are disjoint from every other admitted candidate, lease
     * pressure is below the ceiling, and worker-task fit is acceptable. First candidate wins any
     * file conflict (stable, deterministic order); losers are reported in conflict_files.
     *
     * @param  array{
     *   candidates?: list<array{worker_id?:string, allowed_files?:list<string>, worker_fit_score?:float}>,
     *   active_leases?: int,
     *   lease_ceiling?: int,
     * }  $input
     * @return array{
     *   schema: string,
     *   coordination_decision: string,
     *   conflict_files: list<string>,
     *   recommended_worker_count: int,
     *   backoff_or_route: string,
     *   admitted_worker_ids: list<string>,
     *   excluded: list<array{worker_id:string, reason:string}>,
     * }
     */
    public function coordinateConcurrentWork(array $input): array
    {
        $candidates = array_values((array) ($input['candidates'] ?? []));
        $activeLeases = max(0, (int) ($input['active_leases'] ?? 0));
        $leaseCeiling = max(1, (int) ($input['lease_ceiling'] ?? self::DEFAULT_LEASE_CEILING));

        if ($activeLeases >= $leaseCeiling) {
            return [
                'schema' => self::SCHEMA,
                'coordination_decision' => 'block',
                'conflict_files' => [],
                'recommended_worker_count' => 0,
                'backoff_or_route' => 'retry_after_lease_pressure_clears',
                'admitted_worker_ids' => [],
                'excluded' => array_map(
                    static fn (array $c): array => ['worker_id' => (string) ($c['worker_id'] ?? ''), 'reason' => 'lease_pressure_unsafe'],
                    $candidates,
                ),
            ];
        }

        $claimedFiles = [];
        $conflictFiles = [];
        $admittedIds = [];
        $excluded = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $workerId = (string) ($candidate['worker_id'] ?? '');
            $allowedFiles = array_values(array_map('strval', (array) ($candidate['allowed_files'] ?? [])));
            $fitScore = max(0.0, min(1.0, (float) ($candidate['worker_fit_score'] ?? 1.0)));

            $overlap = array_values(array_intersect($allowedFiles, $claimedFiles));
            if ($overlap !== []) {
                $conflictFiles = array_values(array_unique(array_merge($conflictFiles, $overlap)));
                $excluded[] = ['worker_id' => $workerId, 'reason' => 'allowed_files_conflict'];

                continue;
            }
            if ($fitScore < self::WORKER_FIT_MINIMUM) {
                $excluded[] = ['worker_id' => $workerId, 'reason' => 'poor_worker_task_fit'];

                continue;
            }

            $claimedFiles = array_merge($claimedFiles, $allowedFiles);
            $admittedIds[] = $workerId;
        }

        $recommendedWorkerCount = count($admittedIds);

        $coordinationDecision = match (true) {
            $recommendedWorkerCount === 0 => 'block',
            $excluded === [] => 'admit_all',
            default => 'admit_partial',
        };

        $hasConflictExclusion = array_filter($excluded, static fn (array $e): bool => $e['reason'] === 'allowed_files_conflict') !== [];
        $hasFitExclusion = array_filter($excluded, static fn (array $e): bool => $e['reason'] === 'poor_worker_task_fit') !== [];

        $backoffOrRoute = match (true) {
            $coordinationDecision === 'block' && $hasConflictExclusion => 'reroute_conflicting_worker_to_disjoint_task',
            $coordinationDecision === 'block' && $hasFitExclusion => 'reassign_poor_fit_worker_to_better_matched_task',
            $coordinationDecision === 'block' => 'no_admittable_candidates',
            $hasConflictExclusion => 'reroute_conflicting_worker_to_disjoint_task',
            $hasFitExclusion => 'reassign_poor_fit_worker_to_better_matched_task',
            default => 'proceed_concurrently',
        };

        return [
            'schema' => self::SCHEMA,
            'coordination_decision' => $coordinationDecision,
            'conflict_files' => $conflictFiles,
            'recommended_worker_count' => $recommendedWorkerCount,
            'backoff_or_route' => $backoffOrRoute,
            'admitted_worker_ids' => $admittedIds,
            'excluded' => $excluded,
        ];
    }
}
