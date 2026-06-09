<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

/**
 * EWMA control chart for single-day anomaly detection over a daily series.
 *
 *   S_t  = α·X_t + (1-α)·S_{t-1}                    smoothed level
 *   V_t  = α·(X_t - S_{t-1})² + (1-α)·V_{t-1}        smoothed variance
 *   σ_t  = √(V_t / α)
 *   anomaly if |X_t - S_{t-1}| > k·σ_t
 *
 * Defaults from Agent 3 design (calibrated for Atlas at 10-100 traces/day):
 *   α=0.20 (memory ~5 days), k=2.5 sigma, warm-up 10 days.
 *
 * Latency metrics use log-scale wrapper externally; this class is unit-agnostic.
 * When sigma collapses to ~0 (constant or near-constant series), anomaly is
 * suppressed instead of dividing by zero.
 *
 * The arithmetic is NOT done in PHP: per the runtime_language_boundary canon it
 * is computed in numpy by runtimes/python/stats_engine and verified through
 * StatsEngineRuntimeClient. If the Python runtime is absent the client throws —
 * there is NO PHP fallback math.
 */
class EwmaDetector
{
    public const DEFAULT_ALPHA = 0.20;
    public const DEFAULT_K_SIGMA = 2.5;
    public const DEFAULT_WARMUP_DAYS = 10;

    public function __construct(
        private readonly StatsEngineRuntimeClient $runtime = new StatsEngineRuntimeClient,
    ) {}

    /**
     * @param  array<int,float>  $series  ordered daily values (oldest first), nulls excluded by caller
     * @return array{
     *   anomaly: bool,
     *   z_score: ?float,
     *   baseline_ewma: ?float,
     *   sigma: ?float,
     *   today: ?float,
     *   confidence: string,
     *   warmup_days_remaining: int
     * }
     */
    public function detect(
        array $series,
        float $alpha = self::DEFAULT_ALPHA,
        float $kSigma = self::DEFAULT_K_SIGMA,
        int $warmupDays = self::DEFAULT_WARMUP_DAYS,
    ): array {
        /** @var array{anomaly: bool, z_score: ?float, baseline_ewma: ?float, sigma: ?float, today: ?float, confidence: string, warmup_days_remaining: int} */
        return $this->runtime->ewma(array_values($series), $alpha, $kSigma, $warmupDays);
    }
}
