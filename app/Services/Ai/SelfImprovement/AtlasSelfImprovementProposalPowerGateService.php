<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Proposal Power Gate.
 *
 * Decides whether a Proposal Packet is strong enough to enter Forge. Applies
 * the canonical hard fails from
 * `docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md`
 * and produces an outcome of `approved`, `needs_revision`, `rejected` or
 * `human_review_required`.
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER spends a token;
 *   - NEVER promotes a Forge run by itself — even `approved` only unlocks the
 *     next step (Before Snapshot);
 *   - NEVER turns a `human_review_required` outcome into `approved`.
 *
 * Schema: atlas.self_improvement.proposal_power_gate.v1
 */
class AtlasSelfImprovementProposalPowerGateService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.proposal_power_gate.v1';

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_NEEDS_REVISION = 'needs_revision';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_HUMAN_REVIEW_REQUIRED = 'human_review_required';

    /** @var list<string> Canonical hard fails — any of these blocks promotion. */
    public const HARD_FAILS = [
        'missing_business_rule',
        'missing_canonical_docs',
        'missing_before_snapshot_plan',
        'missing_success_metrics',
        'missing_forbidden_paths',
        'missing_rollback_strategy',
        'missing_test_strategy',
        'mixes_hypothesis_with_claim',
        'attempts_critical_autopromotion',
        'ignores_rivals_or_before_after',
    ];

    /**
     * Evaluate a Proposal Packet against the canonical Power Gate rules.
     *
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    public function evaluate(array $proposal): array
    {
        $hardFails = $this->collectHardFails($proposal);
        $softFindings = $this->collectSoftFindings($proposal);
        $criticalRisk = (string) data_get($proposal, 'risk_classification.risk_level') === 'critical';
        $autopromotionRequested = (bool) ($proposal['autopromotion_requested'] ?? false);
        $autopromotionAllowed = (bool) ($proposal['autopromotion_allowed'] ?? false);

        $outcome = $this->resolveOutcome(
            $hardFails,
            $softFindings,
            $criticalRisk,
            $autopromotionRequested,
            $autopromotionAllowed,
            (bool) ($proposal['human_review_required'] ?? true),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gate_id' => 'gate_'.(string) Str::ulid(),
            'outcome' => $outcome,
            'evaluated_at' => Carbon::now()->toIso8601String(),
            'proposal_id' => $proposal['proposal_id'] ?? null,
            'proposal_status' => $proposal['status'] ?? null,
            'hard_fails' => $hardFails,
            'soft_findings' => $softFindings,
            'requires_human_review' => $outcome === self::OUTCOME_HUMAN_REVIEW_REQUIRED
                || $criticalRisk
                || (bool) ($proposal['human_review_required'] ?? true),
            'autopromotion_requested' => $autopromotionRequested,
            'autopromotion_allowed' => $autopromotionAllowed && $outcome === self::OUTCOME_APPROVED,
            'criticality_lock_active' => $criticalRisk,
            'next_action' => $this->resolveNextAction($outcome, $hardFails, $softFindings),
            'invariants' => [
                'never_promotes_forge_directly' => true,
                'never_calls_external_provider' => true,
                'never_bypasses_review_completion_gate' => true,
                'never_unlocks_external_rivals_claim' => true,
                'critical_risk_requires_human_review' => $criticalRisk,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function collectHardFails(array $proposal): array
    {
        $fails = [];

        if (! $this->stringPresent(data_get($proposal, 'business_rule'))) {
            $fails[] = 'missing_business_rule';
        }
        if ($this->emptyList(data_get($proposal, 'canonical_docs'))) {
            $fails[] = 'missing_canonical_docs';
        }
        if (! is_array(data_get($proposal, 'before_snapshot_plan')) || empty(data_get($proposal, 'before_snapshot_plan'))) {
            $fails[] = 'missing_before_snapshot_plan';
        }
        if ($this->emptyList(data_get($proposal, 'success_metrics'))) {
            $fails[] = 'missing_success_metrics';
        }
        if ($this->emptyList(data_get($proposal, 'forbidden_paths'))) {
            $fails[] = 'missing_forbidden_paths';
        }
        if (! is_array(data_get($proposal, 'rollback_strategy')) || empty(data_get($proposal, 'rollback_strategy'))) {
            $fails[] = 'missing_rollback_strategy';
        }
        if (! is_array(data_get($proposal, 'test_strategy')) || empty(data_get($proposal, 'test_strategy'))) {
            $fails[] = 'missing_test_strategy';
        }

        // Hypothesis vs claim: expected_power_gain present but rivals plan
        // explicitly opts out → hypothesis pretending to be claim.
        $expectedGain = data_get($proposal, 'expected_power_gain');
        $rivalsPlan = data_get($proposal, 'rivals_evaluation_plan');
        if ($this->stringPresent($expectedGain)
            && is_array($rivalsPlan)
            && ($rivalsPlan['mode'] ?? null) === 'no_evaluation'
        ) {
            $fails[] = 'mixes_hypothesis_with_claim';
        }

        // Attempting critical autopromotion is a hard fail no matter what.
        $criticalRisk = (string) data_get($proposal, 'risk_classification.risk_level') === 'critical';
        $autopromotionRequested = (bool) ($proposal['autopromotion_requested'] ?? false);
        if ($criticalRisk && $autopromotionRequested) {
            $fails[] = 'attempts_critical_autopromotion';
        }

        // Declares improvement but rivals plan says it never promotes external claim
        // AND declares no before/after capture → trying to declare power gain
        // without measurement.
        $beforeSnapshotPlan = data_get($proposal, 'before_snapshot_plan', []);
        $beforeAfterComparable = (bool) ($beforeSnapshotPlan['before_after_comparable'] ?? false);
        if ($this->stringPresent($expectedGain) && ! $beforeAfterComparable) {
            $fails[] = 'ignores_rivals_or_before_after';
        }

        return array_values(array_unique($fails));
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function collectSoftFindings(array $proposal): array
    {
        $findings = [];

        if ($this->emptyList(data_get($proposal, 'acceptance_gates'))) {
            $findings[] = 'no_acceptance_gates_declared';
        }
        if (! data_get($proposal, 'risk_classification.rollback_required')) {
            $riskLevel = (string) data_get($proposal, 'risk_classification.risk_level', 'medium');
            if (in_array($riskLevel, ['high', 'critical'], true)) {
                $findings[] = 'risk_level_high_but_rollback_not_required';
            }
        }
        if (! (bool) data_get($proposal, 'rivals_evaluation_plan.separated_from_external_rivals_certification', false)) {
            $findings[] = 'rivals_plan_does_not_declare_separation_from_external_rivals_certification';
        }
        if (! (bool) data_get($proposal, 'test_strategy.docs_health_required', false)) {
            $findings[] = 'test_strategy_skips_docs_health';
        }

        return $findings;
    }

    /**
     * @param  list<string>  $hardFails
     * @param  list<string>  $softFindings
     */
    private function resolveOutcome(
        array $hardFails,
        array $softFindings,
        bool $criticalRisk,
        bool $autopromotionRequested,
        bool $autopromotionAllowed,
        bool $humanReviewRequired,
    ): string {
        if ($hardFails === []) {
            if ($humanReviewRequired || $criticalRisk || $autopromotionRequested && ! $autopromotionAllowed) {
                return self::OUTCOME_HUMAN_REVIEW_REQUIRED;
            }
            if ($softFindings !== []) {
                return self::OUTCOME_NEEDS_REVISION;
            }

            return self::OUTCOME_APPROVED;
        }

        // Critical-autopromotion attempts are categorically rejected so the
        // operator cannot resubmit them as `needs_revision`.
        if (in_array('attempts_critical_autopromotion', $hardFails, true)) {
            return self::OUTCOME_REJECTED;
        }

        return self::OUTCOME_NEEDS_REVISION;
    }

    /**
     * @param  list<string>  $hardFails
     * @param  list<string>  $softFindings
     */
    private function resolveNextAction(string $outcome, array $hardFails, array $softFindings): string
    {
        return match ($outcome) {
            self::OUTCOME_APPROVED => 'capture_before_snapshot_then_dispatch_forge',
            self::OUTCOME_HUMAN_REVIEW_REQUIRED => 'request_human_reviewer_signoff',
            self::OUTCOME_REJECTED => 'rewrite_proposal:'.implode(',', array_slice($hardFails, 0, 3)),
            self::OUTCOME_NEEDS_REVISION => 'fix:'.implode(',', array_slice(array_merge($hardFails, $softFindings), 0, 3)),
            default => 'inspect_outcome',
        };
    }

    private function stringPresent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function emptyList(mixed $value): bool
    {
        return ! is_array($value) || $value === [];
    }
}
