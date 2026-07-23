<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdLadderNormalizer;

final class AtlasDepartmentQualityBarLevelClassifier
{
    public const FIELD_SATISFIED = 'satisfied';
    public const FIELD_THRESHOLD = 'threshold';
    public const SCHEMA_VERSION = 'atlas.aaeos.quality_bar_level.v1';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_DEPARTMENT_ID = 'department_id';
    public const FIELD_ACHIEVED_LEVEL = 'achieved_level';
    public const FIELD_ACHIEVED_BAND_INDEX = 'achieved_band_index';
    public const FIELD_HIGHEST_EVALUABLE_LEVEL = 'highest_evaluable_level';
    public const FIELD_ALL_BANDS_SATISFIED = 'all_bands_satisfied';
    public const FIELD_NEXT_LEVEL = 'next_level';
    public const FIELD_PROMOTION_BLOCKED = 'promotion_blocked';
    public const FIELD_LEVEL = 'level';
    public const FIELD_METRIC = 'metric';
    public const FIELD_COMPARATOR = 'comparator';
    public const FIELD_VALUE = 'value';
    public const FIELD_BINDING_BREACHES = 'binding_breaches';
    public const FIELD_EVALUATED_BANDS = 'evaluated_bands';
    public const FIELD_EVALUATED_METRICS = 'evaluated_metrics';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_MISSING_METRIC = 'missing_metric';
    public const FIELD_OBSERVED = 'observed';

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
        $departmentId = AiValueNormalizer::trimmedStringOrNull($departmentId) ?? '';
        $bands = AtlasThresholdLadderNormalizer::levelLadder($bandLadder);
        $evaluatedMetrics = $this->countMeasuredMetrics($measuredMetrics);

        if ($bands === []) {
            return $this->sortByKey([
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_DEPARTMENT_ID => $departmentId,
                self::FIELD_ACHIEVED_LEVEL => null,
                self::FIELD_ACHIEVED_BAND_INDEX => -1,
                self::FIELD_HIGHEST_EVALUABLE_LEVEL => null,
                self::FIELD_ALL_BANDS_SATISFIED => false,
                self::FIELD_NEXT_LEVEL => null,
                self::FIELD_PROMOTION_BLOCKED => false,
                self::FIELD_BINDING_BREACHES => [],
                self::FIELD_EVALUATED_BANDS => 0,
                self::FIELD_EVALUATED_METRICS => $evaluatedMetrics,
            ]);
        }

        $achievedLevel = null;
        $achievedBandIndex = -1;
        $firstFailingIndex = null;

        foreach ($bands as $index => $band) {
            if ($this->bandSatisfied($band, $measuredMetrics)) {
                $achievedLevel = $band[self::FIELD_LEVEL];
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
            $nextLevel = $blockingBand[self::FIELD_LEVEL];
            $bindingBreaches = $this->breachesFor($blockingBand, $measuredMetrics);
        }

        return $this->sortByKey([
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENT_ID => $departmentId,
            self::FIELD_ACHIEVED_LEVEL => $achievedLevel,
            self::FIELD_ACHIEVED_BAND_INDEX => $achievedBandIndex,
            self::FIELD_HIGHEST_EVALUABLE_LEVEL => $bands[count($bands) - 1][self::FIELD_LEVEL],
            self::FIELD_ALL_BANDS_SATISFIED => $allBandsSatisfied,
            self::FIELD_NEXT_LEVEL => $nextLevel,
            self::FIELD_PROMOTION_BLOCKED => $bindingBreaches !== [],
            self::FIELD_BINDING_BREACHES => $bindingBreaches,
            // CONTRATO CONGELADO (teste de 01/06): evaluated_bands = total de
            // bandas do CONTRATO avaliado (consistente com highest_evaluable_
            // level acima), não "visitadas até a 1ª falha". Um auto-merge do
            // Loop em 13/06 (eb14ed9000, pré-O-3/reprove) trocou a semântica
            // sem reconciliar o teste — 5 testes vermelhos por 3 semanas.
            self::FIELD_EVALUATED_BANDS => count($bands),
            self::FIELD_EVALUATED_METRICS => $evaluatedMetrics,
        ]);
    }

    public function comparatorSatisfied(string $comparator, float $observed, float $threshold): bool
    {
        return AtlasThresholdComparator::satisfied($comparator, $observed, $threshold);
    }

    /**
     * @param  array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}  $band
     * @param  array<string, float|int>  $measuredMetrics
     */
    private function bandSatisfied(array $band, array $measuredMetrics): bool
    {
        foreach ($band[self::FIELD_THRESHOLDS] as $threshold) {
            $observed = $this->observedValue($measuredMetrics, $threshold[self::FIELD_METRIC]);

            if ($observed === null) {
                return false;
            }

            if (! $this->comparatorSatisfied($threshold[self::FIELD_COMPARATOR], $observed, $threshold[self::FIELD_VALUE])) {
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

        foreach ($band[self::FIELD_THRESHOLDS] as $threshold) {
            $observed = $this->observedValue($measuredMetrics, $threshold[self::FIELD_METRIC]);
            $missingMetric = $observed === null;

            if (! $missingMetric && $this->comparatorSatisfied($threshold[self::FIELD_COMPARATOR], $observed, $threshold[self::FIELD_VALUE])) {
                continue;
            }

            $breaches[] = [
                self::FIELD_LEVEL => $band[self::FIELD_LEVEL],
                self::FIELD_METRIC => $threshold[self::FIELD_METRIC],
                self::FIELD_COMPARATOR => $threshold[self::FIELD_COMPARATOR],
                self::FIELD_THRESHOLD => $threshold[self::FIELD_VALUE],
                self::FIELD_OBSERVED => $observed,
                self::FIELD_SATISFIED => false,
                self::FIELD_MISSING_METRIC => $missingMetric,
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

        return AiValueNormalizer::finiteFloatOrNull($measuredMetrics[$metric]);
    }

    /**
     * @param  array<string, float|int>  $measuredMetrics
     */
    private function countMeasuredMetrics(array $measuredMetrics): int
    {
        $count = 0;

        foreach ($measuredMetrics as $value) {
            if (AiValueNormalizer::finiteFloatOrNull($value) !== null) {
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
