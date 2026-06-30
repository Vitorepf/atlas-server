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
 * METRICS:
 *   would_admit          — count admitted under current thresholds
 *   false_reject_green   — admitted=false AND outcome=commit_success (false negatives)
 *   true_reject_poison   — admitted=false AND outcome IN [give_back, poison] (correct)
 *   missed_poison        — admitted=true  AND outcome IN [give_back, poison] (false positives)
 *
 * THRESHOLD ADJUSTMENT RULES (conservative, ±0.05 increments):
 *   missed_poison / total > MISSED_POISON_RATE_CEILING
 *     → lower give_back_risk_ceiling by 0.05 (catch more risk)
 *   false_reject_green / total > FALSE_REJECT_RATE_CEILING
 *     → lower compound_impact_floor by 0.05 (let more high-impact through)
 *   (Both can fire at once.)
 *
 * DEFAULT THRESHOLDS:
 *   compound_impact_floor    = 0.30
 *   give_back_risk_ceiling   = 0.70
 *   MISSED_POISON_RATE       = 0.30  (>30% misses → tighten risk ceiling)
 *   FALSE_REJECT_RATE        = 0.20  (>20% false rejects → loosen impact floor)
 *   ADJUSTMENT_STEP          = 0.05
 *
 * OUTPUT:
 *   {
 *     schema,
 *     would_admit,
 *     false_reject_green,
 *     true_reject_poison,
 *     missed_poison,
 *     recommended_threshold_adjustments
 *   }
 */
final class AtlasExternalBrainValueGateBacktestReplay
{
    public const SCHEMA = 'atlas.external_brain.value_gate_backtest_replay.v1';

    private const DEFAULT_IMPACT_FLOOR       = 0.30;
    private const DEFAULT_RISK_CEILING       = 0.70;
    private const MISSED_POISON_RATE_CEILING = 0.30;
    private const FALSE_REJECT_RATE_CEILING  = 0.20;
    private const ADJUSTMENT_STEP            = 0.05;

    private const POISON_OUTCOMES = ['give_back', 'poison'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function replay(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $thresholds = is_array($input['current_thresholds'] ?? null) ? $input['current_thresholds'] : [];

        $impactFloor  = (float) ($thresholds['compound_impact_floor']  ?? self::DEFAULT_IMPACT_FLOOR);
        $riskCeiling  = (float) ($thresholds['give_back_risk_ceiling'] ?? self::DEFAULT_RISK_CEILING);

        $wouldAdmit       = 0;
        $falseRejectGreen = 0;
        $trueRejectPoison = 0;
        $missedPoison     = 0;
        $total            = 0;

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
                $wouldAdmit++;
                if ($isPoison) {
                    $missedPoison++;
                }
            } else {
                if ($isGreen) {
                    $falseRejectGreen++;
                } elseif ($isPoison) {
                    $trueRejectPoison++;
                }
            }
        }

        $adjustments = $this->computeAdjustments(
            $total,
            $missedPoison,
            $falseRejectGreen,
            $impactFloor,
            $riskCeiling,
        );

        return [
            'schema'                           => self::SCHEMA,
            'would_admit'                      => $wouldAdmit,
            'false_reject_green'               => $falseRejectGreen,
            'true_reject_poison'               => $trueRejectPoison,
            'missed_poison'                    => $missedPoison,
            'recommended_threshold_adjustments' => $adjustments,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function computeAdjustments(
        int   $total,
        int   $missedPoison,
        int   $falseRejectGreen,
        float $impactFloor,
        float $riskCeiling,
    ): array {
        if ($total === 0) {
            return [];
        }

        $adjustments = [];

        $missedRate      = $missedPoison     / $total;
        $falseRejectRate = $falseRejectGreen / $total;

        if ($missedRate > self::MISSED_POISON_RATE_CEILING) {
            $recommended = round(max(0.0, $riskCeiling - self::ADJUSTMENT_STEP), 6);
            $adjustments[] = [
                'threshold'         => 'give_back_risk_ceiling',
                'current_value'     => $riskCeiling,
                'recommended_value' => $recommended,
                'rationale'         => sprintf(
                    'missed_poison_rate_%.2f_exceeds_ceiling_%.2f; tighten to block more risky candidates',
                    $missedRate,
                    self::MISSED_POISON_RATE_CEILING,
                ),
            ];
        }

        if ($falseRejectRate > self::FALSE_REJECT_RATE_CEILING) {
            $recommended = round(max(0.0, $impactFloor - self::ADJUSTMENT_STEP), 6);
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
