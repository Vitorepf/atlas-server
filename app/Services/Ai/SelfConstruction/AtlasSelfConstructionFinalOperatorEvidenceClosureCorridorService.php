<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

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
        $submissionReadiness = $this->safeCall(fn () => (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService($this->readiness))->build($options));
        $finalEvidenceBundle = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionFinalEvidenceBundleStatus($options));
        $runtimeReceiptInput = (array) ($options['runtime_promotion_receipt'] ?? []);
        $realProviderSmokeInput = (array) ($options['real_provider_smoke'] ?? []);
        $completionReceiptInput = (array) ($options['completion_receipt'] ?? []);
        $draftWorkspaceLoaded = (string) data_get($submissionReadiness, 'draft_workspace_input.status', '') === 'loaded_for_read_only_submission_readiness';
        $draftHashFinalizationRequired = (bool) data_get($submissionReadiness, 'draft_hash_finalization_required', false);
        $draftHashFinalizationStatus = (string) data_get($submissionReadiness, 'draft_hash_finalization.status', 'not_requested');
        $draftWorkspacePublisher = $this->safeCall(fn () => (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish($options));
        $draftWorkspacePublisherStatus = (string) data_get($draftWorkspacePublisher, 'status', 'not_requested');
        $draftWorkspacePublishableCount = (int) data_get($draftWorkspacePublisher, 'publishable_artifact_count', 0);
        $draftWorkspacePublishedCount = (int) data_get($draftWorkspacePublisher, 'published_artifact_count', 0);
        $draftWorkspaceAtomicPublishRequired = (bool) data_get($draftWorkspacePublisher, 'atomic_bundle_publish_required', true);
        $draftWorkspaceAtomicBundleReady = (bool) data_get($draftWorkspacePublisher, 'atomic_bundle_ready', false);

        $runtimeReceiptReady = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed'
            && (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false);
        $realProviderSmokeReady = (string) data_get($completionEvidence, 'real_provider_smoke.status') === 'passed';
        $realProviderSmokePersistedBeforeHumanReceiptCommand = (bool) data_get(
            $completionEvidence,
            'checks.real_provider_smoke_persisted_before_human_receipt_command',
            false,
        );
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
        $operatorSubmissionEnvelopes = $this->operatorSubmissionEnvelopes(
            options: $options,
            completionAudit: $completionAudit,
            completionEvidence: $completionEvidence,
            runtimeReceipt: $runtimeReceiptInput,
            realProviderSmoke: $realProviderSmokeInput,
            completionReceipt: $completionReceiptInput,
            runtimeReceiptReady: $runtimeReceiptReady,
            realProviderSmokeReady: $realProviderSmokeReady,
            realProviderSmokePersistedBeforeHumanReceiptCommand: $realProviderSmokePersistedBeforeHumanReceiptCommand,
            humanReceiptReady: $humanReceiptReady,
        );

        $orderedOperatorPath = $this->orderedOperatorPath(
            blockerExplainer: $blockerExplainer,
            runtimeReceiptReady: $runtimeReceiptReady,
            realProviderSmokeReady: $realProviderSmokeReady,
            realProviderSmokePersistedBeforeHumanReceiptCommand: $realProviderSmokePersistedBeforeHumanReceiptCommand,
            humanReceiptReady: $humanReceiptReady,
            completionAuditComplete: $completionAuditComplete,
            runtimeReceiptHash: $runtimeReceiptHash,
            realProviderSmokeHash: $realProviderSmokeHash,
            humanReceiptHash: $humanReceiptHash,
            completionAuditHash: $completionAuditHash,
            draftWorkspaceLoaded: $draftWorkspaceLoaded,
            draftHashFinalizationRequired: $draftHashFinalizationRequired,
            draftHashFinalizationStatus: $draftHashFinalizationStatus,
            draftWorkspacePublisherStatus: $draftWorkspacePublisherStatus,
            draftWorkspacePublishableCount: $draftWorkspacePublishableCount,
            draftWorkspacePublishedCount: $draftWorkspacePublishedCount,
            draftWorkspaceAtomicBundleReady: $draftWorkspaceAtomicBundleReady,
        );
        $operatorCommandPlan = [
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
            'finalize_operator_draft_workspace_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path=<workspace_path> --write-computed-operator-draft-hashes --json',
            'publish_finalized_operator_draft_workspace' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path=<workspace_path> --publish-operator-draft-workspace --json',
        ];
        $blockingArtifacts = array_values(array_filter([
            $runtimeReceiptReady ? null : 'runtime_promotion_receipt',
            $realProviderSmokeReady ? null : 'real_provider_smoke',
            $humanReceiptReady ? null : 'human_completion_receipt',
        ]));
        $operatorNextAction = $this->operatorNextAction(
            orderedOperatorPath: $orderedOperatorPath,
            submissionPreflight: $submissionPreflight,
            operatorCommandPlan: $operatorCommandPlan,
            completionAuditComplete: $completionAuditComplete,
        );
        $resumptionCheckpoint = (array) data_get($submissionPreflight, 'operator_resumption_checkpoint', []);
        $operatorClosureCommandReplay = (array) data_get($submissionPreflight, 'operator_closure_command_replay', []);
        $operatorClosureHandoff = $this->operatorClosureHandoff(
            operatorNextAction: $operatorNextAction,
            operatorCommandPlan: $operatorCommandPlan,
            orderedOperatorPath: $orderedOperatorPath,
            blockingArtifacts: $blockingArtifacts,
            completionAuditBlockerSummary: (array) data_get($submissionPreflight, 'completion_audit_blocker_summary', []),
            resumptionCheckpoint: $resumptionCheckpoint,
            operatorClosureCommandReplay: $operatorClosureCommandReplay,
            completionAuditComplete: $completionAuditComplete,
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

        $status = 'blocked_operator_evidence_required';
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            $status = $completionAuditComplete ? 'complete_candidate' : 'ready_for_final_audit';
        }
        $operatorExecutionRunbook = $this->operatorExecutionRunbook(
            operatorNextAction: $operatorNextAction,
            operatorClosureHandoff: $operatorClosureHandoff,
            orderedOperatorPath: $orderedOperatorPath,
            operatorCommandPlan: $operatorCommandPlan,
            artifactVerificationMatrix: $artifactVerificationMatrix,
            operatorClosureCommandReplay: $operatorClosureCommandReplay,
            completionAuditComplete: $completionAuditComplete,
        );
        $closureReadinessSummary = $this->closureReadinessSummary(
            completionAudit: $completionAudit,
            blockingArtifacts: $blockingArtifacts,
            operatorNextAction: $operatorNextAction,
            completionAuditComplete: $completionAuditComplete,
        );
        $operatorCompletionProgressMeter = $this->operatorCompletionProgressMeter(
            completionAudit: $completionAudit,
            artifactVerificationMatrix: $artifactVerificationMatrix,
            operatorNextAction: $operatorNextAction,
            completionAuditComplete: $completionAuditComplete,
        );
        $operatorNextActionShellPacket = $this->operatorNextActionShellPacket(
            operatorNextAction: $operatorNextAction,
            operatorCompletionProgressMeter: $operatorCompletionProgressMeter,
        );
        $operatorFailureRecoveryMatrix = $this->operatorFailureRecoveryMatrix($operatorNextActionShellPacket);

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
                'failed_criteria_detailed' => (array) data_get($completionAudit, 'failed_criteria_detailed', []),
                'blocker_classification' => (array) data_get($completionAudit, 'blocker_classification', []),
            ],
            'current_completion_evidence_status' => [
                'status' => (string) data_get($completionEvidence, 'status', 'unknown'),
                'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
                'runtime_promotion_receipt_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'blocked'),
                'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', 'blocked'),
                'real_provider_smoke_persisted_before_human_receipt_command' => $realProviderSmokePersistedBeforeHumanReceiptCommand,
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
                'completion_audit_blocker_summary' => (array) data_get($submissionPreflight, 'completion_audit_blocker_summary', []),
                'resumption_checkpoint_hash' => (string) data_get($submissionPreflight, 'operator_resumption_checkpoint.resumption_checkpoint_hash', ''),
                'resumption_checkpoint_current_step' => (string) data_get($submissionPreflight, 'operator_resumption_checkpoint.current_step', ''),
                'resumption_checkpoint_can_resume_without_chat_history' => (bool) data_get($submissionPreflight, 'operator_resumption_checkpoint.can_resume_without_chat_history', false),
                'resumption_checkpoint_requires_fresh_preflight_before_persist' => (bool) data_get($submissionPreflight, 'operator_resumption_checkpoint.requires_fresh_preflight_before_persist', false),
                'operator_closure_command_replay_hash' => (string) data_get($submissionPreflight, 'operator_closure_command_replay.command_replay_hash', ''),
                'operator_closure_command_replay_current_step' => (string) data_get($submissionPreflight, 'operator_closure_command_replay.current_step', ''),
                'operator_closure_command_replay_step_count' => (int) data_get($submissionPreflight, 'operator_closure_command_replay.replay_step_count', 0),
            ],
            'operator_workspace_diagnostics' => [
                'schema_version' => 'atlas.self_construction.final_operator_evidence_closure_corridor_workspace_diagnostics.v1',
                'status' => $draftWorkspaceLoaded ? 'operator_draft_workspace_loaded' : 'operator_draft_workspace_not_loaded',
                'requested_path' => (string) data_get($submissionReadiness, 'draft_workspace_input.requested_path', ''),
                'workspace_directory' => (string) data_get($submissionReadiness, 'draft_workspace_input.workspace_directory', ''),
                'manifest_path' => (string) data_get($submissionReadiness, 'draft_workspace_input.manifest_path', ''),
                'loaded_artifacts' => (array) data_get($submissionReadiness, 'draft_workspace_input.loaded_artifacts', []),
                'violation_count' => (int) data_get($submissionReadiness, 'draft_workspace_input.violation_count', 0),
                'warning_count' => (int) data_get($submissionReadiness, 'draft_workspace_input.warning_count', 0),
                'draft_hash_finalization_status' => $draftHashFinalizationStatus,
                'draft_hash_finalization_required' => $draftHashFinalizationRequired,
                'draft_hash_finalization_ready_artifact_count' => (int) data_get($submissionReadiness, 'draft_hash_finalization.ready_artifact_count', 0),
                'draft_hash_finalization_blocked_artifact_count' => (int) data_get($submissionReadiness, 'draft_hash_finalization.blocked_artifact_count', 0),
                'draft_hash_finalization_write_command' => (string) data_get($submissionReadiness, 'draft_hash_finalization.write_command', ''),
                'draft_workspace_publisher_status' => $draftWorkspacePublisherStatus,
                'draft_workspace_publishable_artifact_count' => $draftWorkspacePublishableCount,
                'draft_workspace_published_artifact_count' => $draftWorkspacePublishedCount,
                'draft_workspace_atomic_bundle_publish_required' => $draftWorkspaceAtomicPublishRequired,
                'draft_workspace_atomic_bundle_ready' => $draftWorkspaceAtomicBundleReady,
                'draft_workspace_publish_command' => (string) data_get($draftWorkspacePublisher, 'publish_command', 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path=<workspace_path> --publish-operator-draft-workspace --json'),
                'draft_workspace_post_publish_readiness_command' => (string) data_get($draftWorkspacePublisher, 'post_publish_readiness_command', ''),
                'draft_workspace_post_publish_persistence_sequence' => (array) data_get($draftWorkspacePublisher, 'post_publish_persistence_sequence', []),
                'draft_workspace_post_publish_persistence_step_count' => (int) data_get($draftWorkspacePublisher, 'post_publish_persistence_step_count', 0),
                'draft_workspace_post_publish_persistence_sequence_ordered' => (bool) data_get($draftWorkspacePublisher, 'post_publish_persistence_sequence_ordered', false),
                'draft_workspace_requires_explicit_operator_persistence_commands' => (bool) data_get($draftWorkspacePublisher, 'requires_explicit_operator_persistence_commands', true),
                'draft_workspace_can_persist_from_publisher' => (bool) data_get($draftWorkspacePublisher, 'can_persist_from_publisher', false),
                'submission_readiness_status' => (string) data_get($submissionReadiness, 'status', 'unknown'),
                'submission_readiness_next_required' => (string) data_get($submissionReadiness, 'next_required', ''),
                'canonical_submission_persistence_plan_status' => (string) data_get($submissionReadiness, 'canonical_submission_persistence_plan.status', 'unknown'),
                'canonical_submission_persistence_plan_next_step_id' => (string) data_get($submissionReadiness, 'canonical_submission_persistence_plan.next_step_id', ''),
                'canonical_submission_persisted_evidence_state' => (array) data_get($submissionReadiness, 'canonical_submission_persistence_plan.persisted_evidence_state', []),
                'human_receipt_persistence_requires_prior_persisted_smoke_command' => (bool) data_get($submissionReadiness, 'canonical_submission_persistence_plan.human_receipt_persistence_requires_prior_persisted_smoke_command', false),
                'can_write_from_corridor' => false,
                'can_persist_from_corridor' => false,
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
            'operator_submission_envelopes' => $operatorSubmissionEnvelopes,
            'operator_command_plan' => $operatorCommandPlan,
            'operator_closure_command_replay' => $operatorClosureCommandReplay,
            'operator_next_action' => $operatorNextAction,
            'operator_closure_handoff' => $operatorClosureHandoff,
            'operator_execution_runbook' => $operatorExecutionRunbook,
            'operator_next_action_shell_packet' => $operatorNextActionShellPacket,
            'operator_failure_recovery_matrix' => $operatorFailureRecoveryMatrix,
            'operator_command_surface_integrity' => $this->operatorCommandSurfaceIntegrity([
                'operator_command_plan' => $operatorCommandPlan,
                'operator_closure_command_replay' => $operatorClosureCommandReplay,
                'operator_next_action' => $operatorNextAction,
                'operator_closure_handoff' => $operatorClosureHandoff,
                'operator_execution_runbook' => $operatorExecutionRunbook,
                'operator_next_action_shell_packet' => $operatorNextActionShellPacket,
            ]),
            'operator_completion_progress_meter' => $operatorCompletionProgressMeter,
            'closure_readiness_summary' => $closureReadinessSummary,
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
                'reject_persistence_without_operator_submission_envelope',
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
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @return array<string, mixed>
     */
    private function operatorNextActionShellPacket(array $operatorNextAction, array $operatorCompletionProgressMeter): array
    {
        $exactCommand = (string) data_get($operatorNextAction, 'exact_command', '');
        $exactPersistCommand = (string) data_get($operatorNextAction, 'exact_persist_command', '');
        $proofCommands = (array) data_get($operatorNextAction, 'proof_commands_after_action', []);
        $commands = array_values(array_filter(array_merge(
            [
                'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                $exactCommand,
                $exactPersistCommand,
            ],
            $proofCommands,
        )));
        $placeholderFields = (array) data_get($operatorNextAction, 'placeholder_fields_to_replace', []);
        $placeholderReplacementContract = $this->placeholderReplacementContract($placeholderFields);
        $commandRows = [];
        foreach ($commands as $index => $command) {
            $commandRows[] = [
                'order' => $index + 1,
                'command' => $command,
                'command_hash' => hash('sha256', (string) $command),
                'contains_placeholders' => $this->placeholderFields((string) $command) !== [],
            ];
        }

        $packet = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_shell_packet.v1',
            'mode' => 'read_only_final_operator_next_action_shell_packet',
            'status' => $placeholderFields === [] ? 'ready_after_operator_review' : 'blocked_replace_placeholders_before_copy',
            'current_required_artifact' => (string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_command' => $exactCommand,
            'exact_persist_command' => $exactPersistCommand,
            'command_contains_placeholders' => $placeholderFields !== [],
            'placeholder_fields_to_replace' => $placeholderFields,
            'placeholder_replacement_contract' => $placeholderReplacementContract,
            'placeholder_replacement_contract_count' => count($placeholderReplacementContract),
            'safe_to_copy_after_operator_review' => $placeholderFields === [],
            'operator_must_replace_placeholders' => $placeholderFields !== [],
            'preflight_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
            'post_action_proof_commands' => $proofCommands,
            'post_action_success_checks' => $this->postActionSuccessChecks((string) data_get($operatorNextAction, 'next_required_submission', '')),
            'post_action_verification_bundle' => $this->postActionVerificationBundle(
                (string) data_get($operatorNextAction, 'next_required_submission', ''),
                $proofCommands,
            ),
            'resume_after_interruption' => $this->operatorNextActionResumeAfterInterruption(
                $operatorNextAction,
                $operatorCompletionProgressMeter,
                $proofCommands,
            ),
            'ordered_shell_commands' => $commandRows,
            'ordered_shell_command_count' => count($commandRows),
            'stop_condition' => (string) data_get($operatorNextAction, 'stop_condition', ''),
            'forbidden_shortcuts' => (array) data_get($operatorNextAction, 'forbidden_shortcuts', []),
            'success_predicate_after_all_actions' => (string) data_get($operatorNextAction, 'success_predicate_after_all_actions', ''),
            'can_execute_from_packet' => false,
            'can_persist_from_packet' => false,
            'can_call_provider_from_packet' => false,
            'can_sign_for_operator_from_packet' => false,
            'non_execution_guarantees' => [
                'next_action_shell_packet_does_not_execute_commands',
                'next_action_shell_packet_does_not_persist_receipts',
                'next_action_shell_packet_does_not_call_provider',
                'next_action_shell_packet_does_not_spend_tokens',
                'next_action_shell_packet_does_not_sign_for_operator',
                'next_action_shell_packet_does_not_promote_completion',
            ],
        ];
        $packet['shell_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /**
     * @param  array<string, mixed>  $operatorNextActionShellPacket
     * @return array<string, mixed>
     */
    private function operatorFailureRecoveryMatrix(array $operatorNextActionShellPacket): array
    {
        $resumeCommand = (string) data_get(
            $operatorNextActionShellPacket,
            'resume_after_interruption.resume_command',
            'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
        );
        $verificationCommands = array_map(
            static fn (array $row): string => (string) ($row['command'] ?? ''),
            (array) data_get($operatorNextActionShellPacket, 'post_action_verification_bundle.verification_commands', []),
        );

        $rows = [
            [
                'failure_id' => 'placeholder_command_detected',
                'classification' => 'operator_input_required',
                'trigger' => 'exact command still contains <operator>, <operator reason...> or @/path/to placeholders',
                'safe_recovery_command' => $resumeCommand,
                'required_operator_action' => 'Replace placeholders with reviewed operator identity, reason or file path, then rerun the shell packet preflight.',
                'do_not_do' => ['do_not_execute_placeholder_command', 'do_not_persist_placeholder_receipt'],
            ],
            [
                'failure_id' => 'post_action_verifier_not_green',
                'classification' => 'verification_failed',
                'trigger' => 'post_action_verification_bundle success check fails or reports a blocked verifier',
                'safe_recovery_command' => $resumeCommand,
                'required_operator_action' => 'Inspect the failed verifier payload, keep the current artifact as the active step, and do not advance to the next artifact.',
                'do_not_do' => ['do_not_advance_to_next_artifact', 'do_not_persist_until_verifier_green'],
            ],
            [
                'failure_id' => 'hash_mismatch_detected',
                'classification' => 'evidence_integrity_failed',
                'trigger' => 'receipt_hash, smoke_hash, command hash or draft hash does not match the normalized payload',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'required_operator_action' => 'Recompute the canonical hash with the composer, update only the reviewed draft hash field, then rerun readiness.',
                'do_not_do' => ['do_not_edit_hash_by_hand', 'do_not_persist_mismatched_hash'],
            ],
            [
                'failure_id' => 'stale_replay_snapshot_or_runtime_basis',
                'classification' => 'stale_context',
                'trigger' => 'completion audit, release dossier, runtime gap matrix, promotion basis or closure basis hash changed after a draft was prepared',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
                'required_operator_action' => 'Refresh the replay snapshot if required, regenerate the affected draft against current hashes, then rerun the closure corridor.',
                'do_not_do' => ['do_not_sign_stale_hashes', 'do_not_reuse_old_draft_after_basis_drift'],
            ],
            [
                'failure_id' => 'real_provider_smoke_aborted_or_incomplete',
                'classification' => 'real_provider_operator_stop',
                'trigger' => 'operator aborts the external smoke, kill switch fires, or provider/cost/work-product evidence is incomplete',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'required_operator_action' => 'Record the aborted smoke as non-passing operator evidence, inspect kill-switch expectations, and rerun a bounded smoke only after operator approval.',
                'do_not_do' => ['do_not_mark_aborted_smoke_green', 'do_not_fabricate_provider_run_or_cost_event'],
            ],
        ];

        foreach ($rows as $index => &$row) {
            $row['order'] = $index + 1;
            $row['rerun_verification_commands'] = $verificationCommands;
            $row['can_recover_automatically'] = false;
            $row['requires_operator_review'] = true;
            $row['recovery_row_hash'] = $this->stableHash($row);
        }
        unset($row);

        $matrix = [
            'schema_version' => 'atlas.self_construction.final_operator_failure_recovery_matrix.v1',
            'mode' => 'read_only_operator_failure_recovery_matrix',
            'status' => 'operator_recovery_guidance_available',
            'row_count' => count($rows),
            'rows' => $rows,
            'recovery_requires_fresh_corridor_status' => true,
            'can_recover_from_matrix' => false,
            'can_execute_from_matrix' => false,
            'can_persist_from_matrix' => false,
            'can_call_provider_from_matrix' => false,
            'can_sign_for_operator_from_matrix' => false,
        ];
        $matrix['failure_recovery_matrix_hash'] = $this->stableHash($matrix);

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @param  list<string>  $proofCommands
     * @return array<string, mixed>
     */
    private function operatorNextActionResumeAfterInterruption(
        array $operatorNextAction,
        array $operatorCompletionProgressMeter,
        array $proofCommands,
    ): array {
        $resumeCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json';
        $resume = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_shell_packet_resume.v1',
            'mode' => 'read_only_resume_after_interruption_contract',
            'status' => 'resume_by_rerunning_closure_corridor_status',
            'current_required_artifact' => (string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'resume_command' => $resumeCommand,
            'resumption_checkpoint_command' => $resumeCommand,
            'exact_command_to_recompare_after_resume' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_persist_command_to_recompare_after_resume' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'proof_commands_to_rerun' => $proofCommands,
            'requires_fresh_preflight_before_persist' => true,
            'do_not_run_persist_command_until_verifier_green' => true,
            'can_resume_without_chat_history' => true,
            'if_command_failed_next_action' => 'Rerun the closure corridor status, inspect the reported blocker, do not persist evidence, and do not skip to the next artifact.',
            'if_chat_context_was_lost_next_action' => 'Rerun resume_command and continue only from current_required_artifact/current_step_id reported by the JSON payload.',
            'forbidden_resume_shortcuts' => [
                'do_not_persist_from_stale_chat_memory',
                'do_not_skip_preflight_after_interruption',
                'do_not_reuse_placeholder_commands',
                'do_not_mark_completion_without_fresh_audit_green',
                'do_not_fabricate_operator_or_provider_evidence',
            ],
            'can_execute_from_resume_contract' => false,
            'can_persist_from_resume_contract' => false,
            'can_call_provider_from_resume_contract' => false,
            'can_sign_for_operator_from_resume_contract' => false,
        ];
        $resume['resume_contract_hash'] = $this->stableHash($resume);

        return $resume;
    }

    /**
     * @param  list<string>  $placeholderFields
     * @return list<array<string, mixed>>
     */
    private function placeholderReplacementContract(array $placeholderFields): array
    {
        $rows = [];
        foreach ($placeholderFields as $placeholder) {
            $placeholder = (string) $placeholder;
            $rows[] = match (true) {
                $placeholder === '<operator>' => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_identity',
                    'required' => true,
                    'minimum_length' => 3,
                    'must_not_equal' => ['<operator>', 'operator', 'codex', 'claude', 'assistant', 'system', 'atlas'],
                    'validation_hint' => 'Use the real human/operator signer identity.',
                ],
                str_contains($placeholder, 'reason') => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_reason',
                    'required' => true,
                    'minimum_length' => 32,
                    'must_not_contain' => ['todo', 'placeholder', 'autosigned', 'lorem'],
                    'validation_hint' => 'Explain the operator judgment in at least 32 characters.',
                ],
                str_starts_with($placeholder, '@/path/to/') => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_file_path',
                    'required' => true,
                    'minimum_length' => 2,
                    'must_point_to_existing_operator_reviewed_json' => true,
                    'validation_hint' => 'Replace with an operator-reviewed JSON artifact path.',
                ],
                default => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'generic_operator_input',
                    'required' => true,
                    'minimum_length' => 1,
                    'validation_hint' => 'Replace before running; never execute a command with placeholders.',
                ],
            };
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function postActionSuccessChecks(string $nextRequiredSubmission): array
    {
        return match ($nextRequiredSubmission) {
            'runtime_promotion_receipt' => [
                ['surface' => 'runtime_promotion_receipt_draft', 'expected' => 'status=ready_for_operator_persistence'],
                ['surface' => 'operator_evidence_submission_readiness', 'expected' => 'next_required remains runtime_promotion_receipt until explicit persist succeeds'],
                ['surface' => 'completion_audit', 'expected' => 'runtime_gap_matrix_all_runtime_y remains blocked until persistence'],
            ],
            'real_provider_smoke' => [
                ['surface' => 'real_provider_smoke_offline_harness', 'expected' => 'operator has a bounded external smoke procedure'],
                ['surface' => 'real_provider_smoke_endgame', 'expected' => 'status becomes verifier_passed_ready_for_explicit_persistence only after real evidence'],
                ['surface' => 'completion_audit', 'expected' => 'end_to_end_real_provider_smoke_green remains blocked until persistence'],
            ],
            'human_completion_receipt' => [
                ['surface' => 'human_completion_receipt_draft', 'expected' => 'status=ready_for_operator_persistence only after runtime and smoke are green'],
                ['surface' => 'human_completion_receipt_endgame_verifier', 'expected' => 'can_persist=true before explicit persistence'],
                ['surface' => 'completion_audit', 'expected' => 'human_signed_os_complete_receipt_present remains blocked until persistence'],
            ],
            default => [
                ['surface' => 'completion_audit', 'expected' => 'status=complete AND completion_allowed=true AND failed_count=0'],
            ],
        };
    }

    /**
     * @param  list<string>  $proofCommands
     * @return array<string, mixed>
     */
    private function postActionVerificationBundle(string $nextRequiredSubmission, array $proofCommands): array
    {
        $commands = array_values(array_unique(array_filter(array_merge(
            $proofCommands,
            [
                'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
        ))));
        $commandRows = [];
        foreach ($commands as $index => $command) {
            $commandRows[] = [
                'order' => $index + 1,
                'command' => $command,
                'command_hash' => hash('sha256', (string) $command),
            ];
        }

        $bundle = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_post_action_verification_bundle.v1',
            'mode' => 'read_only_post_action_verification_bundle',
            'status' => 'verify_before_next_artifact_or_persist',
            'next_required_submission' => $nextRequiredSubmission,
            'verification_commands' => $commandRows,
            'verification_command_count' => count($commandRows),
            'success_checks' => $this->postActionSuccessChecks($nextRequiredSubmission),
            'success_check_count' => count($this->postActionSuccessChecks($nextRequiredSubmission)),
            'failure_policy' => [
                'if_any_check_fails' => 'stop_and_rerun_final_operator_evidence_closure_corridor_status',
                'do_not_advance_to_next_artifact' => true,
                'do_not_persist_receipt_until_verifier_green' => true,
                'do_not_mark_os_complete_from_partial_verification' => true,
            ],
            'expected_completion_audit_after_action' => match ($nextRequiredSubmission) {
                'runtime_promotion_receipt' => 'runtime_gap_matrix_all_runtime_y remains blocked until explicit runtime promotion persistence succeeds',
                'real_provider_smoke' => 'end_to_end_real_provider_smoke_green remains blocked until operator-approved smoke evidence is persisted',
                'human_completion_receipt' => 'human_signed_os_complete_receipt_present remains blocked until explicit human receipt persistence succeeds',
                default => 'status=complete only when completion_allowed=true and failed_count=0',
            },
            'can_verify_from_bundle' => false,
            'can_execute_from_bundle' => false,
            'can_persist_from_bundle' => false,
            'can_call_provider_from_bundle' => false,
            'can_sign_for_operator_from_bundle' => false,
        ];
        $bundle['verification_bundle_hash'] = $this->stableHash($bundle);

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $artifactVerificationMatrix
     * @param  array<string, mixed>  $operatorNextAction
     * @return array<string, mixed>
     */
    private function operatorCompletionProgressMeter(
        array $completionAudit,
        array $artifactVerificationMatrix,
        array $operatorNextAction,
        bool $completionAuditComplete,
    ): array {
        $artifactOrder = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'final_completion_audit',
        ];
        $rows = [];
        foreach ($artifactOrder as $index => $artifact) {
            $matrixRow = (array) ($artifactVerificationMatrix[$artifact] ?? []);
            $blockerCount = (int) ($matrixRow['blocker_count'] ?? 0);
            $missingCount = (int) ($matrixRow['missing_count'] ?? 0);
            $green = $blockerCount === 0 && $missingCount === 0;
            $rows[] = [
                'order' => $index + 1,
                'artifact' => $artifact,
                'status' => $green ? 'green' : 'blocked',
                'current_status' => (string) ($matrixRow['current_status'] ?? ''),
                'current_hash' => (string) ($matrixRow['current_hash'] ?? ''),
                'blocker_count' => $blockerCount,
                'missing_count' => $missingCount,
                'verifying_service' => (string) ($matrixRow['verifying_service'] ?? ''),
            ];
        }

        $greenRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['status'] ?? '') === 'green',
        ));
        $blockedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'green',
        ));
        $blockerClassification = (array) data_get($completionAudit, 'blocker_classification', []);

        $progress = [
            'schema_version' => 'atlas.self_construction.final_operator_completion_progress_meter.v1',
            'mode' => 'read_only_final_operator_completion_progress_meter',
            'status' => $completionAuditComplete ? 'completion_audit_complete' : 'operator_evidence_remaining',
            'artifact_count' => count($rows),
            'green_artifact_count' => count($greenRows),
            'blocked_artifact_count' => count($blockedRows),
            'progress_percent' => (int) floor((count($greenRows) / max(1, count($rows))) * 100),
            'current_required_artifact' => (string) data_get($operatorNextAction, 'next_required_submission', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_next_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_next_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'artifact_rows' => $rows,
            'technical_blockers_clear' => (array) data_get($blockerClassification, 'technical_blockers', []) === [],
            'human_blocker_count' => count((array) data_get($blockerClassification, 'human_blockers', [])),
            'real_provider_blocker_count' => count((array) data_get($blockerClassification, 'real_provider_blockers', [])),
            'completion_allowed' => $completionAuditComplete,
            'completion_claim_allowed' => $completionAuditComplete,
            'can_finish_without_operator' => false,
            'can_finish_without_real_provider_smoke' => false,
            'can_self_promote_completion' => false,
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'completion_progress_meter_does_not_execute_commands',
                'completion_progress_meter_does_not_persist_receipts',
                'completion_progress_meter_does_not_call_provider',
                'completion_progress_meter_does_not_spend_tokens',
                'completion_progress_meter_does_not_sign_for_operator',
                'completion_progress_meter_does_not_promote_completion',
            ],
        ];
        $progress['progress_meter_hash'] = $this->stableHash($progress);

        return $progress;
    }

    /**
     * Verifies that every exposed final-closure `atlas:ai:self-construction`
     * command references options that exist on the real Artisan command.
     *
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>
     */
    private function operatorCommandSurfaceIntegrity(array $surface): array
    {
        $commands = $this->collectOperatorCommands($surface);
        $commands = $this->uniqueCommandsByText($commands);
        $knownOptions = $this->selfConstructionCommandOptions();
        $knownOptionNames = array_fill_keys($knownOptions, true);
        $rows = [];
        $missing = [];

        foreach ($commands as $path => $command) {
            $options = $this->extractCommandOptions($command);
            $missingOptions = array_values(array_filter(
                $options,
                static fn (string $option): bool => ! isset($knownOptionNames[$option]),
            ));
            foreach ($missingOptions as $option) {
                $missing[] = [
                    'payload_path' => $path,
                    'option' => $option,
                    'command' => $command,
                ];
            }
            $rows[] = [
                'payload_path' => $path,
                'command' => $command,
                'command_hash' => hash('sha256', $command),
                'option_count' => count($options),
                'options' => $options,
                'missing_option_count' => count($missingOptions),
                'missing_options' => $missingOptions,
                'surface_ok' => $missingOptions === [],
            ];
        }

        $uniqueOptions = [];
        foreach ($rows as $row) {
            $uniqueOptions = array_merge($uniqueOptions, (array) $row['options']);
        }

        $integrity = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_command_surface_integrity.v1',
            'mode' => 'read_only_final_operator_closure_command_surface_integrity',
            'status' => $missing === [] ? 'command_surface_aligned' : 'command_surface_attention_required',
            'command_name' => 'atlas:ai:self-construction',
            'command_count' => count($commands),
            'checked_option_count' => count(array_unique($uniqueOptions)),
            'missing_option_count' => count($missing),
            'missing_options' => $missing,
            'commands' => $rows,
            'can_execute_commands_from_integrity_check' => false,
            'can_persist_from_integrity_check' => false,
            'can_call_provider_from_integrity_check' => false,
            'can_sign_for_operator_from_integrity_check' => false,
            'non_execution_guarantees' => [
                'final_operator_closure_command_surface_integrity_does_not_run_operator_commands',
                'final_operator_closure_command_surface_integrity_does_not_persist_evidence',
                'final_operator_closure_command_surface_integrity_does_not_call_provider',
                'final_operator_closure_command_surface_integrity_does_not_spend_tokens',
                'final_operator_closure_command_surface_integrity_does_not_dispatch_work',
                'final_operator_closure_command_surface_integrity_does_not_promote_completion',
            ],
        ];
        $integrity['command_surface_integrity_hash'] = $this->stableHash($integrity);

        return $integrity;
    }

    /**
     * @param  array<string, string>  $commands
     * @return array<string, string>
     */
    private function uniqueCommandsByText(array $commands): array
    {
        $unique = [];
        $seen = [];
        foreach ($commands as $path => $command) {
            if (isset($seen[$command])) {
                continue;
            }
            $seen[$command] = true;
            $unique[$path] = $command;
        }

        return $unique;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    private function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        $commands = [];
        foreach ($value as $key => $entry) {
            $entryPath = $path.'.'.(string) $key;
            if (is_array($entry)) {
                $commands = array_merge($commands, $this->collectOperatorCommands($entry, $entryPath));

                continue;
            }

            if (! is_string($entry)) {
                continue;
            }
            if (! str_contains($entry, 'php artisan atlas:ai:self-construction')) {
                continue;
            }
            $commands[$entryPath] = $entry;
        }

        ksort($commands);

        return $commands;
    }

    /**
     * @return list<string>
     */
    private function extractCommandOptions(string $command): array
    {
        preg_match_all('/(?:^|\s)--([A-Za-z0-9][A-Za-z0-9-]*)/', $command, $matches);
        $options = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($options);

        return $options;
    }

    /**
     * @return list<string>
     */
    private function selfConstructionCommandOptions(): array
    {
        $command = Artisan::all()['atlas:ai:self-construction'] ?? null;
        if ($command === null) {
            return [];
        }

        $options = array_keys($command->getDefinition()->getOptions());
        sort($options);

        return array_values(array_map('strval', $options));
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  list<string>  $blockingArtifacts
     * @param  array<string, mixed>  $operatorNextAction
     * @return array<string, mixed>
     */
    private function closureReadinessSummary(
        array $completionAudit,
        array $blockingArtifacts,
        array $operatorNextAction,
        bool $completionAuditComplete,
    ): array {
        $blockerClassification = (array) data_get($completionAudit, 'blocker_classification', []);
        $technicalBlockers = (array) data_get($blockerClassification, 'technical_blockers', []);
        $humanBlockers = (array) data_get($blockerClassification, 'human_blockers', []);
        $realProviderBlockers = (array) data_get($blockerClassification, 'real_provider_blockers', []);
        $releaseDossierGreen = $this->criterionPassed($completionAudit, 'release_dossier_green');
        $replayDiffGreen = $this->criterionPassed($completionAudit, 'replay_diff_against_completion_snapshot_green');
        $promotionGateGreen = $this->criterionPassed($completionAudit, 'promotion_gate_green');
        $mutationGuardGreen = $this->criterionPassed($completionAudit, 'mutation_guard_green');
        $certificationBatchGreen = $this->criterionPassed($completionAudit, 'certification_status_batch_green');
        $terminalLoopGreen = $this->criterionPassed($completionAudit, 'agent_control_plane_terminal_loop_certification_green');
        $technicalClosureGreen = $technicalBlockers === []
            && $releaseDossierGreen
            && $replayDiffGreen
            && $promotionGateGreen
            && $mutationGuardGreen
            && $certificationBatchGreen
            && $terminalLoopGreen;

        $summary = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_readiness_summary.v1',
            'status' => $completionAuditComplete
                ? 'completion_audit_complete'
                : ($technicalClosureGreen ? 'technical_closure_green_operator_evidence_remaining' : 'technical_closure_blocked'),
            'completion_audit_status' => (string) data_get($completionAudit, 'status', 'incomplete'),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_allowed' => $completionAuditComplete,
            'completion_claim_allowed' => $completionAuditComplete,
            'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
            'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
            'technical_closure_green' => $technicalClosureGreen,
            'technical_blocker_count' => count($technicalBlockers),
            'technical_blocker_ids' => array_values($technicalBlockers),
            'human_blocker_count' => count($humanBlockers),
            'human_blocker_ids' => array_values($humanBlockers),
            'real_provider_blocker_count' => count($realProviderBlockers),
            'real_provider_blocker_ids' => array_values($realProviderBlockers),
            'operator_evidence_blocking_artifacts' => $blockingArtifacts,
            'operator_evidence_blocking_artifact_count' => count($blockingArtifacts),
            'release_dossier_green' => $releaseDossierGreen,
            'replay_diff_green' => $replayDiffGreen,
            'promotion_gate_green' => $promotionGateGreen,
            'mutation_guard_green' => $mutationGuardGreen,
            'certification_status_batch_green' => $certificationBatchGreen,
            'terminal_loop_certification_green' => $terminalLoopGreen,
            'can_finish_without_operator' => false,
            'can_finish_without_real_provider_smoke' => false,
            'can_self_promote_completion' => false,
            'next_required_submission' => (string) data_get($operatorNextAction, 'next_required_submission', ''),
            'next_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_next_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_next_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'closure_readiness_summary_does_not_execute_commands',
                'closure_readiness_summary_does_not_persist_receipts',
                'closure_readiness_summary_does_not_call_provider',
                'closure_readiness_summary_does_not_spend_tokens',
                'closure_readiness_summary_does_not_sign_for_operator',
                'closure_readiness_summary_does_not_promote_completion',
            ],
        ];
        $summary['closure_readiness_summary_hash'] = $this->stableHash($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorClosureHandoff
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  array<string, string>  $operatorCommandPlan
     * @param  array<string, mixed>  $artifactVerificationMatrix
     * @return array<string, mixed>
     */
    private function operatorExecutionRunbook(
        array $operatorNextAction,
        array $operatorClosureHandoff,
        array $orderedOperatorPath,
        array $operatorCommandPlan,
        array $artifactVerificationMatrix,
        array $operatorClosureCommandReplay,
        bool $completionAuditComplete,
    ): array {
        $phases = [];
        $stepCards = [];
        foreach ($orderedOperatorPath as $index => $step) {
            $phase = (string) ($step['phase'] ?? '');
            if ($phase !== '' && ! in_array($phase, $phases, true)) {
                $phases[] = $phase;
            }
            $command = (string) ($step['command'] ?? '');
            $persistCommand = (string) ($step['persist_command'] ?? '');
            $stepCards[] = [
                'order' => $index + 1,
                'id' => (string) ($step['id'] ?? ''),
                'phase' => $phase,
                'status' => (string) ($step['status'] ?? ''),
                'command' => $command,
                'persist_command' => $persistCommand,
                'command_hash' => $command === '' ? '' : hash('sha256', $command),
                'persist_command_hash' => $persistCommand === '' ? '' : hash('sha256', $persistCommand),
                'placeholder_fields_to_replace' => $this->placeholderFields($command.' '.$persistCommand),
                'required_inputs' => (array) ($step['required_inputs'] ?? []),
                'missing_inputs' => (array) ($step['missing_inputs'] ?? []),
                'produced_artifacts' => (array) ($step['produced_artifacts'] ?? []),
                'stop_condition' => (string) ($step['stop_condition'] ?? ''),
                'forbidden_shortcuts' => (array) ($step['forbidden_shortcuts'] ?? []),
                'can_run_automatically' => false,
                'can_persist_from_runbook' => false,
            ];
        }

        $blockedArtifactIds = array_values(array_filter(
            array_keys($artifactVerificationMatrix),
            static fn (string $artifact): bool => (int) data_get($artifactVerificationMatrix, $artifact.'.blocker_count', 0) > 0
                || (int) data_get($artifactVerificationMatrix, $artifact.'.missing_count', 0) > 0,
        ));
        $runbook = [
            'schema_version' => 'atlas.self_construction.final_operator_execution_runbook.v1',
            'mode' => 'read_only_operator_execution_runbook',
            'status' => $completionAuditComplete ? 'completion_audit_complete' : 'blocked_operator_driven_steps_remaining',
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'current_step_phase' => (string) data_get($operatorNextAction, 'next_step_phase', ''),
            'current_step_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'current_step_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'step_count' => count($stepCards),
            'phase_count' => count($phases),
            'phases' => $phases,
            'blocked_artifact_ids' => $blockedArtifactIds,
            'blocked_artifact_count' => count($blockedArtifactIds),
            'step_cards' => $stepCards,
            'command_plan_hash' => $this->stableHash($operatorCommandPlan),
            'artifact_verification_matrix_hash' => $this->stableHash($artifactVerificationMatrix),
            'operator_closure_handoff_hash' => (string) data_get($operatorClosureHandoff, 'operator_closure_handoff_hash', ''),
            'operator_closure_command_replay_hash' => (string) data_get($operatorClosureCommandReplay, 'command_replay_hash', ''),
            'operator_closure_command_replay_current_step' => (string) data_get($operatorClosureCommandReplay, 'current_step', ''),
            'operator_closure_command_replay_step_count' => (int) data_get($operatorClosureCommandReplay, 'replay_step_count', 0),
            'proof_commands_after_each_step' => [
                'closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'resume_without_chat_history' => [
                'can_resume_without_chat_history' => (bool) data_get($operatorClosureHandoff, 'can_resume_without_chat_history', false),
                'resume_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'resumption_checkpoint_hash' => (string) data_get($operatorClosureHandoff, 'resumption_checkpoint_hash', ''),
                'requires_fresh_preflight_before_persist' => (bool) data_get($operatorClosureHandoff, 'requires_fresh_preflight_before_persist', false),
            ],
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'can_run_automatically' => false,
            'can_persist_from_runbook' => false,
            'can_call_provider_from_runbook' => false,
            'can_sign_for_operator_from_runbook' => false,
            'non_execution_guarantees' => [
                'operator_execution_runbook_does_not_execute_commands',
                'operator_execution_runbook_does_not_persist_receipts',
                'operator_execution_runbook_does_not_call_provider',
                'operator_execution_runbook_does_not_spend_tokens',
                'operator_execution_runbook_does_not_dispatch_work',
                'operator_execution_runbook_does_not_sign_for_operator',
                'operator_execution_runbook_does_not_promote_completion',
            ],
        ];
        $runbook['operator_execution_runbook_hash'] = $this->stableHash($runbook);

        return $runbook;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, string>  $operatorCommandPlan
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  list<string>  $blockingArtifacts
     * @return array<string, mixed>
     */
    private function operatorClosureHandoff(
        array $operatorNextAction,
        array $operatorCommandPlan,
        array $orderedOperatorPath,
        array $blockingArtifacts,
        array $completionAuditBlockerSummary,
        array $resumptionCheckpoint,
        array $operatorClosureCommandReplay,
        bool $completionAuditComplete,
    ): array {
        $commandSequence = array_values(array_map(
            static fn (array $step): array => [
                'id' => (string) ($step['id'] ?? ''),
                'phase' => (string) ($step['phase'] ?? ''),
                'status' => (string) ($step['status'] ?? ''),
                'command' => (string) ($step['command'] ?? ''),
                'persist_command' => (string) ($step['persist_command'] ?? ''),
                'can_run_automatically' => (bool) ($step['can_run_automatically'] ?? false),
                'missing_inputs' => (array) ($step['missing_inputs'] ?? []),
                'stop_condition' => (string) ($step['stop_condition'] ?? ''),
            ],
            $orderedOperatorPath,
        ));

        $handoff = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_handoff.v1',
            'mode' => 'read_only_operator_closure_handoff',
            'status' => $completionAuditComplete ? 'complete_candidate_ready_for_next_stage' : 'blocked_operator_action_required',
            'next_required_submission' => (string) ($operatorNextAction['next_required_submission'] ?? ''),
            'next_step_id' => (string) ($operatorNextAction['next_step_id'] ?? ''),
            'next_step_phase' => (string) ($operatorNextAction['next_step_phase'] ?? ''),
            'immediate_command' => (string) ($operatorNextAction['exact_command'] ?? ''),
            'immediate_persist_command' => (string) ($operatorNextAction['exact_persist_command'] ?? ''),
            'command_contains_placeholders' => (bool) ($operatorNextAction['command_contains_placeholders'] ?? false),
            'placeholder_fields_to_replace' => (array) ($operatorNextAction['placeholder_fields_to_replace'] ?? []),
            'can_run_automatically' => false,
            'why_not_automatic' => (string) ($operatorNextAction['why_not_automatic'] ?? 'requires_operator_or_provider_evidence'),
            'blocking_artifacts' => $blockingArtifacts,
            'blocking_artifact_count' => count($blockingArtifacts),
            'completion_audit_blocker_summary' => $completionAuditBlockerSummary,
            'resumption_checkpoint_hash' => (string) data_get($resumptionCheckpoint, 'resumption_checkpoint_hash', ''),
            'resumption_checkpoint_current_step' => (string) data_get($resumptionCheckpoint, 'current_step', ''),
            'resumption_checkpoint_exact_next_command' => (string) data_get($resumptionCheckpoint, 'exact_next_command', ''),
            'operator_closure_command_replay_hash' => (string) data_get($operatorClosureCommandReplay, 'command_replay_hash', ''),
            'operator_closure_command_replay_current_step' => (string) data_get($operatorClosureCommandReplay, 'current_step', ''),
            'operator_closure_command_replay_step_count' => (int) data_get($operatorClosureCommandReplay, 'replay_step_count', 0),
            'can_resume_without_chat_history' => (bool) data_get($resumptionCheckpoint, 'can_resume_without_chat_history', false),
            'requires_fresh_preflight_before_persist' => (bool) data_get($resumptionCheckpoint, 'requires_fresh_preflight_before_persist', false),
            'requires_human_operator' => $blockingArtifacts !== [],
            'requires_real_provider_smoke' => in_array('real_provider_smoke', $blockingArtifacts, true),
            'full_ordered_command_sequence' => $commandSequence,
            'workspace_flow' => [
                'template_pack_command' => (string) ($operatorCommandPlan['operator_evidence_artifact_template_pack'] ?? ''),
                'submission_readiness_command' => (string) ($operatorCommandPlan['operator_evidence_submission_readiness'] ?? ''),
                'finalize_hashes_command' => (string) ($operatorCommandPlan['finalize_operator_draft_workspace_hashes'] ?? ''),
                'publish_workspace_command' => (string) ($operatorCommandPlan['publish_finalized_operator_draft_workspace'] ?? ''),
                'requires_explicit_operator_publish' => true,
                'requires_explicit_operator_persistence' => true,
            ],
            'proof_commands_after_action' => (array) ($operatorNextAction['proof_commands_after_action'] ?? []),
            'success_predicate_after_all_actions' => (string) ($operatorNextAction['success_predicate_after_all_actions'] ?? 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0'),
            'forbidden_shortcuts' => (array) ($operatorNextAction['forbidden_shortcuts'] ?? []),
            'non_execution_guarantees' => [
                'operator_closure_handoff_does_not_execute_command',
                'operator_closure_handoff_does_not_persist_receipts',
                'operator_closure_handoff_does_not_call_provider',
                'operator_closure_handoff_does_not_spend_tokens',
                'operator_closure_handoff_does_not_dispatch_work',
                'operator_closure_handoff_does_not_sign_for_operator',
                'operator_closure_handoff_does_not_promote_completion',
            ],
        ];
        $handoff['operator_closure_handoff_hash'] = $this->stableHash($handoff);

        return $handoff;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @param  array<string, mixed>  $completionReceipt
     * @return array<string, mixed>
     */
    private function operatorSubmissionEnvelopes(
        array $options,
        array $completionAudit,
        array $completionEvidence,
        array $runtimeReceipt,
        array $realProviderSmoke,
        array $completionReceipt,
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $realProviderSmokePersistedBeforeHumanReceiptCommand,
        bool $humanReceiptReady,
    ): array {
        $runtimeEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.runtime_promotion_operator_submission_envelope.v1',
            artifact: 'runtime_promotion_receipt',
            status: $runtimeReceiptReady ? 'persisted_runtime_promotion_receipt' : ($runtimeReceipt === [] ? 'blocked_until_operator_receipt_or_draft_exists' : 'operator_payload_supplied_verify_with_runtime_promotion_endgame'),
            payload: $runtimeReceipt,
            hashField: 'receipt_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
        );
        $smokeEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.real_provider_smoke_operator_submission_envelope.v1',
            artifact: 'real_provider_smoke',
            status: $realProviderSmokeReady ? 'persisted_real_provider_smoke' : ($realProviderSmoke === [] ? 'blocked_until_operator_smoke_payload_exists' : 'operator_payload_supplied_verify_with_real_provider_smoke_endgame'),
            payload: $realProviderSmoke,
            hashField: 'smoke_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
        );
        $humanBlockedByPrereqs = ! $runtimeReceiptReady || ! $realProviderSmokeReady || ! $realProviderSmokePersistedBeforeHumanReceiptCommand;
        $humanEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.human_completion_receipt_operator_submission_envelope.v1',
            artifact: 'human_completion_receipt',
            status: $humanReceiptReady ? 'persisted_human_completion_receipt' : ($humanBlockedByPrereqs ? 'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green' : ($completionReceipt === [] ? 'blocked_until_human_completion_receipt_payload_exists' : 'operator_payload_supplied_verify_with_human_completion_receipt_closure_pack')),
            payload: $completionReceipt,
            hashField: 'receipt_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            currentEvidenceContext: [
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'runtime_gap_matrix_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', ''),
                'runtime_promotion_receipt_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', ''),
                'real_provider_smoke_persisted_before_human_receipt_command' => $realProviderSmokePersistedBeforeHumanReceiptCommand,
            ],
        );
        $statuses = [
            'runtime_promotion_receipt' => (string) data_get($runtimeEnvelope, 'status', 'missing'),
            'real_provider_smoke' => (string) data_get($smokeEnvelope, 'status', 'missing'),
            'human_completion_receipt' => (string) data_get($humanEnvelope, 'status', 'missing'),
        ];
        $ready = array_values(array_filter(
            $statuses,
            static fn (string $status): bool => str_starts_with($status, 'persisted_'),
        ));

        $payload = [
            'schema_version' => 'atlas.self_construction.final_operator_evidence_submission_envelopes.v1',
            'mode' => 'read_only_aggregated_operator_submission_envelopes',
            'status' => count($ready) === 3 ? 'all_required_operator_evidence_persisted' : 'blocked_until_all_required_operator_envelopes_are_ready',
            'runtime_promotion_receipt' => $runtimeEnvelope,
            'real_provider_smoke' => $smokeEnvelope,
            'human_completion_receipt' => $humanEnvelope,
            'envelope_statuses' => $statuses,
            'ready_envelope_count' => count($ready),
            'persisted_envelope_count' => count($ready),
            'required_envelope_count' => 3,
            'next_required_envelope' => $this->nextRequiredEnvelope($statuses),
            'source_option_keys' => array_values(array_filter([
                $runtimeReceipt !== [] ? 'runtime_promotion_receipt' : null,
                $realProviderSmoke !== [] ? 'real_provider_smoke' : null,
                $completionReceipt !== [] ? 'completion_receipt' : null,
                ($options['runtime_promotion_receipt_json'] ?? null) !== null ? 'runtime_promotion_receipt_json' : null,
                ($options['real_provider_smoke_json'] ?? null) !== null ? 'real_provider_smoke_json' : null,
                ($options['completion_receipt_json'] ?? null) !== null ? 'completion_receipt_json' : null,
            ])),
            'non_execution_guarantees' => [
                'aggregated_envelopes_do_not_sign_for_operator',
                'aggregated_envelopes_do_not_persist_receipts',
                'aggregated_envelopes_do_not_call_provider',
                'aggregated_envelopes_do_not_spend_tokens',
                'aggregated_envelopes_do_not_dispatch',
                'aggregated_envelopes_do_not_enable_runtime',
                'aggregated_envelopes_do_not_promote_completion',
            ],
        ];
        $payload['operator_submission_envelopes_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $currentEvidenceContext
     * @return array<string, mixed>
     */
    private function operatorEnvelopeSummary(
        string $schemaVersion,
        string $artifact,
        string $status,
        array $payload,
        string $hashField,
        string $persistCommand,
        string $detailedEndgameCommand,
        array $currentEvidenceContext = [],
    ): array {
        $json = $payload === []
            ? ''
            : (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = [
            'schema_version' => $schemaVersion,
            'mode' => 'read_only_operator_submission_envelope_summary',
            'artifact' => $artifact,
            'status' => $status,
            'payload_present' => $payload !== [],
            'payload_json_sha256' => $json === '' ? '' : hash('sha256', $json),
            'payload_hash' => (string) ($payload[$hashField] ?? ''),
            'hash_field' => $hashField,
            'current_evidence_context' => $currentEvidenceContext,
            'persist_command' => $persistCommand,
            'detailed_endgame_command' => $detailedEndgameCommand,
            'can_persist_from_corridor' => false,
            'non_execution_guarantees' => [
                'envelope_summary_does_not_verify_authority' => true,
                'envelope_summary_does_not_sign_for_operator' => true,
                'envelope_summary_does_not_persist_receipts' => true,
                'envelope_summary_does_not_call_provider' => true,
                'envelope_summary_does_not_spend_tokens' => true,
                'envelope_summary_does_not_dispatch' => true,
                'envelope_summary_does_not_enable_runtime' => true,
                'envelope_summary_does_not_promote_completion' => true,
            ],
        ];
        $envelope['envelope_summary_hash'] = $this->stableHash($envelope);

        return $envelope;
    }

    /** @param array<string, string> $statuses */
    private function nextRequiredEnvelope(array $statuses): string
    {
        foreach (['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'] as $key) {
            if (! str_starts_with(($statuses[$key] ?? ''), 'persisted_')) {
                return $key;
            }
        }

        return 'rerun_completion_audit';
    }

    /**
     * @param  array<string, mixed>  $blockerExplainer
     * @return list<array<string, mixed>>
     */
    private function orderedOperatorPath(
        array $blockerExplainer,
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $realProviderSmokePersistedBeforeHumanReceiptCommand,
        bool $humanReceiptReady,
        bool $completionAuditComplete,
        string $runtimeReceiptHash,
        string $realProviderSmokeHash,
        string $humanReceiptHash,
        string $completionAuditHash,
        bool $draftWorkspaceLoaded,
        bool $draftHashFinalizationRequired,
        string $draftHashFinalizationStatus,
        string $draftWorkspacePublisherStatus,
        int $draftWorkspacePublishableCount,
        int $draftWorkspacePublishedCount,
        bool $draftWorkspaceAtomicBundleReady,
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
        $finalizeWorkspaceHashes = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path=<workspace_path> --write-computed-operator-draft-hashes --json';
        $publishFinalizedWorkspace = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path=<workspace_path> --publish-operator-draft-workspace --json';

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
                status: $humanReceiptReady ? 'completed' : ($runtimeReceiptReady && $realProviderSmokeReady && $realProviderSmokePersistedBeforeHumanReceiptCommand ? 'ready_for_operator_input' : 'waiting_for_runtime_promotion_and_prior_persisted_real_provider_smoke'),
                requiredInputs: ['signed_by', 'reason', 'runtime_promotion_receipt_payload', 'real_provider_smoke_payload'],
                producedArtifacts: ['human_completion_receipt_draft', 'human_completion_receipt_hash_preimage'],
                verifierService: AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                command: $draftHumanReceipt,
                persistCommand: '',
                stopCondition: 'stop_if_human_receipt_drafted_before_runtime_and_prior_persisted_smoke_green',
                forbiddenShortcuts: ['drafting_human_receipt_before_runtime_and_prior_persisted_smoke_green', 'same_command_smoke_and_human_receipt_persistence', 'placeholder_completion_audit_hash'],
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
                id: 'finalize_operator_draft_workspace_hashes',
                phase: 'operator_workspace_hash_finalization',
                status: $this->draftHashFinalizationStepStatus(
                    runtimeReceiptReady: $runtimeReceiptReady,
                    realProviderSmokeReady: $realProviderSmokeReady,
                    humanReceiptReady: $humanReceiptReady,
                    draftWorkspaceLoaded: $draftWorkspaceLoaded,
                    draftHashFinalizationRequired: $draftHashFinalizationRequired,
                    draftHashFinalizationStatus: $draftHashFinalizationStatus,
                ),
                requiredInputs: ['operator_draft_workspace_path', 'operator_edited_runtime_promotion_receipt', 'operator_edited_real_provider_smoke', 'operator_edited_human_completion_receipt'],
                producedArtifacts: ['runtime_promotion_receipt_hash_written_to_draft', 'real_provider_smoke_hash_written_to_draft', 'human_completion_receipt_hash_written_to_draft'],
                verifierService: AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::class,
                command: $finalizeWorkspaceHashes,
                persistCommand: '',
                stopCondition: 'stop_if_any_draft_contains_placeholders_invalid_hashes_or_forbidden_flags',
                forbiddenShortcuts: ['persisting_drafts_before_hash_finalization', 'accepting_operator_generated_hash_without_recomputation'],
                evidenceHashesCurrentlyAvailable: array_values(array_filter([
                    $hashAvailableRuntime ? 'runtime_promotion_receipt_hash' : null,
                    $hashAvailableSmoke ? 'real_provider_smoke_hash' : null,
                    $hashAvailableHuman ? 'human_completion_receipt_hash' : null,
                ])),
                missingInputs: $this->draftHashFinalizationMissingInputs(
                    runtimeReceiptReady: $runtimeReceiptReady,
                    realProviderSmokeReady: $realProviderSmokeReady,
                    humanReceiptReady: $humanReceiptReady,
                    draftWorkspaceLoaded: $draftWorkspaceLoaded,
                    draftHashFinalizationRequired: $draftHashFinalizationRequired,
                ),
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'publish_finalized_operator_draft_workspace',
                phase: 'operator_workspace_submission_staging',
                status: $this->draftWorkspacePublisherStepStatus(
                    runtimeReceiptReady: $runtimeReceiptReady,
                    realProviderSmokeReady: $realProviderSmokeReady,
                    humanReceiptReady: $humanReceiptReady,
                    draftWorkspaceLoaded: $draftWorkspaceLoaded,
                    draftWorkspacePublisherStatus: $draftWorkspacePublisherStatus,
                    draftWorkspacePublishableCount: $draftWorkspacePublishableCount,
                    draftWorkspacePublishedCount: $draftWorkspacePublishedCount,
                    draftWorkspaceAtomicBundleReady: $draftWorkspaceAtomicBundleReady,
                ),
                requiredInputs: ['operator_draft_workspace_path', 'finalized_operator_draft_hashes'],
                producedArtifacts: ['runtime_promotion_submission_json', 'real_provider_smoke_submission_json', 'human_completion_receipt_submission_json'],
                verifierService: AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService::class,
                command: $publishFinalizedWorkspace,
                persistCommand: '',
                stopCondition: 'stop_if_any_finalized_draft_is_not_publishable_or_destination_path_is_not_allowed',
                forbiddenShortcuts: ['publishing_unfinalized_drafts', 'publishing_to_non_canonical_submission_paths', 'treating_published_submission_json_as_persisted_evidence'],
                evidenceHashesCurrentlyAvailable: array_values(array_filter([
                    $hashAvailableRuntime ? 'runtime_promotion_receipt_hash' : null,
                    $hashAvailableSmoke ? 'real_provider_smoke_hash' : null,
                    $hashAvailableHuman ? 'human_completion_receipt_hash' : null,
                ])),
                missingInputs: $this->draftWorkspacePublisherMissingInputs(
                    runtimeReceiptReady: $runtimeReceiptReady,
                    realProviderSmokeReady: $realProviderSmokeReady,
                    humanReceiptReady: $humanReceiptReady,
                    draftWorkspaceLoaded: $draftWorkspaceLoaded,
                    draftWorkspacePublishableCount: $draftWorkspacePublishableCount,
                    draftWorkspacePublishedCount: $draftWorkspacePublishedCount,
                    draftWorkspaceAtomicBundleReady: $draftWorkspaceAtomicBundleReady,
                ),
                canRunAutomatically: false,
            ),
            $this->pathStep(
                id: 'persist_human_completion_receipt_after_prerequisites_green',
                phase: 'human_completion_receipt',
                status: $humanReceiptReady ? 'completed' : 'waiting_for_runtime_and_prior_persisted_smoke_and_human_draft',
                requiredInputs: ['human_completion_receipt_payload', 'runtime_promotion_receipt_passed', 'real_provider_smoke_passed', 'real_provider_smoke_persisted_before_human_receipt_command'],
                producedArtifacts: ['human_completion_receipt_persisted_under_verifier'],
                verifierService: AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                command: '',
                persistCommand: $persistHumanReceipt,
                stopCondition: 'stop_if_verifier_returns_status_other_than_passed_or_smoke_was_not_persisted_in_prior_command',
                forbiddenShortcuts: ['persisting_before_runtime_and_prior_persisted_smoke_green', 'persisting_same_command_as_real_provider_smoke', 'persisting_before_verifier_passes'],
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

    private function draftHashFinalizationStepStatus(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        bool $draftHashFinalizationRequired,
        string $draftHashFinalizationStatus,
    ): string {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return 'completed';
        }
        if (! $draftWorkspaceLoaded) {
            return 'waiting_for_operator_edited_draft_workspace';
        }
        if ($draftHashFinalizationRequired) {
            return 'ready_to_finalize_operator_draft_workspace_hashes';
        }
        if (in_array($draftHashFinalizationStatus, ['ready_to_write_computed_hashes', 'partially_ready_to_write_computed_hashes'], true)) {
            return 'ready_for_operator_hash_finalization_review';
        }
        if ($draftHashFinalizationStatus === 'blocked_operator_drafts_not_ready_for_hash_write') {
            return 'blocked_until_operator_drafts_are_hashable';
        }

        return 'operator_draft_workspace_loaded_no_hash_write_required';
    }

    /**
     * @return list<string>
     */
    private function draftHashFinalizationMissingInputs(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        bool $draftHashFinalizationRequired,
    ): array {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return [];
        }
        if (! $draftWorkspaceLoaded) {
            return ['operator_edited_draft_workspace_with_all_required_artifacts'];
        }
        if ($draftHashFinalizationRequired) {
            return ['operator_must_run_workspace_hash_finalizer_write_command'];
        }

        return ['operator_must_finish_draft_workspace_or_run_readiness'];
    }

    private function draftWorkspacePublisherStepStatus(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        string $draftWorkspacePublisherStatus,
        int $draftWorkspacePublishableCount,
        int $draftWorkspacePublishedCount,
        bool $draftWorkspaceAtomicBundleReady,
    ): string {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return 'completed';
        }
        if ($draftWorkspacePublisherStatus === 'operator_draft_workspace_published' || $draftWorkspacePublishedCount >= 3) {
            return 'completed_submission_json_staged';
        }
        if (! $draftWorkspaceLoaded) {
            return 'waiting_for_operator_edited_draft_workspace';
        }
        if ($draftWorkspaceAtomicBundleReady && $draftWorkspacePublishableCount >= 3) {
            return 'ready_to_publish_finalized_operator_draft_workspace';
        }
        if ($draftWorkspacePublishableCount > 0) {
            return 'blocked_until_all_operator_draft_artifacts_are_publishable';
        }

        return 'blocked_until_operator_draft_hashes_are_finalized';
    }

    /**
     * @return list<string>
     */
    private function draftWorkspacePublisherMissingInputs(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        int $draftWorkspacePublishableCount,
        int $draftWorkspacePublishedCount,
        bool $draftWorkspaceAtomicBundleReady,
    ): array {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return [];
        }
        if ($draftWorkspacePublishedCount >= 3) {
            return [];
        }
        if (! $draftWorkspaceLoaded) {
            return ['operator_edited_draft_workspace_with_all_required_artifacts'];
        }
        if ($draftWorkspaceAtomicBundleReady && $draftWorkspacePublishableCount >= 3) {
            return ['operator_must_run_draft_workspace_publisher_write_command'];
        }
        if ($draftWorkspacePublishableCount > 0) {
            return ['operator_must_make_all_three_draft_artifacts_publishable_before_atomic_publish'];
        }

        return ['operator_must_finalize_all_draft_hashes_before_publishing'];
    }

    /**
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  array<string, mixed>  $submissionPreflight
     * @param  array<string, string>  $operatorCommandPlan
     * @return array<string, mixed>
     */
    private function operatorNextAction(
        array $orderedOperatorPath,
        array $submissionPreflight,
        array $operatorCommandPlan,
        bool $completionAuditComplete,
    ): array {
        $nextRequiredSubmission = (string) data_get($submissionPreflight, 'next_required_submission', '');
        $stepId = match ($nextRequiredSubmission) {
            'runtime_promotion_receipt' => 'draft_runtime_promotion_receipt',
            'real_provider_smoke' => 'prepare_real_provider_smoke_offline_harness',
            'human_completion_receipt' => 'draft_human_completion_receipt',
            'final_completion_audit', 'rerun_completion_audit' => 'rerun_completion_audit',
            default => $completionAuditComplete ? 'promote_next_stage_only_after_completion_audit_complete' : 'rerun_completion_audit',
        };

        $step = collect($orderedOperatorPath)->firstWhere('id', $stepId) ?? [];
        $exactCommand = (string) data_get($step, 'command', '');
        $exactPersistCommand = (string) data_get($step, 'persist_command', '');
        if ($exactCommand === '' && isset($operatorCommandPlan[$stepId])) {
            $exactCommand = $operatorCommandPlan[$stepId];
        }

        $placeholderFields = $this->placeholderFields($exactCommand.' '.$exactPersistCommand);
        $automatic = (bool) data_get($step, 'can_run_automatically', false);
        $status = $completionAuditComplete
            ? 'completion_audit_complete_operator_may_review_next_stage'
            : ($automatic ? 'ready_for_safe_read_only_check' : 'blocked_operator_action_required');

        $payload = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action.v1',
            'status' => $status,
            'next_required_submission' => $nextRequiredSubmission,
            'next_step_id' => $stepId,
            'next_step_phase' => (string) data_get($step, 'phase', ''),
            'next_step_status' => (string) data_get($step, 'status', ''),
            'exact_command' => $exactCommand,
            'exact_persist_command' => $exactPersistCommand,
            'command_contains_placeholders' => $placeholderFields !== [],
            'placeholder_fields_to_replace' => $placeholderFields,
            'can_run_automatically' => $automatic,
            'why_not_automatic' => $automatic ? '' : $this->whyNextActionIsNotAutomatic($stepId),
            'required_inputs' => (array) data_get($step, 'required_inputs', []),
            'missing_inputs' => (array) data_get($step, 'missing_inputs', []),
            'produced_artifacts' => (array) data_get($step, 'produced_artifacts', []),
            'verifier_service' => (string) data_get($step, 'verifier_service', ''),
            'stop_condition' => (string) data_get($step, 'stop_condition', ''),
            'forbidden_shortcuts' => (array) data_get($step, 'forbidden_shortcuts', []),
            'proof_commands_after_action' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'success_predicate_after_all_actions' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'next_action_projection_does_not_execute_command',
                'next_action_projection_does_not_persist_receipts',
                'next_action_projection_does_not_call_provider',
                'next_action_projection_does_not_spend_tokens',
                'next_action_projection_does_not_promote_completion',
            ],
        ];
        $payload['operator_next_action_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return list<string> */
    private function placeholderFields(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[0] ?? [])));
    }

    private function whyNextActionIsNotAutomatic(string $stepId): string
    {
        return match ($stepId) {
            'draft_runtime_promotion_receipt',
            'persist_runtime_promotion_receipt_after_verifier_passes' => 'requires_operator_signature_and_runtime_promotion_judgment',
            'prepare_real_provider_smoke_offline_harness' => 'operator_must_review_before_any_real_provider_activity',
            'draft_real_provider_smoke_payload',
            'persist_real_provider_smoke_after_verifier_passes' => 'requires_operator_observed_real_provider_evidence',
            'draft_human_completion_receipt',
            'persist_human_completion_receipt_after_prerequisites_green',
            'promote_next_stage_only_after_completion_audit_complete' => 'requires_human_completion_judgment_and_signed_receipt',
            default => 'requires_operator_review_or_external_evidence',
        };
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     */
    private function criterionPassed(array $completionAudit, string $criterionId): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) data_get($criterion, 'id') === $criterionId) {
                return (bool) data_get($criterion, 'passed', false);
            }
        }

        return false;
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
        $payload = $this->stripVolatileKeys($payload);
        unset($payload['generated_at'], $payload['closure_corridor_hash'], $payload['operator_submission_envelopes_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripVolatileKeys(array $payload): array
    {
        foreach ([
            'generated_at',
            'audited_at',
            'verified_at',
            'certified_at',
            'persisted_at',
            'assessed_at',
            'closure_corridor_hash',
            'operator_submission_envelopes_hash',
        ] as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripVolatileKeys($value);
            }
        }

        return $payload;
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
