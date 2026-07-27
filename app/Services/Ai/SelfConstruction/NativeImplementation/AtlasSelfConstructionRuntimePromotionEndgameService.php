<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AtlasSelfConstructionRuntimePromotionEndgameService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_endgame.v1';

    public const MODE = 'read_only_runtime_promotion_endgame';

    private const CANONICAL_PUBLISHED_RECEIPT_PATH = 'atlas/self-construction/operator-submissions/runtime-promotion.json';

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
        $draftWorkspaceReceipt = $receiptProvided ? [] : $this->loadRuntimePromotionReceiptFromDraftWorkspace(
            (string) ($options['operator_draft_workspace_path'] ?? ''),
        );
        $workspaceReceipt = (array) data_get($draftWorkspaceReceipt, 'receipt_payload', []);
        $workspaceReceiptLoaded = $workspaceReceipt !== [];
        $canonicalSubmissionReceipt = (! $receiptProvided && ! $workspaceReceiptLoaded)
            ? $this->loadRuntimePromotionReceiptFromCanonicalSubmission()
            : [];
        $canonicalReceipt = (array) data_get($canonicalSubmissionReceipt, 'receipt_payload', []);
        $canonicalReceiptLoaded = $canonicalReceipt !== [];
        $persistRequested = (bool) ($options['persist_runtime_promotion_receipt'] ?? false);
        $skipCompletionSurfaces = (bool) ($options['skip_completion_surfaces'] ?? false);

        $receiptForMatrix = match (true) {
            $receiptProvided => $providedReceipt,
            $workspaceReceiptLoaded => $workspaceReceipt,
            $canonicalReceiptLoaded => $canonicalReceipt,
            default => [],
        };
        $matrix = (array) ($options['runtime_gap_matrix'] ?? (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => $receiptForMatrix,
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
        $receiptUnderReview = match (true) {
            $receiptProvided => $providedReceipt,
            $workspaceReceiptLoaded => $workspaceReceipt,
            $canonicalReceiptLoaded => $canonicalReceipt,
            default => $draftPayload,
        };
        $receiptUnderReviewSource = match (true) {
            $receiptProvided => 'operator_supplied_runtime_promotion_receipt',
            $workspaceReceiptLoaded => 'operator_draft_workspace_runtime_promotion_receipt',
            $canonicalReceiptLoaded => 'canonical_published_runtime_promotion_receipt',
            $draftPayload !== [] => 'generated_runtime_promotion_receipt_draft',
            default => 'none',
        };
        $receiptReadyForPersistence = $receiptProvided || $workspaceReceiptLoaded || $canonicalReceiptLoaded || $draftReady;

        $endgameVerifier = new AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
        $preSubmissionVerification = $receiptUnderReview !== []
            ? $endgameVerifier->verify($receiptUnderReview, $matrix)
            : $endgameVerifier->emptyVerification();
        $preSubmissionPassed = (string) data_get($preSubmissionVerification, 'status') === 'passed';
        $verifierViolationCodes = $this->verifierViolationCodes($preSubmissionVerification);
        $staleReceiptDetected = $receiptUnderReview !== [] && $this->staleReceiptDetected($verifierViolationCodes);

        $persistencePreflight = [
            'persist_flag_required' => '--persist-runtime-promotion-receipt',
            'gated_by_service' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
            'verifier_passed' => $preSubmissionPassed,
            'receipt_source' => $receiptUnderReviewSource,
            'receipt_provided' => $receiptProvided,
            'operator_draft_workspace_receipt_loaded' => $workspaceReceiptLoaded,
            'operator_draft_workspace_status' => (string) data_get($draftWorkspaceReceipt, 'status', 'not_requested'),
            'canonical_submission_receipt_loaded' => $canonicalReceiptLoaded,
            'canonical_submission_status' => (string) data_get($canonicalSubmissionReceipt, 'status', 'not_checked'),
            'draft_status' => (string) data_get($receiptDraft, 'status', $draftRequested ? 'blocked_operator_input_required' : 'not_drafted'),
            'persist_requested' => $persistRequested,
            'persistence_blocked_reason' => $this->persistenceBlockedReason($receiptUnderReview !== [], $receiptReadyForPersistence, $preSubmissionPassed, $persistRequested),
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'rerun_matrix_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
            'terminal_loop_operational_proof_command' => $this->terminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
            'rerun_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'effective_rerun_audit_with_canonical_terminal_loop_operational_proof_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'rerun_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];
        $operatorSubmissionEnvelope = $this->operatorSubmissionEnvelope(
            receipt: $receiptUnderReview,
            receiptSource: $receiptUnderReviewSource,
            verifierPassed: $preSubmissionPassed,
            persistRequested: $persistRequested,
            persisted: false,
        );

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
            $operatorSubmissionEnvelope = $this->operatorSubmissionEnvelope(
                receipt: $receiptUnderReview,
                receiptSource: $receiptUnderReviewSource,
                verifierPassed: $preSubmissionPassed,
                persistRequested: $persistRequested,
                persisted: $persisted,
            );
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
            $receiptUnderReview !== [] => 'receipt_loaded_verifier_blocked',
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
        $operatorShellPacket = $this->operatorNextActionShellPacket(
            status: $status,
            receiptSource: $receiptUnderReviewSource,
            verifierPassed: $preSubmissionPassed,
            persisted: $persisted,
            draftPath: (string) data_get($draftWorkspaceReceipt, 'draft_path', ''),
            canonicalReceiptLoaded: $canonicalReceiptLoaded,
            verifierViolationCodes: $verifierViolationCodes,
            staleReceiptDetected: $staleReceiptDetected,
        );

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
                'provided' => $receiptProvided || $workspaceReceiptLoaded || $canonicalReceiptLoaded,
                'provided_by_cli_payload' => $receiptProvided,
                'provided_by_operator_draft_workspace' => $workspaceReceiptLoaded,
                'provided_by_canonical_submission' => $canonicalReceiptLoaded,
                'present' => $receiptUnderReview !== [],
                'receipt_hash' => (string) ($receiptUnderReview['receipt_hash'] ?? ''),
                'receipt_id' => (string) ($receiptUnderReview['receipt_id'] ?? ''),
                'signed_by' => (string) ($receiptUnderReview['signed_by'] ?? ''),
            ],
            'operator_draft_workspace_receipt' => $draftWorkspaceReceipt,
            'canonical_submission_receipt' => $canonicalSubmissionReceipt,
            'operator_submission_envelope' => $operatorSubmissionEnvelope,
            'operator_next_action_shell_packet' => $operatorShellPacket,
            'verifier_violation_codes' => $verifierViolationCodes,
            'stale_runtime_promotion_receipt_detected' => $staleReceiptDetected,
            'fresh_runtime_promotion_receipt_required' => $staleReceiptDetected,
            'fresh_runtime_promotion_receipt_recovery_command' => $staleReceiptDetected
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json'
                : '',
            'receipt_pre_submission_verification' => $preSubmissionVerification,
            'persistence_preflight' => $persistencePreflight,
            'persistence_result' => $persistenceResult,
            'persisted' => $persisted,
            'post_persistence_next_commands' => $persisted ? [
                'rerun_runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'rerun_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
                'rerun_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'rerun_completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
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
                'reject_persistence_without_operator_submission_envelope',
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
                'operator_submission_envelope_does_not_write_files_or_receipts',
                'operator_next_action_shell_packet_does_not_execute_or_persist',
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
        $placeholders = ['', '<operator>', 'operator', 'human', 'codex', 'assistant', 'system', 'claude', 'codex-autosigned', 'atlas', 'seu_nome', 'seu nome', '<operador>', 'operador'];

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

    /** @return array<string, mixed> */
    private function loadRuntimePromotionReceiptFromDraftWorkspace(string $requestedPath): array
    {
        $requestedPath = trim($requestedPath);
        if ($requestedPath === '') {
            return [
                'status' => 'not_requested',
                'requested_path' => '',
                'manifest_path' => '',
                'draft_path' => '',
                'receipt_payload' => [],
                'violations' => [],
            ];
        }

        $path = $this->normalizeStoragePath($requestedPath);
        if ($path === '') {
            return [
                'status' => 'blocked',
                'requested_path' => $requestedPath,
                'manifest_path' => '',
                'draft_path' => '',
                'receipt_payload' => [],
                'violations' => ['invalid_operator_draft_workspace_path'],
            ];
        }

        $manifestPath = '';
        $draftPath = '';
        if (str_ends_with($path, 'runtime-promotion.json')) {
            $draftPath = $path;
        } else {
            $manifestPath = str_ends_with($path, 'manifest.json') ? $path : rtrim($path, '/').'/manifest.json';
            if (! Storage::disk('local')->exists($manifestPath)) {
                return [
                    'status' => 'blocked',
                    'requested_path' => $requestedPath,
                    'manifest_path' => $manifestPath,
                    'draft_path' => '',
                    'receipt_payload' => [],
                    'violations' => ['operator_draft_workspace_manifest_not_found'],
                ];
            }
            $manifest = $this->readStorageJson($manifestPath);
            foreach ((array) data_get($manifest, 'files', []) as $file) {
                if ((string) data_get($file, 'artifact') === 'runtime_promotion_receipt') {
                    $draftPath = (string) data_get($file, 'draft_path', '');
                    break;
                }
            }
        }

        if ($draftPath === '' || ! Storage::disk('local')->exists($draftPath)) {
            return [
                'status' => 'blocked',
                'requested_path' => $requestedPath,
                'manifest_path' => $manifestPath,
                'draft_path' => $draftPath,
                'receipt_payload' => [],
                'violations' => ['runtime_promotion_draft_file_not_found'],
            ];
        }

        $payload = $this->readStorageJson($draftPath);
        if ($payload === []) {
            return [
                'status' => 'blocked',
                'requested_path' => $requestedPath,
                'manifest_path' => $manifestPath,
                'draft_path' => $draftPath,
                'receipt_payload' => [],
                'violations' => ['runtime_promotion_draft_file_invalid_json_or_empty'],
            ];
        }

        return [
            'status' => 'loaded_for_endgame_review',
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'draft_path' => $draftPath,
            'receipt_payload' => $payload,
            'violations' => [],
            'non_execution_guarantees' => [
                'operator_draft_workspace_loader_reads_only',
                'operator_draft_workspace_loader_does_not_mark_draft_as_evidence',
                'operator_draft_workspace_loader_does_not_persist_receipts',
                'operator_draft_workspace_loader_does_not_sign_for_operator',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function loadRuntimePromotionReceiptFromCanonicalSubmission(): array
    {
        if (! Storage::disk('local')->exists(self::CANONICAL_PUBLISHED_RECEIPT_PATH)) {
            return [
                'status' => 'not_found',
                'submission_path' => self::CANONICAL_PUBLISHED_RECEIPT_PATH,
                'receipt_payload' => [],
                'violations' => [],
            ];
        }

        $payload = $this->readStorageJson(self::CANONICAL_PUBLISHED_RECEIPT_PATH);
        if ($payload === []) {
            return [
                'status' => 'blocked',
                'submission_path' => self::CANONICAL_PUBLISHED_RECEIPT_PATH,
                'receipt_payload' => [],
                'violations' => ['canonical_runtime_promotion_submission_invalid_json_or_empty'],
            ];
        }

        return [
            'status' => 'loaded_for_endgame_review',
            'submission_path' => self::CANONICAL_PUBLISHED_RECEIPT_PATH,
            'receipt_payload' => $payload,
            'violations' => [],
            'non_execution_guarantees' => [
                'canonical_submission_loader_reads_only',
                'canonical_submission_loader_does_not_mark_submission_as_persisted_evidence',
                'canonical_submission_loader_does_not_persist_receipts',
                'canonical_submission_loader_does_not_sign_for_operator',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readStorageJson(string $path): array
    {
        try {
            $decoded = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        $path = preg_replace('#^storage/app/private/#', '', $path) ?? $path;
        $path = preg_replace('#^storage/app/#', '', $path) ?? $path;

        return trim($path, '/');
    }

    /** @param array<string, mixed> $receipt */
    private function operatorSubmissionEnvelope(
        array $receipt,
        string $receiptSource,
        bool $verifierPassed,
        bool $persistRequested,
        bool $persisted,
    ): array {
        $receiptPresent = $receipt !== [];
        $receiptJson = $receiptPresent
            ? (string) json_encode($this->ksortRecursive($receipt), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $receiptJsonHash = $receiptJson !== '' ? hash('sha256', $receiptJson) : '';
        $status = match (true) {
            $persisted => 'persisted_runtime_promotion_receipt',
            $receiptPresent && $verifierPassed => 'ready_for_explicit_operator_persistence',
            $receiptPresent => 'blocked_until_verifier_passes',
            default => 'blocked_until_operator_receipt_or_draft_exists',
        };

        $envelope = [
            'schema_version' => 'atlas.self_construction.runtime_promotion_operator_submission_envelope.v1',
            'status' => $status,
            'receipt_source' => $receiptSource,
            'receipt_present' => $receiptPresent,
            'verifier_passed' => $verifierPassed,
            'persist_requested' => $persistRequested,
            'persisted' => $persisted,
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'receipt_payload' => $receipt,
            'receipt_json_sha256' => $receiptJsonHash,
            'receipt_file_hint' => 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            'receipt_private_storage_file_hint' => 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            'exact_persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'post_persistence_commands' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                $this->terminalLoopOperationalProofCommand(),
                $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
            'effective_completion_audit_with_canonical_terminal_loop_operational_proof_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'operator_checks_before_persisting' => [
                'receipt_json_sha256_matches_saved_file',
                'receipt_hash_matches_payload',
                'verifier_passed_true',
                'persist_command_contains_explicit_persist_runtime_promotion_receipt_flag',
                'no_runtime_enabling_flags_true',
            ],
            'non_execution_guarantees' => [
                'operator_submission_envelope_does_not_sign_for_operator',
                'operator_submission_envelope_does_not_persist_receipts',
                'operator_submission_envelope_does_not_enable_runtime',
                'operator_submission_envelope_does_not_call_provider',
                'operator_submission_envelope_does_not_dispatch',
                'operator_submission_envelope_does_not_spend_tokens',
            ],
        ];
        $envelope['envelope_hash'] = $this->stableHash($envelope);

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    private function operatorNextActionShellPacket(
        string $status,
        string $receiptSource,
        bool $verifierPassed,
        bool $persisted,
        string $draftPath,
        bool $canonicalReceiptLoaded,
        array $verifierViolationCodes,
        bool $staleReceiptDetected,
    ): array {
        $draftCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json';
        $draftReceiptPayloadPath = '.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload';
        $canonicalDraftFileCommand = 'mkdir -p storage/app/private/atlas/self-construction/operator-submissions && '
            .$draftCommand
            .' | jq \''.$draftReceiptPayloadPath.'\''
            .' > storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json';
        $canonicalPersistCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json --persist-runtime-promotion-receipt --json';
        $workspacePersistCommand = $draftPath !== ''
            ? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@storage/app/private/'.$draftPath.' --persist-runtime-promotion-receipt --json'
            : '';

        $commandToCopy = match (true) {
            $persisted => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
            $verifierPassed && $canonicalReceiptLoaded => $canonicalPersistCommand,
            $verifierPassed && $receiptSource === 'operator_draft_workspace_runtime_promotion_receipt' && $workspacePersistCommand !== '' => $workspacePersistCommand,
            $verifierPassed => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            default => $draftCommand,
        };
        $placeholders = $this->commandPlaceholders($commandToCopy);
        $copySafe = $placeholders === []
            && (
                $persisted
                || ($verifierPassed && ($canonicalReceiptLoaded || ($receiptSource === 'operator_draft_workspace_runtime_promotion_receipt' && $workspacePersistCommand !== '')))
            );
        $packetStatus = match (true) {
            $persisted => 'copy_ready_rerun_runtime_gap_matrix',
            $staleReceiptDetected => 'blocked_stale_runtime_promotion_receipt_regenerate_draft',
            $placeholders !== [] => 'blocked_placeholder_replacement_required',
            $copySafe => 'copy_ready_for_explicit_operator_persistence',
            $verifierPassed => 'blocked_receipt_file_path_required',
            default => 'blocked_runtime_promotion_receipt_required',
        };

        $packet = [
            'schema_version' => 'atlas.self_construction.runtime_promotion_endgame_next_action_shell_packet.v1',
            'mode' => 'read_only_runtime_promotion_endgame_next_action_shell_packet',
            'status' => $packetStatus,
            'endgame_status' => $status,
            'receipt_source' => $receiptSource,
            'verifier_passed' => $verifierPassed,
            'verifier_violation_codes' => $verifierViolationCodes,
            'stale_runtime_promotion_receipt_detected' => $staleReceiptDetected,
            'fresh_runtime_promotion_receipt_required' => $staleReceiptDetected,
            'stale_runtime_promotion_receipt_blocking_codes' => $staleReceiptDetected
                ? array_values(array_intersect($verifierViolationCodes, $this->staleReceiptViolationCodes()))
                : [],
            'stale_runtime_promotion_receipt_must_be_discarded' => $staleReceiptDetected,
            'fresh_runtime_promotion_receipt_recovery_command' => $staleReceiptDetected ? $draftCommand : '',
            'fresh_runtime_promotion_receipt_recovery_file_command' => $staleReceiptDetected ? $canonicalDraftFileCommand : '',
            'fresh_runtime_promotion_receipt_recovery_file_command_hash' => $staleReceiptDetected ? hash('sha256', $canonicalDraftFileCommand) : '',
            'fresh_runtime_promotion_receipt_recovery_file_command_payload_path' => $staleReceiptDetected ? $draftReceiptPayloadPath : '',
            'fresh_runtime_promotion_receipt_recovery_file_command_placeholder_fields' => $staleReceiptDetected ? $this->commandPlaceholders($canonicalDraftFileCommand) : [],
            'fresh_runtime_promotion_receipt_recovery_file_command_copy_safe' => $staleReceiptDetected && $this->commandPlaceholders($canonicalDraftFileCommand) === [],
            'persisted' => $persisted,
            'command_to_copy' => $commandToCopy,
            'command_to_copy_hash' => hash('sha256', $commandToCopy),
            'draft_command_template' => $draftCommand,
            'canonical_persist_command' => $canonicalPersistCommand,
            'workspace_persist_command' => $workspacePersistCommand,
            'placeholder_count' => count($placeholders),
            'placeholders' => $placeholders,
            'copy_safe' => $copySafe,
            'requires_fresh_endgame_status_before_copy' => true,
            'requires_verifier_green_before_persist' => true,
            'requires_explicit_persist_flag' => true,
            'requires_receipt_file_path_when_payload_supplied_inline' => $verifierPassed && ! $copySafe && ! $persisted,
            'can_resume_without_chat_history' => true,
            'post_action_proof_commands' => [
                'runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'operator_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            ],
            'success_check' => 'runtime_promotion_receipt persisted AND runtime_gap_matrix_all_runtime_y=true after rerun',
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_verifier_status_is_not_passed',
                'stop_if_stale_runtime_promotion_receipt_detected',
                'stop_if_receipt_file_path_is_missing_for_persist_command',
                'stop_if_hash_mismatch',
                'stop_if_persist_command_is_run_without_explicit_operator_review',
            ],
            'non_execution_guarantees' => [
                'shell_packet_does_not_execute_command',
                'shell_packet_does_not_persist_receipts',
                'shell_packet_does_not_enable_runtime',
                'shell_packet_does_not_call_provider',
                'shell_packet_does_not_spend_tokens',
                'shell_packet_does_not_dispatch_work',
                'shell_packet_does_not_sign_for_operator',
                'shell_packet_does_not_promote_completion',
            ],
        ];
        $packet['shell_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /** @return list<string> */
    private function commandPlaceholders(string $command): array
    {
        preg_match_all('/<[^>]+>/', $command, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /** @return list<string> */
    private function verifierViolationCodes(array $verification): array
    {
        $codes = [];
        foreach ((array) data_get($verification, 'violations', []) as $violation) {
            $code = (string) data_get($violation, 'code', '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** @param list<string> $violationCodes */
    private function staleReceiptDetected(array $violationCodes): bool
    {
        foreach ($this->staleReceiptViolationCodes() as $staleCode) {
            if (in_array($staleCode, $violationCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function staleReceiptViolationCodes(): array
    {
        return [
            'stale_runtime_gap_matrix_hash',
            'stale_runtime_promotion_basis_hash',
            'stale_runtime_promotion_closure_basis_hash',
            'promoted_gap_id_drift',
            'graduation_hash_mismatch',
        ];
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

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofCanonicalBindingPath().' --json';
    }

    private function terminalLoopOperationalProofCanonicalBindingPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
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
}
