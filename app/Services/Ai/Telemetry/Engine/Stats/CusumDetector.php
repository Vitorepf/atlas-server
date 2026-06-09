<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

/**
 * Two-sided tabular CUSUM for change-point detection.
 *
 *   C+_t = max(0, C+_{t-1} + (X_t - μ_ref - k))
 *   C-_t = max(0, C-_{t-1} - (X_t - μ_ref + k))
 *   alarm if C+ > h OR C- > h
 *
 * k = 0.5·σ_ref (detect 1-sigma shifts)
 * h = 4·σ_ref (ARL_0 ≈ 465 — effectively zero false alarms in daily use)
 *
 * Reference (μ_ref, σ_ref) computed from oldest half of the window.
 * Minimum window: 15 days total with at least 7 in reference half.
 *
 * The arithmetic is NOT done in PHP: per the runtime_language_boundary canon it
 * is computed in numpy by runtimes/python/stats_engine and verified through
 * StatsEngineRuntimeClient. If the Python runtime is absent the client throws —
 * there is NO PHP fallback math.
 */
class CusumDetector
{
    public const MIN_WINDOW = 15;
    public const MIN_REFERENCE_HALF = 7;

    public function __construct(
        private readonly StatsEngineRuntimeClient $runtime = new StatsEngineRuntimeClient,
    ) {}

    /**
     * @param  array<int,float>  $series
     * @return array{
     *   fired: bool,
     *   change_point_index: ?int,
     *   direction: string,
     *   magnitude_sigma: ?float,
     *   suppressed: bool,
     *   suppression_reason: ?string
     * }
     */
    public function detect(array $series): array
    {
        /** @var array{fired: bool, change_point_index: ?int, direction: string, magnitude_sigma: ?float, suppressed: bool, suppression_reason: ?string} */
        return $this->runtime->cusum(array_values($series));
    }
}
