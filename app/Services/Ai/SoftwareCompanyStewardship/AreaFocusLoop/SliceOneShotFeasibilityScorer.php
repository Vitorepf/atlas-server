<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class SliceOneShotFeasibilityScorer
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.slice_one_shot_feasibility.v1';

    /**
     * @return array{
     *     schema_version: string,
     *     one_shot_able: bool,
     *     feasibility_band: 'one_shot'|'tight'|'multi_shot',
     *     score: float,
     *     spend_allowed: bool,
     *     reasons: list<string>
     * }
     */
    public function score(
        int $estimatedLoc,
        int $ruleCount,
        int $declaredDependencyCount,
        int $allowedFileCount,
        bool $isNewFileSlice,
    ): array {
        $estimatedLoc = max(0, $estimatedLoc);
        $ruleCount = max(0, $ruleCount);
        $declaredDependencyCount = max(0, $declaredDependencyCount);
        $allowedFileCount = max(0, $allowedFileCount);

        $maxFilesPerSlice = FindingSlicePlannerService::MAX_FILES_PER_SLICE;

        $multiShot = $estimatedLoc > 180
            || $ruleCount > 9
            || $declaredDependencyCount > 2
            || $allowedFileCount > $maxFilesPerSlice;

        if ($multiShot) {
            $band = 'multi_shot';
            $oneShotAble = false;
        } elseif ($estimatedLoc > 110 || $ruleCount > 6 || $allowedFileCount > 2) {
            $band = 'tight';
            $oneShotAble = true;
        } else {
            $band = 'one_shot';
            $oneShotAble = true;
        }

        $locOrFileTierExceeded = $estimatedLoc > 110 || $allowedFileCount > 2;
        $nonRelaxableTierExceeded = $ruleCount > 6 || $declaredDependencyCount > 2;
        if ($isNewFileSlice && $band === 'tight' && $locOrFileTierExceeded && ! $nonRelaxableTierExceeded) {
            $band = 'one_shot';
        }

        $rawPressure = (
            $estimatedLoc / 200
            + $ruleCount / 10
            + $declaredDependencyCount / 3
            + $allowedFileCount / 5
        ) / 4;
        $clampedPressure = max(0.0, min(1.0, $rawPressure));
        $score = round(1 - $clampedPressure, 2);

        $reasons = $this->buildReasons(
            $estimatedLoc,
            $ruleCount,
            $declaredDependencyCount,
            $allowedFileCount,
            $band,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'one_shot_able' => $oneShotAble,
            'feasibility_band' => $band,
            'score' => $score,
            'spend_allowed' => $oneShotAble,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildReasons(
        int $estimatedLoc,
        int $ruleCount,
        int $declaredDependencyCount,
        int $allowedFileCount,
        string $band,
    ): array {
        $reasons = [];

        if ($estimatedLoc > 110) {
            $reasons[] = 'loc_over_budget';
        }
        if ($ruleCount > 6) {
            $reasons[] = 'rule_count_over_budget';
        }
        if ($declaredDependencyCount > 2) {
            $reasons[] = 'too_many_dependencies';
        }
        if ($allowedFileCount > 2) {
            $reasons[] = 'too_many_files';
        }
        if ($band === 'one_shot' && $reasons === []) {
            $reasons[] = 'within_one_shot_budget';
        }

        return $reasons;
    }
}
