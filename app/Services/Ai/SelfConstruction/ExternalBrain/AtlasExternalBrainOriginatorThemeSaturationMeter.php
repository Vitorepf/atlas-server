<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects when recent task origination is over-concentrated in one theme
 * (e.g. "local clients" or "provider pools") and the brain should pivot.
 * Read-only: it groups already-authored task facts and recommends the next
 * theme — it never deletes or mutates queued tasks.
 *
 * Groups recent authored tasks by theme, target_family, capability_type, and
 * allowed_file directory (dirname of the first allowed_files entry).
 *
 * saturation_ratio = (count of the dominant theme) / (total tasks).
 * overrepresented_themes = every theme whose share >= saturation_threshold.
 *
 * saturation_high=true only when the dominant theme is overrepresented AND
 * none of its tasks unlocked a new prerequisite (new_prerequisite_unlock)
 * and they all share the same distinct_impact_class — i.e. repetition
 * without novelty.
 *
 * recommended_next_theme is the first candidate_next_theme that is not
 * overrepresented; forbidden_next_themes is overrepresented_themes.
 *
 * INPUT:
 *   recent_authored_tasks: list<{
 *     theme?:                   string
 *     target_family?:           string
 *     capability_type?:         string
 *     allowed_files?:           list<string>
 *     new_prerequisite_unlock?: bool (default false)
 *     distinct_impact_class?:   string (default '')
 *   }>
 *   context: {
 *     saturation_threshold?:  float (default 0.6)
 *     candidate_next_themes?: list<string> (default [])
 *   }
 *
 * OUTPUT:
 *   { schema, total_tasks, theme_counts, target_family_counts,
 *     capability_type_counts, allowed_file_directory_counts,
 *     dominant_theme, saturation_ratio, overrepresented_themes,
 *     saturation_high, recommended_next_theme, forbidden_next_themes }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainOriginatorThemeSaturationMeter
{
    public const SCHEMA = 'atlas.external_brain.originator_theme_saturation_meter.v1';

    private const DEFAULT_SATURATION_THRESHOLD = 0.6;

    /**
     * @param  list<array<string,mixed>>  $recentAuthoredTasks
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function measure(array $recentAuthoredTasks, array $context = []): array
    {
        $threshold = max(0.0, min(1.0, (float) ($context['saturation_threshold'] ?? self::DEFAULT_SATURATION_THRESHOLD)));
        $candidateNextThemes = is_array($context['candidate_next_themes'] ?? null) ? array_values($context['candidate_next_themes']) : [];

        $total = count($recentAuthoredTasks);

        $themeCounts = [];
        $targetFamilyCounts = [];
        $capabilityTypeCounts = [];
        $directoryCounts = [];
        $tasksByTheme = [];

        foreach ($recentAuthoredTasks as $task) {
            $theme = (string) ($task['theme'] ?? '');
            $targetFamily = (string) ($task['target_family'] ?? '');
            $capabilityType = (string) ($task['capability_type'] ?? '');
            $allowedFiles = is_array($task['allowed_files'] ?? null) ? $task['allowed_files'] : [];
            $directory = $allowedFiles === [] ? '' : dirname((string) $allowedFiles[0]);

            if ($theme !== '') {
                $themeCounts[$theme] = ($themeCounts[$theme] ?? 0) + 1;
                $tasksByTheme[$theme][] = $task;
            }
            if ($targetFamily !== '') {
                $targetFamilyCounts[$targetFamily] = ($targetFamilyCounts[$targetFamily] ?? 0) + 1;
            }
            if ($capabilityType !== '') {
                $capabilityTypeCounts[$capabilityType] = ($capabilityTypeCounts[$capabilityType] ?? 0) + 1;
            }
            if ($directory !== '') {
                $directoryCounts[$directory] = ($directoryCounts[$directory] ?? 0) + 1;
            }
        }

        arsort($themeCounts);
        $dominantTheme = $themeCounts === [] ? null : (string) array_key_first($themeCounts);
        $dominantCount = $dominantTheme === null ? 0 : $themeCounts[$dominantTheme];
        $saturationRatio = $total > 0 ? $dominantCount / $total : 0.0;

        $overrepresentedThemes = [];
        foreach ($themeCounts as $theme => $count) {
            if ($total > 0 && ($count / $total) >= $threshold) {
                $overrepresentedThemes[] = (string) $theme;
            }
        }

        $saturationHigh = false;
        if ($dominantTheme !== null && in_array($dominantTheme, $overrepresentedThemes, true)) {
            $dominantTasks = $tasksByTheme[$dominantTheme] ?? [];
            $hasNewUnlock = false;
            $impactClasses = [];
            foreach ($dominantTasks as $task) {
                if ((bool) ($task['new_prerequisite_unlock'] ?? false)) {
                    $hasNewUnlock = true;
                }
                $impactClasses[(string) ($task['distinct_impact_class'] ?? '')] = true;
            }
            $saturationHigh = ! $hasNewUnlock && count($impactClasses) <= 1;
        }

        $recommendedNextTheme = null;
        foreach ($candidateNextThemes as $candidate) {
            if (! in_array((string) $candidate, $overrepresentedThemes, true)) {
                $recommendedNextTheme = (string) $candidate;
                break;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'total_tasks' => $total,
            'theme_counts' => $themeCounts,
            'target_family_counts' => $targetFamilyCounts,
            'capability_type_counts' => $capabilityTypeCounts,
            'allowed_file_directory_counts' => $directoryCounts,
            'dominant_theme' => $dominantTheme,
            'saturation_ratio' => round($saturationRatio, 4),
            'overrepresented_themes' => $overrepresentedThemes,
            'saturation_high' => $saturationHigh,
            'recommended_next_theme' => $recommendedNextTheme,
            'forbidden_next_themes' => $overrepresentedThemes,
        ];
    }
}
