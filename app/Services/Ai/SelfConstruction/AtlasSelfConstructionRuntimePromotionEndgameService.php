<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Runtime Promotion Endgame v1.
 *
 * Maximum-leverage read-only corridor for closing the
 * `runtime_gap_matrix_all_runtime_y` blocker. Composes every canonical runtime
 * promotion surface (matrix, evidence dossier, closure pack + closure pack
 * verifier, runbook, draft, pre-submission verifier, submission preflight,
 * completion audit, operator action packet) and threads the operator from
 * "draft missing" to "receipt persisted, rerun matrix + audit" without ever
 * autopromoting runtime, persisting receipt without explicit flag + verifier
 * green, signing for the operator, calling providers, spending tokens,
 * dispatching, or declaring the OS complete.
 */
final class AtlasSelfConstructionRuntimePromotionEndgameService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_endgame.v1';

    public const MODE = 'read_only_runtime_promotion_endgame';

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
        $receiptProvided = $providedReceipt !== [];
        $persistRequested = (bool) ($options['persist_runtime_promotion_receipt'] ?? false);
        $skipCompletionSurfaces = (bool) ($options['skip_completion_surfaces'] ?? false);

        $matrix = (array) ($options['runtime_gap_matrix'] ?? (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => $providedReceipt,
            'persist_runtime_promotion_receipt' => false,
        ]));
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
        $closurePackVerification = (new AtlasSelfConstructionRuntimePromotionClosurePackVerifierService)->verify($closurePack);

        $draftRequested = $signedBy !== '' || $reason !== '';
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
        $receiptUnderReview = $receiptProvided ? $providedReceipt : $draftPayload;
        $receiptUnderReviewSource = $receiptProvided ? 'operator_supplied_runtime_promotion_receipt' : ($draftPayload !== [] ? 'generated_runtime_promotion_receipt_draft' : 'none');

        $endgameVerifier = new AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
        $preSubmissionVerification = $receiptUnderReview !== []
            ? $endgameVerifier->verify($receiptUnderReview, $matrix)
            : $endgameVerifier->emptyVerification();
        $preSubmissionPassed = (string) data_get($preSubmissionVerification, 'status') === 'passed';

        $persistencePreflight = [
            'persist_flag_required' => '--persist-runtime-promotion-receipt',
            'gated_by_service' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
            'verifier_passed' => $preSubmissionPassed,
            'receipt_source' => $receiptUnderReviewSource,
            'receipt_provided' => $receiptProvided,
            'draft_status' => (string) data_get($receiptDraft, 'status', $draftRequested ? 'blocked_operator_input_required' : 'not_drafted'),
            'persist_requested' => $persistRequested,
            'persistence_blocked_reason' => $this->persistenceBlockedReason($receiptUnderReview !== [], $receiptProvided || $draftReady, $preSubmissionPassed, $persistRequested),
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'rerun_matrix_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
            'rerun_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];

        $persistenceResult = [];
        $persisted = false;
        if ($persistRequested && $preSubmissionPassed && $receiptUnderReview !== []) {
            $persistenceResult = (new AtlasSelfConstructionRuntimePromotionReceiptService)->persist(
                receipt: $receiptUnderReview,
                rows: $rows,
                expectedRuntimePromotionBasisHash: $runtimePromotionBasisHash,
                expectedRuntimeGapMatrixHash: $expectedRuntimeGapMatrixHashForPromotionReceipt,
                expectedRuntimePromotionClosureBasisHash: $runtimePromotionClosureBasisHash,
            );
            $persisted = (bool) data_get($persistenceResult, 'persisted', false);
        }

        $completionAudit = $skipCompletionSurfaces
            ? ['status' => 'skipped_for_supplied_runtime_gap_matrix_override', 'completion_audit_hash' => '', 'passed_count' => 0, 'failed_count' => 0, 'failed_criteria' => []]
            : (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit($options);
        $blockerExplainer = $skipCompletionSurfaces
            ? []
            : (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit);
        $completionEvidence = $skipCompletionSurfaces
            ? []
            : $this->safeCall(fn () => $this->readiness->atlasSelfConstructionOsCompletionEvidenceStatus($options));
        $submissionPreflight = $skipCompletionSurfaces
            ? ['status' => 'skipped_for_supplied_runtime_gap_matrix_override', 'submission_preflight_hash' => '', 'next_required_submission' => '']
            : (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)
                ->build($completionAudit, $completionEvidence, $blockerExplainer);
        $operatorActionPacket = (array) data_get($completionAudit, 'operator_action_packet', []);

        $status = match (true) {
            $persisted => 'receipt_persisted_runtime_gap_matrix_should_be_rerun',
            $preSubmissionPassed && $receiptUnderReview !== [] => 'receipt_verifier_passed_ready_for_explicit_persistence',
            $draftRequested && $draftReady => 'draft_ready_for_operator_review',
            default => 'blocked_runtime_promotion_receipt_required',
        };

        $operatorDecisionChecklist = [
            $this->checklistItem(
                id: 'current_gap_ids_match_receipt',
                summary: 'Operator confirms promoted_gap_ids in the receipt match the live blocked_gap_ids exactly.',
                passed: $this->checklistGapIdsMatch($receiptUnderReview, $blockedGapIds),
                blockingReason: 'promoted_gap_ids in receipt drift from live matrix',
            ),
            $this->checklistItem(
                id: 'graduation_hashes_match_receipt',
                summary: 'Every gap id in graduation_evidence_hashes matches its current graduation_evidence_hash.',
                passed: $this->checklistGraduationHashesMatch($receiptUnderReview, $graduationHashes),
                blockingReason: 'graduation_evidence_hashes drift from live matrix',
            ),
            $this->checklistItem(
                id: 'closure_basis_hash_current',
                summary: 'runtime_promotion_closure_basis_hash equals the value the current matrix exposes.',
                passed: (string) ($receiptUnderReview['runtime_promotion_closure_basis_hash'] ?? '') === $runtimePromotionClosureBasisHash,
                blockingReason: 'closure_basis_hash drift from live matrix',
            ),
            $this->checklistItem(
                id: 'no_runtime_autopromotion_acknowledged',
                summary: 'Operator acknowledges that runtime promotion is not direct execution.',
                passed: (bool) ($receiptUnderReview['no_runtime_autopromotion_acknowledged'] ?? false) === true,
                blockingReason: 'no_runtime_autopromotion_acknowledged is missing or false',
            ),
            $this->checklistItem(
                id: 'operator_signature_present',
                summary: 'signed_by is a real operator identity (not <operator>, codex, claude, system or empty).',
                passed: $this->checklistSignerReal((string) ($receiptUnderReview['signed_by'] ?? '')),
                blockingReason: 'signed_by is placeholder or empty',
            ),
            $this->checklistItem(
                id: 'receipt_hash_canonical',
                summary: 'receipt_hash equals CompletionEvidenceHashService::runtimePromotionReceiptHash($payload).',
                passed: $this->checklistReceiptHashCanonical($receiptUnderReview),
                blockingReason: 'receipt_hash does not match canonical computation',
            ),
            $this->checklistItem(
                id: 'persistence_flag_explicit',
                summary: 'Operator must invoke --persist-runtime-promotion-receipt explicitly; nothing persists without it.',
                passed: $persisted,
                blockingReason: $persistRequested ? 'persistence_flag present but verifier did not pass' : 'persistence_flag_not_supplied',
            ),
            $this->checklistItem(
                id: 'completion_audit_rerun_required',
                summary: 'After persistence, rerun the runtime gap matrix and the completion audit.',
                passed: $persisted,
                blockingReason: 'persist runtime promotion receipt first then rerun gap matrix + completion audit',
            ),
        ];

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
            'completion_surfaces_skipped' => $skipCompletionSurfaces,
            'current_gap_matrix' => [
                'status' => (string) data_get($matrix, 'status', 'unknown'),
                'runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'all_runtime_y' => (bool) data_get($matrix, 'all_runtime_y', false),
                'runtime_y_candidate_count' => (int) data_get($matrix, 'runtime_y_candidate_count', 0),
                'runtime_enabled_count' => count(array_filter($rows, static fn (array $r): bool => (bool) ($r['runtime_enabled'] ?? false))),
            ],
            'runtime_gap_count' => count($gapRows),
            'blocked_gap_ids' => $blockedGapIds,
            'promoted_gap_ids' => $promotedGapIds,
            'current_runtime_gap_matrix_hash' => $runtimeGapMatrixHash,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
            'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'graduation_evidence_hashes' => $graduationHashes,
            'closure_pack' => [
                'status' => (string) data_get($closurePack, 'status', 'unknown'),
                'closure_pack_hash' => (string) data_get($closurePack, 'closure_pack_hash', ''),
                'runtime_promotion_receipt_template_hash' => (string) data_get($closurePack, 'runtime_promotion_receipt_template_hash', ''),
                'runtime_y_candidate_count' => (int) data_get($closurePack, 'runtime_y_candidate_count', 0),
                'runtime_enabled_count' => (int) data_get($closurePack, 'runtime_enabled_count', 0),
                'blockers' => (array) data_get($closurePack, 'blockers', []),
            ],
            'closure_pack_verification' => [
                'status' => (string) data_get($closurePackVerification, 'status', 'unknown'),
                'verification_hash' => (string) data_get($closurePackVerification, 'verification_hash', ''),
                'closure_pack_hash_matches' => (bool) data_get($closurePackVerification, 'closure_pack_hash_matches', false),
                'violation_count' => (int) data_get($closurePackVerification, 'violation_count', 0),
                'violations' => (array) data_get($closurePackVerification, 'violations', []),
            ],
            'receipt_template' => [
                'preimage' => (array) data_get($closurePack, 'runtime_promotion_receipt_preimage', []),
                'template_hash' => (string) data_get($closurePack, 'runtime_promotion_receipt_template_hash', ''),
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
            'receipt_under_review' => [
                'source' => $receiptUnderReviewSource,
                'provided' => $receiptProvided,
                'present' => $receiptUnderReview !== [],
                'receipt_hash' => (string) ($receiptUnderReview['receipt_hash'] ?? ''),
                'receipt_id' => (string) ($receiptUnderReview['receipt_id'] ?? ''),
                'signed_by' => (string) ($receiptUnderReview['signed_by'] ?? ''),
            ],
            'receipt_pre_submission_verification' => $preSubmissionVerification,
            'persistence_preflight' => $persistencePreflight,
            'persistence_result' => $persistenceResult,
            'persisted' => $persisted,
            'post_persistence_next_commands' => $persisted ? [
                'rerun_runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'rerun_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'inspect_runtime_promotion_evidence_dossier' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-evidence-dossier-status --json',
                'inspect_completion_audit_blocker_explainer' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-audit-blocker-explainer-status --json',
            ] : [
                'first_persist_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            ],
            'operator_decision_checklist' => $operatorDecisionChecklist,
            'operator_decision_checklist_complete' => collect($operatorDecisionChecklist)->every(static fn (array $row): bool => (bool) ($row['passed'] ?? false)),
            'completion_audit_status' => [
                'status' => (string) data_get($completionAudit, 'status', 'unknown'),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
                'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
                'runtime_gap_matrix_all_runtime_y_passed' => $this->criterionPassed($completionAudit, 'runtime_gap_matrix_all_runtime_y'),
            ],
            'submission_preflight' => [
                'status' => (string) data_get($submissionPreflight, 'status', 'unknown'),
                'submission_preflight_hash' => (string) data_get($submissionPreflight, 'submission_preflight_hash', ''),
                'next_required_submission' => (string) data_get($submissionPreflight, 'next_required_submission', ''),
            ],
            'composed_surfaces' => [
                'runtime_promotion_evidence_dossier_hash' => (string) data_get($dossier, 'machine_verification.dossier_hash', ''),
                'runtime_promotion_closure_pack_hash' => (string) data_get($closurePack, 'closure_pack_hash', ''),
                'runtime_promotion_closure_pack_verification_hash' => (string) data_get($closurePackVerification, 'verification_hash', ''),
                'runtime_promotion_receipt_draft_hash' => (string) data_get($receiptDraft, 'draft_hash', ''),
                'runtime_promotion_endgame_verifier_hash' => (string) data_get($preSubmissionVerification, 'verifier_hash', data_get($preSubmissionVerification, 'verification_hash', '')),
                'completion_evidence_submission_preflight_hash' => (string) data_get($submissionPreflight, 'submission_preflight_hash', ''),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'operator_action_packet_hash' => (string) data_get($operatorActionPacket, 'operator_action_packet_hash', ''),
            ],
            'anti_cheat_policy' => [
                'reject_runtime_autopromotion',
                'reject_stale_gap_matrix_hash',
                'reject_stale_closure_basis_hash',
                'reject_gap_id_drift',
                'reject_graduation_hash_mismatch',
                'reject_placeholder_operator',
                'reject_hash_mismatch',
                'reject_runtime_enabled_flags_true',
                'reject_completion_claim_from_runtime_receipt_alone',
            ],
            'non_execution_guarantees' => [
                'endgame_does_not_autopromote_runtime',
                'endgame_does_not_persist_without_flag_and_verifier_green',
                'endgame_does_not_sign_for_operator',
                'endgame_does_not_call_codex_cli_or_app',
                'endgame_does_not_call_provider',
                'endgame_does_not_spend_tokens',
                'endgame_does_not_dispatch',
                'endgame_does_not_start_process',
                'endgame_does_not_enable_self_programming',
                'endgame_does_not_promote_completion',
                'endgame_does_not_declare_os_complete',
            ],
        ];
        $payload['endgame_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  list<string>  $blockedGapIds
     */
    private function checklistGapIdsMatch(array $receipt, array $blockedGapIds): bool
    {
        if ($blockedGapIds === [] && $receipt === []) {
            return false;
        }
        $promoted = array_values(array_filter((array) ($receipt['promoted_gap_ids'] ?? []), 'is_string'));

        return $promoted === $blockedGapIds;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, string>  $expectedGraduationHashes
     */
    private function checklistGraduationHashesMatch(array $receipt, array $expectedGraduationHashes): bool
    {
        if ($expectedGraduationHashes === [] || $receipt === []) {
            return false;
        }
        $provided = (array) ($receipt['graduation_evidence_hashes'] ?? []);
        foreach ($expectedGraduationHashes as $gapId => $hash) {
            if ((string) ($provided[$gapId] ?? '') !== $hash) {
                return false;
            }
        }

        return true;
    }

    private function checklistSignerReal(string $signedBy): bool
    {
        $placeholders = ['', '<operator>', 'operator', 'human', 'codex', 'assistant', 'system', 'claude', 'codex-autosigned', 'atlas'];

        return ! in_array(strtolower(trim($signedBy)), $placeholders, true);
    }

    /** @param array<string, mixed> $receipt */
    private function checklistReceiptHashCanonical(array $receipt): bool
    {
        if ($receipt === []) {
            return false;
        }
        $expected = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return (string) ($receipt['receipt_hash'] ?? '') === $expected;
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterionPassed(array $completionAudit, string $criterionId): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $row) {
            if ((string) ($row['id'] ?? '') === $criterionId) {
                return (bool) ($row['passed'] ?? false);
            }
        }

        return false;
    }

    private function persistenceBlockedReason(bool $receiptPresent, bool $receiptReady, bool $verifierPassed, bool $persistRequested): string
    {
        if (! $persistRequested) {
            return 'persistence_flag_not_supplied';
        }
        if (! $receiptPresent) {
            return 'no_receipt_supplied_or_drafted';
        }
        if (! $receiptReady) {
            return 'receipt_blocked_operator_input_required';
        }
        if (! $verifierPassed) {
            return 'endgame_verifier_did_not_pass';
        }

        return '';
    }

    private function checklistItem(string $id, string $summary, bool $passed, string $blockingReason): array
    {
        return [
            'id' => $id,
            'summary' => $summary,
            'passed' => $passed,
            'blocking_reason' => $passed ? '' : $blockingReason,
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
        unset($payload['generated_at'], $payload['endgame_hash']);
        unset($payload['receipt_template']['preimage']['receipt_id']);
        unset($payload['receipt_draft']['receipt_payload']['receipt_id']);
        unset($payload['persistence_result']['persisted_at']);

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
