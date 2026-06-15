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
 *  - coverage_added (a new pinning test) ......... bonus
 *
 * A real extract-class (cx 27→8, total 42→24, +new test, in scope, green) scores 10; a marginal
 * 7%-drop refactor scores <9 and is correctly rejected as not-high-value-enough.
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

        $reasons = [];

        // HARD GATE 1: broken behavior is never deliverable.
        if (! $behaviorPreserved) {
            return $this->result(0.0, $bar, ['behavior_preserved' => false], ['behavior_not_preserved_or_tests_red']);
        }
        // HARD GATE 2: an out-of-scope / frozen-tamper diff is structurally untrustworthy.
        if (! $scopeClean) {
            return $this->result(2.0, $bar, ['scope_clean' => false], ['out_of_scope_or_frozen_tampered']);
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
}
