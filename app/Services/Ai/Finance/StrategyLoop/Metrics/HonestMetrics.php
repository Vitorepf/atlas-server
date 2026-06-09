<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Metrics;

/**
 * The honest scorer — the per-candidate, inner-search-loop reductions.
 *
 * R7.3 boundary split (runtime_language_boundary canon + operator thesis "data
 * math belongs in Python; the kernel scanner forbids reimplementing engines in
 * PHP"): the SENSITIVE statistical ENGINE that used to live here — the return
 * moments (skewness/kurtosis), the Deflated Sharpe Ratio with the Lo-2002 analytic
 * variance floor + normal CDF/inverse, and the PBO/CSCV overfitting estimator — has
 * been migrated to the REAL Python numpy runtime (runtimes/python/honest_metrics)
 * behind the signed boundary {@see \App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient}.
 * It is proven behaviourally EQUIVALENT to the removed PHP (old-vs-new known-answer
 * within 1e-9, including the varSharpe=0 / clustered-siblings DSR N-sweep that a
 * prior audit pinned). The post-selection {@see \App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate}
 * is the consumer: it computes the WHOLE honesty bundle in ONE subprocess call.
 *
 * What REMAINS in PHP, deliberately and flagged: only the trivial scalar reductions
 * below — mean, sample std, per-period & annualized Sharpe, and max drawdown. These
 * are NOT a statistical engine; they are O(n) array reductions called PER CANDIDATE
 * inside the strategy search's tight inner loop (e.g. AtlasFinanceStrategySearchCommand
 * scores thousands of parameterizations). Spawning a Python subprocess per call there
 * would be thousands of process spawns — architecturally wrong and a perf regression,
 * the exact inversion of the create-path-perf discipline. They carry no audit-sensitive
 * formula, no special-function approximation, and no overfitting logic, so keeping them
 * as a cheap in-process reduction does not reimplement a data ENGINE in PHP. Their
 * numeric equivalence to the prior implementation is unchanged (they were not touched)
 * and is still pinned in the PHP unit test; the Python engine carries an identical copy
 * (mean/std/sharpe/max_drawdown) for use inside the moment/DSR computations, proven
 * equal to these to machine precision.
 *
 * Win-rate is deliberately ABSENT here and in the engine: it is the textbook trading
 * fake-green and is never a selection objective.
 */
final class HonestMetrics
{
    public function mean(array $x): float
    {
        $n = count($x);

        return $n > 0 ? array_sum($x) / $n : 0.0;
    }

    /** Standard deviation. Sample (n-1) by default — the convention the Sharpe estimator assumes. */
    public function std(array $x, bool $sample = true): float
    {
        $n = count($x);
        if ($n < ($sample ? 2 : 1)) {
            return 0.0;
        }
        $m = $this->mean($x);
        $sse = 0.0;
        foreach ($x as $v) {
            $d = (float) $v - $m;
            $sse += $d * $d;
        }

        return sqrt($sse / ($n - ($sample ? 1 : 0)));
    }

    /** Per-period Sharpe = mean(excess)/std. No annualization. */
    public function perPeriodSharpe(array $returns, float $rfPerPeriod = 0.0): float
    {
        $excess = array_map(static fn ($r): float => (float) $r - $rfPerPeriod, $returns);
        $s = $this->std($excess);

        return $s > 0.0 ? $this->mean($excess) / $s : 0.0;
    }

    /** Annualized Sharpe ratio. */
    public function sharpe(array $returns, float $periodsPerYear, float $rfPerPeriod = 0.0): float
    {
        return $this->perPeriodSharpe($returns, $rfPerPeriod) * sqrt(max(1.0, $periodsPerYear));
    }

    /** Maximum peak-to-trough decline of an equity curve, as a positive fraction in [0,1]. */
    public function maxDrawdown(array $equityCurve): float
    {
        $peak = -INF;
        $maxDd = 0.0;
        foreach ($equityCurve as $e) {
            $e = (float) $e;
            if (! is_finite($e)) {
                return 1.0; // a non-finite equity point is a broken curve — treat as total loss
            }
            if ($e > $peak) {
                $peak = $e;
            }
            if ($peak > 0.0) {
                $dd = ($peak - $e) / $peak;
                if ($dd > $maxDd) {
                    $maxDd = $dd;
                }
            }
        }

        return $maxDd;
    }
}
