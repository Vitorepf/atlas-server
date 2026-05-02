<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

/**
 * Bootstrap percentile confidence intervals for non-normal distributions.
 *
 * Atlas-pragmatic defaults:
 *   B = 500 replications (CI width error ~10% of true; sufficient for ops report)
 *   95% CI by default
 *   Min n = 10 (below this, CI is meaningless)
 *
 * Deterministic mode via $seed parameter for reproducibility in tests.
 */
class BootstrapCalculator
{
    public const DEFAULT_REPLICATIONS = 500;
    public const MIN_N = 10;

    /**
     * Bootstrap CI for a percentile (e.g. p95 of latency).
     * @return array{lower:?float, upper:?float, center:?float, n:int, method:string}
     */
    public function percentileCi(
        array $values,
        float $quantile = 0.95,
        int $replications = self::DEFAULT_REPLICATIONS,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        return $this->ciViaResampling($values, fn (array $sample) => $this->quantile($sample, $quantile), $replications, $alpha, $seed, 'bootstrap_percentile');
    }

    /**
     * Bootstrap CI for the mean.
     */
    public function meanCi(
        array $values,
        int $replications = self::DEFAULT_REPLICATIONS,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        return $this->ciViaResampling($values, fn (array $sample) => array_sum($sample) / count($sample), $replications, $alpha, $seed, 'bootstrap_mean');
    }

    private function ciViaResampling(array $values, callable $statistic, int $replications, float $alpha, ?int $seed, string $method): array
    {
        $n = count($values);
        if ($n < self::MIN_N) {
            return ['lower' => null, 'upper' => null, 'center' => null, 'n' => $n, 'method' => $method.':n_too_small'];
        }

        if ($seed !== null) {
            mt_srand($seed);
        }

        $stats = [];
        for ($r = 0; $r < $replications; $r++) {
            $sample = [];
            for ($i = 0; $i < $n; $i++) {
                $sample[] = $values[mt_rand(0, $n - 1)];
            }
            $stats[] = $statistic($sample);
        }

        sort($stats);
        $lowerIdx = (int) floor(($alpha / 2.0) * $replications);
        $upperIdx = (int) floor((1.0 - $alpha / 2.0) * $replications);

        return [
            'lower' => round($stats[$lowerIdx], 6),
            'upper' => round($stats[$upperIdx], 6),
            'center' => round($statistic($values), 6),
            'n' => $n,
            'method' => $method,
        ];
    }

    private function quantile(array $values, float $q): float
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);
        $idx = (int) floor($q * ($n - 1));

        return (float) $sorted[$idx];
    }
}
