<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Fairness;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;

/**
 * Pure FACT-only fairness reporter. Computes Gini on TWO axes:
 *   - workers      → distribution of executed (completed) tasks across worker client_ids
 *   - task_classes → distribution of executed tasks across task_class prefixes parsed from
 *                    task_packet_id (everything before the first '-').
 *
 * NO recommendation, NO mutation, provider-free.
 *
 * Inputs (constructor):
 *   - AtlasMaestroWorkerFleetProbe — source of {client_id, lifetime_throughput} per worker.
 *   - $completedTaskSource: callable(): iterable<{worker_client_id, task_packet_id, outcome?}>
 *       only rows with outcome === 'success' (or absent) are counted; task_class is the prefix
 *       of task_packet_id before the first '-'.
 *
 * Empty input ⇒ gini = 0.0 (NEVER NaN), histograms = empty arrays, max_*_share_id = null.
 */
final class AtlasMaestroFairnessGiniReporter
{
    public const SCHEMA = 'atlas.maestro.fairness_gini.v1';

    /** @var callable(): iterable<array<string,mixed>> */
    private $completedTaskSource;

    /**
     * @param  callable(): iterable<array<string,mixed>>  $completedTaskSource
     */
    public function __construct(
        private readonly AtlasMaestroWorkerFleetProbe $probe,
        callable $completedTaskSource,
    ) {
        $this->completedTaskSource = $completedTaskSource;
    }

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $workerThroughput = $this->workerThroughput();
        $taskClassCounts = $this->taskClassCounts();

        $totalWorkers = array_sum($workerThroughput);
        $totalTaskClasses = array_sum($taskClassCounts);
        // total_completed is the count from the task source — that's the authoritative axis. Workers
        // throughput is from the probe; the two SHOULD agree but the reporter doesn't enforce it.
        $totalCompleted = $totalTaskClasses;

        $workerHist = $this->shareHistogram($workerThroughput);
        $taskClassHist = $this->shareHistogram($taskClassCounts);

        return [
            'schema_version' => self::SCHEMA,
            'workers' => count($workerThroughput),
            'task_classes' => count($taskClassCounts),
            'total_completed' => $totalCompleted,
            'gini_workers' => $this->gini(array_values($workerThroughput)),
            'gini_task_classes' => $this->gini(array_values($taskClassCounts)),
            'worker_share_histogram' => $workerHist,
            'task_class_share_histogram' => $taskClassHist,
            'max_worker_share_id' => $this->maxKey($workerHist),
            'max_task_class_share_id' => $this->maxKey($taskClassHist),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function workerThroughput(): array
    {
        $out = [];
        foreach ($this->probe->probe() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clientId = (string) ($row['client_id'] ?? '');
            if ($clientId === '') {
                continue;
            }
            $out[$clientId] = (int) ($row['lifetime_throughput'] ?? 0);
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string,int>
     */
    private function taskClassCounts(): array
    {
        $out = [];
        try {
            foreach (($this->completedTaskSource)() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $packetId = (string) ($row['task_packet_id'] ?? '');
                if ($packetId === '') {
                    continue;
                }
                $outcome = (string) ($row['outcome'] ?? 'success');
                if ($outcome !== 'success' && $outcome !== '') {
                    continue;
                }
                $taskClass = $this->taskClassOf($packetId);
                $out[$taskClass] = ($out[$taskClass] ?? 0) + 1;
            }
        } catch (\Throwable) {
            // facts-only — drop on read error.
        }
        ksort($out);

        return $out;
    }

    private function taskClassOf(string $packetId): string
    {
        $dashAt = strpos($packetId, '-');

        return $dashAt === false ? $packetId : substr($packetId, 0, $dashAt);
    }

    /**
     * Compute the Gini coefficient over a list of non-negative counts. Empty / all-zero list ⇒ 0.0.
     *
     * Formula: G = ( Σ_i Σ_j |x_i - x_j| ) / ( 2 * n * Σ x )
     *
     * @param  list<int>  $counts
     */
    private function gini(array $counts): float
    {
        $n = count($counts);
        if ($n === 0) {
            return 0.0;
        }
        $sum = array_sum($counts);
        if ($sum <= 0) {
            return 0.0;
        }
        $absSum = 0.0;
        foreach ($counts as $i => $xi) {
            foreach ($counts as $j => $xj) {
                $absSum += abs($xi - $xj);
            }
        }

        return $absSum / (2 * $n * $sum);
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<string,float>
     */
    private function shareHistogram(array $counts): array
    {
        $sum = array_sum($counts);
        if ($sum <= 0) {
            return [];
        }
        $out = [];
        foreach ($counts as $k => $v) {
            $out[(string) $k] = $v / $sum;
        }

        return $out;
    }

    /**
     * @param  array<string,float>  $histogram
     */
    private function maxKey(array $histogram): ?string
    {
        if ($histogram === []) {
            return null;
        }
        $maxKey = null;
        $maxValue = -INF;
        foreach ($histogram as $k => $v) {
            if ($v > $maxValue) {
                $maxValue = $v;
                $maxKey = (string) $k;
            }
        }

        return $maxKey;
    }
}
