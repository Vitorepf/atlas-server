<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

/**
 * Two-sample Kolmogorov-Smirnov distribution comparison.
 *
 *   D = max_x |F1(x) - F2(x)|     max difference between empirical CDFs
 *   z = D · √(n1·n2 / (n1+n2))
 *   p ≈ 2·Σ_{j=1}^{∞} (-1)^{j+1}·exp(-2j²·z²)
 *
 * Mann-Whitney U fallback when n_per_group < 20.
 * Both groups must have n >= 8 minimum (otherwise insufficient_data).
 *
 * The arithmetic is NOT done in PHP: per the runtime_language_boundary canon
 * (and "nunca faça em PHP o que deveria ser Python") the ECDF, the (-1)^j KS
 * series and the Mann-Whitney rank sums are computed in numpy by
 * runtimes/python/stats_engine and verified through StatsEngineRuntimeClient. If
 * the Python runtime is absent the client throws — there is NO PHP fallback math.
 */
class KolmogorovSmirnovTest
{
    public const KS_MIN_N_PER_GROUP = 20;
    public const ABSOLUTE_MIN_N_PER_GROUP = 8;

    public function __construct(
        private readonly StatsEngineRuntimeClient $runtime = new StatsEngineRuntimeClient,
    ) {}

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
        /** @var array{method: string, d_statistic: ?float, p_value: ?float, n1: int, n2: int, direction: string, suppressed: bool, suppression_reason: ?string} */
        return $this->runtime->ks(array_values($sample1), array_values($sample2));
    }
}
