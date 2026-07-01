<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure learner: analyses recent task history to compute a diversity score, detect
 * concentrated families, and recommend underrepresented high-leverage families for
 * the next wave.
 *
 * diversity_score         = 1 - max_family_concentration_ratio (task_family naming)
 * capability_impact_score = 1 - max_capability_family_concentration (structural impact)
 * concentrated_families   = families appearing in > 50% of the done_set
 * high_negative_signal_families = families where refused|give_back > 50% of their runs
 * missing_family_recommendations = KNOWN_FAMILIES unseen or under 10%, excluding negative-signal families
 *
 * recommendation:
 *   shift_pattern       — naming concentrated AND capability_impact concentrated (no diversity in impact)
 *   continue_or_compound — naming concentrated but capability_impact is diverse (repeated naming ≠ repeated impact)
 *                          OR both dimensions are diverse
 *
 * Structural-impact discounting: tasks flagged is_duplicate_wrapper, is_cosmetic_cli or
 * is_behavior_neutral are excluded from every diversity/concentration calculation — they
 * are counted separately in discounted_task_count and total_delivered so the score cannot
 * be inflated by commit count alone. Surviving tasks contribute risk_reduced,
 * autonomy_gained and downstream_unlocks into structural_impact_by_family / high_impact_families,
 * and recommended_next_originator_focus names the single best next family (high-leverage
 * under-served families win over ordinary under-served ones).
 */
final class AtlasExternalBrainDoneSetDiversityLearner
{
    public const SCHEMA = 'atlas.external_brain.done_set_diversity_learner.v1';

    public const CONCENTRATION_THRESHOLD = 0.5;

    public const UNDERREPRESENTED_THRESHOLD = 0.1;

    public const TEMPLATE_FARM_THRESHOLD = 0.5;

    private const HIGH_LEVERAGE_FAMILIES = ['bug-hunt', 'gate-impl', 'research'];

    private const KNOWN_FAMILIES = [
        'bug-hunt',
        'discovery',
        'gate-certification',
        'gate-impl',
        'gate-wiring',
        'origination',
        'research',
        'telemetry-wiring',
        'trend-measurement',
    ];

    private const NEGATIVE_OUTCOMES = ['give_back', 'refused'];

