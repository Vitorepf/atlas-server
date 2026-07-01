<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure meter that detects theme saturation by allowed-file directory and repeated
 * design path, emitting a pivot plan that forces the next batch into a different
 * high-leverage lane.
 *
 * A new prerequisite unlock prevents false saturation for legitimately compounding themes.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainOriginatorThemeSaturationMeter
{
    public const SCHEMA = 'atlas.external_brain.originator_theme_saturation_meter.v1';

    public const SATURATION_HIGH = 'saturation_high';
    public const SATURATION_LOW = 'saturation_low';

    private const DIRECTORY_REPEAT_THRESHOLD = 3;
    private const DESIGN_PATH_REPEAT_THRESHOLD = 3;

    /**
     * @param  list<array{
     *   theme_label?:string,
     *   allowed_files?:list<string>,
     *   design_path?:string,
     *   prerequisite_unlock?:bool,
     * }>  $recentBatches
     * @return array{
     *   schema:string,
     *   saturation:string,
     *   pivot_plan:array{
     *     forbidden_directories:list<string>,
     *     forbidden_design_paths:list<string>,
     *     recommended_next_theme:?string,
     *   },
     *   reasons:list<string>,
     * }
     */
    public function measure(array $recentBatches): array
    {
        $directoryCounts = [];
        $designPathCounts = [];
        $hasNewPrereqUnlock = false;

        foreach ($recentBatches as $batch) {
            $files = (array) ($batch['allowed_files'] ?? []);
            foreach ($files as $file) {
                $dir = dirname((string) $file);
                if ($dir !== '.' && $dir !== '') {
                    $directoryCounts[$dir] = ($directoryCounts[$dir] ?? 0) + 1;
                }
            }

            $designPath = (string) ($batch['design_path'] ?? '');
            if ($designPath !== '') {
                $designPathCounts[$designPath] = ($designPathCounts[$designPath] ?? 0) + 1;
            }

            if (($batch['prerequisite_unlock'] ?? false) === true) {
                $hasNewPrereqUnlock = true;
            }
        }

        // Find saturated directories and design paths
        $saturatedDirs = [];
        foreach ($directoryCounts as $dir => $count) {
            if ($count >= self::DIRECTORY_REPEAT_THRESHOLD) {
                $saturatedDirs[] = $dir;
            }
        }

        $saturatedPaths = [];
        foreach ($designPathCounts as $path => $count) {
            if ($count >= self::DESIGN_PATH_REPEAT_THRESHOLD) {
                $saturatedPaths[] = $path;
            }
        }

        $reasons = [];

        // Prerequisite unlock exempts saturation (legitimate compounding)
        if ($hasNewPrereqUnlock) {
            $reasons[] = 'prerequisite_unlock_exempts_saturation';
        }

        // Saturation detected when both directory AND design path repeat
        $isSaturated = ! $hasNewPrereqUnlock
            && count($saturatedDirs) > 0
            && count($saturatedPaths) > 0;

        if ($isSaturated) {
            $reasons[] = 'directory_repeat:' . implode(',', $saturatedDirs);
            $reasons[] = 'design_path_repeat:' . implode(',', $saturatedPaths);
            sort($reasons, SORT_STRING);

            return [
                'schema' => self::SCHEMA,
                'saturation' => self::SATURATION_HIGH,
                'pivot_plan' => [
                    'forbidden_directories' => $saturatedDirs,
                    'forbidden_design_paths' => $saturatedPaths,
                    'recommended_next_theme' => $this->recommendNextTheme($saturatedDirs),
                ],
                'reasons' => $reasons,
            ];
        }

        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'saturation' => self::SATURATION_LOW,
            'pivot_plan' => [
                'forbidden_directories' => [],
                'forbidden_design_paths' => [],
                'recommended_next_theme' => null,
            ],
            'reasons' => $reasons !== [] ? $reasons : ['no_saturation_detected'],
        ];
    }

    /**
     * @param  list<string>  $saturatedDirs
     */
    private function recommendNextTheme(array $saturatedDirs): ?string
    {
        // Simple heuristic: recommend a different domain based on what's saturated
        $allLanes = ['external_brain', 'verification_court', 'knowledge_sync', 'replenisher', 'simplification', 'task_fabric'];
        foreach ($allLanes as $lane) {
            $notInSaturated = true;
            foreach ($saturatedDirs as $dir) {
                if (stripos($dir, $lane) !== false) {
                    $notInSaturated = false;
                    break;
                }
            }
            if ($notInSaturated) {
                return $lane;
            }
        }

        return 'novel_research';
    }
}
