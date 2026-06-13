<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

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
final class AtlasAaeosDepartmentMaturityBandClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_maturity_band.v1';

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
        $bands = AtlasAaeosThresholdLadderNormalizer::rankedBandLadder($bandLadder);

        $perBand = [];
        $qualifiedBand = null;
        $qualifiedRank = -1;
        $hasQualified = false;

        foreach ($bands as $band) {
            $breaches = $this->evaluateBand($band['thresholds'], $metricsSnapshot);
            $qualifies = $breaches === [];

            $perBand[] = [
                'band' => $band['band'],
                'rank' => $band['rank'],
                'qualifies' => $qualifies,
                'breaches' => $breaches,
            ];

            if ($qualifies && (! $hasQualified || $band['rank'] > $qualifiedRank)) {
                $hasQualified = true;
                $qualifiedBand = $band['band'];
                $qualifiedRank = $band['rank'];
            }
        }

        $allBandsBreached = ! $hasQualified;

        if ($allBandsBreached) {
            $qualifiedBand = null;
            $qualifiedRank = -1;
        }

        [$nextBand, $nextBandBreaches] = $this->resolveNextBand($perBand, $qualifiedRank);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'qualified_band' => $qualifiedBand,
            'qualified_rank' => $qualifiedRank,
            'all_bands_breached' => $allBandsBreached,
            'per_band' => $perBand,
            'next_band' => $nextBand,
            'next_band_breaches' => $nextBandBreaches,
            'promotion_blocked' => $nextBand !== null,
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
            $snapshot = $departmentSnapshots[$departmentId] ?? [];

            $departments[$departmentId] = $this->classify(
                is_array($ladder) ? $ladder : [],
                is_array($snapshot) ? $snapshot : [],
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'departments' => $departments,
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
            $metric = $threshold['metric'];
            $missing = ! array_key_exists($metric, $metricsSnapshot);
            $observed = $this->observedValue($metricsSnapshot, $metric);

            if ($missing || $observed === null || ! AtlasAaeosThresholdComparator::binarySatisfied($threshold['comparator'], $observed, $threshold['value'])) {
                $breaches[] = [
                    'metric' => $metric,
                    'comparator' => $threshold['comparator'],
                    'threshold' => $threshold['value'],
                    'observed' => $observed,
                    'missing' => $missing || $observed === null,
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
            if ($row['rank'] <= $qualifiedRank) {
                continue;
            }

            if ($nextRow === null || $row['rank'] < $nextRow['rank']) {
                $nextRow = $row;
            }
        }

        if ($nextRow === null) {
            return [null, []];
        }

        return [
            ['band' => $nextRow['band'], 'rank' => $nextRow['rank']],
            $nextRow['breaches'],
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

        $value = $metricsSnapshot[$metric];

        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;

            return is_finite($value) ? $value : null;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return (float) $value;
    }

}
