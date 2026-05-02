<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

/**
 * Two-sample Kolmogorov-Smirnov distribution comparison.
 *
 *   D = max_x |F1(x) - F2(x)|     max difference between empirical CDFs
 *   z = D · √(n1·n2 / (n1+n2))
 *   p ≈ 2·Σ_{j=1}^{∞} (-1)^{j+1}·exp(-2j²·z²)
 *
 * Mann-Whitney U fallback when n_per_group < 20.
 * Both groups must have n >= 8 minimum (otherwise insufficient_data).
 */
class KolmogorovSmirnovTest
{
    public const KS_MIN_N_PER_GROUP = 20;
    public const ABSOLUTE_MIN_N_PER_GROUP = 8;
    private const KOLMOGOROV_TERMS = 100;

    /**
     * @param  array<int,float>  $sample1
     * @param  array<int,float>  $sample2
     * @return array{
     *   method: string,
     *   d_statistic: ?float,
     *   p_value: ?float,
     *   n1: int,
     *   n2: int,
     *   direction: string,
     *   suppressed: bool,
     *   suppression_reason: ?string
     * }
     */
    public function compare(array $sample1, array $sample2): array
    {
        $n1 = count($sample1);
        $n2 = count($sample2);

        if ($n1 < self::ABSOLUTE_MIN_N_PER_GROUP || $n2 < self::ABSOLUTE_MIN_N_PER_GROUP) {
            return [
                'method' => 'none',
                'd_statistic' => null,
                'p_value' => null,
                'n1' => $n1,
                'n2' => $n2,
                'direction' => 'none',
                'suppressed' => true,
                'suppression_reason' => 'n_below_absolute_min:'.self::ABSOLUTE_MIN_N_PER_GROUP,
            ];
        }

        if ($n1 < self::KS_MIN_N_PER_GROUP || $n2 < self::KS_MIN_N_PER_GROUP) {
            return $this->mannWhitney($sample1, $sample2);
        }

        $sorted1 = $sample1;
        $sorted2 = $sample2;
        sort($sorted1);
        sort($sorted2);

        $combined = array_unique(array_merge($sorted1, $sorted2));
        sort($combined);

        $maxD = 0.0;
        $direction = 'none';
        foreach ($combined as $x) {
            $f1 = $this->ecdf($sorted1, $x);
            $f2 = $this->ecdf($sorted2, $x);
            $diff = $f1 - $f2;
            if (abs($diff) > abs($maxD)) {
                $maxD = abs($diff);
                $direction = $diff > 0 ? 'sample1_lower_distribution' : 'sample1_higher_distribution';
            }
        }

        $z = $maxD * sqrt(($n1 * $n2) / ($n1 + $n2));
        $pValue = $this->kolmogorovP($z);

        return [
            'method' => 'ks',
            'd_statistic' => round($maxD, 6),
            'p_value' => round($pValue, 6),
            'n1' => $n1,
            'n2' => $n2,
            'direction' => $direction,
            'suppressed' => false,
            'suppression_reason' => null,
        ];
    }

    private function ecdf(array $sortedSample, float $x): float
    {
        $n = count($sortedSample);
        $count = 0;
        foreach ($sortedSample as $v) {
            if ($v <= $x) {
                $count++;
            } else {
                break;
            }
        }

        return $count / $n;
    }

    private function kolmogorovP(float $z): float
    {
        if ($z < 1e-7) {
            return 1.0;
        }
        $sum = 0.0;
        for ($k = 1; $k <= self::KOLMOGOROV_TERMS; $k++) {
            $term = (($k % 2 === 1) ? 1 : -1) * exp(-2.0 * $k * $k * $z * $z);
            $sum += $term;
            if (abs($term) < 1e-10) {
                break;
            }
        }
        $p = 2.0 * $sum;

        return max(0.0, min(1.0, $p));
    }

    /**
     * Mann-Whitney U two-sample test, normal approximation.
     * Used when n is below KS threshold but above absolute minimum.
     */
    private function mannWhitney(array $sample1, array $sample2): array
    {
        $n1 = count($sample1);
        $n2 = count($sample2);
        $combined = array_merge(
            array_map(fn ($v) => ['v' => $v, 'g' => 1], $sample1),
            array_map(fn ($v) => ['v' => $v, 'g' => 2], $sample2),
        );
        usort($combined, fn ($a, $b) => $a['v'] <=> $b['v']);

        $rankSum1 = 0.0;
        $i = 0;
        $total = $n1 + $n2;
        while ($i < $total) {
            $j = $i;
            while ($j + 1 < $total && $combined[$j + 1]['v'] === $combined[$i]['v']) {
                $j++;
            }
            $avgRank = ($i + 1 + $j + 1) / 2.0;
            for ($k = $i; $k <= $j; $k++) {
                if ($combined[$k]['g'] === 1) {
                    $rankSum1 += $avgRank;
                }
            }
            $i = $j + 1;
        }

        $u1 = $rankSum1 - ($n1 * ($n1 + 1)) / 2.0;
        $muU = ($n1 * $n2) / 2.0;
        $sigmaU = sqrt(($n1 * $n2 * ($n1 + $n2 + 1)) / 12.0);
        $z = $sigmaU > 0 ? ($u1 - $muU) / $sigmaU : 0.0;

        // Two-tailed p via Abramowitz approx
        $pValue = 2.0 * (1.0 - $this->normalCdfFromZ(abs($z)));

        return [
            'method' => 'mann_whitney',
            'd_statistic' => null,
            'p_value' => round($pValue, 6),
            'n1' => $n1,
            'n2' => $n2,
            'direction' => $z > 0 ? 'sample1_higher_distribution' : ($z < 0 ? 'sample1_lower_distribution' : 'none'),
            'suppressed' => false,
            'suppression_reason' => null,
        ];
    }

    private function normalCdfFromZ(float $z): float
    {
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p = 0.2316419;
        $t = 1.0 / (1.0 + $p * $z);
        $pdf = exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI);

        return 1.0 - $pdf * ($b1 * $t + $b2 * $t ** 2 + $b3 * $t ** 3 + $b4 * $t ** 4 + $b5 * $t ** 5);
    }
}
