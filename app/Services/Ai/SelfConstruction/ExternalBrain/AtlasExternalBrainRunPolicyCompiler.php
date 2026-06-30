<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles an external-brain run contract from caller config and evaluates run-state against it.
 *
 * compile() builds the policy object; evaluate() audits a live run-state and reports whether
 * the run may stop, what violations exist, and what actions are required.
 *
 * COMPILE INPUT config:
 *   { target_quota:int, min_value_score:float[0..1], stall_threshold_pct:float[0..1],
 *     breakthrough_required_after_stall?:bool, forbidden_behaviours?:list<string>,
 *     continuation_rules?:list<string> }
 *
 * COMPILED POLICY:
 *   { schema, target_quota, min_value_score, stall_threshold, forbidden_behaviours,
 *     continuation_rules, breakthrough_required_after_stall, honest_exhausted_criteria }
 *
 * EVALUATE INPUT run_state:
 *   { verified_count:int, stalled:bool, breakthrough_actions_taken:bool, honest_exhausted:bool,
 *     padding_detected?:bool, same_template_fill_detected?:bool,
 *     human_dependent_steady_state?:bool, value_score?:float }
 *
 * EVALUATE OUTPUT:
 *   { schema, can_stop:bool, violations:list<string>, required_actions:list<string>,
 *     stop_reason:string|null }
 *
 * HARD RULES (always enforced regardless of config):
 *   1. quota not reached → must show breakthrough actions OR honest_exhausted evidence bundle.
 *   2. padding detected → always violates.
 *   3. same-template quota fill → always violates.
 *   4. human_dependent_steady_state → always violates.
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainRunPolicyCompiler
{
    public const SCHEMA_POLICY  = 'atlas.external_brain.run_policy.v1';

    public const SCHEMA_VERDICT = 'atlas.external_brain.run_policy_verdict.v1';

    public const FORBIDDEN_PADDING               = 'padding';

    public const FORBIDDEN_SAME_TEMPLATE_FILL    = 'same_template_quota_fill';

    public const FORBIDDEN_HUMAN_DEPENDENT       = 'human_dependent_steady_state';

    private const DEFAULT_FORBIDDEN = [
        self::FORBIDDEN_PADDING,
        self::FORBIDDEN_SAME_TEMPLATE_FILL,
        self::FORBIDDEN_HUMAN_DEPENDENT,
    ];

    private const DEFAULT_CONTINUATION_RULES = [
        'continue_until_quota_reached_or_honest_exhausted',
        'invoke_breakthrough_planner_on_stall',
        'never_repeat_same_template_to_pad_quota',
    ];

    private const DEFAULT_HONEST_EXHAUSTED_CRITERIA = [
        'all_escalation_modes_attempted_with_evidence',
        'breakthrough_planner_returned_honest_exhausted',
    ];

    /** Runtime quality gate defaults (applied on top of quota, even when quota is reached). */
    private const DEFAULT_MIN_FRESHNESS_RATIO          = 0.80; // ≥80% evidence records must be fresh
    private const DEFAULT_MAX_GIVE_BACK_RATIO          = 0.30; // ≤30% tasks returned without value
    private const DEFAULT_MAX_TEMPLATE_REPETITION_RATE = 0.20; // ≤20% tasks from same template

    /**
     * @param  array{
     *   target_quota?:int,
     *   min_value_score?:float,
     *   stall_threshold_pct?:float,
     *   breakthrough_required_after_stall?:bool,
     *   forbidden_behaviours?:list<string>,
     *   continuation_rules?:list<string>,
     * }  $config
     * @return array<string,mixed>
     */
    public function compile(array $config): array
    {
        $targetQuota = max(1, (int) ($config['target_quota'] ?? 1));
        $minValueScore = max(0.0, min(1.0, (float) ($config['min_value_score'] ?? 0.6)));
        $stallThresholdPct = max(0.0, min(1.0, (float) ($config['stall_threshold_pct'] ?? 0.50)));
        $breakthroughRequired = (bool) ($config['breakthrough_required_after_stall'] ?? true);

        $forbidden = is_array($config['forbidden_behaviours'] ?? null)
            ? array_values(array_unique(array_merge(self::DEFAULT_FORBIDDEN, $config['forbidden_behaviours'])))
            : self::DEFAULT_FORBIDDEN;

        $continuationRules = is_array($config['continuation_rules'] ?? null)
            ? $config['continuation_rules']
            : self::DEFAULT_CONTINUATION_RULES;

        // stall_threshold: absolute count below which a stall is declared (pct of target).
        $stallThreshold = max(1, (int) ceil($targetQuota * $stallThresholdPct));

        return [
            'schema' => self::SCHEMA_POLICY,
            'target_quota' => $targetQuota,
            'min_value_score' => $minValueScore,
            'stall_threshold' => $stallThreshold,
            'stall_threshold_pct' => $stallThresholdPct,
            'forbidden_behaviours' => $forbidden,
            'continuation_rules' => $continuationRules,
            'breakthrough_required_after_stall' => $breakthroughRequired,
            'honest_exhausted_criteria' => self::DEFAULT_HONEST_EXHAUSTED_CRITERIA,
            'runtime_quality_gates' => [
                'min_freshness_ratio'          => self::DEFAULT_MIN_FRESHNESS_RATIO,
                'min_avg_value_score'          => $minValueScore,
                'max_give_back_ratio'          => self::DEFAULT_MAX_GIVE_BACK_RATIO,
                'max_template_repetition_rate' => self::DEFAULT_MAX_TEMPLATE_REPETITION_RATE,
            ],
        ];
    }

    /**
     * Evaluate a live run state against the compiled policy.
     *
     * @param  array<string,mixed>  $policy    Output of compile().
     * @param  array{
     *   verified_count?:int,
     *   stalled?:bool,
     *   breakthrough_actions_taken?:bool,
     *   honest_exhausted?:bool,
     *   padding_detected?:bool,
     *   same_template_fill_detected?:bool,
     *   human_dependent_steady_state?:bool,
     *   value_score?:float,
     * }  $runState
     * @return array{schema:string, can_stop:bool, violations:list<string>, required_actions:list<string>, stop_reason:string|null}
     */
    public function evaluate(array $policy, array $runState): array
    {
        $violations = [];
        $requiredActions = [];

        $targetQuota = max(1, (int) ($policy['target_quota'] ?? 1));
        $minValueScore = (float) ($policy['min_value_score'] ?? 0.6);
        $breakthroughRequired = (bool) ($policy['breakthrough_required_after_stall'] ?? true);

        $verifiedCount = max(0, (int) ($runState['verified_count'] ?? 0));
        $stalled = (bool) ($runState['stalled'] ?? false);
        $breakthroughTaken = (bool) ($runState['breakthrough_actions_taken'] ?? false);
        $honestExhausted = (bool) ($runState['honest_exhausted'] ?? false);
        $paddingDetected = (bool) ($runState['padding_detected'] ?? false);
        $sameTemplateFill = (bool) ($runState['same_template_fill_detected'] ?? false);
        $humanDependent = (bool) ($runState['human_dependent_steady_state'] ?? false);
        $valueScore = (float) ($runState['value_score'] ?? 1.0);

        // RULE 1 — quota not reached: must have breakthrough actions OR honest_exhausted.
        if ($verifiedCount < $targetQuota) {
            if (! $honestExhausted && (! $stalled || ! $breakthroughTaken)) {
                if ($stalled && $breakthroughRequired && ! $breakthroughTaken) {
                    $violations[] = 'quota_not_reached:stalled_without_breakthrough_actions';
                    $requiredActions[] = 'invoke_breakthrough_planner';
                } elseif (! $stalled) {
                    $violations[] = 'quota_not_reached:run_not_stalled_keep_searching';
                    $requiredActions[] = 'continue_searching';
                }
            }
            // If honest_exhausted=true, quota shortfall is acceptable.
        }

        // RULE 2 — padding forbidden.
        if ($paddingDetected) {
            $violations[] = 'forbidden_behaviour:padding_detected';
            $requiredActions[] = 'replace_padding_with_substantive_origination';
        }

        // RULE 3 — same-template fill forbidden.
        if ($sameTemplateFill) {
            $violations[] = 'forbidden_behaviour:same_template_quota_fill_detected';
            $requiredActions[] = 'diversify_patterns_before_continuing';
        }

        // RULE 4 — human-dependent steady-state forbidden.
        if ($humanDependent) {
            $violations[] = 'forbidden_behaviour:human_dependent_steady_state';
            $requiredActions[] = 'restore_autonomous_origination';
        }

        // RULE 5 — value score below minimum.
        if ($valueScore < $minValueScore) {
            $violations[] = 'value_score_below_minimum:'.round($valueScore, 4).':min:'.round($minValueScore, 4);
            $requiredActions[] = 'raise_value_bar_before_continuing';
        }

        // RUNTIME QUALITY GATES — applied even when quota is reached.
        $gates = is_array($policy['runtime_quality_gates'] ?? null) ? $policy['runtime_quality_gates'] : [];

        $freshnessRatio = (float) ($runState['freshness_ratio'] ?? 1.0);
        $minFreshness   = (float) ($gates['min_freshness_ratio'] ?? self::DEFAULT_MIN_FRESHNESS_RATIO);
        if ($freshnessRatio < $minFreshness) {
            $violations[] = 'runtime_quality_gate:freshness_ratio:'.round($freshnessRatio, 4).':min:'.round($minFreshness, 4);
            $requiredActions[] = 'refresh_stale_evidence_before_continuing';
        }

        $giveBackRatio    = (float) ($runState['give_back_ratio'] ?? 0.0);
        $maxGiveBack      = (float) ($gates['max_give_back_ratio'] ?? self::DEFAULT_MAX_GIVE_BACK_RATIO);
        if ($giveBackRatio > $maxGiveBack) {
            $violations[] = 'runtime_quality_gate:give_back_ratio:'.round($giveBackRatio, 4).':max:'.round($maxGiveBack, 4);
            $requiredActions[] = 'reduce_give_back_rate_before_continuing';
        }

        $templateRepetition    = (float) ($runState['template_repetition_rate'] ?? 0.0);
        $maxTemplateRepetition = (float) ($gates['max_template_repetition_rate'] ?? self::DEFAULT_MAX_TEMPLATE_REPETITION_RATE);
        if ($templateRepetition > $maxTemplateRepetition) {
            $violations[] = 'runtime_quality_gate:template_repetition_rate:'.round($templateRepetition, 4).':max:'.round($maxTemplateRepetition, 4);
            $requiredActions[] = 'diversify_task_templates_before_continuing';
        }

        $violations = array_values($violations);
        $requiredActions = array_values(array_unique($requiredActions));

        $canStop = $violations === [] && ($verifiedCount >= $targetQuota || $honestExhausted);

        $stopReason = null;
        if ($canStop) {
            $stopReason = $honestExhausted ? 'honest_exhausted' : 'quota_reached';
        }

        return [
            'schema' => self::SCHEMA_VERDICT,
            'can_stop' => $canStop,
            'violations' => $violations,
            'required_actions' => $requiredActions,
            'stop_reason' => $stopReason,
        ];
    }
}
