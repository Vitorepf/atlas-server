<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

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
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_artifact_template_pack.v1';

    public const MODE = 'read_only_operator_evidence_artifact_template_pack';

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
            'schema_version' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::SCHEMA_VERSION,
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

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'template_count' => count($templates),
            'templates' => $templates,
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
            ],
        ];
        $payload['template_pack_hash'] = $this->stableHash($payload);

        return $payload;
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
