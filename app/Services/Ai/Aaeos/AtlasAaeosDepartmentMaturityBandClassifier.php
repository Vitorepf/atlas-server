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
        $bands = $this->normalizeLadder($bandLadder);

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

            if ($missing || $observed === null || ! $this->meetsThreshold($observed, $threshold['comparator'], $threshold['value'])) {
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
     * @param  list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function normalizeLadder(array $bandLadder): array
    {
        if (! array_is_list($bandLadder)) {
            return [];
        }

        $bands = [];

        foreach ($bandLadder as $band) {
            if (! is_array($band)
                || ! isset($band['band'], $band['rank'])
                || ! is_string($band['band'])
                || ! is_int($band['rank'])
            ) {
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
                'band' => $band['band'],
                'rank' => $band['rank'],
                'thresholds' => $normalizedThresholds,
            ];
        }

        return $bands;
    }
}
