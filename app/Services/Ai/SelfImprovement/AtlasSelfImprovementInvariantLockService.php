<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Invariant Lock.
 *
 * Protects the canonical sacred rules of the Atlas. ANY violation is a hard
 * fail and the proposal cannot be promoted. The lock evaluates each invariant
 * by inspecting the after-snapshot + the implementation diff descriptor and
 * returns a deterministic verdict.
 *
 * Sacred invariants (per governance ladder):
 *   1. Obra is never created silently.
 *   2. `static_policy` never executes runtime.
 *   3. Real provider never invoked without approval + budget gate.
 *   4. Fallback is never silent.
 *   5. Completion never closes without evidence/review when required.
 *   6. External Rivals never replaced by synthetic score.
 *   7. Canonical docs govern structural change.
 *   8. Rollback + checkpoint mandatory for critical change.
 *
 * Schema: atlas.self_improvement.invariant_lock.v1
 */
class AtlasSelfImprovementInvariantLockService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.invariant_lock.v1';

    public const STATUS_PASSED = 'passed';
    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> Canonical invariant identifiers. */
    public const INVARIANTS = [
        'obra_never_silent',
        'static_policy_never_dispatches',
        'provider_never_called_without_approval',
        'fallback_never_silent',
        'completion_requires_evidence_and_review',
        'rivals_external_never_replaced_by_synthetic',
        'canonical_docs_govern_structural_change',
        'rollback_checkpoint_required_for_critical',
    ];

    /**
     * Evaluate the invariant lock against the post-implementation snapshot.
     *
     * @param  array<string,mixed>  $afterSnapshot      Audit blocks, command outputs, etc.
     * @param  array<string,mixed>  $implementationDiff Description of files / behaviour changed.
     * @param  array<string,mixed>  $proposal           Original proposal packet (for risk_classification).
     * @return array<string,mixed>
     */
    public function evaluate(
        array $afterSnapshot,
        array $implementationDiff = [],
        array $proposal = [],
    ): array {
        $violations = [];
        $invariantStatuses = [];

        foreach (self::INVARIANTS as $invariant) {
            $result = $this->evaluateInvariant($invariant, $afterSnapshot, $implementationDiff, $proposal);
            $invariantStatuses[$invariant] = $result['passed'];
            if (! $result['passed']) {
                $violations[] = [
                    'invariant' => $invariant,
                    'reason' => $result['reason'],
                    'severity' => $result['severity'] ?? 'hard_fail',
                ];
            }
        }

        $passed = $violations === [];
        $status = $passed ? self::STATUS_PASSED : self::STATUS_BLOCKED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'lock_id' => 'lock_'.(string) Str::ulid(),
            'evaluated_at' => Carbon::now()->toIso8601String(),
            'status' => $status,
            'proposal_id' => $proposal['proposal_id'] ?? null,
            'invariants' => $invariantStatuses,
            'invariants_count' => count(self::INVARIANTS),
            'violations' => $violations,
            'violations_count' => count($violations),
            'passed' => $passed,
            'next_action' => $passed
                ? 'invariant_lock_clear_run_regression_sentinel'
                : 'rollback_or_revise_until_invariants_pass',
            'sacred_rules_protected' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @param  array<string,mixed>  $implementationDiff
     * @param  array<string,mixed>  $proposal
     * @return array{passed: bool, reason: ?string, severity?: string}
     */
    private function evaluateInvariant(
        string $invariant,
        array $afterSnapshot,
        array $implementationDiff,
        array $proposal,
    ): array {
        return match ($invariant) {
            'obra_never_silent' => $this->checkObraNeverSilent($afterSnapshot, $implementationDiff),
            'static_policy_never_dispatches' => $this->checkStaticPolicyNeverDispatches($afterSnapshot),
            'provider_never_called_without_approval' => $this->checkProviderNeverCalledWithoutApproval($afterSnapshot, $implementationDiff),
            'fallback_never_silent' => $this->checkFallbackNeverSilent($afterSnapshot),
            'completion_requires_evidence_and_review' => $this->checkCompletionRequiresEvidenceReview($afterSnapshot),
            'rivals_external_never_replaced_by_synthetic' => $this->checkRivalsNeverSynthetic($afterSnapshot),
            'canonical_docs_govern_structural_change' => $this->checkCanonicalDocsGovern($proposal, $implementationDiff),
            'rollback_checkpoint_required_for_critical' => $this->checkRollbackForCritical($proposal),
            default => ['passed' => true, 'reason' => null],
        };
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @param  array<string,mixed>  $implementationDiff
     * @return array{passed: bool, reason: ?string}
     */
    private function checkObraNeverSilent(array $afterSnapshot, array $implementationDiff): array
    {
        $auditFlag = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_forge_continuum_certification.invariants.no_silent_obra_creation',
            true,
        );
        if (! $auditFlag) {
            return ['passed' => false, 'reason' => 'continuum_audit_lost_no_silent_obra_creation'];
        }
        $diffFlag = (bool) ($implementationDiff['allows_silent_obra_creation'] ?? false);
        if ($diffFlag) {
            return ['passed' => false, 'reason' => 'implementation_diff_allows_silent_obra_creation'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkStaticPolicyNeverDispatches(array $afterSnapshot): array
    {
        $flag = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_forge_provider_capacity_certification.invariants.static_policy_does_not_dispatch',
            true,
        );

        return $flag
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'static_policy_dispatches_runtime_after_change'];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @param  array<string,mixed>  $implementationDiff
     * @return array{passed: bool, reason: ?string}
     */
    private function checkProviderNeverCalledWithoutApproval(array $afterSnapshot, array $implementationDiff): array
    {
        if ((bool) ($implementationDiff['adds_unapproved_provider_call'] ?? false)) {
            return ['passed' => false, 'reason' => 'implementation_diff_adds_unapproved_provider_call'];
        }
        $externalCall = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_forge_continuum_certification.external_provider_call',
            false,
        );

        return $externalCall
            ? ['passed' => false, 'reason' => 'continuum_audit_reports_external_provider_call_true']
            : ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkFallbackNeverSilent(array $afterSnapshot): array
    {
        $flag = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_forge_continuum_certification.no_silent_fallback',
            true,
        );

        return $flag
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'continuum_audit_lost_no_silent_fallback_invariant'];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkCompletionRequiresEvidenceReview(array $afterSnapshot): array
    {
        $flag = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_code_forge_review_completion_certification.lifecycle_invariants.no_auto_completion_without_human_review',
            true,
        );

        return $flag
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'review_completion_gate_lost_no_auto_completion_invariant'];
    }

    /**
     * @param  array<string,mixed>  $afterSnapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkRivalsNeverSynthetic(array $afterSnapshot): array
    {
        $separated = (bool) data_get(
            $afterSnapshot,
            'completion_audit.atlas_forge_continuum_certification.separated_from_external_rivals',
            true,
        );
        $synthetic = (bool) data_get(
            $afterSnapshot,
            'completion_audit.rules.synthetic_scores_allowed',
            false,
        );
        if (! $separated || $synthetic) {
            return ['passed' => false, 'reason' => 'rivals_external_separation_or_synthetic_score_invariant_lost'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $implementationDiff
     * @return array{passed: bool, reason: ?string}
     */
    private function checkCanonicalDocsGovern(array $proposal, array $implementationDiff): array
    {
        $docs = $proposal['canonical_docs'] ?? [];
        if (! is_array($docs) || $docs === []) {
            return ['passed' => false, 'reason' => 'proposal_lacks_canonical_docs'];
        }
        if ((bool) ($implementationDiff['ignores_canonical_docs'] ?? false)) {
            return ['passed' => false, 'reason' => 'implementation_diff_ignores_canonical_docs'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{passed: bool, reason: ?string}
     */
    private function checkRollbackForCritical(array $proposal): array
    {
        $riskLevel = (string) data_get($proposal, 'risk_classification.risk_level', 'medium');
        if (! in_array($riskLevel, ['high', 'critical'], true)) {
            return ['passed' => true, 'reason' => null];
        }
        $rollbackRequired = (bool) data_get($proposal, 'risk_classification.rollback_required', false);
        $rollbackKind = (string) data_get($proposal, 'rollback_strategy.rollback_kind', '');
        if (! $rollbackRequired || $rollbackKind === '') {
            return ['passed' => false, 'reason' => 'critical_or_high_risk_lacks_rollback_strategy'];
        }

        return ['passed' => true, 'reason' => null];
    }
}
