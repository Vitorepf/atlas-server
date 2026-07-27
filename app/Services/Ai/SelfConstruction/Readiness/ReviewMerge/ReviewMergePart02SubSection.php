<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 02 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergeAuthorizingActionTemplate
 *           .. agentReviewMergeExecutionReceiptTemplate
 */
final class ReviewMergePart02SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizingActionTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergeFinalAuthorizationPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_final_authorization_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-AUTHORIZING-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $templateReady ? 'waiting_for_external_authorizing_action_evidence' : 'blocked_before_final_authorization_preflight',
            'source_final_authorization_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_authorization_post_signature_runbook_hash' => data_get($preflightPayload, 'preflight.source_authorization_post_signature_runbook_hash'),
            'source_authorization_signature_request_hash' => data_get($preflightPayload, 'preflight.source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($preflightPayload, 'preflight.source_authorization_signable_payload_hash'),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'required_inputs_for_future_authorizing_action' => [
                'external_authorization_signature_value',
                'external_authorization_signature_validator_identity',
                'external_authorization_signature_validation_timestamp',
                'validated_authorization_signable_payload_hash',
                'validated_authorization_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_merge_preflight_hash',
                'fresh_test_output_hash',
                'fresh_docs_health_output_hash',
                'fresh_architecture_validate_output_hash',
                'fresh_diff_check_output_hash',
                'workspace_artifact_integrity_statement',
                'scope_integrity_statement',
                'hot_scope_exclusion_statement',
                'completed_packet_evidence_integrity_statement',
                'rollback_plan',
                'human_final_merge_confirmation',
            ],
            'required_action_validations' => [
                'selected_decision_must_equal_merge',
                'signature_must_validate_against_source_authorization_signable_payload_hash',
                'authorization_receipt_hash_must_match_source_chain',
                'fresh_merge_preflight_hash_must_be_bound',
                'fresh_tests_must_pass',
                'docs_health_must_pass',
                'architecture_validate_must_pass',
                'diff_check_must_pass',
                'workspace_artifact_integrity_must_pass',
                'scope_must_exclude_hot_voice_and_kernel_files',
                'packet_evidence_must_match_completed_packet_hashes',
                'rollback_plan_must_be_present',
                'human_final_merge_confirmation_must_be_explicit',
            ],
            'receipt_fields_to_persist_in_future' => [
                'authorizing_action_id',
                'workspace_id',
                'obra_id',
                'source_final_authorization_preflight_hash',
                'selected_decision',
                'decision_rationale',
                'validated_signature_hash',
                'validated_authorization_receipt_hash',
                'fresh_gate_hashes',
                'workspace_artifact_integrity_result',
                'scope_integrity_result',
                'evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'authorized_by',
                'authorized_at',
            ],
            'future_execution_boundary' => [
                'authorizing_action_may_record_decision_after_validation',
                'authorizing_action_may_emit_final_merge_receipt_after_validation',
                'authorizing_action_must_reference_obras_shared_workspace',
                'authorizing_action_must_not_apply_patch_or_merge_directly',
                'separate_executor_must_consume_final_merge_receipt',
                'executor_must_rerun_last_minute_diff_and_scope_checks',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_authorizing_action_template',
                'signature_validation_by_authorizing_action_template',
                'decision_recording_by_authorizing_action_template',
                'approval_from_authorizing_action_template',
                'merge_from_authorizing_action_template',
                'dispatch_from_authorizing_action_template',
            ],
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_authorizing_action_template.v1',
            'status' => $templateReady ? 'merge_authorizing_action_template_ready' : 'merge_authorizing_action_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_authorizing_action_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_valid' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_merge_authorizing_action_template_does_not_claim_packets',
                'agent_review_merge_authorizing_action_template_does_not_complete_packets',
                'agent_review_merge_authorizing_action_template_does_not_accept_signature',
                'agent_review_merge_authorizing_action_template_does_not_validate_signature',
                'agent_review_merge_authorizing_action_template_does_not_record_decision',
                'agent_review_merge_authorizing_action_template_does_not_approve_code',
                'agent_review_merge_authorizing_action_template_does_not_merge',
                'agent_review_merge_authorizing_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge authorizing action template is ready as a non-authorizing Forge Workspace contract for a future separate action. It still does not accept evidence, validate, approve or merge.'
                : 'Agent review merge authorizing action template is blocked until final authorization preflight is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergeAuthorizingActionTemplate($options);
        $templateReady = data_get($templatePayload, 'status') === 'merge_authorizing_action_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-MERGE-FINAL-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $templateReady ? 'waiting_for_external_authorizing_action_receipt_evidence' : 'blocked_before_authorizing_action_template',
            'source_authorizing_action_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_final_authorization_preflight_hash' => data_get($templatePayload, 'template.source_final_authorization_preflight_hash'),
            'source_authorization_signature_request_hash' => data_get($templatePayload, 'template.source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($templatePayload, 'template.source_authorization_signable_payload_hash'),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'drafted_authorization_fields' => [
                'authorizing_action_id',
                'workspace_id',
                'obra_id',
                'selected_decision',
                'decision_rationale',
                'validated_signature_hash',
                'validated_authorization_receipt_hash',
                'fresh_gate_hashes',
                'workspace_artifact_integrity_result',
                'scope_integrity_result',
                'evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'authorized_by',
                'authorized_at',
            ],
            'required_before_final_receipt_can_be_signed' => [
                'external_authorization_signature_validated',
                'selected_decision_equals_merge',
                'source_hashes_match',
                'fresh_gate_hashes_present',
                'workspace_artifact_integrity_passed',
                'scope_integrity_passed',
                'hot_scope_exclusion_passed',
                'packet_evidence_integrity_passed',
                'rollback_plan_hash_present',
                'human_confirmation_hash_present',
            ],
            'future_executor_contract' => [
                'executor_must_consume_signed_final_merge_receipt',
                'executor_must_verify_final_receipt_hash',
                'executor_must_reference_obras_shared_workspace',
                'executor_must_rerun_last_minute_diff_check',
                'executor_must_rerun_hot_scope_check',
                'executor_must_emit_execution_evidence',
                'executor_must_not_run_without_signed_final_receipt',
            ],
            'still_forbidden_by_receipt_draft' => [
                'signature_acceptance_by_final_receipt_draft',
                'signature_validation_by_final_receipt_draft',
                'decision_recording_by_final_receipt_draft',
                'approval_from_final_receipt_draft',
                'merge_from_final_receipt_draft',
                'dispatch_from_final_receipt_draft',
            ],
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_final_receipt_draft.v1',
            'status' => $templateReady ? 'merge_final_receipt_draft_ready' : 'merge_final_receipt_draft_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_final_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'agent_review_merge_final_receipt_draft_does_not_claim_packets',
                'agent_review_merge_final_receipt_draft_does_not_complete_packets',
                'agent_review_merge_final_receipt_draft_does_not_accept_signature',
                'agent_review_merge_final_receipt_draft_does_not_validate_signature',
                'agent_review_merge_final_receipt_draft_does_not_record_decision',
                'agent_review_merge_final_receipt_draft_does_not_approve_code',
                'agent_review_merge_final_receipt_draft_does_not_merge',
                'agent_review_merge_final_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge final receipt draft is ready as an unsigned, non-authorizing Forge Workspace receipt shell. It still does not validate, approve or merge.'
                : 'Agent review merge final receipt draft is blocked until authorizing action template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergeFinalReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $requestReady = data_get($receiptPayload, 'status') === 'merge_final_receipt_draft_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $signablePayload = [
            'signature_request_id' => 'AGENT-REVIEW-MERGE-FINAL-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
            'request_id' => 'AGENT-REVIEW-MERGE-FINAL-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_final_signature_request.v1',
            'status' => $requestReady ? 'merge_final_signature_request_pending' : 'merge_final_signature_request_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_final_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'signature_request' => $signatureRequest,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_final_signature_request_does_not_claim_packets',
                'agent_review_merge_final_signature_request_does_not_complete_packets',
                'agent_review_merge_final_signature_request_does_not_accept_signature',
                'agent_review_merge_final_signature_request_does_not_validate_signature',
                'agent_review_merge_final_signature_request_does_not_record_decision',
                'agent_review_merge_final_signature_request_does_not_approve_code',
                'agent_review_merge_final_signature_request_does_not_merge',
                'agent_review_merge_final_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Agent review merge final signature request is pending as a Forge Workspace signable receipt payload. It still does not accept, validate, approve or merge.'
                : 'Agent review merge final signature request is blocked until final receipt draft is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->agentReviewMergeFinalSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_final_signature_request_pending';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-FINAL-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_artifact_integrity_statement',
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
                'verify_workspace_artifact_integrity',
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
                'workspace_artifact_integrity_failure',
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
                'must_reference_obras_shared_workspace',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_final_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_final_post_signature_runbook_ready' : 'merge_final_post_signature_runbook_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_final_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'agent_review_merge_final_post_signature_runbook_does_not_claim_packets',
                'agent_review_merge_final_post_signature_runbook_does_not_complete_packets',
                'agent_review_merge_final_post_signature_runbook_does_not_accept_signature',
                'agent_review_merge_final_post_signature_runbook_does_not_validate_signature',
                'agent_review_merge_final_post_signature_runbook_does_not_sign_receipt',
                'agent_review_merge_final_post_signature_runbook_does_not_record_decision',
                'agent_review_merge_final_post_signature_runbook_does_not_approve_code',
                'agent_review_merge_final_post_signature_runbook_does_not_merge',
                'agent_review_merge_final_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Agent review merge final post-signature runbook is ready as a non-executing Forge Workspace checklist. It still does not validate, sign, approve or merge.'
                : 'Agent review merge final post-signature runbook is blocked until final signature request is pending.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergeFinalPostSignatureRunbook($options);
        $templateReady = data_get($runbookPayload, 'status') === 'merge_final_post_signature_runbook_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'selected_decision_equals_merge',
                'decision_rationale',
                'fresh_merge_preflight_hash',
                'fresh_test_output_hash',
                'fresh_docs_health_output_hash',
                'fresh_architecture_validate_output_hash',
                'fresh_diff_check_output_hash',
                'workspace_artifact_integrity_statement',
                'scope_integrity_statement',
                'hot_scope_exclusion_statement',
                'packet_evidence_integrity_statement',
                'rollback_plan_hash',
                'human_confirmation_hash',
            ],
            'required_validations_before_persisting_signed_receipt' => [
                'signature_validates_against_final_signable_payload_hash',
                'final_receipt_hash_matches_source',
                'selected_decision_equals_merge',
                'fresh_gate_hashes_present',
                'workspace_artifact_integrity_passed',
                'scope_integrity_passed',
                'hot_scope_exclusion_passed',
                'packet_evidence_integrity_passed',
                'rollback_plan_hash_present',
                'human_confirmation_hash_present',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_final_receipt_id',
                'workspace_id',
                'obra_id',
                'source_final_post_signature_runbook_hash',
                'validated_signature_hash',
                'validated_final_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_gate_hashes',
                'workspace_artifact_integrity_result',
                'scope_integrity_result',
                'evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'signed_by',
                'signed_at',
            ],
            'future_executor_release_boundary' => [
                'signed_receipt_may_enable_future_executor_release_preflight',
                'signed_receipt_must_reference_obras_shared_workspace',
                'signed_receipt_must_not_apply_patch_or_merge_directly',
                'separate_executor_release_must_validate_signed_receipt_hash',
                'executor_must_consume_signed_final_merge_receipt',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_signed_final_receipt_template',
                'signature_validation_by_signed_final_receipt_template',
                'receipt_persistence_by_signed_final_receipt_template',
                'executor_release_by_signed_final_receipt_template',
                'approval_from_signed_final_receipt_template',
                'merge_from_signed_final_receipt_template',
                'dispatch_from_signed_final_receipt_template',
            ],
            'signature_present' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'executor_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_signed_final_receipt_template.v1',
            'status' => $templateReady ? 'merge_signed_final_receipt_template_ready' : 'merge_signed_final_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_signed_final_receipt_template',
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
                'agent_review_merge_signed_final_receipt_template_does_not_claim_packets',
                'agent_review_merge_signed_final_receipt_template_does_not_complete_packets',
                'agent_review_merge_signed_final_receipt_template_does_not_accept_signature',
                'agent_review_merge_signed_final_receipt_template_does_not_validate_signature',
                'agent_review_merge_signed_final_receipt_template_does_not_persist_receipt',
                'agent_review_merge_signed_final_receipt_template_does_not_release_executor',
                'agent_review_merge_signed_final_receipt_template_does_not_approve_code',
                'agent_review_merge_signed_final_receipt_template_does_not_merge',
                'agent_review_merge_signed_final_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge signed final receipt template is ready as a non-persisting Forge Workspace receipt contract. It still does not accept signature, persist receipt, release executor or merge.'
                : 'Agent review merge signed final receipt template is blocked until final post-signature runbook is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergeSignedFinalReceiptTemplate($options);
        $preflightReady = data_get($templatePayload, 'status') === 'merge_signed_final_receipt_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'fresh_gate_hashes_present',
                'workspace_artifact_integrity_passed',
                'validated_scope_integrity_passed',
                'hot_scope_exclusion_passed',
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
                'workspace_artifact_integrity_failure',
                'scope_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'packet_evidence_integrity_failure',
                'missing_rollback_plan_hash',
                'missing_human_confirmation_hash',
            ],
            'future_persistence_requirements' => [
                'persist_signed_final_receipt_append_only',
                'include_workspace_id_and_obra_id',
                'include_all_signed_receipt_fields',
                'hash_signed_receipt_before_executor_release',
                'keep_patch_execution_separate',
                'emit_executor_release_preflight_after_persistence',
            ],
            'still_forbidden_after_preflight' => [
                'signature_acceptance_by_signed_final_receipt_preflight',
                'signature_validation_by_signed_final_receipt_preflight',
                'receipt_persistence_by_signed_final_receipt_preflight',
                'approval_from_signed_final_receipt_preflight',
                'executor_release_from_signed_final_receipt_preflight',
                'merge_from_signed_final_receipt_preflight',
                'dispatch_from_signed_final_receipt_preflight',
            ],
            'signature_valid' => false,
            'receipt_signed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_signed_final_receipt_preflight.v1',
            'status' => $preflightReady ? 'merge_signed_final_receipt_preflight_ready' : 'merge_signed_final_receipt_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_signed_final_receipt_preflight',
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
                'agent_review_merge_signed_final_receipt_preflight_does_not_claim_packets',
                'agent_review_merge_signed_final_receipt_preflight_does_not_complete_packets',
                'agent_review_merge_signed_final_receipt_preflight_does_not_accept_signature',
                'agent_review_merge_signed_final_receipt_preflight_does_not_validate_signature',
                'agent_review_merge_signed_final_receipt_preflight_does_not_persist_receipt',
                'agent_review_merge_signed_final_receipt_preflight_does_not_release_executor',
                'agent_review_merge_signed_final_receipt_preflight_does_not_approve_code',
                'agent_review_merge_signed_final_receipt_preflight_does_not_merge',
                'agent_review_merge_signed_final_receipt_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge signed final receipt preflight is ready as a non-persisting Forge Workspace check contract. It still does not accept signature, validate signature, persist receipt, release executor or merge.'
                : 'Agent review merge signed final receipt preflight is blocked until signed final receipt template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergeSignedFinalReceiptPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_signed_final_receipt_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-SIGNED-FINAL-RECEIPT-PERSISTENCE-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'validated_workspace_artifact_integrity_result',
                'validated_scope_integrity_result',
                'validated_packet_evidence_integrity_result',
                'validated_rollback_plan_hash',
                'validated_human_confirmation_hash',
            ],
            'append_only_persistence_fields' => [
                'signed_final_receipt_id',
                'workspace_id',
                'obra_id',
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
                'workspace_artifact_integrity_result',
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
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
                'executor_release_by_signed_final_receipt_persistence_template',
                'merge_from_signed_final_receipt_persistence_template',
                'dispatch_from_signed_final_receipt_persistence_template',
            ],
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'executor_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_signed_final_receipt_persistence_template.v1',
            'status' => $templateReady ? 'merge_signed_final_receipt_persistence_template_ready' : 'merge_signed_final_receipt_persistence_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_signed_final_receipt_persistence_template',
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
            'receipt_persisted' => false,
            'executor_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_claim_packets',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_complete_packets',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_accept_signature',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_validate_signature',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_persist_receipt',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_release_executor',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_approve_code',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_merge',
                'agent_review_merge_signed_final_receipt_persistence_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge signed final receipt persistence template is ready as a read-only Forge Workspace append-only storage contract. It still does not accept signature, validate signature, persist receipt, release executor or merge.'
                : 'Agent review merge signed final receipt persistence template is blocked until signed final receipt preflight is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorReleasePreflight(array $options = []): array
    {
        $persistencePayload = $this->parent->agentReviewMergeSignedFinalReceiptPersistenceTemplate($options);
        $preflightReady = data_get($persistencePayload, 'status') === 'merge_signed_final_receipt_persistence_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $preflight = [
            'preflight_id' => 'AGENT-REVIEW-MERGE-EXECUTOR-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
                'workspace_or_obra_mismatch',
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
                'executor_revalidates_workspace_and_obra_before_patch',
                'executor_revalidates_final_diff_before_patch',
                'executor_revalidates_hot_scope_before_patch',
                'executor_emits_execution_receipt',
                'executor_stops_before_merge_on_any_gate_failure',
            ],
            'still_forbidden_by_preflight' => [
                'persisted_receipt_acceptance_by_executor_release_preflight',
                'receipt_persistence_by_executor_release_preflight',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_executor_release_preflight.v1',
            'status' => $preflightReady ? 'merge_executor_release_preflight_ready' : 'merge_executor_release_preflight_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_executor_release_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'receipt_persisted' => false,
            'approval_granted' => false,
            'executor_allowed' => false,
            'merge_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_review_merge_executor_release_preflight_does_not_claim_packets',
                'agent_review_merge_executor_release_preflight_does_not_complete_packets',
                'agent_review_merge_executor_release_preflight_does_not_accept_persisted_receipt',
                'agent_review_merge_executor_release_preflight_does_not_persist_receipt',
                'agent_review_merge_executor_release_preflight_does_not_release_executor',
                'agent_review_merge_executor_release_preflight_does_not_execute_patch',
                'agent_review_merge_executor_release_preflight_does_not_merge',
                'agent_review_merge_executor_release_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Agent review merge executor release preflight is ready as a read-only Forge Workspace release prerequisite contract. It still does not accept persisted receipt evidence, release an executor, execute patches or merge.'
                : 'Agent review merge executor release preflight is blocked until signed final receipt persistence template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorContractTemplate(array $options = []): array
    {
        $releasePayload = $this->parent->agentReviewMergeExecutorReleasePreflight($options);
        $templateReady = data_get($releasePayload, 'status') === 'merge_executor_release_preflight_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-EXECUTOR-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
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
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
                'workspace_id',
                'obra_id',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_executor_contract_template.v1',
            'status' => $templateReady ? 'merge_executor_contract_template_ready' : 'merge_executor_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_executor_contract_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'patch_execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'executor_allowed' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_merge_executor_contract_template_does_not_claim_packets',
                'agent_review_merge_executor_contract_template_does_not_complete_packets',
                'agent_review_merge_executor_contract_template_does_not_accept_release_authority',
                'agent_review_merge_executor_contract_template_does_not_release_executor',
                'agent_review_merge_executor_contract_template_does_not_execute_patch',
                'agent_review_merge_executor_contract_template_does_not_merge',
                'agent_review_merge_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge executor contract template is ready as a read-only Forge Workspace future executor contract. It still does not release an executor, execute patches or merge.'
                : 'Agent review merge executor contract template is blocked until executor release preflight is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutionReceiptTemplate(array $options = []): array
    {
        $executorContractPayload = $this->parent->agentReviewMergeExecutorContractTemplate($options);
        $templateReady = data_get($executorContractPayload, 'status') === 'merge_executor_contract_template_ready';

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
        ];

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-EXECUTION-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => $workspace,
            'status' => $templateReady ? 'waiting_for_future_executor_execution_evidence' : 'blocked_before_executor_contract_template',
            'source_executor_contract_template_hash' => data_get($executorContractPayload, 'template_hash'),
            'source_executor_release_preflight_hash' => data_get($executorContractPayload, 'template.source_executor_release_preflight_hash'),
            'source_signed_final_receipt_persistence_template_hash' => data_get($executorContractPayload, 'template.source_signed_final_receipt_persistence_template_hash'),
            'source_final_receipt_hash' => data_get($executorContractPayload, 'template.source_final_receipt_hash'),
            'required_execution_evidence' => [
                'executor_run_id',
                'executor_identity',
                'workspace_id',
                'obra_id',
                'provider_name',
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
                'workspace_id_matches_forge_workspace',
                'obra_id_matches_self_construction_os',
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
                'missing_workspace_id',
                'missing_obra_id',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_execution_receipt_template.v1',
            'status' => $templateReady ? 'merge_execution_receipt_template_ready' : 'merge_execution_receipt_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_execution_receipt_template',
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
                'agent_review_merge_execution_receipt_template_does_not_claim_packets',
                'agent_review_merge_execution_receipt_template_does_not_complete_packets',
                'agent_review_merge_execution_receipt_template_does_not_release_executor',
                'agent_review_merge_execution_receipt_template_does_not_execute_patch',
                'agent_review_merge_execution_receipt_template_does_not_record_execution',
                'agent_review_merge_execution_receipt_template_does_not_persist_receipt',
                'agent_review_merge_execution_receipt_template_does_not_merge',
                'agent_review_merge_execution_receipt_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review merge execution receipt template is ready as a read-only Forge Workspace post-execution evidence contract. It still does not execute patches, record execution, persist receipts or merge.'
                : 'Agent review merge execution receipt template is blocked until executor contract template is ready.',
        ];
    }
}
