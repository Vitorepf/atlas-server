<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE Bloco C — the DELIVERY QUALITY SCORE (the falsifiable ">=2x vs ultracode" instrument).
 *
 * The operator's goal ("each committed large obra has >=2x the delivery quality of Opus ultracode") is
 * meaningless until it is MACHINE-MEASURED head-to-head — self-declared quality is exactly the Goodhart this
 * system forbids. This scorer makes it falsifiable, and it closes the SELECTION-BIAS trap the design panel
 * flagged: ACDE commits only what passed its frozen bar, so comparing ACDE's filtered output to Opus's
 * unfiltered output flatters ACDE. The fix is structural: BOTH engines are scored over the SAME panel of
 * attempted frozen obras, and a REFUSAL counts as a DEFECT. An engine cannot win by only committing winners
 * — refusing a task it cannot prove is a delivery failure on that task, full stop.
 *
 * Every axis is machine-resolved (never model self-report):
 *   - PRIMARY: defect_rate = defects / attempted, where defect = (attempted AND (not committed OR post-merge
 *     canary RED)). Refusal => defect; committed-but-canary-red => defect; committed-green => clean.
 *   - TIE-BREAKS (over the committed-clean set, used only near parity): mutation-kill-ratio, completeness,
 *     cyclomatic-drop — all from the certify() envelope, never declared.
 *
 * ">=2x better" is decided on the Wilson score interval (z=1.96): A is >=factor better than B with confidence
 * iff A's defect-rate UPPER bound <= B's defect-rate LOWER bound / factor (worst-case for A, worst-case for B
 * — conservative + honest; needs real evidence, not a small-sample fluke). Pure: no provider, no DB.
 */
final class AtlasLoopDeliveryQualityScore
{
    /**
     * Score one engine's outcomes over a panel of ATTEMPTED frozen obras.
     *
     * @param  list<array{attempted?:bool, committed?:bool, canary?:string, mutation_kill_ratio?:float, completeness?:float, cyclomatic_drop?:float}>  $panel
     * @return array{attempted:int, committed:int, refused:int, escaped_defects:int, defects:int, defect_rate:float, wilson_lower:float, wilson_upper:float, mean_mutation_kill:float, mean_completeness:float, mean_cyclomatic_drop:float}
     */
    public function score(array $panel): array
    {
        $attempted = 0;
        $committed = 0;
        $refused = 0;
        $escaped = 0; // committed but canary RED
        $mutSum = 0.0;
        $compSum = 0.0;
        $cycSum = 0.0;
        $cleanCommitted = 0;

        foreach ($panel as $o) {
            if (! is_array($o) || ($o['attempted'] ?? true) !== true) {
                continue;
            }
            $attempted++;
            $isCommitted = ($o['committed'] ?? false) === true;
            $canaryRed = mb_strtolower(trim((string) ($o['canary'] ?? 'not_run'))) === 'red';

            if (! $isCommitted) {
                $refused++; // a refusal IS a delivery defect on that task (anti-selection-bias)

                continue;
            }
            $committed++;
            if ($canaryRed) {
                $escaped++; // a regression that reached main is a defect

                continue;
            }
            // committed + clean => contributes to the tie-break axes
            $cleanCommitted++;
            $mutSum += max(0.0, min(1.0, (float) ($o['mutation_kill_ratio'] ?? 0.0)));
            $compSum += max(0.0, min(1.0, (float) ($o['completeness'] ?? 0.0)));
            $cycSum += max(0.0, (float) ($o['cyclomatic_drop'] ?? 0.0));
        }

        $defects = $refused + $escaped;
        $rate = $attempted > 0 ? $defects / $attempted : 0.0;
        [$lo, $hi] = $this->wilson($defects, $attempted);

        return [
            'attempted' => $attempted,
            'committed' => $committed,
            'refused' => $refused,
            'escaped_defects' => $escaped,
            'defects' => $defects,
            'defect_rate' => round($rate, 4),
            'wilson_lower' => round($lo, 4),
            'wilson_upper' => round($hi, 4),
            'mean_mutation_kill' => $cleanCommitted > 0 ? round($mutSum / $cleanCommitted, 4) : 0.0,
            'mean_completeness' => $cleanCommitted > 0 ? round($compSum / $cleanCommitted, 4) : 0.0,
            'mean_cyclomatic_drop' => $cleanCommitted > 0 ? round($cycSum / $cleanCommitted, 4) : 0.0,
        ];
    }

