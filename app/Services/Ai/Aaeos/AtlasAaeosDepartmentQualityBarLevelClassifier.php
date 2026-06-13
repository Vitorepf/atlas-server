<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosDepartmentQualityBarLevelClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.quality_bar_level.v1';

    /**
     * Deterministically classify a department against a caller-supplied, lowest-first
     * band ladder. A band is "satisfied" only when every one of its thresholds holds
     * for a present metric; an absent metric is a hard failure (never silently passed).
     *
     * achieved_level is the highest band such that that band AND every band below it
     * are fully satisfied (standard cumulative climb): once a band fails, the climb
     * stops. next_level is the first band above the achieved one (the band that blocks
     * promotion); binding_breaches enumerates exactly that next band's unmet thresholds
     * (each carrying observed===null + missing_metric===true when the metric is absent).
     * The ladder carries NO embedded department data and identical inputs return an
     * identical array.
     *
     * @param  array<string, float|int>  $measuredMetrics
     * @param  list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return array{
     *     schema_version: string,
     *     department_id: string,
     *     achieved_level: string|null,
     *     achieved_band_index: int,
     *     highest_evaluable_level: string|null,
     *     all_bands_satisfied: bool,
     *     next_level: string|null,
     *     promotion_blocked: bool,
     *     binding_breaches: list<array{level: string, metric: string, comparator: string, threshold: float, observed: float|null, satisfied: false, missing_metric: bool}>,
     *     evaluated_bands: int,
     *     evaluated_metrics: int
     * }
     */
    public function classify(string $departmentId, array $measuredMetrics, array $bandLadder): array
    {
        $bands = AtlasAaeosThresholdLadderNormalizer::levelLadder($bandLadder);
        $evaluatedMetrics = $this->countMeasuredMetrics($measuredMetrics);

        if ($bands === []) {
            return $this->sortByKey([
                'schema_version' => self::SCHEMA_VERSION,
                'department_id' => $departmentId,
                'achieved_level' => null,
                'achieved_band_index' => -1,
                'highest_evaluable_level' => null,
                'all_bands_satisfied' => false,
                'next_level' => null,
                'promotion_blocked' => false,
                'binding_breaches' => [],
                'evaluated_bands' => 0,
                'evaluated_metrics' => $evaluatedMetrics,
            ]);
        }

        $achievedLevel = null;
        $achievedBandIndex = -1;
        $firstFailingIndex = null;

        foreach ($bands as $index => $band) {
            if ($this->bandSatisfied($band, $measuredMetrics)) {
                $achievedLevel = $band['level'];
                $achievedBandIndex = $index;

                continue;
            }

            $firstFailingIndex = $index;
            break;
        }

        $allBandsSatisfied = $achievedBandIndex === count($bands) - 1;

        $nextLevel = null;
        $bindingBreaches = [];

        if ($firstFailingIndex !== null) {
            $blockingBand = $bands[$firstFailingIndex];
            $nextLevel = $blockingBand['level'];
            $bindingBreaches = $this->breachesFor($blockingBand, $measuredMetrics);
        }

        return $this->sortByKey([
            'schema_version' => self::SCHEMA_VERSION,
            'department_id' => $departmentId,
            'achieved_level' => $achievedLevel,
            'achieved_band_index' => $achievedBandIndex,
            'highest_evaluable_level' => $bands[count($bands) - 1]['level'],
            'all_bands_satisfied' => $allBandsSatisfied,
            'next_level' => $nextLevel,
            'promotion_blocked' => $bindingBreaches !== [],
            'binding_breaches' => $bindingBreaches,
            'evaluated_bands' => $firstFailingIndex === null ? count($bands) : $firstFailingIndex + 1,
            'evaluated_metrics' => $evaluatedMetrics,
        ]);
    }

    public function comparatorSatisfied(string $comparator, float $observed, float $threshold): bool
    {
        return AtlasAaeosThresholdComparator::satisfied($comparator, $observed, $threshold);
    }

    /**
     * @param  array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}  $band
     * @param  array<string, float|int>  $measuredMetrics
     */
    private function bandSatisfied(array $band, array $measuredMetrics): bool
    {
        foreach ($band['thresholds'] as $threshold) {
            $observed = $this->observedValue($measuredMetrics, $threshold['metric']);

            if ($observed === null) {
                return false;
            }

            if (! $this->comparatorSatisfied($threshold['comparator'], $observed, $threshold['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}  $band
     * @param  array<string, float|int>  $measuredMetrics
     * @return list<array{level: string, metric: string, comparator: string, threshold: float, observed: float|null, satisfied: false, missing_metric: bool}>
     */
    private function breachesFor(array $band, array $measuredMetrics): array
    {
        $breaches = [];

        foreach ($band['thresholds'] as $threshold) {
            $observed = $this->observedValue($measuredMetrics, $threshold['metric']);
            $missingMetric = $observed === null;

            if (! $missingMetric && $this->comparatorSatisfied($threshold['comparator'], $observed, $threshold['value'])) {
                continue;
            }

            $breaches[] = [
                'level' => $band['level'],
                'metric' => $threshold['metric'],
                'comparator' => $threshold['comparator'],
                'threshold' => $threshold['value'],
                'observed' => $observed,
                'satisfied' => false,
                'missing_metric' => $missingMetric,
            ];
        }

        return $breaches;
    }

    /**
     * @param  array<string, float|int>  $measuredMetrics
     */
    private function observedValue(array $measuredMetrics, string $metric): ?float
    {
        if (! array_key_exists($metric, $measuredMetrics)) {
            return null;
        }

        $value = $measuredMetrics[$metric];

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, float|int>  $measuredMetrics
     */
    private function countMeasuredMetrics(array $measuredMetrics): int
    {
        $count = 0;

        foreach ($measuredMetrics as $value) {
            if (is_int($value) || is_float($value)) {
                $count++;
            }
        }

        return $count;
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
