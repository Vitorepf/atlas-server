<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure entropy planner. Converts low or zero hint entropy into concrete
 * diversity pressure on the next task batch, preventing Goodhart drift where
 * the brain repeats the same path family indefinitely.
 *
 * Pressure levels (by hint_entropy):
 *   critical (< 0.30) — strong bans + high target floor (0.65)
 *   moderate (0.30–0.60) — soft bans + medium floor (0.45)
 *   low (> 0.60)     — light constraints + minimum floor (0.30)
 *
 * A family is BANNED when it appears in more than 50% of recent_batch_families
 * (concentration ban) AND entropy is below the moderate threshold.
 *
 * The dominant high-yield vein is preserved as an allowed_exception when
 * dominant_vein_compounding = true, but at least one orthogonal probe family
 * (a starved_path that differs from the dominant vein) is always required.
 *
 * AC4: result always includes target_entropy_floor, required_hint_families,
 *      banned_repeated_families, and allowed_exceptions.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainHintEntropyRestorationPlanner
{
    public const SCHEMA = 'atlas.external_brain.hint_entropy_restoration_planner.v1';

    private const ENTROPY_CRITICAL_THRESHOLD = 0.30;
    private const ENTROPY_MODERATE_THRESHOLD = 0.60;

    private const TARGET_FLOOR_CRITICAL = 0.65;
    private const TARGET_FLOOR_MODERATE = 0.45;
    private const TARGET_FLOOR_LOW      = 0.30;

    private const CONCENTRATION_BAN_FRACTION = 0.50;

    private const AXES = ['capability_area', 'task_family', 'evidence_source', 'expected_impact'];

    private const LOW_AXIS_DIVERSITY_THRESHOLD = 0.30;

    private const TEMPLATE_COLLAPSE_GAP = 0.30;

    /**
     * @param  array{
     *   hint_entropy?: float,
     *   recent_batch_families?: list<string>,
     *   starved_paths?: list<string>,
     *   dominant_vein?: string|null,
     *   dominant_vein_compounding?: bool,
     *   batch_size?: int,
     * }  $input
     * @return array{schema:string, target_entropy_floor:float, required_hint_families:list<string>, banned_repeated_families:list<string>, allowed_exceptions:list<string>}
     */
    public function plan(array $input): array
    {
        $hintEntropy          = max(0.0, min(1.0, (float) ($input['hint_entropy']           ?? 1.0)));
        $recentFamilies       = array_values(array_filter(array_map('trim', (array) ($input['recent_batch_families'] ?? []))));
        $starvedPaths         = array_values(array_filter(array_map('trim', (array) ($input['starved_paths']         ?? []))));
        $dominantVein         = trim((string) ($input['dominant_vein']                        ?? ''));
        $dominantCompounding  = (bool) ($input['dominant_vein_compounding']                   ?? false);

        $isCritical = $hintEntropy < self::ENTROPY_CRITICAL_THRESHOLD;
        $isModerate = $hintEntropy < self::ENTROPY_MODERATE_THRESHOLD;

        $targetFloor = match (true) {
            $isCritical => self::TARGET_FLOOR_CRITICAL,
            $isModerate => self::TARGET_FLOOR_MODERATE,
            default     => self::TARGET_FLOOR_LOW,
        };

        $banned = $this->computeBannedFamilies($recentFamilies, $isModerate);

        $allowedExceptions = [];
        if ($dominantVein !== '' && $dominantCompounding) {
            $allowedExceptions[] = $dominantVein;
        }

        $required = $this->computeRequiredFamilies($starvedPaths, $banned, $dominantVein, $dominantCompounding, $isCritical);

        // AC1/AC4: axis diversity (opt-in via recent_hints) — names the exact axes that have
        // collapsed to near-single-value coverage, so the plan can be checked against real
        // recent hint variety, not just the entropy scalar and family bans.
        $recentHints = is_array($input['recent_hints'] ?? null) ? $input['recent_hints'] : [];
        $lowAxisDiversity = [];
        $axisDiversityGaps = [];
        foreach (self::AXES as $axis) {
            $values = array_values(array_filter(array_map(
                static fn ($h): string => is_array($h) ? trim((string) ($h[$axis] ?? '')) : '',
                $recentHints,
            ), static fn (string $v): bool => $v !== ''));
            $diversity = count($recentHints) > 0 ? round(count(array_unique($values)) / count($recentHints), 4) : 0.0;
            $isLow = $recentHints !== [] && $diversity < self::LOW_AXIS_DIVERSITY_THRESHOLD;
            $lowAxisDiversity[$axis] = $isLow;
            if ($isLow) {
                $axisDiversityGaps[] = $axis;
            }
        }

        return [
            'schema'                    => self::SCHEMA,
            'target_entropy_floor'      => $targetFloor,
            'required_hint_families'    => $required,
            'banned_repeated_families'  => $banned,
            'allowed_exceptions'        => $allowedExceptions,
            'low_axis_diversity'        => $lowAxisDiversity,
            'axis_diversity_gaps'       => $axisDiversityGaps,
        ];
    }

    /** @return list<string> */
    private function computeBannedFamilies(array $recentFamilies, bool $shouldBan): array
    {
        if (! $shouldBan || $recentFamilies === []) {
            return [];
        }

        $total  = count($recentFamilies);
        $counts = array_count_values($recentFamilies);
        $banned = [];

        foreach ($counts as $family => $count) {
            if (($count / $total) > self::CONCENTRATION_BAN_FRACTION) {
                $banned[] = $family;
            }
        }

        sort($banned);

        return $banned;
    }

    /**
     * @param  list<string>  $starvedPaths
     * @param  list<string>  $banned
     * @return list<string>
     */
    private function computeRequiredFamilies(
        array $starvedPaths,
        array $banned,
        string $dominantVein,
        bool $dominantCompounding,
        bool $isCritical,
    ): array {
        $required = [];

        // Include starved paths that are not banned
        foreach ($starvedPaths as $path) {
            if (! in_array($path, $banned, true)) {
                $required[] = $path;
            }
        }

        // Always require at least one orthogonal probe (a starved path different from dominant vein)
        $hasOrthogonalProbe = false;
        foreach ($required as $family) {
            if ($family !== $dominantVein) {
                $hasOrthogonalProbe = true;
                break;
            }
        }

        // An orthogonal probe is required whenever entropy is critical, OR whenever a compounding
        // exception was granted — an allowed exception must never be the ONLY path in the batch.
        $requiresOrthogonalProbe = $isCritical || ($dominantVein !== '' && $dominantCompounding);
        if (! $hasOrthogonalProbe && $requiresOrthogonalProbe) {
            $required[] = 'orthogonal_probe_required';
        }

        // If dominant vein is compounding, it is allowed via exceptions, not required.
        // Remove it from required (it's covered by allowed_exceptions).
        if ($dominantVein !== '' && $dominantCompounding) {
            $required = array_values(array_filter($required, fn (string $f): bool => $f !== $dominantVein));
        }

        return $required;
    }

    /**
     * Measures hint diversity across capability_area, task_family,
     * evidence_source and expected_impact, and recommends a concrete
     * restoration action when diversity collapses. Mere renaming/template
     * variants are detected via normalized_template_signature and never
     * count as real diversity restoration: when distinct task_family
     * labels collapse onto far fewer distinct template signatures, the
     * planner reports template_collapse_detected=true and recommends
     * inspect_negative_results instead of trusting the apparent family
     * diversity.
     *
     * diversity_by_axis[axis] = count(distinct values) / count(hints), in [0,1].
     *
     * RECOMMENDATION (first matching rule wins):
     *   template_collapse_detected                         -> inspect_negative_results
     *   evidence_source diversity < 0.30                    -> change_search_method
     *   capability_area diversity < 0.30                    -> rotate_area
     *   expected_impact diversity < 0.30 (others healthy)    -> consolidate
     *   otherwise                                             -> maintain_current_breadth
     *
     * @param  array<string,mixed>  $input  { recent_hints: list<{
     *   capability_area?, task_family?, evidence_source?, expected_impact?,
     *   normalized_template_signature?}> }
     * @return array<string,mixed>
     */
    public function measureAndRecommend(array $input): array
    {
        $hints = is_array($input['recent_hints'] ?? null) ? $input['recent_hints'] : [];
        $total = count($hints);

        $diversityByAxis = [];
        foreach (self::AXES as $axis) {
            $values = array_values(array_filter(array_map(
                static fn ($h): string => is_array($h) ? trim((string) ($h[$axis] ?? '')) : '',
                $hints,
            ), static fn (string $v): bool => $v !== ''));
            $diversityByAxis[$axis] = $total > 0 ? round(count(array_unique($values)) / $total, 4) : 0.0;
        }

        $signatures = array_values(array_filter(array_map(
            static fn ($h): string => is_array($h) ? trim((string) ($h['normalized_template_signature'] ?? '')) : '',
            $hints,
        ), static fn (string $v): bool => $v !== ''));
        $signatureDiversity = $total > 0 ? round(count(array_unique($signatures)) / $total, 4) : 0.0;

        $templateCollapseDetected = $signatures !== []
            && ($diversityByAxis['task_family'] - $signatureDiversity) >= self::TEMPLATE_COLLAPSE_GAP;

        [$recommendation, $reason] = match (true) {
            $templateCollapseDetected => ['inspect_negative_results', 'template_renaming_masks_collapsed_diversity_not_real_breadth'],
            $diversityByAxis['evidence_source'] < self::LOW_AXIS_DIVERSITY_THRESHOLD => ['change_search_method', 'evidence_source_diversity_collapsed'],
            $diversityByAxis['capability_area'] < self::LOW_AXIS_DIVERSITY_THRESHOLD => ['rotate_area', 'capability_area_diversity_collapsed'],
            $diversityByAxis['expected_impact'] < self::LOW_AXIS_DIVERSITY_THRESHOLD => ['consolidate', 'expected_impact_diversity_collapsed_consolidate_into_fewer_high_quality_tasks'],
            default => ['maintain_current_breadth', 'diversity_healthy_across_all_axes'],
        };

        $overallDiversityScore = $total > 0 ? round(array_sum($diversityByAxis) / count($diversityByAxis), 4) : 0.0;

        // Name the exact axis (capability_area/task_family/evidence_source/expected_impact)
        // that needs a fresh orthogonal probe, so the next batch targets the collapsed axis.
        $requiredHintFamilies = [];
        foreach (self::AXES as $axis) {
            if ($diversityByAxis[$axis] < self::LOW_AXIS_DIVERSITY_THRESHOLD) {
                $requiredHintFamilies[] = "orthogonal_probe:{$axis}";
            }
        }

        return [
            'schema' => self::SCHEMA,
            'diversity_by_axis' => $diversityByAxis,
            'overall_diversity_score' => $overallDiversityScore,
            'signature_diversity' => $signatureDiversity,
            'template_collapse_detected' => $templateCollapseDetected,
            'recommendation' => $recommendation,
            'reason' => $reason,
            'required_hint_families' => $requiredHintFamilies,
        ];
    }
}
