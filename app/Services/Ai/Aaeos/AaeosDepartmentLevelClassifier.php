<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AaeosDepartmentLevelClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_level_classification.v1';

    /**
     * Deterministically walk a caller-supplied (bottom-up ordered) band ladder and
     * compute the highest band whose every threshold is satisfied by the metrics
     * snapshot. The ladder is NOT embedded; thresholds use a 1e-9 epsilon comparator
     * mirroring DepartmentQualityBarThresholdContract::meetsThreshold.
     *
     * @param  array<string, float>  $metricsSnapshot
     * @param  list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return array{
     *     schema_version: string,
     *     department_id: string,
     *     earned_level: string|null,
     *     earned_level_index: int,
     *     highest_band_offered: string,
     *     all_bands_satisfied: bool,
     *     capping_metric: array{level: string, metric: string, comparator: string, threshold: float, observed: float|null}|null,
     *     missing_metrics: list<string>,
     *     evaluated_bands: list<array{level: string, satisfied: bool, failed_thresholds: list<array{metric: string, comparator: string, threshold: float, observed: float|null}>}>
     * }
     */
    public function classify(string $departmentId, array $metricsSnapshot, array $bandLadder): array
    {
        $bands = $this->normalizeLadder($bandLadder);

        if ($bands === []) {
            return $this->sortByKey([
                'schema_version' => self::SCHEMA_VERSION,
                'department_id' => $departmentId,
                'earned_level' => null,
                'earned_level_index' => -1,
                'highest_band_offered' => '',
                'all_bands_satisfied' => false,
                'capping_metric' => null,
                'missing_metrics' => [],
                'evaluated_bands' => [],
            ]);
        }

        $evaluatedBands = [];
        $missingMetrics = [];

        $earnedLevel = null;
        $earnedLevelIndex = -1;
        $climbing = true;
        $cappingMetric = null;

        foreach ($bands as $index => $band) {
            $failedThresholds = [];

            foreach ($band['thresholds'] as $threshold) {
                $metric = $threshold['metric'];
                $observed = $this->observedValue($metricsSnapshot, $metric);

                if ($observed === null) {
                    $missingMetrics[] = $metric;
                }

                if ($observed === null || ! $this->meetsThreshold($observed, $threshold['comparator'], $threshold['value'])) {
                    $failedThresholds[] = [
                        'metric' => $metric,
                        'comparator' => $threshold['comparator'],
                        'threshold' => $threshold['value'],
                        'observed' => $observed,
                    ];
                }
            }

            $satisfied = $failedThresholds === [];

            if ($satisfied && $climbing) {
                $earnedLevel = $band['level'];
                $earnedLevelIndex = $index;
            } elseif (! $satisfied && $climbing) {
                $climbing = false;
                $firstFailed = $failedThresholds[0];
                $cappingMetric = [
                    'level' => $band['level'],
                    'metric' => $firstFailed['metric'],
                    'comparator' => $firstFailed['comparator'],
                    'threshold' => $firstFailed['threshold'],
                    'observed' => $firstFailed['observed'],
                ];
            }

            $evaluatedBands[] = [
                'level' => $band['level'],
                'satisfied' => $satisfied,
                'failed_thresholds' => $failedThresholds,
            ];
        }

        $highestBandOffered = $bands[count($bands) - 1]['level'];
        $allBandsSatisfied = $earnedLevelIndex === count($bands) - 1;

        return $this->sortByKey([
            'schema_version' => self::SCHEMA_VERSION,
            'department_id' => $departmentId,
            'earned_level' => $earnedLevel,
            'earned_level_index' => $earnedLevelIndex,
            'highest_band_offered' => $highestBandOffered,
            'all_bands_satisfied' => $allBandsSatisfied,
            'capping_metric' => $cappingMetric,
            'missing_metrics' => $this->sortedUnique($missingMetrics),
            'evaluated_bands' => $evaluatedBands,
        ]);
    }

    private function meetsThreshold(float $observed, string $comparator, float $threshold): bool
    {
        return match ($comparator) {
            '>=' => $observed + 1e-9 >= $threshold,
            '<=' => $observed - 1e-9 <= $threshold,
            default => false,
        };
    }

    /**
     * @param  array<string, float>  $metricsSnapshot
     */
    private function observedValue(array $metricsSnapshot, string $metric): ?float
    {
        if (! array_key_exists($metric, $metricsSnapshot)) {
            return null;
        }

        $value = $metricsSnapshot[$metric];

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function normalizeLadder(array $bandLadder): array
    {
        if ($bandLadder === [] || ! array_is_list($bandLadder)) {
            return [];
        }

        $bands = [];

        foreach ($bandLadder as $band) {
            if (! is_array($band) || ! isset($band['level']) || ! is_string($band['level'])) {
                return [];
            }

            $thresholds = $band['thresholds'] ?? null;

            if (! is_array($thresholds) || ! array_is_list($thresholds)) {
                return [];
            }

            $normalizedThresholds = [];

            foreach ($thresholds as $threshold) {
                if (! is_array($threshold)
                    || ! isset($threshold['metric'], $threshold['comparator'], $threshold['value'])
                    || ! is_string($threshold['metric'])
                    || ! is_string($threshold['comparator'])
                    || (! is_int($threshold['value']) && ! is_float($threshold['value']))
                ) {
                    return [];
                }

                $normalizedThresholds[] = [
                    'metric' => $threshold['metric'],
                    'comparator' => $threshold['comparator'],
                    'value' => (float) $threshold['value'],
                ];
            }

            $bands[] = [
                'level' => $band['level'],
                'thresholds' => $normalizedThresholds,
            ];
        }

        return $bands;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sortByKey(array $payload): array
    {
        ksort($payload);

        return $payload;
    }
}
