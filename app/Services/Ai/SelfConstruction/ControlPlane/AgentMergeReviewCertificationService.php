<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Carbon\CarbonImmutable;
use App\Support\YesNo;

/**
 * Aggregates merge review packet, scope verification, risk score,
 * approval plan, promotion dry-run and rollback verification under
 * hard-law invariants. Pure projection — promotion_allowed and
 * completion_claim_allowed are *always* false. Never applies a patch,
 * never modifies real files, never persists state.
 */
final class AgentMergeReviewCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_certification.v1';

    public const MODE = 'read_only_agent_merge_review_certification';

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_certification_does_not_apply_patch',
        'agent_merge_review_certification_does_not_modify_real_files',
        'agent_merge_review_certification_does_not_grant_approval',
        'agent_merge_review_certification_does_not_persist_state',
        'agent_merge_review_certification_does_not_advance_completion_claim',
        'agent_merge_review_certification_does_not_dispatch_agent',
        'agent_merge_review_certification_does_not_write_ledger',
        'agent_merge_review_certification_does_not_enable_self_programming',
    ];

    public function __construct(
        private readonly AgentMergeReviewPacketBuilder $packetBuilder = new AgentMergeReviewPacketBuilder,
        private readonly AgentMergeReviewScopeVerifier $scopeVerifier = new AgentMergeReviewScopeVerifier,
        private readonly AgentMergeReviewRiskScorer $riskScorer = new AgentMergeReviewRiskScorer,
        private readonly AgentMergeReviewHumanApprovalPlanner $approvalPlanner = new AgentMergeReviewHumanApprovalPlanner,
        private readonly AgentMergeReviewPromotionDryRun $promotionDryRun = new AgentMergeReviewPromotionDryRun,
        private readonly AgentMergeReviewRollbackVerifier $rollbackVerifier = new AgentMergeReviewRollbackVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $diffManifest
     * @param  array<string, mixed>  $artifactManifest
     * @param  array<string, mixed>  $declaredScope
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $diffManifest, array $artifactManifest = [], array $declaredScope = [], array $context = [], array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $packet = $this->packetBuilder->build($diffManifest, $artifactManifest, $context);
        $scope = $this->scopeVerifier->verify($packet, $declaredScope);
        $risk = $this->riskScorer->score($packet, $scope);
        $approval = $this->approvalPlanner->plan($packet, $scope, $risk, $options);
        $dryRun = $this->promotionDryRun->plan($packet, $scope, $risk, $approval);
        $rollback = $this->rollbackVerifier->verify($packet, $dryRun);

        $invariants = $this->buildInvariants($packet, $scope, $risk, $approval, $dryRun, $rollback);

        $violationCount = 0;
        foreach ($invariants as $invariant) {
            if ($invariant['ok'] === false) {
                $violationCount++;
            }
        }

        $runtimeSafety = [
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'promotion_allowed' => false,
            'rollback_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $runtimeSafety['runtime_safety_all_false'] = ! in_array(true, $runtimeSafety, true);

        $invariantsAllTrue = $violationCount === 0;
        $status = $invariantsAllTrue && $runtimeSafety['runtime_safety_all_false'] === true
            ? 'available'
            : 'agent_merge_review_certification_violations_present';

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_claim_allowed' => false,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'rollback_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'invariants_all_true' => $invariantsAllTrue,
            'violation_count' => $violationCount,
            'generated_at' => $now,
            'runtime_safety' => $runtimeSafety,
            'invariants' => $invariants,
            'human_summary' => $this->humanSummary($status, $risk, $approval, $rollback),
            'next_action' => $this->nextAction($invariantsAllTrue, $risk, $approval, $rollback),
            'inputs' => [
                'packet' => $packet,
                'scope_verification' => $scope,
                'risk_score' => $risk,
                'approval_plan' => $approval,
                'promotion_dry_run' => $dryRun,
                'rollback_verification' => $rollback,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['certification_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    private const DEFAULT_MAX_EVIDENCE_AGE_SECONDS = 3600;

    /**
     * Certifies a task success CLAIM (not the merge review packet) by
     * requiring scope cleanliness, runnable proof, rollback readiness and
     * fresh evidence — never trusting a "green" report alone. A
     * self-declared success (no runtime confirmation behind it) or a stale
     * evidence timestamp is always rejected, no matter what the claim says.
     *
     * Pure: no I/O, no patch application, no file writes.
     *
     * @param  array<string, mixed>  $claim  { scope_clean?: bool,
     *   runnable_proof_present?: bool, rollback_ready?: bool,
     *   evidence_age_seconds?: int|null, self_declared?: bool,
     *   max_evidence_age_seconds?: int }
     * @return array{certified: bool, blocked_reason: string|null, missing_evidence: list<string>}
     */
    public function certifySuccessClaim(array $claim): array
    {
        $scopeClean = (bool) ($claim['scope_clean'] ?? false);
        $runnableProofPresent = (bool) ($claim['runnable_proof_present'] ?? false);
        $rollbackReady = (bool) ($claim['rollback_ready'] ?? false);
        $evidenceAgeSeconds = array_key_exists('evidence_age_seconds', $claim) && $claim['evidence_age_seconds'] !== null
            ? (int) $claim['evidence_age_seconds']
            : null;
        $selfDeclared = (bool) ($claim['self_declared'] ?? false);
        $maxEvidenceAgeSeconds = max(1, (int) ($claim['max_evidence_age_seconds'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_SECONDS));

        $missingEvidence = [];
        if (! $scopeClean) {
            $missingEvidence[] = 'scope_not_clean';
        }
        if (! $runnableProofPresent) {
            $missingEvidence[] = 'missing_runnable_proof';
        }
        if (! $rollbackReady) {
            $missingEvidence[] = 'rollback_not_ready';
        }
        if ($evidenceAgeSeconds === null) {
            $missingEvidence[] = 'missing_evidence_freshness_timestamp';
        } elseif ($evidenceAgeSeconds > $maxEvidenceAgeSeconds) {
            $missingEvidence[] = 'stale_evidence';
        }
        if ($selfDeclared) {
            $missingEvidence[] = 'self_declared_success_without_runtime_confirmation';
        }

        $certified = $missingEvidence === [];

        $blockedReason = match (true) {
            $certified => null,
            $selfDeclared => 'self_declared_success_without_runtime_confirmation',
            ! $scopeClean => 'scope_not_clean',
            ! $runnableProofPresent => 'missing_runnable_proof',
            ! $rollbackReady => 'rollback_not_ready',
            $evidenceAgeSeconds === null => 'missing_evidence_freshness_timestamp',
            default => 'stale_evidence',
        };

        return [
            'certified' => $certified,
            'blocked_reason' => $blockedReason,
            'missing_evidence' => $missingEvidence,
        ];
    }

    /**
     * @return list<array{name: string, ok: bool, observation: string}>
     */
    private function buildInvariants(array $packet, array $scope, array $risk, array $approval, array $dryRun, array $rollback): array
    {
        $invariants = [];

        $invariants[] = $this->inv('packet_envelope_marks_no_patch_apply', (bool) ($packet['apply_patch_allowed'] ?? true) === false, 'packet envelope must declare apply_patch_allowed=false');
        $invariants[] = $this->inv('packet_envelope_marks_no_real_file_write', (bool) ($packet['real_file_write_allowed'] ?? true) === false, 'packet envelope must declare real_file_write_allowed=false');
        $invariants[] = $this->inv('packet_envelope_marks_no_completion_claim', (bool) ($packet['completion_claim_allowed'] ?? true) === false, 'packet envelope must declare completion_claim_allowed=false');
        $invariants[] = $this->inv('scope_envelope_marks_no_patch_apply', (bool) ($scope['apply_patch_allowed'] ?? true) === false, 'scope verification envelope must declare apply_patch_allowed=false');
        $invariants[] = $this->inv('scope_envelope_marks_no_real_file_write', (bool) ($scope['real_file_write_allowed'] ?? true) === false, 'scope verification envelope must declare real_file_write_allowed=false');
        $invariants[] = $this->inv('risk_envelope_marks_no_patch_apply', (bool) ($risk['apply_patch_allowed'] ?? true) === false, 'risk score envelope must declare apply_patch_allowed=false');
        $invariants[] = $this->inv('approval_envelope_marks_no_grant', (bool) ($approval['approval_granted'] ?? true) === false, 'approval plan must declare approval_granted=false');
        $invariants[] = $this->inv('approval_envelope_marks_no_persist', (bool) ($approval['approval_persisted'] ?? true) === false, 'approval plan must declare approval_persisted=false');
        $invariants[] = $this->inv('dry_run_envelope_marks_no_promotion', (bool) ($dryRun['promotion_allowed'] ?? true) === false, 'promotion dry-run must declare promotion_allowed=false');
        $invariants[] = $this->inv('dry_run_envelope_marks_no_real_file_write', (bool) ($dryRun['real_file_write_allowed'] ?? true) === false, 'promotion dry-run must declare real_file_write_allowed=false');
        $invariants[] = $this->inv('dry_run_envelope_marks_no_dispatch', (bool) ($dryRun['dispatch_allowed'] ?? true) === false, 'promotion dry-run must declare dispatch_allowed=false');
        $invariants[] = $this->inv('rollback_envelope_marks_no_execution', (bool) ($rollback['rollback_execution_allowed'] ?? true) === false, 'rollback verification must declare rollback_execution_allowed=false');
        $invariants[] = $this->inv('rollback_envelope_marks_no_real_file_write', (bool) ($rollback['real_file_write_allowed'] ?? true) === false, 'rollback verification must declare real_file_write_allowed=false');

        $invariants[] = $this->inv('packet_has_hash', isset($packet['packet_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $packet['packet_hash']) === 1, 'packet must carry a stable sha256 packet_hash');
        $invariants[] = $this->inv('scope_has_hash', isset($scope['verification_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $scope['verification_hash']) === 1, 'scope verification must carry a stable sha256 verification_hash');
        $invariants[] = $this->inv('risk_has_hash', isset($risk['risk_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $risk['risk_hash']) === 1, 'risk score must carry a stable sha256 risk_hash');
        $invariants[] = $this->inv('approval_has_hash', isset($approval['plan_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $approval['plan_hash']) === 1, 'approval plan must carry a stable sha256 plan_hash');
        $invariants[] = $this->inv('dry_run_has_hash', isset($dryRun['dry_run_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $dryRun['dry_run_hash']) === 1, 'promotion dry-run must carry a stable sha256 dry_run_hash');
        $invariants[] = $this->inv('rollback_has_hash', isset($rollback['verification_hash']) && preg_match('/^[a-f0-9]{64}$/', (string) $rollback['verification_hash']) === 1, 'rollback verification must carry a stable sha256 verification_hash');

        $invariants[] = $this->inv('runtime_safety:no_patch_apply', true, 'projection-only services must keep patch apply locked off');
        $invariants[] = $this->inv('runtime_safety:no_real_file_write', true, 'projection-only services must keep real file writes locked off');
        $invariants[] = $this->inv('runtime_safety:no_completion_claim', true, 'projection-only services must keep completion claim locked off');
        $invariants[] = $this->inv('runtime_safety:no_promotion_execution', true, 'projection-only services must keep promotion execution locked off');
        $invariants[] = $this->inv('runtime_safety:no_dispatch_real', true, 'projection-only services must keep dispatch locked off');
        $invariants[] = $this->inv('runtime_safety:no_token_spend', true, 'projection-only services must keep provider/token spend locked off');
        $invariants[] = $this->inv('runtime_safety:no_self_programming', true, 'projection-only services must keep self-programming locked off');
        $invariants[] = $this->inv('runtime_safety:no_ledger_write', true, 'projection-only services must keep ledger write locked off');

        return $invariants;
    }

    /**
     * @return array{name: string, ok: bool, observation: string}
     */
    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function humanSummary(string $status, array $risk, array $approval, array $rollback): string
    {
        if ($status !== 'available') {
            return 'Agent Merge Review certification is not aligned — see violations[].';
        }
        $band = (string) (data_get($risk, 'risk.overall_band') ?? 'low');
        $approvalEligible = (bool) (data_get($approval, 'plan.approval_eligible') ?? false);
        $allReversible = (bool) (data_get($rollback, 'verification.all_steps_reversible') ?? false);

        return sprintf(
            'Agent Merge Review certification available (read-only). Risk band: %s. Approval eligible: %s. Rollback fully reversible: %s. promotion_allowed=false, completion_claim_allowed=false.',
            $band,
            YesNo::format($approvalEligible),
            YesNo::format($allReversible),
        );
    }

    private function nextAction(bool $invariantsAllTrue, array $risk, array $approval, array $rollback): string
    {
        if (! $invariantsAllTrue) {
            return 'restore_runtime_safety_invariants_before_any_review_action';
        }
        if ((bool) (data_get($approval, 'plan.approval_eligible') ?? false) === false) {
            return 'clear_blocking_conditions_then_seek_human_approval';
        }
        if ((bool) (data_get($rollback, 'verification.all_steps_reversible') ?? false) === false) {
            return 'improve_rollback_step_coverage_before_promotion_planning';
        }
        if ((string) (data_get($risk, 'risk.overall_band') ?? 'low') === 'critical') {
            return 'keep_promotion_blocked_until_critical_risk_resolved';
        }

        return 'keep_merge_review_layer_read_only_until_runtime_pilot_promotes';
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['certification_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
