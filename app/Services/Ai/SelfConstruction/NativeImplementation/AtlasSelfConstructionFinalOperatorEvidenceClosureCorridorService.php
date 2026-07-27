<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;
use App\Services\Ai\SelfConstruction\Support\FinalOperatorClosureCorridorHashSupport;
use App\Services\Ai\SelfConstruction\Support\FinalOperatorEvidenceSubmissionEnvelopeBuilder;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\FinalOperatorClosureCommandSurfaceCollector;
use App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor\EvidenceClosureCorridorSupport;
use App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor\OperatorClosureSection;
use App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor\OperatorNextActionSection;

final class AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.final_operator_evidence_closure_corridor.v1';

    public const MODE = 'read_only_final_operator_evidence_closure_corridor';

    private ?FinalOperatorClosureCommandSurfaceCollector $commandSurfaceCollectorInstance = null;

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
        $terminalLoopClosureProof = (array) data_get($submissionPreflight, 'terminal_loop_closure_proof', []);
        $submissionReadiness = $this->safeCall(fn () => (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService($this->readiness))->build($options));
        $finalEvidenceBundle = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionFinalEvidenceBundleStatus($options));
        $closureArtifactSequence = (array) data_get($submissionPreflight, 'closure_artifact_sequence', []);
        $promptToArtifactChecklist = (array) data_get($submissionPreflight, 'prompt_to_artifact_checklist', []);
        $runtimeReceiptInput = (array) ($options['runtime_promotion_receipt'] ?? []);
        $realProviderSmokeInput = (array) ($options['real_provider_smoke'] ?? []);
        $completionReceiptInput = (array) ($options['completion_receipt'] ?? []);
        $draftWorkspaceLoaded = (string) data_get($submissionReadiness, 'draft_workspace_input.status', '') === 'loaded_for_read_only_submission_readiness';
        $draftHashFinalizationRequired = (bool) data_get($submissionReadiness, 'draft_hash_finalization_required', false);
        $draftHashFinalizationStatus = (string) data_get($submissionReadiness, 'draft_hash_finalization.status', 'not_requested');
        $operatorDraftWorkspacePath = $draftWorkspaceLoaded
            ? $this->privateStorageAppPath((string) data_get($submissionReadiness, 'draft_workspace_input.workspace_directory', ''))
            : '<workspace_path>';
        $draftWorkspacePublisherOptions = $draftWorkspaceLoaded
            ? array_replace($options, ['operator_draft_workspace_path' => $operatorDraftWorkspacePath])
            : $options;
        $draftWorkspacePublisher = $this->draftWorkspacePublisherPreview($draftWorkspacePublisherOptions);
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
            operatorDraftWorkspacePath: $operatorDraftWorkspacePath,
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
            'rerun_completion_audit' => (string) data_get($terminalLoopClosureProof, 'audit_command_with_binding', $this->completionAuditWithTerminalLoopOperationalProofCommand()),
            'effective_rerun_completion_audit' => (string) data_get($terminalLoopClosureProof, 'effective_audit_command_with_binding', $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
            'operator_evidence_submission_readiness' => $draftWorkspaceLoaded
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --json'
                : 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            'operator_evidence_artifact_template_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --persist-operator-draft-workspace --json',
            'finalize_operator_draft_workspace_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --write-computed-operator-draft-hashes --json',
            'publish_finalized_operator_draft_workspace' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --publish-operator-draft-workspace --json',
            'refresh_terminal_loop_operational_proof' => (string) data_get($terminalLoopClosureProof, 'proof_command', 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json'),
            'persist_terminal_loop_operational_proof_binding' => (string) data_get($terminalLoopClosureProof, 'proof_binding_persist_command', 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json'),
            'rerun_completion_audit_with_terminal_loop_operational_proof' => (string) data_get($terminalLoopClosureProof, 'audit_command_with_binding', 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json'),
            'rerun_completion_audit_with_canonical_terminal_loop_operational_proof' => (string) data_get($terminalLoopClosureProof, 'audit_command_with_canonical_binding', $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
            'effective_rerun_completion_audit_with_terminal_loop_operational_proof' => (string) data_get($terminalLoopClosureProof, 'effective_audit_command_with_binding', $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
        ];
        $closureCorridorStatusCommand = $this->closureCorridorStatusCommand($options);
        $blockingArtifacts = array_values(array_filter([
            $runtimeReceiptReady ? null : 'runtime_promotion_receipt',
            $realProviderSmokeReady ? null : 'real_provider_smoke',
            $humanReceiptReady ? null : 'human_completion_receipt',
        ]));
        $operatorNextAction = $this->operatorNextAction(
            orderedOperatorPath: $orderedOperatorPath,
            submissionPreflight: $submissionPreflight,
            operatorCommandPlan: $operatorCommandPlan,
            closureCorridorStatusCommand: $closureCorridorStatusCommand,
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
                'schema_version' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'mode' => AtlasSelfConstructionHumanSignedCompletionReceiptService::MODE,
                'draft_service' => AtlasSelfConstructionHumanCompletionReceiptDraftService::class,
                'hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'verifier_service' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                'verifier_schema_version' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::SCHEMA_VERSION,
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
            closureCorridorStatusCommand: $closureCorridorStatusCommand,
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
            closureCorridorStatusCommand: $closureCorridorStatusCommand,
        );
        $operatorFailureRecoveryMatrix = $this->operatorFailureRecoveryMatrix($operatorNextActionShellPacket);
        $operatorNextActionReadinessGate = $this->operatorNextActionReadinessGate(
            operatorNextActionShellPacket: $operatorNextActionShellPacket,
            operatorFailureRecoveryMatrix: $operatorFailureRecoveryMatrix,
            operatorCompletionProgressMeter: $operatorCompletionProgressMeter,
        );
        $externalCompletionClaimPolicy = $this->externalCompletionClaimPolicy(
            completionAudit: $completionAudit,
            completionAuditComplete: $completionAuditComplete,
        );

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
                'prompt_to_artifact_checklist' => (array) data_get($completionAudit, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($completionAudit, 'checklist_count', count((array) data_get($completionAudit, 'prompt_to_artifact_checklist', []))),
            ],
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => (string) data_get($submissionPreflight, 'closure_artifact_sequence_hash', ''),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => (int) data_get($submissionPreflight, 'prompt_to_artifact_checklist_passed_count', 0),
            'prompt_to_artifact_checklist_hash' => (string) data_get($submissionPreflight, 'prompt_to_artifact_checklist_hash', ''),
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
                'workspace_cli_path' => $this->storageAppPath((string) data_get($submissionReadiness, 'draft_workspace_input.workspace_directory', '')),
                'workspace_private_storage_path' => $this->privateStorageAppPath((string) data_get($submissionReadiness, 'draft_workspace_input.workspace_directory', '')),
                'manifest_cli_path' => $this->storageAppPath((string) data_get($submissionReadiness, 'draft_workspace_input.manifest_path', '')),
                'manifest_private_storage_path' => $this->privateStorageAppPath((string) data_get($submissionReadiness, 'draft_workspace_input.manifest_path', '')),
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
                'draft_workspace_publish_command' => (string) data_get($draftWorkspacePublisher, 'publish_command', 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --publish-operator-draft-workspace --json'),
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
            'terminal_loop_closure_proof' => $terminalLoopClosureProof,
            'operator_failure_recovery_matrix' => $operatorFailureRecoveryMatrix,
            'operator_next_action_readiness_gate' => $operatorNextActionReadinessGate,
            'external_completion_claim_policy' => $externalCompletionClaimPolicy,
            'operator_command_surface_integrity' => $this->operatorCommandSurfaceIntegrity([
                'operator_command_plan' => $operatorCommandPlan,
                'operator_closure_command_replay' => $operatorClosureCommandReplay,
                'operator_next_action' => $operatorNextAction,
                'operator_closure_handoff' => $operatorClosureHandoff,
                'operator_execution_runbook' => $operatorExecutionRunbook,
                'operator_next_action_shell_packet' => $operatorNextActionShellPacket,
                'terminal_loop_closure_proof' => $terminalLoopClosureProof,
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

    /** @param array<string, mixed> $options */
    private function draftWorkspacePublisherPreview(array $options): array
    {
        return $this->safeCall(fn () => (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish(
            array_replace($options, ['publish_operator_draft_workspace' => false]),
        ));
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    private function externalCompletionClaimPolicy(array $completionAudit, bool $completionAuditComplete): array
    {
        return $this->operatorClosureSection()->externalCompletionClaimPolicy($completionAudit, $completionAuditComplete);
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @return array<string, mixed>
     */
    private function operatorNextActionShellPacket(
        array $operatorNextAction,
        array $operatorCompletionProgressMeter,
        string $closureCorridorStatusCommand,
    ): array {
        return $this->operatorNextActionSection()->operatorNextActionShellPacket($operatorNextAction, $operatorCompletionProgressMeter, $closureCorridorStatusCommand);
    }

    /**
     * @param  array<string, mixed>  $operatorNextActionShellPacket
     * @return array<string, mixed>
     */
    private function operatorFailureRecoveryMatrix(array $operatorNextActionShellPacket): array
    {
        return $this->operatorClosureSection()->operatorFailureRecoveryMatrix($operatorNextActionShellPacket);
    }

    /**
     * @param  array<string, mixed>  $operatorNextActionShellPacket
     * @param  array<string, mixed>  $operatorFailureRecoveryMatrix
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @return array<string, mixed>
     */
    private function operatorNextActionReadinessGate(
        array $operatorNextActionShellPacket,
        array $operatorFailureRecoveryMatrix,
        array $operatorCompletionProgressMeter,
    ): array {
        return $this->operatorNextActionSection()->operatorNextActionReadinessGate($operatorNextActionShellPacket, $operatorFailureRecoveryMatrix, $operatorCompletionProgressMeter);
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
        string $closureCorridorStatusCommand,
    ): array {
        return $this->operatorNextActionSection()->operatorNextActionResumeAfterInterruption($operatorNextAction, $operatorCompletionProgressMeter, $proofCommands, $closureCorridorStatusCommand);
    }

    /**
     * @param  list<string>  $placeholderFields
     * @return list<array<string, mixed>>
     */
    private function placeholderReplacementContract(array $placeholderFields): array
    {
        return $this->operatorNextActionSection()->placeholderReplacementContract($placeholderFields);
    }

    /**
     * @return list<array<string, string>>
     */
    private function postActionSuccessChecks(string $nextRequiredSubmission): array
    {
        return $this->operatorNextActionSection()->postActionSuccessChecks($nextRequiredSubmission);
    }

    /**
     * @param  list<string>  $proofCommands
     * @return array<string, mixed>
     */
    private function postActionVerificationBundle(
        string $nextRequiredSubmission,
        array $proofCommands,
        string $closureCorridorStatusCommand,
    ): array {
        return $this->operatorNextActionSection()->postActionVerificationBundle($nextRequiredSubmission, $proofCommands, $closureCorridorStatusCommand);
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
        return $this->operatorClosureSection()->operatorCompletionProgressMeter($completionAudit, $artifactVerificationMatrix, $operatorNextAction, $completionAuditComplete);
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
        return $this->commandSurfaceCollector()->operatorCommandSurfaceIntegrity($surface);
    }

    /**
     * @param  array<string, string>  $commands
     * @return array<string, string>
     */
    private function uniqueCommandsByText(array $commands): array
    {
        return $this->commandSurfaceCollector()->uniqueCommandsByText($commands);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    private function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        return $this->commandSurfaceCollector()->collectOperatorCommands($value, $path);
    }

    /**
     * @return list<string>
     */
    private function extractCommandOptions(string $command): array
    {
        return $this->commandSurfaceCollector()->extractCommandOptions($command);
    }

    /**
     * @return list<string>
     */
    private function selfConstructionCommandOptions(): array
    {
        return $this->commandSurfaceCollector()->selfConstructionCommandOptions();
    }

    /**
     * @return list<string>
     */
    private function legacySelfConstructionCommandAliases(): array
    {
        return $this->commandSurfaceCollector()->legacySelfConstructionCommandAliases();
    }

    private function commandSurfaceCollector(): FinalOperatorClosureCommandSurfaceCollector
    {
        return $this->commandSurfaceCollectorInstance ??= new FinalOperatorClosureCommandSurfaceCollector;
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
        return $this->operatorClosureSection()->closureReadinessSummary($completionAudit, $blockingArtifacts, $operatorNextAction, $completionAuditComplete);
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
        string $closureCorridorStatusCommand,
        bool $completionAuditComplete,
    ): array {
        return $this->operatorClosureSection()->operatorExecutionRunbook($operatorNextAction, $operatorClosureHandoff, $orderedOperatorPath, $operatorCommandPlan, $artifactVerificationMatrix, $operatorClosureCommandReplay, $closureCorridorStatusCommand, $completionAuditComplete);
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
        return $this->operatorClosureSection()->operatorClosureHandoff($operatorNextAction, $operatorCommandPlan, $orderedOperatorPath, $blockingArtifacts, $completionAuditBlockerSummary, $resumptionCheckpoint, $operatorClosureCommandReplay, $completionAuditComplete);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     /**
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
         return $this->submissionEnvelopeBuilder()->operatorSubmissionEnvelopes(
             $options, $completionAudit, $completionEvidence, $runtimeReceipt, $realProviderSmoke, $completionReceipt,
             $runtimeReceiptReady, $realProviderSmokeReady, $realProviderSmokePersistedBeforeHumanReceiptCommand, $humanReceiptReady,
         );
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
         return $this->submissionEnvelopeBuilder()->operatorEnvelopeSummary(
             $schemaVersion, $artifact, $status, $payload, $hashField, $persistCommand, $detailedEndgameCommand, $currentEvidenceContext,
         );
     }

     /** @param array<string, string> $statuses */
     private function nextRequiredEnvelope(array $statuses): string
     {
         return $this->submissionEnvelopeBuilder()->nextRequiredEnvelope($statuses);
     }

     /**
      * ITEM8 — lazy accessor for {@see FinalOperatorEvidenceSubmissionEnvelopeBuilder}. The new
      * collaborator holds the three extracted operator-submission-envelope helpers verbatim; the
      * god-class keeps its private methods as thin delegators so the existing call sites (inside
      * `build()` and `orderedOperatorPath`) stay byte-identical. Lazy-instantiated per call so
      * production callers pay no construction cost beyond the first use.
      */
     private function submissionEnvelopeBuilder(): FinalOperatorEvidenceSubmissionEnvelopeBuilder
     {
         return new FinalOperatorEvidenceSubmissionEnvelopeBuilder;
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
        string $operatorDraftWorkspacePath,
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
        $completionAuditCommand = (string) data_get(
            $blockerExplainer,
            'command_plan.completion_audit_with_terminal_loop_operational_proof',
            $this->completionAuditWithTerminalLoopOperationalProofCommand(),
        );
        $finalizeWorkspaceHashes = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --write-computed-operator-draft-hashes --json';
        $publishFinalizedWorkspace = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --publish-operator-draft-workspace --json';

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
        return FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(
            $runtimeReceiptReady, $realProviderSmokeReady, $humanReceiptReady, $draftWorkspaceLoaded, $draftHashFinalizationRequired, $draftHashFinalizationStatus,
        );
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
        return FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(
            $runtimeReceiptReady, $realProviderSmokeReady, $humanReceiptReady, $draftWorkspaceLoaded, $draftHashFinalizationRequired,
        );
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
        return FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(
            $runtimeReceiptReady, $realProviderSmokeReady, $humanReceiptReady, $draftWorkspaceLoaded, $draftWorkspacePublisherStatus, $draftWorkspacePublishableCount, $draftWorkspacePublishedCount, $draftWorkspaceAtomicBundleReady,
        );
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
        return FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(
            $runtimeReceiptReady, $realProviderSmokeReady, $humanReceiptReady, $draftWorkspaceLoaded, $draftWorkspacePublishableCount, $draftWorkspacePublishedCount, $draftWorkspaceAtomicBundleReady,
        );
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
        string $closureCorridorStatusCommand,
        bool $completionAuditComplete,
    ): array {
        return $this->operatorNextActionSection()->operatorNextAction($orderedOperatorPath, $submissionPreflight, $operatorCommandPlan, $closureCorridorStatusCommand, $completionAuditComplete);
    }

    /** @return list<string> */
    private function placeholderFields(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[0] ?? [])));
    }

    private function whyNextActionIsNotAutomatic(string $stepId): string
    {
        return $this->operatorNextActionSection()->whyNextActionIsNotAutomatic($stepId);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     */
    private function criterionPassed(array $completionAudit, string $criterionId): bool
    {
        return $this->operatorClosureSection()->criterionPassed($completionAudit, $criterionId);
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

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json --json';
    }

    /** @param array<string, mixed> $options */
    private function closureCorridorStatusCommand(array $options): string
    {
        $command = 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status';

        $workspacePath = trim((string) ($options['operator_draft_workspace_path'] ?? ''));
        if ($workspacePath !== '') {
            $command .= ' --operator-draft-workspace-path='.$workspacePath;
        }

        $terminalLoopProof = trim((string) ($options['agent_control_plane_terminal_loop_operational_proof_json'] ?? ''));
        if ($terminalLoopProof !== '') {
            $command .= ' --agent-control-plane-terminal-loop-operational-proof-json='.$terminalLoopProof;
        }

        return $command.' --json';
    }

    private function storageAppPath(string $path): string
    {
        return FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher::storageAppPath($path);
    }

    private function privateStorageAppPath(string $path): string
    {
        return FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher::privateStorageAppPath($path);
    }

    /**
     * ITEM8 — lazy accessor for {@see FinalOperatorClosureCorridorHashSupport}. The new
     * collaborator holds the three extracted canonicalization/hashing helpers verbatim; the
     * god-class keeps its private methods as thin delegators so every emitted *_hash value
     * stays byte-identical. Lazy-instantiated per call so production callers pay no construction
     * cost beyond the first use.
     */
    private function hashSupport(): FinalOperatorClosureCorridorHashSupport
    {
        return new FinalOperatorClosureCorridorHashSupport;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return $this->hashSupport()->stableHash($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripVolatileKeys(array $payload): array
    {
        return $this->hashSupport()->stripVolatileKeys($payload);
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        return $this->hashSupport()->ksortRecursive($value);
    }

    private function evidenceClosureCorridorSupport(): EvidenceClosureCorridorSupport
    {
        return new EvidenceClosureCorridorSupport;
    }

    private function operatorNextActionSection(): OperatorNextActionSection
    {
        return new OperatorNextActionSection($this->evidenceClosureCorridorSupport());
    }

    private function operatorClosureSection(): OperatorClosureSection
    {
        return new OperatorClosureSection($this->evidenceClosureCorridorSupport());
    }
}
