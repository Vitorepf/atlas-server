<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure simulation of token/time waste reduction from a proposed admission or
 * self-healing policy versus the historical baseline.
 *
 * AC2: Returns avoided_give_backs, avoided_quarantines, avoided_malformed_serves,
 *      avoided_poison_count, avoided_give_back_count, lost_high_value_count,
 *      false_negative_penalty, net_policy_value, estimated_tokens_saved and confidence_band.
 *
 * AC3: Penalizes policies that trim waste by also rejecting successful
 *      high-impact tasks. Penalty multiplier = 3× per lost high-impact task.
 *      net_policy_value = tokens_saved + poison_savings - false_negative_penalty.
 *
 * AC4: Pure PHP, no I/O, no providers, no queue mutation.
 */
final class AtlasExternalBrainWasteReductionSimulator
{
    public const SCHEMA = 'atlas.external_brain.waste_reduction_simulator.v1';

    private const PENALTY_MULTIPLIER          = 3.0;
    private const CONFIDENCE_HIGH_SERVED_FLOOR = 50;
    private const CONFIDENCE_HIGH_AVOIDED_FLOOR = 5;
    private const CONFIDENCE_MEDIUM_SERVED_FLOOR = 20;
    private const CONFIDENCE_MEDIUM_AVOIDED_FLOOR = 2;

    /**
     * @param  array{
     *   baseline?: array<string,int>,
     *   proposed?: array<string,int>,
     *   tokens_per_task?: float,
     * }  $input
     * @return array{schema:string, avoided_give_backs:int, avoided_quarantines:int, avoided_malformed_serves:int, estimated_tokens_saved:float, confidence_band:string, penalty_applied:bool, penalty_reason:string|null, net_value_score:float}
     */
    public function simulate(array $input): array
    {
        $baseline        = (array) ($input['baseline']       ?? []);
        $proposed        = (array) ($input['proposed']       ?? []);
        $tokensPerTask   = max(0.0, (float) ($input['tokens_per_task'] ?? 1.0));

        $baseGiveBacks   = max(0, (int) ($baseline['give_back']             ?? 0));
        $baseQuarantine  = max(0, (int) ($baseline['quarantine']            ?? 0));
        $baseMalformed   = max(0, (int) ($baseline['malformed']             ?? 0));
        $baseServed      = max(0, (int) ($baseline['served']                ?? 0));
        $baseHighImpact  = max(0, (int) ($baseline['successful_high_impact'] ?? 0));
        $basePoison      = max(0, (int) ($baseline['poison']                ?? 0));

        $propGiveBacks   = max(0, (int) ($proposed['give_back']             ?? 0));
        $propQuarantine  = max(0, (int) ($proposed['quarantine']            ?? 0));
        $propMalformed   = max(0, (int) ($proposed['malformed']             ?? 0));
        $propHighImpact  = max(0, (int) ($proposed['successful_high_impact'] ?? 0));
        $propPoison      = max(0, (int) ($proposed['poison']                ?? 0));

        $avoidedGiveBacks  = max(0, $baseGiveBacks  - $propGiveBacks);
        $avoidedQuarantine = max(0, $baseQuarantine - $propQuarantine);
        $avoidedMalformed  = max(0, $baseMalformed  - $propMalformed);
        $avoidedPoison     = max(0, $basePoison     - $propPoison);
        $totalAvoided      = $avoidedGiveBacks + $avoidedQuarantine + $avoidedMalformed;

        $tokensSaved = $totalAvoided * $tokensPerTask;

        // AC3: penalty when high-impact tasks are lost
        $impactLoss           = max(0, $baseHighImpact - $propHighImpact);
        $penaltyApplied       = $impactLoss > 0;
        $falseNegativePenalty = $penaltyApplied ? $impactLoss * $tokensPerTask * self::PENALTY_MULTIPLIER : 0.0;
        $penaltyReason        = $penaltyApplied
            ? sprintf('policy_rejected_%d_successful_high_impact_tasks', $impactLoss)
            : null;

        $netValueScore  = $tokensSaved - $falseNegativePenalty;
        $netPolicyValue = $tokensSaved + ($avoidedPoison * $tokensPerTask) - $falseNegativePenalty;

        return [
            'schema'                   => self::SCHEMA,
            'avoided_give_backs'       => $avoidedGiveBacks,
            'avoided_give_back_count'  => $avoidedGiveBacks,
            'avoided_quarantines'      => $avoidedQuarantine,
            'avoided_malformed_serves' => $avoidedMalformed,
            'avoided_poison_count'     => $avoidedPoison,
            'lost_high_value_count'    => $impactLoss,
            'false_negative_penalty'   => $falseNegativePenalty,
            'estimated_tokens_saved'   => $tokensSaved,
            'confidence_band'          => $this->confidenceBand($baseServed, $totalAvoided),
            'penalty_applied'          => $penaltyApplied,
            'penalty_reason'           => $penaltyReason,
            'net_value_score'          => $netValueScore,
            'net_policy_value'         => $netPolicyValue,
        ];
    }

    private function confidenceBand(int $baseServed, int $totalAvoided): string
    {
        if ($baseServed >= self::CONFIDENCE_HIGH_SERVED_FLOOR && $totalAvoided >= self::CONFIDENCE_HIGH_AVOIDED_FLOOR) {
            return 'high';
        }
        if ($baseServed >= self::CONFIDENCE_MEDIUM_SERVED_FLOOR || $totalAvoided >= self::CONFIDENCE_MEDIUM_AVOIDED_FLOOR) {
            return 'medium';
        }
        return 'low';
    }
}
