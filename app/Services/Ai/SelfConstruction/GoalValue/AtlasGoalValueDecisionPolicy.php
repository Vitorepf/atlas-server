<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * Pure policy that combines:
 *   - the real-leverage contract verdict ({real_leverage:bool, proxy_only:bool, blockers:list<string>})
 *   - the anti-proxy gate verdict ({blocked:bool, blocked_proxy_categories:list<string>})
 *   - the outcome verification verdict ({passed:bool, color:'green'|'red'|'amber', evidence:list<string>})
 * into a single named decision: promote | revise | reject | learn.
 *
 * Decision rules (deterministic, FACTS-only):
 *   - reject  : anti-proxy gate blocked (proxy-only signals without real lever) OR contract proxy_only.
 *   - reject  : verification is red.
 *   - learn   : verification is amber (insufficient evidence to promote, worth recording).
 *   - revise  : real_leverage=false but evidence is salvageable (contract has blockers, gate not blocked).
 *   - promote : real_leverage=true AND gate not blocked AND verification.color=green.
 *
 * Output: {schema_version, decision, reasons, next_required_evidence}
 * NO live system calls (no provider, no DB, no shell).
 */
final class AtlasGoalValueDecisionPolicy
{
    public const SCHEMA = 'atlas.goal_value.decision_policy.v1';

    public const DECISION_PROMOTE = 'promote';

    public const DECISION_REVISE = 'revise';

    public const DECISION_REJECT = 'reject';

    public const DECISION_LEARN = 'learn';

    public const DECISION_DEFER = 'defer';

    private const NEXT_ACTION_MAP = [
        self::DECISION_PROMOTE => 'proceed_with_execution',
        self::DECISION_REVISE => 'attach_missing_evidence_and_resubmit',
        self::DECISION_REJECT => 'fix_blockers_then_resubmit',
        self::DECISION_LEARN => 'record_learning_and_wait_for_corroboration',
        self::DECISION_DEFER => 'demonstrate_real_outcome_potential_then_resubmit',
    ];

