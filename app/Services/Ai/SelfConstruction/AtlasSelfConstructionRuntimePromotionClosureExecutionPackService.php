<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Runtime Promotion Closure Execution Pack v1.
 *
 * Single read-only operator pack that walks the operator through closing the
 * `runtime_gap_matrix_all_runtime_y` blocker of the Atlas Self-Construction OS
 * completion audit. It composes runtime gap matrix, runtime promotion evidence
 * dossier, runtime promotion closure pack, runtime promotion receipt runbook,
 * optional runtime promotion receipt draft, completion evidence status,
 * partial completion audit and the operator action packet into a single
 * artifact. It never persists, never enables runtime, never calls providers.
 */
final class AtlasSelfConstructionRuntimePromotionClosureExecutionPackService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_closure_execution_pack.v1';

    public const MODE = 'read_only_runtime_promotion_closure_execution_pack';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $signedBy = trim((string) ($options['signed_by'] ?? ''));
        $reason = trim((string) ($options['reason'] ?? ''));
        $providedReceipt = (array) ($options['runtime_promotion_receipt'] ?? []);

        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => $providedReceipt,
            'persist_runtime_promotion_receipt' => false,
        ]);
        $rows = array_values(array_filter((array) data_get($matrix, 'rows', []), 'is_array'));
        $gapRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $blockedGapIds = array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows));
        $promotedGapIds = $blockedGapIds;
        $graduationHashes = [];
        foreach ($gapRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $graduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }
        $runtimeGapMatrixHash = (string) data_get($matrix, 'runtime_gap_matrix_hash', '');
        $expectedRuntimeGapMatrixHashForPromotionReceipt = (string) data_get(
            $matrix,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            $runtimeGapMatrixHash,
        );
        $runtimePromotionBasisHash = (string) data_get($matrix, 'runtime_promotion_basis_hash', '');
        $runtimePromotionClosureBasisHash = (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', '');

        $dossier = (new AtlasSelfConstructionRuntimePromotionEvidenceDossierService($this->readiness))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $providedReceipt,
        ]);
        $closurePack = (new AtlasSelfConstructionRuntimePromotionClosurePackService($this->readiness))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $providedReceipt,
        ]);
        $runbook = (new AtlasSelfConstructionRuntimePromotionReceiptRunbookService)->build(
            (array) data_get($closurePack, 'runtime_promotion_receipt_preimage', [])
        );

        $receiptTemplate = (array) data_get($closurePack, 'runtime_promotion_receipt_preimage', []);
        $receiptTemplateHash = (string) data_get($closurePack, 'runtime_promotion_receipt_template_hash', '');

        $draftRequested = $signedBy !== '' || $reason !== '' || $providedReceipt !== [];
        $receiptDraft = [];
        if ($draftRequested) {
            $receiptDraft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($matrix, [
                'signed_by' => $signedBy,
                'reason' => $reason,
                'persist_runtime_promotion_receipt' => false,
            ]);
        }
        $draftPayload = (array) data_get($receiptDraft, 'receipt_payload', []);
        $draftReady = (string) data_get($receiptDraft, 'status', '') === 'ready_for_operator_persistence';

        $preSubmissionVerifier = $draftPayload !== []
            ? (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify(
                receipt: $draftPayload,
                matrix: $matrix,
            )
            : (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->emptyVerification();
        $verifierPassed = (string) data_get($preSubmissionVerifier, 'status') === 'passed';

        $completionEvidence = $this->safeCall(fn () => $this->readiness->atlasSelfConstructionOsCompletionEvidenceStatus($options));
        $completionAudit = (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit($options);
        $operatorActionPacket = (array) data_get($completionAudit, 'operator_action_packet', []);

        $persistencePreflight = [
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'requires_explicit_flag' => '--persist-runtime-promotion-receipt',
            'gated_by_service' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
            'verifier_passed' => $verifierPassed,
            'draft_status' => (string) data_get($receiptDraft, 'status', $draftRequested ? 'blocked_operator_input_required' : 'not_drafted'),
            'persistence_blocked' => ! $verifierPassed,
            'persistence_blocker' => ! $verifierPassed
                ? ($draftRequested ? 'pre_submission_verifier_did_not_pass' : 'no_draft_supplied')
                : '',
        ];

        $orderedOperatorSteps = [
            $this->step(
                id: 'review_current_runtime_gap_matrix',
                summary: 'Review the current runtime gap matrix; confirm every blocked gap is intentional and current.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                producedArtifacts: ['runtime_gap_matrix_hash', 'blocked_gap_ids'],
            ),
            $this->step(
                id: 'review_graduation_evidence_hashes',
                summary: 'Review each graduation_evidence_hash; every value must be the current candidate hash for its gap.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-evidence-dossier-status --json',
                producedArtifacts: ['graduation_evidence_hashes', 'runtime_promotion_closure_basis_hash'],
            ),
            $this->step(
                id: 'generate_runtime_promotion_receipt_draft',
                summary: 'Generate a draft from the current closure pack by supplying signed_by and a reason ≥32 chars.',
                canRunAutomatically: false,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                producedArtifacts: ['runtime_promotion_receipt_draft', 'runtime_promotion_receipt_payload'],
            ),
            $this->step(
                id: 'compute_canonical_receipt_hash',
                summary: 'Compute canonical receipt_hash through CompletionEvidenceHashService and embed it into the payload.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                producedArtifacts: ['receipt_hash'],
            ),
            $this->step(
                id: 'verify_receipt_hash_matches_current_matrix',
                summary: 'Run the pre-submission verifier; reject stale matrix hash, stale closure basis, hash mismatch, placeholder signer or runtime-enabling flags.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                producedArtifacts: ['pre_submission_verifier_result'],
            ),
            $this->step(
                id: 'persist_with_explicit_flag_only',
                summary: 'Persist the runtime promotion receipt only through the canonical verifier and only with --persist-runtime-promotion-receipt.',
                canRunAutomatically: false,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                producedArtifacts: ['persisted_runtime_promotion_receipt'],
            ),
            $this->step(
                id: 'rerun_runtime_gap_matrix',
                summary: 'Rerun the runtime gap matrix so every promoted gap row counts as runtime_y=true.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                producedArtifacts: ['runtime_gap_matrix_with_all_runtime_y_true'],
            ),
            $this->step(
                id: 'rerun_completion_audit',
                summary: 'Rerun the completion audit; runtime_gap_matrix_all_runtime_y should turn green.',
                canRunAutomatically: true,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                producedArtifacts: ['updated_completion_audit'],
            ),
        ];

        $exactCommands = [
            'review_runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
            'review_runtime_promotion_evidence_dossier' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-evidence-dossier-status --json',
            'review_runtime_promotion_closure_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-closure-pack-status --json',
            'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'compute_receipt_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            'pre_submission_verify' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            'persist_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'rerun_runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
            'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];

        $status = 'blocked_operator_runtime_promotion_receipt_required';
        if ($verifierPassed && $draftReady) {
            $status = 'ready_to_persist_runtime_promotion_receipt';
        }
        if ((bool) data_get($matrix, 'all_runtime_y', false) === true
            && (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed') {
            $status = 'runtime_promotion_receipt_verified';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
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
            'runtime_gap_count' => count($gapRows),
            'blocked_gap_ids' => $blockedGapIds,
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
            'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'receipt_template' => [
                'preimage' => $receiptTemplate,
                'template_hash' => $receiptTemplateHash,
                'placeholder_fields' => $this->placeholderFields($receiptTemplate),
            ],
            'receipt_draft' => [
                'requested' => $draftRequested,
                'status' => (string) data_get($receiptDraft, 'status', $draftRequested ? 'blocked_operator_input_required' : 'not_drafted'),
                'draft_hash' => (string) data_get($receiptDraft, 'draft_hash', ''),
                'receipt_hash' => (string) data_get($receiptDraft, 'receipt_hash', ''),
                'receipt_payload' => $draftPayload,
                'missing_operator_inputs' => (array) data_get($receiptDraft, 'missing_operator_inputs', []),
                'candidate_count' => (int) data_get($receiptDraft, 'candidate_count', 0),
            ],
            'receipt_verification' => [
                'status' => (string) data_get($preSubmissionVerifier, 'status', 'not_supplied'),
                'verification_hash' => (string) data_get($preSubmissionVerifier, 'verification_hash', ''),
                'can_persist' => (bool) data_get($preSubmissionVerifier, 'can_persist', false),
                'violation_count' => (int) data_get($preSubmissionVerifier, 'violation_count', 0),
                'violations' => (array) data_get($preSubmissionVerifier, 'violations', []),
            ],
            'receipt_persistence_preflight' => $persistencePreflight,
            'composed_surfaces' => [
                'runtime_gap_matrix_status' => (string) data_get($matrix, 'status', 'unknown'),
                'runtime_promotion_evidence_dossier_status' => (string) data_get($dossier, 'status', 'unknown'),
                'runtime_promotion_closure_pack_status' => (string) data_get($closurePack, 'status', 'unknown'),
                'runtime_promotion_closure_pack_hash' => (string) data_get($closurePack, 'closure_pack_hash', ''),
                'runtime_promotion_receipt_runbook_hash' => (string) data_get($runbook, 'runbook_hash', ''),
                'completion_evidence_status' => (string) data_get($completionEvidence, 'status', 'unknown'),
                'completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'operator_action_packet_status' => (string) data_get($operatorActionPacket, 'status', 'unknown'),
                'operator_action_packet_hash' => (string) data_get($operatorActionPacket, 'operator_action_packet_hash', ''),
            ],
            'partial_completion_audit_status' => [
                'status' => (string) data_get($completionAudit, 'status', 'incomplete'),
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
                'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
                'runtime_gap_matrix_all_runtime_y_passed' => (bool) data_get(
                    collect((array) data_get($completionAudit, 'criteria', []))
                        ->first(static fn (array $c): bool => (string) ($c['id'] ?? '') === 'runtime_gap_matrix_all_runtime_y'),
                    'passed',
                    false,
                ),
            ],
            'ordered_operator_steps' => $orderedOperatorSteps,
            'exact_commands' => $exactCommands,
            'anti_cheat_policy' => [
                'reject_runtime_autopromotion',
                'reject_stale_runtime_gap_matrix_hash',
                'reject_missing_closure_basis_hash',
                'reject_promoted_gap_id_drift',
                'reject_graduation_hash_mismatch',
                'reject_placeholder_operator',
                'reject_receipt_hash_mismatch',
                'reject_runtime_enabled_flags_true',
            ],
            'non_execution_guarantees' => [
                'does_not_enable_runtime',
                'does_not_persist_without_flag',
                'does_not_start_process',
                'does_not_call_provider',
                'does_not_spend_tokens',
                'does_not_dispatch',
                'does_not_promote_completion',
                'does_not_sign_for_operator',
            ],
        ];
        $payload['closure_pack_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $producedArtifacts
     * @return array<string, mixed>
     */
    private function step(string $id, string $summary, bool $canRunAutomatically, string $command, array $producedArtifacts): array
    {
        return [
            'id' => $id,
            'summary' => $summary,
            'can_run_automatically' => $canRunAutomatically,
            'command' => $command,
            'produced_artifacts' => $producedArtifacts,
        ];
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
        unset($payload['generated_at'], $payload['closure_pack_hash']);
        unset($payload['receipt_template']['preimage']['receipt_id']);
        unset($payload['receipt_draft']['receipt_payload']['receipt_id']);

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
