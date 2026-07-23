<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdLadderNormalizer;

/**
 * Deterministically classify one department against a caller-supplied,
 * lowest-first maturity band ladder and compute the highest qualifying band,
 * every per-band qualifies/breaches row, the next band that blocks promotion
 * and the exact breaches holding it back. The ladder is NEVER embedded; the
 * caller owns the canonical thresholds (e.g. the Forge ladder from the quality
 * bar matrix). Threshold comparison mirrors
 * DepartmentQualityBarThresholdContract::meetsThreshold byte-for-byte (1e-9
 * epsilon) so this class stays consistent with the rest of the AAEOS ladder.
 */
final class AtlasDepartmentMaturityBandClassifier
{
    public const FIELD_NEXT_BAND = 'next_band';
    public const FIELD_NEXT_BAND_BREACHES = 'next_band_breaches';
    public const SCHEMA_VERSION = 'atlas.aaeos.department_maturity_band.v1';

    public const FIELD_MISSING = 'missing';
    public const FIELD_BAND = 'band';
    public const FIELD_RANK = 'rank';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_QUALIFIES = 'qualifies';
    public const FIELD_BREACHES = 'breaches';
    public const FIELD_QUALIFIED_BAND = 'qualified_band';
    public const FIELD_QUALIFIED_RANK = 'qualified_rank';
    public const FIELD_PROMOTION_BLOCKED = 'promotion_blocked';
    public const FIELD_COMPARATOR = 'comparator';
    public const FIELD_METRIC = 'metric';
    public const FIELD_VALUE = 'value';
    public const FIELD_ALL_BANDS_BREACHED = 'all_bands_breached';
    public const FIELD_DEPARTMENTS = 'departments';
    public const FIELD_OBSERVED = 'observed';
    public const FIELD_PER_BAND = 'per_band';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_THRESHOLDS = 'thresholds';

    /**
     * @param  list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder  ordered lowest-first
     * @param  array<string, float>  $metricsSnapshot
     * @return array{
     *     schema_version: string,
     *     qualified_band: string|null,
     *     qualified_rank: int,
     *     all_bands_breached: bool,
     *     per_band: list<array{band: string, rank: int, qualifies: bool, breaches: list<array{metric: string, comparator: string, threshold: float, observed: float|null, missing: bool}>}>,
     *     next_band: array{band: string, rank: int}|null,
     *     next_band_breaches: list<array{metric: string, comparator: string, threshold: float, observed: float|null, missing: bool}>,
     *     promotion_blocked: bool
     * }
     */
    public function classify(array $bandLadder, array $metricsSnapshot): array
    {
        $bands = AtlasThresholdLadderNormalizer::rankedBandLadder($bandLadder);

        $perBand = [];
        $qualifiedBand = null;
        $qualifiedRank = -1;
        $hasQualified = false;

        foreach ($bands as $band) {
            $breaches = $this->evaluateBand($band[self::FIELD_THRESHOLDS], $metricsSnapshot);
            $qualifies = $breaches === [];

            $perBand[] = [
                self::FIELD_BAND => $band[self::FIELD_BAND],
                self::FIELD_RANK => $band[self::FIELD_RANK],
                self::FIELD_QUALIFIES => $qualifies,
                self::FIELD_BREACHES => $breaches,
            ];

            if ($qualifies && (! $hasQualified || $band[self::FIELD_RANK] > $qualifiedRank)) {
                $hasQualified = true;
                $qualifiedBand = $band[self::FIELD_BAND];
                $qualifiedRank = $band[self::FIELD_RANK];
            }
        }

        $allBandsBreached = ! $hasQualified;

        if ($allBandsBreached) {
            $qualifiedBand = null;
            $qualifiedRank = -1;
        }

        [$nextBand, $nextBandBreaches] = $this->resolveNextBand($perBand, $qualifiedRank);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_QUALIFIED_BAND => $qualifiedBand,
            self::FIELD_QUALIFIED_RANK => $qualifiedRank,
            self::FIELD_ALL_BANDS_BREACHED => $allBandsBreached,
            self::FIELD_PER_BAND => $perBand,
            self::FIELD_NEXT_BAND => $nextBand,
            self::FIELD_NEXT_BAND_BREACHES => $nextBandBreaches,
            self::FIELD_PROMOTION_BLOCKED => $nextBand !== null,
        ];
    }

    /**
     * @param  array<string, list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>>  $departmentBandLadders
     * @param  array<string, array<string, float>>  $departmentSnapshots
     * @return array{schema_version: string, departments: array<string, array<string, mixed>>}
     */
    public function classifyDepartments(array $departmentBandLadders, array $departmentSnapshots): array
    {
        $departments = [];

        foreach ($departmentBandLadders as $departmentId => $ladder) {
            $id = AiValueNormalizer::trimmedStringOrNull((string) $departmentId) ?? '';
            $snapshot = $departmentSnapshots[$departmentId] ?? $departmentSnapshots[$id] ?? [];

            $departments[$id] = $this->classify(
                AiValueNormalizer::arrayOrEmpty($ladder),
                AiValueNormalizer::arrayOrEmpty($snapshot),
            );
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENTS => $departments,
        ];
    }

    /**
     * @param  list<array{metric: string, comparator: string, value: float}>  $thresholds
     * @param  array<string, float>  $metricsSnapshot
     * @return list<array{metric: string, comparator: string, threshold: float, observed: float|null, missing: bool}>
     */
    private function evaluateBand(array $thresholds, array $metricsSnapshot): array
    {
        $breaches = [];

        foreach ($thresholds as $threshold) {
            $metric = $threshold[self::FIELD_METRIC];
            $missing = ! array_key_exists($metric, $metricsSnapshot);
            $observed = $this->observedValue($metricsSnapshot, $metric);

            if ($missing || $observed === null || ! AtlasThresholdComparator::binarySatisfied($threshold[self::FIELD_COMPARATOR], $observed, $threshold[self::FIELD_VALUE])) {
                $breaches[] = [
                    self::FIELD_METRIC => $metric,
                    self::FIELD_COMPARATOR => $threshold[self::FIELD_COMPARATOR],
                    self::FIELD_THRESHOLD => $threshold[self::FIELD_VALUE],
                    self::FIELD_OBSERVED => $observed,
                    self::FIELD_MISSING => $missing || $observed === null,
                ];
            }
        }

        return $breaches;
    }

    /**
     * Lowest band whose rank is strictly above the qualified rank. With the
     * qualified band being the highest qualifying rank, every band above it must
     * fail, so the lowest such band is exactly the promotion-blocking next band.
     *
     * @param  list<array{band: string, rank: int, qualifies: bool, breaches: list<array{metric: string, comparator: string, threshold: float, observed: float|null, missing: bool}>}>  $perBand
     * @return array{0: array{band: string, rank: int}|null, 1: list<array{metric: string, comparator: string, threshold: float, observed: float|null, missing: bool}>}
     */
    private function resolveNextBand(array $perBand, int $qualifiedRank): array
    {
        $nextRow = null;

        foreach ($perBand as $row) {
            if ($row[self::FIELD_RANK] <= $qualifiedRank) {
                continue;
            }

            if ($nextRow === null || $row[self::FIELD_RANK] < $nextRow[self::FIELD_RANK]) {
                $nextRow = $row;
            }
        }

        if ($nextRow === null) {
            return [null, []];
        }

        return [
            [self::FIELD_BAND => $nextRow[self::FIELD_BAND], self::FIELD_RANK => $nextRow[self::FIELD_RANK]],
            $nextRow[self::FIELD_BREACHES],
        ];
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

}
