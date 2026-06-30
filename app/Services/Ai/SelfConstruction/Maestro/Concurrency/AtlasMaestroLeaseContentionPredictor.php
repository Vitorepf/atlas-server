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

        return [
            'schema_version'        => self::SCHEMA,
            'contention_risk'       => $risk,
            'is_healthy_parallelism' => $isHealthy,
            'most_contended_paths'  => $mostContended,
            'mitigation_hints'      => $this->mitigationHints($risk, $totalFailures),
            'diagnostics' => [
                'active_workers'       => $workerCount,
                'max_path_overlap'     => $maxOverlap,
                'commit_failure_rate'  => round($failureRate, 3),
                'hot_path_count'       => count(array_filter($pathWorkers, static fn (array $ws): bool => count($ws) >= 2)),
            ],
        ];
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
