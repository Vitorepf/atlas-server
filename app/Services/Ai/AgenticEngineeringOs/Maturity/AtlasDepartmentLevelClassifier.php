<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdLadderNormalizer;

final class AtlasDepartmentLevelClassifier
{
    public const FIELD_VALUE = 'value';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const SCHEMA_VERSION = 'atlas.aaeos.department_level_classification.v1';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_DEPARTMENT_ID = 'department_id';
    public const FIELD_EARNED_LEVEL = 'earned_level';
    public const FIELD_EARNED_LEVEL_INDEX = 'earned_level_index';
    public const FIELD_HIGHEST_BAND_OFFERED = 'highest_band_offered';
    public const FIELD_ALL_BANDS_SATISFIED = 'all_bands_satisfied';
    public const FIELD_CAPPING_METRIC = 'capping_metric';
    public const FIELD_MISSING_METRICS = 'missing_metrics';
    public const FIELD_LEVEL = 'level';
    public const FIELD_COMPARATOR = 'comparator';
    public const FIELD_METRIC = 'metric';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_OBSERVED = 'observed';
    public const FIELD_EVALUATED_BANDS = 'evaluated_bands';
    public const FIELD_FAILED_THRESHOLDS = 'failed_thresholds';
    public const FIELD_SATISFIED = 'satisfied';

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
        $departmentId = AiValueNormalizer::trimmedStringOrNull($departmentId) ?? '';
        $bands = AtlasThresholdLadderNormalizer::levelLadder($bandLadder);

        if ($bands === []) {
            return $this->sortByKey([
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_DEPARTMENT_ID => $departmentId,
                self::FIELD_EARNED_LEVEL => null,
                self::FIELD_EARNED_LEVEL_INDEX => -1,
                self::FIELD_HIGHEST_BAND_OFFERED => '',
                self::FIELD_ALL_BANDS_SATISFIED => false,
                self::FIELD_CAPPING_METRIC => null,
                self::FIELD_MISSING_METRICS => [],
                self::FIELD_EVALUATED_BANDS => [],
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

            foreach ($band[self::FIELD_THRESHOLDS] as $threshold) {
                $metric = $threshold[self::FIELD_METRIC];
                $observed = $this->observedValue($metricsSnapshot, $metric);

                if ($observed === null) {
                    $missingMetrics[] = $metric;
                }

                if ($observed === null || ! AtlasThresholdComparator::binarySatisfied($threshold[self::FIELD_COMPARATOR], $observed, $threshold[self::FIELD_VALUE])) {
                    $failedThresholds[] = [
                        self::FIELD_METRIC => $metric,
                        self::FIELD_COMPARATOR => $threshold[self::FIELD_COMPARATOR],
                        self::FIELD_THRESHOLD => $threshold[self::FIELD_VALUE],
                        self::FIELD_OBSERVED => $observed,
                    ];
                }
            }

            $satisfied = $failedThresholds === [];

            if ($satisfied && $climbing) {
                $earnedLevel = $band[self::FIELD_LEVEL];
                $earnedLevelIndex = $index;
            } elseif (! $satisfied && $climbing) {
                $climbing = false;
                $firstFailed = $failedThresholds[0];
                $cappingMetric = [
                    self::FIELD_LEVEL => $band[self::FIELD_LEVEL],
                    self::FIELD_METRIC => $firstFailed[self::FIELD_METRIC],
                    self::FIELD_COMPARATOR => $firstFailed[self::FIELD_COMPARATOR],
                    self::FIELD_THRESHOLD => $firstFailed[self::FIELD_THRESHOLD],
                    self::FIELD_OBSERVED => $firstFailed[self::FIELD_OBSERVED],
                ];
            }

            $evaluatedBands[] = [
                self::FIELD_LEVEL => $band[self::FIELD_LEVEL],
                self::FIELD_SATISFIED => $satisfied,
                self::FIELD_FAILED_THRESHOLDS => $failedThresholds,
            ];
        }

        $highestBandOffered = $bands[count($bands) - 1][self::FIELD_LEVEL];
        $allBandsSatisfied = $earnedLevelIndex === count($bands) - 1;

        return $this->sortByKey([
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENT_ID => $departmentId,
            self::FIELD_EARNED_LEVEL => $earnedLevel,
            self::FIELD_EARNED_LEVEL_INDEX => $earnedLevelIndex,
            self::FIELD_HIGHEST_BAND_OFFERED => $highestBandOffered,
            self::FIELD_ALL_BANDS_SATISFIED => $allBandsSatisfied,
            self::FIELD_CAPPING_METRIC => $cappingMetric,
            self::FIELD_MISSING_METRICS => AtlasStringListNormalizer::uniqueSortedStrings($missingMetrics),
            self::FIELD_EVALUATED_BANDS => $evaluatedBands,
        ]);
    }

    /**
     * @param  array<string, float>  $metricsSnapshot
     */
    private function observedValue(array $metricsSnapshot, string $metric): ?float
    {
        if (! array_key_exists($metric, $metricsSnapshot)) {
            return null;
        }

        return AiValueNormalizer::finiteFloatOrNull($metricsSnapshot[$metric]);
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
