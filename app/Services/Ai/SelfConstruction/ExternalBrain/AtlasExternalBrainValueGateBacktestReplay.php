<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Replays historical candidate-task outcomes through the brutal value gate to calibrate
 * thresholds that reduce give_backs without blocking successful high-impact tasks.
 * PURE / DETERMINISTIC. Consumes supplied facts only; performs no I/O or provider calls.
 *
 * OUTCOME CATEGORIES:
 *   commit_success — task completed successfully (green)
 *   give_back      — worker gave back (soft poison)
 *   poison         — hard poison / quarantine
 *
 * SIMULATION:
 *   For each historical candidate the gate admission is re-evaluated using supplied
 *   compound_impact_score and give_back_risk_score against the current (or default)
 *   thresholds.
 *
 * CONFUSION-MATRIX METRICS (new):
 *   admitted_green  — admitted AND outcome=commit_success (true positives for good tasks)
 *   rejected_green  — rejected AND outcome=commit_success (false negatives / false rejects)
 *   admitted_poison — admitted AND outcome IN [give_back, poison] (false positives)
 *   rejected_poison — rejected AND outcome IN [give_back, poison] (true negatives)
 *
 * LEGACY ALIASES (kept for backward compat):
 *   would_admit          — admitted_green + admitted_poison
 *   false_reject_green   — same as rejected_green
 *   true_reject_poison   — same as rejected_poison
 *   missed_poison        — same as admitted_poison
 *
 * DERIVED METRICS (new):
 *   precision                    — admitted_green / max(1, admitted_green + admitted_poison)
 *   recall                       — admitted_green / max(1, admitted_green + rejected_green)
 *   estimated_token_waste_avoided — rejected_poison * token_cost_per_task (default 1000)
 *
 * THRESHOLD ADJUSTMENT RULES (conservative, ±0.05 increments):
 *   Tighten risk ceiling (lower give_back_risk_ceiling by 0.05) when:
 *     (missed_poison_rate > MISSED_POISON_RATE_CEILING  OR  admitted_poison > admitted_green)
 *     AND NOT (rejected_poison > admitted_poison AND precision >= PRECISION_FLOOR_FOR_EXEMPTION)
 *   Loosen impact floor (lower compound_impact_floor by 0.05) when:
 *     false_reject_green / total > FALSE_REJECT_RATE_CEILING
 *
 * DEFAULT THRESHOLDS:
 *   compound_impact_floor          = 0.30
 *   give_back_risk_ceiling         = 0.70
 *   MISSED_POISON_RATE             = 0.30  (>30% misses → consider tighten)
 *   FALSE_REJECT_RATE              = 0.20  (>20% false rejects → loosen impact floor)
 *   PRECISION_FLOOR_FOR_EXEMPTION  = 0.70  (precision above this + rejected dominates → no tighten)
 *   ADJUSTMENT_STEP                = 0.05
 *   DEFAULT_TOKEN_COST_PER_TASK    = 1000
 *
 * INPUT: { candidates, current_thresholds?, token_cost_per_task? }
 *
 * OUTPUT:
 *   {
 *     schema,
 *     would_admit, false_reject_green, true_reject_poison, missed_poison,
 *     admitted_green, rejected_green, admitted_poison, rejected_poison,
 *     precision, recall, estimated_token_waste_avoided,
 *     recommended_threshold_adjustments
 *   }
 */
final class AtlasExternalBrainValueGateBacktestReplay
{
    public const SCHEMA = 'atlas.external_brain.value_gate_backtest_replay.v1';

    private const DEFAULT_IMPACT_FLOOR                = 0.30;
    private const DEFAULT_RISK_CEILING                = 0.70;
    private const MISSED_POISON_RATE_CEILING          = 0.30;
    private const FALSE_REJECT_RATE_CEILING           = 0.20;
    private const PRECISION_FLOOR_FOR_TIGHTEN_EXEMPTION = 0.40;
    private const ADJUSTMENT_STEP                     = 0.05;
    private const DEFAULT_TOKEN_COST_PER_TASK         = 1000;

