<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 06 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate
 */
final class ReviewMergePart06SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options);
        $reviewTemplate = (array) data_get($reviewPayload, 'review_template', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template_ready';
    
        $requirements = [
            'post_monitoring_review_template_ready',
            'selected_post_monitoring_decision_equals_request_reenable_review',
            'fresh_execution_contract_preflight_required',
            'fresh_execution_contract_template_required',
            'fresh_disable_contract_template_required',
            'fresh_observability_contract_template_required',
            'fresh_workspace_identity_recheck_required',
            'fresh_obra_identity_recheck_required',
            'fresh_provider_identity_recheck_required',
            'fresh_hot_scope_recheck_required',
            'fresh_writer_capability_tests_required',
            'fresh_human_authorization_required',
            'previous_disable_or_watch_reason_resolved',
        ];
    
        $reenablePacket = [
            'reenable_packet_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-REENABLE-REVIEW-PACKET-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($reviewTemplate, 'workspace'),
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
                'fresh_workspace_identity_recheck_hash',
                'fresh_obra_identity_recheck_hash',
                'fresh_provider_identity_recheck_hash',
                'fresh_hot_scope_recheck_hash',
                'fresh_writer_capability_test_hash',
                'fresh_execution_contract_template_hash',
                'fresh_disable_contract_template_hash',
                'fresh_observability_contract_template_hash',
                'fresh_human_authorization_hash',
            ],
            'hard_blocks' => [
                'previous_workspace_identity_drift_unresolved',
                'previous_obra_identity_drift_unresolved',
                'previous_provider_identity_drift_unresolved',
                'previous_cross_workspace_attempt_unresolved',
                'previous_cross_obra_attempt_unresolved',
                'previous_forbidden_merge_attempt_unresolved',
                'previous_forbidden_dispatch_attempt_unresolved',
                'previous_unexpected_ledger_write_unresolved',
                'previous_unexpected_receipt_persistence_unresolved',
                'fresh_workspace_identity_recheck_missing',
                'fresh_obra_identity_recheck_missing',
                'fresh_provider_identity_recheck_missing',
                'fresh_hot_scope_recheck_missing',
                'fresh_writer_capability_tests_missing',
                'fresh_human_authorization_missing',
            ],
            'future_reenable_outputs' => [
                'writer_release_reenable_review_packet_hash',
                'writer_release_fresh_authorization_request_hash',
                'writer_release_new_execution_contract_chain_hash',
                'writer_release_reenable_denial_receipt_hash',
                'writer_release_reenable_workspace_identity_hash',
                'writer_release_reenable_obra_identity_hash',
                'writer_release_reenable_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release re-enable review packet template is ready as a provider-neutral Forge Workspace packet. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release re-enable review packet template is blocked until post-monitoring review template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate(array $options = []): array
    {
        $reenablePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options);
        $reenablePacket = (array) data_get($reenablePayload, 'reenable_packet', []);
        $reenableReady = data_get($reenablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_reenable_review_packet_template_ready';
    
        $requiredEvidence = [
            'writer_release_reenable_review_packet_hash',
            'selected_reenable_decision_equals_request_fresh_authorization',
            'fresh_workspace_identity_recheck_hash',
            'fresh_obra_identity_recheck_hash',
            'fresh_provider_identity_recheck_hash',
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
            'authorization_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($reenablePacket, 'workspace'),
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
                'workspace_steward',
                'security_reviewer',
                'release_operator',
            ],
            'required_signer_count' => 4,
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
                'fresh_workspace_identity_recheck_missing',
                'fresh_obra_identity_recheck_missing',
                'fresh_provider_identity_recheck_missing',
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
                'writer_release_fresh_authorization_workspace_identity_hash',
                'writer_release_fresh_authorization_obra_identity_hash',
                'writer_release_fresh_authorization_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template.v1',
            'status' => $reenableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $reenableReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization request template is ready as a provider-neutral Forge Workspace request. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization request template is blocked until re-enable review packet template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        $authorizationPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options);
        $authorizationRequest = (array) data_get($authorizationPayload, 'authorization_request', []);
        $authorizationReady = data_get($authorizationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_request_template_ready';
    
        $receiptClaims = [
            'fresh_authorization_request_reviewed',
            'fresh_authorization_request_hash_bound',
            'workspace_identity_hash_required',
            'obra_identity_hash_required',
            'provider_identity_hash_required',
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
            'receipt_draft_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-RECEIPT-DRAFT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($authorizationRequest, 'workspace'),
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
                'workspace_id',
                'obra_id',
                'provider_identity_hash',
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
                'expires_if_workspace_identity_changes',
                'expires_if_obra_identity_changes',
                'expires_if_provider_identity_changes',
                'expires_if_hot_scope_changes',
                'expires_if_security_review_changes',
                'expires_if_required_signer_changes',
                'expires_if_monitoring_plan_changes',
            ],
            'future_receipt_outputs' => [
                'writer_release_fresh_authorization_receipt_draft_hash',
                'writer_release_fresh_authorization_signature_request_hash',
                'writer_release_fresh_authorization_post_signature_runbook_hash',
                'writer_release_fresh_authorization_receipt_workspace_identity_hash',
                'writer_release_fresh_authorization_receipt_obra_identity_hash',
                'writer_release_fresh_authorization_receipt_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template.v1',
            'status' => $authorizationReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_does_not_dispatch_work',
            ],
            'human_summary' => $authorizationReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization receipt draft template is ready as a provider-neutral unsigned Forge Workspace receipt draft. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization receipt draft template is blocked until fresh authorization request template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options);
        $receiptDraft = (array) data_get($receiptPayload, 'receipt_draft', []);
        $receiptDraftReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_receipt_draft_template_ready';
    
        $signablePayloadFields = [
            'receipt_draft_hash',
            'workspace_id',
            'obra_id',
            'provider_identity_hash',
            'source_writer_release_fresh_authorization_request_hash',
            'source_writer_release_reenable_review_packet_hash',
            'required_authorized_outcome',
            'receipt_expiration_policy',
            'required_signers',
            'non_execution_guarantees',
        ];
    
        $signatureRequest = [
            'signature_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-SIGNATURE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($receiptDraft, 'workspace'),
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
                'workspace_steward',
                'security_reviewer',
                'release_operator',
            ],
            'required_signer_count' => 4,
            'signature_acceptance_conditions' => [
                'signature_must_reference_exact_receipt_draft_hash',
                'signature_must_reference_exact_authorization_request_hash',
                'signature_must_reference_exact_workspace_id',
                'signature_must_reference_exact_obra_id',
                'signature_must_reference_exact_provider_identity_hash',
                'signature_must_include_all_required_signers',
                'signature_must_include_expiration_policy',
                'signature_must_be_reviewed_by_post_signature_runbook',
            ],
            'signature_rejection_conditions' => [
                'receipt_draft_hash_changed',
                'authorization_request_hash_changed',
                'workspace_identity_changed',
                'obra_identity_changed',
                'provider_identity_changed',
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
                'writer_release_fresh_authorization_signature_workspace_identity_hash',
                'writer_release_fresh_authorization_signature_obra_identity_hash',
                'writer_release_fresh_authorization_signature_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template.v1',
            'status' => $receiptDraftReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $receiptDraftReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization signature request template is ready as a provider-neutral Forge Workspace signature request. It still does not accept signatures, validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization signature request template is blocked until fresh authorization receipt draft template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        $signaturePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signatureRequestReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signature_request_template_ready';
    
        $steps = [
            'collect_external_signature_evidence',
            'confirm_signature_references_exact_signature_request_hash',
            'confirm_signature_references_exact_receipt_draft_hash',
            'confirm_signature_references_exact_authorization_request_hash',
            'confirm_signature_references_exact_workspace_id',
            'confirm_signature_references_exact_obra_id',
            'confirm_signature_references_exact_provider_identity_hash',
            'confirm_all_required_signers_are_present',
            'recheck_receipt_expiration_policy',
            'recheck_hot_scope_security_and_monitoring_plan',
            'prepare_signed_receipt_template_without_persisting',
        ];
    
        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-POST-SIGNATURE-RUNBOOK-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signatureRequest, 'workspace'),
            'status' => $signatureRequestReady ? 'ready_as_future_fresh_authorization_post_signature_runbook_template' : 'blocked_before_writer_release_fresh_authorization_signature_request_template',
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($signaturePayload, 'signature_request_hash'),
            'source_writer_release_fresh_authorization_receipt_draft_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($signatureRequest, 'source_writer_release_reenable_review_packet_hash'),
            'source_writer_release_post_monitoring_review_hash' => data_get($signatureRequest, 'source_writer_release_post_monitoring_review_hash'),
            'steps' => $steps,
            'step_count' => count($steps),
            'required_external_evidence' => [
                'external_signature_payload_hash',
                'external_signer_identity_manifest_hash',
                'external_signature_timestamp',
                'external_signature_scope_hash',
                'external_signature_expiration_policy_hash',
                'external_signature_workspace_identity_hash',
                'external_signature_obra_identity_hash',
                'external_signature_provider_identity_hash',
            ],
            'hard_stops' => [
                'missing_external_signature_evidence',
                'signature_request_hash_mismatch',
                'receipt_draft_hash_mismatch',
                'authorization_request_hash_mismatch',
                'workspace_identity_mismatch',
                'obra_identity_mismatch',
                'provider_identity_mismatch',
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
                'writer_release_fresh_authorization_post_signature_workspace_identity_hash',
                'writer_release_fresh_authorization_post_signature_obra_identity_hash',
                'writer_release_fresh_authorization_post_signature_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template.v1',
            'status' => $signatureRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_does_not_dispatch_work',
            ],
            'human_summary' => $signatureRequestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization post-signature runbook template is ready as a provider-neutral Forge Workspace non-validating sequence. It still does not accept signatures, validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization post-signature runbook template is blocked until fresh authorization signature request template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_signature_runbook_template_ready';
    
        $templateFields = [
            'signed_receipt_id',
            'receipt_type',
            'workspace_id',
            'obra_id',
            'provider_identity_hash',
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
            'signed_receipt_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($runbook, 'workspace'),
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
                'external_signature_workspace_identity_hash',
                'external_signature_obra_identity_hash',
                'external_signature_provider_identity_hash',
                'post_signature_runbook_hash',
            ],
            'receipt_scope' => [
                'fresh_authorization_only',
                'forge_workspace_only',
                'obra_atlas_self_construction_os_only',
                'provider_identity_bound',
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
                'writer_release_fresh_authorization_signed_receipt_workspace_identity_hash',
                'writer_release_fresh_authorization_signed_receipt_obra_identity_hash',
                'writer_release_fresh_authorization_signed_receipt_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization signed receipt template is ready as a provider-neutral Forge Workspace non-persisting template. It still does not accept signatures, validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization signed receipt template is blocked until fresh authorization post-signature runbook template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate(array $options = []): array
    {
        $signedReceiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options);
        $signedReceipt = (array) data_get($signedReceiptPayload, 'template', []);
        $signedReceiptReady = data_get($signedReceiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_signed_receipt_template_ready';
    
        $blockingConditions = [
            'fresh_authorization_signed_receipt_template_not_ready',
            'external_signature_evidence_missing',
            'external_signature_not_validated_by_external_system',
            'fresh_workspace_identity_recheck_missing',
            'fresh_obra_identity_recheck_missing',
            'fresh_provider_identity_recheck_missing',
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
            'fresh_workspace_identity_recheck_hash',
            'fresh_obra_identity_recheck_hash',
            'fresh_provider_identity_recheck_hash',
            'fresh_hot_scope_recheck_hash',
            'fresh_security_review_hash',
            'fresh_execution_contract_chain_hash',
            'fresh_disable_path_hash',
            'fresh_rollback_plan_hash',
            'fresh_monitoring_plan_hash',
            'human_execution_authorization_hash',
        ];
    
        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-EXECUTION-CONTRACT-PREFLIGHT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signedReceipt, 'workspace'),
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
                'writer_release_fresh_authorization_execution_contract_workspace_identity_hash',
                'writer_release_fresh_authorization_execution_contract_obra_identity_hash',
                'writer_release_fresh_authorization_execution_contract_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template.v1',
            'status' => $signedReceiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_does_not_dispatch_work',
            ],
            'human_summary' => $signedReceiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization execution contract preflight template is ready as a provider-neutral Forge Workspace non-executing preflight. It still does not accept signatures, validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization execution contract preflight template is blocked until fresh authorization signed receipt template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_preflight_template_ready';
    
        $blockingConditions = $preflightReady
            ? [
                'fresh_authorization_execution_still_not_authorized',
                'writer_release_reenable_still_not_authorized',
                'validated_external_signature_evidence_not_bound_to_contract',
                'human_execution_authorization_not_attached',
                'workspace_identity_not_bound_to_execution_contract',
                'obra_identity_not_bound_to_execution_contract',
                'provider_identity_not_bound_to_execution_contract',
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
            'contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-EXECUTION-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
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
                'workspace_id' => data_get($preflight, 'workspace.workspace_id'),
                'obra_id' => data_get($preflight, 'workspace.obra_id'),
                'provider_identity_required' => true,
                'forbidden_scope' => [
                    'merge_execution',
                    'dispatch_execution',
                    'receipt_persistence_execution',
                    'policy_mutation',
                    'hot_scope_mutation',
                    'unscoped_file_creation',
                    'cross_workspace_execution',
                    'cross_obra_execution',
                    'provider_identity_substitution',
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
                    'cross_workspace_authority',
                    'cross_provider_identity_authority',
                ],
            ],
            'required_actor_evidence' => [
                'fresh_authorization_executor_identity',
                'fresh_authorization_executor_session',
                'fresh_authorization_executor_provider',
                'fresh_authorization_executor_provider_identity_hash',
                'fresh_authorization_execution_reason',
                'fresh_authorization_execution_scope_hash',
                'fresh_authorization_execution_contract_reviewer_identity',
            ],
            'required_recheck_evidence' => [
                'fresh_authorization_contract_hash_rechecked_against_patch',
                'fresh_workspace_identity_recheck_hash',
                'fresh_obra_identity_recheck_hash',
                'fresh_provider_identity_recheck_hash',
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
                'writer_release_fresh_authorization_execution_contract_workspace_identity_hash',
                'writer_release_fresh_authorization_execution_contract_obra_identity_hash',
                'writer_release_fresh_authorization_execution_contract_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization execution contract template is ready as a provider-neutral Forge Workspace non-authorizing contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization execution contract template is blocked until fresh authorization execution contract preflight template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate(array $options = []): array
    {
        $contractPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_execution_contract_template_ready';
    
        $disableTriggers = [
            'fresh_authorization_contract_hash_drift_detected',
            'fresh_workspace_identity_drift_detected',
            'fresh_obra_identity_drift_detected',
            'fresh_provider_identity_drift_detected',
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
            'disable_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-DISABLE-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($contract, 'workspace'),
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
                'rerun_fresh_workspace_identity_check',
                'rerun_fresh_obra_identity_check',
                'rerun_fresh_provider_identity_check',
                'rerun_fresh_writer_no_merge_authority_check',
                'rerun_fresh_writer_no_dispatch_authority_check',
                'capture_fresh_disable_reason_actor_and_provider',
                'generate_future_fresh_authorization_post_disable_receipt',
                'require_human_review_before_any_new_reenable',
            ],
            'required_disable_evidence' => [
                'fresh_disable_actor_identity',
                'fresh_disable_actor_provider',
                'fresh_disable_actor_provider_identity_hash',
                'fresh_disable_reason',
                'fresh_disable_trigger_id',
                'fresh_runtime_stop_evidence_hash',
                'fresh_capability_revocation_evidence_hash',
                'fresh_quarantine_manifest_hash',
                'fresh_post_disable_workspace_identity_evidence_hash',
                'fresh_post_disable_obra_identity_evidence_hash',
                'fresh_post_disable_provider_identity_evidence_hash',
                'fresh_post_disable_no_merge_authority_evidence_hash',
                'fresh_post_disable_no_dispatch_authority_evidence_hash',
            ],
            'future_disable_outputs' => [
                'writer_release_fresh_authorization_disable_contract_hash',
                'writer_release_fresh_authorization_disable_receipt_hash',
                'writer_release_fresh_authorization_revocation_event_hash',
                'writer_release_fresh_authorization_workspace_identity_recheck_hash',
                'writer_release_fresh_authorization_obra_identity_recheck_hash',
                'writer_release_fresh_authorization_provider_identity_recheck_hash',
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
                'fresh_workspace_identity_recheck',
                'fresh_obra_identity_recheck',
                'fresh_provider_identity_recheck',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization disable contract template is ready as a provider-neutral Forge Workspace rollback contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization disable contract template is blocked until fresh authorization execution contract template is ready.',
        ];
    }
}
