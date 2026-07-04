<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

/**
 * Pure predictor. Given snapshots of active workers (with write-sets),
 * lease holders, and recent commit failures, forecasts lease/commit-lock contention.
 *
 * Algorithm:
 *   1. Build path → workers map from worker_snapshot.write_set fields.
 *   2. Count commit failures per path from commit_failure_snapshot.
 *   3. Derive max_path_overlap and commit_failure_rate.
 *   4. Risk tier:
 *        high   → max_overlap >= HIGH_OVERLAP  OR  failure_rate >= HIGH_FAILURE_RATE
 *        medium → max_overlap >= 2             OR  failure_rate >= MEDIUM_FAILURE_RATE
 *        low    → otherwise
 *   5. is_healthy_parallelism → many workers but max_overlap <= 1 (no shared paths).
 *      This flag is the key AC2 signal: healthy ≠ risky even when worker count is high.
 *   6. most_contended_paths → top paths by overlap count (ties broken by failure count desc).
 *   7. mitigation_hints from risk tier + commit failure presence.
 *   8. recommended_action (serve|stagger|backoff|split_queue) + reasons:
 *        low    → serve        (no meaningful contention signal)
 *        medium → stagger      (some path overlap, spread writes over time)
 *        high, hot_path_count <= 1 → backoff  (one hot path — retry/backoff resolves it)
 *        high, hot_path_count >= 2 → split_queue (structural: partition the queue by path)
 *      recommended_action is driven ONLY by overlap/failure signals, never by worker_count
 *      alone — many independent (non-overlapping) workers still recommend serve.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasMaestroLeaseContentionPredictor
{
    public const SCHEMA = 'atlas.maestro.lease_contention_predictor.v1';

    private const HIGH_OVERLAP       = 3;
    private const HIGH_FAILURE_RATE  = 0.5;
    private const MEDIUM_FAILURE_RATE = 0.2;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function predict(array $facts): array
    {
        $workers         = is_array($facts['worker_snapshot'] ?? null) ? $facts['worker_snapshot'] : [];
        $commitFailures  = is_array($facts['commit_failure_snapshot'] ?? null) ? $facts['commit_failure_snapshot'] : [];

        // path → list of worker ids that declare that path in their write_set.
        $pathWorkers = [];
        foreach ($workers as $w) {
            $wid      = (string) ($w['id'] ?? '');
            $writeSet = is_array($w['write_set'] ?? null) ? $w['write_set'] : [];
            foreach ($writeSet as $path) {
                $p = (string) $path;
                $pathWorkers[$p][] = $wid;
            }
        }

        // path → failure count.
        $pathFailures = [];
        foreach ($commitFailures as $f) {
            $p = (string) ($f['path'] ?? '');
            if ($p !== '') {
                $pathFailures[$p] = ($pathFailures[$p] ?? 0) + 1;
            }
        }

        $workerCount    = count($workers);
        $maxOverlap     = $workerCount > 0 ? max(array_map('count', $pathWorkers) ?: [0]) : 0;
        $totalFailures  = count($commitFailures);
        $failureRate    = $workerCount > 0 ? $totalFailures / $workerCount : 0.0;

        $risk = $this->riskTier($maxOverlap, $failureRate);

        $isHealthy = $workerCount >= 2 && $maxOverlap <= 1;

        $mostContended = $this->topContentedPaths($pathWorkers, $pathFailures);
        $hotPathCount  = count(array_filter($pathWorkers, static fn (array $ws): bool => count($ws) >= 2));

        // hotspot_families: distinct file families (directory prefixes) with contention
        $hotspotFamilies = $this->computeHotspotFamilies($pathWorkers);

        // safe_parallelism: how many workers can run safely without contention
        $safeParallelism = $this->computeSafeParallelism($workerCount, $maxOverlap, $hotspotFamilies);

        // recommended_backoff_seconds: 0 when low risk, scaled when high
        $recommendedBackoffSeconds = $this->computeRecommendedBackoffSeconds($risk, $maxOverlap, $totalFailures);

        [$recommendedAction, $actionReasons] = $this->recommendation($risk, $hotPathCount, $maxOverlap, $failureRate, $totalFailures);

        return [
            'schema_version'        => self::SCHEMA,
            'contention_risk'       => $risk,
            'hotspot_families'      => $hotspotFamilies,
            'safe_parallelism'      => $safeParallelism,
            'recommended_backoff_seconds' => $recommendedBackoffSeconds,
            'is_healthy_parallelism' => $isHealthy,
            'most_contended_paths'  => $mostContended,
            'mitigation_hints'      => $this->mitigationHints($risk, $totalFailures),
            'recommended_action'    => $recommendedAction,
            'action_reasons'        => $actionReasons,
            'diagnostics' => [
                'active_workers'       => $workerCount,
                'max_path_overlap'     => $maxOverlap,
                'commit_failure_rate'  => round($failureRate, 3),
                'hot_path_count'       => $hotPathCount,
            ],
        ];
    }

    /**
     * Compute distinct file families (directory prefixes) with contention.
     *
     * @param  array<string, list<string>>  $pathWorkers
     * @return list<string>
     */
    private function computeHotspotFamilies(array $pathWorkers): array
    {
        $families = [];
        foreach ($pathWorkers as $path => $workers) {
            if (count($workers) >= 2) {
                $parts = explode('/', $path);
                $family = implode('/', array_slice($parts, 0, max(1, count($parts) - 1)));
                $families[$family] = ($families[$family] ?? 0) + 1;
            }
        }

        return array_keys($families);
    }

    /**
     * Compute safe parallelism: how many workers can run without contention.
     */
    private function computeSafeParallelism(int $workerCount, int $maxOverlap, array $hotspotFamilies): int
    {
        if ($workerCount === 0) {
            return 0;
        }

        // If no hotspots, all workers are safe
        if ($hotspotFamilies === []) {
            return $workerCount;
        }

        // Safe parallelism = number of distinct families (each family can handle 1 worker safely)
        $familyCount = count($hotspotFamilies);

        return max(1, $familyCount);
    }

    /**
     * Compute recommended backoff seconds based on risk level.
     */
    private function computeRecommendedBackoffSeconds(string $risk, int $maxOverlap, int $totalFailures): int
    {
        if ($risk === 'low') {
            return 0;
        }

        if ($risk === 'medium') {
            return 30;
        }

        // High risk: scale with overlap and failures
        return min(300, max(60, ($maxOverlap * 20) + ($totalFailures * 10)));
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function recommendation(string $risk, int $hotPathCount, int $maxOverlap, float $failureRate, int $totalFailures): array
    {
        if ($risk === 'low') {
            return ['serve', ['no meaningful path overlap or commit-failure signal detected']];
        }

        if ($risk === 'medium') {
            return ['stagger', [
                sprintf('max_path_overlap=%d indicates some shared write paths', $maxOverlap),
                sprintf('commit_failure_rate=%.3f is at/above the stagger threshold', $failureRate),
            ]];
        }

        // risk === 'high'
        if ($hotPathCount >= 2) {
            return ['split_queue', [
                sprintf('hot_path_count=%d distinct paths are each contended by >=2 workers', $hotPathCount),
                'a single stagger/backoff will not resolve contention spread across multiple paths',
            ]];
        }

        return ['backoff', array_values(array_filter([
            sprintf('max_path_overlap=%d on a single hot path', $maxOverlap),
            $totalFailures > 0 ? sprintf('%d recent commit-lock failure(s) observed', $totalFailures) : null,
        ]))];
    }

    private function riskTier(int $maxOverlap, float $failureRate): string
    {
        if ($maxOverlap >= self::HIGH_OVERLAP || $failureRate >= self::HIGH_FAILURE_RATE) {
            return 'high';
        }
        if ($maxOverlap >= 2 || $failureRate >= self::MEDIUM_FAILURE_RATE) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,list<string>>  $pathWorkers
     * @param  array<string,int>           $pathFailures
     * @return list<string>
     */
    private function topContentedPaths(array $pathWorkers, array $pathFailures): array
    {
        // Only paths with >= 2 workers.
        $candidates = array_filter($pathWorkers, static fn (array $ws): bool => count($ws) >= 2);
        if ($candidates === []) {
            return [];
        }

        $paths = array_keys($candidates);
        usort($paths, static function (string $a, string $b) use ($pathWorkers, $pathFailures): int {
            $overlapDiff = count($pathWorkers[$b]) - count($pathWorkers[$a]);
            if ($overlapDiff !== 0) {
                return $overlapDiff;
            }

            return ($pathFailures[$b] ?? 0) - ($pathFailures[$a] ?? 0);
        });

        return array_values($paths);
    }

    /**
     * @return list<string>
     */
    private function mitigationHints(string $risk, int $totalFailures): array
    {
        $hints = match ($risk) {
            'high'   => ['serialize_access_to_hot_paths', 'reduce_worker_parallelism', 'implement_write_set_partitioning'],
            'medium' => ['monitor_hot_paths', 'reduce_overlap_in_write_sets'],
            default  => [],
        };

        if ($totalFailures > 0 && ! in_array('serialize_access_to_hot_paths', $hints, true)) {
            $hints[] = 'add_exponential_retry_on_lock_conflict';
        }

        return $hints;
    }
}
