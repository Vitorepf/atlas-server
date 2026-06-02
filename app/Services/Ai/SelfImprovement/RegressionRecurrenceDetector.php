<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

final class RegressionRecurrenceDetector
{
    public const HARD_REGRESSION_METRICS = [
        'governance_integrity',
        'runtime_safety',
        'business_rule_alignment',
        'regressions_and_new_blockers',
    ];

    private const CHRONIC_RECURRENCE_THRESHOLD = 3;

    /**
     * @param  array<int, array{grade?: mixed, regressed_metrics?: mixed}>  $entries
     * @return array{recurrence_by_metric: array<string, int>, top_metric: string|null, top_recurrence: int, chronic: bool, scanned_cycles: int}
     */
    public function detect(array $entries): array
    {
        $counts = array_fill_keys(self::HARD_REGRESSION_METRICS, 0);

        foreach ($entries as $entry) {
            $regressed = is_array($entry['regressed_metrics'] ?? null) ? $entry['regressed_metrics'] : [];

            foreach (self::HARD_REGRESSION_METRICS as $metric) {
                if (in_array($metric, $regressed, true)) {
                    $counts[$metric]++;
                }
            }
        }

        $recurrenceByMetric = array_filter($counts, static fn (int $count): bool => $count >= 1);

        $topMetric = null;
        $topRecurrence = 0;

        foreach (self::HARD_REGRESSION_METRICS as $metric) {
            if ($counts[$metric] > $topRecurrence) {
                $topMetric = $metric;
                $topRecurrence = $counts[$metric];
            }
        }

        return [
            'recurrence_by_metric' => $recurrenceByMetric,
            'top_metric' => $topMetric,
            'top_recurrence' => $topRecurrence,
            'chronic' => $topRecurrence >= self::CHRONIC_RECURRENCE_THRESHOLD,
            'scanned_cycles' => count($entries),
        ];
    }
}
