<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

/**
 * Pure coverage-gap scorer for Atlas Dev Test Selection Intelligence.
 *
 * Given the production files a change touched, the files a test run actually
 * covered, and the declared risk level, this collapses them into a single
 * deterministic gap verdict: how many production files stayed uncovered, the
 * coverage ratio, a gap band and a risk-aware confidence ceiling. Same input
 * always yields the same output; nothing here touches I/O, the filesystem, the
 * clock, randomness or any collaborator. A "production file" is any path that
 * does NOT begin with 'tests/' (mirroring the tests/-exclusion convention used
 * throughout Atlas Dev test-selection logic).
 */
final class TestSelectionCoverageGapScorer
{
    public const SCHEMA_VERSION = 'atlas.programming.test_coverage_gap.v1';

    public const GAP_BAND_NONE = 'none';

    public const GAP_BAND_PARTIAL = 'partial';

    public const GAP_BAND_SEVERE = 'severe';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /**
     * Risk levels that, even on a fully-covered change, cap confidence at
     * medium because the blast radius warrants a wider safety net.
     *
     * @var list<string>
     */
    private const ELEVATED_RISK_LEVELS = ['R4', 'R5', 'high', 'critical'];

    /**
     * @param  array<int|string, mixed>  $changedFiles
     * @param  array<int|string, mixed>  $coveredFiles
     * @return array{
     *     schema_version: string,
     *     uncovered_production_files: list<string>,
     *     production_file_count: int,
     *     covered_count: int,
     *     coverage_ratio: float,
     *     gap_band: string,
     *     confidence_ceiling: string
     * }
     */
    public function score(array $changedFiles, array $coveredFiles, string $riskLevel): array
    {
        $productionFiles = $this->productionPaths($changedFiles);
        $coveredLookup = $this->coveredLookup($coveredFiles);

        $uncoveredFiles = [];
        $coveredCount = 0;

        foreach ($productionFiles as $path) {
            if (isset($coveredLookup[$path])) {
                $coveredCount++;

                continue;
            }

            // Append the original string value, never an array key: a pure-numeric
            // path like '123' used as a key would be coerced to int and break the
            // declared list<string> contract. $productionFiles is already distinct.
            $uncoveredFiles[] = $path;
        }

        sort($uncoveredFiles, SORT_STRING);

        $productionFileCount = count($productionFiles);

        // R1: with no production files there is nothing to cover -> full ratio.
        if ($productionFileCount === 0) {
            $coverageRatio = 1.0;
        } else {
            // R2: ratio is covered production files over production files, 2dp.
            $coverageRatio = round($coveredCount / $productionFileCount, 2);
        }

        $gapBand = $this->gapBand($coveredCount, $productionFileCount);
        $confidenceCeiling = $this->confidenceCeiling($gapBand, $riskLevel);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'uncovered_production_files' => $uncoveredFiles,
            'production_file_count' => $productionFileCount,
            'covered_count' => $coveredCount,
            'coverage_ratio' => $coverageRatio,
            'gap_band' => $gapBand,
            'confidence_ceiling' => $confidenceCeiling,
        ];
    }

    /**
     * Distinct production paths from the changed set, preserving first-seen
     * order. A production path is any non-empty string not under 'tests/'.
     *
     * @param  array<int|string, mixed>  $changedFiles
     * @return list<string>
     */
    private function productionPaths(array $changedFiles): array
    {
        $seen = [];
        $paths = [];

        foreach ($changedFiles as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (str_starts_with($value, 'tests/')) {
                continue;
            }

            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $paths[] = $value;
        }

        return $paths;
    }

    /**
     * Set of covered paths keyed by path for O(1) membership tests.
     *
     * @param  array<int|string, mixed>  $coveredFiles
     * @return array<string, true>
     */
    private function coveredLookup(array $coveredFiles): array
    {
        $lookup = [];

        foreach ($coveredFiles as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $lookup[$value] = true;
        }

        return $lookup;
    }

    /**
     * Ordered gap-band resolution.
     *
     * Keyed off the exact integer covered/production counts, never the rounded
     * coverage_ratio. round(covered/count, 2) can land a not-fully-covered
     * change on exactly 1.0 (e.g. 199/200 = 0.995 -> 1.0) or a partially-covered
     * change on exactly 0.0 (e.g. 1/300 -> 0.0); banding off that rounded value
     * would emit gap_band 'none' alongside a non-empty uncovered list, or 'severe'
     * with covered_count > 0 -- a fail-open / mislabel the exact counts cannot
     * produce. 'none' iff every production file is covered; 'severe' iff at least
     * one production file and none covered; 'partial' for everything in between.
     */
    private function gapBand(int $coveredCount, int $productionFileCount): string
    {
        // R1: zero production files always lands on 'none'.
        if ($productionFileCount === 0) {
            return self::GAP_BAND_NONE;
        }

        // R3: fully covered (every production file covered).
        if ($coveredCount >= $productionFileCount) {
            return self::GAP_BAND_NONE;
        }

        // R5: at least one production file and nothing covered.
        if ($coveredCount === 0) {
            return self::GAP_BAND_SEVERE;
        }

        // R4: strictly between fully-covered and nothing-covered.
        return self::GAP_BAND_PARTIAL;
    }

    /**
     * R6: confidence ceiling derived from gap band and risk level.
     */
    private function confidenceCeiling(string $gapBand, string $riskLevel): string
    {
        if ($gapBand === self::GAP_BAND_SEVERE) {
            return self::CONFIDENCE_LOW;
        }

        if ($gapBand === self::GAP_BAND_PARTIAL) {
            return self::CONFIDENCE_MEDIUM;
        }

        if (in_array($riskLevel, self::ELEVATED_RISK_LEVELS, true)) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_HIGH;
    }
}
