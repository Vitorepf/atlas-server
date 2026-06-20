<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ADEP layer — the ≥9 QUALITY BAR (operator directive: "tudo numa nota de no mínimo 9").
 *
 * The cert gates are pass/fail; this adds a 0–10 quality SCORE over the measurable signals a verified
 * refactor already produces, so ADEP/the loop only delivers genuinely high-value work, not merely
 * green-and-in-scope work. Deterministic + pure (unit-testable); a provider-judge panel can later add
 * subjective dimensions (design quality) ON TOP, but the floor here is objective and ungameable:
 *
 *  - behavior_preserved (acceptance green) ...... HARD GATE: false ⇒ 0 (never deliver broken code)
 *  - scope_clean (diff within allowed_globs) ..... HARD GATE: false ⇒ ≤2 (structurally untrustworthy)
 *  - complexity_reduction (worst-method cx drop) . the VALUE: scaled to the relative drop
 *  - no_net_branches_added (total ≤ before) ...... anti-gaming: relocate, don't inflate
 *  - no_metric_laundering ......................... anti-gaming: don't hide decisions in boolean arrays
 *  - absolute_branch_drop (total before−after) .... rewards small surgical reductions that compound
 *  - coverage_added (a new pinning test) ......... bonus
 *
 * A real extract-class (cx 27→8, total 42→24, +new test, in scope, green) scores 10; a marginal
 * 7%-drop refactor with no total branch drop scores <9 and is correctly rejected as not-high-value-enough.
 */
final class AtlasLoopQualityGrader
{
    public const DEFAULT_BAR = 9.0;

    /**
     * @param  array<string,mixed>  $signals
     * @return array{score:float, passes_bar:bool, bar:float, dimensions:array<string,mixed>, reasons:list<string>}
     */
    public function grade(array $signals, ?float $bar = null): array
    {
        $bar = $bar ?? (float) config('atlas.loop.quality_bar', self::DEFAULT_BAR);

        $behaviorPreserved = (bool) ($signals['behavior_preserved'] ?? false);
        $scopeClean = (bool) ($signals['scope_clean'] ?? false);
        $cxBefore = max(0, (int) ($signals['cx_before'] ?? 0));
        $cxAfter = max(0, (int) ($signals['cx_after'] ?? 0));
        $totalBefore = max(0, (int) ($signals['total_branches_before'] ?? 0));
        $totalAfter = max(0, (int) ($signals['total_branches_after'] ?? 0));
        $coverageAdded = (bool) ($signals['coverage_added'] ?? false);
        $complexityGamingReasons = $this->stringList($signals['complexity_gaming_reasons'] ?? []);

        $reasons = [];

        // HARD GATE 1: broken behavior is never deliverable.
        if (! $behaviorPreserved) {
            return $this->result(0.0, $bar, ['behavior_preserved' => false], ['behavior_not_preserved_or_tests_red']);
        }
        // HARD GATE 2: an out-of-scope / frozen-tamper diff is structurally untrustworthy.
        if (! $scopeClean) {
            return $this->result(2.0, $bar, ['scope_clean' => false], ['out_of_scope_or_frozen_tampered']);
        }
        // HARD GATE 3: a refactor that lowers AST complexity by encoding boolean branches into data
        // literals is metric laundering, not maintainability improvement. The detector is diff-scoped
        // and supplied by the certifier, so existing honest refactors stay on the normal path.
        if ($complexityGamingReasons !== []) {
            return $this->result(2.0, $bar, [
                'behavior_preserved' => true,
                'scope_clean' => true,
                'complexity_metric_laundering' => true,
                'complexity_gaming_reasons' => $complexityGamingReasons,
            ], ['complexity_metric_laundering:'.$complexityGamingReasons[0]]);
        }

        // Base for a green, in-scope, behavior-preserving change.
        $score = 6.0;

        // VALUE: relative complexity drop on the worst method (0..1) → up to +3.
        $relDrop = ($cxBefore > 0 && $cxAfter < $cxBefore) ? min(1.0, ($cxBefore - $cxAfter) / $cxBefore) : 0.0;
        $cxPoints = round(3.0 * $relDrop, 2);
        $score += $cxPoints;
        if ($relDrop <= 0.0) {
            $reasons[] = 'no_complexity_reduction';
        }

        // ANTI-GAMING: total branch count must not rise (relocate, don't inflate). +1 when it held/fell.
        $netBranchesOk = $totalBefore === 0 || $totalAfter <= $totalBefore;
        $score += $netBranchesOk ? 1.0 : -2.0;
        if (! $netBranchesOk) {
            $reasons[] = 'net_branches_increased';
        }

        // COMPOUNDING SMALL WINS: a sequence step can be tiny in relative worst-method terms but still
        // remove real decision points from the file. Reward absolute total-branch drops so bounded
        // surgical reductions can clear the bar when they genuinely make the loop simpler.
        $branchDrop = $netBranchesOk ? max(0, $totalBefore - $totalAfter) : 0;
        $branchDropPoints = round(min(2.0, (float) $branchDrop), 2);
        $score += $branchDropPoints;

        // BONUS: a new pinning test for the extracted/changed surface.
        if ($coverageAdded) {
            $score += 0.5;
        }

        $score = max(0.0, min(10.0, round($score, 2)));

        return $this->result($score, $bar, [
            'behavior_preserved' => true,
            'scope_clean' => true,
            'cx_before' => $cxBefore,
            'cx_after' => $cxAfter,
            'relative_cx_drop' => round($relDrop, 3),
            'cx_points' => $cxPoints,
            'net_branches_ok' => $netBranchesOk,
            'branch_drop' => $branchDrop,
            'branch_drop_points' => $branchDropPoints,
            'coverage_added' => $coverageAdded,
        ], $reasons);
    }

