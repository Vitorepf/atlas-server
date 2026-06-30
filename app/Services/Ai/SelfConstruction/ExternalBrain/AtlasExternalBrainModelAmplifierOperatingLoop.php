<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic decision coordinator for the model-amplifier loop.
 * Emits one of five decisions based on scaffold evidence, proxy detection,
 * benchmark score, and frontier availability — with no side effects.
 *
 * Decision priority (first match wins):
 *   1. proxy_leak_detected           → repair_scaffold
 *   2. scaffold retire_signal        → retire_scaffold
 *   3. scaffold repair_signal        → repair_scaffold
 *   4. frontier available + budget + benchmark < 0.70 → escalate_frontier
 *   5. scaffold available + positive lift             → run_scaffolded
 *   6. (default)                     → run_small  ← steady-state, no frontier needed
 *
 * Frontier is NEVER required for steady-state autonomy; it is only used when
 * explicitly available, budget allows, AND the benchmark warrants escalation.
 *
 * CLOSED FEEDBACK (AC2/AC3):
 *   next_run_plan includes scaffold_variant, context_budget, regression_suite,
 *   repair_policy, and promotion_blocked.
 *   promotion_blocked = true when:
 *     held_out_regressions_failing=true  OR
 *     weak_output_repair_refusing=true   OR
 *     give_back_risk > give_back_risk_threshold (default 0.30)
 */
final class AtlasExternalBrainModelAmplifierOperatingLoop
{
    public const SCHEMA = 'atlas.external_brain.model_amplifier_operating_loop.v1';

    public const DECISION_RUN_SMALL = 'run_small';
    public const DECISION_RUN_SCAFFOLDED = 'run_scaffolded';
    public const DECISION_ESCALATE_FRONTIER = 'escalate_frontier';
    public const DECISION_REPAIR_SCAFFOLD = 'repair_scaffold';
    public const DECISION_RETIRE_SCAFFOLD = 'retire_scaffold';

    public const ESCALATION_SCORE_THRESHOLD = 0.70;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $se = is_array($input['scaffold_evidence'] ?? null) ? $input['scaffold_evidence'] : [];
        $proxyLeak        = (bool)  ($input['proxy_leak_detected']          ?? false);
        $benchmark        = (float) ($input['benchmark_score']              ?? 1.0);
        $frontierAvail    = (bool)  ($input['frontier_available']           ?? false);
        $escalationBudget = (bool)  ($input['escalation_budget_remaining']  ?? false);
        $scaffoldAvail    = (bool)  ($input['scaffold_available']           ?? false);
        $lift             = (float) ($se['lift_score']                      ?? 0.0);
        $retireSignal     = (bool)  ($se['retire_signal']                   ?? false);
        $repairSignal     = (bool)  ($se['repair_signal']                   ?? false);

        // AC2/AC3: closed-feedback plan fields.
        $scaffoldVariant          = (string) ($input['scaffold_variant']           ?? 'default');
        $contextBudget            = (int)    ($input['context_budget']             ?? 0);
        $regressionSuite          = is_array($input['regression_suite'] ?? null) ? $input['regression_suite'] : [];
        $repairPolicy             = (string) ($input['repair_policy']              ?? 'retry_with_stronger_scaffold');
        $heldOutFailing           = (bool)   ($input['held_out_regressions_failing'] ?? false);
        $weakOutputRefusing       = (bool)   ($input['weak_output_repair_refusing']  ?? false);
        $giveBackRisk             = (float)  ($input['give_back_risk']             ?? 0.0);
        $giveBackRiskThreshold    = (float)  ($input['give_back_risk_threshold']   ?? 0.30);

        $promotionBlocked = $heldOutFailing
            || $weakOutputRefusing
            || $giveBackRisk > $giveBackRiskThreshold;

        $decision = match (true) {
            $proxyLeak                                                  => self::DECISION_REPAIR_SCAFFOLD,
            $retireSignal                                               => self::DECISION_RETIRE_SCAFFOLD,
            $repairSignal                                               => self::DECISION_REPAIR_SCAFFOLD,
            $frontierAvail && $escalationBudget && $benchmark < self::ESCALATION_SCORE_THRESHOLD
                                                                        => self::DECISION_ESCALATE_FRONTIER,
            $scaffoldAvail && $lift > 0.0                               => self::DECISION_RUN_SCAFFOLDED,
            default                                                     => self::DECISION_RUN_SMALL,
        };

        $rationale = match ($decision) {
            self::DECISION_REPAIR_SCAFFOLD   => $proxyLeak ? 'proxy_leak_detected' : 'scaffold_repair_signal',
            self::DECISION_RETIRE_SCAFFOLD   => 'scaffold_retire_signal',
            self::DECISION_ESCALATE_FRONTIER => 'benchmark_below_threshold_frontier_available',
            self::DECISION_RUN_SCAFFOLDED    => 'scaffold_provides_positive_lift',
            default                          => 'default_steady_state',
        };

        return [
            'schema_version'  => self::SCHEMA,
            'decision'        => $decision,
            'rationale'       => $rationale,
            'frontier_required' => false,
            'receipt'         => [
                'proxy_leak_detected' => $proxyLeak,
                'frontier_available'  => $frontierAvail,
                'scaffold_available'  => $scaffoldAvail,
                'benchmark_score'     => $benchmark,
            ],
            'next_run_plan'   => [
                'scaffold_variant'  => $scaffoldVariant,
                'context_budget'    => $contextBudget,
                'regression_suite'  => $regressionSuite,
                'repair_policy'     => $repairPolicy,
                'promotion_blocked' => $promotionBlocked,
            ],
        ];
    }
}
