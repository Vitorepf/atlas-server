<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Metrics;

/**
 * The honest scorer. Pure math, no state. Every method here is what separates "a
 * strategy that looks good on this history" from "a strategy with a real edge after
 * you account for how hard you searched."
 *
 *   - sharpe / maxDrawdown          : the raw, after-cost performance of one candidate.
 *   - deflatedSharpe (Bailey & López de Prado 2014): the Sharpe corrected for the number
 *     of TRIALS the loop ran, plus the return distribution's skew and fat tails. As the
 *     loop explores more scenarios, SR0 (the expected best-by-luck Sharpe) rises, so the
 *     bar to be "real" rises with it. This is the anti-Goodhart core — searching harder
 *     makes the metric stricter, not easier.
 *   - pbo via CSCV (Bailey et al. 2017): the probability that the in-sample winner
 *     underperforms out-of-sample — a direct overfitting estimate.
 *
 * Win-rate is deliberately ABSENT: it is the textbook trading fake-green and is never a
 * selection objective here.
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

    /** Standardized third moment. 0 for a symmetric distribution. */
    public function skewness(array $x): float
    {
        $n = count($x);
        $s = $this->std($x);
        if ($n < 3 || $s <= 0.0) {
            return 0.0;
        }
        $m = $this->mean($x);
        $acc = 0.0;
        foreach ($x as $v) {
            $acc += (((float) $v - $m) / $s) ** 3;
        }

        return $acc / $n;
    }

    /** Standardized fourth moment (Pearson kurtosis; 3.0 for a Gaussian). */
    public function kurtosis(array $x): float
    {
        $n = count($x);
        $s = $this->std($x);
        if ($n < 4 || $s <= 0.0) {
            return 3.0;
        }
        $m = $this->mean($x);
        $acc = 0.0;
        foreach ($x as $v) {
            $acc += (((float) $v - $m) / $s) ** 4;
        }

        return $acc / $n;
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

    /**
     * The Deflated Sharpe Ratio: probability in [0,1] that the observed per-period Sharpe
     * is genuinely > 0 after accounting for $trials independent attempts, return skew and
     * kurtosis, and sample length. Feed the NON-annualized Sharpe and the variance of the
     * Sharpe ratios ACROSS the trials (how much luck the search had to draw from).
     */
    public function deflatedSharpe(float $sr, int $nObs, float $skew, float $kurt, int $trials, float $varSharpeAcrossTrials): float
    {
        if ($nObs < 2 || ! is_finite($sr)) {
            return 0.0;
        }
        $sr0 = 0.0;
        if ($trials >= 2) {
            // Lo (2002) analytic lower bound on a per-trial Sharpe estimator's variance:
            //   Var(SR̂) ≈ (1 + SR²/2) / nObs.
            // FLOOR the empirical cross-trial variance with it. This closes the loop's #1
            // overfitting hole: when the passing siblings happen to cluster (singleton, tied,
            // or a convergent search — the loop's NATURAL state), the empirical variance is ~0
            // and SR0 would collapse, making the trial count N inert. Flooring guarantees SR0
            // grows with N regardless — searching wider always raises the bar. Every term here
            // is per-period (the units the DSR is defined in); the caller must feed a per-period
            // sibling variance, not an annualized one.
            $varFloor = (1.0 + 0.5 * $sr * $sr) / max(1, $nObs);
            $effVar = max($varSharpeAcrossTrials, $varFloor);
            $g = 0.5772156649015329; // Euler–Mascheroni
            $z1 = $this->inverseNormalCdf(1.0 - 1.0 / $trials);
            $z2 = $this->inverseNormalCdf(1.0 - 1.0 / ($trials * M_E));
            $sr0 = sqrt($effVar) * ((1.0 - $g) * $z1 + $g * $z2);
        }
        $denom = 1.0 - $skew * $sr + (($kurt - 1.0) / 4.0) * $sr * $sr;
        if ($denom <= 1e-12) {
            // A non-positive variance estimate of the Sharpe estimator is degenerate (pathological
            // higher moments). Fail SAFE — reject — never certify on a broken denominator.
            return 0.0;
        }
        $z = (($sr - $sr0) * sqrt($nObs - 1)) / sqrt($denom);

        return $this->normalCdf($z);
    }

    /** Convenience: compute the DSR straight from a returns series. */
    public function deflatedSharpeRatio(array $returns, int $trials, float $varSharpeAcrossTrials): float
    {
        return $this->deflatedSharpe(
            $this->perPeriodSharpe($returns),
            count($returns),
            $this->skewness($returns),
            $this->kurtosis($returns),
            $trials,
            $varSharpeAcrossTrials,
        );
    }

    /**
     * Probability of Backtest Overfitting via CSCV. $matrix rows are strategies, columns
     * are aligned time observations (e.g. per-window OOS returns). Splits time into
     * $blocks, and over every symmetric IS/OOS partition asks: does the IS-best strategy
     * land in the bottom half OOS? The fraction that do is the PBO. Ranks by mean return
     * (robust; degeneracy-free on tiny samples). 1.0 = cannot disprove overfitting.
     */
    public function pbo(array $matrix, int $blocks = 8): float
    {
        $matrix = array_values($matrix);
        $m = count($matrix);
        if ($m < 2) {
            return 1.0;
        }
        $t = count($matrix[0]);
        foreach ($matrix as $row) {
            if (count($row) !== $t) {
                return 1.0; // ragged matrix — cannot evaluate
            }
            foreach ($row as $v) {
                if (! is_finite((float) $v)) {
                    return 1.0; // a NaN/INF cell would corrupt the ranking — cannot disprove overfitting
                }
            }
        }
        $s = $blocks - ($blocks % 2);
        if ($t < $s) {
            $s = $t - ($t % 2);
        }
        if ($s < 2) {
            return 1.0;
        }
        $blockCols = $this->splitBlocks($t, $s);
        $combos = $this->combinations(range(0, $s - 1), intdiv($s, 2));
        if ($combos === []) {
            return 1.0;
        }

        $overfit = 0;
        $total = 0;
        foreach ($combos as $isBlocks) {
            $isSet = array_flip($isBlocks);
            $isCols = $oosCols = [];
            for ($b = 0; $b < $s; $b++) {
                foreach ($blockCols[$b] as $c) {
                    if (isset($isSet[$b])) {
                        $isCols[] = $c;
                    } else {
                        $oosCols[] = $c;
                    }
                }
            }
            if ($isCols === [] || $oosCols === []) {
                continue;
            }
            $isBest = $this->argbestMean($matrix, $isCols);
            $oosPerf = [];
            foreach ($matrix as $i => $row) {
                $oosPerf[$i] = $this->meanOf($row, $oosCols);
            }
            asort($oosPerf); // ascending: worst -> best
            $ranked = array_keys($oosPerf);
            $rank = (int) array_search($isBest, $ranked, true) + 1; // 1 = worst, m = best
            $omega = $rank / ($m + 1);
            $lambda = log($omega / (1.0 - $omega));
            if ($lambda <= 0.0) {
                $overfit++;
            }
            $total++;
        }

        return $total > 0 ? $overfit / $total : 1.0;
    }

    public function normalCdf(float $x): float
    {
        return 0.5 * (1.0 + $this->erf($x / M_SQRT2));
    }

    /** Inverse standard-normal CDF (probit) via Acklam's rational approximation. */
    public function inverseNormalCdf(float $p): float
    {
        $p = min(1.0 - 1e-15, max(1e-15, $p));
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $pLow = 0.02425;
        $pHigh = 1.0 - $pLow;

        if ($p < $pLow) {
            $q = sqrt(-2.0 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }
        if ($p <= $pHigh) {
            $q = $p - 0.5;
            $r = $q * $q;

            return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
        }
        $q = sqrt(-2.0 * log(1.0 - $p));

        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
    }

    /** erf via Abramowitz & Stegun 7.1.26 (|error| < 1.5e-7). */
    private function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $y = 1.0 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /** @return list<list<int>> contiguous, near-equal column blocks */
    private function splitBlocks(int $t, int $s): array
    {
        $base = intdiv($t, $s);
        $rem = $t % $s;
        $blocks = [];
        $c = 0;
        for ($b = 0; $b < $s; $b++) {
            $size = $base + ($b < $rem ? 1 : 0);
            $cols = [];
            for ($j = 0; $j < $size; $j++) {
                $cols[] = $c++;
            }
            $blocks[] = $cols;
        }

        return $blocks;
    }

    /**
     * @param  list<int>  $items
     * @return list<list<int>>
     */
    private function combinations(array $items, int $k): array
    {
        $res = [];
        $n = count($items);
        $rec = function (int $start, array $chosen) use (&$rec, &$res, $items, $n, $k): void {
            if (count($chosen) === $k) {
                $res[] = $chosen;

                return;
            }
            for ($i = $start; $i < $n; $i++) {
                $rec($i + 1, [...$chosen, $items[$i]]);
            }
        };
        $rec(0, []);

        return $res;
    }

    /**
     * @param  list<list<float>>  $matrix
     * @param  list<int>  $cols
     */
    private function argbestMean(array $matrix, array $cols): int
    {
        $best = 0;
        $bestVal = -INF;
        foreach ($matrix as $i => $row) {
            $v = $this->meanOf($row, $cols);
            if ($v > $bestVal) {
                $bestVal = $v;
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $row
     * @param  list<int>  $cols
     */
    private function meanOf(array $row, array $cols): float
    {
        if ($cols === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($cols as $c) {
            $sum += (float) $row[$c];
        }

        return $sum / count($cols);
    }
}
