<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Operator Evidence Artifact Template Pack v1.
 *
 * Read-only aggregation of the three operator-fillable templates (runtime
 * promotion receipt, real provider smoke preimage, human completion receipt)
 * with the *current* canonical context hashes the operator must reference and
 * the *current* preview hash of each unfilled template. It never persists,
 * never signs, never calls providers.
 */
final class AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_artifact_template_pack.v1';

    public const MODE = 'read_only_operator_evidence_artifact_template_pack';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/operator-evidence/template-pack-exports';

    private const DRAFT_WORKSPACE_PREFIX = 'atlas/self-construction/operator-submissions/draft-workspaces';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $persistExport = (bool) ($options['persist_export'] ?? false);
        $persistOperatorDraftWorkspace = (bool) ($options['persist_operator_draft_workspace'] ?? false);
        $completionAudit = (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit($options);
        $completionEvidence = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionOsCompletionEvidenceStatus($options));
        $blockerExplainer = (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit);
        $completionEvidenceSubmissionPreflight = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)
            ->build($completionAudit, $completionEvidence, $blockerExplainer);
        $operatorPacket = (array) data_get($completionAudit, 'operator_action_packet', []);
        $runtimeGapMatrix = (array) data_get($completionEvidence, 'runtime_gap_matrix', []);
        $releaseDossierHash = (string) data_get($operatorPacket, 'human_completion_receipt_template.release_dossier_hash', '');
        $replayDiffHash = (string) data_get($operatorPacket, 'human_completion_receipt_template.replay_diff_hash', '');
        $certificationStatusBatchHash = (string) data_get($operatorPacket, 'human_completion_receipt_template.certification_status_batch_hash', '');
        $runtimeGapMatrixHash = (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', '');
        $expectedRuntimeGapMatrixHashForPromotionReceipt = (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', '');
        $runtimePromotionBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', '');
        $runtimePromotionClosureBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', '');
        $runtimePromotionReceiptHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.receipt_hash', '');
        $realProviderSmokeHash = (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', '');
        $completionAuditHash = (string) data_get($completionAudit, 'completion_audit_hash', '');

        $runtimeTemplate = (array) data_get($operatorPacket, 'runtime_promotion_receipt_template', []);
        $smokeTemplate = (array) data_get($operatorPacket, 'real_provider_smoke_template', []);
        $humanTemplate = (array) data_get($operatorPacket, 'human_completion_receipt_template', []);

        $runtimeReceiptTemplate = [
            'kind' => 'runtime_promotion_receipt_template',
            'schema_version' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
            'template_hash' => (string) data_get($operatorPacket, 'template_hashes.runtime_promotion_receipt_template_hash', ''),
            'payload_template' => $runtimeTemplate,
            'required_fields' => ['receipt_id', 'signed_by', 'reason', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'promoted_gap_ids', 'graduation_evidence_hashes', 'receipt_hash'],
            'placeholder_fields' => $this->placeholderFields($runtimeTemplate),
            'fields_that_must_be_64_hex' => ['runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'receipt_hash'],
            'boolean_acknowledgements' => ['runtime_promotion_approved', 'operator_reviewed_runtime_graduations', 'no_runtime_autopromotion_acknowledged'],
            'forbidden_flags' => ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'],
            'command_to_compute_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            'command_to_verify' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            'command_to_persist' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'verifier_service' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
            'draft_service' => AtlasSelfConstructionRuntimePromotionReceiptDraftService::class,
            'runbook' => (new AtlasSelfConstructionRuntimePromotionReceiptRunbookService)->build($runtimeTemplate),
            'current_context_hashes' => [
                'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'promoted_gap_ids' => (array) data_get($runtimeTemplate, 'promoted_gap_ids', []),
                'graduation_evidence_hashes' => (array) data_get($runtimeTemplate, 'graduation_evidence_hashes', []),
                'runtime_promotion_receipt_hash_if_already_persisted' => $runtimePromotionReceiptHash,
            ],
        ];

        $smokePreimageTemplate = [
            'kind' => 'real_provider_smoke_preimage_template',
            'schema_version' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
            'template_hash' => (string) data_get($operatorPacket, 'template_hashes.real_provider_smoke_template_hash', ''),
            'payload_template' => $smokeTemplate,
            'required_fields' => ['kind', 'status', 'provider_run_id', 'task_packet_id', 'observed_by', 'approval_reason', 'smoke_hash'],
            'placeholder_fields' => $this->placeholderFields($smokeTemplate),
            'fields_that_must_be_64_hex' => ['smoke_hash', 'operator_approval_receipt_hash', 'evidence_ledger_hash', 'work_product_manifest_hash', 'cost_event_hash', 'continuation_summary_hash', 'provider_response_hash'],
            'boolean_acknowledgements' => ['provider_call_observed', 'token_spend_observed', 'claim_to_completion_observed', 'work_product_collected', 'operator_supplied_evidence', 'real_provider_run_observed_by_operator'],
            'forbidden_flags' => ['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_promoted_without_receipt'],
            'command_to_compute_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'command_to_verify' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'command_to_persist' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'verifier_service' => AtlasSelfConstructionRealProviderSmokeCertificationService::class,
            'draft_service' => AtlasSelfConstructionRealProviderSmokeDraftService::class,
            'offline_harness_service' => AtlasSelfConstructionRealProviderSmokeOfflineHarnessService::class,
            'runbook' => (new AtlasSelfConstructionRealProviderSmokeRunbookService)->build($smokeTemplate),
            'current_context_hashes' => [
                'real_provider_smoke_hash_if_already_persisted' => $realProviderSmokeHash,
            ],
        ];

        $humanReceiptTemplate = [
            'kind' => 'human_completion_receipt_template',
            'schema_version' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
            'template_hash' => (string) data_get($operatorPacket, 'template_hashes.human_completion_receipt_template_hash', ''),
            'payload_template' => $humanTemplate,
            'required_fields' => ['receipt_id', 'signed_by', 'reason', 'completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash', 'receipt_hash'],
            'placeholder_fields' => $this->placeholderFields($humanTemplate),
            'fields_that_must_be_64_hex' => ['completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash', 'receipt_hash'],
            'boolean_acknowledgements' => ['os_complete_approved', 'operator_reviewed_completion_audit', 'no_autopromotion_acknowledged'],
            'forbidden_flags' => ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted'],
            'command_to_compute_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            'command_to_verify' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            'command_to_persist' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'verifier_service' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
            'verifier_schema_version' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::SCHEMA_VERSION,
            'draft_service' => AtlasSelfConstructionHumanCompletionReceiptDraftService::class,
            'runbook' => (new AtlasSelfConstructionHumanCompletionReceiptRunbookService)->build($humanTemplate),
            'current_context_hashes' => [
                'completion_audit_hash' => $completionAuditHash,
                'release_dossier_hash' => $releaseDossierHash,
                'replay_diff_hash' => $replayDiffHash,
                'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
                'runtime_promotion_receipt_hash' => $runtimePromotionReceiptHash,
                'real_provider_smoke_hash' => $realProviderSmokeHash,
                'certification_status_batch_hash' => $certificationStatusBatchHash,
            ],
        ];

        $templates = [
            'runtime_promotion_receipt_template' => $runtimeReceiptTemplate,
            'real_provider_smoke_preimage_template' => $smokePreimageTemplate,
            'human_completion_receipt_template' => $humanReceiptTemplate,
        ];
        $operatorSubmissionBundle = $this->operatorSubmissionBundle($templates, $completionEvidenceSubmissionPreflight);
        $operatorSubmissionBundleMarkdown = $this->operatorSubmissionBundleMarkdown($operatorSubmissionBundle);
        $operatorSubmissionBundleMarkdownHash = hash('sha256', $operatorSubmissionBundleMarkdown);
        $operatorDraftWorkspace = $this->operatorDraftWorkspace($operatorSubmissionBundle, $persistOperatorDraftWorkspace);
        $exportPath = '';
        $persistedExport = false;
        if ($persistExport) {
            $timestamp = CarbonImmutable::now()->format('Ymd-His');
            $exportPath = self::STORAGE_PREFIX.'/operator-submission-bundle-'.$timestamp.'-'.substr($operatorSubmissionBundleMarkdownHash, 0, 12).'.md';
            Storage::disk(self::STORAGE_DISK)->put($exportPath, $operatorSubmissionBundleMarkdown);
            $persistedExport = true;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persistedExport ? 'exported' : 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'persist_export_requested' => $persistExport,
            'persist_operator_draft_workspace_requested' => $persistOperatorDraftWorkspace,
            'persist' => $persistedExport,
            'export_path' => $exportPath,
            'storage_disk' => self::STORAGE_DISK,
            'storage_prefix' => self::STORAGE_PREFIX,
            'draft_workspace_storage_prefix' => self::DRAFT_WORKSPACE_PREFIX,
            'template_count' => count($templates),
            'templates' => $templates,
            'completion_evidence_submission_preflight' => $completionEvidenceSubmissionPreflight,
            'operator_execution_plan' => (array) data_get($completionEvidenceSubmissionPreflight, 'operator_execution_plan', []),
            'operator_handoff_packet' => (array) data_get($completionEvidenceSubmissionPreflight, 'operator_handoff_packet', []),
            'operator_submission_bundle' => $operatorSubmissionBundle,
            'operator_submission_bundle_markdown' => $operatorSubmissionBundleMarkdown,
            'operator_submission_bundle_markdown_hash' => $operatorSubmissionBundleMarkdownHash,
            'operator_draft_workspace' => $operatorDraftWorkspace,
            'shared_context_hashes' => [
                'completion_audit_hash' => $completionAuditHash,
                'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'runtime_promotion_receipt_hash' => $runtimePromotionReceiptHash,
                'real_provider_smoke_hash' => $realProviderSmokeHash,
                'release_dossier_hash' => $releaseDossierHash,
                'replay_diff_hash' => $replayDiffHash,
                'certification_status_batch_hash' => $certificationStatusBatchHash,
            ],
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'operator_evidence_artifact_template_pack_does_not_persist_receipts',
                'operator_evidence_artifact_template_pack_does_not_sign_for_operator',
                'operator_evidence_artifact_template_pack_does_not_call_provider',
                'operator_evidence_artifact_template_pack_does_not_spend_tokens',
                'operator_evidence_artifact_template_pack_does_not_dispatch_work',
                'operator_evidence_artifact_template_pack_does_not_enable_runtime',
                'operator_evidence_artifact_template_pack_does_not_start_codex',
                'operator_evidence_artifact_template_pack_does_not_promote_completion',
                'operator_evidence_artifact_template_pack_does_not_write_operator_submission_bundle',
                'operator_evidence_artifact_template_pack_only_exports_markdown_when_persist_export_true',
                'operator_evidence_artifact_template_pack_only_exports_placeholder_drafts_when_persist_operator_draft_workspace_true',
                'operator_evidence_artifact_template_pack_export_is_not_completion_evidence',
            ],
        ];
        $payload['template_pack_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $bundle */
    private function operatorDraftWorkspace(array $bundle, bool $persistOperatorDraftWorkspace): array
    {
        $workspaceHash = substr((string) data_get($bundle, 'operator_submission_bundle_hash', hash('sha256', 'empty-bundle')), 0, 12);
        $workspaceDirectory = self::DRAFT_WORKSPACE_PREFIX.'/'.CarbonImmutable::now()->format('Ymd-His').'-'.$workspaceHash;
        $files = [];

        foreach ((array) data_get($bundle, 'artifacts', []) as $artifact) {
            $artifactName = (string) data_get($artifact, 'artifact', '');
            $draftFilename = match ($artifactName) {
                'runtime_promotion_receipt' => 'runtime-promotion.json',
                'real_provider_smoke' => 'real-provider-smoke.json',
                'human_completion_receipt' => 'completion-receipt.json',
                default => $artifactName.'.json',
            };
            $draftPath = $workspaceDirectory.'/'.$draftFilename;
            $draftJson = $this->prettyJson((array) data_get($artifact, 'payload_template', []));
            $draftSha = hash('sha256', $draftJson);
            $draftCliPath = 'storage/app/'.$draftPath;
            $draftPrivateStoragePath = 'storage/app/private/'.$draftPath;
            $workspacePrivateStoragePath = 'storage/app/private/'.$workspaceDirectory;
            $recommendedFilePath = (string) data_get($artifact, 'recommended_file_path', '');
            $isRuntimePromotionReceipt = $artifactName === 'runtime_promotion_receipt';

            $file = [
                'artifact' => $artifactName,
                'draft_path' => $draftPath,
                'draft_cli_path' => $draftCliPath,
                'draft_private_storage_path' => $draftPrivateStoragePath,
                'recommended_file_path' => $recommendedFilePath,
                'payload_template_json_sha256' => (string) data_get($artifact, 'payload_template_json_sha256', ''),
                'draft_file_sha256' => $draftSha,
                'sha256_matches_payload_template' => hash_equals((string) data_get($artifact, 'payload_template_json_sha256', ''), $draftSha),
                'command_to_finalize_workspace_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path='.$workspacePrivateStoragePath.' --write-computed-operator-draft-hashes --json',
                'command_to_compute_hash_draft_file' => $this->commandForSavedFile((string) data_get($artifact, 'command_to_compute_hash', ''), $draftPrivateStoragePath),
                'command_to_verify_draft_file' => $this->commandForSavedFile($this->commandWithoutPersistFlag((string) data_get($artifact, 'command_to_persist_saved_file', '')), $draftPrivateStoragePath),
                'copy_to_recommended_file_path_before_persisting' => ! $isRuntimePromotionReceipt,
                'draft_is_evidence' => false,
                'can_persist_draft_directly' => false,
            ];

            if ($isRuntimePromotionReceipt) {
                $file['command_to_finalize_draft_receipt_hash'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-draft-hash-finalizer-status --operator-draft-workspace-path='.$workspacePrivateStoragePath.' --write-computed-runtime-promotion-receipt-hash --json';
                $file['command_to_review_draft_file_with_endgame'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --operator-draft-workspace-path='.$workspacePrivateStoragePath.' --json';
                $file['command_to_persist_draft_file_with_endgame'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --operator-draft-workspace-path='.$workspacePrivateStoragePath.' --persist-runtime-promotion-receipt --json';
                $file['copy_not_required_reason'] = 'runtime_promotion_endgame_can_read_operator_draft_workspace_path_directly_after_operator_edits_and_hash_recompute';
            }

            $files[] = $file;

            if ($persistOperatorDraftWorkspace) {
                Storage::disk(self::STORAGE_DISK)->put($draftPath, $draftJson);
            }
        }

        $manifest = [
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'mode' => 'operator_placeholder_draft_workspace',
            'status' => $persistOperatorDraftWorkspace ? 'persisted_placeholder_drafts' : 'not_persisted',
            'workspace_directory' => $persistOperatorDraftWorkspace ? $workspaceDirectory : '',
            'workspace_cli_path' => $persistOperatorDraftWorkspace ? 'storage/app/'.$workspaceDirectory : '',
            'workspace_private_storage_path' => $persistOperatorDraftWorkspace ? 'storage/app/private/'.$workspaceDirectory : '',
            'manifest_cli_path' => $persistOperatorDraftWorkspace ? 'storage/app/'.$workspaceDirectory.'/manifest.json' : '',
            'manifest_private_storage_path' => $persistOperatorDraftWorkspace ? 'storage/app/private/'.$workspaceDirectory.'/manifest.json' : '',
            'artifact_count' => count($files),
            'files' => $files,
            'operator_required_next_steps' => [
                'replace_all_placeholders_with_real_operator_values',
                'follow_operator_execution_plan_order_without_parallel_submission',
                'review_operator_next_action_before_editing_or_persisting',
                'compute_canonical_hashes_after_editing',
                'run_operator_evidence_draft_hash_finalizer_against_the_draft_workspace_path',
                'publish_finalized_operator_drafts_to_recommended_submission_paths',
                'for_runtime_promotion_receipt_run_hash_finalizer_against_the_draft_workspace_path',
                'for_runtime_promotion_receipt_run_endgame_against_the_draft_workspace_path',
                'run_readiness_before_any_persistence',
                'persist_only_through_canonical_verifier_commands',
            ],
            'operator_execution_plan' => (array) data_get($bundle, 'operator_execution_plan', []),
            'operator_handoff_packet' => (array) data_get($bundle, 'operator_handoff_packet', []),
            'operator_next_action' => (array) data_get($bundle, 'operator_next_action', []),
            'submission_preflight_hash' => (string) data_get($bundle, 'submission_preflight_hash', ''),
            'next_required_submission' => (string) data_get($bundle, 'next_required_submission', ''),
            'command_to_publish_finalized_workspace' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path=storage/app/private/'.$workspaceDirectory.' --publish-operator-draft-workspace --json',
            'non_execution_guarantees' => [
                'draft_workspace_contains_placeholders_until_operator_edits',
                'draft_workspace_is_not_completion_evidence',
                'draft_workspace_is_not_a_receipt_registry',
                'draft_workspace_does_not_sign_for_operator',
                'draft_workspace_does_not_call_provider',
                'draft_workspace_does_not_spend_tokens',
                'draft_workspace_does_not_dispatch',
                'draft_workspace_does_not_enable_runtime',
                'draft_workspace_does_not_promote_completion',
                'draft_workspace_publisher_copies_only_finalized_json_and_never_persists_evidence',
            ],
        ];
        $manifest['manifest_hash'] = $this->stableHash($manifest);

        if ($persistOperatorDraftWorkspace) {
            Storage::disk(self::STORAGE_DISK)->put($workspaceDirectory.'/manifest.json', $this->prettyJson($manifest));
        }

        return [
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace_export.v1',
            'mode' => 'explicit_placeholder_draft_workspace_export',
            'status' => $persistOperatorDraftWorkspace ? 'persisted_placeholder_drafts' : 'not_requested',
            'persist_operator_draft_workspace_requested' => $persistOperatorDraftWorkspace,
            'persisted' => $persistOperatorDraftWorkspace,
            'storage_disk' => self::STORAGE_DISK,
            'workspace_directory' => $persistOperatorDraftWorkspace ? $workspaceDirectory : '',
            'manifest_path' => $persistOperatorDraftWorkspace ? $workspaceDirectory.'/manifest.json' : '',
            'workspace_cli_path' => $persistOperatorDraftWorkspace ? 'storage/app/'.$workspaceDirectory : '',
            'workspace_private_storage_path' => $persistOperatorDraftWorkspace ? 'storage/app/private/'.$workspaceDirectory : '',
            'manifest_cli_path' => $persistOperatorDraftWorkspace ? 'storage/app/'.$workspaceDirectory.'/manifest.json' : '',
            'manifest_private_storage_path' => $persistOperatorDraftWorkspace ? 'storage/app/private/'.$workspaceDirectory.'/manifest.json' : '',
            'manifest' => $manifest,
            'can_persist_completion_evidence_from_draft_workspace' => false,
            'can_promote_completion_from_draft_workspace' => false,
            'operator_draft_workspace_hash' => $this->stableHash($manifest),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $templates
     * @param  array<string, mixed>  $completionEvidenceSubmissionPreflight
     * @return array<string, mixed>
     */
    private function operatorSubmissionBundle(array $templates, array $completionEvidenceSubmissionPreflight): array
    {
        $operatorNextAction = $this->operatorNextActionFromPreflight($completionEvidenceSubmissionPreflight);
        $artifacts = [
            $this->bundleArtifact(
                artifact: 'runtime_promotion_receipt',
                sequence: 1,
                template: $templates['runtime_promotion_receipt_template'],
                filePath: 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
                sourceOption: 'runtime_promotion_receipt',
                blocks: ['runtime_gap_matrix_all_runtime_y'],
                commandToVerify: (string) data_get($templates, 'runtime_promotion_receipt_template.command_to_verify', ''),
                commandToPersist: (string) data_get($templates, 'runtime_promotion_receipt_template.command_to_persist', ''),
                commandToComputeHash: (string) data_get($templates, 'runtime_promotion_receipt_template.command_to_compute_hash', ''),
            ),
            $this->bundleArtifact(
                artifact: 'real_provider_smoke',
                sequence: 2,
                template: $templates['real_provider_smoke_preimage_template'],
                filePath: 'storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                sourceOption: 'real_provider_smoke',
                blocks: ['end_to_end_real_provider_smoke_green'],
                commandToVerify: (string) data_get($templates, 'real_provider_smoke_preimage_template.command_to_verify', ''),
                commandToPersist: (string) data_get($templates, 'real_provider_smoke_preimage_template.command_to_persist', ''),
                commandToComputeHash: (string) data_get($templates, 'real_provider_smoke_preimage_template.command_to_compute_hash', ''),
            ),
            $this->bundleArtifact(
                artifact: 'human_completion_receipt',
                sequence: 3,
                template: $templates['human_completion_receipt_template'],
                filePath: 'storage/app/atlas/self-construction/operator-submissions/completion-receipt.json',
                sourceOption: 'completion_receipt',
                blocks: ['human_signed_os_complete_receipt_present'],
                commandToVerify: (string) data_get($templates, 'human_completion_receipt_template.command_to_verify', ''),
                commandToPersist: (string) data_get($templates, 'human_completion_receipt_template.command_to_persist', ''),
                commandToComputeHash: (string) data_get($templates, 'human_completion_receipt_template.command_to_compute_hash', ''),
            ),
        ];

        $bundle = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_bundle.v1',
            'mode' => 'read_only_operator_submission_bundle_manifest',
            'status' => 'available',
            'artifact_count' => count($artifacts),
            'recommended_directory' => 'storage/app/atlas/self-construction/operator-submissions',
            'artifact_sequence' => array_column($artifacts, 'artifact'),
            'artifacts' => $artifacts,
            'submission_preflight_status' => (string) data_get($completionEvidenceSubmissionPreflight, 'status', ''),
            'submission_preflight_hash' => (string) data_get($completionEvidenceSubmissionPreflight, 'submission_preflight_hash', ''),
            'next_required_submission' => (string) data_get($completionEvidenceSubmissionPreflight, 'next_required_submission', ''),
            'next_required_command' => (string) data_get($completionEvidenceSubmissionPreflight, 'next_required_command', ''),
            'next_required_persist_command' => (string) data_get($completionEvidenceSubmissionPreflight, 'next_required_persist_command', ''),
            'ordered_steps' => (array) data_get($completionEvidenceSubmissionPreflight, 'ordered_steps', []),
            'completion_audit_blocker_summary' => (array) data_get($completionEvidenceSubmissionPreflight, 'completion_audit_blocker_summary', []),
            'operator_execution_plan' => (array) data_get($completionEvidenceSubmissionPreflight, 'operator_execution_plan', []),
            'operator_handoff_packet' => (array) data_get($completionEvidenceSubmissionPreflight, 'operator_handoff_packet', []),
            'operator_next_action' => $operatorNextAction,
            'bundle_usage_order' => [
                'fill_runtime_promotion_receipt_from_current_template',
                'compute_runtime_promotion_receipt_hash_and_verify',
                'persist_runtime_promotion_receipt_with_explicit_flag',
                'rerun_runtime_gap_matrix_and_completion_evidence',
                'run_operator_approved_real_provider_smoke_outside_this_read_only_pack',
                'fill_real_provider_smoke_payload_from_observed_evidence',
                'compute_real_provider_smoke_hash_and_verify',
                'persist_real_provider_smoke_with_explicit_flag',
                'rerun_completion_evidence_and_completion_audit',
                'fill_human_completion_receipt_only_after_runtime_and_smoke_are_green',
                'compute_human_completion_receipt_hash_and_verify',
                'persist_human_completion_receipt_with_explicit_flag',
                'refresh_terminal_loop_operational_proof',
                'rerun_completion_audit_with_terminal_loop_operational_proof',
                'rerun_completion_audit_and_finalization_gate',
            ],
            'global_verify_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            'global_submission_preflight_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
            'global_completion_evidence_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'global_completion_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'global_terminal_loop_operational_proof_command' => $this->terminalLoopOperationalProofCommand(),
            'global_completion_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'operator_must_follow_order' => (bool) data_get($completionEvidenceSubmissionPreflight, 'operator_execution_plan.operator_must_follow_order', true),
            'parallel_submission_allowed' => (bool) data_get($completionEvidenceSubmissionPreflight, 'operator_execution_plan.parallel_submission_allowed', false),
            'final_success_predicate' => (string) data_get($completionEvidenceSubmissionPreflight, 'operator_execution_plan.final_success_predicate', ''),
            'can_write_files_from_template_pack' => false,
            'can_persist_from_template_pack' => false,
            'non_execution_guarantees' => [
                'operator_submission_bundle_manifest_does_not_write_files',
                'operator_submission_bundle_manifest_does_not_persist_receipts',
                'operator_submission_bundle_manifest_does_not_persist_smoke',
                'operator_submission_bundle_manifest_does_not_sign_for_operator',
                'operator_submission_bundle_manifest_does_not_call_provider',
                'operator_submission_bundle_manifest_does_not_spend_tokens',
                'operator_submission_bundle_manifest_does_not_dispatch',
                'operator_submission_bundle_manifest_does_not_promote_completion',
            ],
        ];
        $bundle['operator_submission_bundle_hash'] = $this->stableHash($bundle);

        return $bundle;
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    /** @param array<string, mixed> $completionEvidenceSubmissionPreflight */
    private function operatorNextActionFromPreflight(array $completionEvidenceSubmissionPreflight): array
    {
        $handoff = (array) data_get($completionEvidenceSubmissionPreflight, 'operator_handoff_packet', []);
        $command = (string) data_get($handoff, 'next_draft_or_check_command', '');
        $persistCommand = (string) data_get($handoff, 'next_persist_command', '');
        $currentStep = (string) data_get($handoff, 'current_step', '');
        $placeholders = $this->placeholderFieldsFromCommand($command.' '.$persistCommand);

        $nextAction = [
            'schema_version' => 'atlas.self_construction.operator_evidence_template_pack_next_action.v1',
            'status' => (string) data_get($completionEvidenceSubmissionPreflight, 'status', 'unknown'),
            'current_step' => $currentStep,
            'current_status' => (string) data_get($handoff, 'current_status', ''),
            'next_required_submission' => (string) data_get($completionEvidenceSubmissionPreflight, 'next_required_submission', ''),
            'exact_command' => $command,
            'exact_persist_command' => $persistCommand,
            'command_contains_placeholders' => $placeholders !== [],
            'placeholder_fields_to_replace' => $placeholders,
            'required_operator_inputs' => (array) data_get($handoff, 'required_operator_inputs', []),
            'current_blocker_count' => (int) data_get($handoff, 'current_blocker_count', 0),
            'current_blockers' => (array) data_get($handoff, 'current_blockers', []),
            'current_blocks_completion_criteria' => (array) data_get($handoff, 'current_blocks_completion_criteria', []),
            'current_completion_blockers_classified' => (array) data_get($handoff, 'current_completion_blockers_classified', []),
            'completion_audit_blocker_summary' => (array) data_get($handoff, 'completion_audit_blocker_summary', []),
            'operator_must_follow_order' => (bool) data_get($handoff, 'operator_must_follow_order', true),
            'parallel_submission_allowed' => (bool) data_get($handoff, 'parallel_submission_allowed', false),
            'proof_commands_after_action' => (array) data_get($handoff, 'proof_commands_after_each_persist', []),
            'handoff_stop_conditions' => (array) data_get($handoff, 'handoff_stop_conditions', []),
            'final_success_command' => (string) data_get($handoff, 'final_success_command', ''),
            'final_success_predicate' => (string) data_get($handoff, 'final_success_predicate', ''),
            'can_run_automatically' => false,
            'why_not_automatic' => match ($currentStep) {
                'runtime_promotion_receipt' => 'requires_operator_signature_and_runtime_promotion_judgment',
                'real_provider_smoke' => 'requires_operator_observed_real_provider_evidence',
                'human_completion_receipt' => 'requires_human_completion_judgment_and_signed_receipt',
                default => 'requires_operator_review_or_completion_audit_confirmation',
            },
            'non_execution_guarantees' => [
                'template_pack_next_action_does_not_execute_command',
                'template_pack_next_action_does_not_persist_receipts',
                'template_pack_next_action_does_not_call_provider',
                'template_pack_next_action_does_not_spend_tokens',
                'template_pack_next_action_does_not_promote_completion',
            ],
        ];
        $nextAction['operator_next_action_hash'] = $this->stableHash($nextAction);

        return $nextAction;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<string>  $blocks
     * @return array<string, mixed>
     */
    private function bundleArtifact(
        string $artifact,
        int $sequence,
        array $template,
        string $filePath,
        string $sourceOption,
        array $blocks,
        string $commandToVerify,
        string $commandToPersist,
        string $commandToComputeHash,
    ): array {
        $commandFilePath = $this->privateStoragePath($filePath);

        return [
            'artifact' => $artifact,
            'sequence' => $sequence,
            'source_option_key' => $sourceOption,
            'recommended_file_path' => $filePath,
            'recommended_private_storage_path' => $commandFilePath,
            'blocks_completion_criteria' => $blocks,
            'schema_version' => (string) ($template['schema_version'] ?? ''),
            'template_hash' => (string) ($template['template_hash'] ?? ''),
            'required_fields' => (array) ($template['required_fields'] ?? []),
            'placeholder_fields' => (array) ($template['placeholder_fields'] ?? []),
            'fields_that_must_be_64_hex' => (array) ($template['fields_that_must_be_64_hex'] ?? []),
            'boolean_acknowledgements' => (array) ($template['boolean_acknowledgements'] ?? []),
            'forbidden_flags' => (array) ($template['forbidden_flags'] ?? []),
            'payload_template' => (array) ($template['payload_template'] ?? []),
            'payload_template_json_sha256' => $this->payloadJsonSha256((array) ($template['payload_template'] ?? [])),
            'command_to_compute_hash' => $commandToComputeHash,
            'command_to_compute_hash_saved_file' => $this->commandForSavedFile($commandToComputeHash, $commandFilePath),
            'command_to_verify_saved_file' => $this->commandForSavedFile($commandToVerify, $commandFilePath),
            'command_to_persist_saved_file' => $this->commandForSavedFile($commandToPersist, $commandFilePath),
            'operator_checks_before_saving' => [
                'compare_initial_file_sha256_to_payload_template_json_sha256_before_editing',
                'replace_every_placeholder_field',
                'compute_and_set_canonical_hash_field',
                'keep_forbidden_flags_absent_or_false',
                'save_json_to_recommended_file_path',
                'run_command_to_compute_hash_saved_file_after_editing',
                'run_command_to_verify_saved_file_before_persisting',
            ],
            'can_write_file_from_template_pack' => false,
            'can_persist_from_template_pack' => false,
        ];
    }

    private function commandForSavedFile(string $command, string $filePath): string
    {
        return str_replace(
            [
                '@/path/to/runtime-promotion.json',
                '@/path/to/real-provider-smoke.json',
                '@/path/to/completion-receipt.json',
                '@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
                '@storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                '@storage/app/atlas/self-construction/operator-submissions/completion-receipt.json',
                '@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
                '@storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                '@storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
            ],
            '@'.$filePath,
            $command,
        );
    }

    private function privateStoragePath(string $filePath): string
    {
        if (str_starts_with($filePath, 'storage/app/private/')) {
            return $filePath;
        }

        if (str_starts_with($filePath, 'storage/app/')) {
            return 'storage/app/private/'.substr($filePath, strlen('storage/app/'));
        }

        return $filePath;
    }

    private function commandWithoutPersistFlag(string $command): string
    {
        return trim(str_replace([' --persist-runtime-promotion-receipt', ' --persist-completion-evidence'], '', $command));
    }

    /** @return list<string> */
    private function placeholderFieldsFromCommand(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[0] ?? [])));
    }

    /** @param array<string, mixed> $bundle */
    private function operatorSubmissionBundleMarkdown(array $bundle): string
    {
        $lines = [
            '# Atlas Self-Construction Operator Evidence Submission Bundle v1',
            '',
            '> Read-only operator bundle for the final Atlas Self-Construction OS evidence blockers.',
            '> This export does not create receipts, does not sign, does not call providers,',
            '> does not persist completion evidence and does not promote completion.',
            '',
            '## Status',
            '',
            '- **schema_version**: `'.((string) data_get($bundle, 'schema_version', '')).'`',
            '- **status**: `'.((string) data_get($bundle, 'status', '')).'`',
            '- **artifact_count**: `'.((string) data_get($bundle, 'artifact_count', 0)).'`',
            '- **recommended_directory**: `'.((string) data_get($bundle, 'recommended_directory', '')).'`',
            '- **can_write_files_from_template_pack**: `'.(((bool) data_get($bundle, 'can_write_files_from_template_pack', false)) ? 'true' : 'false').'`',
            '- **can_persist_from_template_pack**: `'.(((bool) data_get($bundle, 'can_persist_from_template_pack', false)) ? 'true' : 'false').'`',
            '',
            '## Artifact Sequence',
            '',
        ];

        foreach ((array) data_get($bundle, 'artifacts', []) as $artifact) {
            $lines[] = '### '.((int) ($artifact['sequence'] ?? 0)).'. `'.((string) ($artifact['artifact'] ?? '')).'`';
            $lines[] = '';
            $lines[] = '- **recommended_file_path**: `'.((string) ($artifact['recommended_file_path'] ?? '')).'`';
            $lines[] = '- **source_option_key**: `'.((string) ($artifact['source_option_key'] ?? '')).'`';
            $lines[] = '- **schema_version**: `'.((string) ($artifact['schema_version'] ?? '')).'`';
            $lines[] = '- **template_hash**: `'.((string) ($artifact['template_hash'] ?? '')).'`';
            $lines[] = '- **payload_template_json_sha256**: `'.((string) ($artifact['payload_template_json_sha256'] ?? '')).'`';
            $lines[] = '- **blocks_completion_criteria**: `'.implode('`, `', (array) ($artifact['blocks_completion_criteria'] ?? [])).'`';
            $lines[] = '';
            $lines[] = '**Required fields**';
            foreach ((array) ($artifact['required_fields'] ?? []) as $field) {
                $lines[] = '- `'.$field.'`';
            }
            $lines[] = '';
            $lines[] = '**Placeholder fields to replace**';
            $placeholders = (array) ($artifact['placeholder_fields'] ?? []);
            if ($placeholders === []) {
                $lines[] = '- none';
            } else {
                foreach ($placeholders as $field) {
                    $lines[] = '- `'.$field.'`';
                }
            }
            $lines[] = '';
            $lines[] = '**Commands**';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) ($artifact['command_to_compute_hash_saved_file'] ?? '');
            $lines[] = (string) ($artifact['command_to_verify_saved_file'] ?? '');
            $lines[] = (string) ($artifact['command_to_persist_saved_file'] ?? '');
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '## Operator Execution Plan';
        $lines[] = '';
        $lines[] = '- **submission_preflight_status**: `'.((string) data_get($bundle, 'submission_preflight_status', '')).'`';
        $lines[] = '- **submission_preflight_hash**: `'.((string) data_get($bundle, 'submission_preflight_hash', '')).'`';
        $lines[] = '- **next_required_submission**: `'.((string) data_get($bundle, 'next_required_submission', '')).'`';
        $lines[] = '- **operator_must_follow_order**: `'.(((bool) data_get($bundle, 'operator_must_follow_order', false)) ? 'true' : 'false').'`';
        $lines[] = '- **parallel_submission_allowed**: `'.(((bool) data_get($bundle, 'parallel_submission_allowed', false)) ? 'true' : 'false').'`';
        $lines[] = '- **final_success_predicate**: `'.((string) data_get($bundle, 'final_success_predicate', '')).'`';
        $lines[] = '';
        $lines[] = '## Completion Audit Blocker Summary';
        $lines[] = '';
        $lines[] = '- **completion_audit_hash**: `'.((string) data_get($bundle, 'completion_audit_blocker_summary.completion_audit_hash', '')).'`';
        $lines[] = '- **failed_count**: `'.((string) data_get($bundle, 'completion_audit_blocker_summary.failed_count', 0)).'`';
        $lines[] = '- **human_blocker_count**: `'.((string) data_get($bundle, 'completion_audit_blocker_summary.human_blocker_count', 0)).'`';
        $lines[] = '- **real_provider_blocker_count**: `'.((string) data_get($bundle, 'completion_audit_blocker_summary.real_provider_blocker_count', 0)).'`';
        $lines[] = '- **technical_blocker_count**: `'.((string) data_get($bundle, 'completion_audit_blocker_summary.technical_blocker_count', 0)).'`';
        $lines[] = '';
        foreach ((array) data_get($bundle, 'completion_audit_blocker_summary.blockers', []) as $blocker) {
            $lines[] = '### `'.((string) data_get($blocker, 'id', '')).'`';
            $lines[] = '- **blocker_type**: `'.((string) data_get($blocker, 'blocker_type', '')).'`';
            $lines[] = '- **owner**: `'.((string) data_get($blocker, 'owner', '')).'`';
            $lines[] = '- **expected_receipt_schema**: `'.((string) data_get($blocker, 'expected_receipt_schema', '')).'`';
            $lines[] = '- **doc_anchor**: `'.((string) data_get($blocker, 'doc_anchor', '')).'`';
            $lines[] = '- **remediation_command**: `'.((string) data_get($blocker, 'remediation_command', '')).'`';
            $lines[] = '- **why_not_automatic**: `'.$this->markdownScalar((string) data_get($blocker, 'why_not_automatic', '')).'`';
            $lines[] = '';
        }
        $lines[] = '';
        $lines[] = '## Operator Next Action';
        $lines[] = '';
        $lines[] = '- **schema_version**: `'.((string) data_get($bundle, 'operator_next_action.schema_version', '')).'`';
        $lines[] = '- **current_step**: `'.((string) data_get($bundle, 'operator_next_action.current_step', '')).'`';
        $lines[] = '- **next_required_submission**: `'.((string) data_get($bundle, 'operator_next_action.next_required_submission', '')).'`';
        $lines[] = '- **can_run_automatically**: `'.(((bool) data_get($bundle, 'operator_next_action.can_run_automatically', false)) ? 'true' : 'false').'`';
        $lines[] = '- **why_not_automatic**: `'.((string) data_get($bundle, 'operator_next_action.why_not_automatic', '')).'`';
        $lines[] = '- **operator_next_action_hash**: `'.((string) data_get($bundle, 'operator_next_action.operator_next_action_hash', '')).'`';
        $lines[] = '';
        $lines[] = '**Placeholders to replace before running**';
        $nextActionPlaceholders = (array) data_get($bundle, 'operator_next_action.placeholder_fields_to_replace', []);
        if ($nextActionPlaceholders === []) {
            $lines[] = '- none';
        } else {
            foreach ($nextActionPlaceholders as $placeholder) {
                $lines[] = '- `'.$placeholder.'`';
            }
        }
        $lines[] = '';
        $lines[] = '**Exact next command**';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) data_get($bundle, 'operator_next_action.exact_command', '');
        if ((string) data_get($bundle, 'operator_next_action.exact_persist_command', '') !== '') {
            $lines[] = (string) data_get($bundle, 'operator_next_action.exact_persist_command', '');
        }
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Operator Current-Step Handoff';
        $lines[] = '';
        $lines[] = '- **schema_version**: `'.((string) data_get($bundle, 'operator_handoff_packet.schema_version', '')).'`';
        $lines[] = '- **current_step**: `'.((string) data_get($bundle, 'operator_handoff_packet.current_step', '')).'`';
        $lines[] = '- **current_status**: `'.((string) data_get($bundle, 'operator_handoff_packet.current_status', '')).'`';
        $lines[] = '- **current_blocker_count**: `'.((string) data_get($bundle, 'operator_handoff_packet.current_blocker_count', 0)).'`';
        $lines[] = '- **handoff_packet_hash**: `'.((string) data_get($bundle, 'operator_handoff_packet.handoff_packet_hash', '')).'`';
        $lines[] = '- **resumption_checkpoint_hash**: `'.((string) data_get($bundle, 'operator_handoff_packet.resumption_checkpoint_hash', '')).'`';
        $lines[] = '- **can_resume_without_chat_history**: `'.((bool) data_get($bundle, 'operator_handoff_packet.can_resume_without_chat_history', false) ? 'true' : 'false').'`';
        $lines[] = '- **requires_fresh_preflight_before_persist**: `'.((bool) data_get($bundle, 'operator_handoff_packet.requires_fresh_preflight_before_persist', false) ? 'true' : 'false').'`';
        $lines[] = '';
        $lines[] = '**Required operator inputs for current step**';
        foreach ((array) data_get($bundle, 'operator_handoff_packet.required_operator_inputs', []) as $input) {
            $lines[] = '- `'.$input.'`';
        }
        $lines[] = '';
        $lines[] = '**Current blockers**';
        $currentBlockers = (array) data_get($bundle, 'operator_handoff_packet.current_blockers', []);
        if ($currentBlockers === []) {
            $lines[] = '- none';
        } else {
            foreach ($currentBlockers as $blocker) {
                $lines[] = '- `'.$this->markdownScalar($blocker).'`';
            }
        }
        $lines[] = '';
        $lines[] = '**Current completion blockers classified**';
        $classifiedBlockers = (array) data_get($bundle, 'operator_handoff_packet.current_completion_blockers_classified', []);
        if ($classifiedBlockers === []) {
            $lines[] = '- none';
        } else {
            foreach ($classifiedBlockers as $blocker) {
                $lines[] = '- `'.((string) data_get($blocker, 'id', '')).'`'
                    .' type=`'.((string) data_get($blocker, 'blocker_type', '')).'`'
                    .' schema=`'.((string) data_get($blocker, 'expected_receipt_schema', '')).'`';
            }
        }
        $lines[] = '';
        $lines[] = '**Current-step commands**';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) data_get($bundle, 'operator_handoff_packet.next_draft_or_check_command', '');
        if ((string) data_get($bundle, 'operator_handoff_packet.next_persist_command', '') !== '') {
            $lines[] = (string) data_get($bundle, 'operator_handoff_packet.next_persist_command', '');
        }
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '**Ordered command queue**';
        $lines[] = '';
        foreach ((array) data_get($bundle, 'operator_execution_plan.ordered_command_queue', []) as $step) {
            $lines[] = '### `'.((string) data_get($step, 'id', '')).'`';
            $lines[] = '- **status**: `'.((string) data_get($step, 'status', '')).'`';
            $lines[] = '- **ready**: `'.(((bool) data_get($step, 'ready', false)) ? 'true' : 'false').'`';
            $lines[] = '- **required_before**: `'.implode('`, `', (array) data_get($step, 'required_before', [])).'`';
            $lines[] = '- **evidence_hash**: `'.((string) data_get($step, 'evidence_hash', '')).'`';
            $lines[] = '- **blocks_completion_criteria**: `'.implode('`, `', (array) data_get($step, 'blocks_completion_criteria', [])).'`';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) data_get($step, 'draft_or_check_command', '');
            if ((string) data_get($step, 'persist_command', '') !== '') {
                $lines[] = (string) data_get($step, 'persist_command', '');
            }
            $lines[] = '```';
            $lines[] = '';
        }
        $lines[] = '**Handoff stop conditions**';
        foreach ((array) data_get($bundle, 'operator_handoff_packet.handoff_stop_conditions', []) as $condition) {
            $lines[] = '- `'.$condition.'`';
        }
        $lines[] = '';
        $lines[] = '**Stop conditions**';
        foreach ((array) data_get($bundle, 'operator_execution_plan.stop_conditions', []) as $condition) {
            $lines[] = '- `'.$condition.'`';
        }
        $lines[] = '';

        $lines[] = '## Bundle Usage Order';
        $lines[] = '';
        foreach ((array) data_get($bundle, 'bundle_usage_order', []) as $step) {
            $lines[] = '- `'.$step.'`';
        }
        $lines[] = '';
        $lines[] = '## Global Commands';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) data_get($bundle, 'global_verify_command', '');
        $lines[] = (string) data_get($bundle, 'global_submission_preflight_command', '');
        $lines[] = (string) data_get($bundle, 'global_completion_evidence_command', '');
        $lines[] = (string) data_get($bundle, 'global_completion_audit_command', '');
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Non-Execution Guarantees';
        $lines[] = '';
        foreach ((array) data_get($bundle, 'non_execution_guarantees', []) as $guarantee) {
            $lines[] = '- `'.$guarantee.'`';
        }
        $lines[] = '';
        $lines[] = '_Generated by Atlas Self-Construction Operator Evidence Artifact Template Pack v1._';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    private function placeholderFields(array $template): array
    {
        $fields = [];
        foreach ($template as $field => $value) {
            if (is_string($value) && str_starts_with(trim($value), '<') && str_ends_with(trim($value), '>')) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $payload */
    private function payloadJsonSha256(array $payload): string
    {
        return hash('sha256', $this->prettyJson($payload));
    }

    /** @param array<string, mixed> $payload */
    private function prettyJson(array $payload): string
    {
        return (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function markdownScalar(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        unset($payload['generated_at'], $payload['template_pack_hash']);
        unset($payload['operator_submission_bundle_markdown']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
