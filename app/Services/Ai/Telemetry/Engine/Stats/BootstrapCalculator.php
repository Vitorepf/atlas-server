<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

/**
 * Bootstrap percentile confidence intervals for non-normal distributions.
 *
 * Atlas-pragmatic defaults:
 *   B = 500 replications (CI width error ~10% of true; sufficient for ops report)
 *   95% CI by default
 *   Min n = 10 (below this, CI is meaningless)
 *
 * Deterministic mode via $seed parameter for reproducibility in tests.
 *
 * By the runtime_language_boundary canon — and the operator thesis "Python é o
 * melhor para dados; nunca faça em PHP o que deveria ser Python" — the bootstrap
 * resampling math is NOT hand-rolled here in PHP. The PHP kernel only assembles
 * the request and delegates to the real numpy data runtime via
 * StatsEngineRuntimeClient (vectorised draw-with-replacement in numpy). There is
 * NO PHP fallback resampling: if the runtime is absent the boundary raises
 * explicitly (an honest failure, never a silent PHP stand-in).
 *
 * The non-random contract (MIN_N guard + ":n_too_small" suffix, the percentile-
 * index arithmetic, the `center` = exact statistic on the original values, the
 * quantile rule, and the 6-dp rounding) is preserved byte-for-byte in the numpy
 * port, so the swap is behaviour-preserving for everything but the RNG itself —
 * and a bootstrap CI is a Monte Carlo estimator, so old-PHP and new-numpy CIs
 * agree within Monte Carlo error (proven by the equivalence tests).
 */
class BootstrapCalculator
{
    public const DEFAULT_REPLICATIONS = 500;
    public const MIN_N = 10;

    public function __construct(
        private readonly StatsEngineRuntimeClient $stats = new StatsEngineRuntimeClient,
    ) {}

    /**
     * Bootstrap CI for a percentile (e.g. p95 of latency).
     *
     * @return array{lower:?float, upper:?float, center:?float, n:int, method:string}
     */
    public function percentileCi(
        array $values,
        float $quantile = 0.95,
        int $replications = self::DEFAULT_REPLICATIONS,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        /** @var array{lower:?float, upper:?float, center:?float, n:int, method:string} $result */
        $result = $this->stats->bootstrapPercentileCi(
            array_values($values),
            $quantile,
            $replications,
            $alpha,
            $seed,
        );

        return $result;
    }

    /**
     * Bootstrap CI for the mean.
     *
     * @return array{lower:?float, upper:?float, center:?float, n:int, method:string}
     */
    public function meanCi(
        array $values,
        int $replications = self::DEFAULT_REPLICATIONS,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        /** @var array{lower:?float, upper:?float, center:?float, n:int, method:string} $result */
        $result = $this->stats->bootstrapMeanCi(
            array_values($values),
            $replications,
            $alpha,
            $seed,
        );

        return $result;
    }
}
