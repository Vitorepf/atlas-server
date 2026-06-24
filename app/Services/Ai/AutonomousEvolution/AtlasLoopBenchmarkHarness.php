<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §11.2 performance — the benchmark harness + PERF-CERT (the loop can PROVE a speedup).
 *
 * Today the loop has no first-class way to MEASURE and PROVE a performance improvement: a candidate
 * that is allegedly faster ships on a model claim or a single un-warmed timing — pure noise. This
 * harness makes "speedup" a machine-anchored, statistically-guarded work-type.
 *
 *  - measure(): a warmed, repeated-sample runner over hrtime(true) (monotonic, nanosecond). It runs
 *    warmup+iterations, DISCARDS the warmup samples (cold cache / JIT-warm artifacts), and reduces the
 *    kept samples to a deterministic shape {n, median_ns, iqr_ns, stddev_ns, min_ns, max_ns, samples}.
 *  - proveSpeedup(): the PERF-CERT. baseline vs candidate, both warmed-median over N, with TWO honest
 *    guards so noise is never sold as a win: (1) a regression threshold (speedup >= minSpeedup) and
 *    (2) a VARIANCE guard — the candidate's median PLUS its own IQR must still sit below the baseline
 *    median, so overlapping noisy distributions cannot certify "significant".
 *
 * HONEST LIMIT (by design): wall-clock micro-benchmarks on a shared host are inherently noisy; the IQR
 * variance guard is a defensible-but-not-rigorous proxy for a paired statistical test. It converts
 * "unmeasured speedup claim" into "warmed median + threshold + variance-bounded cert" — a real, bounded
 * lift, not a p-value. Pure + deterministic in shape; the only nondeterminism is the timing itself.
 */
final class AtlasLoopBenchmarkHarness
{
    public function __construct(private ?AtlasLoopHardCaseHarness $hardCaseHarness = null) {}

    /**
     * Frozen replay deck for historically-hard model-bound cases. Default-OFF through the harness.
     *
     * @param  list<array<string,mixed>>  $taskPackets
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    public function hardCaseDeck(array $taskPackets = [], ?AtlasLoopAttemptLedger $ledger = null, array $options = []): array
    {
        return ($this->hardCaseHarness ?? new AtlasLoopHardCaseHarness)
            ->curate($taskPackets, $ledger, $options);
    }

    /**
     * Run $fn (warmup + iterations) and reduce the KEPT samples to a deterministic statistical shape.
     *
     * @param  callable():mixed  $fn          the workload to time; its return value is discarded
     * @param  int               $iterations  number of timed samples kept (>= 1)
     * @param  int               $warmup      leading samples to run-and-discard (>= 0)
     * @return array{n:int, median_ns:float, iqr_ns:float, stddev_ns:float, min_ns:int, max_ns:int, samples:list<int>}
     */
    public function measure(callable $fn, int $iterations = 30, int $warmup = 5): array
    {
        $iterations = max(1, $iterations);
        $warmup = max(0, $warmup);

        // Warmup: run but DISCARD — absorbs cold-cache / opcode-warm artifacts so the kept samples
        // reflect steady-state cost, not first-touch cost.
        for ($i = 0; $i < $warmup; $i++) {
            $fn();
        }

        /** @var list<int> $samples */
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $fn();
            $samples[] = (int) (hrtime(true) - $start);
        }

        return $this->summarize($samples);
    }

    /**
     * PERF-CERT: prove (or refuse) that $candidate is meaningfully faster than $baseline.
     *
     * @param  callable():mixed  $baseline    the current/reference workload
     * @param  callable():mixed  $candidate   the proposed-faster workload
     * @param  int               $iterations  timed samples per side
     * @param  float             $minSpeedup  regression threshold; speedup must be >= this to count
     * @return array{
     *   faster:bool,
     *   speedup:float,
     *   significant:bool,
     *   baseline:array{n:int, median_ns:float, iqr_ns:float, stddev_ns:float, min_ns:int, max_ns:int, samples:list<int>},
     *   candidate:array{n:int, median_ns:float, iqr_ns:float, stddev_ns:float, min_ns:int, max_ns:int, samples:list<int>}
     * }
     */
    public function proveSpeedup(
        callable $baseline,
        callable $candidate,
        int $iterations = 30,
        float $minSpeedup = 1.10,
    ): array {
        return $this->certify($this->measure($baseline, $iterations), $this->measure($candidate, $iterations), $minSpeedup);
    }

    /**
     * The PERF-CERT decision, separated from the (noisy) timing so it is DETERMINISTICALLY testable: given
     * two measured summaries, decide faster/speedup/significant. The threshold AND the variance guard are
     * pinned here, free of wall-clock jitter.
     *
     * @param  array{median_ns:float, iqr_ns:float}  $baselineStats
     * @param  array{median_ns:float, iqr_ns:float}  $candidateStats
     * @return array{faster:bool, speedup:float, significant:bool, baseline:array<string,mixed>, candidate:array<string,mixed>}
     */
    public function certify(array $baselineStats, array $candidateStats, float $minSpeedup = 1.10): array
    {
        $baselineMedian = (float) $baselineStats['median_ns'];
        $candidateMedian = (float) $candidateStats['median_ns'];

        // speedup = baseline_median / candidate_median (>1 means candidate is faster). Guard /0.
        $speedup = $candidateMedian > 0.0
            ? $baselineMedian / $candidateMedian
            : ($baselineMedian > 0.0 ? INF : 1.0);

        $faster = $speedup > 1.0;

        // Variance guard: the candidate's median + its own IQR must still beat the baseline median. If the
        // candidate's noise band reaches up to (or past) the baseline, the "speedup" is just jitter.
        $variancePass = ($candidateMedian + (float) $candidateStats['iqr_ns']) < $baselineMedian;

        return [
            'faster' => $faster,
            'speedup' => $speedup,
            'significant' => $speedup >= $minSpeedup && $variancePass,
            'baseline' => $baselineStats,
            'candidate' => $candidateStats,
        ];
    }

    /**
     * Reduce raw nanosecond samples to the deterministic summary shape.
     *
     * @param  list<int>  $samples
     * @return array{n:int, median_ns:float, iqr_ns:float, stddev_ns:float, min_ns:int, max_ns:int, samples:list<int>}
     */
    public function summarize(array $samples): array
    {
        $sorted = $samples;
        sort($sorted, SORT_NUMERIC);
        $n = count($sorted);

        $median = $this->percentile($sorted, 0.50);
        $q1 = $this->percentile($sorted, 0.25);
        $q3 = $this->percentile($sorted, 0.75);

        $mean = array_sum($sorted) / $n;
        $variance = 0.0;
        foreach ($sorted as $s) {
            $variance += ($s - $mean) ** 2;
        }
        $variance /= $n; // population stddev — deterministic, no n-1 edge case at n=1
        $stddev = sqrt($variance);

        return [
            'n' => $n,
            'median_ns' => $median,
            'iqr_ns' => $q3 - $q1,
            'stddev_ns' => $stddev,
            'min_ns' => $sorted[0],
            'max_ns' => $sorted[$n - 1],
            'samples' => $samples,
        ];
    }

    /**
     * Linear-interpolated percentile on an already-ascending list. $p in [0,1].
     *
     * @param  list<int>  $sorted  ascending, non-empty
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }

        $rank = $p * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        if ($low === $high) {
            return (float) $sorted[$low];
        }

        $frac = $rank - $low;

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $frac;
    }
}
