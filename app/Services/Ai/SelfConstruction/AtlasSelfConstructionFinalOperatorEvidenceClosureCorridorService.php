<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Final Operator Evidence Closure Corridor v1.
 *
 * Read-only macro projection that says exactly how to walk the operator out of
 * the current incomplete completion audit (3 real blockers) into a state where
 * a final completion audit can pass with REAL evidence. It does not close
 * blockers by itself, never starts processes, never calls providers and never
 * promotes completion.
 */
final class AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.final_operator_evidence_closure_corridor.v1';

    public const MODE = 'read_only_final_operator_evidence_closure_corridor';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $completionAudit = (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit($options);
        $completionEvidence = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionOsCompletionEvidenceStatus($options));
        $blockerExplainer = (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit);
        $submissionPreflight = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)
            ->build($completionAudit, $completionEvidence, $blockerExplainer);
        $finalEvidenceBundle = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionFinalEvidenceBundleStatus($options));

        $runtimeReceiptReady = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed'
            && (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false);
        $realProviderSmokeReady = (string) data_get($completionEvidence, 'real_provider_smoke.status') === 'passed';
        $humanReceiptReady = (string) data_get($completionEvidence, 'human_signed_completion_receipt.status') === 'passed';
        $completionAuditComplete = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false);

        $runtimeReceiptHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', '');
        $realProviderSmokeHash = (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', '');
        $humanReceiptHash = (string) data_get($completionEvidence, 'human_signed_completion_receipt.receipt_hash', '');
        $completionAuditHash = (string) data_get($completionAudit, 'completion_audit_hash', '');
        $runtimeGapMatrixHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', '');
        $expectedRuntimeGapMatrixHashForPromotionReceipt = (string) data_get($completionEvidence, 'runtime_gap_matrix.expected_runtime_gap_matrix_hash_for_promotion_receipt', '');
        $runtimePromotionBasisHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_basis_hash', '');
        $runtimePromotionClosureBasisHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_closure_basis_hash', '');

        $orderedOperatorPath = $this->orderedOperatorPath(
            blockerExplainer: $blockerExplainer,
            runtimeReceiptReady: $runtimeReceiptReady,
            realProviderSmokeReady: $realProviderSmokeReady,
            humanReceiptReady: $humanReceiptReady,
            completionAuditComplete: $completionAuditComplete,
            runtimeReceiptHash: $runtimeReceiptHash,
            realProviderSmokeHash: $realProviderSmokeHash,
            humanReceiptHash: $humanReceiptHash,
            completionAuditHash: $completionAuditHash,
        );

        $artifactVerificationMatrix = [
            'runtime_promotion_receipt' => $this->artifactRow(
                requiredFields: ['receipt_id', 'signed_by', 'reason', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'promoted_gap_ids', 'graduation_evidence_hashes', 'receipt_hash'],
                requiredHashFields: ['runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'receipt_hash'],
                requiredBooleanAcks: ['runtime_promotion_approved', 'operator_reviewed_runtime_graduations', 'no_runtime_autopromotion_acknowledged'],
                forbiddenFlags: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'],
                verifyingService: AtlasSelfConstructionRuntimePromotionReceiptService::class,
                currentStatus: (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'blocked'),
                currentHash: $runtimeReceiptHash,
                blockerCount: $runtimeReceiptReady ? 0 : (int) max(1, count((array) data_get($completionEvidence, 'runtime_gap_matrix.blocked_gap_ids', [1]))),
                missingCount: $runtimeReceiptReady ? 0 : 1,
            ),
            'real_provider_smoke' => $this->artifactRow(
                requiredFields: ['kind', 'status', 'provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason', 'smoke_hash'],
                requiredHashFields: ['smoke_hash', 'operator_approval_receipt_hash', 'evidence_ledger_hash', 'work_product_manifest_hash', 'cost_event_hash', 'continuation_summary_hash', 'provider_response_hash'],
                requiredBooleanAcks: ['provider_call_observed', 'token_spend_observed', 'claim_to_completion_observed', 'work_product_collected', 'operator_supplied_evidence', 'real_provider_run_observed_by_operator'],
                forbiddenFlags: ['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_promoted_without_receipt'],
                verifyingService: AtlasSelfConstructionRealProviderSmokeCertificationService::class,
                currentStatus: (string) data_get($completionEvidence, 'real_provider_smoke.status', 'blocked'),
                currentHash: $realProviderSmokeHash,
                blockerCount: $realProviderSmokeReady ? 0 : (int) max(1, count((array) data_get($completionEvidence, 'real_provider_smoke.violations', [1]))),
                missingCount: $realProviderSmokeReady ? 0 : 1,
            ),
            'human_completion_receipt' => $this->artifactRow(
                requiredFields: ['receipt_id', 'signed_by', 'reason', 'completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash', 'receipt_hash'],
                requiredHashFields: ['completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash', 'receipt_hash'],
                requiredBooleanAcks: ['os_complete_approved', 'operator_reviewed_completion_audit', 'no_autopromotion_acknowledged'],
                forbiddenFlags: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted'],
                verifyingService: AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                currentStatus: (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', 'blocked'),
                currentHash: $humanReceiptHash,
                blockerCount: $humanReceiptReady ? 0 : (int) max(1, count((array) data_get($completionEvidence, 'human_signed_completion_receipt.violations', [1]))),
                missingCount: $humanReceiptReady ? 0 : 1,
            ),
            'final_completion_audit' => $this->artifactRow(
                requiredFields: ['completion_audit_hash', 'status', 'failed_criteria'],
                requiredHashFields: ['completion_audit_hash'],
                requiredBooleanAcks: ['completion_allowed', 'completion_claim_allowed'],
                forbiddenFlags: [],
                verifyingService: AtlasSelfConstructionOsCompletionAuditService::class,
                currentStatus: (string) data_get($completionAudit, 'status', 'incomplete'),
                currentHash: $completionAuditHash,
                blockerCount: (int) data_get($completionAudit, 'failed_count', 0),
                missingCount: $completionAuditComplete ? 0 : 1,
            ),
        ];

        $artifactContracts = [
            'runtime_promotion_receipt' => [
                'schema_version' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'mode' => AtlasSelfConstructionRuntimePromotionReceiptService::MODE,
                'draft_service' => AtlasSelfConstructionRuntimePromotionReceiptDraftService::class,
                'hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'verifier_service' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
            ],
            'real_provider_smoke' => [
                'schema_version' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'mode' => AtlasSelfConstructionRealProviderSmokeCertificationService::MODE,
                'draft_service' => AtlasSelfConstructionRealProviderSmokeDraftService::class,
                'hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'verifier_service' => AtlasSelfConstructionRealProviderSmokeCertificationService::class,
                'offline_harness_service' => AtlasSelfConstructionRealProviderSmokeOfflineHarnessService::class,
            ],
            'human_completion_receipt' => [
                'schema_version' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::SCHEMA_VERSION,
                'mode' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::MODE,
                'draft_service' => AtlasSelfConstructionHumanCompletionReceiptDraftService::class,
                'hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'verifier_service' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
            ],
            'final_completion_audit' => [
                'schema_version' => AtlasSelfConstructionOsCompletionAuditService::SCHEMA_VERSION,
                'mode' => AtlasSelfConstructionOsCompletionAuditService::MODE,
                'verifier_service' => AtlasSelfConstructionOsCompletionAuditService::class,
            ],
        ];

        $artifactTemplates = [
            'runtime_promotion_receipt_template_hash' => (string) data_get($completionAudit, 'operator_action_packet.template_hashes.runtime_promotion_receipt_template_hash', ''),
            'human_completion_receipt_template_hash' => (string) data_get($completionAudit, 'operator_action_packet.template_hashes.human_completion_receipt_template_hash', ''),
            'real_provider_smoke_template_hash' => (string) data_get($completionAudit, 'operator_action_packet.template_hashes.real_provider_smoke_template_hash', ''),
        ];

        $blockingArtifacts = array_values(array_filter([
            $runtimeReceiptReady ? null : 'runtime_promotion_receipt',
            $realProviderSmokeReady ? null : 'real_provider_smoke',
            $humanReceiptReady ? null : 'human_completion_receipt',
        ]));

        $status = 'blocked_operator_evidence_required';
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            $status = $completionAuditComplete ? 'complete_candidate' : 'ready_for_final_audit';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => $completionAuditComplete,
            'completion_claim_allowed' => $completionAuditComplete,
            'current_completion_audit' => [
                'status' => (string) data_get($completionAudit, 'status', 'incomplete'),
                'completion_audit_hash' => $completionAuditHash,
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
                'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            ],
            'current_completion_evidence_status' => [
                'status' => (string) data_get($completionEvidence, 'status', 'unknown'),
                'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
                'runtime_promotion_receipt_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'blocked'),
                'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', 'blocked'),
                'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', 'blocked'),
                'all_runtime_y' => (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false),
                'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            ],
            'submission_preflight' => [
                'status' => (string) data_get($submissionPreflight, 'status', 'unknown'),
                'submission_preflight_hash' => (string) data_get($submissionPreflight, 'submission_preflight_hash', ''),
                'next_required_submission' => (string) data_get($submissionPreflight, 'next_required_submission', ''),
                'next_required_command' => (string) data_get($submissionPreflight, 'next_required_command', ''),
                'step_count' => (int) data_get($submissionPreflight, 'step_count', 0),
                'ready_step_count' => (int) data_get($submissionPreflight, 'ready_step_count', 0),
                'blocked_step_count' => (int) data_get($submissionPreflight, 'blocked_step_count', 0),
            ],
            'blocker_explainer' => [
                'status' => (string) data_get($blockerExplainer, 'status', 'unknown'),
                'explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
                'remaining_blocker_count' => (int) data_get($blockerExplainer, 'remaining_blocker_count', 0),
                'known_blocker_ids' => (array) data_get($blockerExplainer, 'known_blocker_ids', []),
                'human_required' => (bool) data_get($blockerExplainer, 'machine_status.human_required', false),
                'real_provider_required' => (bool) data_get($blockerExplainer, 'machine_status.real_provider_required', false),
            ],
            'final_evidence_bundle' => [
                'status' => (string) data_get($finalEvidenceBundle, 'status', 'unknown'),
                'bundle_hash' => (string) data_get($finalEvidenceBundle, 'atlas_self_construction_final_evidence_bundle_status.bundle_identity.bundle_hash', data_get($finalEvidenceBundle, 'atlas_self_construction_final_evidence_bundle.bundle_identity.bundle_hash', '')),
                'completion_claim_allowed' => (bool) data_get($finalEvidenceBundle, 'completion_claim_allowed', false),
            ],
            'ordered_operator_path' => $orderedOperatorPath,
            'ordered_operator_path_step_count' => count($orderedOperatorPath),
            'blocking_artifacts' => $blockingArtifacts,
            'blocking_artifact_count' => count($blockingArtifacts),
            'artifact_contracts' => $artifactContracts,
            'artifact_templates' => $artifactTemplates,
            'artifact_verification_matrix' => $artifactVerificationMatrix,
            'operator_command_plan' => [
                'refresh_replay_snapshot_if_stale' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
                'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'compose_runtime_promotion_receipt_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'prepare_real_provider_smoke_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'compose_real_provider_smoke_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'compose_human_completion_receipt_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'operator_evidence_artifact_template_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --json',
            ],
            'anti_cheat_policy' => [
                'reject_fake_real_provider_smoke',
                'reject_synthetic_provider_call',
                'reject_token_spend_without_cost_event',
                'reject_human_receipt_before_runtime_and_smoke_green',
                'reject_runtime_autopromotion',
                'reject_completion_claim_without_human_receipt',
                'reject_placeholder_operator',
                'reject_hash_mismatch',
                'reject_stale_replay_snapshot',
                'reject_direct_provider_call_from_read_only_surface',
            ],
            'non_execution_guarantees' => [
                'does_not_start_codex',
                'does_not_call_codex_cli_or_app',
                'does_not_spawn_process',
                'does_not_call_provider',
                'does_not_spend_tokens',
                'does_not_dispatch_work',
                'does_not_execute_adapter',
                'does_not_enable_runtime',
                'does_not_write_ledger',
                'does_not_persist_receipts',
                'does_not_promote_completion',
                'does_not_sign_for_operator',
            ],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['closure_corridor_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $blockerExplainer
     * @return list<array<string, mixed>>
     */
    private function orderedOperatorPath(
        array $blockerExplainer,
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $completionAuditComplete,
        string $runtimeReceiptHash,
        string $realProviderSmokeHash,
        string $humanReceiptHash,
        string $completionAuditHash,
    ): array {
        $captureSnapshotCommand = (string) data_get($blockerExplainer, 'command_plan.capture_snapshot_if_stale', '');
        $draftRuntime = (string) data_get($blockerExplainer, 'command_plan.draft_runtime_promotion_receipt', '');
        $persistRuntime = (string) data_get($blockerExplainer, 'command_plan.persist_runtime_promotion_receipt', '');
        $composeHashes = (string) data_get($blockerExplainer, 'command_plan.compose_completion_evidence_hashes', '');
        $offlineHarness = 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json';
        $draftSmoke = (string) data_get($blockerExplainer, 'command_plan.draft_real_provider_smoke', '');
        $persistSmoke = (string) data_get($blockerExplainer, 'command_plan.persist_real_provider_smoke', '');
        $draftHumanReceipt = (string) data_get($blockerExplainer, 'command_plan.draft_human_completion_receipt', '');
        $persistHumanReceipt = (string) data_get($blockerExplainer, 'command_plan.persist_human_completion_receipt', '');
        $completionAuditCommand = (string) data_get($blockerExplainer, 'command_plan.completion_audit', '');

        $hashAvailableRuntime = $runtimeReceiptHash !== '';
        $hashAvailableSmoke = $realProviderSmokeHash !== '';
        $hashAvailableHuman = $humanReceiptHash !== '';
        $hashAvailableAudit = $completionAuditHash !== '';

        return [
            $this->pathStep(
                id: 'refresh_replay_snapshot_if_stale',
                phase: 'refresh_baseline',
                status: 'always_safe',
                requiredInputs: [],
                producedArtifacts: ['fresh_replay_snapshot', 'replay_diff_with_current_before_snapshot'],
                verifierService: AgentControlPlaneReleaseDossierService::class,
                command: $captureSnapshotCommand,
                persistCommand: '',
                stopCondition: 'stop_if_release_dossier_status_is_not_available',
                forbiddenShortcuts: ['skipping_snapshot_when_release_dossier_warns_stale'],
                evidenceHashesCurrentlyAvailable: array_values(array_filter([$hashAvailableRuntime ? 'runtime_promotion_receipt_hash' : null])),
                missingInputs: [],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'draft_runtime_promotion_receipt',
                phase: 'runtime_promotion',
                status: $runtimeReceiptReady ? 'completed' : 'blocked_operator_input_required',
                requiredInputs: ['signed_by', 'reason'],
                producedArtifacts: ['runtime_promotion_receipt_draft', 'runtime_promotion_receipt_hash_preimage'],
                verifierService: AtlasSelfConstructionRuntimePromotionReceiptService::class,
                command: $draftRuntime,
                persistCommand: '',
                stopCondition: 'stop_if_receipt_hash_mismatch_or_promoted_gap_ids_drift',
                forbiddenShortcuts: ['placeholder_signed_by', 'reason_under_32_chars'],
                evidenceHashesCurrentlyAvailable: $hashAvailableRuntime ? ['runtime_promotion_receipt_hash'] : [],
                missingInputs: $runtimeReceiptReady ? [] : ['operator_signed_runtime_promotion_receipt'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'compose_runtime_promotion_receipt_hash',
                phase: 'runtime_promotion',
                status: $hashAvailableRuntime ? 'ready_to_recompose' : 'waiting_for_runtime_promotion_draft',
                requiredInputs: ['runtime_promotion_receipt_json'],
                producedArtifacts: ['runtime_promotion_receipt_hash_recomputed', 'placeholder_field_report'],
                verifierService: AtlasSelfConstructionCompletionEvidenceHashComposerService::class,
                command: $composeHashes,
                persistCommand: '',
                stopCondition: 'stop_if_runtime_promotion_receipt_payload_contains_placeholders_or_forbidden_flags',
                forbiddenShortcuts: ['accepting_input_hash_without_recomputation'],
                evidenceHashesCurrentlyAvailable: $hashAvailableRuntime ? ['runtime_promotion_receipt_hash'] : [],
                missingInputs: $hashAvailableRuntime ? [] : ['runtime_promotion_receipt_payload'],
                canRunAutomatically: true,
            ),
            $this->pathStep(
                id: 'persist_runtime_promotion_receipt_after_verifier_passes',
                phase: 'runtime_promotion',
                status: $runtimeReceiptReady ? 'completed' : 'waiting_for_runtime_promotion_draft',
                requiredInputs: ['runtime_promotion_receipt_payload'],
                producedArtifacts: ['runtime_promotion_receipt_persisted_under_verifier'],
                verifierService: AtlasSelfConstructionRuntimePromotionReceiptService::class,
                command: '',
                persistCommand: $persistRuntime,
                stopCondition: 'stop_if_verifier_returns_status_other_than_passed',
                forbiddenShortcuts: ['persisting_before_verifier_passes', 'bypassing_AtlasSelfConstructionRuntimePromotionReceiptService_verify'],
                evidenceHashesCurrentlyAvailable: $hashAvailableRuntime ? ['runtime_promotion_receipt_hash'] : [],
                missingInputs: $runtimeReceiptReady ? [] : ['verified_runtime_promotion_receipt'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'prepare_real_provider_smoke_offline_harness',
                phase: 'real_provider_smoke',
                status: $realProviderSmokeReady ? 'completed' : 'always_safe',
                requiredInputs: [],
                producedArtifacts: ['real_provider_smoke_offline_harness_runbook'],
                verifierService: AtlasSelfConstructionRealProviderSmokeOfflineHarnessService::class,
                command: $offlineHarness,
                persistCommand: '',
                stopCondition: 'stop_if_offline_harness_status_is_not_available',
                forbiddenShortcuts: ['skipping_offline_harness_and_running_provider_directly_from_atlas'],
                evidenceHashesCurrentlyAvailable: [],
                missingInputs: [],
                canRunAutomatically: true,
            ),
            $this->pathStep(
                id: 'run_operator_approved_real_provider_smoke_outside_this_read_only_surface',
                phase: 'real_provider_smoke',
                status: $realProviderSmokeReady ? 'completed' : 'blocked_operator_action_required_outside_this_surface',
                requiredInputs: ['operator_approval', 'operator_observation_evidence'],
                producedArtifacts: ['provider_run_id', 'task_packet_id', 'cost_event_hash', 'work_product_manifest_hash', 'evidence_ledger_hash', 'continuation_summary_hash', 'provider_response_hash', 'operator_approval_receipt_hash'],
                verifierService: '',
                command: '',
                persistCommand: '',
                stopCondition: 'stop_if_atlas_initiates_provider_call_or_token_spend',
                forbiddenShortcuts: ['atlas_calls_provider', 'atlas_spends_tokens', 'synthetic_smoke_payload'],
                evidenceHashesCurrentlyAvailable: $hashAvailableSmoke ? ['real_provider_smoke_hash'] : [],
                missingInputs: $realProviderSmokeReady ? [] : ['operator_approved_real_provider_run'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'draft_real_provider_smoke_payload',
                phase: 'real_provider_smoke',
                status: $realProviderSmokeReady ? 'completed' : 'waiting_for_operator_approved_provider_run',
                requiredInputs: ['real_provider_smoke_preimage'],
                producedArtifacts: ['real_provider_smoke_draft', 'real_provider_smoke_payload_with_hash'],
                verifierService: AtlasSelfConstructionRealProviderSmokeCertificationService::class,
                command: $draftSmoke,
                persistCommand: '',
                stopCondition: 'stop_if_required_observation_flags_or_evidence_hashes_missing',
                forbiddenShortcuts: ['observation_flags_false', 'placeholder_provider_run_id', 'forbidden_flags_true'],
                evidenceHashesCurrentlyAvailable: $hashAvailableSmoke ? ['real_provider_smoke_hash'] : [],
                missingInputs: $realProviderSmokeReady ? [] : ['operator_supplied_real_provider_smoke_payload'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'compose_real_provider_smoke_hash',
                phase: 'real_provider_smoke',
                status: $hashAvailableSmoke ? 'ready_to_recompose' : 'waiting_for_real_provider_smoke_draft',
                requiredInputs: ['real_provider_smoke_json'],
                producedArtifacts: ['real_provider_smoke_hash_recomputed', 'forbidden_flag_report'],
                verifierService: AtlasSelfConstructionCompletionEvidenceHashComposerService::class,
                command: $composeHashes,
                persistCommand: '',
                stopCondition: 'stop_if_real_provider_smoke_payload_contains_forbidden_flags_or_placeholders',
                forbiddenShortcuts: ['accepting_atlas_origin_provider_call_flags'],
                evidenceHashesCurrentlyAvailable: $hashAvailableSmoke ? ['real_provider_smoke_hash'] : [],
                missingInputs: $hashAvailableSmoke ? [] : ['real_provider_smoke_payload'],
                canRunAutomatically: true,
            ),
            $this->pathStep(
                id: 'persist_real_provider_smoke_after_verifier_passes',
                phase: 'real_provider_smoke',
                status: $realProviderSmokeReady ? 'completed' : 'waiting_for_real_provider_smoke_draft',
                requiredInputs: ['real_provider_smoke_payload'],
                producedArtifacts: ['real_provider_smoke_persisted_under_certifier'],
                verifierService: AtlasSelfConstructionRealProviderSmokeCertificationService::class,
                command: '',
                persistCommand: $persistSmoke,
                stopCondition: 'stop_if_certifier_returns_status_other_than_passed',
                forbiddenShortcuts: ['persisting_before_certifier_passes', 'bypassing_AtlasSelfConstructionRealProviderSmokeCertificationService_certify'],
                evidenceHashesCurrentlyAvailable: $hashAvailableSmoke ? ['real_provider_smoke_hash'] : [],
                missingInputs: $realProviderSmokeReady ? [] : ['verified_real_provider_smoke_payload'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'draft_human_completion_receipt',
                phase: 'human_completion_receipt',
                status: $humanReceiptReady ? 'completed' : ($runtimeReceiptReady && $realProviderSmokeReady ? 'ready_for_operator_input' : 'waiting_for_runtime_promotion_and_real_provider_smoke'),
                requiredInputs: ['signed_by', 'reason', 'runtime_promotion_receipt_payload', 'real_provider_smoke_payload'],
                producedArtifacts: ['human_completion_receipt_draft', 'human_completion_receipt_hash_preimage'],
                verifierService: AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                command: $draftHumanReceipt,
                persistCommand: '',
                stopCondition: 'stop_if_human_receipt_drafted_before_runtime_and_smoke_green',
                forbiddenShortcuts: ['drafting_human_receipt_before_runtime_and_smoke_green', 'placeholder_completion_audit_hash'],
                evidenceHashesCurrentlyAvailable: $hashAvailableHuman ? ['human_completion_receipt_hash'] : [],
                missingInputs: $humanReceiptReady ? [] : ['operator_signed_human_completion_receipt'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'compose_human_completion_receipt_hash',
                phase: 'human_completion_receipt',
                status: $hashAvailableHuman ? 'ready_to_recompose' : 'waiting_for_human_completion_receipt_draft',
                requiredInputs: ['completion_receipt_json'],
                producedArtifacts: ['human_completion_receipt_hash_recomputed', 'placeholder_field_report'],
                verifierService: AtlasSelfConstructionCompletionEvidenceHashComposerService::class,
                command: $composeHashes,
                persistCommand: '',
                stopCondition: 'stop_if_human_completion_receipt_payload_contains_placeholders_or_forbidden_flags',
                forbiddenShortcuts: ['accepting_input_hash_without_recomputation'],
                evidenceHashesCurrentlyAvailable: $hashAvailableHuman ? ['human_completion_receipt_hash'] : [],
                missingInputs: $hashAvailableHuman ? [] : ['human_completion_receipt_payload'],
                canRunAutomatically: true,
            ),
            $this->pathStep(
                id: 'persist_human_completion_receipt_after_prerequisites_green',
                phase: 'human_completion_receipt',
                status: $humanReceiptReady ? 'completed' : 'waiting_for_runtime_and_smoke_and_human_draft',
                requiredInputs: ['human_completion_receipt_payload', 'runtime_promotion_receipt_passed', 'real_provider_smoke_passed'],
                producedArtifacts: ['human_completion_receipt_persisted_under_verifier'],
                verifierService: AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                command: '',
                persistCommand: $persistHumanReceipt,
                stopCondition: 'stop_if_verifier_returns_status_other_than_passed',
                forbiddenShortcuts: ['persisting_before_runtime_and_smoke_green', 'persisting_before_verifier_passes'],
                evidenceHashesCurrentlyAvailable: $hashAvailableHuman ? ['human_completion_receipt_hash'] : [],
                missingInputs: $humanReceiptReady ? [] : ['verified_human_completion_receipt'],
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'rerun_completion_audit',
                phase: 'final_audit',
                status: $completionAuditComplete ? 'completed' : 'always_safe',
                requiredInputs: [],
                producedArtifacts: ['fresh_completion_audit', 'completion_audit_hash'],
                verifierService: AtlasSelfConstructionOsCompletionAuditService::class,
                command: $completionAuditCommand,
                persistCommand: '',
                stopCondition: 'stop_if_failed_criteria_remains_non_empty',
                forbiddenShortcuts: ['promoting_completion_before_audit_status_complete'],
                evidenceHashesCurrentlyAvailable: $hashAvailableAudit ? ['completion_audit_hash'] : [],
                missingInputs: $completionAuditComplete ? [] : ['all_three_evidence_artifacts'],
                canRunAutomatically: true,
            ),
            $this->pathStep(
                id: 'promote_next_stage_only_after_completion_audit_complete',
                phase: 'final_audit',
                status: $completionAuditComplete ? 'ready_for_operator_review_outside_this_surface' : 'blocked_until_completion_audit_complete',
                requiredInputs: ['completion_audit_status_complete'],
                producedArtifacts: ['next_stage_promotion_decision'],
                verifierService: '',
                command: '',
                persistCommand: '',
                stopCondition: 'stop_if_completion_audit_status_is_not_complete',
                forbiddenShortcuts: ['promoting_next_stage_without_completion_audit_complete', 'enabling_self_programming_without_signed_gate'],
                evidenceHashesCurrentlyAvailable: $hashAvailableAudit ? ['completion_audit_hash'] : [],
                missingInputs: $completionAuditComplete ? [] : ['completion_audit_status_complete'],
                canRunAutomatically: false,
            ),
        ];
    }

    /**
     * @param  list<string>  $requiredInputs
     * @param  list<string>  $producedArtifacts
     * @param  list<string>  $forbiddenShortcuts
     * @param  list<string>  $evidenceHashesCurrentlyAvailable
     * @param  list<string>  $missingInputs
     * @return array<string, mixed>
     */
    private function pathStep(
        string $id,
        string $phase,
        string $status,
        array $requiredInputs,
        array $producedArtifacts,
        string $verifierService,
        string $command,
        string $persistCommand,
        string $stopCondition,
        array $forbiddenShortcuts,
        array $evidenceHashesCurrentlyAvailable,
        array $missingInputs,
        bool $canRunAutomatically,
    ): array {
        return [
            'id' => $id,
            'phase' => $phase,
            'status' => $status,
            'required_inputs' => $requiredInputs,
            'produced_artifacts' => $producedArtifacts,
            'verifier_service' => $verifierService,
            'command' => $command,
            'persist_command' => $persistCommand,
            'stop_condition' => $stopCondition,
            'forbidden_shortcuts' => $forbiddenShortcuts,
            'evidence_hashes_currently_available' => $evidenceHashesCurrentlyAvailable,
            'missing_inputs' => $missingInputs,
            'can_run_automatically' => $canRunAutomatically,
        ];
    }

    /**
     * @param  list<string>  $requiredFields
     * @param  list<string>  $requiredHashFields
     * @param  list<string>  $requiredBooleanAcks
     * @param  list<string>  $forbiddenFlags
     * @return array<string, mixed>
     */
    private function artifactRow(
        array $requiredFields,
        array $requiredHashFields,
        array $requiredBooleanAcks,
        array $forbiddenFlags,
        string $verifyingService,
        string $currentStatus,
        string $currentHash,
        int $blockerCount,
        int $missingCount,
    ): array {
        return [
            'required_fields' => $requiredFields,
            'required_hash_fields' => $requiredHashFields,
            'required_boolean_acknowledgements' => $requiredBooleanAcks,
            'forbidden_flags' => $forbiddenFlags,
            'verifying_service' => $verifyingService,
            'current_status' => $currentStatus,
            'current_hash' => $currentHash,
            'missing_count' => $missingCount,
            'blocker_count' => $blockerCount,
        ];
    }

    /** @return array<string, mixed> */
    private function safeCall(callable $fn): array
    {
        try {
            $value = $fn();

            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            return ['status' => 'exception', 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['closure_corridor_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
