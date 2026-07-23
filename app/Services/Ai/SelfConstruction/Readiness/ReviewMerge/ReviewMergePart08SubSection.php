<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 08 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate
 */
final class ReviewMergePart08SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_ready';
    
        $templateFields = [
            'new_cycle_signed_receipt_id',
            'receipt_type',
            'workspace_identity_hash',
            'obra_identity_hash',
            'provider_identity_hash',
            'source_new_cycle_post_signature_runbook_hash',
            'source_new_cycle_signature_request_hash',
            'source_new_cycle_receipt_draft_hash',
            'source_new_cycle_authorization_request_hash',
            'source_new_cycle_request_hash',
            'external_signature_bundle_hash',
            'required_signer_manifest_hash',
            'no_previous_cycle_authority_reuse_evidence_hash',
            'authorization_scope',
            'expiration_policy',
        ];
    
        $template = [
            'signed_receipt_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($runbook, 'workspace'),
            'status' => $runbookReady ? 'ready_as_future_fresh_authorization_new_cycle_signed_receipt_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($runbook, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'template_fields' => $templateFields,
            'template_field_count' => count($templateFields),
            'required_external_evidence' => [
                'external_signature_bundle_hash',
                'required_signer_manifest_hash',
                'signature_payload_integrity_hash',
                'writer_release_fresh_authorization_new_cycle_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_hash',
                'no_previous_cycle_authority_reuse_evidence_hash',
                'new_cycle_post_signature_runbook_hash',
                'human_reviewer_identity',
            ],
            'receipt_scope' => [
                'fresh_authorization_new_cycle_only',
                'forge_workspace_only',
                'obra_atlas_self_construction_os_only',
                'provider_neutral_agent_receipt_only',
                'does_not_reuse_previous_authorization',
                'does_not_reenable_writer',
                'does_not_create_writer_file',
                'does_not_write_ledger',
                'does_not_persist_receipt',
                'does_not_merge',
                'does_not_dispatch',
            ],
            'future_template_outputs' => [
                'writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_signature_rejection_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_signed_receipt_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
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
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle signed receipt template is ready as a provider-neutral non-persisting template. It still does not accept or validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle signed receipt template is blocked until fresh authorization new-cycle post-signature runbook template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate(array $options = []): array
    {
        $signedReceiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate($options);
        $signedReceipt = (array) data_get($signedReceiptPayload, 'template', []);
        $signedReceiptReady = data_get($signedReceiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signed_receipt_template_ready';
    
        $blockingConditions = [
            'fresh_authorization_new_cycle_signed_receipt_template_not_ready',
            'external_new_cycle_signature_evidence_missing',
            'external_new_cycle_signature_not_validated_by_external_system',
            'new_cycle_workspace_identity_recheck_missing',
            'new_cycle_obra_identity_recheck_missing',
            'new_cycle_provider_identity_recheck_missing',
            'new_cycle_authority_reuse_check_missing',
            'new_cycle_hot_scope_recheck_missing',
            'new_cycle_security_review_missing',
            'new_cycle_execution_contract_chain_missing',
            'new_cycle_disable_path_missing',
            'new_cycle_rollback_plan_missing',
            'new_cycle_monitoring_plan_missing',
            'human_new_cycle_execution_authorization_missing',
        ];
    
        $requiredInputs = [
            'fresh_authorization_new_cycle_signed_receipt_template_hash',
            'fresh_authorization_new_cycle_workspace_identity_hash',
            'fresh_authorization_new_cycle_obra_identity_hash',
            'fresh_authorization_new_cycle_provider_identity_hash',
            'new_cycle_signature_validation_evidence_hash',
            'new_cycle_authority_reuse_check_hash',
            'new_cycle_hot_scope_recheck_hash',
            'new_cycle_security_review_hash',
            'new_cycle_execution_contract_chain_hash',
            'new_cycle_disable_path_hash',
            'new_cycle_rollback_plan_hash',
            'new_cycle_monitoring_plan_hash',
            'human_new_cycle_execution_authorization_hash',
        ];
    
        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-EXECUTION-CONTRACT-PREFLIGHT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signedReceipt, 'workspace'),
            'status' => $signedReceiptReady ? 'ready_as_future_fresh_authorization_new_cycle_execution_contract_preflight_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_signed_receipt_template',
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($signedReceiptPayload, 'template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($signedReceipt, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'blocking_condition_count' => count($blockingConditions),
            'blocking_conditions' => $blockingConditions,
            'required_input_count' => count($requiredInputs),
            'required_inputs' => $requiredInputs,
            'future_preflight_outputs' => [
                'writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_template_hash',
                'writer_release_fresh_authorization_new_cycle_preflight_rejection_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_execution_contract_preflight_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template.v1',
            'status' => $signedReceiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
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
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_does_not_dispatch_work',
            ],
            'human_summary' => $signedReceiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle execution contract preflight template is ready as a provider-neutral non-executing Forge Workspace preflight. It still does not accept or validate signatures, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle execution contract preflight template is blocked until fresh authorization new-cycle signed receipt template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_ready';
    
        $blockingConditions = $preflightReady
            ? [
                'fresh_authorization_new_cycle_execution_still_not_authorized',
                'writer_release_reenable_still_not_authorized',
                'validated_new_cycle_external_signature_evidence_not_bound_to_contract',
                'human_new_cycle_execution_authorization_not_attached',
                'new_cycle_authority_reuse_check_not_attached',
                'new_cycle_rollback_plan_not_rechecked',
                'new_cycle_disable_path_not_rechecked',
                'new_cycle_monitoring_plan_not_rechecked',
                'new_cycle_hot_scope_recheck_not_attached',
                'new_cycle_security_review_not_attached',
                'new_cycle_post_execution_receipt_plan_not_attached',
            ]
            : [
                'writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template_not_ready',
            ];
    
        $contract = [
            'contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-EXECUTION-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
            'status' => $preflightReady ? 'blocked_waiting_for_external_fresh_authorization_new_cycle_execution_authority' : 'blocked_before_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_template',
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($preflight, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'blocking_conditions' => $blockingConditions,
            'blocking_count' => count($blockingConditions),
            'execution_scope' => [
                'allowed_scope' => 'future_provider_neutral_writer_reenable_only_after_external_fresh_authorization_new_cycle',
                'forbidden_scope' => [
                    'merge_execution',
                    'dispatch_execution',
                    'receipt_persistence_execution',
                    'policy_mutation',
                    'hot_scope_mutation',
                    'previous_authorization_reuse',
                    'unscoped_file_creation',
                    'self_authorized_reenable',
                    'provider_specific_authority_bypass',
                ],
                'writer_contract_expected_capability' => 'fresh_authorization_new_cycle_to_reenable_signed_receipt_persistence_writer_only',
                'writer_contract_forbidden_capabilities' => [
                    'merge_authority',
                    'dispatch_authority',
                    'signature_validation_authority',
                    'approval_authority',
                    'previous_cycle_authority',
                    'self_release_authority',
                    'self_reenable_authority',
                    'provider_specific_authority',
                ],
            ],
            'required_actor_evidence' => [
                'fresh_authorization_new_cycle_executor_identity',
                'fresh_authorization_new_cycle_executor_provider',
                'fresh_authorization_new_cycle_executor_session',
                'fresh_authorization_new_cycle_execution_reason',
                'fresh_authorization_new_cycle_execution_scope_hash',
                'fresh_authorization_new_cycle_execution_contract_reviewer_identity',
            ],
            'required_recheck_evidence' => [
                'fresh_authorization_new_cycle_contract_hash_rechecked_against_patch',
                'new_cycle_workspace_identity_recheck_hash',
                'new_cycle_obra_identity_recheck_hash',
                'new_cycle_provider_identity_recheck_hash',
                'new_cycle_authority_reuse_check_hash',
                'new_cycle_hot_scope_clean_recheck_hash',
                'new_cycle_writer_capability_test_output_hash',
                'new_cycle_writer_no_merge_authority_evidence_hash',
                'new_cycle_writer_no_dispatch_authority_evidence_hash',
                'new_cycle_disable_path_evidence_hash',
                'new_cycle_rollback_plan_hash',
                'new_cycle_monitoring_plan_hash',
            ],
            'future_post_execution_outputs' => [
                'writer_release_fresh_authorization_new_cycle_execution_contract_hash',
                'writer_release_fresh_authorization_new_cycle_disable_contract_hash',
                'writer_release_fresh_authorization_new_cycle_observability_contract_hash',
                'writer_release_fresh_authorization_new_cycle_post_execution_receipt_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_execution_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_execution_contract_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_execution_contract_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template',
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
            'contract' => $contract,
            'contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle execution contract template is ready as a provider-neutral non-authorizing Forge Workspace contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle execution contract template is blocked until fresh authorization new-cycle execution contract preflight template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate(array $options = []): array
    {
        $contractPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_execution_contract_template_ready';
    
        $disableTriggers = [
            'new_cycle_contract_hash_drift_detected',
            'new_cycle_hot_scope_dirty_after_reenable',
            'new_cycle_writer_capability_tests_failed_after_reenable',
            'new_cycle_writer_merge_authority_detected',
            'new_cycle_writer_dispatch_authority_detected',
            'previous_cycle_authority_reuse_detected',
            'provider_identity_drift_detected_after_new_cycle_authorization',
            'unexpected_signature_validation_attempt_after_new_cycle_authorization',
            'unexpected_receipt_persistence_attempt_after_new_cycle_authorization',
            'unexpected_ledger_write_attempt_after_new_cycle_authorization',
            'operator_revocation_requested_after_new_cycle_authorization',
            'new_cycle_rollback_plan_missing_or_invalid',
        ];
    
        $disableContract = [
            'disable_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($contract, 'workspace'),
            'status' => $contractReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_execution_contract_template',
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash' => data_get($contractPayload, 'contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($contract, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'disable_triggers' => $disableTriggers,
            'trigger_count' => count($disableTriggers),
            'required_disable_steps' => [
                'stop_fresh_authorization_new_cycle_writer_reenable_runtime',
                'revoke_fresh_authorization_new_cycle_writer_capability_flag',
                'quarantine_fresh_authorization_new_cycle_outputs',
                'rerun_new_cycle_provider_identity_check',
                'rerun_new_cycle_authority_reuse_check',
                'rerun_new_cycle_writer_no_merge_authority_check',
                'rerun_new_cycle_writer_no_dispatch_authority_check',
                'capture_new_cycle_disable_reason_and_actor',
                'generate_future_fresh_authorization_new_cycle_post_disable_receipt',
                'require_human_review_before_any_later_cycle_reenable',
            ],
            'required_disable_evidence' => [
                'new_cycle_disable_actor_identity',
                'new_cycle_disable_actor_provider',
                'new_cycle_disable_reason',
                'new_cycle_disable_trigger_id',
                'new_cycle_runtime_stop_evidence_hash',
                'new_cycle_capability_revocation_evidence_hash',
                'new_cycle_quarantine_manifest_hash',
                'new_cycle_post_disable_provider_identity_recheck_hash',
                'new_cycle_post_disable_no_previous_authority_reuse_hash',
                'new_cycle_post_disable_no_merge_authority_evidence_hash',
                'new_cycle_post_disable_no_dispatch_authority_evidence_hash',
            ],
            'future_disable_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_contract_hash',
                'writer_release_fresh_authorization_new_cycle_disable_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_revocation_event_hash',
                'writer_release_fresh_authorization_next_cycle_review_packet_hash',
            ],
            'reenable_requirements' => [
                'later_cycle_requires_new_fresh_authorization_request_template',
                'later_cycle_requires_new_authorization_request_template',
                'later_cycle_requires_new_receipt_draft_template',
                'later_cycle_requires_new_signature_request_template',
                'later_cycle_requires_new_signed_receipt_template',
                'later_cycle_requires_new_execution_contract_preflight_template',
                'later_cycle_requires_new_execution_contract_template',
                'later_cycle_requires_new_disable_contract_template',
                'later_cycle_requires_fresh_provider_identity_recheck',
                'later_cycle_requires_human_authorization',
                'later_cycle_requires_hot_scope_recheck',
                'later_cycle_requires_writer_capability_tests',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_contract_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_contract_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template',
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
            'disable_contract' => $disableContract,
            'disable_contract_hash' => ReadinessHash::stable($disableContract),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable contract template is ready as a provider-neutral rollback contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable contract template is blocked until fresh authorization new-cycle execution contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate(array $options = []): array
    {
        $disablePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate($options);
        $disableContract = (array) data_get($disablePayload, 'disable_contract', []);
        $disableReady = data_get($disablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_contract_template_ready';
    
        $signals = [
            'fresh_authorization_new_cycle_execution_contract_loaded',
            'fresh_authorization_new_cycle_writer_reenable_runtime_started',
            'fresh_authorization_new_cycle_writer_capability_flag_checked',
            'fresh_authorization_new_cycle_provider_identity_recheck_completed',
            'fresh_authorization_new_cycle_previous_authority_reuse_check_completed',
            'fresh_authorization_new_cycle_receipt_persistence_attempted',
            'fresh_authorization_new_cycle_ledger_write_attempted',
            'fresh_authorization_new_cycle_forbidden_merge_attempt_detected',
            'fresh_authorization_new_cycle_forbidden_dispatch_attempt_detected',
            'fresh_authorization_new_cycle_previous_authority_reuse_attempt_detected',
            'fresh_authorization_new_cycle_provider_identity_drift_detected',
            'fresh_authorization_new_cycle_disable_trigger_detected',
            'fresh_authorization_new_cycle_disable_completed',
            'fresh_authorization_new_cycle_later_cycle_requested',
            'fresh_authorization_new_cycle_human_review_required',
        ];
    
        $observabilityContract = [
            'observability_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-OBSERVABILITY-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($disableContract, 'workspace'),
            'status' => $disableReady ? 'ready_as_future_fresh_authorization_new_cycle_observability_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_contract_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($disablePayload, 'disable_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($disableContract, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'required_signals' => $signals,
            'signal_count' => count($signals),
            'required_metrics' => [
                'fresh_authorization_new_cycle_reenable_attempt_count',
                'fresh_authorization_new_cycle_reenable_success_count',
                'fresh_authorization_new_cycle_reenable_blocked_count',
                'fresh_authorization_new_cycle_provider_identity_drift_count',
                'fresh_authorization_new_cycle_disable_trigger_count',
                'fresh_authorization_new_cycle_forbidden_merge_attempt_count',
                'fresh_authorization_new_cycle_forbidden_dispatch_attempt_count',
                'fresh_authorization_new_cycle_previous_authority_reuse_attempt_count',
                'fresh_authorization_new_cycle_unexpected_ledger_write_attempt_count',
                'fresh_authorization_new_cycle_unexpected_receipt_persistence_attempt_count',
                'fresh_authorization_new_cycle_time_to_disable_ms',
            ],
            'required_alerts' => [
                'alert_on_new_cycle_contract_hash_drift',
                'alert_on_new_cycle_hot_scope_dirty_after_reenable',
                'alert_on_new_cycle_writer_capability_test_failure',
                'alert_on_new_cycle_provider_identity_drift',
                'alert_on_new_cycle_forbidden_merge_authority',
                'alert_on_new_cycle_forbidden_dispatch_authority',
                'alert_on_new_cycle_previous_authority_reuse',
                'alert_on_new_cycle_unexpected_signature_validation',
                'alert_on_new_cycle_unexpected_receipt_persistence',
                'alert_on_new_cycle_unexpected_ledger_write',
            ],
            'required_observability_evidence' => [
                'trace_id',
                'operation_id',
                'fresh_authorization_new_cycle_executor_provider',
                'fresh_authorization_new_cycle_execution_contract_hash',
                'fresh_authorization_new_cycle_disable_contract_hash',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_signal_manifest_hash',
                'fresh_authorization_new_cycle_metrics_snapshot_hash',
                'fresh_authorization_new_cycle_alert_policy_hash',
                'fresh_authorization_new_cycle_post_release_monitoring_window',
            ],
            'minimum_monitoring_window' => [
                'after_future_reenable_minutes' => 60,
                'after_future_disable_minutes' => 30,
                'requires_human_review_before_window_close' => true,
                'requires_provider_identity_drift_review' => true,
                'requires_previous_authority_reuse_review' => true,
            ],
            'future_observability_outputs' => [
                'writer_release_fresh_authorization_new_cycle_observability_contract_hash',
                'writer_release_fresh_authorization_new_cycle_signal_manifest_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'writer_release_fresh_authorization_new_cycle_metrics_snapshot_hash',
                'writer_release_fresh_authorization_new_cycle_alert_policy_hash',
                'writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_observability_contract_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_observability_contract_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_observability_contract_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template.v1',
            'status' => $disableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template',
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
            'observability_contract' => $observabilityContract,
            'observability_contract_hash' => ReadinessHash::stable($observabilityContract),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $disableReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle observability contract template is ready as a provider-neutral non-executing monitoring contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle observability contract template is blocked until fresh authorization new-cycle disable contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate($options);
        $observabilityContract = (array) data_get($observabilityPayload, 'observability_contract', []);
        $observabilityReady = data_get($observabilityPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_observability_contract_template_ready';
    
        $allowedDecisions = [
            'keep_fresh_authorization_new_cycle_writer_disabled',
            'keep_fresh_authorization_new_cycle_writer_enabled_under_watch',
            'request_fresh_authorization_new_cycle_disable_execution',
            'request_later_fresh_authorization_cycle',
            'escalate_to_human_review',
        ];
    
        $reviewTemplate = [
            'review_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-POST-MONITORING-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($observabilityContract, 'workspace'),
            'status' => $observabilityReady ? 'ready_as_future_fresh_authorization_new_cycle_post_monitoring_review_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_observability_contract_template',
            'source_writer_release_fresh_authorization_new_cycle_observability_contract_hash' => data_get($observabilityPayload, 'observability_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'allowed_decisions' => $allowedDecisions,
            'allowed_decision_count' => count($allowedDecisions),
            'required_review_inputs' => [
                'writer_release_fresh_authorization_new_cycle_observability_contract_hash',
                'writer_release_fresh_authorization_new_cycle_signal_manifest_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'writer_release_fresh_authorization_new_cycle_metrics_snapshot_hash',
                'writer_release_fresh_authorization_new_cycle_alert_policy_hash',
                'fresh_authorization_new_cycle_post_release_monitoring_window',
                'fresh_authorization_new_cycle_provider_identity_drift_review',
                'fresh_authorization_new_cycle_previous_authority_reuse_review',
                'human_reviewer_identity',
            ],
            'health_checks' => [
                'fresh_authorization_new_cycle_monitoring_window_completed',
                'all_fresh_authorization_new_cycle_required_signals_present',
                'fresh_authorization_new_cycle_provider_identity_snapshot_present',
                'fresh_authorization_new_cycle_metrics_snapshot_present',
                'fresh_authorization_new_cycle_alert_policy_present',
                'no_fresh_authorization_new_cycle_provider_identity_drift',
                'no_fresh_authorization_new_cycle_forbidden_merge_attempts',
                'no_fresh_authorization_new_cycle_forbidden_dispatch_attempts',
                'no_fresh_authorization_new_cycle_previous_authority_reuse_attempts',
                'no_fresh_authorization_new_cycle_unexpected_ledger_writes',
                'no_fresh_authorization_new_cycle_unexpected_receipt_persistence',
                'fresh_authorization_new_cycle_disable_path_still_available',
                'human_review_completed',
            ],
            'failure_to_decision_map' => [
                'fresh_authorization_new_cycle_provider_identity_drift' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_forbidden_merge_attempt' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_forbidden_dispatch_attempt' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_previous_authority_reuse_attempt' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_unexpected_ledger_write_attempt' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_unexpected_receipt_persistence_attempt' => 'request_fresh_authorization_new_cycle_disable_execution',
                'fresh_authorization_new_cycle_missing_required_signal' => 'escalate_to_human_review',
                'fresh_authorization_new_cycle_monitoring_window_incomplete' => 'keep_fresh_authorization_new_cycle_writer_enabled_under_watch',
                'fresh_authorization_new_cycle_disable_path_unavailable' => 'escalate_to_human_review',
            ],
            'required_review_evidence' => [
                'fresh_authorization_new_cycle_review_actor_identity',
                'fresh_authorization_new_cycle_review_actor_provider',
                'fresh_authorization_new_cycle_reviewed_at',
                'selected_decision',
                'decision_rationale',
                'fresh_authorization_new_cycle_health_check_result_hash',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_provider_identity_drift_review_hash',
                'fresh_authorization_new_cycle_metrics_snapshot_hash',
                'fresh_authorization_new_cycle_alert_summary_hash',
                'fresh_authorization_new_cycle_previous_authority_reuse_review_hash',
                'fresh_authorization_new_cycle_disable_path_verification_hash',
            ],
            'future_review_outputs' => [
                'writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash',
                'writer_release_fresh_authorization_new_cycle_health_decision_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
                'writer_release_fresh_authorization_new_cycle_disable_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_post_monitoring_review_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template.v1',
            'status' => $observabilityReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
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
            'review_template' => $reviewTemplate,
            'review_template_hash' => ReadinessHash::stable($reviewTemplate),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $observabilityReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle post-monitoring review template is ready as a provider-neutral non-authorizing health review. It still does not create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle post-monitoring review template is blocked until fresh authorization new-cycle observability contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate($options);
        $reviewTemplate = (array) data_get($reviewPayload, 'review_template', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template_ready';
    
        $allowedDecisionStates = [
            'keep_fresh_authorization_new_cycle_writer_disabled',
            'continue_fresh_authorization_new_cycle_watch',
            'request_fresh_authorization_new_cycle_disable_execution',
            'request_later_fresh_authorization_cycle',
            'escalate_to_human_review',
        ];
    
        $healthDecision = [
            'health_decision_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-HEALTH-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($reviewTemplate, 'workspace'),
            'status' => $reviewReady ? 'ready_as_future_fresh_authorization_new_cycle_health_decision_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_post_monitoring_review_template',
            'source_writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash' => data_get($reviewPayload, 'review_template_hash'),
            'source_writer_release_fresh_authorization_new_cycle_observability_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_new_cycle_observability_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash'),
            'allowed_decision_states' => $allowedDecisionStates,
            'allowed_decision_state_count' => count($allowedDecisionStates),
            'required_decision_evidence' => [
                'writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash',
                'selected_review_decision',
                'decision_actor_identity',
                'decision_actor_provider',
                'decision_rationale',
                'fresh_authorization_new_cycle_health_check_result_hash',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_provider_identity_drift_review_hash',
                'fresh_authorization_new_cycle_metrics_snapshot_hash',
                'fresh_authorization_new_cycle_alert_summary_hash',
                'fresh_authorization_new_cycle_previous_authority_reuse_review_hash',
                'fresh_authorization_new_cycle_disable_path_verification_hash',
                'human_reviewer_identity',
            ],
            'decision_policy' => [
                'selected_decision_must_be_allowed_for_new_cycle',
                'provider_identity_drift_forces_disable_request',
                'provider_neutral_decision_actor_required',
                'previous_authority_reuse_attempt_forces_disable_request',
                'forbidden_merge_or_dispatch_attempt_forces_disable_request',
                'unexpected_ledger_or_receipt_persistence_forces_disable_request',
                'missing_signal_or_incomplete_window_forces_watch_or_escalation',
                'later_fresh_authorization_cycle_requires_full_chain_restart',
                'human_reviewer_identity_required',
            ],
            'future_decision_outputs' => [
                'writer_release_fresh_authorization_new_cycle_health_decision_hash',
                'writer_release_fresh_authorization_later_cycle_request_hash',
                'writer_release_fresh_authorization_new_cycle_disable_request_hash',
                'writer_release_fresh_authorization_new_cycle_human_escalation_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_health_decision_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_health_decision_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_health_decision_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template',
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
            'health_decision' => $healthDecision,
            'health_decision_hash' => ReadinessHash::stable($healthDecision),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle health decision template is ready as a provider-neutral non-recording decision template. It still does not create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle health decision template is blocked until fresh authorization new-cycle post-monitoring review template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate(array $options = []): array
    {
        $decisionPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate($options);
        $healthDecision = (array) data_get($decisionPayload, 'health_decision', []);
        $decisionReady = data_get($decisionPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_health_decision_template_ready';
    
        $disableTriggers = [
            'selected_decision_request_fresh_authorization_new_cycle_disable_execution',
            'fresh_authorization_new_cycle_provider_identity_drift_after_reenable',
            'fresh_authorization_new_cycle_previous_authority_reuse_after_reenable',
            'fresh_authorization_new_cycle_forbidden_merge_attempt_after_reenable',
            'fresh_authorization_new_cycle_forbidden_dispatch_attempt_after_reenable',
            'fresh_authorization_new_cycle_unexpected_ledger_write_after_reenable',
            'fresh_authorization_new_cycle_unexpected_receipt_persistence_after_reenable',
            'fresh_authorization_new_cycle_disable_path_compromised_or_unavailable',
        ];
    
        $disableRequest = [
            'disable_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($healthDecision, 'workspace'),
            'status' => $decisionReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_request_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_health_decision_template',
            'source_writer_release_fresh_authorization_new_cycle_health_decision_hash' => data_get($decisionPayload, 'health_decision_hash'),
            'source_writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_new_cycle_post_monitoring_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_observability_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_new_cycle_observability_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash'),
            'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_new_cycle_execution_contract_hash'),
            'disable_triggers' => $disableTriggers,
            'trigger_count' => count($disableTriggers),
            'required_disable_request_evidence' => [
                'writer_release_fresh_authorization_new_cycle_health_decision_hash',
                'selected_health_decision_state',
                'disable_trigger',
                'disable_request_actor_identity',
                'disable_request_actor_provider',
                'disable_request_rationale',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_provider_identity_drift_review_hash',
                'fresh_authorization_new_cycle_previous_authority_reuse_review_hash',
                'fresh_authorization_new_cycle_disable_path_verification_hash',
                'fresh_authorization_new_cycle_forbidden_action_evidence_hash',
                'human_reviewer_identity',
            ],
            'disable_request_policy' => [
                'disable_request_requires_new_cycle_health_decision_hash',
                'disable_request_requires_allowed_new_cycle_trigger',
                'disable_request_requires_provider_neutral_actor_identity',
                'disable_request_requires_provider_identity_drift_review',
                'disable_request_must_reference_existing_new_cycle_disable_contract_template',
                'disable_request_does_not_execute_disable',
                'disable_request_does_not_mutate_writer_state',
                'disable_request_does_not_record_decision',
                'disable_request_requires_previous_authority_reuse_review',
            ],
            'future_disable_request_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_request_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_receipt_hash',
                'writer_release_fresh_authorization_new_cycle_disable_evidence_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_request_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_request_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template.v1',
            'status' => $decisionReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template',
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
            'disable_request' => $disableRequest,
            'disable_request_hash' => ReadinessHash::stable($disableRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $decisionReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable request template is ready as a provider-neutral non-executing disable request. It still does not execute disable, mutate writer state, create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable request template is blocked until fresh authorization new-cycle health decision template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate(array $options = []): array
    {
        $requestPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate($options);
        $disableRequest = (array) data_get($requestPayload, 'disable_request', []);
        $requestReady = data_get($requestPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_request_template_ready';
    
        $requiredChecks = [
            'new_cycle_disable_request_hash_present',
            'selected_disable_trigger_allowed',
            'provider_identity_drift_review_present',
            'previous_authority_reuse_review_present',
            'disable_path_verification_present',
            'forbidden_action_evidence_present_when_applicable',
            'human_reviewer_identity_present',
            'execution_surface_still_disabled',
            'writer_state_mutation_still_disabled',
            'ledger_and_receipt_persistence_still_disabled',
        ];
    
        $disableExecutionPreflight = [
            'disable_execution_preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-PREFLIGHT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($disableRequest, 'workspace'),
            'status' => $requestReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_preflight_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_request_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_request_hash' => data_get($requestPayload, 'disable_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_health_decision_hash' => data_get($disableRequest, 'source_writer_release_fresh_authorization_new_cycle_health_decision_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash' => data_get($disableRequest, 'source_writer_release_fresh_authorization_new_cycle_disable_contract_hash'),
            'required_checks' => $requiredChecks,
            'check_count' => count($requiredChecks),
            'required_preflight_evidence' => [
                'writer_release_fresh_authorization_new_cycle_disable_request_hash',
                'disable_request_actor_identity',
                'disable_request_actor_provider',
                'selected_disable_trigger',
                'fresh_authorization_new_cycle_provider_identity_snapshot_hash',
                'fresh_authorization_new_cycle_provider_identity_drift_review_hash',
                'fresh_authorization_new_cycle_previous_authority_reuse_review_hash',
                'fresh_authorization_new_cycle_disable_path_verification_hash',
                'fresh_authorization_new_cycle_forbidden_action_evidence_hash',
                'writer_state_snapshot_hash',
                'human_reviewer_identity',
            ],
            'preflight_policy' => [
                'preflight_requires_new_cycle_disable_request_hash',
                'preflight_requires_existing_new_cycle_disable_contract_hash',
                'preflight_requires_provider_identity_drift_review',
                'preflight_requires_all_checks_green_before_future_execution',
                'preflight_does_not_execute_disable',
                'preflight_does_not_mutate_writer_state',
                'preflight_does_not_write_ledger',
                'preflight_does_not_persist_receipt',
            ],
            'future_preflight_outputs' => [
                'writer_release_fresh_authorization_new_cycle_disable_execution_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_disable_execution_evidence_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_preflight_template' => [
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template.v1',
            'status' => $requestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template',
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
            'disable_execution_preflight' => $disableExecutionPreflight,
            'disable_execution_preflight_hash' => ReadinessHash::stable($disableExecutionPreflight),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_preflight_template_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution preflight template is ready as a provider-neutral non-executing preflight. It still does not execute disable, mutate writer state, create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution preflight template is blocked until fresh authorization new-cycle disable request template is ready.',
        ];
    }
}