    /**
     * @param  array<string,mixed>  $leverageVerdict
     * @param  array<string,mixed>  $antiProxyVerdict
     * @param  array<string,mixed>  $verification
     * @param  array<string,mixed>  $finality  optional; operator/human/provider approval keys are FORBIDDEN
     * @param  array<string,mixed>  $outcomePotential  optional; {sufficient?:bool, blockers?:list<string>} —
     *   empty array skips the check (backward compatible); sufficient=false defers regardless of
     *   otherwise-clean leverage/gate/verification, since real outcome potential is a distinct gate.
     * @return array<string,mixed>
     */
    public function decide(
        array $leverageVerdict,
        array $antiProxyVerdict,
        array $verification,
        array $finality = [],
        array $outcomePotential = [],
    ): array {
        $reasons = [];
        $nextEvidence = [];

        $realLeverage = (bool) ($leverageVerdict['real_leverage'] ?? false);
        $proxyOnly = (bool) ($leverageVerdict['proxy_only'] ?? false);
        $leverageBlockers = (array) ($leverageVerdict['blockers'] ?? []);
        $gateBlocked = (bool) ($antiProxyVerdict['blocked'] ?? false);
        $blockedCats = (array) ($antiProxyVerdict['blocked_proxy_categories'] ?? []);
        $color = strtolower((string) ($verification['color'] ?? 'unknown'));
        $verificationPassed = (bool) ($verification['passed'] ?? false);

        // REJECT — proxy-only gate failure OR contract says proxy_only.
        if ($gateBlocked) {
            $reasons[] = 'anti_proxy_gate_blocked';
            foreach ($blockedCats as $c) {
                $reasons[] = 'proxy_category:'.(string) $c;
            }
            $nextEvidence[] = 'concrete_capability_or_failure_removal_evidence';

            return $this->envelope(self::DECISION_REJECT, $reasons, $nextEvidence);
        }
        if ($proxyOnly) {
            $reasons[] = 'leverage_contract_proxy_only';
            $nextEvidence[] = 'replace_proxy_evidence_with_real_levers';

            return $this->envelope(self::DECISION_REJECT, $reasons, $nextEvidence);
        }

        // REJECT — forbidden finality: operator/human/provider approval never substitutes for evidence.
        foreach (['operator_approved', 'human_approved', 'provider_approved', 'claude_code_approved', 'codex_approved', 'cursor_approved'] as $forbidden) {
            if (! empty($finality[$forbidden])) {
                $reasons[] = 'finality_forbidden:'.$forbidden;
            }
        }
        if ($reasons !== []) {
            $nextEvidence[] = 'replace_with_server_side_verification_evidence';

            return $this->envelope(self::DECISION_REJECT, $reasons, $nextEvidence);
        }

        // REJECT — verification red.
        if ($color === 'red') {
            $reasons[] = 'verification_red';
            foreach ((array) ($verification['evidence'] ?? []) as $e) {
                $reasons[] = 'verification:'.(string) $e;
            }
            $nextEvidence[] = 'fix_failing_verification_then_resubmit';

            return $this->envelope(self::DECISION_REJECT, $reasons, $nextEvidence);
        }

        // LEARN — verification amber: insufficient evidence to promote, but worth recording.
        if ($color === 'amber') {
            $reasons[] = 'verification_amber_insufficient_evidence';
            $nextEvidence[] = 'record_learning_candidate_for_future_corroboration';

            return $this->envelope(self::DECISION_LEARN, $reasons, $nextEvidence);
        }

        // REVISE — real leverage missing but salvageable (contract has blockers, gate clean, color !=red).
        if (! $realLeverage) {
            $reasons[] = 'real_leverage_not_yet_established';
            foreach ($leverageBlockers as $b) {
                $reasons[] = 'leverage:'.(string) $b;
            }
            $nextEvidence[] = 'add_missing_dimension_evidence';

            return $this->envelope(self::DECISION_REVISE, $reasons, $nextEvidence);
        }

        // DEFER — real leverage established but real outcome potential is insufficient. Distinct
        // from reject/revise: nothing is wrong with the evidence, the decision just isn't worth
        // admitting yet. Skipped entirely when the caller supplies no outcome-potential verdict.
        if ($outcomePotential !== [] && (bool) ($outcomePotential['sufficient'] ?? false) === false) {
            $reasons[] = 'low_outcome_potential';
            foreach ((array) ($outcomePotential['blockers'] ?? []) as $b) {
                $reasons[] = 'outcome_potential:'.(string) $b;
            }
            $nextEvidence[] = 'demonstrate_real_outcome_potential_before_resubmission';

            return $this->envelope(self::DECISION_DEFER, $reasons, $nextEvidence);
        }

        // PROMOTE — real_leverage true + gate clean + verification green/passed.
        if ($verificationPassed && $color === 'green') {
            // Require explicit implementation evidence refs.
            $implRefs = array_values(array_filter(array_map('strval', (array) ($leverageVerdict['implementation_evidence_refs'] ?? []))));
            if ($implRefs === []) {
                return $this->envelope(self::DECISION_REVISE, ['implementation_evidence_refs_empty'], ['attach_implementation_evidence_refs']);
            }

            // Require downstream consumer evidence refs.
            $downstreamRefs = array_values(array_filter(array_map('strval', (array) ($leverageVerdict['downstream_consumer_evidence_refs'] ?? []))));
            if ($downstreamRefs === []) {
                return $this->envelope(self::DECISION_REVISE, ['downstream_consumer_evidence_refs_empty'], ['attach_downstream_consumer_evidence_refs']);
            }

            // Require at least one compounding or autonomy-unlock evidence ref.
            $compoundingRefs = array_values(array_filter(array_map('strval', (array) ($leverageVerdict['compounding_evidence_refs'] ?? []))));
            $autonomyRefs = array_values(array_filter(array_map('strval', (array) ($leverageVerdict['autonomy_unlock_evidence_refs'] ?? []))));
            if ($compoundingRefs === [] && $autonomyRefs === []) {
                return $this->envelope(self::DECISION_REVISE, ['compounding_or_autonomy_unlock_evidence_required'], ['attach_compounding_or_autonomy_unlock_evidence_refs']);
            }

            return $this->envelope(self::DECISION_PROMOTE, ['promotion_criteria_met'], []);
        }

        // Otherwise: revise (verification status unknown).
        $reasons[] = 'verification_status_unknown';
        $nextEvidence[] = 'attach_verification_color_and_passed_flag';

        return $this->envelope(self::DECISION_REVISE, $reasons, $nextEvidence);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $nextEvidence
     * @return array<string,mixed>
     */
    private function envelope(string $decision, array $reasons, array $nextEvidence): array
    {
        $reasons = array_values($reasons);
        $nextEvidence = array_values(array_unique($nextEvidence));

        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
            'blocking_reasons' => $reasons,
            'next_required_evidence' => $nextEvidence,
            'required_evidence' => $nextEvidence,
            'next_action' => self::NEXT_ACTION_MAP[$decision] ?? 'review_manually',
        ];
    }
}
