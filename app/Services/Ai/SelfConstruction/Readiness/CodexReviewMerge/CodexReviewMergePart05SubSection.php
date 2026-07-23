<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 05 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate
 *           .. codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate
 */
final class CodexReviewMergePart05SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options);
        $observabilityContract = (array) data_get($observabilityPayload, 'observability_contract', []);
        $observabilityReady = data_get($observabilityPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template_ready';

        $allowedDecisions = [
            'keep_writer_release_disabled',
            'keep_writer_release_enabled_under_watch',
            'request_disable_execution',
            'request_reenable_review',
            'escalate_to_human_review',
        ];

        $reviewTemplate = [
            'review_template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-POST-MONITORING-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $observabilityReady ? 'ready_as_future_post_monitoring_review_template' : 'blocked_before_writer_release_observability_contract_template',
            'source_writer_release_observability_contract_hash' => data_get($observabilityPayload, 'observability_contract_hash'),
            'source_writer_release_disable_contract_hash' => data_get($observabilityContract, 'source_writer_release_disable_contract_hash'),
            'source_writer_release_execution_contract_hash' => data_get($observabilityContract, 'source_writer_release_execution_contract_hash'),
            'source_writer_release_execution_contract_preflight_hash' => data_get($observabilityContract, 'source_writer_release_execution_contract_preflight_hash'),
            'source_writer_release_signed_receipt_template_hash' => data_get($observabilityContract, 'source_writer_release_signed_receipt_template_hash'),
            'allowed_decisions' => $allowedDecisions,
            'allowed_decision_count' => count($allowedDecisions),
            'required_review_inputs' => [
                'writer_release_observability_contract_hash',
                'writer_release_signal_manifest_hash',
                'writer_release_metrics_snapshot_hash',
                'writer_release_alert_policy_hash',
                'post_release_monitoring_window',
                'human_reviewer_identity',
            ],
            'health_checks' => [
                'monitoring_window_completed',
                'all_required_signals_present',
                'metrics_snapshot_present',
                'alert_policy_present',
                'no_forbidden_merge_attempts',
                'no_forbidden_dispatch_attempts',
                'no_unexpected_ledger_writes',
                'no_unexpected_receipt_persistence',
                'disable_path_still_available',
                'human_review_completed',
            ],
            'failure_to_decision_map' => [
                'forbidden_merge_attempt' => 'request_disable_execution',
                'forbidden_dispatch_attempt' => 'request_disable_execution',
                'unexpected_ledger_write_attempt' => 'request_disable_execution',
                'unexpected_receipt_persistence_attempt' => 'request_disable_execution',
                'missing_required_signal' => 'escalate_to_human_review',
                'monitoring_window_incomplete' => 'keep_writer_release_enabled_under_watch',
                'disable_path_unavailable' => 'escalate_to_human_review',
            ],
            'required_review_evidence' => [
                'review_actor_identity',
                'reviewed_at',
                'selected_decision',
                'decision_rationale',
                'health_check_result_hash',
                'metrics_snapshot_hash',
                'alert_summary_hash',
                'disable_path_verification_hash',
            ],
            'future_review_outputs' => [
                'writer_release_post_monitoring_review_hash',
                'writer_release_health_decision_hash',
                'writer_release_reenable_review_packet_hash',
                'writer_release_disable_request_hash',
            ],
            'still_forbidden_by_post_monitoring_review_template' => [
                'writer_file_creation_by_writer_release_post_monitoring_review_template',
                'ledger_write_by_writer_release_post_monitoring_review_template',
                'signature_acceptance_by_writer_release_post_monitoring_review_template',
                'signature_validation_by_writer_release_post_monitoring_review_template',
                'receipt_persistence_by_writer_release_post_monitoring_review_template',
                'decision_recording_by_writer_release_post_monitoring_review_template',
                'approval_from_writer_release_post_monitoring_review_template',
                'merge_from_writer_release_post_monitoring_review_template',
                'dispatch_from_writer_release_post_monitoring_review_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template.v1',
            'status' => $observabilityReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'review_template' => $reviewTemplate,
            'review_template_hash' => ReadinessHash::stable($reviewTemplate),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $observabilityReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release post-monitoring review template is ready as a non-authorizing health review. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release post-monitoring review template is blocked until observability contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options);
        $reviewTemplate = (array) data_get($reviewPayload, 'review_template', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_ready';

        $requirements = [
            'post_monitoring_review_template_ready',
            'selected_post_monitoring_decision_equals_request_reenable_review',
            'fresh_execution_contract_preflight_required',
            'fresh_execution_contract_template_required',
            'fresh_disable_contract_template_required',
            'fresh_observability_contract_template_required',
            'fresh_hot_scope_recheck_required',
            'fresh_writer_capability_tests_required',
            'fresh_human_authorization_required',
            'previous_disable_or_watch_reason_resolved',
        ];

        $reenablePacket = [
            'reenable_packet_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-REENABLE-REVIEW-PACKET-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $reviewReady ? 'ready_as_future_reenable_review_packet_template' : 'blocked_before_writer_release_post_monitoring_review_template',
            'source_writer_release_post_monitoring_review_hash' => data_get($reviewPayload, 'review_template_hash'),
            'source_writer_release_observability_contract_hash' => data_get($reviewTemplate, 'source_writer_release_observability_contract_hash'),
            'source_writer_release_disable_contract_hash' => data_get($reviewTemplate, 'source_writer_release_disable_contract_hash'),
            'source_writer_release_execution_contract_hash' => data_get($reviewTemplate, 'source_writer_release_execution_contract_hash'),
            'source_writer_release_execution_contract_preflight_hash' => data_get($reviewTemplate, 'source_writer_release_execution_contract_preflight_hash'),
            'requirements' => $requirements,
            'requirement_count' => count($requirements),
            'allowed_reenable_decisions' => [
                'deny_reenable',
                'request_fresh_authorization',
                'request_new_execution_contract_chain',
                'keep_disabled_until_remediated',
                'escalate_to_human_review',
            ],
            'required_reenable_evidence' => [
                'reenable_reviewer_identity',
                'reenable_reviewed_at',
                'previous_disable_or_watch_reason',
                'remediation_summary',
                'fresh_hot_scope_recheck_hash',
                'fresh_writer_capability_test_hash',
                'fresh_execution_contract_template_hash',
                'fresh_disable_contract_template_hash',
                'fresh_observability_contract_template_hash',
                'fresh_human_authorization_hash',
            ],
            'hard_blocks' => [
                'previous_forbidden_merge_attempt_unresolved',
                'previous_forbidden_dispatch_attempt_unresolved',
                'previous_unexpected_ledger_write_unresolved',
                'previous_unexpected_receipt_persistence_unresolved',
                'fresh_hot_scope_recheck_missing',
                'fresh_writer_capability_tests_missing',
                'fresh_human_authorization_missing',
            ],
            'future_reenable_outputs' => [
                'writer_release_reenable_review_packet_hash',
                'writer_release_fresh_authorization_request_hash',
                'writer_release_new_execution_contract_chain_hash',
                'writer_release_reenable_denial_receipt_hash',
            ],
            'still_forbidden_by_reenable_review_packet_template' => [
                'writer_file_creation_by_writer_release_reenable_review_packet_template',
                'ledger_write_by_writer_release_reenable_review_packet_template',
                'signature_acceptance_by_writer_release_reenable_review_packet_template',
                'signature_validation_by_writer_release_reenable_review_packet_template',
                'receipt_persistence_by_writer_release_reenable_review_packet_template',
                'decision_recording_by_writer_release_reenable_review_packet_template',
                'approval_from_writer_release_reenable_review_packet_template',
                'merge_from_writer_release_reenable_review_packet_template',
                'dispatch_from_writer_release_reenable_review_packet_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'reenable_packet' => $reenablePacket,
            'reenable_packet_hash' => ReadinessHash::stable($reenablePacket),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release re-enable review packet template is ready as a non-authorizing packet. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release re-enable review packet template is blocked until post-monitoring review template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate(array $options = []): array
    {
        $reenablePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options);
        $reenablePacket = (array) data_get($reenablePayload, 'reenable_packet', []);
        $reenableReady = data_get($reenablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_ready';

        $requiredEvidence = [
            'writer_release_reenable_review_packet_hash',
            'selected_reenable_decision_equals_request_fresh_authorization',
            'fresh_execution_contract_chain_hash',
            'fresh_hot_scope_recheck_hash',
            'fresh_writer_capability_test_hash',
            'fresh_security_review_hash',
            'fresh_rollback_plan_hash',
            'fresh_monitoring_plan_hash',
            'fresh_disable_path_hash',
            'human_authorization_intent',
        ];

        $authorizationRequest = [
            'authorization_request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $reenableReady ? 'ready_as_future_fresh_authorization_request_template' : 'blocked_before_writer_release_reenable_review_packet_template',
            'source_writer_release_reenable_review_packet_hash' => data_get($reenablePayload, 'reenable_packet_hash'),
            'source_writer_release_post_monitoring_review_hash' => data_get($reenablePacket, 'source_writer_release_post_monitoring_review_hash'),
            'source_writer_release_observability_contract_hash' => data_get($reenablePacket, 'source_writer_release_observability_contract_hash'),
            'source_writer_release_disable_contract_hash' => data_get($reenablePacket, 'source_writer_release_disable_contract_hash'),
            'source_writer_release_execution_contract_hash' => data_get($reenablePacket, 'source_writer_release_execution_contract_hash'),
            'source_writer_release_execution_contract_preflight_hash' => data_get($reenablePacket, 'source_writer_release_execution_contract_preflight_hash'),
            'required_evidence' => $requiredEvidence,
            'required_evidence_count' => count($requiredEvidence),
            'required_signers' => [
                'human_owner',
                'security_reviewer',
                'release_operator',
            ],
            'required_signer_count' => 3,
            'allowed_authorization_outcomes' => [
                'deny_authorization',
                'request_more_evidence',
                'request_new_execution_contract_chain',
                'approve_fresh_authorization_request_for_signature',
                'escalate_to_human_review',
            ],
            'hard_blocks' => [
                'reenable_review_packet_missing',
                'selected_reenable_decision_not_request_fresh_authorization',
                'fresh_execution_contract_chain_missing',
                'fresh_hot_scope_recheck_missing',
                'fresh_writer_capability_tests_missing',
                'fresh_security_review_missing',
                'fresh_rollback_plan_missing',
                'fresh_monitoring_plan_missing',
                'fresh_disable_path_missing',
                'human_authorization_intent_missing',
            ],
            'future_authorization_outputs' => [
                'writer_release_fresh_authorization_request_hash',
                'writer_release_fresh_authorization_receipt_draft_hash',
                'writer_release_fresh_authorization_signature_request_hash',
                'writer_release_reenable_denial_receipt_hash',
            ],
            'still_forbidden_by_fresh_authorization_request_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_request_template',
                'ledger_write_by_writer_release_fresh_authorization_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_request_template',
                'signature_validation_by_writer_release_fresh_authorization_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_request_template',
                'decision_recording_by_writer_release_fresh_authorization_request_template',
                'approval_from_writer_release_fresh_authorization_request_template',
                'merge_from_writer_release_fresh_authorization_request_template',
                'dispatch_from_writer_release_fresh_authorization_request_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template.v1',
            'status' => $reenableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'authorization_request' => $authorizationRequest,
            'authorization_request_hash' => ReadinessHash::stable($authorizationRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $reenableReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization request template is ready as a non-signing request. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization request template is blocked until re-enable review packet template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        $authorizationPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options);
        $authorizationRequest = (array) data_get($authorizationPayload, 'authorization_request', []);
        $authorizationReady = data_get($authorizationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_ready';

        $receiptClaims = [
            'fresh_authorization_request_reviewed',
            'fresh_authorization_request_hash_bound',
            'required_signers_declared',
            'required_evidence_declared',
            'hard_blocks_declared',
            'writer_creation_still_forbidden',
            'ledger_write_still_forbidden',
            'receipt_persistence_still_forbidden',
            'merge_still_forbidden',
            'dispatch_still_forbidden',
        ];

        $receiptDraft = [
            'receipt_draft_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-RECEIPT-DRAFT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $authorizationReady ? 'ready_as_future_fresh_authorization_receipt_draft_template' : 'blocked_before_writer_release_fresh_authorization_request_template',
            'source_writer_release_fresh_authorization_request_hash' => data_get($authorizationPayload, 'authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($authorizationRequest, 'source_writer_release_reenable_review_packet_hash'),
            'source_writer_release_post_monitoring_review_hash' => data_get($authorizationRequest, 'source_writer_release_post_monitoring_review_hash'),
            'source_writer_release_observability_contract_hash' => data_get($authorizationRequest, 'source_writer_release_observability_contract_hash'),
            'source_writer_release_disable_contract_hash' => data_get($authorizationRequest, 'source_writer_release_disable_contract_hash'),
            'source_writer_release_execution_contract_hash' => data_get($authorizationRequest, 'source_writer_release_execution_contract_hash'),
            'receipt_claims' => $receiptClaims,
            'receipt_claim_count' => count($receiptClaims),
            'unsigned_receipt_fields' => [
                'receipt_id',
                'receipt_type',
                'request_hash',
                'reviewed_evidence_hashes',
                'authorized_outcome',
                'required_signers',
                'signed_by',
                'signed_at',
                'signature_hash',
                'expiration_policy',
            ],
            'required_authorized_outcome' => 'approve_fresh_authorization_request_for_signature',
            'invalid_authorized_outcomes' => [
                'deny_authorization',
                'request_more_evidence',
                'request_new_execution_contract_chain',
                'escalate_to_human_review',
            ],
            'receipt_expiration_policy' => [
                'expires_if_source_request_changes',
                'expires_if_hot_scope_changes',
                'expires_if_security_review_changes',
                'expires_if_required_signer_changes',
                'expires_if_monitoring_plan_changes',
            ],
            'future_receipt_outputs' => [
                'writer_release_fresh_authorization_receipt_draft_hash',
                'writer_release_fresh_authorization_signature_request_hash',
                'writer_release_fresh_authorization_post_signature_runbook_hash',
            ],
            'still_forbidden_by_fresh_authorization_receipt_draft_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_receipt_draft_template',
                'ledger_write_by_writer_release_fresh_authorization_receipt_draft_template',
                'signature_acceptance_by_writer_release_fresh_authorization_receipt_draft_template',
                'signature_validation_by_writer_release_fresh_authorization_receipt_draft_template',
                'receipt_persistence_by_writer_release_fresh_authorization_receipt_draft_template',
                'decision_recording_by_writer_release_fresh_authorization_receipt_draft_template',
                'approval_from_writer_release_fresh_authorization_receipt_draft_template',
                'merge_from_writer_release_fresh_authorization_receipt_draft_template',
                'dispatch_from_writer_release_fresh_authorization_receipt_draft_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template.v1',
            'status' => $authorizationReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'receipt_draft' => $receiptDraft,
            'receipt_draft_hash' => ReadinessHash::stable($receiptDraft),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_dispatch_work',
            ],
            'human_summary' => $authorizationReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization receipt draft template is ready as an unsigned receipt draft. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization receipt draft template is blocked until fresh authorization request template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options);
        $receiptDraft = (array) data_get($receiptPayload, 'receipt_draft', []);
        $receiptDraftReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_ready';

        $signablePayloadFields = [
            'receipt_draft_hash',
            'source_writer_release_fresh_authorization_request_hash',
            'source_writer_release_reenable_review_packet_hash',
            'required_authorized_outcome',
            'receipt_expiration_policy',
            'required_signers',
            'non_execution_guarantees',
        ];

        $signatureRequest = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-SIGNATURE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $receiptDraftReady ? 'ready_as_future_fresh_authorization_signature_request_template' : 'blocked_before_writer_release_fresh_authorization_receipt_draft_template',
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($receiptPayload, 'receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($receiptDraft, 'source_writer_release_reenable_review_packet_hash'),
            'source_writer_release_post_monitoring_review_hash' => data_get($receiptDraft, 'source_writer_release_post_monitoring_review_hash'),
            'required_authorized_outcome' => data_get($receiptDraft, 'required_authorized_outcome'),
            'signable_payload_fields' => $signablePayloadFields,
            'signable_payload_field_count' => count($signablePayloadFields),
            'required_signers' => [
                'human_owner',
                'security_reviewer',
                'release_operator',
            ],
            'required_signer_count' => 3,
            'signature_acceptance_conditions' => [
                'signature_must_reference_exact_receipt_draft_hash',
                'signature_must_reference_exact_authorization_request_hash',
                'signature_must_include_all_required_signers',
                'signature_must_include_expiration_policy',
                'signature_must_be_reviewed_by_post_signature_runbook',
            ],
            'signature_rejection_conditions' => [
                'receipt_draft_hash_changed',
                'authorization_request_hash_changed',
                'missing_required_signer',
                'expired_receipt_draft',
                'changed_hot_scope',
                'changed_security_review',
                'changed_monitoring_plan',
            ],
            'future_signature_outputs' => [
                'writer_release_fresh_authorization_signature_request_hash',
                'writer_release_fresh_authorization_post_signature_runbook_hash',
                'writer_release_fresh_authorization_signed_receipt_template_hash',
            ],
            'still_forbidden_by_fresh_authorization_signature_request_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_signature_request_template',
                'ledger_write_by_writer_release_fresh_authorization_signature_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_signature_request_template',
                'signature_validation_by_writer_release_fresh_authorization_signature_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_signature_request_template',
                'decision_recording_by_writer_release_fresh_authorization_signature_request_template',
                'approval_from_writer_release_fresh_authorization_signature_request_template',
                'merge_from_writer_release_fresh_authorization_signature_request_template',
                'dispatch_from_writer_release_fresh_authorization_signature_request_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template.v1',
            'status' => $receiptDraftReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template',
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
            'signature_request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $receiptDraftReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization signature request template is ready as a non-accepting signature request. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization signature request template is blocked until fresh authorization receipt draft template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signatureRequestReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_ready';

        $steps = [
            'collect_external_signature_evidence',
            'confirm_signature_references_exact_signature_request_hash',
            'confirm_signature_references_exact_receipt_draft_hash',
            'confirm_signature_references_exact_authorization_request_hash',
            'confirm_all_required_signers_are_present',
            'recheck_receipt_expiration_policy',
            'recheck_hot_scope_security_and_monitoring_plan',
            'prepare_signed_receipt_template_without_persisting',
        ];

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-POST-SIGNATURE-RUNBOOK-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $signatureRequestReady ? 'ready_as_future_fresh_authorization_post_signature_runbook_template' : 'blocked_before_writer_release_fresh_authorization_signature_request_template',
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($signaturePayload, 'signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($signatureRequest, 'source_writer_release_reenable_review_packet_hash'),
            'steps' => $steps,
            'step_count' => count($steps),
            'required_external_evidence' => [
                'external_signature_payload_hash',
                'external_signer_identity_manifest_hash',
                'external_signature_timestamp',
                'external_signature_scope_hash',
                'external_signature_expiration_policy_hash',
            ],
            'hard_stops' => [
                'missing_external_signature_evidence',
                'signature_request_hash_mismatch',
                'receipt_draft_hash_mismatch',
                'authorization_request_hash_mismatch',
                'missing_required_signer',
                'expired_receipt_draft',
                'changed_hot_scope',
                'changed_security_review',
                'changed_monitoring_plan',
            ],
            'future_runbook_outputs' => [
                'writer_release_fresh_authorization_post_signature_runbook_hash',
                'writer_release_fresh_authorization_signed_receipt_template_hash',
                'writer_release_fresh_authorization_signature_rejection_hash',
            ],
            'still_forbidden_by_fresh_authorization_post_signature_runbook_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'ledger_write_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'signature_acceptance_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'signature_validation_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'receipt_persistence_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'decision_recording_by_writer_release_fresh_authorization_post_signature_runbook_template',
                'approval_from_writer_release_fresh_authorization_post_signature_runbook_template',
                'merge_from_writer_release_fresh_authorization_post_signature_runbook_template',
                'dispatch_from_writer_release_fresh_authorization_post_signature_runbook_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template.v1',
            'status' => $signatureRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_dispatch_work',
            ],
            'human_summary' => $signatureRequestReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization post-signature runbook template is ready as a non-validating sequence. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization post-signature runbook template is blocked until fresh authorization signature request template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_ready';

        $templateFields = [
            'signed_receipt_id',
            'receipt_type',
            'source_post_signature_runbook_hash',
            'source_signature_request_hash',
            'source_receipt_draft_hash',
            'source_authorization_request_hash',
            'external_signature_evidence_hash',
            'required_signers',
            'authorization_scope',
            'expiration_policy',
        ];

        $template = [
            'signed_receipt_template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'ready_as_future_fresh_authorization_signed_receipt_template' : 'blocked_before_writer_release_fresh_authorization_post_signature_runbook_template',
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($runbook, 'source_writer_release_reenable_review_packet_hash'),
            'template_fields' => $templateFields,
            'template_field_count' => count($templateFields),
            'required_external_evidence' => [
                'external_signature_payload_hash',
                'external_signer_identity_manifest_hash',
                'external_signature_scope_hash',
                'external_signature_expiration_policy_hash',
                'post_signature_runbook_hash',
            ],
            'receipt_scope' => [
                'fresh_authorization_only',
                'does_not_reenable_writer',
                'does_not_create_writer_file',
                'does_not_write_ledger',
                'does_not_persist_receipt',
                'does_not_merge',
                'does_not_dispatch',
            ],
            'future_template_outputs' => [
                'writer_release_fresh_authorization_signed_receipt_template_hash',
                'writer_release_fresh_authorization_execution_contract_preflight_hash',
                'writer_release_fresh_authorization_signature_rejection_hash',
            ],
            'still_forbidden_by_fresh_authorization_signed_receipt_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_signed_receipt_template',
                'ledger_write_by_writer_release_fresh_authorization_signed_receipt_template',
                'signature_acceptance_by_writer_release_fresh_authorization_signed_receipt_template',
                'signature_validation_by_writer_release_fresh_authorization_signed_receipt_template',
                'receipt_persistence_by_writer_release_fresh_authorization_signed_receipt_template',
                'decision_recording_by_writer_release_fresh_authorization_signed_receipt_template',
                'approval_from_writer_release_fresh_authorization_signed_receipt_template',
                'merge_from_writer_release_fresh_authorization_signed_receipt_template',
                'dispatch_from_writer_release_fresh_authorization_signed_receipt_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization signed receipt template is ready as a non-persisting template. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization signed receipt template is blocked until fresh authorization post-signature runbook template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate(array $options = []): array
    {
        $signedReceiptPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options);
        $signedReceipt = (array) data_get($signedReceiptPayload, 'template', []);
        $signedReceiptReady = data_get($signedReceiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_ready';

        $blockingConditions = [
            'fresh_authorization_signed_receipt_template_not_ready',
            'external_signature_evidence_missing',
            'external_signature_not_validated_by_external_system',
            'fresh_hot_scope_recheck_missing',
            'fresh_security_review_missing',
            'fresh_execution_contract_chain_missing',
            'fresh_disable_path_missing',
            'fresh_rollback_plan_missing',
            'fresh_monitoring_plan_missing',
            'human_execution_authorization_missing',
        ];

        $requiredInputs = [
            'fresh_authorization_signed_receipt_template_hash',
            'fresh_hot_scope_recheck_hash',
            'fresh_security_review_hash',
            'fresh_execution_contract_chain_hash',
            'fresh_disable_path_hash',
            'fresh_rollback_plan_hash',
            'fresh_monitoring_plan_hash',
            'human_execution_authorization_hash',
        ];

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-EXECUTION-CONTRACT-PREFLIGHT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $signedReceiptReady ? 'ready_as_future_fresh_authorization_execution_contract_preflight_template' : 'blocked_before_writer_release_fresh_authorization_signed_receipt_template',
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($signedReceiptPayload, 'template_hash'),
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($signedReceipt, 'source_writer_release_reenable_review_packet_hash'),
            'blocking_condition_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'required_input_count' => count($requiredInputs),
            'required_inputs' => $requiredInputs,
            'future_preflight_outputs' => [
                'writer_release_fresh_authorization_execution_contract_preflight_hash',
                'writer_release_fresh_authorization_execution_contract_template_hash',
                'writer_release_fresh_authorization_preflight_rejection_hash',
            ],
            'still_forbidden_by_fresh_authorization_execution_contract_preflight_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'ledger_write_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'signature_acceptance_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'signature_validation_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'receipt_persistence_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'decision_recording_by_writer_release_fresh_authorization_execution_contract_preflight_template',
                'approval_from_writer_release_fresh_authorization_execution_contract_preflight_template',
                'merge_from_writer_release_fresh_authorization_execution_contract_preflight_template',
                'dispatch_from_writer_release_fresh_authorization_execution_contract_preflight_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template.v1',
            'status' => $signedReceiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_dispatch_work',
            ],
            'human_summary' => $signedReceiptReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization execution contract preflight template is ready as a non-executing preflight. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization execution contract preflight template is blocked until fresh authorization signed receipt template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_ready';

        $blockingConditions = $preflightReady
            ? [
                'fresh_authorization_execution_still_not_authorized',
                'writer_release_reenable_still_not_authorized',
                'validated_external_signature_evidence_not_bound_to_contract',
                'human_execution_authorization_not_attached',
                'rollback_plan_not_rechecked',
                'disable_path_not_rechecked',
                'monitoring_plan_not_rechecked',
                'hot_scope_recheck_not_attached',
                'security_review_not_attached',
                'post_execution_receipt_plan_not_attached',
            ]
            : [
                'writer_release_fresh_authorization_execution_contract_preflight_template_not_ready',
            ];

        $contract = [
            'contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-EXECUTION-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'blocked_waiting_for_external_fresh_authorization_execution_authority' : 'blocked_before_writer_release_fresh_authorization_execution_contract_preflight_template',
            'source_writer_release_fresh_authorization_execution_contract_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($preflight, 'source_writer_release_reenable_review_packet_hash'),
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'execution_scope' => [
                'allowed_scope' => 'future_writer_reenable_only_after_external_fresh_authorization',
                'forbidden_scope' => [
                    'merge_execution',
                    'dispatch_execution',
                    'receipt_persistence_execution',
                    'policy_mutation',
                    'hot_scope_mutation',
                    'unscoped_file_creation',
                    'self_authorized_reenable',
                ],
                'writer_contract_expected_capability' => 'fresh_authorization_to_reenable_signed_receipt_persistence_writer_only',
                'writer_contract_forbidden_capabilities' => [
                    'merge_authority',
                    'dispatch_authority',
                    'signature_validation_authority',
                    'approval_authority',
                    'self_release_authority',
                    'self_reenable_authority',
                ],
            ],
            'required_actor_evidence' => [
                'fresh_authorization_executor_identity',
                'fresh_authorization_executor_session',
                'fresh_authorization_execution_reason',
                'fresh_authorization_execution_scope_hash',
                'fresh_authorization_execution_contract_reviewer_identity',
            ],
            'required_recheck_evidence' => [
                'fresh_authorization_contract_hash_rechecked_against_patch',
                'fresh_hot_scope_clean_recheck_hash',
                'fresh_writer_capability_test_output_hash',
                'fresh_writer_no_merge_authority_evidence_hash',
                'fresh_writer_no_dispatch_authority_evidence_hash',
                'fresh_disable_path_evidence_hash',
                'fresh_rollback_plan_hash',
                'fresh_monitoring_plan_hash',
            ],
            'future_post_execution_outputs' => [
                'writer_release_fresh_authorization_execution_contract_hash',
                'writer_release_fresh_authorization_disable_contract_hash',
                'writer_release_fresh_authorization_observability_contract_hash',
                'writer_release_fresh_authorization_post_execution_receipt_hash',
            ],
            'still_forbidden_by_fresh_authorization_execution_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_execution_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_execution_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_execution_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_execution_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_execution_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_execution_contract_template',
                'approval_from_writer_release_fresh_authorization_execution_contract_template',
                'merge_from_writer_release_fresh_authorization_execution_contract_template',
                'dispatch_from_writer_release_fresh_authorization_execution_contract_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization execution contract template is ready as a non-authorizing contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization execution contract template is blocked until fresh authorization execution contract preflight template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate(array $options = []): array
    {
        $contractPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_ready';

        $disableTriggers = [
            'fresh_authorization_contract_hash_drift_detected',
            'fresh_hot_scope_dirty_after_reenable',
            'fresh_writer_capability_tests_failed_after_reenable',
            'fresh_writer_merge_authority_detected',
            'fresh_writer_dispatch_authority_detected',
            'unexpected_signature_validation_attempt_after_fresh_authorization',
            'unexpected_receipt_persistence_attempt_after_fresh_authorization',
            'unexpected_ledger_write_attempt_after_fresh_authorization',
            'operator_revocation_requested_after_fresh_authorization',
            'fresh_rollback_plan_missing_or_invalid',
        ];

        $disableContract = [
            'disable_contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-DISABLE-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $contractReady ? 'ready_as_future_fresh_authorization_disable_template' : 'blocked_before_writer_release_fresh_authorization_execution_contract_template',
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($contractPayload, 'contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_preflight_hash' => data_get($contract, 'source_writer_release_fresh_authorization_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($contract, 'source_writer_release_fresh_authorization_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($contract, 'source_writer_release_fresh_authorization_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($contract, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($contract, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($contract, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($contract, 'source_writer_release_reenable_review_packet_hash'),
            'disable_triggers' => $disableTriggers,
            'trigger_count' => count($disableTriggers),
            'required_disable_steps' => [
                'stop_fresh_authorization_writer_reenable_runtime',
                'revoke_fresh_authorization_writer_capability_flag',
                'quarantine_fresh_authorization_outputs',
                'rerun_fresh_writer_no_merge_authority_check',
                'rerun_fresh_writer_no_dispatch_authority_check',
                'capture_fresh_disable_reason_and_actor',
                'generate_future_fresh_authorization_post_disable_receipt',
                'require_human_review_before_any_new_reenable',
            ],
            'required_disable_evidence' => [
                'fresh_disable_actor_identity',
                'fresh_disable_reason',
                'fresh_disable_trigger_id',
                'fresh_runtime_stop_evidence_hash',
                'fresh_capability_revocation_evidence_hash',
                'fresh_quarantine_manifest_hash',
                'fresh_post_disable_no_merge_authority_evidence_hash',
                'fresh_post_disable_no_dispatch_authority_evidence_hash',
            ],
            'future_disable_outputs' => [
                'writer_release_fresh_authorization_disable_contract_hash',
                'writer_release_fresh_authorization_disable_receipt_hash',
                'writer_release_fresh_authorization_revocation_event_hash',
                'writer_release_fresh_authorization_reenable_review_packet_hash',
            ],
            'reenable_requirements' => [
                'new_fresh_authorization_request_template',
                'new_fresh_authorization_receipt_draft_template',
                'new_fresh_authorization_signature_request_template',
                'new_fresh_authorization_signed_receipt_template',
                'new_fresh_execution_contract_preflight_template',
                'new_fresh_execution_contract_template',
                'new_fresh_disable_contract_template',
                'fresh_human_authorization',
                'fresh_hot_scope_recheck',
                'fresh_writer_capability_tests',
            ],
            'still_forbidden_by_fresh_authorization_disable_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_disable_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_disable_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_disable_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_disable_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_disable_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_disable_contract_template',
                'approval_from_writer_release_fresh_authorization_disable_contract_template',
                'merge_from_writer_release_fresh_authorization_disable_contract_template',
                'dispatch_from_writer_release_fresh_authorization_disable_contract_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization disable contract template is ready as a rollback contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization disable contract template is blocked until fresh authorization execution contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate(array $options = []): array
    {
        $disablePayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options);
        $disableContract = (array) data_get($disablePayload, 'disable_contract', []);
        $disableReady = data_get($disablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_ready';

        $signals = [
            'fresh_authorization_execution_contract_loaded',
            'fresh_authorization_writer_reenable_runtime_started',
            'fresh_authorization_writer_capability_flag_checked',
            'fresh_authorization_receipt_persistence_attempted',
            'fresh_authorization_ledger_write_attempted',
            'fresh_authorization_forbidden_merge_attempt_detected',
            'fresh_authorization_forbidden_dispatch_attempt_detected',
            'fresh_authorization_disable_trigger_detected',
            'fresh_authorization_disable_completed',
            'fresh_authorization_reenable_requested_again',
            'fresh_authorization_post_execution_receipt_generated',
            'fresh_authorization_human_review_required',
        ];

        $observabilityContract = [
            'observability_contract_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-OBSERVABILITY-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $disableReady ? 'ready_as_future_fresh_authorization_observability_template' : 'blocked_before_writer_release_fresh_authorization_disable_contract_template',
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($disablePayload, 'disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_preflight_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($disableContract, 'source_writer_release_reenable_review_packet_hash'),
            'required_signals' => $signals,
            'signal_count' => count($signals),
            'required_metrics' => [
                'fresh_authorization_reenable_attempt_count',
                'fresh_authorization_reenable_success_count',
                'fresh_authorization_reenable_blocked_count',
                'fresh_authorization_disable_trigger_count',
                'fresh_authorization_forbidden_merge_attempt_count',
                'fresh_authorization_forbidden_dispatch_attempt_count',
                'fresh_authorization_unexpected_ledger_write_attempt_count',
                'fresh_authorization_unexpected_receipt_persistence_attempt_count',
                'fresh_authorization_time_to_disable_ms',
            ],
            'required_alerts' => [
                'alert_on_fresh_authorization_contract_hash_drift',
                'alert_on_fresh_hot_scope_dirty_after_reenable',
                'alert_on_fresh_writer_capability_test_failure',
                'alert_on_fresh_forbidden_merge_authority',
                'alert_on_fresh_forbidden_dispatch_authority',
                'alert_on_fresh_unexpected_signature_validation',
                'alert_on_fresh_unexpected_receipt_persistence',
                'alert_on_fresh_unexpected_ledger_write',
            ],
            'required_observability_evidence' => [
                'trace_id',
                'operation_id',
                'fresh_authorization_execution_contract_hash',
                'fresh_authorization_disable_contract_hash',
                'fresh_authorization_signal_manifest_hash',
                'fresh_authorization_metrics_snapshot_hash',
                'fresh_authorization_alert_policy_hash',
                'fresh_authorization_post_release_monitoring_window',
            ],
            'minimum_monitoring_window' => [
                'after_future_reenable_minutes' => 60,
                'after_future_disable_minutes' => 30,
                'requires_human_review_before_window_close' => true,
            ],
            'future_observability_outputs' => [
                'writer_release_fresh_authorization_observability_contract_hash',
                'writer_release_fresh_authorization_signal_manifest_hash',
                'writer_release_fresh_authorization_metrics_snapshot_hash',
                'writer_release_fresh_authorization_alert_policy_hash',
                'writer_release_fresh_authorization_post_monitoring_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_observability_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_observability_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_observability_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_observability_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_observability_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_observability_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_observability_contract_template',
                'approval_from_writer_release_fresh_authorization_observability_contract_template',
                'merge_from_writer_release_fresh_authorization_observability_contract_template',
                'dispatch_from_writer_release_fresh_authorization_observability_contract_template',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template.v1',
            'status' => $disableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $disableReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization observability contract template is ready as a non-executing monitoring contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization observability contract template is blocked until fresh authorization disable contract template is ready.',
        ];
    }
}
