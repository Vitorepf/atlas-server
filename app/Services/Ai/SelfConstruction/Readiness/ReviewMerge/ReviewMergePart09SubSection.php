<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 09 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate
 */
final class ReviewMergePart09SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate($options);
        $preflight = (array) data_get($preflightPayload, 'disable_execution_preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_ready';
    
        $requiredReceiptFields = [
            'new_cycle_disable_execution_preflight_hash',
            'disable_execution_subject',
            'disable_trigger',
            'disable_actor_identity',
            'disable_actor_provider',
            'provider_identity_snapshot_hash',
            'provider_identity_drift_review_hash',
            'writer_state_before_hash',
            'writer_state_after_expected_hash',
            'disable_path_verification_hash',
            'previous_authority_reuse_review_hash',
            'human_reviewer_identity',
        ];
    
        $disableExecutionReceiptDraft = [
            'disable_execution_receipt_draft_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-RECEIPT-DRAFT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
            'status' => $preflightReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_receipt_draft_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash' => data_get($preflightPayload, 'disable_execution_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_disable_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_health_decision_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_health_decision_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash'),
            'required_receipt_fields' => $requiredReceiptFields,
            'required_receipt_field_count' => count($requiredReceiptFields),
            'receipt_subject' => 'future_fresh_authorization_new_cycle_disable_execution',
            'required_receipt_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_request_hash',
                'disable_execution_actor_identity',
                'disable_execution_actor_provider',
                'selected_disable_trigger',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_provider_identity_drift_review_hash',
                'writer_state_before_hash',
                'writer_state_after_expected_hash',
                'fresh_authorization_new_cycle_disable_path_verification_hash',
                'fresh_authorization_new_cycle_previous_authority_reuse_review_hash',
                'human_reviewer_identity',
            ],
            'receipt_policy' => [
                'receipt_draft_requires_new_cycle_disable_execution_preflight_hash',
                'receipt_draft_requires_provider_identity_drift_review',
                'receipt_draft_must_be_unsigned',
                'receipt_draft_must_not_be_persisted',
                'receipt_draft_does_not_execute_disable',
                'receipt_draft_does_not_mutate_writer_state',
                'receipt_draft_does_not_write_ledger',
                'receipt_draft_does_not_persist_receipt',
                'receipt_draft_does_not_grant_approval',
            ],
            'future_receipt_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_evidence_hash',
                'writer_release_fresh_authorization_new_cycle_disable_post_execution_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_receipt_draft_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_receipt_draft' => $disableExecutionReceiptDraft,
            'disable_execution_receipt_draft_hash' => ReadinessHash::stable($disableExecutionReceiptDraft),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution receipt draft template is ready as a provider-neutral unsigned non-persisted receipt draft. It still does not execute disable, mutate writer state, create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution receipt draft template is blocked until fresh authorization new-cycle disable execution preflight template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate($options);
        $receiptDraft = (array) data_get($receiptPayload, 'disable_execution_receipt_draft', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template_ready';
    
        $requiredSigners = [
            'atlas_operator',
            'self_construction_governance_reviewer',
            'writer_release_safety_reviewer',
            'provider_identity_reviewer',
        ];
    
        $signedReceipt = [
            'disable_execution_signed_receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($receiptDraft, 'workspace'),
            'status' => $receiptReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_signed_receipt_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash' => data_get($receiptPayload, 'disable_execution_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_request_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_new_cycle_disable_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_health_decision_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_new_cycle_health_decision_hash'),
            'required_signers' => $requiredSigners,
            'required_signer_count' => count($requiredSigners),
            'required_signature_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash',
                'all_required_signer_identities',
                'all_required_signer_providers_or_roles',
                'signature_payload_hash',
                'receipt_draft_hash_match',
                'provider_identity_snapshot_hash',
                'provider_identity_drift_review_hash',
                'writer_state_before_hash',
                'writer_state_after_expected_hash',
                'human_reviewer_identity',
            ],
            'signature_policy' => [
                'signed_receipt_template_requires_receipt_draft_hash',
                'signed_receipt_template_requires_provider_identity_drift_review',
                'signed_receipt_template_requires_all_required_signers',
                'signed_receipt_template_must_reference_exact_draft_hash',
                'signed_receipt_template_does_not_accept_signature',
                'signed_receipt_template_does_not_validate_signature',
                'signed_receipt_template_does_not_persist_receipt',
                'signed_receipt_template_does_not_execute_disable',
            ],
            'future_signed_receipt_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_evidence_hash',
                'writer_release_fresh_authorization_new_cycle_disable_post_execution_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_signed_receipt_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_signed_receipt' => $signedReceipt,
            'disable_execution_signed_receipt_hash' => ReadinessHash::stable($signedReceipt),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution signed receipt template is ready as a provider-neutral non-validating signed receipt template. It still does not accept signatures, validate signatures, execute disable, mutate writer state, create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution signed receipt template is blocked until fresh authorization new-cycle disable execution receipt draft template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate(array $options = []): array
    {
        $signedReceiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate($options);
        $signedReceipt = (array) data_get($signedReceiptPayload, 'disable_execution_signed_receipt', []);
        $signedReceiptReady = data_get($signedReceiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template_ready';
    
        $requiredChecks = [
            'signed_receipt_template_hash_present',
            'source_receipt_draft_hash_present',
            'source_disable_execution_preflight_hash_present',
            'all_required_signers_declared',
            'all_required_signer_providers_or_roles_declared',
            'provider_identity_drift_review_present',
            'signature_payload_hash_declared',
            'ledger_target_is_append_only',
            'receipt_persistence_idempotency_key_declared',
            'writer_state_mutation_still_disabled',
            'merge_and_dispatch_still_disabled',
        ];
    
        $persistencePreflight = [
            'disable_execution_persistence_preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-PERSISTENCE-PREFLIGHT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signedReceipt, 'workspace'),
            'status' => $signedReceiptReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash' => data_get($signedReceiptPayload, 'disable_execution_signed_receipt_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash'),
            'required_checks' => $requiredChecks,
            'check_count' => count($requiredChecks),
            'required_persistence_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash',
                'signature_payload_hash',
                'all_required_signer_identities',
                'all_required_signer_providers_or_roles',
                'provider_identity_snapshot_hash',
                'provider_identity_drift_review_hash',
                'receipt_persistence_idempotency_key',
                'append_only_ledger_target_hash',
                'writer_state_snapshot_hash',
                'human_reviewer_identity',
            ],
            'persistence_policy' => [
                'persistence_preflight_requires_signed_receipt_template_hash',
                'persistence_preflight_requires_provider_identity_drift_review',
                'persistence_preflight_requires_append_only_ledger_target',
                'persistence_preflight_requires_idempotency_key',
                'persistence_preflight_does_not_write_ledger',
                'persistence_preflight_does_not_persist_receipt',
                'persistence_preflight_does_not_execute_disable',
                'persistence_preflight_does_not_mutate_writer_state',
            ],
            'future_persistence_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_append_only_ledger_event_hash',
                'writer_release_fresh_authorization_new_cycle_disable_post_execution_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template.v1',
            'status' => $signedReceiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_persistence_preflight' => $persistencePreflight,
            'disable_execution_persistence_preflight_hash' => ReadinessHash::stable($persistencePreflight),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_does_not_dispatch_work',
            ],
            'human_summary' => $signedReceiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution persistence preflight template is ready as a provider-neutral non-writing persistence preflight. It still does not write ledger, persist receipts, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution persistence preflight template is blocked until fresh authorization new-cycle disable execution signed receipt template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate($options);
        $preflight = (array) data_get($preflightPayload, 'disable_execution_persistence_preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template_ready';
    
        $requiredReceiptFields = [
            'disable_execution_persistence_preflight_hash',
            'signed_receipt_template_hash',
            'receipt_persistence_idempotency_key',
            'append_only_ledger_target_hash',
            'append_only_ledger_event_hash',
            'writer_state_snapshot_hash',
            'provider_identity_snapshot_hash',
            'provider_identity_drift_review_hash',
            'receipt_persistence_actor_identity',
            'receipt_persistence_actor_provider_or_role',
            'persistence_timestamp',
            'non_execution_statement',
            'non_mutation_statement',
        ];
    
        $persistenceReceipt = [
            'disable_execution_persistence_receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-PERSISTENCE-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
            'status' => $preflightReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash' => data_get($preflightPayload, 'disable_execution_persistence_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash'),
            'required_receipt_fields' => $requiredReceiptFields,
            'required_receipt_field_count' => count($requiredReceiptFields),
            'required_persistence_receipt_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash',
                'receipt_persistence_idempotency_key',
                'append_only_ledger_target_hash',
                'append_only_ledger_event_hash',
                'writer_state_snapshot_hash',
                'provider_identity_snapshot_hash',
                'provider_identity_drift_review_hash',
                'receipt_persistence_actor_identity',
                'receipt_persistence_actor_provider_or_role',
                'human_reviewer_identity',
            ],
            'persistence_receipt_policy' => [
                'persistence_receipt_requires_preflight_hash',
                'persistence_receipt_requires_signed_receipt_template_hash',
                'persistence_receipt_requires_provider_identity_drift_review',
                'persistence_receipt_requires_idempotency_key',
                'persistence_receipt_describes_future_ledger_event_only',
                'persistence_receipt_does_not_write_ledger',
                'persistence_receipt_does_not_persist_receipt',
                'persistence_receipt_does_not_execute_disable',
                'persistence_receipt_does_not_mutate_writer_state',
            ],
            'future_persistence_receipt_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_append_only_ledger_event_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_audit_hash',
                'writer_release_fresh_authorization_new_cycle_disable_post_execution_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_persistence_receipt' => $persistenceReceipt,
            'disable_execution_persistence_receipt_hash' => ReadinessHash::stable($persistenceReceipt),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution persistence receipt template is ready as a provider-neutral non-writing persistence receipt template. It still does not write ledger, persist receipts, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution persistence receipt template is blocked until fresh authorization new-cycle disable execution persistence preflight template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate($options);
        $persistenceReceipt = (array) data_get($receiptPayload, 'disable_execution_persistence_receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template_ready';
    
        $allowedReviewDecisions = [
            'keep_writer_disabled_after_new_cycle_disable_execution',
            'continue_disable_execution_observation',
            'request_disable_execution_evidence_repair',
            'escalate_disable_execution_persistence_anomaly',
            'request_later_fresh_authorization_cycle_after_disable',
            'require_provider_identity_drift_recheck',
        ];
    
        $postPersistenceReview = [
            'disable_execution_post_persistence_review_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-POST-PERSISTENCE-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($persistenceReceipt, 'workspace'),
            'status' => $receiptReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash' => data_get($receiptPayload, 'disable_execution_persistence_receipt_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash' => data_get($persistenceReceipt, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash' => data_get($persistenceReceipt, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash'),
            'allowed_review_decisions' => $allowedReviewDecisions,
            'allowed_review_decision_count' => count($allowedReviewDecisions),
            'required_review_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash',
                'append_only_ledger_event_hash',
                'receipt_persistence_idempotency_key',
                'writer_state_snapshot_hash',
                'provider_identity_snapshot_hash',
                'provider_identity_drift_review_hash',
                'post_persistence_integrity_check_hash',
                'disable_execution_observation_window_hash',
                'human_reviewer_identity',
            ],
            'review_policy' => [
                'review_requires_persistence_receipt_hash',
                'review_requires_append_only_ledger_event_hash',
                'review_requires_idempotency_key_match',
                'review_requires_provider_identity_drift_review',
                'review_requires_writer_state_still_disabled',
                'review_does_not_write_ledger',
                'review_does_not_persist_receipt',
                'review_does_not_execute_disable',
                'review_does_not_mutate_writer_state',
                'review_does_not_record_decision',
            ],
            'future_review_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_post_persistence_review_hash',
                'writer_release_fresh_authorization_new_cycle_disable_follow_up_observability_hash',
                'writer_release_fresh_authorization_new_cycle_disable_evidence_repair_request_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_post_persistence_review' => $postPersistenceReview,
            'disable_execution_post_persistence_review_hash' => ReadinessHash::stable($postPersistenceReview),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution post-persistence review template is ready as a provider-neutral non-writing review template. It still does not write ledger, persist receipts, record decisions, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution post-persistence review template is blocked until fresh authorization new-cycle disable execution persistence receipt template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate($options);
        $postPersistenceReview = (array) data_get($reviewPayload, 'disable_execution_post_persistence_review', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template_ready';
    
        $observationSignals = [
            'writer_state_still_disabled',
            'provider_identity_still_matches_reviewed_snapshot',
            'provider_identity_drift_absent_after_disable',
            'no_writer_files_created_after_disable',
            'no_dispatch_after_disable',
            'no_merge_after_disable',
            'no_receipt_persistence_after_template',
            'no_ledger_write_after_template',
            'no_signature_acceptance_after_template',
            'no_decision_recording_after_template',
        ];
    
        $followUpObservability = [
            'disable_execution_follow_up_observability_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-FOLLOW-UP-OBSERVABILITY-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($postPersistenceReview, 'workspace'),
            'status' => $reviewReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_hash' => data_get($reviewPayload, 'disable_execution_post_persistence_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash' => data_get($postPersistenceReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash' => data_get($postPersistenceReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash' => data_get($postPersistenceReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_signed_receipt_hash'),
            'observation_signals' => $observationSignals,
            'observation_signal_count' => count($observationSignals),
            'required_observation_evidence' => [
                'writer_state_snapshot_hash',
                'post_persistence_review_hash',
                'provider_identity_snapshot_hash',
                'provider_identity_drift_review_hash',
                'disable_execution_observation_window_hash',
                'no_dispatch_evidence_hash',
                'no_merge_evidence_hash',
                'no_writer_file_creation_evidence_hash',
                'no_ledger_write_evidence_hash',
                'human_reviewer_identity',
            ],
            'observability_policy' => [
                'observability_requires_post_persistence_review_hash',
                'observability_requires_bounded_observation_window',
                'observability_requires_writer_state_still_disabled',
                'observability_requires_provider_identity_snapshot_match',
                'observability_requires_provider_identity_drift_absent',
                'observability_requires_no_dispatch_evidence',
                'observability_does_not_write_ledger',
                'observability_does_not_persist_receipt',
                'observability_does_not_execute_disable',
                'observability_does_not_mutate_writer_state',
                'observability_does_not_record_decision',
            ],
            'future_observability_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_follow_up_observability_hash',
                'writer_release_fresh_authorization_new_cycle_disable_observation_window_report_hash',
                'writer_release_fresh_authorization_new_cycle_disable_provider_identity_drift_absence_report_hash',
                'writer_release_fresh_authorization_new_cycle_disable_evidence_repair_request_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_follow_up_observability' => $followUpObservability,
            'disable_execution_follow_up_observability_hash' => ReadinessHash::stable($followUpObservability),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution follow-up observability template is ready as a provider-neutral non-writing observability template. It still does not write ledger, persist receipts, record decisions, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution follow-up observability template is blocked until fresh authorization new-cycle disable execution post-persistence review template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate($options);
        $followUpObservability = (array) data_get($observabilityPayload, 'disable_execution_follow_up_observability', []);
        $observabilityReady = data_get($observabilityPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template_ready';
    
        $repairItems = [
            'missing_or_mismatched_writer_state_snapshot_hash',
            'missing_or_mismatched_provider_identity_snapshot_hash',
            'missing_or_mismatched_provider_identity_drift_review_hash',
            'missing_no_dispatch_evidence_hash',
            'missing_no_merge_evidence_hash',
            'missing_no_writer_file_creation_evidence_hash',
            'missing_no_ledger_write_evidence_hash',
            'missing_human_reviewer_identity',
        ];
    
        $evidenceRepairRequest = [
            'disable_execution_evidence_repair_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-EVIDENCE-REPAIR-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($followUpObservability, 'workspace'),
            'status' => $observabilityReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_hash' => data_get($observabilityPayload, 'disable_execution_follow_up_observability_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_hash' => data_get($followUpObservability, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash' => data_get($followUpObservability, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_persistence_receipt_hash'),
            'repair_items' => $repairItems,
            'repair_item_count' => count($repairItems),
            'required_repair_evidence' => [
                'failed_observation_signal',
                'missing_evidence_key',
                'expected_evidence_hash',
                'replacement_evidence_hash',
                'replacement_provider_identity_snapshot_hash',
                'replacement_provider_identity_drift_review_hash',
                'repair_reason',
                'repair_actor_identity',
                'repair_actor_provider',
                'human_reviewer_identity',
            ],
            'repair_policy' => [
                'repair_request_requires_follow_up_observability_hash',
                'repair_request_requires_failed_observation_signal',
                'repair_request_requires_missing_evidence_key',
                'repair_request_requires_replacement_evidence_hash',
                'repair_request_requires_provider_identity_snapshot_when_provider_evidence_failed',
                'repair_request_requires_provider_identity_drift_review_when_provider_evidence_failed',
                'repair_request_does_not_write_ledger',
                'repair_request_does_not_persist_receipt',
                'repair_request_does_not_execute_disable',
                'repair_request_does_not_mutate_writer_state',
                'repair_request_does_not_record_decision',
            ],
            'future_repair_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_evidence_repair_request_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repaired_evidence_packet_hash',
                'writer_release_fresh_authorization_new_cycle_disable_provider_identity_repair_packet_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repair_review_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template.v1',
            'status' => $observabilityReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_evidence_repair_request' => $evidenceRepairRequest,
            'disable_execution_evidence_repair_request_hash' => ReadinessHash::stable($evidenceRepairRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $observabilityReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution evidence repair request template is ready as a provider-neutral non-writing repair request template. It still does not write ledger, persist receipts, record decisions, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution evidence repair request template is blocked until fresh authorization new-cycle disable execution follow-up observability template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate(array $options = []): array
    {
        $repairRequestPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate($options);
        $repairRequest = (array) data_get($repairRequestPayload, 'disable_execution_evidence_repair_request', []);
        $repairRequestReady = data_get($repairRequestPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template_ready';
    
        $requiredPacketFields = [
            'evidence_repair_request_hash',
            'failed_observation_signal',
            'missing_evidence_key',
            'original_expected_evidence_hash',
            'replacement_evidence_hash',
            'replacement_evidence_source_hash',
            'replacement_provider_identity_snapshot_hash',
            'replacement_provider_identity_drift_review_hash',
            'repair_actor_identity',
            'repair_actor_provider',
            'repair_timestamp',
            'non_execution_statement',
            'non_mutation_statement',
        ];
    
        $repairedEvidencePacket = [
            'disable_execution_repaired_evidence_packet_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-REPAIRED-EVIDENCE-PACKET-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($repairRequest, 'workspace'),
            'status' => $repairRequestReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_hash' => data_get($repairRequestPayload, 'disable_execution_evidence_repair_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_hash' => data_get($repairRequest, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_hash' => data_get($repairRequest, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_post_persistence_review_hash'),
            'required_packet_fields' => $requiredPacketFields,
            'required_packet_field_count' => count($requiredPacketFields),
            'required_packet_evidence' => [
                'evidence_repair_request_hash',
                'failed_observation_signal',
                'missing_evidence_key',
                'replacement_evidence_hash',
                'replacement_evidence_source_hash',
                'replacement_provider_identity_snapshot_hash',
                'replacement_provider_identity_drift_review_hash',
                'repair_actor_identity',
                'repair_actor_provider',
                'human_reviewer_identity',
            ],
            'packet_policy' => [
                'repaired_packet_requires_repair_request_hash',
                'repaired_packet_requires_failed_observation_signal',
                'repaired_packet_requires_replacement_evidence_hash',
                'repaired_packet_requires_replacement_source_hash',
                'repaired_packet_requires_provider_identity_snapshot_when_provider_evidence_failed',
                'repaired_packet_requires_provider_identity_drift_review_when_provider_evidence_failed',
                'repaired_packet_does_not_write_ledger',
                'repaired_packet_does_not_persist_receipt',
                'repaired_packet_does_not_execute_disable',
                'repaired_packet_does_not_mutate_writer_state',
                'repaired_packet_does_not_record_decision',
            ],
            'future_packet_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_repaired_evidence_packet_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repaired_evidence_integrity_hash',
                'writer_release_fresh_authorization_new_cycle_disable_provider_identity_repair_integrity_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repair_review_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template.v1',
            'status' => $repairRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_repaired_evidence_packet' => $repairedEvidencePacket,
            'disable_execution_repaired_evidence_packet_hash' => ReadinessHash::stable($repairedEvidencePacket),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_does_not_dispatch_work',
            ],
            'human_summary' => $repairRequestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repaired evidence packet template is ready as a provider-neutral non-writing repaired evidence packet template. It still does not write ledger, persist receipts, record decisions, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repaired evidence packet template is blocked until fresh authorization new-cycle disable execution evidence repair request template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate(array $options = []): array
    {
        $packetPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate($options);
        $repairedPacket = (array) data_get($packetPayload, 'disable_execution_repaired_evidence_packet', []);
        $packetReady = data_get($packetPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template_ready';
    
        $allowedOutcomes = [
            'repair_evidence_accepted_for_later_cycle_request',
            'repair_evidence_requires_additional_packet',
            'repair_evidence_rejected_due_to_integrity_gap',
            'repair_evidence_rejected_due_to_provider_identity_gap',
            'repair_evidence_escalated_to_human_review',
            'repair_evidence_observation_window_extended',
        ];
    
        $repairReview = [
            'disable_execution_repair_review_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-REPAIR-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($repairedPacket, 'workspace'),
            'status' => $packetReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_repair_review_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_hash' => data_get($packetPayload, 'disable_execution_repaired_evidence_packet_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_hash' => data_get($repairedPacket, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_hash' => data_get($repairedPacket, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_follow_up_observability_hash'),
            'allowed_repair_review_outcomes' => $allowedOutcomes,
            'allowed_repair_review_outcome_count' => count($allowedOutcomes),
            'required_review_evidence' => [
                'repaired_evidence_packet_hash',
                'repaired_evidence_integrity_hash',
                'provider_identity_repair_integrity_hash',
                'repair_request_hash',
                'replacement_evidence_hash',
                'replacement_evidence_source_hash',
                'replacement_provider_identity_snapshot_hash',
                'replacement_provider_identity_drift_review_hash',
                'repair_actor_provider',
                'reviewer_identity',
                'reviewer_provider',
                'human_reviewer_identity',
            ],
            'repair_review_policy' => [
                'repair_review_requires_repaired_packet_hash',
                'repair_review_requires_integrity_hash',
                'repair_review_requires_provider_identity_repair_integrity_hash',
                'repair_review_requires_repair_request_hash',
                'repair_review_requires_replacement_source_hash',
                'repair_review_requires_provider_identity_snapshot_when_provider_evidence_failed',
                'repair_review_requires_provider_identity_drift_review_when_provider_evidence_failed',
                'repair_review_does_not_write_ledger',
                'repair_review_does_not_persist_receipt',
                'repair_review_does_not_execute_disable',
                'repair_review_does_not_mutate_writer_state',
                'repair_review_does_not_record_decision',
            ],
            'future_repair_review_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_repair_review_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repair_integrity_review_hash',
                'writer_release_fresh_authorization_new_cycle_disable_provider_identity_repair_review_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repair_outcome_packet_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_repair_review_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
            ],
            'execution_allowed' => false,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template.v1',
            'status' => $packetReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'disable_execution_repair_review' => $repairReview,
            'disable_execution_repair_review_hash' => ReadinessHash::stable($repairReview),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $packetReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repair review template is ready as a provider-neutral non-writing repair review template. It still does not write ledger, persist receipts, record decisions, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repair review template is blocked until fresh authorization new-cycle disable execution repaired evidence packet template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate($options);
        $repairReview = (array) data_get($reviewPayload, 'disable_execution_repair_review', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template_ready';
    
        $requiredOutcomeFields = [
            'repair_review_hash',
            'selected_repair_review_outcome',
            'outcome_rationale',
            'repaired_evidence_packet_hash',
            'repaired_evidence_integrity_hash',
            'provider_identity_repair_review_hash',
            'later_cycle_readiness_signal',
            'outcome_actor_identity',
            'outcome_actor_provider',
            'outcome_timestamp',
            'non_execution_statement',
            'non_authorization_statement',
        ];
    
        $repairOutcomePacket = [
            'disable_execution_repair_outcome_packet_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-REPAIR-OUTCOME-PACKET-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($repairReview, 'workspace'),
            'status' => $reviewReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_repair_review_hash' => data_get($reviewPayload, 'disable_execution_repair_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_hash' => data_get($repairReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_repaired_evidence_packet_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_hash' => data_get($repairReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_evidence_repair_request_hash'),
            'required_outcome_fields' => $requiredOutcomeFields,
            'required_outcome_field_count' => count($requiredOutcomeFields),
            'allowed_outcome_values' => [
                'repair_evidence_accepted_for_later_cycle_request',
                'repair_evidence_requires_additional_packet',
                'repair_evidence_rejected_due_to_integrity_gap',
                'repair_evidence_rejected_due_to_provider_identity_gap',
                'repair_evidence_escalated_to_human_review',
                'repair_evidence_observation_window_extended',
            ],
            'required_outcome_evidence' => [
                'repair_review_hash',
                'selected_repair_review_outcome',
                'outcome_rationale',
                'repaired_evidence_integrity_hash',
                'provider_identity_repair_review_hash',
                'outcome_actor_provider',
                'human_reviewer_identity',
            ],
            'outcome_policy' => [
                'repair_outcome_requires_repair_review_hash',
                'repair_outcome_requires_allowed_outcome_value',
                'repair_outcome_requires_integrity_hash',
                'repair_outcome_requires_provider_identity_repair_review_hash',
                'repair_outcome_does_not_authorize_later_cycle',
                'repair_outcome_does_not_write_ledger',
                'repair_outcome_does_not_persist_receipt',
                'repair_outcome_does_not_execute_disable',
                'repair_outcome_does_not_mutate_writer_state',
                'repair_outcome_does_not_record_decision',
            ],
            'future_outcome_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_repair_outcome_packet_hash',
                'writer_release_fresh_authorization_new_cycle_disable_repair_outcome_integrity_hash',
                'writer_release_fresh_authorization_new_cycle_disable_provider_identity_repair_outcome_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
                'writer_release_fresh_authorization_later_cycle_preflight_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
                'later_cycle_authorization_by_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
            ],
            'execution_allowed' => false,
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
            'later_cycle_authorized' => false,
        ];
    
        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'later_cycle_authorized' => false,
            'disable_execution_repair_outcome_packet' => $repairOutcomePacket,
            'disable_execution_repair_outcome_packet_hash' => ReadinessHash::stable($repairOutcomePacket),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_authorize_later_cycle',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_repair_outcome_packet_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repair outcome packet template is ready as a provider-neutral non-writing repair outcome packet template. It still does not write ledger, persist receipts, record decisions, authorize a later cycle, execute disable, mutate writer state, create a writer, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution repair outcome packet template is blocked until fresh authorization new-cycle disable execution repair review template is ready.',
        ];
    }
}
