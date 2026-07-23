<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 02 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergeFinalSignatureRequest
 *           .. codexReviewMergePostExecutionActionPostSignatureRunbook
 */
final class CodexReviewMergePart02SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergeFinalSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergeFinalReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $requestReady = data_get($receiptPayload, 'status') === 'merge_final_receipt_draft_ready';

        $signablePayload = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-FINAL-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_authorizing_action_template_hash' => data_get($receipt, 'source_authorizing_action_template_hash'),
            'source_final_authorization_preflight_hash' => data_get($receipt, 'source_final_authorization_preflight_hash'),
            'source_authorization_signature_request_hash' => data_get($receipt, 'source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($receipt, 'source_authorization_signable_payload_hash'),
            'requested_signature_type' => 'human_or_governed_final_merge_receipt_signature',
            'allowed_decisions' => (array) data_get($receipt, 'allowed_decisions', []),
            'default_decision' => data_get($receipt, 'default_decision'),
            'drafted_authorization_fields' => (array) data_get($receipt, 'drafted_authorization_fields', []),
            'required_before_final_receipt_can_be_signed' => (array) data_get($receipt, 'required_before_final_receipt_can_be_signed', []),
            'future_executor_contract' => (array) data_get($receipt, 'future_executor_contract', []),
            'still_forbidden_after_signature_request' => [
                'signature_acceptance_by_final_signature_request',
                'signature_validation_by_final_signature_request',
                'decision_recording_by_final_signature_request',
                'approval_from_final_signature_request',
                'merge_from_final_signature_request',
                'dispatch_from_final_signature_request',
            ],
        ];

        $signatureRequest = [
            'request_id' => 'CODEX-REVIEW-MERGE-FINAL-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_external_final_merge_receipt_signature' : 'blocked_before_final_receipt_draft',
            'source_final_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_final_signature_request.v1',
            'status' => $requestReady ? 'merge_final_signature_request_pending' : 'merge_final_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_final_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'signature_request' => $signatureRequest,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_final_signature_request_does_not_claim_packets',
                'codex_review_merge_final_signature_request_does_not_complete_packets',
                'codex_review_merge_final_signature_request_does_not_accept_signature',
                'codex_review_merge_final_signature_request_does_not_validate_signature',
                'codex_review_merge_final_signature_request_does_not_record_decision',
                'codex_review_merge_final_signature_request_does_not_approve_code',
                'codex_review_merge_final_signature_request_does_not_merge',
                'codex_review_merge_final_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review merge final signature request is pending as a signable receipt payload. It still does not accept, validate, approve or merge.'
                : 'Codex review merge final signature request is blocked until final receipt draft is ready.',
        ];
    }

    public function codexReviewMergeFinalPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergeFinalSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_final_signature_request_pending';

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-FINAL-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_final_signature_evidence' : 'blocked_before_final_signature_request',
            'source_final_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_final_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'source_final_receipt_hash' => data_get($signaturePayload, 'signature_request.source_final_receipt_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_external_inputs' => [
                'external_final_merge_receipt_signature_value',
                'external_final_merge_receipt_signature_validator_identity',
                'external_final_merge_receipt_signature_validation_timestamp',
                'validated_final_signable_payload_hash',
                'validated_final_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_merge_preflight_hash',
                'fresh_test_output_hash',
                'fresh_docs_health_output_hash',
                'fresh_architecture_validate_output_hash',
                'fresh_diff_check_output_hash',
                'scope_integrity_statement',
                'hot_scope_exclusion_statement',
                'packet_evidence_integrity_statement',
                'rollback_plan_hash',
                'human_confirmation_hash',
            ],
            'ordered_steps_after_external_final_signature' => [
                'verify_external_final_signature_was_validated_outside_this_command',
                'confirm_validated_final_signable_payload_hash_matches_source',
                'confirm_validated_final_receipt_hash_matches_source',
                'confirm_selected_decision_equals_merge',
                'rerun_or_verify_fresh_quality_gate_hashes',
                'verify_scope_integrity_and_hot_scope_exclusions',
                'verify_packet_evidence_integrity',
                'prepare_separate_signed_final_receipt_surface',
            ],
            'blocking_conditions' => [
                'missing_external_final_signature',
                'final_signature_not_validated_by_governed_actor',
                'final_signable_payload_hash_mismatch',
                'final_receipt_hash_mismatch',
                'selected_decision_is_not_merge',
                'fresh_gate_hash_missing_or_failed',
                'scope_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'packet_evidence_integrity_failure',
                'missing_rollback_plan_hash',
                'missing_human_confirmation_hash',
            ],
            'future_signed_receipt_surface_requirements' => [
                'must_be_separate_command_or_endpoint',
                'must_accept_explicit_external_final_signature_evidence',
                'must_validate_signature_against_final_signable_payload_hash',
                'must_persist_signed_final_merge_receipt_append_only',
                'must_keep_patch_execution_separate',
                'must_require_executor_to_consume_signed_final_merge_receipt',
            ],
            'still_forbidden_after_runbook' => [
                'signature_validation_by_final_post_signature_runbook',
                'receipt_signing_by_final_post_signature_runbook',
                'decision_recording_by_final_post_signature_runbook',
                'approval_from_final_post_signature_runbook',
                'merge_from_final_post_signature_runbook',
                'dispatch_from_final_post_signature_runbook',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_final_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_final_post_signature_runbook_ready' : 'merge_final_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_final_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_final_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_final_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_final_post_signature_runbook_does_not_accept_signature',
                'codex_review_merge_final_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_final_post_signature_runbook_does_not_sign_receipt',
                'codex_review_merge_final_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_final_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_final_post_signature_runbook_does_not_merge',
                'codex_review_merge_final_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge final post-signature runbook is ready as a non-executing checklist. It still does not validate, sign, approve or merge.'
                : 'Codex review merge final post-signature runbook is blocked until final signature request is pending.',
        ];
    }

    public function codexReviewMergeSignedFinalReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergeFinalPostSignatureRunbook($options);
        $templateReady = data_get($runbookPayload, 'status') === 'merge_final_post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_signed_final_receipt_evidence' : 'blocked_before_final_post_signature_runbook',
            'source_final_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_final_signature_request_hash' => data_get($runbookPayload, 'runbook.source_final_signature_request_hash'),
            'source_final_signable_payload_hash' => data_get($runbookPayload, 'runbook.source_final_signable_payload_hash'),
            'source_final_receipt_hash' => data_get($runbookPayload, 'runbook.source_final_receipt_hash'),
            'required_external_evidence_for_future_signed_receipt' => [
                'external_final_merge_receipt_signature_value',
                'external_final_merge_receipt_signature_validator_identity',
                'external_final_merge_receipt_signature_validation_timestamp',
                'validated_final_signable_payload_hash',
                'validated_final_receipt_hash',
                'validated_selected_decision',
                'validated_gate_hashes',
                'validated_scope_integrity_result',
                'validated_packet_evidence_integrity_result',
                'validated_rollback_plan_hash',
                'validated_human_confirmation_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_final_receipt_id',
                'source_final_receipt_hash',
                'source_final_signable_payload_hash',
                'source_final_post_signature_runbook_hash',
                'signature_hash',
                'signature_validator_identity',
                'signature_validated_at',
                'selected_decision',
                'decision_rationale',
                'gate_hashes',
                'scope_integrity_result',
                'packet_evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'executor_contract_hash',
                'signed_by',
                'signed_at',
            ],
            'required_validations_before_persisting_signed_receipt' => [
                'signature_validates_against_final_signable_payload_hash',
                'final_receipt_hash_matches_source',
                'selected_decision_equals_merge',
                'gate_hashes_are_fresh_and_passing',
                'scope_integrity_passed',
                'hot_scope_exclusion_passed',
                'packet_evidence_integrity_passed',
                'rollback_plan_hash_present',
                'human_confirmation_hash_present',
            ],
            'future_executor_release_conditions' => [
                'signed_final_receipt_persisted_append_only',
                'signed_final_receipt_hash_verified',
                'executor_consumes_signed_final_receipt_only',
                'executor_reruns_last_minute_diff_check',
                'executor_reruns_hot_scope_check',
                'executor_emits_execution_evidence',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_signed_final_receipt_template',
                'signature_validation_by_signed_final_receipt_template',
                'receipt_persistence_by_signed_final_receipt_template',
                'decision_recording_by_signed_final_receipt_template',
                'approval_from_signed_final_receipt_template',
                'merge_from_signed_final_receipt_template',
                'dispatch_from_signed_final_receipt_template',
            ],
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_signed_final_receipt_template.v1',
            'status' => $templateReady ? 'merge_signed_final_receipt_template_ready' : 'merge_signed_final_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_signed_final_receipt_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'executor_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_signed_final_receipt_template_does_not_claim_packets',
                'codex_review_merge_signed_final_receipt_template_does_not_complete_packets',
                'codex_review_merge_signed_final_receipt_template_does_not_accept_signature',
                'codex_review_merge_signed_final_receipt_template_does_not_validate_signature',
                'codex_review_merge_signed_final_receipt_template_does_not_persist_receipt',
                'codex_review_merge_signed_final_receipt_template_does_not_record_decision',
                'codex_review_merge_signed_final_receipt_template_does_not_approve_code',
                'codex_review_merge_signed_final_receipt_template_does_not_merge',
                'codex_review_merge_signed_final_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge signed final receipt template is ready as a non-persisting contract. It still does not accept, validate, sign, approve or merge.'
                : 'Codex review merge signed final receipt template is blocked until final post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergeSignedFinalReceiptPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergeSignedFinalReceiptTemplate($options);
        $preflightReady = data_get($templatePayload, 'status') === 'merge_signed_final_receipt_template_ready';

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_signed_final_receipt_inputs' : 'blocked_before_signed_final_receipt_template',
            'source_signed_final_receipt_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_final_receipt_hash' => data_get($templatePayload, 'template.source_final_receipt_hash'),
            'source_final_signable_payload_hash' => data_get($templatePayload, 'template.source_final_signable_payload_hash'),
            'source_final_post_signature_runbook_hash' => data_get($templatePayload, 'template.source_final_post_signature_runbook_hash'),
            'required_external_inputs' => (array) data_get($templatePayload, 'template.required_external_evidence_for_future_signed_receipt', []),
            'required_preflight_checks' => [
                'external_signature_evidence_present',
                'signature_validator_identity_present',
                'signature_validation_timestamp_present',
                'validated_final_signable_payload_hash_matches_source',
                'validated_final_receipt_hash_matches_source',
                'validated_selected_decision_equals_merge',
                'validated_gate_hashes_present',
                'validated_scope_integrity_passed',
                'validated_packet_evidence_integrity_passed',
                'validated_rollback_plan_hash_present',
                'validated_human_confirmation_hash_present',
            ],
            'blocking_conditions' => [
                'missing_external_signature_evidence',
                'missing_signature_validator_identity',
                'missing_signature_validation_timestamp',
                'final_signable_payload_hash_mismatch',
                'final_receipt_hash_mismatch',
                'selected_decision_not_merge',
                'gate_hash_missing_or_failed',
                'scope_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'packet_evidence_integrity_failure',
                'missing_rollback_plan_hash',
                'missing_human_confirmation_hash',
            ],
            'future_persistence_requirements' => [
                'persist_signed_final_receipt_append_only',
                'include_all_signed_receipt_fields',
                'hash_signed_receipt_before_executor_release',
                'keep_patch_execution_separate',
                'emit_executor_release_preflight_after_persistence',
            ],
            'still_forbidden_after_preflight' => [
                'signature_acceptance_by_signed_final_receipt_preflight',
                'signature_validation_by_signed_final_receipt_preflight',
                'receipt_persistence_by_signed_final_receipt_preflight',
                'decision_recording_by_signed_final_receipt_preflight',
                'approval_from_signed_final_receipt_preflight',
                'executor_release_from_signed_final_receipt_preflight',
                'merge_from_signed_final_receipt_preflight',
                'dispatch_from_signed_final_receipt_preflight',
            ],
            'signature_valid' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_signed_final_receipt_preflight.v1',
            'status' => $preflightReady ? 'merge_signed_final_receipt_preflight_ready' : 'merge_signed_final_receipt_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_signed_final_receipt_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'executor_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_signed_final_receipt_preflight_does_not_claim_packets',
                'codex_review_merge_signed_final_receipt_preflight_does_not_complete_packets',
                'codex_review_merge_signed_final_receipt_preflight_does_not_accept_signature',
                'codex_review_merge_signed_final_receipt_preflight_does_not_validate_signature',
                'codex_review_merge_signed_final_receipt_preflight_does_not_persist_receipt',
                'codex_review_merge_signed_final_receipt_preflight_does_not_record_decision',
                'codex_review_merge_signed_final_receipt_preflight_does_not_release_executor',
                'codex_review_merge_signed_final_receipt_preflight_does_not_merge',
                'codex_review_merge_signed_final_receipt_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge signed final receipt preflight is ready as a non-persisting check contract. It still does not accept, validate, persist, release executor or merge.'
                : 'Codex review merge signed final receipt preflight is blocked until signed final receipt template is ready.',
        ];
    }

    public function codexReviewMergeSignedFinalReceiptPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergeSignedFinalReceiptPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_signed_final_receipt_preflight_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-PERSISTENCE-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_signed_receipt_persistence_evidence' : 'blocked_before_signed_final_receipt_preflight',
            'source_signed_final_receipt_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_signed_final_receipt_template_hash' => data_get($preflightPayload, 'preflight.source_signed_final_receipt_template_hash'),
            'source_final_receipt_hash' => data_get($preflightPayload, 'preflight.source_final_receipt_hash'),
            'source_final_signable_payload_hash' => data_get($preflightPayload, 'preflight.source_final_signable_payload_hash'),
            'required_external_inputs' => [
                'external_signature_evidence',
                'signature_validator_identity',
                'signature_validation_timestamp',
                'validated_final_signable_payload_hash',
                'validated_final_receipt_hash',
                'validated_selected_decision',
                'validated_gate_hashes',
                'validated_scope_integrity_result',
                'validated_packet_evidence_integrity_result',
                'validated_rollback_plan_hash',
                'validated_human_confirmation_hash',
            ],
            'append_only_persistence_fields' => [
                'signed_final_receipt_id',
                'source_signed_final_receipt_preflight_hash',
                'source_signed_final_receipt_template_hash',
                'source_final_receipt_hash',
                'source_final_signable_payload_hash',
                'signature_hash',
                'signature_validator_identity',
                'signature_validated_at',
                'selected_decision',
                'decision_rationale',
                'gate_hashes',
                'scope_integrity_result',
                'packet_evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'executor_contract_hash',
                'persisted_by',
                'persisted_at',
            ],
            'required_persistence_validations' => [
                'all_preflight_checks_passed',
                'signature_hash_matches_external_evidence',
                'selected_decision_equals_merge',
                'source_hashes_match_preflight',
                'append_only_store_available',
                'receipt_id_is_unique',
                'executor_contract_hash_present',
                'no_patch_execution_in_persistence_surface',
            ],
            'future_executor_release_requirements' => [
                'signed_final_receipt_persisted_append_only',
                'signed_final_receipt_hash_verified',
                'executor_release_preflight_ready',
                'executor_consumes_signed_final_receipt_only',
                'executor_reruns_last_minute_diff_check',
                'executor_reruns_hot_scope_check',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_signed_final_receipt_persistence_template',
                'signature_validation_by_signed_final_receipt_persistence_template',
                'receipt_persistence_by_signed_final_receipt_persistence_template',
                'decision_recording_by_signed_final_receipt_persistence_template',
                'executor_release_by_signed_final_receipt_persistence_template',
                'merge_from_signed_final_receipt_persistence_template',
                'dispatch_from_signed_final_receipt_persistence_template',
            ],
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_signed_final_receipt_persistence_template.v1',
            'status' => $templateReady ? 'merge_signed_final_receipt_persistence_template_ready' : 'merge_signed_final_receipt_persistence_template_blocked',
            'mode' => 'read_only_codex_review_merge_signed_final_receipt_persistence_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_claim_packets',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_complete_packets',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_accept_signature',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_validate_signature',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_persist_receipt',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_record_decision',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_release_executor',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_merge',
                'codex_review_merge_signed_final_receipt_persistence_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge signed final receipt persistence template is ready as a read-only append-only storage contract. It still does not accept, validate, persist, release executor or merge.'
                : 'Codex review merge signed final receipt persistence template is blocked until signed final receipt preflight is ready.',
        ];
    }

    public function codexReviewMergeExecutorReleasePreflight(array $options = []): array
    {
        $persistencePayload = $this->parent->codexReviewMergeSignedFinalReceiptPersistenceTemplate($options);
        $preflightReady = data_get($persistencePayload, 'status') === 'merge_signed_final_receipt_persistence_template_ready';

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-EXECUTOR-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_persisted_signed_final_receipt_inputs' : 'blocked_before_signed_final_receipt_persistence_template',
            'source_signed_final_receipt_persistence_template_hash' => data_get($persistencePayload, 'template_hash'),
            'source_signed_final_receipt_preflight_hash' => data_get($persistencePayload, 'template.source_signed_final_receipt_preflight_hash'),
            'source_signed_final_receipt_template_hash' => data_get($persistencePayload, 'template.source_signed_final_receipt_template_hash'),
            'source_final_receipt_hash' => data_get($persistencePayload, 'template.source_final_receipt_hash'),
            'source_final_signable_payload_hash' => data_get($persistencePayload, 'template.source_final_signable_payload_hash'),
            'required_external_inputs' => [
                'persisted_signed_final_receipt_id',
                'persisted_signed_final_receipt_hash',
                'append_only_receipt_event_hash',
                'executor_contract_hash',
                'final_diff_check_hash',
                'hot_scope_check_hash',
                'docs_health_hash',
                'architecture_validate_hash',
                'focused_test_matrix_hash',
                'human_executor_release_confirmation_hash',
            ],
            'required_release_checks' => [
                'signed_final_receipt_persistence_template_ready',
                'persisted_signed_final_receipt_id_present',
                'persisted_signed_final_receipt_hash_present',
                'append_only_receipt_event_hash_present',
                'persisted_receipt_sources_match_template_hashes',
                'executor_contract_hash_matches_persisted_receipt',
                'final_diff_check_passed',
                'hot_scope_check_passed',
                'docs_health_passed',
                'architecture_validate_passed',
                'focused_test_matrix_passed',
                'human_executor_release_confirmation_present',
            ],
            'blocking_conditions' => [
                'missing_persisted_signed_final_receipt',
                'missing_append_only_receipt_event',
                'receipt_source_hash_mismatch',
                'executor_contract_hash_mismatch',
                'final_diff_check_failed',
                'hot_voice_or_kernel_scope_touched',
                'docs_health_failed',
                'architecture_validate_failed',
                'focused_test_matrix_failed',
                'missing_human_executor_release_confirmation',
            ],
            'future_executor_contract_requirements' => [
                'executor_consumes_persisted_signed_final_receipt_only',
                'executor_revalidates_receipt_hash_before_patch',
                'executor_revalidates_final_diff_before_patch',
                'executor_revalidates_hot_scope_before_patch',
                'executor_emits_execution_receipt',
                'executor_stops_before_merge_on_any_gate_failure',
            ],
            'still_forbidden_by_preflight' => [
                'persisted_receipt_acceptance_by_executor_release_preflight',
                'receipt_persistence_by_executor_release_preflight',
                'decision_recording_by_executor_release_preflight',
                'executor_release_by_executor_release_preflight',
                'patch_execution_by_executor_release_preflight',
                'merge_from_executor_release_preflight',
                'dispatch_from_executor_release_preflight',
            ],
            'receipt_persisted' => false,
            'approval_granted' => false,
            'executor_allowed' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_executor_release_preflight.v1',
            'status' => $preflightReady ? 'merge_executor_release_preflight_ready' : 'merge_executor_release_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_executor_release_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'receipt_persisted' => false,
            'approval_granted' => false,
            'executor_allowed' => false,
            'merge_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_executor_release_preflight_does_not_claim_packets',
                'codex_review_merge_executor_release_preflight_does_not_complete_packets',
                'codex_review_merge_executor_release_preflight_does_not_accept_persisted_receipt',
                'codex_review_merge_executor_release_preflight_does_not_persist_receipt',
                'codex_review_merge_executor_release_preflight_does_not_record_decision',
                'codex_review_merge_executor_release_preflight_does_not_release_executor',
                'codex_review_merge_executor_release_preflight_does_not_execute_patch',
                'codex_review_merge_executor_release_preflight_does_not_merge',
                'codex_review_merge_executor_release_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge executor release preflight is ready as a read-only release prerequisite contract. It still does not accept persisted receipt evidence, release an executor, execute patches or merge.'
                : 'Codex review merge executor release preflight is blocked until signed final receipt persistence template is ready.',
        ];
    }

    public function codexReviewMergeExecutorContractTemplate(array $options = []): array
    {
        $releasePayload = $this->parent->codexReviewMergeExecutorReleasePreflight($options);
        $templateReady = data_get($releasePayload, 'status') === 'merge_executor_release_preflight_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-EXECUTOR-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_executor_release_authority' : 'blocked_before_executor_release_preflight',
            'source_executor_release_preflight_hash' => data_get($releasePayload, 'preflight_hash'),
            'source_signed_final_receipt_persistence_template_hash' => data_get($releasePayload, 'preflight.source_signed_final_receipt_persistence_template_hash'),
            'source_signed_final_receipt_preflight_hash' => data_get($releasePayload, 'preflight.source_signed_final_receipt_preflight_hash'),
            'source_signed_final_receipt_template_hash' => data_get($releasePayload, 'preflight.source_signed_final_receipt_template_hash'),
            'source_final_receipt_hash' => data_get($releasePayload, 'preflight.source_final_receipt_hash'),
            'required_executor_inputs' => [
                'executor_release_authority_hash',
                'persisted_signed_final_receipt_id',
                'persisted_signed_final_receipt_hash',
                'executor_contract_hash',
                'final_diff_check_hash',
                'hot_scope_check_hash',
                'rollback_plan_hash',
                'human_executor_release_confirmation_hash',
            ],
            'executor_must_revalidate' => [
                'persisted_signed_final_receipt_hash_matches_contract',
                'executor_release_authority_hash_matches_preflight',
                'final_diff_check_still_clean',
                'hot_scope_check_still_clean',
                'docs_health_still_clean',
                'architecture_validate_still_clean',
                'focused_test_matrix_still_clean',
                'rollback_plan_available',
            ],
            'allowed_future_executor_actions' => [
                'read_persisted_signed_final_receipt',
                'read_current_diff',
                'read_scope_validator_report',
                'read_gate_reports',
                'apply_only_receipt_bound_patch_set',
                'emit_executor_evidence_receipt',
                'stop_on_any_mismatch',
            ],
            'forbidden_future_executor_actions' => [
                'modify_voice_or_kernel_hot_scope_without_new_receipt',
                'expand_scope_beyond_signed_receipt',
                'skip_final_diff_check',
                'skip_hot_scope_check',
                'skip_docs_health',
                'skip_architecture_validate',
                'skip_focused_tests',
                'merge_without_post_execution_receipt',
                'dispatch_new_packets',
            ],
            'required_execution_receipt_fields' => [
                'executor_run_id',
                'source_executor_contract_hash',
                'source_executor_release_preflight_hash',
                'source_persisted_signed_final_receipt_hash',
                'applied_patch_hash',
                'files_changed',
                'final_diff_check_hash',
                'hot_scope_check_hash',
                'docs_health_hash',
                'architecture_validate_hash',
                'focused_test_matrix_hash',
                'rollback_plan_hash',
                'executed_by',
                'executed_at',
            ],
            'still_forbidden_by_template' => [
                'executor_release_by_executor_contract_template',
                'patch_execution_by_executor_contract_template',
                'merge_from_executor_contract_template',
                'dispatch_from_executor_contract_template',
                'receipt_persistence_by_executor_contract_template',
            ],
            'executor_allowed' => false,
            'patch_execution_allowed' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_executor_contract_template.v1',
            'status' => $templateReady ? 'merge_executor_contract_template_ready' : 'merge_executor_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_executor_contract_template',
            'execution_allowed' => false,
            'patch_execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'executor_allowed' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_executor_contract_template_does_not_claim_packets',
                'codex_review_merge_executor_contract_template_does_not_complete_packets',
                'codex_review_merge_executor_contract_template_does_not_accept_release_authority',
                'codex_review_merge_executor_contract_template_does_not_release_executor',
                'codex_review_merge_executor_contract_template_does_not_execute_patch',
                'codex_review_merge_executor_contract_template_does_not_merge',
                'codex_review_merge_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge executor contract template is ready as a read-only future executor contract. It still does not release an executor, execute patches or merge.'
                : 'Codex review merge executor contract template is blocked until executor release preflight is ready.',
        ];
    }

    public function codexReviewMergeExecutionReceiptTemplate(array $options = []): array
    {
        $executorContractPayload = $this->parent->codexReviewMergeExecutorContractTemplate($options);
        $templateReady = data_get($executorContractPayload, 'status') === 'merge_executor_contract_template_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-EXECUTION-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_future_executor_execution_evidence' : 'blocked_before_executor_contract_template',
            'source_executor_contract_template_hash' => data_get($executorContractPayload, 'template_hash'),
            'source_executor_release_preflight_hash' => data_get($executorContractPayload, 'template.source_executor_release_preflight_hash'),
            'source_signed_final_receipt_persistence_template_hash' => data_get($executorContractPayload, 'template.source_signed_final_receipt_persistence_template_hash'),
            'source_final_receipt_hash' => data_get($executorContractPayload, 'template.source_final_receipt_hash'),
            'required_execution_evidence' => [
                'executor_run_id',
                'executor_identity',
                'source_executor_contract_hash',
                'source_persisted_signed_final_receipt_hash',
                'pre_execution_diff_hash',
                'post_execution_diff_hash',
                'applied_patch_hash',
                'files_changed',
                'commands_run',
                'focused_test_matrix_hash',
                'docs_health_hash',
                'architecture_validate_hash',
                'hot_scope_check_hash',
                'rollback_plan_hash',
                'execution_started_at',
                'execution_completed_at',
            ],
            'required_post_execution_checks' => [
                'applied_patch_hash_matches_receipt_bound_patch_set',
                'files_changed_subset_of_signed_receipt_scope',
                'hot_scope_check_passed_after_execution',
                'docs_health_passed_after_execution',
                'architecture_validate_passed_after_execution',
                'focused_test_matrix_passed_after_execution',
                'no_untracked_execution_artifacts_outside_scope',
                'rollback_plan_still_available',
            ],
            'blocking_conditions' => [
                'missing_executor_run_id',
                'missing_source_executor_contract_hash',
                'patch_hash_mismatch',
                'files_changed_outside_signed_receipt_scope',
                'hot_scope_failed_after_execution',
                'docs_health_failed_after_execution',
                'architecture_validate_failed_after_execution',
                'focused_test_matrix_failed_after_execution',
                'rollback_plan_missing_after_execution',
            ],
            'future_merge_preflight_requirements' => [
                'execution_receipt_persisted_append_only',
                'execution_receipt_hash_verified',
                'post_execution_gates_passed',
                'diff_matches_execution_receipt',
                'human_post_execution_confirmation_present',
                'merge_executor_uses_execution_receipt_only',
            ],
            'still_forbidden_by_template' => [
                'executor_release_by_execution_receipt_template',
                'patch_execution_by_execution_receipt_template',
                'execution_receipt_persistence_by_execution_receipt_template',
                'merge_from_execution_receipt_template',
                'dispatch_from_execution_receipt_template',
            ],
            'patch_executed' => false,
            'execution_recorded' => false,
            'receipt_persisted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_execution_receipt_template.v1',
            'status' => $templateReady ? 'merge_execution_receipt_template_ready' : 'merge_execution_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_execution_receipt_template',
            'execution_allowed' => false,
            'patch_execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'patch_executed' => false,
            'execution_recorded' => false,
            'receipt_persisted' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_execution_receipt_template_does_not_claim_packets',
                'codex_review_merge_execution_receipt_template_does_not_complete_packets',
                'codex_review_merge_execution_receipt_template_does_not_release_executor',
                'codex_review_merge_execution_receipt_template_does_not_execute_patch',
                'codex_review_merge_execution_receipt_template_does_not_record_execution',
                'codex_review_merge_execution_receipt_template_does_not_persist_receipt',
                'codex_review_merge_execution_receipt_template_does_not_merge',
                'codex_review_merge_execution_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge execution receipt template is ready as a read-only post-execution evidence contract. It still does not execute patches, record execution, persist receipts or merge.'
                : 'Codex review merge execution receipt template is blocked until executor contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionPreflight(array $options = []): array
    {
        $executionReceiptPayload = $this->parent->codexReviewMergeExecutionReceiptTemplate($options);
        $preflightReady = data_get($executionReceiptPayload, 'status') === 'merge_execution_receipt_template_ready';

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_persisted_execution_receipt_inputs' : 'blocked_before_execution_receipt_template',
            'source_execution_receipt_template_hash' => data_get($executionReceiptPayload, 'template_hash'),
            'source_executor_contract_template_hash' => data_get($executionReceiptPayload, 'template.source_executor_contract_template_hash'),
            'source_executor_release_preflight_hash' => data_get($executionReceiptPayload, 'template.source_executor_release_preflight_hash'),
            'source_signed_final_receipt_persistence_template_hash' => data_get($executionReceiptPayload, 'template.source_signed_final_receipt_persistence_template_hash'),
            'source_final_receipt_hash' => data_get($executionReceiptPayload, 'template.source_final_receipt_hash'),
            'required_external_inputs' => [
                'persisted_execution_receipt_id',
                'persisted_execution_receipt_hash',
                'append_only_execution_receipt_event_hash',
                'post_execution_diff_hash',
                'post_execution_gate_report_hash',
                'human_post_execution_confirmation_hash',
                'merge_candidate_hash',
            ],
            'required_preflight_checks' => [
                'execution_receipt_template_ready',
                'persisted_execution_receipt_present',
                'persisted_execution_receipt_hash_verified',
                'append_only_execution_receipt_event_present',
                'execution_receipt_sources_match_templates',
                'post_execution_diff_matches_receipt',
                'post_execution_gates_passed',
                'hot_scope_clean_after_execution',
                'docs_health_clean_after_execution',
                'architecture_validate_clean_after_execution',
                'focused_tests_clean_after_execution',
                'human_post_execution_confirmation_present',
            ],
            'blocking_conditions' => [
                'missing_persisted_execution_receipt',
                'missing_append_only_execution_receipt_event',
                'execution_receipt_source_hash_mismatch',
                'post_execution_diff_mismatch',
                'post_execution_gate_failure',
                'hot_scope_failed_after_execution',
                'docs_health_failed_after_execution',
                'architecture_validate_failed_after_execution',
                'focused_tests_failed_after_execution',
                'missing_human_post_execution_confirmation',
            ],
            'future_merge_action_requirements' => [
                'merge_action_consumes_persisted_execution_receipt_only',
                'merge_action_revalidates_post_execution_diff',
                'merge_action_revalidates_gate_hashes',
                'merge_action_revalidates_no_hot_scope_drift',
                'merge_action_requires_human_confirmation_hash',
                'merge_action_emits_final_merge_receipt',
            ],
            'still_forbidden_by_preflight' => [
                'execution_receipt_acceptance_by_post_execution_preflight',
                'execution_receipt_persistence_by_post_execution_preflight',
                'approval_from_post_execution_preflight',
                'merge_from_post_execution_preflight',
                'dispatch_from_post_execution_preflight',
            ],
            'execution_receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_preflight.v1',
            'status' => $preflightReady ? 'merge_post_execution_preflight_ready' : 'merge_post_execution_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'execution_receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_preflight_does_not_accept_execution_receipt',
                'codex_review_merge_post_execution_preflight_does_not_persist_execution_receipt',
                'codex_review_merge_post_execution_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_preflight_does_not_merge',
                'codex_review_merge_post_execution_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution preflight is ready as a read-only merge prerequisite contract. It still does not accept execution receipt evidence, approve code or merge.'
                : 'Codex review merge post-execution preflight is blocked until execution receipt template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_post_execution_preflight_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_merge_action_authority' : 'blocked_before_post_execution_preflight',
            'default_decision' => 'do_not_merge',
            'source_post_execution_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_execution_receipt_template_hash' => data_get($preflightPayload, 'preflight.source_execution_receipt_template_hash'),
            'source_executor_contract_template_hash' => data_get($preflightPayload, 'preflight.source_executor_contract_template_hash'),
            'source_executor_release_preflight_hash' => data_get($preflightPayload, 'preflight.source_executor_release_preflight_hash'),
            'source_final_receipt_hash' => data_get($preflightPayload, 'preflight.source_final_receipt_hash'),
            'required_authority_inputs' => [
                'post_execution_preflight_hash',
                'persisted_execution_receipt_hash',
                'post_execution_gate_report_hash',
                'merge_candidate_hash',
                'human_post_execution_confirmation_hash',
                'merge_operator_identity',
            ],
            'required_action_validations' => [
                'post_execution_preflight_ready',
                'persisted_execution_receipt_hash_matches_preflight',
                'merge_candidate_hash_matches_post_execution_diff',
                'post_execution_gate_report_hash_matches_preflight',
                'human_post_execution_confirmation_hash_present',
                'merge_operator_identity_present',
                'no_hot_scope_drift_since_preflight',
                'no_unreviewed_diff_since_preflight',
            ],
            'allowed_future_action_steps' => [
                'read_persisted_execution_receipt',
                'read_post_execution_gate_report',
                'read_merge_candidate_diff',
                'verify_merge_candidate_hashes',
                'request_final_merge_confirmation',
                'emit_unsigned_final_merge_action_receipt',
            ],
            'forbidden_future_action_steps' => [
                'merge_without_final_confirmation',
                'merge_with_unreviewed_diff',
                'merge_with_hot_scope_drift',
                'merge_without_persisted_execution_receipt',
                'merge_without_post_execution_gate_report',
                'dispatch_new_packets',
            ],
            'future_final_merge_receipt_requirements' => [
                'final_merge_action_receipt_draft',
                'final_merge_action_signature_request',
                'final_merge_action_signed_receipt',
                'final_merge_action_append_only_event',
                'final_merge_hash',
            ],
            'still_forbidden_by_template' => [
                'approval_from_post_execution_action_template',
                'merge_from_post_execution_action_template',
                'dispatch_from_post_execution_action_template',
                'final_merge_receipt_persistence_by_post_execution_action_template',
            ],
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_template_ready' : 'merge_post_execution_action_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_template_does_not_accept_merge_authority',
                'codex_review_merge_post_execution_action_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_template_does_not_merge',
                'codex_review_merge_post_execution_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge post-execution action template is ready as a read-only future merge action contract. It still does not approve code, merge or dispatch work.'
                : 'Codex review merge post-execution action template is blocked until post-execution preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionReceiptDraft(array $options = []): array
    {
        $actionTemplatePayload = $this->parent->codexReviewMergePostExecutionActionTemplate($options);
        $receiptReady = data_get($actionTemplatePayload, 'status') === 'merge_post_execution_action_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'unsigned_waiting_for_external_final_merge_confirmation' : 'blocked_before_post_execution_action_template',
            'default_decision' => 'do_not_merge',
            'signature_required' => true,
            'source_post_execution_action_template_hash' => data_get($actionTemplatePayload, 'template_hash'),
            'source_post_execution_preflight_hash' => data_get($actionTemplatePayload, 'template.source_post_execution_preflight_hash'),
            'source_execution_receipt_template_hash' => data_get($actionTemplatePayload, 'template.source_execution_receipt_template_hash'),
            'source_executor_contract_template_hash' => data_get($actionTemplatePayload, 'template.source_executor_contract_template_hash'),
            'source_final_receipt_hash' => data_get($actionTemplatePayload, 'template.source_final_receipt_hash'),
            'required_authority_inputs' => data_get($actionTemplatePayload, 'template.required_authority_inputs', []),
            'required_action_validations' => data_get($actionTemplatePayload, 'template.required_action_validations', []),
            'decision_fields' => [
                'selected_decision',
                'decision_rationale',
                'merge_candidate_hash',
                'persisted_execution_receipt_hash',
                'post_execution_gate_report_hash',
                'human_post_execution_confirmation_hash',
                'merge_operator_identity',
            ],
            'required_signable_payload_fields' => [
                'receipt_id',
                'source_post_execution_action_template_hash',
                'source_post_execution_preflight_hash',
                'source_execution_receipt_template_hash',
                'selected_decision',
                'merge_candidate_hash',
                'persisted_execution_receipt_hash',
                'human_post_execution_confirmation_hash',
                'generated_at',
            ],
            'future_signature_requirements' => [
                'final_merge_action_receipt_signature_request',
                'external_final_merge_action_signature_value',
                'signature_validator_identity',
                'signature_validation_timestamp',
                'signed_final_merge_action_receipt_persisted_append_only',
            ],
            'still_forbidden_by_receipt_draft' => [
                'signature_acceptance_by_post_execution_action_receipt_draft',
                'signature_validation_by_post_execution_action_receipt_draft',
                'approval_from_post_execution_action_receipt_draft',
                'merge_from_post_execution_action_receipt_draft',
                'receipt_persistence_by_post_execution_action_receipt_draft',
                'dispatch_from_post_execution_action_receipt_draft',
            ],
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_receipt_draft.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_receipt_draft_ready' : 'merge_post_execution_action_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_receipt_draft',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_receipt_draft_does_not_claim_packets',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_complete_packets',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_accept_signature',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_validate_signature',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_approve_code',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_merge',
                'codex_review_merge_post_execution_action_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge post-execution action receipt draft is ready as an unsigned, non-authorizing receipt. It still does not accept signatures, approve code, merge or dispatch work.'
                : 'Codex review merge post-execution action receipt draft is blocked until post-execution action template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergePostExecutionActionReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $requestReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_receipt_draft_ready';

        $signablePayload = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_post_execution_action_template_hash' => data_get($receipt, 'source_post_execution_action_template_hash'),
            'source_post_execution_preflight_hash' => data_get($receipt, 'source_post_execution_preflight_hash'),
            'source_execution_receipt_template_hash' => data_get($receipt, 'source_execution_receipt_template_hash'),
            'source_executor_contract_template_hash' => data_get($receipt, 'source_executor_contract_template_hash'),
            'source_final_receipt_hash' => data_get($receipt, 'source_final_receipt_hash'),
            'requested_signature_type' => 'human_or_governed_post_execution_merge_action_receipt_signature',
            'default_decision' => data_get($receipt, 'default_decision'),
            'required_authority_inputs' => (array) data_get($receipt, 'required_authority_inputs', []),
            'required_action_validations' => (array) data_get($receipt, 'required_action_validations', []),
            'decision_fields' => (array) data_get($receipt, 'decision_fields', []),
            'required_signable_payload_fields' => (array) data_get($receipt, 'required_signable_payload_fields', []),
            'required_external_signature_fields' => [
                'external_final_merge_action_signature_value',
                'signature_validator_identity',
                'signature_validation_timestamp',
                'signed_final_merge_action_receipt_persisted_append_only',
            ],
            'still_forbidden_after_signature_request' => [
                'signature_acceptance_by_post_execution_action_signature_request',
                'signature_validation_by_post_execution_action_signature_request',
                'approval_from_post_execution_action_signature_request',
                'merge_from_post_execution_action_signature_request',
                'receipt_persistence_by_post_execution_action_signature_request',
                'dispatch_from_post_execution_action_signature_request',
            ],
        ];

        $signatureRequest = [
            'request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_external_final_merge_action_signature' : 'blocked_before_post_execution_action_receipt_draft',
            'source_post_execution_action_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_persisted' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signature_request.v1',
            'status' => $requestReady ? 'merge_post_execution_action_signature_request_pending' : 'merge_post_execution_action_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signature_request',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'signature_request' => $signatureRequest,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signature_request_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signature_request_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signature_request_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signature_request_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signature_request_does_not_approve_code',
                'codex_review_merge_post_execution_action_signature_request_does_not_merge',
                'codex_review_merge_post_execution_action_signature_request_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review merge post-execution action signature request is pending as a signable receipt payload. It still does not accept, validate, approve, persist or merge.'
                : 'Codex review merge post-execution action signature request is blocked until action receipt draft is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergePostExecutionActionSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signature_request_pending';

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_final_merge_action_signature_evidence' : 'blocked_before_post_execution_action_signature_request',
            'source_action_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_action_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'source_action_receipt_hash' => data_get($signaturePayload, 'signature_request.source_post_execution_action_receipt_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_external_inputs' => [
                'external_final_merge_action_signature_value',
                'external_final_merge_action_signature_validator_identity',
                'external_final_merge_action_signature_validation_timestamp',
                'signed_final_merge_action_receipt_persistence_event_hash',
            ],
            'ordered_steps' => [
                'collect_external_final_merge_action_signature_evidence',
                'verify_signature_request_hash_matches_signable_payload',
                'verify_action_receipt_hash_matches_signed_payload',
                'verify_required_authority_inputs_are_present',
                'verify_required_action_validations_are_present',
                'prepare_signed_action_receipt_persistence_candidate',
                'stop_before_signature_acceptance_or_merge',
            ],
            'future_validator_must_check' => [
                'external_signature_value_present',
                'signature_validator_identity_present',
                'signature_validation_timestamp_present',
                'signed_action_receipt_persistence_event_hash_present',
                'source_hashes_match_signature_request',
                'no_hot_scope_drift_since_signature_request',
                'no_unreviewed_diff_since_signature_request',
            ],
            'still_forbidden_by_runbook' => [
                'signature_acceptance_by_post_execution_action_post_signature_runbook',
                'signature_validation_by_post_execution_action_post_signature_runbook',
                'decision_recording_by_post_execution_action_post_signature_runbook',
                'approval_from_post_execution_action_post_signature_runbook',
                'merge_from_post_execution_action_post_signature_runbook',
                'receipt_persistence_by_post_execution_action_post_signature_runbook',
                'dispatch_from_post_execution_action_post_signature_runbook',
            ],
            'step_count' => 7,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_post_signature_runbook_ready' : 'merge_post_execution_action_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_post_signature_runbook',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_accept_signature',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_merge',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-execution action post-signature runbook is ready as a read-only evidence sequence. It still does not accept, validate, approve, persist or merge.'
                : 'Codex review merge post-execution action post-signature runbook is blocked until action signature request is pending.',
        ];
    }
}
