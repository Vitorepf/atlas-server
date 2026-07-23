<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 04 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest
 *           .. codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate
 */
final class CodexReviewMergePart04SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready';

        $signablePayload = [
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'source_writer_release_authorization_preflight_hash' => data_get($receipt, 'source_writer_release_authorization_preflight_hash'),
            'source_writer_release_authorization_template_hash' => data_get($receipt, 'source_writer_release_authorization_template_hash'),
            'source_writer_implementation_preflight_hash' => data_get($receipt, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($receipt, 'source_writer_contract_template_hash'),
            'required_external_evidence' => data_get($receipt, 'required_external_evidence', []),
            'required_authorization_checks' => data_get($receipt, 'required_authorization_checks', []),
            'blocking_conditions' => data_get($receipt, 'blocking_conditions', []),
        ];

        $signatureRequest = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'waiting_for_external_writer_release_signature' : 'blocked_before_writer_release_authorization_receipt_draft',
            'source_writer_release_authorization_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_writer_release_authorization_preflight_hash' => data_get($receipt, 'source_writer_release_authorization_preflight_hash'),
            'source_writer_release_authorization_template_hash' => data_get($receipt, 'source_writer_release_authorization_template_hash'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'allowed_decisions' => data_get($receipt, 'allowed_decisions', []),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'required_external_signature_evidence' => [
                'external_writer_release_signature_value',
                'external_writer_release_signature_validator_identity',
                'external_writer_release_signature_validation_timestamp',
                'validated_writer_release_authorization_receipt_hash',
                'validated_writer_release_signable_payload_hash',
            ],
            'future_post_signature_outputs' => [
                'writer_release_authorization_post_signature_runbook_hash',
                'validated_writer_release_authorization_signature_hash',
                'signed_writer_release_authorization_receipt_template_hash',
            ],
            'still_forbidden_by_signature_request' => [
                'writer_file_creation_by_writer_release_authorization_signature_request',
                'ledger_write_by_writer_release_authorization_signature_request',
                'signature_acceptance_by_writer_release_authorization_signature_request',
                'signature_validation_by_writer_release_authorization_signature_request',
                'receipt_persistence_by_writer_release_authorization_signature_request',
                'decision_recording_by_writer_release_authorization_signature_request',
                'approval_from_writer_release_authorization_signature_request',
                'merge_from_writer_release_authorization_signature_request',
                'dispatch_from_writer_release_authorization_signature_request',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'signature_request' => $signatureRequest,
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'signable_payload_hash' => data_get($signatureRequest, 'signable_payload_hash'),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization signature request is ready as a signable payload. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization signature request is blocked until writer release authorization receipt draft is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signatureRequestReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready';

        $steps = [
            'collect_external_writer_release_signature_evidence',
            'verify_signature_request_hash_matches_signable_payload',
            'verify_writer_release_authorization_receipt_hash_matches_signed_payload',
            'verify_writer_release_signable_payload_hash_matches_signature_request',
            'verify_required_writer_release_authorization_evidence_is_present',
            'verify_hot_scope_clean_before_release_template',
            'prepare_signed_writer_release_authorization_receipt_template_candidate',
            'stop_before_signature_acceptance_writer_creation_or_ledger_write',
        ];

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $signatureRequestReady ? 'waiting_for_external_writer_release_signature_evidence' : 'blocked_before_writer_release_authorization_signature_request',
            'source_writer_release_authorization_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_writer_release_authorization_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'source_writer_release_authorization_receipt_hash' => data_get($signatureRequest, 'source_writer_release_authorization_receipt_hash'),
            'selected_decision' => data_get($signatureRequest, 'selected_decision'),
            'required_external_signature_evidence' => data_get($signatureRequest, 'required_external_signature_evidence', []),
            'ordered_steps' => $steps,
            'step_count' => count($steps),
            'future_validator_must_check' => [
                'external_writer_release_signature_value_present',
                'writer_release_signature_validator_identity_present',
                'writer_release_signature_validation_timestamp_present',
                'validated_writer_release_authorization_receipt_hash_matches_source',
                'validated_writer_release_signable_payload_hash_matches_source',
                'selected_decision_explicitly_authorizes_or_requests_more_evidence',
                'hot_scope_still_clean',
                'writer_patch_still_matches_contract_hash',
            ],
            'future_signed_receipt_template_inputs' => [
                'validated_writer_release_authorization_signature_hash',
                'validated_writer_release_authorization_receipt_hash',
                'validated_writer_release_signable_payload_hash',
                'writer_release_signature_validator_identity',
                'writer_release_signature_validated_at',
            ],
            'still_forbidden_by_runbook' => [
                'writer_file_creation_by_writer_release_authorization_post_signature_runbook',
                'ledger_write_by_writer_release_authorization_post_signature_runbook',
                'signature_acceptance_by_writer_release_authorization_post_signature_runbook',
                'signature_validation_by_writer_release_authorization_post_signature_runbook',
                'receipt_persistence_by_writer_release_authorization_post_signature_runbook',
                'decision_recording_by_writer_release_authorization_post_signature_runbook',
                'approval_from_writer_release_authorization_post_signature_runbook',
                'merge_from_writer_release_authorization_post_signature_runbook',
                'dispatch_from_writer_release_authorization_post_signature_runbook',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook.v1',
            'status' => $signatureRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $signatureRequestReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization post-signature runbook is ready as a read-only evidence sequence. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization post-signature runbook is blocked until writer release authorization signature request is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_signed_writer_release_authorization_evidence' : 'blocked_before_writer_release_authorization_post_signature_runbook',
            'source_writer_release_authorization_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_writer_release_authorization_signature_request_hash' => data_get($runbook, 'source_writer_release_authorization_signature_request_hash'),
            'source_writer_release_authorization_signable_payload_hash' => data_get($runbook, 'source_writer_release_authorization_signable_payload_hash'),
            'source_writer_release_authorization_receipt_hash' => data_get($runbook, 'source_writer_release_authorization_receipt_hash'),
            'selected_decision' => data_get($runbook, 'selected_decision'),
            'required_external_signed_receipt_evidence' => [
                'external_writer_release_signature_value',
                'writer_release_signature_validator_identity',
                'writer_release_signature_validated_at',
                'validated_writer_release_authorization_signature_hash',
                'validated_writer_release_authorization_receipt_hash',
                'validated_writer_release_signable_payload_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_writer_release_authorization_receipt_id',
                'source_writer_release_authorization_receipt_hash',
                'source_writer_release_authorization_signable_payload_hash',
                'source_writer_release_authorization_post_signature_runbook_hash',
                'validated_writer_release_authorization_signature_hash',
                'writer_release_signature_validator_identity',
                'writer_release_signature_validated_at',
                'selected_decision',
                'writer_contract_template_hash',
                'writer_implementation_preflight_hash',
                'writer_release_authorization_actor_identity',
                'signed_at',
            ],
            'future_writer_release_preflight_requirements' => [
                'signed_writer_release_authorization_receipt_template_ready',
                'external_signed_receipt_evidence_present',
                'selected_decision_equals_authorize_writer_release',
                'writer_contract_hash_still_matches_patch',
                'hot_scope_still_clean',
                'writer_capability_tests_still_pass',
            ],
            'still_forbidden_by_template' => [
                'writer_file_creation_by_writer_release_authorization_signed_receipt_template',
                'ledger_write_by_writer_release_authorization_signed_receipt_template',
                'signature_acceptance_by_writer_release_authorization_signed_receipt_template',
                'signature_validation_by_writer_release_authorization_signed_receipt_template',
                'receipt_persistence_by_writer_release_authorization_signed_receipt_template',
                'decision_recording_by_writer_release_authorization_signed_receipt_template',
                'approval_from_writer_release_authorization_signed_receipt_template',
                'merge_from_writer_release_authorization_signed_receipt_template',
                'dispatch_from_writer_release_authorization_signed_receipt_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release authorization signed receipt template is ready as a non-persisting contract. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release authorization signed receipt template is blocked until writer release authorization post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready';

        $blockingConditions = $templateReady
            ? [
                'missing_external_signed_writer_release_authorization_evidence',
                'selected_decision_not_authorize_writer_release',
                'writer_contract_hash_not_rechecked_against_patch',
                'hot_scope_recheck_missing',
                'writer_capability_tests_not_rerun',
                'writer_merge_authority_absence_not_verified',
                'writer_dispatch_authority_absence_not_verified',
                'writer_release_actor_identity_missing',
                'writer_release_receipt_persistence_plan_missing',
                'writer_release_still_not_authorized',
            ]
            : [
                'writer_release_authorization_signed_receipt_template_not_ready',
            ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_signed_writer_release_authorization_evidence' : 'blocked_before_writer_release_authorization_signed_receipt_template',
            'source_writer_release_authorization_signed_receipt_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_writer_release_authorization_post_signature_runbook_hash' => data_get($template, 'source_writer_release_authorization_post_signature_runbook_hash'),
            'source_writer_release_authorization_signature_request_hash' => data_get($template, 'source_writer_release_authorization_signature_request_hash'),
            'source_writer_release_authorization_signable_payload_hash' => data_get($template, 'source_writer_release_authorization_signable_payload_hash'),
            'source_writer_release_authorization_receipt_hash' => data_get($template, 'source_writer_release_authorization_receipt_hash'),
            'selected_decision' => data_get($template, 'selected_decision'),
            'required_external_signed_receipt_evidence' => data_get($template, 'required_external_signed_receipt_evidence', []),
            'required_release_checks' => [
                'signed_writer_release_authorization_receipt_template_ready',
                'external_signed_receipt_evidence_present',
                'selected_decision_equals_authorize_writer_release',
                'writer_contract_hash_still_matches_patch',
                'hot_scope_still_clean',
                'writer_capability_tests_still_pass',
                'writer_has_no_merge_authority',
                'writer_has_no_dispatch_authority',
            ],
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'future_release_outputs' => [
                'writer_release_receipt_hash',
                'writer_release_signature_request_hash',
                'writer_release_runbook_hash',
                'writer_release_execution_contract_hash',
            ],
            'still_forbidden_by_release_preflight' => [
                'writer_file_creation_by_writer_release_preflight',
                'ledger_write_by_writer_release_preflight',
                'signature_acceptance_by_writer_release_preflight',
                'signature_validation_by_writer_release_preflight',
                'receipt_persistence_by_writer_release_preflight',
                'decision_recording_by_writer_release_preflight',
                'approval_from_writer_release_preflight',
                'merge_from_writer_release_preflight',
                'dispatch_from_writer_release_preflight',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release preflight is ready as a blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release preflight is blocked until writer release authorization signed receipt template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'unsigned_writer_release_receipt_draft_waiting_for_external_evidence' : 'blocked_before_writer_release_preflight',
            'source_writer_release_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_authorization_signed_receipt_template_hash' => data_get($preflight, 'source_writer_release_authorization_signed_receipt_template_hash'),
            'source_writer_release_authorization_receipt_hash' => data_get($preflight, 'source_writer_release_authorization_receipt_hash'),
            'source_writer_release_authorization_signable_payload_hash' => data_get($preflight, 'source_writer_release_authorization_signable_payload_hash'),
            'selected_decision' => data_get($preflight, 'selected_decision'),
            'inherited_blocking_conditions' => data_get($preflight, 'blocking_conditions', []),
            'inherited_blocking_count' => data_get($preflight, 'blocking_count', 0),
            'release_receipt_fields' => [
                'writer_release_receipt_id',
                'source_writer_release_preflight_hash',
                'source_writer_release_authorization_signed_receipt_template_hash',
                'validated_writer_release_authorization_signature_hash',
                'writer_release_actor_identity',
                'writer_contract_hash_rechecked_at_release',
                'hot_scope_recheck_hash',
                'writer_capability_test_run_hash',
                'writer_no_merge_authority_evidence_hash',
                'writer_no_dispatch_authority_evidence_hash',
                'writer_release_decision',
                'writer_release_rationale',
                'signed_at',
            ],
            'future_signature_request_inputs' => [
                'writer_release_receipt_hash',
                'writer_release_receipt_id',
                'writer_release_decision',
                'writer_release_actor_identity',
                'writer_release_blocking_conditions',
            ],
            'future_post_signature_outputs' => [
                'writer_release_signature_request_hash',
                'writer_release_signed_receipt_template_hash',
                'writer_release_execution_contract_hash',
            ],
            'still_forbidden_by_receipt_draft' => [
                'writer_file_creation_by_writer_release_receipt_draft',
                'ledger_write_by_writer_release_receipt_draft',
                'signature_acceptance_by_writer_release_receipt_draft',
                'signature_validation_by_writer_release_receipt_draft',
                'receipt_persistence_by_writer_release_receipt_draft',
                'decision_recording_by_writer_release_receipt_draft',
                'approval_from_writer_release_receipt_draft',
                'merge_from_writer_release_receipt_draft',
                'dispatch_from_writer_release_receipt_draft',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release receipt draft is ready as an unsigned non-authorizing receipt. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release receipt draft is blocked until writer release preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_ready';
        $signablePayload = [
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($receipt, 'source_writer_release_preflight_hash'),
            'source_writer_release_authorization_signed_receipt_template_hash' => data_get($receipt, 'source_writer_release_authorization_signed_receipt_template_hash'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'inherited_blocking_conditions' => data_get($receipt, 'inherited_blocking_conditions', []),
            'requested_signature_scope' => 'writer_release_receipt_only',
            'requested_signer_role' => 'human_operator_or_policy_authority',
        ];
        $signablePayloadHash = ReadinessHash::stable($signablePayload);

        $signatureRequest = [
            'request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'waiting_for_external_writer_release_signature' : 'blocked_before_writer_release_receipt_draft',
            'source_writer_release_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($receipt, 'source_writer_release_preflight_hash'),
            'source_writer_release_authorization_signed_receipt_template_hash' => data_get($receipt, 'source_writer_release_authorization_signed_receipt_template_hash'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => $signablePayloadHash,
            'required_signature_evidence' => [
                'external_writer_release_signature_value',
                'writer_release_signer_identity',
                'writer_release_signed_at',
                'writer_release_signature_algorithm',
                'writer_release_signature_scope',
                'writer_release_receipt_hash_signed',
                'writer_release_signable_payload_hash_signed',
            ],
            'future_post_signature_outputs' => [
                'writer_release_signature_validation_hash',
                'writer_release_signed_receipt_template_hash',
                'writer_release_post_signature_runbook_hash',
                'writer_release_execution_contract_hash',
            ],
            'still_forbidden_by_signature_request' => [
                'writer_file_creation_by_writer_release_signature_request',
                'ledger_write_by_writer_release_signature_request',
                'signature_acceptance_by_writer_release_signature_request',
                'signature_validation_by_writer_release_signature_request',
                'receipt_persistence_by_writer_release_signature_request',
                'decision_recording_by_writer_release_signature_request',
                'approval_from_writer_release_signature_request',
                'merge_from_writer_release_signature_request',
                'dispatch_from_writer_release_signature_request',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'signature_request' => $signatureRequest,
            'signable_payload_hash' => $signablePayloadHash,
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release signature request is ready as a non-authorizing request. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release signature request is blocked until writer release receipt draft is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook(array $options = []): array
    {
        $requestPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options);
        $signatureRequest = (array) data_get($requestPayload, 'signature_request', []);
        $requestReady = data_get($requestPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready';

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_external_writer_release_signature_evidence' : 'blocked_before_writer_release_signature_request',
            'source_writer_release_signature_request_hash' => data_get($requestPayload, 'request_hash'),
            'source_writer_release_signable_payload_hash' => data_get($requestPayload, 'signable_payload_hash'),
            'source_writer_release_receipt_hash' => data_get($signatureRequest, 'source_writer_release_receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($signatureRequest, 'source_writer_release_preflight_hash'),
            'selected_decision' => data_get($signatureRequest, 'selected_decision'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'ordered_steps' => [
                'collect_external_writer_release_signature_evidence',
                'verify_signature_scope_matches_writer_release_receipt_only',
                'verify_signed_receipt_hash_matches_source_writer_release_receipt_hash',
                'verify_signed_payload_hash_matches_source_writer_release_signable_payload_hash',
                'recheck_writer_release_blockers_before_validation',
                'prepare_signed_writer_release_receipt_template_inputs',
                'prepare_writer_release_execution_contract_inputs',
                'stop_before_signature_acceptance_or_writer_release',
            ],
            'step_count' => 8,
            'required_external_signature_evidence' => data_get($signatureRequest, 'required_signature_evidence', []),
            'future_signed_receipt_template_inputs' => [
                'validated_writer_release_signature_hash',
                'validated_writer_release_signable_payload_hash',
                'validated_writer_release_receipt_hash',
                'writer_release_signer_identity',
                'writer_release_signature_validated_at',
                'writer_release_post_signature_runbook_hash',
            ],
            'future_validator_must_check' => [
                'writer_release_signature_value_present',
                'writer_release_signature_scope_exact',
                'writer_release_receipt_hash_still_matches',
                'writer_release_signable_payload_hash_still_matches',
                'writer_contract_hash_still_matches_patch',
                'hot_scope_still_clean',
                'writer_capability_tests_still_pass',
                'writer_no_merge_authority_still_true',
                'writer_no_dispatch_authority_still_true',
            ],
            'still_forbidden_by_runbook' => [
                'writer_file_creation_by_writer_release_post_signature_runbook',
                'ledger_write_by_writer_release_post_signature_runbook',
                'signature_acceptance_by_writer_release_post_signature_runbook',
                'signature_validation_by_writer_release_post_signature_runbook',
                'receipt_persistence_by_writer_release_post_signature_runbook',
                'decision_recording_by_writer_release_post_signature_runbook',
                'approval_from_writer_release_post_signature_runbook',
                'merge_from_writer_release_post_signature_runbook',
                'dispatch_from_writer_release_post_signature_runbook',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook.v1',
            'status' => $requestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release post-signature runbook is ready as a non-authorizing sequence. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release post-signature runbook is blocked until writer release signature request is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_validated_writer_release_signature_evidence' : 'blocked_before_writer_release_post_signature_runbook',
            'source_writer_release_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_writer_release_signature_request_hash' => data_get($runbook, 'source_writer_release_signature_request_hash'),
            'source_writer_release_signable_payload_hash' => data_get($runbook, 'source_writer_release_signable_payload_hash'),
            'source_writer_release_receipt_hash' => data_get($runbook, 'source_writer_release_receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($runbook, 'source_writer_release_preflight_hash'),
            'selected_decision' => data_get($runbook, 'selected_decision'),
            'required_external_validated_signature_evidence' => [
                'external_writer_release_signature_value',
                'writer_release_signature_validator_identity',
                'writer_release_signature_validated_at',
                'validated_writer_release_signature_hash',
                'validated_writer_release_receipt_hash',
                'validated_writer_release_signable_payload_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_writer_release_receipt_id',
                'source_writer_release_receipt_hash',
                'source_writer_release_signable_payload_hash',
                'source_writer_release_post_signature_runbook_hash',
                'validated_writer_release_signature_hash',
                'writer_release_signature_validator_identity',
                'writer_release_signature_validated_at',
                'writer_release_signer_identity',
                'writer_release_decision',
                'writer_release_rationale',
                'writer_release_execution_contract_hash',
                'signed_at',
            ],
            'future_execution_contract_requirements' => [
                'signed_writer_release_receipt_template_ready',
                'external_validated_signature_evidence_present',
                'selected_decision_equals_authorize_writer_release',
                'writer_contract_hash_still_matches_patch',
                'hot_scope_still_clean',
                'writer_capability_tests_still_pass',
                'writer_has_no_merge_authority',
                'writer_has_no_dispatch_authority',
            ],
            'still_forbidden_by_template' => [
                'writer_file_creation_by_writer_release_signed_receipt_template',
                'ledger_write_by_writer_release_signed_receipt_template',
                'signature_acceptance_by_writer_release_signed_receipt_template',
                'signature_validation_by_writer_release_signed_receipt_template',
                'receipt_persistence_by_writer_release_signed_receipt_template',
                'decision_recording_by_writer_release_signed_receipt_template',
                'approval_from_writer_release_signed_receipt_template',
                'merge_from_writer_release_signed_receipt_template',
                'dispatch_from_writer_release_signed_receipt_template',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release signed receipt template is ready as a non-persisting contract. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Codex review merge post-execution action signed receipt persistence writer release signed receipt template is blocked until writer release post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_ready';

        $blockingConditions = $templateReady
            ? [
                'missing_external_validated_writer_release_signature_evidence',
                'selected_decision_not_authorize_writer_release',
                'writer_contract_hash_not_rechecked_against_patch',
                'hot_scope_recheck_missing',
                'writer_capability_tests_not_rerun',
                'writer_no_merge_authority_not_verified',
                'writer_no_dispatch_authority_not_verified',
                'writer_release_execution_actor_identity_missing',
                'writer_release_execution_scope_missing',
                'writer_release_execution_still_not_authorized',
            ]
            : [
                'writer_release_signed_receipt_template_not_ready',
            ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-EXECUTION-CONTRACT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_validated_writer_release_signature_evidence' : 'blocked_before_writer_release_signed_receipt_template',
            'source_writer_release_signed_receipt_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_writer_release_post_signature_runbook_hash' => data_get($template, 'source_writer_release_post_signature_runbook_hash'),
            'source_writer_release_signature_request_hash' => data_get($template, 'source_writer_release_signature_request_hash'),
            'source_writer_release_receipt_hash' => data_get($template, 'source_writer_release_receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($template, 'source_writer_release_preflight_hash'),
            'selected_decision' => data_get($template, 'selected_decision'),
            'required_external_validated_signature_evidence' => data_get($template, 'required_external_validated_signature_evidence', []),
            'required_execution_contract_checks' => [
                'signed_writer_release_receipt_template_ready',
                'external_validated_signature_evidence_present',
                'selected_decision_equals_authorize_writer_release',
                'writer_contract_hash_still_matches_patch',
                'hot_scope_still_clean',
                'writer_capability_tests_still_pass',
                'writer_has_no_merge_authority',
                'writer_has_no_dispatch_authority',
                'execution_scope_is_writer_release_only',
                'rollback_and_disable_path_defined',
            ],
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'future_execution_contract_outputs' => [
                'writer_release_execution_contract_hash',
                'writer_release_disable_contract_hash',
                'writer_release_observability_contract_hash',
                'writer_release_post_execution_receipt_hash',
            ],
            'still_forbidden_by_execution_contract_preflight' => [
                'writer_file_creation_by_writer_release_execution_contract_preflight',
                'ledger_write_by_writer_release_execution_contract_preflight',
                'signature_acceptance_by_writer_release_execution_contract_preflight',
                'signature_validation_by_writer_release_execution_contract_preflight',
                'receipt_persistence_by_writer_release_execution_contract_preflight',
                'decision_recording_by_writer_release_execution_contract_preflight',
                'approval_from_writer_release_execution_contract_preflight',
                'merge_from_writer_release_execution_contract_preflight',
                'dispatch_from_writer_release_execution_contract_preflight',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release execution contract preflight is ready as a blocker report. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release execution contract preflight is blocked until writer release signed receipt template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_ready';

        $blockingConditions = $preflightReady
            ? (array) data_get($preflight, 'blocking_conditions', [])
            : [
                'writer_release_execution_contract_preflight_not_ready',
            ];

        $contract = [
            'contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-EXECUTION-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'blocked_waiting_for_external_writer_release_execution_authority' : 'blocked_before_writer_release_execution_contract_preflight',
            'source_writer_release_execution_contract_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_signed_receipt_template_hash' => data_get($preflight, 'source_writer_release_signed_receipt_template_hash'),
            'source_writer_release_post_signature_runbook_hash' => data_get($preflight, 'source_writer_release_post_signature_runbook_hash'),
            'source_writer_release_signature_request_hash' => data_get($preflight, 'source_writer_release_signature_request_hash'),
            'source_writer_release_receipt_hash' => data_get($preflight, 'source_writer_release_receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($preflight, 'source_writer_release_preflight_hash'),
            'selected_decision' => data_get($preflight, 'selected_decision'),
            'required_external_validated_signature_evidence' => data_get($preflight, 'required_external_validated_signature_evidence', []),
            'required_execution_contract_checks' => data_get($preflight, 'required_execution_contract_checks', []),
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'execution_scope' => [
                'allowed_scope' => 'future_writer_release_only_after_external_authorization',
                'forbidden_scope' => [
                    'merge_execution',
                    'dispatch_execution',
                    'receipt_persistence_execution',
                    'policy_mutation',
                    'hot_scope_mutation',
                    'unscoped_file_creation',
                ],
                'writer_contract_expected_capability' => 'signed_post_execution_action_receipt_persistence_writer_only',
                'writer_contract_forbidden_capabilities' => [
                    'merge_authority',
                    'dispatch_authority',
                    'signature_validation_authority',
                    'approval_authority',
                    'self_release_authority',
                ],
            ],
            'required_actor_evidence' => [
                'writer_release_executor_identity',
                'writer_release_executor_session',
                'writer_release_execution_reason',
                'writer_release_execution_scope_hash',
                'writer_release_execution_contract_reviewer_identity',
            ],
            'required_recheck_evidence' => [
                'writer_contract_hash_rechecked_against_patch',
                'hot_scope_clean_recheck_hash',
                'writer_capability_test_output_hash',
                'writer_no_merge_authority_evidence_hash',
                'writer_no_dispatch_authority_evidence_hash',
                'rollback_and_disable_plan_hash',
            ],
            'future_post_execution_outputs' => [
                'writer_release_execution_contract_hash',
                'writer_release_disable_contract_hash',
                'writer_release_observability_contract_hash',
                'writer_release_post_execution_receipt_hash',
            ],
            'still_forbidden_by_execution_contract_template' => [
                'writer_file_creation_by_writer_release_execution_contract_template',
                'ledger_write_by_writer_release_execution_contract_template',
                'signature_acceptance_by_writer_release_execution_contract_template',
                'signature_validation_by_writer_release_execution_contract_template',
                'receipt_persistence_by_writer_release_execution_contract_template',
                'decision_recording_by_writer_release_execution_contract_template',
                'approval_from_writer_release_execution_contract_template',
                'merge_from_writer_release_execution_contract_template',
                'dispatch_from_writer_release_execution_contract_template',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'contract' => $contract,
            'contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release execution contract template is ready as a non-authorizing contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release execution contract template is blocked until execution contract preflight is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate(array $options = []): array
    {
        $contractPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_ready';

        $disableTriggers = [
            'writer_contract_hash_drift_detected',
            'hot_scope_dirty_after_release',
            'writer_capability_tests_failed_after_release',
            'writer_merge_authority_detected',
            'writer_dispatch_authority_detected',
            'unexpected_signature_validation_attempt',
            'unexpected_receipt_persistence_attempt',
            'unexpected_ledger_write_attempt',
            'operator_revocation_requested',
            'rollback_plan_missing_or_invalid',
        ];

        $disableContract = [
            'disable_contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-DISABLE-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $contractReady ? 'ready_as_future_disable_template' : 'blocked_before_writer_release_execution_contract_template',
            'source_writer_release_execution_contract_hash' => data_get($contractPayload, 'contract_hash'),
            'source_writer_release_execution_contract_preflight_hash' => data_get($contract, 'source_writer_release_execution_contract_preflight_hash'),
            'source_writer_release_signed_receipt_template_hash' => data_get($contract, 'source_writer_release_signed_receipt_template_hash'),
            'source_writer_release_post_signature_runbook_hash' => data_get($contract, 'source_writer_release_post_signature_runbook_hash'),
            'source_writer_release_signature_request_hash' => data_get($contract, 'source_writer_release_signature_request_hash'),
            'source_writer_release_receipt_hash' => data_get($contract, 'source_writer_release_receipt_hash'),
            'disable_triggers' => $disableTriggers,
            'trigger_count' => count($disableTriggers),
            'required_disable_steps' => [
                'stop_writer_release_runtime',
                'revoke_writer_release_capability_flag',
                'quarantine_writer_release_outputs',
                'rerun_writer_no_merge_authority_check',
                'rerun_writer_no_dispatch_authority_check',
                'capture_disable_reason_and_actor',
                'generate_future_post_disable_receipt',
                'require_human_review_before_reenable',
            ],
            'required_disable_evidence' => [
                'disable_actor_identity',
                'disable_reason',
                'disable_trigger_id',
                'runtime_stop_evidence_hash',
                'capability_revocation_evidence_hash',
                'quarantine_manifest_hash',
                'post_disable_no_merge_authority_evidence_hash',
                'post_disable_no_dispatch_authority_evidence_hash',
            ],
            'future_disable_outputs' => [
                'writer_release_disable_contract_hash',
                'writer_release_disable_receipt_hash',
                'writer_release_revocation_event_hash',
                'writer_release_reenable_review_packet_hash',
            ],
            'reenable_requirements' => [
                'new_execution_contract_preflight',
                'new_execution_contract_template',
                'new_disable_contract_template',
                'fresh_human_authorization',
                'fresh_hot_scope_recheck',
                'fresh_writer_capability_tests',
            ],
            'still_forbidden_by_disable_contract_template' => [
                'writer_file_creation_by_writer_release_disable_contract_template',
                'ledger_write_by_writer_release_disable_contract_template',
                'signature_acceptance_by_writer_release_disable_contract_template',
                'signature_validation_by_writer_release_disable_contract_template',
                'receipt_persistence_by_writer_release_disable_contract_template',
                'decision_recording_by_writer_release_disable_contract_template',
                'approval_from_writer_release_disable_contract_template',
                'merge_from_writer_release_disable_contract_template',
                'dispatch_from_writer_release_disable_contract_template',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'disable_contract' => $disableContract,
            'disable_contract_hash' => ReadinessHash::stable($disableContract),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release disable contract template is ready as a rollback contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release disable contract template is blocked until execution contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate(array $options = []): array
    {
        $disablePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options);
        $disableContract = (array) data_get($disablePayload, 'disable_contract', []);
        $disableReady = data_get($disablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_ready';

        $signals = [
            'writer_release_execution_contract_loaded',
            'writer_release_runtime_started',
            'writer_release_capability_flag_checked',
            'writer_release_receipt_persistence_attempted',
            'writer_release_ledger_write_attempted',
            'writer_release_forbidden_merge_attempt_detected',
            'writer_release_forbidden_dispatch_attempt_detected',
            'writer_release_disable_trigger_detected',
            'writer_release_disable_completed',
            'writer_release_reenable_requested',
            'writer_release_post_execution_receipt_generated',
            'writer_release_human_review_required',
        ];

        $observabilityContract = [
            'observability_contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-OBSERVABILITY-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $disableReady ? 'ready_as_future_observability_template' : 'blocked_before_writer_release_disable_contract_template',
            'source_writer_release_disable_contract_hash' => data_get($disablePayload, 'disable_contract_hash'),
            'source_writer_release_execution_contract_hash' => data_get($disableContract, 'source_writer_release_execution_contract_hash'),
            'source_writer_release_execution_contract_preflight_hash' => data_get($disableContract, 'source_writer_release_execution_contract_preflight_hash'),
            'source_writer_release_signed_receipt_template_hash' => data_get($disableContract, 'source_writer_release_signed_receipt_template_hash'),
            'source_writer_release_post_signature_runbook_hash' => data_get($disableContract, 'source_writer_release_post_signature_runbook_hash'),
            'source_writer_release_signature_request_hash' => data_get($disableContract, 'source_writer_release_signature_request_hash'),
            'source_writer_release_receipt_hash' => data_get($disableContract, 'source_writer_release_receipt_hash'),
            'required_signals' => $signals,
            'signal_count' => count($signals),
            'required_metrics' => [
                'writer_release_attempt_count',
                'writer_release_success_count',
                'writer_release_blocked_count',
                'writer_release_disable_trigger_count',
                'writer_release_forbidden_merge_attempt_count',
                'writer_release_forbidden_dispatch_attempt_count',
                'writer_release_unexpected_ledger_write_attempt_count',
                'writer_release_unexpected_receipt_persistence_attempt_count',
                'writer_release_time_to_disable_ms',
            ],
            'required_alerts' => [
                'alert_on_writer_contract_hash_drift',
                'alert_on_hot_scope_dirty_after_release',
                'alert_on_writer_capability_test_failure',
                'alert_on_forbidden_merge_authority',
                'alert_on_forbidden_dispatch_authority',
                'alert_on_unexpected_signature_validation',
                'alert_on_unexpected_receipt_persistence',
                'alert_on_unexpected_ledger_write',
            ],
            'required_observability_evidence' => [
                'trace_id',
                'operation_id',
                'writer_release_contract_hash',
                'writer_release_disable_contract_hash',
                'signal_manifest_hash',
                'metrics_snapshot_hash',
                'alert_policy_hash',
                'post_release_monitoring_window',
            ],
            'minimum_monitoring_window' => [
                'after_future_release_minutes' => 60,
                'after_future_disable_minutes' => 30,
                'requires_human_review_before_window_close' => true,
            ],
            'future_observability_outputs' => [
                'writer_release_observability_contract_hash',
                'writer_release_signal_manifest_hash',
                'writer_release_metrics_snapshot_hash',
                'writer_release_alert_policy_hash',
                'writer_release_post_monitoring_review_hash',
            ],
            'still_forbidden_by_observability_contract_template' => [
                'writer_file_creation_by_writer_release_observability_contract_template',
                'ledger_write_by_writer_release_observability_contract_template',
                'signature_acceptance_by_writer_release_observability_contract_template',
                'signature_validation_by_writer_release_observability_contract_template',
                'receipt_persistence_by_writer_release_observability_contract_template',
                'decision_recording_by_writer_release_observability_contract_template',
                'approval_from_writer_release_observability_contract_template',
                'merge_from_writer_release_observability_contract_template',
                'dispatch_from_writer_release_observability_contract_template',
            ],
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template.v1',
            'status' => $disableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'observability_contract' => $observabilityContract,
            'observability_contract_hash' => ReadinessHash::stable($observabilityContract),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $disableReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release observability contract template is ready as a monitoring contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release observability contract template is blocked until disable contract template is ready.',
        ];
    }
}
