<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 03 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergePostExecutionActionSignedReceiptTemplate
 *           .. codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft
 */
final class CodexReviewMergePart03SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergePostExecutionActionSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostExecutionActionPostSignatureRunbook($options);
        $templateReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_signed_action_receipt_evidence' : 'blocked_before_post_execution_action_post_signature_runbook',
            'source_action_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_action_signature_request_hash' => data_get($runbookPayload, 'runbook.source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($runbookPayload, 'runbook.source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($runbookPayload, 'runbook.source_action_receipt_hash'),
            'required_external_evidence_for_future_signed_receipt' => [
                'external_final_merge_action_signature_value',
                'external_final_merge_action_signature_validator_identity',
                'external_final_merge_action_signature_validation_timestamp',
                'validated_action_signable_payload_hash',
                'validated_action_receipt_hash',
                'validated_selected_decision',
                'validated_required_authority_inputs',
                'validated_required_action_validations',
                'signed_final_merge_action_receipt_persistence_event_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_action_receipt_id',
                'source_action_receipt_hash',
                'source_action_signable_payload_hash',
                'source_action_post_signature_runbook_hash',
                'signature_hash',
                'signature_validator_identity',
                'signature_validated_at',
                'selected_decision',
                'decision_rationale',
                'merge_candidate_hash',
                'persisted_execution_receipt_hash',
                'post_execution_gate_report_hash',
                'human_post_execution_confirmation_hash',
                'merge_operator_identity',
                'signed_by',
                'signed_at',
            ],
            'required_validations_before_persisting_signed_receipt' => [
                'signature_validates_against_action_signable_payload_hash',
                'action_receipt_hash_matches_source',
                'selected_decision_equals_merge',
                'required_authority_inputs_present',
                'required_action_validations_passed',
                'post_execution_gate_report_hash_present',
                'human_post_execution_confirmation_hash_present',
                'merge_candidate_hash_present',
                'persisted_execution_receipt_hash_present',
            ],
            'future_merge_surface_release_conditions' => [
                'signed_action_receipt_persisted_append_only',
                'signed_action_receipt_hash_verified',
                'merge_surface_consumes_signed_action_receipt_only',
                'merge_surface_reruns_last_minute_diff_check',
                'merge_surface_reruns_hot_scope_check',
                'merge_surface_emits_final_merge_evidence',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_post_execution_action_signed_receipt_template',
                'signature_validation_by_post_execution_action_signed_receipt_template',
                'receipt_persistence_by_post_execution_action_signed_receipt_template',
                'decision_recording_by_post_execution_action_signed_receipt_template',
                'approval_from_post_execution_action_signed_receipt_template',
                'merge_from_post_execution_action_signed_receipt_template',
                'dispatch_from_post_execution_action_signed_receipt_template',
            ],
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge post-execution action signed receipt template is ready as a non-persisting contract. It still does not accept, validate, approve, persist or merge.'
                : 'Codex review merge post-execution action signed receipt template is blocked until action post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $preflightReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_template_ready';

        $blockingConditions = [
            'missing_external_final_merge_action_signature_value',
            'missing_signature_validator_identity',
            'missing_signature_validation_timestamp',
            'missing_validated_action_signable_payload_hash',
            'missing_validated_action_receipt_hash',
            'missing_validated_selected_decision',
            'missing_validated_required_authority_inputs',
            'missing_validated_required_action_validations',
            'missing_signed_action_receipt_persistence_event_hash',
            'selected_decision_is_not_merge',
            'action_receipt_hash_mismatch',
            'action_signable_payload_hash_mismatch',
            'hot_scope_drift_since_action_signature_request',
            'unreviewed_diff_since_action_signature_request',
        ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_signed_action_receipt_evidence' : 'blocked_before_signed_action_receipt_template',
            'source_signed_action_receipt_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_action_post_signature_runbook_hash' => data_get($template, 'source_action_post_signature_runbook_hash'),
            'source_action_signature_request_hash' => data_get($template, 'source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($template, 'source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($template, 'source_action_receipt_hash'),
            'required_external_evidence' => (array) data_get($template, 'required_external_evidence_for_future_signed_receipt', []),
            'required_future_persisted_fields' => (array) data_get($template, 'signed_receipt_fields_to_persist_in_future', []),
            'required_validations_before_persisting' => (array) data_get($template, 'required_validations_before_persisting_signed_receipt', []),
            'future_merge_surface_release_conditions' => (array) data_get($template, 'future_merge_surface_release_conditions', []),
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'still_forbidden_by_preflight' => [
                'signature_acceptance_by_post_execution_action_signed_receipt_preflight',
                'signature_validation_by_post_execution_action_signed_receipt_preflight',
                'receipt_persistence_by_post_execution_action_signed_receipt_preflight',
                'decision_recording_by_post_execution_action_signed_receipt_preflight',
                'approval_from_post_execution_action_signed_receipt_preflight',
                'merge_from_post_execution_action_signed_receipt_preflight',
                'dispatch_from_post_execution_action_signed_receipt_preflight',
            ],
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_preflight.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_preflight_ready' : 'merge_post_execution_action_signed_receipt_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt preflight is ready as a read-only persistence prerequisite check. It still does not accept, validate, approve, persist or merge.'
                : 'Codex review merge post-execution action signed receipt preflight is blocked until signed action receipt template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_preflight_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_future_append_only_persistence_surface' : 'blocked_before_signed_action_receipt_preflight',
            'source_signed_action_receipt_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_signed_action_receipt_template_hash' => data_get($preflight, 'source_signed_action_receipt_template_hash'),
            'source_action_signature_request_hash' => data_get($preflight, 'source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($preflight, 'source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($preflight, 'source_action_receipt_hash'),
            'future_append_only_event_type' => 'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED',
            'future_append_only_event_fields' => [
                'event_id',
                'event_type',
                'signed_action_receipt_id',
                'signed_action_receipt_hash',
                'source_signed_action_receipt_preflight_hash',
                'source_signed_action_receipt_template_hash',
                'source_action_signature_request_hash',
                'source_action_signable_payload_hash',
                'source_action_receipt_hash',
                'signature_hash',
                'signature_validator_identity',
                'signature_validated_at',
                'selected_decision',
                'merge_candidate_hash',
                'persisted_execution_receipt_hash',
                'human_post_execution_confirmation_hash',
                'persisted_at',
            ],
            'required_pre_persistence_checks' => [
                'signed_action_receipt_preflight_ready',
                'all_preflight_blocking_conditions_resolved',
                'external_signature_value_present',
                'signature_validator_identity_present',
                'signature_validation_timestamp_present',
                'selected_decision_equals_merge',
                'source_hashes_match_preflight',
                'hot_scope_still_clean',
                'unreviewed_diff_absent',
            ],
            'future_verification_outputs' => [
                'signed_action_receipt_hash',
                'append_only_event_hash',
                'ledger_sequence_number',
                'persistence_actor_identity',
                'persistence_timestamp',
            ],
            'still_forbidden_by_template' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_template',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_template',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_template',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_template',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_template',
                'approval_from_post_execution_action_signed_receipt_persistence_template',
                'merge_from_post_execution_action_signed_receipt_persistence_template',
                'dispatch_from_post_execution_action_signed_receipt_persistence_template',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_persistence_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge post-execution action signed receipt persistence template is ready as a non-writing contract. It still does not accept, validate, write, persist, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence template is blocked until signed receipt preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $receiptReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'unsigned_waiting_for_external_persistence_evidence' : 'blocked_before_signed_action_receipt_persistence_template',
            'source_signed_action_receipt_persistence_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_signed_action_receipt_preflight_hash' => data_get($template, 'source_signed_action_receipt_preflight_hash'),
            'source_signed_action_receipt_template_hash' => data_get($template, 'source_signed_action_receipt_template_hash'),
            'source_action_signature_request_hash' => data_get($template, 'source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($template, 'source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($template, 'source_action_receipt_hash'),
            'future_append_only_event_type' => data_get($template, 'future_append_only_event_type'),
            'required_persistence_fields' => data_get($template, 'future_append_only_event_fields', []),
            'required_pre_persistence_checks' => data_get($template, 'required_pre_persistence_checks', []),
            'future_verification_outputs' => data_get($template, 'future_verification_outputs', []),
            'required_receipt_evidence' => [
                'signed_action_receipt_hash',
                'append_only_event_hash',
                'ledger_sequence_number',
                'persistence_actor_identity',
                'persistence_timestamp',
                'source_hash_match_report',
                'hot_scope_recheck_report',
                'unreviewed_diff_absence_report',
            ],
            'still_forbidden_by_receipt_draft' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_receipt_draft',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_receipt_draft',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_receipt_draft',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_receipt_draft',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_receipt_draft',
                'approval_from_post_execution_action_signed_receipt_persistence_receipt_draft',
                'merge_from_post_execution_action_signed_receipt_persistence_receipt_draft',
                'dispatch_from_post_execution_action_signed_receipt_persistence_receipt_draft',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready' : 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge post-execution action signed receipt persistence receipt draft is ready as an unsigned, non-writing receipt. It still does not accept signatures, validate, write ledger, persist, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence receipt draft is blocked until the persistence template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistencePreflight(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptDraftReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready';

        $blockingConditions = $receiptDraftReady ? [
            'external_persistence_actor_identity_missing',
            'external_persistence_timestamp_missing',
            'external_signed_action_receipt_hash_missing',
            'external_append_only_event_hash_missing',
            'external_ledger_sequence_number_missing',
            'source_hash_match_report_missing',
            'hot_scope_recheck_report_missing',
            'unreviewed_diff_absence_report_missing',
            'ledger_write_surface_not_implemented',
            'human_persistence_confirmation_missing',
        ] : [
            'signed_action_receipt_persistence_receipt_draft_not_ready',
        ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $receiptDraftReady ? 'waiting_for_external_append_only_persistence_evidence' : 'blocked_before_signed_action_receipt_persistence_receipt_draft',
            'source_persistence_receipt_draft_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_signed_action_receipt_persistence_template_hash' => data_get($receipt, 'source_signed_action_receipt_persistence_template_hash'),
            'future_append_only_event_type' => data_get($receipt, 'future_append_only_event_type'),
            'required_persistence_fields' => data_get($receipt, 'required_persistence_fields', []),
            'required_receipt_evidence' => data_get($receipt, 'required_receipt_evidence', []),
            'blocking_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'release_conditions_for_future_persistence_surface' => [
                'receipt_draft_ready',
                'all_blocking_conditions_resolved',
                'source_hashes_match_receipt_draft',
                'append_only_event_hash_present',
                'ledger_sequence_number_present',
                'human_persistence_confirmation_present',
                'persistence_surface_allows_append_only_write_only',
            ],
            'still_forbidden_by_preflight' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_preflight',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_preflight',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_preflight',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_preflight',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_preflight',
                'approval_from_post_execution_action_signed_receipt_persistence_preflight',
                'merge_from_post_execution_action_signed_receipt_persistence_preflight',
                'dispatch_from_post_execution_action_signed_receipt_persistence_preflight',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight.v1',
            'status' => $receiptDraftReady ? 'merge_post_execution_action_signed_receipt_persistence_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $receiptDraftReady
                ? 'Codex review merge post-execution action signed receipt persistence preflight is ready as a blocker report. It still does not write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence preflight is blocked until the persistence receipt draft is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_preflight_ready';

        $steps = [
            [
                'id' => 'step_01_reconfirm_preflight_hash',
                'action' => 'Recompute and compare the persistence preflight hash before any future persistence attempt.',
                'required_evidence' => ['preflight_hash_match_report'],
            ],
            [
                'id' => 'step_02_collect_external_persistence_evidence',
                'action' => 'Collect actor identity, timestamp, signed receipt hash, append-only event hash and ledger sequence number.',
                'required_evidence' => ['persistence_actor_identity', 'persistence_timestamp', 'signed_action_receipt_hash', 'append_only_event_hash', 'ledger_sequence_number'],
            ],
            [
                'id' => 'step_03_verify_source_hashes',
                'action' => 'Verify every source hash still matches the receipt draft chain.',
                'required_evidence' => ['source_hash_match_report'],
            ],
            [
                'id' => 'step_04_recheck_hot_scope',
                'action' => 'Recheck hot scopes and reject persistence if unreviewed drift appears.',
                'required_evidence' => ['hot_scope_recheck_report', 'unreviewed_diff_absence_report'],
            ],
            [
                'id' => 'step_05_require_human_persistence_confirmation',
                'action' => 'Require explicit human confirmation before any later append-only write surface is invoked.',
                'required_evidence' => ['human_persistence_confirmation_hash'],
            ],
            [
                'id' => 'step_06_prepare_future_append_only_write',
                'action' => 'Prepare the future append-only event payload without writing it.',
                'required_evidence' => ['future_append_only_event_payload_hash'],
            ],
        ];

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-POST-PREFLIGHT-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_persistence_evidence_collection' : 'blocked_before_signed_action_receipt_persistence_preflight',
            'source_persistence_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_persistence_receipt_draft_hash' => data_get($preflight, 'source_persistence_receipt_draft_hash'),
            'future_append_only_event_type' => data_get($preflight, 'future_append_only_event_type'),
            'preflight_blocking_count' => data_get($preflight, 'blocking_count'),
            'preflight_blocking_conditions' => data_get($preflight, 'blocking_conditions', []),
            'step_count' => count($steps),
            'steps' => $steps,
            'exit_conditions' => [
                'all_runbook_steps_have_evidence',
                'all_preflight_blockers_resolved',
                'append_only_event_payload_hash_created',
                'human_persistence_confirmation_hash_present',
                'future_writer_surface_separately_authorized',
            ],
            'still_forbidden_by_runbook' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'approval_from_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'merge_from_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
                'dispatch_from_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready' : 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence post-preflight runbook is ready as a non-writing sequence. It still does not write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence post-preflight runbook is blocked until persistence preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready';

        $payload = [
            'payload_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-APPEND-ONLY-EVENT-PAYLOAD-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_future_writer_surface_authorization' : 'blocked_before_signed_action_receipt_persistence_post_preflight_runbook',
            'event_type' => data_get($runbook, 'future_append_only_event_type'),
            'source_persistence_post_preflight_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_persistence_preflight_hash' => data_get($runbook, 'source_persistence_preflight_hash'),
            'source_persistence_receipt_draft_hash' => data_get($runbook, 'source_persistence_receipt_draft_hash'),
            'required_payload_fields' => [
                'event_id',
                'event_type',
                'signed_action_receipt_id',
                'signed_action_receipt_hash',
                'source_persistence_post_preflight_runbook_hash',
                'source_persistence_preflight_hash',
                'source_persistence_receipt_draft_hash',
                'append_only_event_hash',
                'ledger_sequence_number',
                'persistence_actor_identity',
                'persistence_timestamp',
                'human_persistence_confirmation_hash',
                'source_hash_match_report_hash',
                'hot_scope_recheck_report_hash',
                'unreviewed_diff_absence_report_hash',
            ],
            'field_values' => [
                'event_id' => null,
                'event_type' => data_get($runbook, 'future_append_only_event_type'),
                'signed_action_receipt_id' => null,
                'signed_action_receipt_hash' => null,
                'source_persistence_post_preflight_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
                'source_persistence_preflight_hash' => data_get($runbook, 'source_persistence_preflight_hash'),
                'source_persistence_receipt_draft_hash' => data_get($runbook, 'source_persistence_receipt_draft_hash'),
                'append_only_event_hash' => null,
                'ledger_sequence_number' => null,
                'persistence_actor_identity' => null,
                'persistence_timestamp' => null,
                'human_persistence_confirmation_hash' => null,
                'source_hash_match_report_hash' => null,
                'hot_scope_recheck_report_hash' => null,
                'unreviewed_diff_absence_report_hash' => null,
            ],
            'required_before_write' => [
                'post_preflight_runbook_ready',
                'all_runbook_steps_have_evidence',
                'all_preflight_blockers_resolved',
                'all_required_payload_fields_non_null',
                'payload_hash_recomputed_by_writer',
                'future_writer_surface_separately_authorized',
            ],
            'still_forbidden_by_payload_template' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'approval_from_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'merge_from_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
                'dispatch_from_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'payload' => $payload,
            'payload_hash' => ReadinessHash::stable($payload),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-execution action signed receipt persistence append-only event payload template is ready as a non-writing payload contract. It still does not write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence append-only event payload template is blocked until post-preflight runbook is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight(array $options = []): array
    {
        $payloadTemplate = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options);
        $payload = (array) data_get($payloadTemplate, 'payload', []);
        $payloadReady = data_get($payloadTemplate, 'status') === 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready';

        $blockingConditions = $payloadReady ? [
            'future_writer_surface_not_implemented',
            'future_writer_surface_not_separately_authorized',
            'payload_required_fields_still_null',
            'payload_hash_not_recomputed_by_writer',
            'append_only_event_hash_missing',
            'ledger_sequence_number_missing',
            'persistence_actor_identity_missing',
            'persistence_timestamp_missing',
            'human_persistence_confirmation_hash_missing',
            'source_hash_match_report_hash_missing',
            'hot_scope_recheck_report_hash_missing',
            'unreviewed_diff_absence_report_hash_missing',
        ] : [
            'append_only_event_payload_template_not_ready',
        ];

        $writerPreflight = [
            'writer_preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $payloadReady ? 'waiting_for_future_writer_surface_authorization' : 'blocked_before_append_only_event_payload_template',
            'source_append_only_event_payload_template_hash' => data_get($payloadTemplate, 'payload_hash'),
            'source_persistence_post_preflight_runbook_hash' => data_get($payload, 'source_persistence_post_preflight_runbook_hash'),
            'event_type' => data_get($payload, 'event_type'),
            'required_payload_fields' => data_get($payload, 'required_payload_fields', []),
            'required_before_write' => data_get($payload, 'required_before_write', []),
            'blocking_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'writer_contract_required_capabilities' => [
                'append_only_ledger_write_only',
                'payload_hash_recompute',
                'source_hash_match_enforcement',
                'hot_scope_recheck_enforcement',
                'human_confirmation_hash_enforcement',
                'no_merge_authority',
                'no_dispatch_authority',
            ],
            'future_writer_release_conditions' => [
                'writer_surface_implemented',
                'writer_surface_separately_authorized',
                'all_payload_fields_non_null',
                'all_writer_contract_capabilities_present',
                'all_blocking_conditions_resolved',
                'writer_preflight_hash_bound_to_writer_contract',
            ],
            'still_forbidden_by_writer_preflight' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_writer_preflight',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_writer_preflight',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_writer_preflight',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_writer_preflight',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_writer_preflight',
                'approval_from_post_execution_action_signed_receipt_persistence_writer_preflight',
                'merge_from_post_execution_action_signed_receipt_persistence_writer_preflight',
                'dispatch_from_post_execution_action_signed_receipt_persistence_writer_preflight',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight.v1',
            'status' => $payloadReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'writer_preflight' => $writerPreflight,
            'writer_preflight_hash' => ReadinessHash::stable($writerPreflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $payloadReady
                ? 'Codex review merge post-execution action signed receipt persistence writer preflight is ready as a blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer preflight is blocked until the append-only event payload template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options);
        $writerPreflight = (array) data_get($preflightPayload, 'writer_preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready';

        $capabilities = data_get($writerPreflight, 'writer_contract_required_capabilities', []);
        $contract = [
            'contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_future_writer_implementation' : 'blocked_before_persistence_writer_preflight',
            'source_writer_preflight_hash' => data_get($preflightPayload, 'writer_preflight_hash'),
            'source_append_only_event_payload_template_hash' => data_get($writerPreflight, 'source_append_only_event_payload_template_hash'),
            'event_type' => data_get($writerPreflight, 'event_type'),
            'capability_count' => count($capabilities),
            'required_capabilities' => $capabilities,
            'required_payload_fields' => data_get($writerPreflight, 'required_payload_fields', []),
            'required_pre_write_checks' => [
                'writer_preflight_ready',
                'writer_contract_hash_bound_to_implementation',
                'writer_contract_capabilities_verified',
                'all_payload_fields_non_null',
                'payload_hash_recomputed_by_writer',
                'source_hash_match_enforced',
                'hot_scope_recheck_enforced',
                'human_confirmation_hash_enforced',
                'merge_authority_absent',
                'dispatch_authority_absent',
            ],
            'implementation_must_not_include' => [
                'merge_execution',
                'dispatch_execution',
                'signature_acceptance',
                'signature_validation',
                'approval_recording',
                'decision_recording',
                'packet_claiming',
                'packet_completion',
            ],
            'future_release_conditions' => data_get($writerPreflight, 'future_writer_release_conditions', []),
            'still_forbidden_by_contract_template' => [
                'ledger_write_by_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'approval_from_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'merge_from_post_execution_action_signed_receipt_persistence_writer_contract_template',
                'dispatch_from_post_execution_action_signed_receipt_persistence_writer_contract_template',
            ],
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'contract' => $contract,
            'contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer contract template is ready as a non-writing contract. It still does not implement a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer contract template is blocked until writer preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready';

        $blockingConditions = $contractReady ? [
            'writer_implementation_absent',
            'writer_contract_hash_not_bound_to_implementation',
            'writer_capability_tests_absent',
            'append_only_write_guard_absent',
            'merge_authority_absence_test_absent',
            'dispatch_authority_absence_test_absent',
            'payload_non_null_validation_absent',
            'payload_hash_recompute_test_absent',
            'source_hash_match_enforcement_test_absent',
            'hot_scope_recheck_enforcement_test_absent',
            'human_confirmation_hash_enforcement_test_absent',
            'writer_release_authorization_absent',
        ] : [
            'writer_contract_template_not_ready',
        ];

        $implementationPreflight = [
            'implementation_preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-IMPLEMENTATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $contractReady ? 'waiting_for_future_writer_implementation_patch' : 'blocked_before_persistence_writer_contract_template',
            'source_writer_contract_template_hash' => data_get($contractPayload, 'contract_hash'),
            'source_writer_preflight_hash' => data_get($contract, 'source_writer_preflight_hash'),
            'event_type' => data_get($contract, 'event_type'),
            'required_implementation_files' => [
                'future:app/Services/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriter.php',
                'future:tests/Unit/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriterTest.php',
            ],
            'required_implementation_tests' => [
                'writer_rejects_null_payload_fields',
                'writer_recomputes_payload_hash',
                'writer_enforces_source_hash_match',
                'writer_enforces_hot_scope_recheck',
                'writer_requires_human_confirmation_hash',
                'writer_has_no_merge_authority',
                'writer_has_no_dispatch_authority',
                'writer_is_append_only_write_only',
            ],
            'blocking_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'implementation_must_not_include' => data_get($contract, 'implementation_must_not_include', []),
            'required_capabilities' => data_get($contract, 'required_capabilities', []),
            'future_release_conditions' => [
                'implementation_files_exist',
                'implementation_tests_pass',
                'contract_hash_bound_to_implementation',
                'all_required_capabilities_verified',
                'all_forbidden_authorities_absent',
                'separate_writer_release_authorization_present',
            ],
            'still_forbidden_by_implementation_preflight' => [
                'writer_file_creation_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'ledger_write_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'signature_acceptance_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'signature_validation_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'receipt_persistence_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'decision_recording_by_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'approval_from_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'merge_from_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
                'dispatch_from_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
            ],
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'implementation_preflight' => $implementationPreflight,
            'implementation_preflight_hash' => ReadinessHash::stable($implementationPreflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Codex review merge post-execution action signed receipt persistence writer implementation preflight is ready as a blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer implementation preflight is blocked until writer contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate(array $options = []): array
    {
        $implementationPreflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options);
        $implementationPreflight = (array) data_get($implementationPreflightPayload, 'implementation_preflight', []);
        $implementationPreflightReady = data_get($implementationPreflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready';

        $requiredEvidence = [
            'writer_implementation_patch_hash',
            'writer_contract_template_hash',
            'writer_implementation_preflight_hash',
            'writer_capability_test_output_hash',
            'append_only_guard_test_output_hash',
            'merge_authority_absence_test_output_hash',
            'dispatch_authority_absence_test_output_hash',
            'hot_scope_recheck_output_hash',
            'human_writer_release_confirmation_hash',
        ];

        $authorization = [
            'authorization_template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $implementationPreflightReady ? 'waiting_for_future_human_writer_release_authorization' : 'blocked_before_writer_implementation_preflight',
            'source_writer_implementation_preflight_hash' => data_get($implementationPreflightPayload, 'implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($implementationPreflight, 'source_writer_contract_template_hash'),
            'source_writer_preflight_hash' => data_get($implementationPreflight, 'source_writer_preflight_hash'),
            'event_type' => data_get($implementationPreflight, 'event_type'),
            'required_evidence_count' => count($requiredEvidence),
            'required_external_evidence' => $requiredEvidence,
            'required_authorization_checks' => [
                'writer_implementation_preflight_ready',
                'writer_patch_reviewed_by_principal_integrator',
                'writer_contract_hash_matches_patch',
                'required_capability_tests_pass',
                'append_only_guard_passes',
                'merge_authority_absent',
                'dispatch_authority_absent',
                'hot_scope_clean_at_release_time',
                'human_writer_release_confirmation_present',
            ],
            'future_authorized_writer_scope' => [
                'may_validate_non_null_payload_fields',
                'may_recompute_payload_hash',
                'may_enforce_source_hash_match',
                'may_enforce_hot_scope_recheck',
                'may_require_human_confirmation_hash',
                'may_write_one_append_only_persistence_event_after_all_checks_pass',
            ],
            'still_forbidden_by_release_authorization_template' => [
                'writer_file_creation_by_writer_release_authorization_template',
                'ledger_write_by_writer_release_authorization_template',
                'signature_acceptance_by_writer_release_authorization_template',
                'signature_validation_by_writer_release_authorization_template',
                'receipt_persistence_by_writer_release_authorization_template',
                'decision_recording_by_writer_release_authorization_template',
                'approval_from_writer_release_authorization_template',
                'merge_from_writer_release_authorization_template',
                'dispatch_from_writer_release_authorization_template',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template.v1',
            'status' => $implementationPreflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'authorization' => $authorization,
            'authorization_hash' => ReadinessHash::stable($authorization),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_dispatch_work',
            ],
            'human_summary' => $implementationPreflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization template is ready as a human authorization contract. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization template is blocked until writer implementation preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight(array $options = []): array
    {
        $authorizationPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options);
        $authorization = (array) data_get($authorizationPayload, 'authorization', []);
        $authorizationTemplateReady = data_get($authorizationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready';

        $blockingConditions = $authorizationTemplateReady ? [
            'missing_writer_implementation_patch_hash',
            'missing_writer_contract_template_hash',
            'missing_writer_implementation_preflight_hash',
            'missing_writer_capability_test_output_hash',
            'missing_append_only_guard_test_output_hash',
            'missing_merge_authority_absence_test_output_hash',
            'missing_dispatch_authority_absence_test_output_hash',
            'missing_hot_scope_recheck_output_hash',
            'missing_human_writer_release_confirmation_hash',
            'writer_patch_not_reviewed_by_principal_integrator',
            'writer_contract_hash_not_verified_against_patch',
            'writer_release_not_separately_authorized',
        ] : [
            'writer_release_authorization_template_not_ready',
        ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $authorizationTemplateReady ? 'waiting_for_external_writer_release_evidence' : 'blocked_before_writer_release_authorization_template',
            'source_writer_release_authorization_template_hash' => data_get($authorizationPayload, 'authorization_hash'),
            'source_writer_implementation_preflight_hash' => data_get($authorization, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($authorization, 'source_writer_contract_template_hash'),
            'event_type' => data_get($authorization, 'event_type'),
            'required_external_evidence' => data_get($authorization, 'required_external_evidence', []),
            'required_authorization_checks' => data_get($authorization, 'required_authorization_checks', []),
            'blocking_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'future_writer_release_outputs' => [
                'writer_release_authorization_receipt_hash',
                'writer_release_authorization_signature_request_hash',
                'writer_release_signable_payload_hash',
                'writer_release_runbook_hash',
            ],
            'still_forbidden_by_release_authorization_preflight' => [
                'writer_file_creation_by_writer_release_authorization_preflight',
                'ledger_write_by_writer_release_authorization_preflight',
                'signature_acceptance_by_writer_release_authorization_preflight',
                'signature_validation_by_writer_release_authorization_preflight',
                'receipt_persistence_by_writer_release_authorization_preflight',
                'decision_recording_by_writer_release_authorization_preflight',
                'approval_from_writer_release_authorization_preflight',
                'merge_from_writer_release_authorization_preflight',
                'dispatch_from_writer_release_authorization_preflight',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight.v1',
            'status' => $authorizationTemplateReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $authorizationTemplateReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization preflight is ready as a blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization preflight is blocked until writer release authorization template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_writer_release_evidence' : 'blocked_before_writer_release_authorization_preflight',
            'source_writer_release_authorization_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_authorization_template_hash' => data_get($preflight, 'source_writer_release_authorization_template_hash'),
            'source_writer_implementation_preflight_hash' => data_get($preflight, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($preflight, 'source_writer_contract_template_hash'),
            'event_type' => data_get($preflight, 'event_type'),
            'selected_decision' => 'request_external_writer_release_evidence',
            'allowed_decisions' => [
                'authorize_writer_release',
                'request_external_writer_release_evidence',
                'request_changes',
                'abort',
            ],
            'required_external_evidence' => data_get($preflight, 'required_external_evidence', []),
            'required_authorization_checks' => data_get($preflight, 'required_authorization_checks', []),
            'blocking_conditions' => data_get($preflight, 'blocking_conditions', []),
            'future_signature_request_inputs' => [
                'receipt_hash',
                'selected_decision',
                'writer_release_authorization_preflight_hash',
                'writer_implementation_patch_hash',
                'human_writer_release_confirmation_hash',
                'principal_integrator_identity',
            ],
            'signable_payload_fields' => [
                'receipt_id',
                'source_writer_release_authorization_preflight_hash',
                'source_writer_release_authorization_template_hash',
                'source_writer_implementation_preflight_hash',
                'source_writer_contract_template_hash',
                'selected_decision',
                'required_external_evidence',
                'required_authorization_checks',
                'blocking_conditions',
            ],
            'still_forbidden_by_receipt_draft' => [
                'writer_file_creation_by_writer_release_authorization_receipt_draft',
                'ledger_write_by_writer_release_authorization_receipt_draft',
                'signature_acceptance_by_writer_release_authorization_receipt_draft',
                'signature_validation_by_writer_release_authorization_receipt_draft',
                'receipt_persistence_by_writer_release_authorization_receipt_draft',
                'decision_recording_by_writer_release_authorization_receipt_draft',
                'approval_from_writer_release_authorization_receipt_draft',
                'merge_from_writer_release_authorization_receipt_draft',
                'dispatch_from_writer_release_authorization_receipt_draft',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization receipt draft is ready as an unsigned receipt. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization receipt draft is blocked until writer release authorization preflight is ready.',
        ];
    }
}
