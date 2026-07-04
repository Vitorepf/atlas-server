<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure predictor. Estimates whether too many muscles are about to collide on
 * leases, the git index, or overlapping file families — BEFORE another
 * worker is started.
 *
 * Safe parallelism is bounded by the number of DISTINCT file families
 * currently queued: two workers touching the same family will fight over
 * the same files/lease, so safe_parallelism = max(1, distinct_file_families).
 *
 * contention_risk:
 *   high   — workers > distinct_file_families, OR recent_index_lock_events > 0
 *   medium — workers === distinct_file_families (no headroom, but balanced)
 *   low    — workers < distinct_file_families
 *
 * safe_to_add_another_muscle = true only when contention_risk = 'low'.
 *
 * INPUT:
 *   active_leases?:              int
 *   claimed_records?:            int
 *   workers?:                    int
 *   file_families?:              list<string>
 *   recent_index_lock_events?:   int
 *   per_worker_throughput?:      list<{worker_id?: string, tasks_per_hour?: float}>
 *
 * Pure: no I/O, no side effects, never starts or stops a worker itself.
 */
final class AtlasExternalBrainMuscleLeaseContentionPredictor
{
    public const SCHEMA = 'atlas.external_brain.muscle_lease_contention_predictor.v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function predict(array $facts): array
    {
        $activeLeases = max(0, (int) ($facts['active_leases'] ?? 0));
        $claimedRecords = max(0, (int) ($facts['claimed_records'] ?? 0));
        $workers = max(0, (int) ($facts['workers'] ?? 0));
        $fileFamilies = is_array($facts['file_families'] ?? null) ? array_map('strval', $facts['file_families']) : [];
        $distinctFamilies = count(array_unique($fileFamilies));
        $recentIndexLockEvents = max(0, (int) ($facts['recent_index_lock_events'] ?? 0));
        $perWorkerThroughput = is_array($facts['per_worker_throughput'] ?? null) ? $facts['per_worker_throughput'] : [];

        $leaseMismatch = $activeLeases !== $claimedRecords;

        $contentionRisk = match (true) {
            $recentIndexLockEvents > 0 => 'high',
            $distinctFamilies > 0 && $workers > $distinctFamilies => 'high',
            $distinctFamilies > 0 && $workers === $distinctFamilies => 'medium',
            $leaseMismatch && $activeLeases > 0 => 'medium',
            default => 'low',
        };

        $bottleneckReason = match (true) {
            $recentIndexLockEvents > 0 => 'git_index_lock_contention_observed',
            $distinctFamilies > 0 && $workers > $distinctFamilies => 'worker_count_exceeds_file_family_parallelism',
            $leaseMismatch && $activeLeases > 0 => 'lease_claimed_record_mismatch',
            $distinctFamilies === 0 => 'no_file_family_data',
            default => 'none',
        };

        $safeParallelism = max(1, $distinctFamilies);
        $recommendedWorkerCount = match (true) {
            $recentIndexLockEvents > 0 => max(1, min($workers, $safeParallelism) - 1),
            default => max(0, min($workers, $safeParallelism)),
        };

        $safeToAddAnotherMuscle = $contentionRisk === 'low';

        return [
            'schema' => self::SCHEMA,
            'contention_risk' => $contentionRisk,
            'recommended_worker_count' => $recommendedWorkerCount,
            'bottleneck_reason' => $bottleneckReason,
            'safe_to_add_another_muscle' => $safeToAddAnotherMuscle,
            'facts' => [
                'active_leases' => $activeLeases,
                'claimed_records' => $claimedRecords,
                'workers' => $workers,
                'distinct_file_families' => $distinctFamilies,
                'safe_parallelism' => $safeParallelism,
                'recent_index_lock_events' => $recentIndexLockEvents,
                'per_worker_throughput_sample_count' => count($perWorkerThroughput),
                'lease_claimed_record_mismatch' => $leaseMismatch,
            ],
        ];
    }

    /**
     * File family pressure: computes file_family_pressure from queued family concentration
     * and active worker count. Marks safe_to_add_another_muscle false when one family dominates.
     *
     * @param  array{
     *   file_families: list<string>,
     *   active_workers: int,
     *   dominant_family_threshold: float,
     * }  $input
     * @return array{safe_parallelism:int, bottleneck_reason:string, recommended_worker_mix:string, file_family_pressure:float}
     */
    public function fileFamilyPressure(array $input): array
    {
        $fileFamilies = is_array($input['file_families'] ?? null) ? array_map('strval', $input['file_families']) : [];
        $activeWorkers = max(0, (int) ($input['active_workers'] ?? 0));
        $dominantThreshold = (float) ($input['dominant_family_threshold'] ?? 0.5);

        $distinctFamilies = count(array_unique($fileFamilies));
        $totalFamilies = count($fileFamilies);
        $safeParallelism = max(1, $distinctFamilies);

        // Compute file_family_pressure: ratio of workers to distinct families
        $fileFamilyPressure = $distinctFamilies > 0 ? $activeWorkers / $distinctFamilies : (float) $activeWorkers;

        // Check if one family dominates the queue
        $familyCounts = array_count_values($fileFamilies);
        $dominantFamily = null;
        $dominantRatio = 0.0;
        foreach ($familyCounts as $family => $count) {
            $ratio = $totalFamilies > 0 ? $count / $totalFamilies : 0.0;
            if ($ratio > $dominantRatio) {
                $dominantRatio = $ratio;
                $dominantFamily = $family;
            }
        }

        $bottleneckReason = match (true) {
            $dominantFamily !== null && $dominantRatio > $dominantThreshold => sprintf('family_dominance:%s=%.2f', $dominantFamily, $dominantRatio),
            $activeWorkers > $safeParallelism => 'worker_count_exceeds_safe_parallelism',
            default => 'none',
        };

        $recommendedWorkerMix = match (true) {
            $dominantFamily !== null && $dominantRatio > $dominantThreshold => sprintf('reduce_workers_on_%s_to_1', $dominantFamily),
            $activeWorkers > $safeParallelism => sprintf('scale_workers_to_%d', $safeParallelism),
            default => sprintf('maintain_%d_workers', $activeWorkers),
        };

        return [
            'safe_parallelism' => $safeParallelism,
            'bottleneck_reason' => $bottleneckReason,
            'recommended_worker_mix' => $recommendedWorkerMix,
            'file_family_pressure' => round($fileFamilyPressure, 4),
        ];
    }
}
