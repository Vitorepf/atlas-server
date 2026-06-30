<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, provider-agnostic model-amplifier discipline gate. Decides when a SMALL model (with a scaffold)
 * can handle a task and when frontier cognition is actually justified by evidence — never by default.
 * Inputs are facts about the task family, not provider names: ambiguity, blast radius, historical success
 * rate, repeated give_back count, acceptance strength, and implementation risk.
 *
 * Priority order (checked in this order so a weak-acceptance/low-risk task is routed to spec repair
 * BEFORE it can be swept into a frontier escalation it does not actually need):
 *   1. weak acceptance + low implementation risk  -> improve_spec_before_assignment
 *   2. high ambiguity / high blast radius / repeated give_back -> frontier_review
 *   3. low risk + high historical success for the task family -> small_with_scaffold
 *   4. no strong signal either way -> standard_review
 */
final class AtlasExternalBrainModelTierEscalationPolicyCompiler
{
    public const SCHEMA = 'atlas.self_construction.external_brain.model_tier_escalation_policy_compiler.v1';

    public const TIER_SMALL_WITH_SCAFFOLD = 'small_with_scaffold';

    public const TIER_FRONTIER_REVIEW = 'frontier_review';

    public const TIER_IMPROVE_SPEC = 'improve_spec_before_assignment';

    public const TIER_STANDARD_REVIEW = 'standard_review';

    private const HIGH_SUCCESS_THRESHOLD = 0.8;

    private const GIVE_BACK_ESCALATION_THRESHOLD = 2;

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function compile(array $task): array
    {
        $ambiguity = (string) ($task['ambiguity'] ?? 'low');
        $blastRadius = (string) ($task['blast_radius'] ?? 'low');
        $historicalSuccessRate = (float) ($task['historical_success_rate'] ?? 0.0);
        $giveBackCount = (int) ($task['give_back_count'] ?? 0);
        $weakAcceptance = (string) ($task['acceptance_strength'] ?? 'strong') === 'weak';
        $lowImplementationRisk = (string) ($task['implementation_risk'] ?? 'low') === 'low';
        $riskLevel = (string) ($task['risk_level'] ?? 'low');

        if ($weakAcceptance && $lowImplementationRisk) {
            return $this->result(
                self::TIER_IMPROVE_SPEC,
                'weak_acceptance_low_implementation_risk',
                [],
                'no_provider_spend_until_spec_improved',
            );
        }

        if ($ambiguity === 'high') {
            return $this->result(self::TIER_FRONTIER_REVIEW, 'high_ambiguity', [], 'require_operator_budget_approval_for_frontier');
        }

        if ($blastRadius === 'high') {
            return $this->result(self::TIER_FRONTIER_REVIEW, 'high_blast_radius', [], 'require_operator_budget_approval_for_frontier');
        }

        if ($giveBackCount >= self::GIVE_BACK_ESCALATION_THRESHOLD) {
            return $this->result(
                self::TIER_FRONTIER_REVIEW,
                'repeated_give_back:'.$giveBackCount,
                [],
                'require_operator_budget_approval_for_frontier',
            );
        }

        if ($riskLevel === 'low' && $historicalSuccessRate >= self::HIGH_SUCCESS_THRESHOLD) {
            return $this->result(
                self::TIER_SMALL_WITH_SCAFFOLD,
                'low_risk_high_historical_success:'.$historicalSuccessRate,
                ['concrete_examples', 'explicit_acceptance_breakdown', 'test_skeleton'],
                'cap_retries_before_escalation',
            );
        }

        return $this->result(self::TIER_STANDARD_REVIEW, 'no_strong_signal_either_way', [], 'standard_provider_budget');
    }

    /**
     * @param  list<string>  $scaffoldRequirements
     * @return array<string, mixed>
     */
    private function result(string $tier, string $reason, array $scaffoldRequirements, string $costGuardrail): array
    {
        return [
            'schema' => self::SCHEMA,
            'selected_tier' => $tier,
            'scaffold_requirements' => $scaffoldRequirements,
            'escalation_reason' => $reason,
            'cost_guardrail' => $costGuardrail,
        ];
    }
}