    /**
     * Blind head-to-head: is engine A (e.g. ACDE) >= `factor` better than engine B (e.g. Opus ultracode) on
     * the SAME panel of attempted frozen obras? Decided on the DEFECT-RATE primary with the conservative
     * Wilson test; ties broken by the machine tie-break axes. The two panels MUST be the same task set
     * (same attempted count) or the comparison is not apples-to-apples (reported as panel_mismatch).
     *
     * @param  list<array<string,mixed>>  $panelA
     * @param  list<array<string,mixed>>  $panelB
     * @return array{a:array<string,mixed>, b:array<string,mixed>, defect_ratio_point:?float, factor:float, confident_a_better:bool, verdict:string, tie_break_winner:?string, reason:string}
     */
    public function headToHead(array $panelA, array $panelB, float $factor = 2.0): array
    {
        $factor = max(1.0, $factor);
        $a = $this->score($panelA);
        $b = $this->score($panelB);

        if ($a['attempted'] === 0 || $b['attempted'] === 0) {
            return $this->verdict($a, $b, null, $factor, false, 'insufficient_data', null, 'one or both panels are empty');
        }
        if ($a['attempted'] !== $b['attempted']) {
            return $this->verdict($a, $b, null, $factor, false, 'panel_mismatch', null,
                'panels differ in attempted count ('.$a['attempted'].' vs '.$b['attempted'].') — not the same frozen obra set, comparison invalid');
        }

        // RELATIVE RISK of B's defect rate vs A's (RR = pB/pA; > 1 => A has fewer defects). Katz log-method
        // CI with a Haldane-Anscombe +0.5 correction (handles zero-defect cells without a divide-by-zero).
        // "A is >= factor better" iff the LOWER bound of RR >= factor — A genuinely halves (or better) B's
        // defect rate with ~95% confidence, not a small-sample fluke. This is the falsifiable ">=2x".
        $rr = $this->relativeRisk($a['defects'], $a['attempted'], $b['defects'], $b['attempted']);
        $ratio = $rr['point'];

        if ($rr['lower'] >= $factor - 1e-9) {
            return $this->verdict($a, $b, $ratio, $factor, true, 'a_at_least_factor_better', null,
                'relative-risk lower bound '.$rr['lower'].' >= factor '.$factor.' — A has at most 1/'.$factor.' of B\'s defect rate with ~95% confidence');
        }
        if ($rr['lower'] > 1.0 + 1e-9) {
            return $this->verdict($a, $b, $ratio, $factor, false, 'a_better_not_factor', null,
                'A has fewer defects with confidence (RR lower '.$rr['lower'].' > 1) but not >='.$factor.'x');
        }
        if ($rr['upper'] < 1.0 - 1e-9) {
            return $this->verdict($a, $b, $ratio, $factor, false, 'b_better', null,
                'B has fewer defects with confidence (RR upper '.$rr['upper'].' < 1) — A does NOT beat B');
        }

        // RR CI straddles 1 => parity on the primary; the machine tie-breaks decide.
        $tie = $this->tieBreak($a, $b);

        return $this->verdict($a, $b, $ratio, $factor, false, 'parity_tie_breaks_decide', $tie,
            'defect-rate relative-risk CI straddles 1 (no confident defect winner); tie-break axes favour '.($tie ?? 'neither'));
    }

    /** @return array{0:float,1:float} Wilson score-interval [lower, upper] for k defects in n trials. */
    private function wilson(int $k, int $n, float $z = 1.96): array
    {
        if ($n <= 0) {
            return [0.0, 0.0];
        }
        $phat = $k / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = ($phat + $z2 / (2 * $n)) / $denom;
        $margin = ($z * sqrt(($phat * (1 - $phat) + $z2 / (4 * $n)) / $n)) / $denom;

        return [max(0.0, $centre - $margin), min(1.0, $centre + $margin)];
    }

    /**
     * Relative risk of B's defect rate vs A's (RR = pB/pA), with the Katz log-method 95% CI and a
     * Haldane-Anscombe +0.5 correction so zero-defect cells do not divide by zero. RR > 1 means A has
     * the lower defect rate; the LOWER bound is what licenses a confident ">=factor better" claim.
     *
     * @return array{point:?float, lower:float, upper:float}
     */
    private function relativeRisk(int $dA, int $nA, int $dB, int $nB, float $z = 1.96): array
    {
        if ($nA <= 0 || $nB <= 0) {
            return ['point' => null, 'lower' => 0.0, 'upper' => 0.0];
        }
        // Haldane-Anscombe: +0.5 to each 2x2 cell => +1 to each total.
        $pA = ($dA + 0.5) / ($nA + 1);
        $pB = ($dB + 0.5) / ($nB + 1);
        $rr = $pB / $pA;
        $se = sqrt(1.0 / ($dA + 0.5) - 1.0 / ($nA + 1) + 1.0 / ($dB + 0.5) - 1.0 / ($nB + 1));
        $ln = log($rr);

        return [
            'point' => round($rr, 3),
            'lower' => round(exp($ln - $z * $se), 3),
            'upper' => round(exp($ln + $z * $se), 3),
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function tieBreak(array $a, array $b): ?string
    {
        // Ordered machine tie-breaks: robustness (mutation), then completeness, then maintainability (cx drop).
        foreach (['mean_mutation_kill', 'mean_completeness', 'mean_cyclomatic_drop'] as $axis) {
            $av = (float) $a[$axis];
            $bv = (float) $b[$axis];
            if (abs($av - $bv) > 0.02) {
                return $av > $bv ? 'a' : 'b';
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return array{a:array<string,mixed>, b:array<string,mixed>, defect_ratio_point:?float, factor:float, confident_a_better:bool, verdict:string, tie_break_winner:?string, reason:string}
     */
    private function verdict(array $a, array $b, ?float $ratio, float $factor, bool $confident, string $verdict, ?string $tie, string $reason): array
    {
        return [
            'a' => $a,
            'b' => $b,
            'defect_ratio_point' => $ratio === null ? null : ($ratio === INF ? null : round($ratio, 3)),
            'factor' => $factor,
            'confident_a_better' => $confident,
            'verdict' => $verdict,
            'tie_break_winner' => $tie,
            'reason' => $reason,
        ];
    }
}
