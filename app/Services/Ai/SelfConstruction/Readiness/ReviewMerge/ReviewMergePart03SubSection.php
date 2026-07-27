<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 03 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionPreflight
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook
 */
final class ReviewMergePart03SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionPreflight(array $options = []): array
    {
        $executionReceiptPayload = $this->parent->agentReviewMergeExecutionReceiptTemplate($options);
        $preflightReady = data_get($executionReceiptPayload, 'status') === 'merge_execution_receipt_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id',
                'obra_id',
                'post_execution_diff_hash',
                'post_execution_gate_report_hash',
                'human_post_execution_confirmation_hash',
                'merge_candidate_hash',
            ],
            'required_preflight_checks' => [
                'execution_receipt_template_ready',
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
                'missing_workspace_id',
                'missing_obra_id',
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
                'merge_action_revalidates_workspace_identity',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_preflight.v1',
            'status' => $preflightReady ? 'merge_post_execution_preflight_ready' : 'merge_post_execution_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_preflight',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'execution_receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_preflight_does_not_accept_execution_receipt',
                'agent_review_merge_post_execution_preflight_does_not_persist_execution_receipt',
                'agent_review_merge_post_execution_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_preflight_does_not_merge',
                'agent_review_merge_post_execution_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution preflight is ready as a read-only Forge Workspace merge prerequisite contract. It still does not accept execution receipt evidence, approve code or merge.'
                : 'Agent review merge post-execution preflight is blocked until execution receipt template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_post_execution_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id',
                'obra_id',
                'post_execution_gate_report_hash',
                'merge_candidate_hash',
                'human_post_execution_confirmation_hash',
                'merge_operator_identity',
            ],
            'required_action_validations' => [
                'post_execution_preflight_ready',
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_template_ready' : 'merge_post_execution_action_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_template_does_not_accept_merge_authority',
                'agent_review_merge_post_execution_action_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_template_does_not_merge',
                'agent_review_merge_post_execution_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge post-execution action template is ready as a read-only Forge Workspace future merge action contract. It still does not approve code, merge or dispatch work.'
                : 'Agent review merge post-execution action template is blocked until post-execution preflight is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionReceiptDraft(array $options = []): array
    {
        $actionTemplatePayload = $this->parent->agentReviewMergePostExecutionActionTemplate($options);
        $receiptReady = data_get($actionTemplatePayload, 'status') === 'merge_post_execution_action_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id',
                'obra_id',
                'merge_candidate_hash',
                'persisted_execution_receipt_hash',
                'post_execution_gate_report_hash',
                'human_post_execution_confirmation_hash',
                'merge_operator_identity',
            ],
            'required_signable_payload_fields' => [
                'receipt_id',
                'workspace_id',
                'obra_id',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_receipt_draft.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_receipt_draft_ready' : 'merge_post_execution_action_receipt_draft_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_receipt_draft',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_receipt_draft_does_not_claim_packets',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_complete_packets',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_accept_signature',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_validate_signature',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_approve_code',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_merge',
                'agent_review_merge_post_execution_action_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action receipt draft is ready as an unsigned, non-authorizing Forge Workspace receipt. It still does not accept signatures, approve code, merge or dispatch work.'
                : 'Agent review merge post-execution action receipt draft is blocked until post-execution action template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $requestReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_receipt_draft_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $signablePayload = [
            'signature_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
            'request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signature_request.v1',
            'status' => $requestReady ? 'merge_post_execution_action_signature_request_pending' : 'merge_post_execution_action_signature_request_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signature_request',
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
                'agent_review_merge_post_execution_action_signature_request_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signature_request_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signature_request_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signature_request_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signature_request_does_not_approve_code',
                'agent_review_merge_post_execution_action_signature_request_does_not_merge',
                'agent_review_merge_post_execution_action_signature_request_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Agent review merge post-execution action signature request is pending as a signable Forge Workspace receipt payload. It still does not accept, validate, approve, persist or merge.'
                : 'Agent review merge post-execution action signature request is blocked until action receipt draft is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->agentReviewMergePostExecutionActionSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signature_request_pending';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id',
                'obra_id',
                'provider_name',
                'external_final_merge_action_signature_value',
                'external_final_merge_action_signature_validator_identity',
                'external_final_merge_action_signature_validation_timestamp',
                'signed_final_merge_action_receipt_persistence_event_hash',
            ],
            'ordered_steps' => [
                'collect_external_final_merge_action_signature_evidence',
                'verify_workspace_identity_matches_forge_workspace',
                'verify_signature_request_hash_matches_signable_payload',
                'verify_action_receipt_hash_matches_signed_payload',
                'verify_required_authority_inputs_are_present',
                'verify_required_action_validations_are_present',
                'prepare_signed_action_receipt_persistence_candidate',
                'stop_before_signature_acceptance_or_merge',
            ],
            'future_validator_must_check' => [
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
                'provider_name_present',
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
            'step_count' => 8,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_post_execution_action_post_signature_runbook_ready' : 'merge_post_execution_action_post_signature_runbook_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_post_signature_runbook',
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
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_claim_packets',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_complete_packets',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_accept_signature',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_validate_signature',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_record_decision',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_approve_code',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_merge',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge post-execution action post-signature runbook is ready as a read-only Forge Workspace evidence sequence. It still does not accept, validate, approve, persist or merge.'
                : 'Agent review merge post-execution action post-signature runbook is blocked until action signature request is pending.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionPostSignatureRunbook($options);
        $templateReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_post_signature_runbook_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $templateReady ? 'waiting_for_external_signed_action_receipt_evidence' : 'blocked_before_post_execution_action_post_signature_runbook',
            'source_action_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_action_signature_request_hash' => data_get($runbookPayload, 'runbook.source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($runbookPayload, 'runbook.source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($runbookPayload, 'runbook.source_action_receipt_hash'),
            'required_external_evidence_for_future_signed_receipt' => [
                'workspace_id',
                'obra_id',
                'provider_name',
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
                'workspace_id',
                'obra_id',
                'provider_name',
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
                'workspace_identity_matches_forge_workspace',
                'obra_identity_matches_self_construction_os',
                'provider_name_present',
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
                'merge_surface_reruns_workspace_identity_check',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_template_ready' : 'merge_post_execution_action_signed_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge post-execution action signed receipt template is ready as a non-persisting Forge Workspace contract. It still does not accept, validate, approve, persist or merge.'
                : 'Agent review merge post-execution action signed receipt template is blocked until action post-signature runbook is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $preflightReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $blockingConditions = [
            'missing_workspace_id',
            'missing_obra_id',
            'missing_provider_name',
            'missing_external_final_merge_action_signature_value',
            'missing_signature_validator_identity',
            'missing_signature_validation_timestamp',
            'missing_validated_action_signable_payload_hash',
            'missing_validated_action_receipt_hash',
            'missing_validated_selected_decision',
            'missing_validated_required_authority_inputs',
            'missing_validated_required_action_validations',
            'missing_signed_action_receipt_persistence_event_hash',
            'workspace_identity_mismatch',
            'obra_identity_mismatch',
            'selected_decision_is_not_merge',
            'action_receipt_hash_mismatch',
            'action_signable_payload_hash_mismatch',
            'hot_scope_drift_since_action_signature_request',
            'unreviewed_diff_since_action_signature_request',
        ];

        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_preflight.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_preflight_ready' : 'merge_post_execution_action_signed_receipt_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt preflight is ready as a read-only Forge Workspace persistence prerequisite check. It still does not accept, validate, approve, persist or merge.'
                : 'Agent review merge post-execution action signed receipt preflight is blocked until signed action receipt template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $templateReady ? 'waiting_for_future_append_only_persistence_surface' : 'blocked_before_signed_action_receipt_preflight',
            'source_signed_action_receipt_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_signed_action_receipt_template_hash' => data_get($preflight, 'source_signed_action_receipt_template_hash'),
            'source_action_signature_request_hash' => data_get($preflight, 'source_action_signature_request_hash'),
            'source_action_signable_payload_hash' => data_get($preflight, 'source_action_signable_payload_hash'),
            'source_action_receipt_hash' => data_get($preflight, 'source_action_receipt_hash'),
            'future_append_only_event_type' => 'AGENT_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED',
            'future_append_only_event_fields' => [
                'event_id',
                'event_type',
                'workspace_id',
                'obra_id',
                'provider_name',
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
                'workspace_identity_matches_forge_workspace',
                'obra_identity_matches_self_construction_os',
                'provider_name_present',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_template.v1',
            'status' => $templateReady ? 'merge_post_execution_action_signed_receipt_persistence_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge post-execution action signed receipt persistence template is ready as a non-writing Forge Workspace contract. It still does not accept, validate, write, persist, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence template is blocked until signed receipt preflight is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $receiptReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id',
                'obra_id',
                'provider_name',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready' : 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence receipt draft is ready as an unsigned, non-writing Forge Workspace receipt. It still does not accept signatures, validate, write ledger, persist, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence receipt draft is blocked until the persistence template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptDraftReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $blockingConditions = $receiptDraftReady ? [
            'workspace_identity_missing',
            'obra_identity_missing',
            'provider_name_missing',
            'workspace_identity_mismatch',
            'obra_identity_mismatch',
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
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_identity_verified',
                'obra_identity_verified',
                'provider_name_present',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_preflight.v1',
            'status' => $receiptDraftReady ? 'merge_post_execution_action_signed_receipt_persistence_preflight_ready' : 'merge_post_execution_action_signed_receipt_persistence_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_preflight',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $receiptDraftReady
                ? 'Agent review merge post-execution action signed receipt persistence preflight is ready as a provider-neutral Forge Workspace blocker report. It still does not write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence preflight is blocked until the persistence receipt draft is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $steps = [
            [
                'id' => 'step_01_reconfirm_workspace_and_preflight_hash',
                'action' => 'Recompute and compare the persistence preflight hash and Forge Workspace identity before any future persistence attempt.',
                'required_evidence' => ['preflight_hash_match_report', 'workspace_identity_match_report', 'obra_identity_match_report'],
            ],
            [
                'id' => 'step_02_collect_provider_persistence_evidence',
                'action' => 'Collect provider name, actor identity, timestamp, signed receipt hash, append-only event hash and ledger sequence number.',
                'required_evidence' => ['provider_name', 'persistence_actor_identity', 'persistence_timestamp', 'signed_action_receipt_hash', 'append_only_event_hash', 'ledger_sequence_number'],
            ],
            [
                'id' => 'step_03_verify_source_hashes',
                'action' => 'Verify every source hash still matches the provider-neutral Forge Workspace receipt draft chain.',
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
                'action' => 'Prepare the future provider-neutral append-only event payload without writing it.',
                'required_evidence' => ['future_append_only_event_payload_hash'],
            ],
        ];

        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-POST-PREFLIGHT-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_identity_verified',
                'obra_identity_verified',
                'provider_name_present',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook.v1',
            'status' => $preflightReady ? 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_ready' : 'merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_post_preflight_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence post-preflight runbook is ready as a provider-neutral Forge Workspace non-writing sequence. It still does not write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence post-preflight runbook is blocked until persistence preflight is ready.',
        ];
    }
}
