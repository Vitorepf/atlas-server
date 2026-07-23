<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 04 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight
 */
final class ReviewMergePart04SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready';
    
        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];
    
        $payload = [
            'payload_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-APPEND-ONLY-EVENT-PAYLOAD-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $runbookReady ? 'waiting_for_future_writer_surface_authorization' : 'blocked_before_signed_action_receipt_persistence_post_preflight_runbook',
            'event_type' => data_get($runbook, 'future_append_only_event_type'),
            'source_persistence_post_preflight_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_persistence_preflight_hash' => data_get($runbook, 'source_persistence_preflight_hash'),
            'source_persistence_receipt_draft_hash' => data_get($runbook, 'source_persistence_receipt_draft_hash'),
            'required_payload_fields' => [
                'event_id',
                'event_type',
                'workspace_id',
                'obra_id',
                'provider_name',
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
                'workspace_id' => $workspace['workspace_id'],
                'obra_id' => $workspace['obra_id'],
                'provider_name' => null,
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
                'workspace_identity_verified',
                'obra_identity_verified',
                'provider_name_present',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge post-execution action signed receipt persistence append-only event payload template is ready as a provider-neutral Forge Workspace non-writing payload contract. It still does not write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence append-only event payload template is blocked until post-preflight runbook is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight(array $options = []): array
    {
        $payloadTemplate = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options);
        $payload = (array) data_get($payloadTemplate, 'payload', []);
        $payloadReady = data_get($payloadTemplate, 'status') === 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready';
    
        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];
    
        $blockingConditions = $payloadReady ? [
            'future_writer_surface_not_implemented',
            'future_writer_surface_not_separately_authorized',
            'workspace_identity_missing',
            'obra_identity_missing',
            'provider_name_missing',
            'workspace_identity_mismatch',
            'obra_identity_mismatch',
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
            'writer_preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_identity_enforcement',
                'obra_identity_enforcement',
                'provider_identity_enforcement',
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
                'workspace_identity_verified',
                'obra_identity_verified',
                'provider_name_present',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight.v1',
            'status' => $payloadReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $payloadReady
                ? 'Agent review merge post-execution action signed receipt persistence writer preflight is ready as a provider-neutral Forge Workspace blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer preflight is blocked until the append-only event payload template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options);
        $writerPreflight = (array) data_get($preflightPayload, 'writer_preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready';
    
        $capabilities = data_get($writerPreflight, 'writer_contract_required_capabilities', []);
        $contract = [
            'contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($writerPreflight, 'workspace'),
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
                'workspace_identity_enforced',
                'obra_identity_enforced',
                'provider_identity_enforced',
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
                'workspace_identity_override',
                'obra_identity_override',
                'provider_identity_override',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer contract template is ready as a provider-neutral Forge Workspace non-writing contract. It still does not implement a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer contract template is blocked until writer preflight is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_contract_template_ready';
    
        $blockingConditions = $contractReady ? [
            'writer_implementation_absent',
            'writer_contract_hash_not_bound_to_implementation',
            'writer_capability_tests_absent',
            'append_only_write_guard_absent',
            'workspace_identity_enforcement_test_absent',
            'obra_identity_enforcement_test_absent',
            'provider_identity_enforcement_test_absent',
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
            'implementation_preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-IMPLEMENTATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($contract, 'workspace'),
            'status' => $contractReady ? 'waiting_for_future_writer_implementation_patch' : 'blocked_before_persistence_writer_contract_template',
            'source_writer_contract_template_hash' => data_get($contractPayload, 'contract_hash'),
            'source_writer_preflight_hash' => data_get($contract, 'source_writer_preflight_hash'),
            'event_type' => data_get($contract, 'event_type'),
            'required_implementation_files' => [
                'future:app/Services/Ai/SelfConstruction/AgentReviewMergePostExecutionActionSignedReceiptPersistenceWriter.php',
                'future:tests/Unit/Ai/SelfConstruction/AgentReviewMergePostExecutionActionSignedReceiptPersistenceWriterTest.php',
            ],
            'required_implementation_tests' => [
                'writer_rejects_null_payload_fields',
                'writer_recomputes_payload_hash',
                'writer_enforces_workspace_identity',
                'writer_enforces_obra_identity',
                'writer_enforces_provider_identity',
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
                'workspace_identity_verified',
                'obra_identity_verified',
                'provider_identity_verified',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight.v1',
            'status' => $contractReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $contractReady
                ? 'Agent review merge post-execution action signed receipt persistence writer implementation preflight is ready as a provider-neutral Forge Workspace blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer implementation preflight is blocked until writer contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate(array $options = []): array
    {
        $implementationPreflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options);
        $implementationPreflight = (array) data_get($implementationPreflightPayload, 'implementation_preflight', []);
        $implementationPreflightReady = data_get($implementationPreflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_implementation_preflight_ready';
    
        $requiredEvidence = [
            'writer_implementation_patch_hash',
            'writer_contract_template_hash',
            'writer_implementation_preflight_hash',
            'writer_capability_test_output_hash',
            'append_only_guard_test_output_hash',
            'workspace_identity_enforcement_test_output_hash',
            'obra_identity_enforcement_test_output_hash',
            'provider_identity_enforcement_test_output_hash',
            'merge_authority_absence_test_output_hash',
            'dispatch_authority_absence_test_output_hash',
            'hot_scope_recheck_output_hash',
            'human_writer_release_confirmation_hash',
        ];
    
        $authorization = [
            'authorization_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($implementationPreflight, 'workspace'),
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
                'workspace_identity_enforcement_passes',
                'obra_identity_enforcement_passes',
                'provider_identity_enforcement_passes',
                'merge_authority_absent',
                'dispatch_authority_absent',
                'identity_override_absent',
                'hot_scope_clean_at_release_time',
                'human_writer_release_confirmation_present',
            ],
            'future_authorized_writer_scope' => [
                'may_validate_non_null_payload_fields',
                'may_recompute_payload_hash',
                'may_enforce_workspace_identity',
                'may_enforce_obra_identity',
                'may_enforce_provider_identity',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template.v1',
            'status' => $implementationPreflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_does_not_dispatch_work',
            ],
            'human_summary' => $implementationPreflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization template is ready as a provider-neutral Forge Workspace human authorization contract. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization template is blocked until writer implementation preflight is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight(array $options = []): array
    {
        $authorizationPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options);
        $authorization = (array) data_get($authorizationPayload, 'authorization', []);
        $authorizationTemplateReady = data_get($authorizationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_template_ready';
    
        $blockingConditions = $authorizationTemplateReady ? [
            'missing_writer_implementation_patch_hash',
            'missing_writer_contract_template_hash',
            'missing_writer_implementation_preflight_hash',
            'missing_writer_capability_test_output_hash',
            'missing_append_only_guard_test_output_hash',
            'missing_workspace_identity_enforcement_test_output_hash',
            'missing_obra_identity_enforcement_test_output_hash',
            'missing_provider_identity_enforcement_test_output_hash',
            'missing_merge_authority_absence_test_output_hash',
            'missing_dispatch_authority_absence_test_output_hash',
            'missing_hot_scope_recheck_output_hash',
            'missing_human_writer_release_confirmation_hash',
            'writer_patch_not_reviewed_by_principal_integrator',
            'writer_contract_hash_not_verified_against_patch',
            'identity_override_absence_not_verified',
            'writer_release_not_separately_authorized',
        ] : [
            'writer_release_authorization_template_not_ready',
        ];
    
        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($authorization, 'workspace'),
            'status' => $authorizationTemplateReady ? 'waiting_for_external_writer_release_evidence' : 'blocked_before_writer_release_authorization_template',
            'source_writer_release_authorization_template_hash' => data_get($authorizationPayload, 'authorization_hash'),
            'source_writer_implementation_preflight_hash' => data_get($authorization, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($authorization, 'source_writer_contract_template_hash'),
            'source_writer_preflight_hash' => data_get($authorization, 'source_writer_preflight_hash'),
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight.v1',
            'status' => $authorizationTemplateReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $authorizationTemplateReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization preflight is ready as a provider-neutral Forge Workspace blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization preflight is blocked until writer release authorization template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_preflight_ready';
    
        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
            'status' => $preflightReady ? 'waiting_for_external_writer_release_evidence' : 'blocked_before_writer_release_authorization_preflight',
            'source_writer_release_authorization_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_writer_release_authorization_template_hash' => data_get($preflight, 'source_writer_release_authorization_template_hash'),
            'source_writer_implementation_preflight_hash' => data_get($preflight, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($preflight, 'source_writer_contract_template_hash'),
            'source_writer_preflight_hash' => data_get($preflight, 'source_writer_preflight_hash'),
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
                'workspace_identity_enforcement_test_output_hash',
                'obra_identity_enforcement_test_output_hash',
                'provider_identity_enforcement_test_output_hash',
                'human_writer_release_confirmation_hash',
                'principal_integrator_identity',
            ],
            'signable_payload_fields' => [
                'receipt_id',
                'workspace',
                'source_writer_release_authorization_preflight_hash',
                'source_writer_release_authorization_template_hash',
                'source_writer_implementation_preflight_hash',
                'source_writer_contract_template_hash',
                'source_writer_preflight_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization receipt draft is ready as an unsigned provider-neutral Forge Workspace receipt. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization receipt draft is blocked until writer release authorization preflight is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_receipt_draft_ready';
    
        $signablePayload = [
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'workspace' => data_get($receipt, 'workspace'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'source_writer_release_authorization_preflight_hash' => data_get($receipt, 'source_writer_release_authorization_preflight_hash'),
            'source_writer_release_authorization_template_hash' => data_get($receipt, 'source_writer_release_authorization_template_hash'),
            'source_writer_implementation_preflight_hash' => data_get($receipt, 'source_writer_implementation_preflight_hash'),
            'source_writer_contract_template_hash' => data_get($receipt, 'source_writer_contract_template_hash'),
            'source_writer_preflight_hash' => data_get($receipt, 'source_writer_preflight_hash'),
            'required_external_evidence' => data_get($receipt, 'required_external_evidence', []),
            'required_authorization_checks' => data_get($receipt, 'required_authorization_checks', []),
            'blocking_conditions' => data_get($receipt, 'blocking_conditions', []),
        ];
    
        $signatureRequest = [
            'signature_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($receipt, 'workspace'),
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
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization signature request is ready as a provider-neutral Forge Workspace signable payload. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization signature request is blocked until writer release authorization receipt draft is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signatureRequestReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signature_request_ready';
    
        $steps = [
            'collect_external_writer_release_signature_evidence',
            'verify_signature_request_hash_matches_signable_payload',
            'verify_writer_release_authorization_receipt_hash_matches_signed_payload',
            'verify_writer_release_signable_payload_hash_matches_signature_request',
            'verify_workspace_identity_hash_matches_forge_workspace',
            'verify_obra_identity_hash_matches_self_construction_obra',
            'verify_provider_identity_hash_matches_declared_agent',
            'verify_required_writer_release_authorization_evidence_is_present',
            'verify_hot_scope_clean_before_release_template',
            'prepare_signed_writer_release_authorization_receipt_template_candidate',
            'stop_before_signature_acceptance_writer_creation_or_ledger_write',
        ];
    
        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signatureRequest, 'workspace'),
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
                'validated_workspace_identity_hash_matches_source',
                'validated_obra_identity_hash_matches_source',
                'validated_provider_identity_hash_matches_source',
                'selected_decision_explicitly_authorizes_or_requests_more_evidence',
                'hot_scope_still_clean',
                'writer_patch_still_matches_contract_hash',
            ],
            'future_signed_receipt_template_inputs' => [
                'validated_writer_release_authorization_signature_hash',
                'validated_writer_release_authorization_receipt_hash',
                'validated_writer_release_signable_payload_hash',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook.v1',
            'status' => $signatureRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $signatureRequestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization post-signature runbook is ready as a provider-neutral Forge Workspace evidence sequence. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization post-signature runbook is blocked until writer release authorization signature request is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_post_signature_runbook_ready';
    
        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-AUTHORIZATION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($runbook, 'workspace'),
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
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_writer_release_authorization_receipt_id',
                'workspace',
                'source_writer_release_authorization_receipt_hash',
                'source_writer_release_authorization_signable_payload_hash',
                'source_writer_release_authorization_post_signature_runbook_hash',
                'validated_writer_release_authorization_signature_hash',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
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
                'workspace_identity_still_matches_forge_workspace',
                'obra_identity_still_matches_self_construction_obra',
                'provider_identity_still_matches_declared_agent',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release authorization signed receipt template is ready as a provider-neutral Forge Workspace signed receipt candidate. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release authorization signed receipt template is blocked until writer release authorization post-signature runbook is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_authorization_signed_receipt_template_ready';
    
        $blockingConditions = $templateReady
            ? [
                'missing_external_signed_writer_release_authorization_evidence',
                'selected_decision_not_authorize_writer_release',
                'workspace_identity_recheck_missing',
                'obra_identity_recheck_missing',
                'provider_identity_recheck_missing',
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
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($template, 'workspace'),
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
                'workspace_identity_still_matches_forge_workspace',
                'obra_identity_still_matches_self_construction_obra',
                'provider_identity_still_matches_declared_agent',
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
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
                'writer_release_provider_identity_evidence_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release preflight is ready as a provider-neutral Forge Workspace blocker report. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release preflight is blocked until writer release authorization signed receipt template is ready.',
        ];
    }
}
