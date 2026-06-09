<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

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
 *
 * The arithmetic is NOT done in PHP: per the runtime_language_boundary canon
 * (and "nunca faça em PHP o que deveria ser Python") it is computed in numpy by
 * runtimes/python/stats_engine and verified through StatsEngineRuntimeClient. If
 * the Python runtime is absent the client throws — there is NO PHP fallback math
 * (the O(n²) sign loop and the Abramowitz-Stegun normal CDF moved to numpy).
 */
class MannKendallAnalyzer
{
    public const MIN_N = 7;

    public function __construct(
        private readonly StatsEngineRuntimeClient $runtime = new StatsEngineRuntimeClient,
    ) {}

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
        /** @var array{n: int, s_statistic: int, z: ?float, p_value: ?float, sens_slope: ?float, direction: string, suppressed: bool, suppression_reason: ?string} */
        return $this->runtime->mannKendall(array_values($series));
    }
}
