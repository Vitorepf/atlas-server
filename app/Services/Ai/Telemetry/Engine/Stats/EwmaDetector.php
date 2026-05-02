<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

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
 */
class EwmaDetector
{
    public const DEFAULT_ALPHA = 0.20;
    public const DEFAULT_K_SIGMA = 2.5;
    public const DEFAULT_WARMUP_DAYS = 10;
    private const SIGMA_FLOOR = 1e-6;

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
        $n = count($series);
        if ($n === 0) {
            return $this->emptyResult($warmupDays);
        }

        $today = (float) end($series);
        if ($n < $warmupDays) {
            return [
                'anomaly' => false,
                'z_score' => null,
                'baseline_ewma' => null,
                'sigma' => null,
                'today' => $today,
                'confidence' => 'cold_start',
                'warmup_days_remaining' => $warmupDays - $n,
            ];
        }

        // Initialize EWMA state from first observation
        $level = (float) $series[0];
        $variance = 0.0;

        // Update EWMA with each observation EXCEPT the last (today).
        // Today is what we test against the previous level.
        for ($i = 1; $i < $n - 1; $i++) {
            $x = (float) $series[$i];
            $residual = $x - $level;
            $variance = $alpha * ($residual ** 2) + (1.0 - $alpha) * $variance;
            $level = $alpha * $x + (1.0 - $alpha) * $level;
        }

        $sigma = sqrt($variance / max($alpha, self::SIGMA_FLOOR));
        if ($sigma < self::SIGMA_FLOOR) {
            return [
                'anomaly' => false,
                'z_score' => null,
                'baseline_ewma' => $level,
                'sigma' => $sigma,
                'today' => $today,
                'confidence' => 'variance_too_low',
                'warmup_days_remaining' => 0,
            ];
        }

        $zScore = ($today - $level) / $sigma;
        $anomaly = abs($zScore) > $kSigma;

        return [
            'anomaly' => $anomaly,
            'z_score' => round($zScore, 4),
            'baseline_ewma' => round($level, 4),
            'sigma' => round($sigma, 4),
            'today' => $today,
            'confidence' => 'full',
            'warmup_days_remaining' => 0,
        ];
    }

    private function emptyResult(int $warmupDays): array
    {
        return [
            'anomaly' => false,
            'z_score' => null,
            'baseline_ewma' => null,
            'sigma' => null,
            'today' => null,
            'confidence' => 'cold_start',
            'warmup_days_remaining' => $warmupDays,
        ];
    }
}
