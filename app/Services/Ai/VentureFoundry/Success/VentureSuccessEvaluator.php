<?php

namespace App\Services\Ai\VentureFoundry\Success;

use App\Models\AiVenture;
use App\Models\AiVentureMetricObservation;

/**
 * Determines whether a venture has reached SUSTAINED recurring revenue —
 * the success definition for the company success engine (meta >=70%).
 *
 * Success = MRR (config `success_metric_key`) >= threshold maintained over
 * >= N consecutive calendar months. Evaluated ONLY against persisted metric
 * observations; never auto-declared. No observation -> `insufficient_data`.
 *
 * States:
 *   - insufficient_data : fewer than N distinct months observed
 *   - not_yet           : N+ months observed, but no qualifying trailing streak
 *   - succeeded         : trailing run of >= N consecutive months all >= threshold
 *   - failed            : reached a qualifying streak in the past, then churned
 *                         below threshold (latest months no longer qualify)
 */
class VentureSuccessEvaluator
{
    public const STATUS_INSUFFICIENT = 'insufficient_data';

    public const STATUS_NOT_YET = 'not_yet';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array{
     *   status: string,
     *   metric_key: string,
     *   threshold: float,
     *   min_months: int,
     *   months_observed: int,
     *   trailing_streak: int,
     *   best_streak: int,
     *   succeeded_month: ?string,
     *   monthly: array<int,array{month: string, value: float}>
     * }
     */
    public function evaluate(AiVenture $venture): array
    {
        $metricKey = (string) config('atlas_venture_foundry.success_metric_key', 'mrr');
        $threshold = (float) config('atlas_venture_foundry.success_mrr_threshold', 1000.0);
        $minMonths = max(1, (int) config('atlas_venture_foundry.success_min_consecutive_months', 3));

        $monthly = $this->monthlySeries($venture, $metricKey);
        $monthsObserved = count($monthly);

        // Longest run anywhere (best_streak), trailing run ending at the most
        // recent month (trailing_streak), and the first month a qualifying
        // streak completed.
        $bestStreak = 0;
        $run = 0;
        $succeededMonth = null;
        foreach ($monthly as $i => $point) {
            $consecutive = $i > 0 && $this->isNextMonth($monthly[$i - 1]['month'], $point['month']);
            if ($point['value'] >= $threshold) {
                $run = $consecutive ? $run + 1 : 1;
                if ($run >= $minMonths && $succeededMonth === null) {
                    $succeededMonth = $point['month'];
                }
            } else {
                $run = 0;
            }
            $bestStreak = max($bestStreak, $run);
        }

        $trailingStreak = $this->trailingStreak($monthly, $threshold);

        $status = $this->classify($monthsObserved, $minMonths, $trailingStreak, $bestStreak);

        return [
            'status' => $status,
            'metric_key' => $metricKey,
            'threshold' => $threshold,
            'min_months' => $minMonths,
            'months_observed' => $monthsObserved,
            'trailing_streak' => $trailingStreak,
            'best_streak' => $bestStreak,
            'succeeded_month' => $succeededMonth,
            'monthly' => $monthly,
        ];
    }

    private function classify(int $monthsObserved, int $minMonths, int $trailingStreak, int $bestStreak): string
    {
        if ($trailingStreak >= $minMonths) {
            return self::STATUS_SUCCEEDED;
        }
        if ($bestStreak >= $minMonths) {
            // Hit the bar before, but the latest months fell below it.
            return self::STATUS_FAILED;
        }
        if ($monthsObserved < $minMonths) {
            return self::STATUS_INSUFFICIENT;
        }

        return self::STATUS_NOT_YET;
    }

    /**
     * One value per calendar month (the last observation in each month, by
     * observed_at), ascending.
     *
     * @return array<int,array{month: string, value: float}>
     */
    private function monthlySeries(AiVenture $venture, string $metricKey): array
    {
        $observations = AiVentureMetricObservation::query()
            ->where('venture_id', $venture->id)
            ->where('metric_key', $metricKey)
            ->orderBy('observed_at')
            ->orderBy('created_at')
            ->get();

        $byMonth = [];
        foreach ($observations as $observation) {
            $month = $observation->observed_at?->format('Y-m');
            if ($month === null) {
                continue;
            }
            $byMonth[$month] = (float) $observation->value; // later obs in month wins
        }

        ksort($byMonth);

        $series = [];
        foreach ($byMonth as $month => $value) {
            $series[] = ['month' => (string) $month, 'value' => $value];
        }

        return $series;
    }

    /**
     * @param  array<int,array{month: string, value: float}>  $monthly
     */
    private function trailingStreak(array $monthly, float $threshold): int
    {
        $streak = 0;
        for ($i = count($monthly) - 1; $i >= 0; $i--) {
            if ($monthly[$i]['value'] < $threshold) {
                break;
            }
            if ($i < count($monthly) - 1 && ! $this->isNextMonth($monthly[$i]['month'], $monthly[$i + 1]['month'])) {
                break; // gap in calendar months breaks the trailing run
            }
            $streak++;
        }

        return $streak;
    }

    private function isNextMonth(string $prev, string $next): bool
    {
        return $this->monthIndex($next) - $this->monthIndex($prev) === 1;
    }

    private function monthIndex(string $month): int
    {
        [$year, $mon] = array_map('intval', explode('-', $month));

        return $year * 12 + ($mon - 1);
    }
}