    /**
     * FEATURE LANE — the ≥9 quality bar for NON-refactor work (a feature/bugfix has no complexity drop,
     * so {@see grade} does not apply). Grades the measurable signals the certifier already computes for a
     * feature cert: behavior preserved (frozen suite green), scope clean, the diff EARNED (the new
     * behaviour failed before / passes after), the frozen suite's mutation kill strength, zero adversarial
     * refutations, and a bonus for a new/changed pinning test. Same shape contract as {@see grade}
     * (reuses {@see result}), so existing passes_bar/score consumers and the receipt's quality_grade key
     * are unchanged. Deterministic + pure (no I/O).
     *
     *  - behavior_preserved (frozen suite green) ... HARD GATE: false ⇒ 0 (never deliver broken code)
     *  - scope_clean (diff within allowed_globs) .... HARD GATE: false ⇒ 2 (structurally untrustworthy)
     *  - adversarial_refuted_count == 0 ............. HARD GATE: any refute ⇒ 2 (mirrors the cert contract)
     *  - diff_earned (failed-before/passes-after) ... the VALUE: +1.5
     *  - mutation_kill_ratio (suite strength) ....... scaled 0..2 by the kill ratio
     *  - coverage_added (a new pinning test) ........ bonus +0.5
     *
     * A fully-evidenced feature (earned + kill 1.0 + cover) = 6+1.5+2+0.5 = 10; a thin feature
     * (earned, kill 0.0, no cover) = 7.5 < 9 and is correctly rejected as not-high-value-enough.
     *
     * @param  array<string,mixed>  $signals
     * @return array{score:float, passes_bar:bool, bar:float, dimensions:array<string,mixed>, reasons:list<string>}
     */
    public function gradeFeature(array $signals, ?float $bar = null): array
    {
        $bar = $bar ?? (float) config('atlas.loop.quality_bar', self::DEFAULT_BAR);

        $behaviorPreserved = (bool) ($signals['behavior_preserved'] ?? false);
        $scopeClean = (bool) ($signals['scope_clean'] ?? false);
        $diffEarned = (bool) ($signals['diff_earned'] ?? false);
        $killRatio = max(0.0, min(1.0, (float) ($signals['mutation_kill_ratio'] ?? 0.0)));
        $refuted = max(0, (int) ($signals['adversarial_refuted_count'] ?? 0));
        $coverageAdded = (bool) ($signals['coverage_added'] ?? false);

        $reasons = [];

        // HARD GATE 1: broken behavior is never deliverable.
        if (! $behaviorPreserved) {
            return $this->result(0.0, $bar, ['behavior_preserved' => false], ['behavior_not_preserved_or_tests_red']);
        }
        // HARD GATE 2: an out-of-scope / frozen-tamper diff is structurally untrustworthy.
        if (! $scopeClean) {
            return $this->result(2.0, $bar, ['scope_clean' => false], ['out_of_scope_or_frozen_tampered']);
        }
        // HARD GATE 3: any adversarial refutation caps the score low (mirrors the cert's refuted_count==0 contract).
        if ($refuted > 0) {
            return $this->result(2.0, $bar, ['adversarial_refuted_count' => $refuted], ['adversarial_refuted']);
        }

        // Base for a green, in-scope, behavior-preserving feature.
        $score = 6.0;

        // VALUE: a feature must EARN its diff (the new behaviour failed before, passes after) — the core
        // non-refactor signal. +1.5.
        $score += $diffEarned ? 1.5 : 0.0;
        if (! $diffEarned) {
            $reasons[] = 'diff_not_earned';
        }

        // STRENGTH: the frozen suite must be strong enough to have killed a behaviour change. Scaled 0..2
        // by the kill ratio.
        $killPoints = round(2.0 * $killRatio, 2);
        $score += $killPoints;
        if ($killRatio <= 0.0) {
            $reasons[] = 'no_mutation_kill_signal';
        }

        // BONUS: a new/changed pinning test for the feature surface.
        if ($coverageAdded) {
            $score += 0.5;
        }

        $score = max(0.0, min(10.0, round($score, 2)));

        return $this->result($score, $bar, [
            'behavior_preserved' => true,
            'scope_clean' => true,
            'diff_earned' => $diffEarned,
            'mutation_kill_ratio' => round($killRatio, 3),
            'kill_points' => $killPoints,
            'adversarial_refuted_count' => 0,
            'coverage_added' => $coverageAdded,
        ], $reasons);
    }

    /**
     * @param  array<string,mixed>  $dimensions
     * @param  list<string>  $reasons
     * @return array{score:float, passes_bar:bool, bar:float, dimensions:array<string,mixed>, reasons:list<string>}
     */
    private function result(float $score, float $bar, array $dimensions, array $reasons): array
    {
        return [
            'score' => $score,
            'passes_bar' => $score >= $bar,
            'bar' => $bar,
            'dimensions' => $dimensions,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }

        return array_keys($out);
    }
}