    /**
     * @param  list<array<string,mixed>>  $doneSet  Each: task_family, outcome
     * @return array<string,mixed>
     */
    public function learn(array $doneSet): array
    {
        $rawTotal = count($doneSet);

        $effectiveDoneSet = array_values(array_filter($doneSet, static fn (array $task): bool => ! self::isDiscounted($task)));
        $discountedCount = $rawTotal - count($effectiveDoneSet);

        $total = count($effectiveDoneSet);

        if ($total === 0) {
            return [
                'schema_version'                     => self::SCHEMA,
                'diversity_score'                    => 1.0,
                'capability_impact_score'             => 1.0,
                'recommendation'                      => 'continue_or_compound',
                'concentrated_families'               => [],
                'high_negative_signal_families'       => [],
                'missing_family_recommendations'      => self::KNOWN_FAMILIES,
                'template_concentration'              => 0.0,
                'template_farm_detected'              => false,
                'top_template_signature'              => null,
                'capability_family_coverage'          => 0.0,
                'dominant_objective_shape'             => null,
                'discounted_task_count'               => $discountedCount,
                'total_delivered'                     => $rawTotal,
                'structural_impact_by_family'         => [],
                'high_impact_families'                => [],
                'recommended_next_originator_focus'   => $this->recommendedFocusFrom(self::KNOWN_FAMILIES),
            ];
        }

        $familyCounts        = [];
        $negativeCounts      = [];
        $templateCounts      = [];
        $capFamiliesSeen     = [];
        $capFamilyCounts     = [];   // capability_family impact concentration
        $shapeCounts         = [];
        $structuralImpactByFamily = [];

        foreach ($effectiveDoneSet as $task) {
            $family  = (string) ($task['task_family']       ?? 'unknown');
            $outcome = (string) ($task['outcome']           ?? 'served');
            $sig     = trim((string) ($task['template_signature'] ?? ''));
            $capFam  = trim((string) ($task['capability_family']  ?? ''));
            $shape   = trim((string) ($task['objective_shape']    ?? ''));
            $riskReduced       = (bool) ($task['risk_reduced'] ?? false);
            $autonomyGained    = (bool) ($task['autonomy_gained'] ?? false);
            $downstreamUnlocks = max(0, (int) ($task['downstream_unlocks'] ?? 0));

            $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            if (in_array($outcome, self::NEGATIVE_OUTCOMES, true)) {
                $negativeCounts[$family] = ($negativeCounts[$family] ?? 0) + 1;
            }
            if ($sig !== '') {
                $templateCounts[$sig] = ($templateCounts[$sig] ?? 0) + 1;
            }
            if ($capFam !== '') {
                $capFamiliesSeen[$capFam]    = true;
                $capFamilyCounts[$capFam] = ($capFamilyCounts[$capFam] ?? 0) + 1;
            }
            if ($shape !== '') {
                $shapeCounts[$shape] = ($shapeCounts[$shape] ?? 0) + 1;
            }

            $structuralImpactByFamily[$family] ??= [
                'risk_reduced_count'     => 0,
                'autonomy_gained_count'  => 0,
                'downstream_unlocks_sum' => 0,
            ];
            if ($riskReduced) {
                $structuralImpactByFamily[$family]['risk_reduced_count']++;
            }
            if ($autonomyGained) {
                $structuralImpactByFamily[$family]['autonomy_gained_count']++;
            }
            $structuralImpactByFamily[$family]['downstream_unlocks_sum'] += $downstreamUnlocks;
        }

        $maxConcentration = max(array_map(static fn (int $c): float => $c / $total, $familyCounts));
        $diversityScore = round(1.0 - $maxConcentration, 3);

        $concentrated = [];
        foreach ($familyCounts as $family => $count) {
            if ($count / $total > self::CONCENTRATION_THRESHOLD) {
                $concentrated[] = $family;
            }
        }
        sort($concentrated);

        $highNegative = [];
        foreach ($familyCounts as $family => $count) {
            $neg = $negativeCounts[$family] ?? 0;
            if ($neg / $count > 0.5) {
                $highNegative[] = $family;
            }
        }
        sort($highNegative);

        $missing = [];
        foreach (self::KNOWN_FAMILIES as $family) {
            $count = $familyCounts[$family] ?? 0;
            if ($count === 0 || ($count / $total) < self::UNDERREPRESENTED_THRESHOLD) {
                if (! in_array($family, $highNegative, true)) {
                    $missing[] = $family;
                }
            }
        }

        // AC1: template_signature concentration.
        $templateConcentration = 0.0;
        $topTemplateSig        = null;
        if ($templateCounts !== []) {
            $topSigCount           = max($templateCounts);
            $templateConcentration = round($topSigCount / $total, 3);
            $topTemplateSig        = array_search($topSigCount, $templateCounts, true);
        }
        $templateFarmDetected = $templateConcentration > self::TEMPLATE_FARM_THRESHOLD;

        // AC1: capability_family coverage over KNOWN_FAMILIES set.
        $capFamilyCoverage = count($capFamiliesSeen) / count(self::KNOWN_FAMILIES);

        // AC1: dominant objective_shape (>50% of tasks with a shape).
        $dominantShape = null;
        if ($shapeCounts !== []) {
            $topShapeCount = max($shapeCounts);
            if ($topShapeCount / $total > self::CONCENTRATION_THRESHOLD) {
                $dominantShape = (string) array_search($topShapeCount, $shapeCounts, true);
            }
        }

        // AC2: when template farm detected, sort missing to put high-leverage families first.
        if ($templateFarmDetected && $missing !== []) {
            usort($missing, static function (string $a, string $b): int {
                $aHigh = in_array($a, self::HIGH_LEVERAGE_FAMILIES, true);
                $bHigh = in_array($b, self::HIGH_LEVERAGE_FAMILIES, true);
                if ($aHigh === $bHigh) {
                    return 0;
                }
                return $aHigh ? -1 : 1;
            });
        }

        // AC3: capability impact diversity (independent of task_family naming).
        $capImpactScore = 1.0;
        if ($capFamilyCounts !== []) {
            $maxCapConcentration = max(array_map(static fn (int $c): float => $c / $total, $capFamilyCounts));
            $capImpactScore      = round(1.0 - $maxCapConcentration, 3);
        }

        // recommendation: shift_pattern when both naming AND impact are concentrated;
        // continue_or_compound when impact is diverse even if naming repeats (AC2 + AC3).
        $namingConcentrated  = $diversityScore < self::CONCENTRATION_THRESHOLD;
        $impactConcentrated  = $capFamilyCounts !== [] && $capImpactScore < self::CONCENTRATION_THRESHOLD;
        $recommendation = ($namingConcentrated && ($impactConcentrated || $capFamilyCounts === []))
            ? 'shift_pattern'
            : 'continue_or_compound';

        // AC2: high-impact families are those where at least one surviving (non-discounted)
        // task actually reduced risk, grew autonomy or unlocked downstream work — not merely
        // repeated commits under the same family name.
        $highImpactFamilies = [];
        foreach ($structuralImpactByFamily as $family => $impact) {
            if ($impact['risk_reduced_count'] > 0 || $impact['autonomy_gained_count'] > 0 || $impact['downstream_unlocks_sum'] > 0) {
                $highImpactFamilies[] = $family;
            }
        }
        sort($highImpactFamilies);

        return [
            'schema_version'                     => self::SCHEMA,
            'diversity_score'                    => $diversityScore,
            'capability_impact_score'             => $capImpactScore,
            'recommendation'                      => $recommendation,
            'concentrated_families'               => $concentrated,
            'high_negative_signal_families'       => $highNegative,
            'missing_family_recommendations'      => $missing,
            'template_concentration'              => $templateConcentration,
            'template_farm_detected'              => $templateFarmDetected,
            'top_template_signature'              => $topTemplateSig,
            'capability_family_coverage'          => round($capFamilyCoverage, 3),
            'dominant_objective_shape'             => $dominantShape,
            'discounted_task_count'               => $discountedCount,
            'total_delivered'                     => $rawTotal,
            'structural_impact_by_family'         => $structuralImpactByFamily,
            'high_impact_families'                => $highImpactFamilies,
            'recommended_next_originator_focus'   => $this->recommendedFocusFrom($missing),
        ];
    }

    private static function isDiscounted(array $task): bool
    {
        return (bool) ($task['is_duplicate_wrapper'] ?? false)
            || (bool) ($task['is_cosmetic_cli'] ?? false)
            || (bool) ($task['is_behavior_neutral'] ?? false);
    }

    /**
     * @param  list<string>  $missing
     */
    private function recommendedFocusFrom(array $missing): ?string
    {
        $highLeverageMissing = array_values(array_intersect($missing, self::HIGH_LEVERAGE_FAMILIES));

        return $highLeverageMissing[0] ?? ($missing[0] ?? null);
    }
}
