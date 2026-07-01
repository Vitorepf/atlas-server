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
 *
 * AC5: Also simulates waste for duplicate specs, poison-prone packets, false-wait
 *      loops, and low-value backlog accumulation (baseline/proposed 'duplicate_spec',
 *      'poison', 'false_wait', 'low_value_backlog' counts), converting any waste a
 *      policy avoids in those categories into concrete prevention_rules plus an
 *      expected_savings figure — never only a scalar waste score.
 */
final class AtlasExternalBrainWasteReductionSimulator
{
    public const SCHEMA = 'atlas.external_brain.waste_reduction_simulator.v1';

    private const PENALTY_MULTIPLIER          = 3.0;
    private const CONFIDENCE_HIGH_SERVED_FLOOR = 50;
    private const CONFIDENCE_HIGH_AVOIDED_FLOOR = 5;
    private const CONFIDENCE_MEDIUM_SERVED_FLOOR = 20;
    private const CONFIDENCE_MEDIUM_AVOIDED_FLOOR = 2;

    /** waste_key => prevention rule template (sprintf'd with the avoided count) */
    private const PREVENTION_RULE_TEMPLATES = [
        'duplicate_spec' => 'Dedup candidate specs against prior proposals before origination to avoid %d wasted duplicate-spec cycles.',
        'poison' => 'Quarantine poison-prone packets before serving to avoid %d wasted poison cycles.',
        'false_wait' => 'Reject false-wait/idle-stall language in the originating prompt to avoid %d wasted false-wait loops.',
        'low_value_backlog' => 'Require value/evidence gating on backlog accumulation to avoid %d low-value backlog cycles.',
    ];

    /**
     * @param  array{
     *   baseline?: array<string,int>,
     *   proposed?: array<string,int>,
     *   tokens_per_task?: float,
     * }  $input
     * @return array{schema:string, avoided_give_backs:int, avoided_quarantines:int, avoided_malformed_serves:int, estimated_tokens_saved:float, confidence_band:string, penalty_applied:bool, penalty_reason:string|null, net_value_score:float, prevention_rules:list<string>, expected_savings:float}
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
        $baseDuplicateSpec    = max(0, (int) ($baseline['duplicate_spec']     ?? 0));
        $baseFalseWait        = max(0, (int) ($baseline['false_wait']         ?? 0));
        $baseLowValueBacklog  = max(0, (int) ($baseline['low_value_backlog']  ?? 0));

        $propGiveBacks   = max(0, (int) ($proposed['give_back']             ?? 0));
        $propQuarantine  = max(0, (int) ($proposed['quarantine']            ?? 0));
        $propMalformed   = max(0, (int) ($proposed['malformed']             ?? 0));
        $propHighImpact  = max(0, (int) ($proposed['successful_high_impact'] ?? 0));
        $propPoison      = max(0, (int) ($proposed['poison']                ?? 0));
        $propDuplicateSpec    = max(0, (int) ($proposed['duplicate_spec']     ?? 0));
        $propFalseWait        = max(0, (int) ($proposed['false_wait']         ?? 0));
        $propLowValueBacklog  = max(0, (int) ($proposed['low_value_backlog']  ?? 0));

        $avoidedGiveBacks  = max(0, $baseGiveBacks  - $propGiveBacks);
        $avoidedQuarantine = max(0, $baseQuarantine - $propQuarantine);
        $avoidedMalformed  = max(0, $baseMalformed  - $propMalformed);
        $avoidedPoison     = max(0, $basePoison     - $propPoison);
        $avoidedDuplicateSpec   = max(0, $baseDuplicateSpec   - $propDuplicateSpec);
        $avoidedFalseWait       = max(0, $baseFalseWait       - $propFalseWait);
        $avoidedLowValueBacklog = max(0, $baseLowValueBacklog - $propLowValueBacklog);
        $totalAvoided      = $avoidedGiveBacks + $avoidedQuarantine + $avoidedMalformed;

        $tokensSaved = $totalAvoided * $tokensPerTask;

        // AC5: prevention rules + expected savings for the 4 named waste categories
        $wasteCounts = [
            'duplicate_spec' => $avoidedDuplicateSpec,
            'poison' => $avoidedPoison,
            'false_wait' => $avoidedFalseWait,
            'low_value_backlog' => $avoidedLowValueBacklog,
        ];
        $preventionRules = [];
        $extraWasteSavings = 0.0;
        foreach ($wasteCounts as $wasteKey => $avoidedCount) {
            if ($avoidedCount > 0) {
                $preventionRules[] = sprintf(self::PREVENTION_RULE_TEMPLATES[$wasteKey], $avoidedCount);
                $extraWasteSavings += $avoidedCount * $tokensPerTask;
            }
        }

        // AC3: penalty when high-impact tasks are lost
        $impactLoss           = max(0, $baseHighImpact - $propHighImpact);
        $penaltyApplied       = $impactLoss > 0;
        $falseNegativePenalty = $penaltyApplied ? $impactLoss * $tokensPerTask * self::PENALTY_MULTIPLIER : 0.0;
        $penaltyReason        = $penaltyApplied
            ? sprintf('policy_rejected_%d_successful_high_impact_tasks', $impactLoss)
            : null;

        $netValueScore  = $tokensSaved - $falseNegativePenalty;
        $netPolicyValue = $tokensSaved + $extraWasteSavings - $falseNegativePenalty;
        $expectedSavings = $netPolicyValue;

        return [
            'schema'                   => self::SCHEMA,
            'avoided_give_backs'       => $avoidedGiveBacks,
            'avoided_give_back_count'  => $avoidedGiveBacks,
            'avoided_quarantines'      => $avoidedQuarantine,
            'avoided_malformed_serves' => $avoidedMalformed,
            'avoided_poison_count'     => $avoidedPoison,
            'avoided_duplicate_spec_count'   => $avoidedDuplicateSpec,
            'avoided_false_wait_count'       => $avoidedFalseWait,
            'avoided_low_value_backlog_count' => $avoidedLowValueBacklog,
            'lost_high_value_count'    => $impactLoss,
            'false_negative_penalty'   => $falseNegativePenalty,
            'estimated_tokens_saved'   => $tokensSaved,
            'confidence_band'          => $this->confidenceBand($baseServed, $totalAvoided),
            'penalty_applied'          => $penaltyApplied,
            'penalty_reason'           => $penaltyReason,
            'net_value_score'          => $netValueScore,
            'net_policy_value'         => $netPolicyValue,
            'prevention_rules'         => $preventionRules,
            'expected_savings'         => $expectedSavings,
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
