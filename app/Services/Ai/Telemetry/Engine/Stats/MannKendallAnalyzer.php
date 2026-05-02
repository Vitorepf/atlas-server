<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

/**
 * Mann-Kendall non-parametric trend test + Sen's slope estimator.
 *
 *   S = Σ_{i<j} sign(X_j - X_i)
 *   Var(S) = n(n-1)(2n+5) / 18
 *   Z = (S - sign(S)) / √Var(S)               continuity correction
 *   p = 2·(1 - Φ(|Z|))                        two-tailed
 *   Sen's slope = median of all (X_j-X_i)/(j-i) for i<j
 *
 * Minimum n = 7 (below this Var(S) lacks power for any signal).
 * Normal CDF via Abramowitz-Stegun rational approximation (~1e-7 accurate).
 */
class MannKendallAnalyzer
{
    public const MIN_N = 7;

    /**
     * @param  array<int,float>  $series  ordered values (chronological)
     * @return array{
     *   n: int,
     *   s_statistic: int,
     *   z: ?float,
     *   p_value: ?float,
     *   sens_slope: ?float,
     *   direction: string,
     *   suppressed: bool,
     *   suppression_reason: ?string
     * }
     */
    public function test(array $series): array
    {
        $n = count($series);
        if ($n < self::MIN_N) {
            return [
                'n' => $n,
                's_statistic' => 0,
                'z' => null,
                'p_value' => null,
                'sens_slope' => null,
                'direction' => 'none',
                'suppressed' => true,
                'suppression_reason' => 'n_below_min:'.self::MIN_N,
            ];
        }

        // Compute S statistic
        $s = 0;
        $slopes = [];
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $diff = $series[$j] - $series[$i];
                $s += $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
                $slopes[] = $diff / ($j - $i);
            }
        }

        // Variance with continuity correction
        $variance = ($n * ($n - 1) * (2 * $n + 5)) / 18.0;
        $z = $s > 0 ? ($s - 1) / sqrt($variance) : ($s < 0 ? ($s + 1) / sqrt($variance) : 0.0);

        // Two-tailed p-value via standard normal CDF
        $pValue = 2.0 * (1.0 - $this->standardNormalCdf(abs($z)));

        // Sen's slope (median of pairwise slopes)
        sort($slopes);
        $slopeCount = count($slopes);
        $sensSlope = $slopeCount % 2 === 1
            ? $slopes[intdiv($slopeCount, 2)]
            : ($slopes[$slopeCount / 2 - 1] + $slopes[$slopeCount / 2]) / 2.0;

        $direction = match (true) {
            $z > 0 && $pValue < 0.05 => 'increasing',
            $z < 0 && $pValue < 0.05 => 'decreasing',
            $pValue < 0.10 => 'tentative',
            default => 'none',
        };

        return [
            'n' => $n,
            's_statistic' => $s,
            'z' => round($z, 4),
            'p_value' => round($pValue, 6),
            'sens_slope' => round($sensSlope, 6),
            'direction' => $direction,
            'suppressed' => false,
            'suppression_reason' => null,
        ];
    }

    /**
     * Φ(z) via Abramowitz-Stegun rational approximation (formula 26.2.17).
     * Accurate to ~7.5 × 10⁻⁸ — overkill for our threshold checks, simple to audit.
     */
    private function standardNormalCdf(float $z): float
    {
        if ($z < 0.0) {
            return 1.0 - $this->standardNormalCdf(-$z);
        }

        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p = 0.2316419;

        $t = 1.0 / (1.0 + $p * $z);
        $pdf = exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI);
        $cdf = 1.0 - $pdf * ($b1 * $t + $b2 * $t ** 2 + $b3 * $t ** 3 + $b4 * $t ** 4 + $b5 * $t ** 5);

        return $cdf;
    }
}