    private const POISON_OUTCOMES = ['give_back', 'poison'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function replay(array $input): array
    {
        $candidates       = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $thresholds       = is_array($input['current_thresholds'] ?? null) ? $input['current_thresholds'] : [];
        $tokenCostPerTask = max(0, (int) ($input['token_cost_per_task'] ?? self::DEFAULT_TOKEN_COST_PER_TASK));

        $impactFloor = (float) ($thresholds['compound_impact_floor']  ?? self::DEFAULT_IMPACT_FLOOR);
        $riskCeiling = (float) ($thresholds['give_back_risk_ceiling'] ?? self::DEFAULT_RISK_CEILING);

        $admittedGreen  = 0;
        $rejectedGreen  = 0;
        $admittedPoison = 0;
        $rejectedPoison = 0;
        $total          = 0;

        foreach ($candidates as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $candidate = is_array($entry['candidate'] ?? null) ? $entry['candidate'] : [];
            $outcome   = trim(strtolower((string) ($entry['outcome'] ?? '')));

            $impactScore = (float) ($candidate['compound_impact_score'] ?? 0.0);
            $riskScore   = (float) ($candidate['give_back_risk_score']  ?? 0.0);

            $admitted = $impactScore >= $impactFloor && $riskScore < $riskCeiling;
            $isPoison = in_array($outcome, self::POISON_OUTCOMES, true);
            $isGreen  = $outcome === 'commit_success';

            $total++;

            if ($admitted) {
                if ($isGreen) {
                    $admittedGreen++;
                } elseif ($isPoison) {
                    $admittedPoison++;
                }
            } else {
                if ($isGreen) {
                    $rejectedGreen++;
                } elseif ($isPoison) {
                    $rejectedPoison++;
                }
            }
        }

        $wouldAdmit       = $admittedGreen  + $admittedPoison;
        $falseRejectGreen = $rejectedGreen;
        $trueRejectPoison = $rejectedPoison;
        $missedPoison     = $admittedPoison;

        $precision = $admittedGreen / max(1, $admittedGreen + $admittedPoison);
        $recall    = $admittedGreen / max(1, $admittedGreen + $rejectedGreen);

        $adjustments = $this->computeAdjustments(
            $total,
            $admittedGreen,
            $admittedPoison,
            $rejectedGreen,
            $rejectedPoison,
            $precision,
            $impactFloor,
            $riskCeiling,
        );

        return [
            'schema'              => self::SCHEMA,
            // legacy fields (unchanged)
            'would_admit'         => $wouldAdmit,
            'false_reject_green'  => $falseRejectGreen,
            'true_reject_poison'  => $trueRejectPoison,
            'missed_poison'       => $missedPoison,
            // confusion-matrix breakdown
            'admitted_green'      => $admittedGreen,
            'rejected_green'      => $rejectedGreen,
            'admitted_poison'     => $admittedPoison,
            'rejected_poison'     => $rejectedPoison,
            // derived calibration metrics
            'precision'                    => round($precision, 6),
            'recall'                       => round($recall,    6),
            'estimated_token_waste_avoided' => $rejectedPoison * $tokenCostPerTask,
            // adjustment recommendations
            'recommended_threshold_adjustments' => $adjustments,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function computeAdjustments(
        int   $total,
        int   $admittedGreen,
        int   $admittedPoison,
        int   $rejectedGreen,
        int   $rejectedPoison,
        float $precision,
        float $impactFloor,
        float $riskCeiling,
    ): array {
        if ($total === 0) {
            return [];
        }

        $adjustments    = [];
        $missedPoison   = $admittedPoison;
        $falseRejectCnt = $rejectedGreen;

        $missedRate      = $missedPoison   / $total;
        $falseRejectRate = $falseRejectCnt / $total;

        // Tighten risk ceiling when:
        //   (missed poison rate is high  OR  admitted_poison dominates over admitted_green)
        //   AND the gate is not already performing well (rejected_poison dominating + healthy precision)
        $admittedPoisonDominates = $admittedPoison > $admittedGreen;
        $shouldTighten           = $missedRate > self::MISSED_POISON_RATE_CEILING || $admittedPoisonDominates;
        $rejectedDominates       = $rejectedPoison > $admittedPoison;
        $tightenExempt           = $rejectedDominates && $precision >= self::PRECISION_FLOOR_FOR_TIGHTEN_EXEMPTION;

        if ($shouldTighten && ! $tightenExempt) {
            $recommended   = round(max(0.0, $riskCeiling - self::ADJUSTMENT_STEP), 6);
            $adjustments[] = [
                'threshold'         => 'give_back_risk_ceiling',
                'current_value'     => $riskCeiling,
                'recommended_value' => $recommended,
                'rationale'         => $admittedPoisonDominates && $missedRate <= self::MISSED_POISON_RATE_CEILING
                    ? sprintf(
                        'admitted_poison_%d_dominates_admitted_green_%d; tighten to block more risky candidates',
                        $admittedPoison,
                        $admittedGreen,
                    )
                    : sprintf(
                        'missed_poison_rate_%.2f_exceeds_ceiling_%.2f; tighten to block more risky candidates',
                        $missedRate,
                        self::MISSED_POISON_RATE_CEILING,
                    ),
            ];
        }

        if ($falseRejectRate > self::FALSE_REJECT_RATE_CEILING) {
            $recommended   = round(max(0.0, $impactFloor - self::ADJUSTMENT_STEP), 6);
            $adjustments[] = [
                'threshold'         => 'compound_impact_floor',
                'current_value'     => $impactFloor,
                'recommended_value' => $recommended,
                'rationale'         => sprintf(
                    'false_reject_green_rate_%.2f_exceeds_ceiling_%.2f; loosen to admit more high-impact tasks',
                    $falseRejectRate,
                    self::FALSE_REJECT_RATE_CEILING,
                ),
            ];
        }

        return $adjustments;
    }
}
