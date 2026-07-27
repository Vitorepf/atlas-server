<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessReviewMergeEnvelope;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 05 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate
 */
final class ReviewMergePart05SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_preflight_ready';

        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
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
                'workspace',
                'source_writer_release_preflight_hash',
                'source_writer_release_authorization_signed_receipt_template_hash',
                'validated_writer_release_authorization_signature_hash',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
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
                'workspace',
                'writer_release_decision',
                'writer_release_actor_identity',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
                'writer_release_blocking_conditions',
            ],
            'future_post_signature_outputs' => [
                'writer_release_signature_request_hash',
                'writer_release_signed_receipt_template_hash',
                'writer_release_execution_contract_hash',
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
                'writer_release_provider_identity_evidence_hash',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft',
            ready: $preflightReady,
            payloadKey: 'receipt',
            payload: $receipt,
            humanSummary: $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release receipt draft is ready as a provider-neutral unsigned Forge Workspace receipt. It still does not create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release receipt draft is blocked until writer release preflight is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_receipt_draft_ready';
        $signablePayload = [
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'workspace' => data_get($receipt, 'workspace'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($receipt, 'source_writer_release_preflight_hash'),
            'source_writer_release_authorization_signed_receipt_template_hash' => data_get($receipt, 'source_writer_release_authorization_signed_receipt_template_hash'),
            'selected_decision' => data_get($receipt, 'selected_decision'),
            'inherited_blocking_conditions' => data_get($receipt, 'inherited_blocking_conditions', []),
            'requested_signature_scope' => 'writer_release_receipt_only',
            'requested_signer_role' => 'human_operator_or_policy_authority',
            'identity_constraints' => [
                'workspace_identity_must_match' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'obra_identity_must_match' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
                'provider_identity_must_match_declared_agent' => true,
            ],
        ];
        $signablePayloadHash = ReadinessHash::stable($signablePayload);

        $signatureRequest = [
            'request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($receipt, 'workspace'),
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
                'writer_release_workspace_identity_hash_signed',
                'writer_release_obra_identity_hash_signed',
                'writer_release_provider_identity_hash_signed',
            ],
            'future_post_signature_outputs' => [
                'writer_release_signature_validation_hash',
                'writer_release_signed_receipt_template_hash',
                'writer_release_post_signature_runbook_hash',
                'writer_release_execution_contract_hash',
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
                'writer_release_provider_identity_evidence_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release signature request is ready as a provider-neutral non-authorizing Forge Workspace request. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release signature request is blocked until writer release receipt draft is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook(array $options = []): array
    {
        $requestPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options);
        $signatureRequest = (array) data_get($requestPayload, 'signature_request', []);
        $requestReady = data_get($requestPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_signature_request_ready';

        $orderedSteps = [
            'collect_external_writer_release_signature_evidence',
            'verify_signature_scope_matches_writer_release_receipt_only',
            'verify_workspace_identity_hash_matches_forge_workspace',
            'verify_obra_identity_hash_matches_self_construction_obra',
            'verify_provider_identity_hash_matches_declared_agent',
            'verify_signed_receipt_hash_matches_source_writer_release_receipt_hash',
            'verify_signed_payload_hash_matches_source_writer_release_signable_payload_hash',
            'recheck_writer_release_blockers_before_validation',
            'prepare_signed_writer_release_receipt_template_inputs',
            'prepare_writer_release_execution_contract_inputs',
            'stop_before_signature_acceptance_or_writer_release',
        ];

        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signatureRequest, 'workspace'),
            'status' => $requestReady ? 'waiting_for_external_writer_release_signature_evidence' : 'blocked_before_writer_release_signature_request',
            'source_writer_release_signature_request_hash' => data_get($requestPayload, 'request_hash'),
            'source_writer_release_signable_payload_hash' => data_get($requestPayload, 'signable_payload_hash'),
            'source_writer_release_receipt_hash' => data_get($signatureRequest, 'source_writer_release_receipt_hash'),
            'source_writer_release_preflight_hash' => data_get($signatureRequest, 'source_writer_release_preflight_hash'),
            'selected_decision' => data_get($signatureRequest, 'selected_decision'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'ordered_steps' => $orderedSteps,
            'step_count' => count($orderedSteps),
            'required_external_signature_evidence' => data_get($signatureRequest, 'required_signature_evidence', []),
            'future_signed_receipt_template_inputs' => [
                'validated_writer_release_signature_hash',
                'validated_writer_release_signable_payload_hash',
                'validated_writer_release_receipt_hash',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
                'writer_release_signer_identity',
                'writer_release_signature_validated_at',
                'writer_release_post_signature_runbook_hash',
            ],
            'future_validator_must_check' => [
                'writer_release_signature_value_present',
                'writer_release_signature_scope_exact',
                'workspace_identity_hash_matches_forge_workspace',
                'obra_identity_hash_matches_self_construction_obra',
                'provider_identity_hash_matches_declared_agent',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook',
            ready: $requestReady,
            payloadKey: 'runbook',
            payload: $runbook,
            humanSummary: $requestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release post-signature runbook is ready as a provider-neutral non-authorizing Forge Workspace sequence. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release post-signature runbook is blocked until writer release signature request is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_signature_runbook_ready';

        $template = [
            'template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-SIGNED-RECEIPT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($runbook, 'workspace'),
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
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
            ],
            'signed_receipt_fields_to_persist_in_future' => [
                'signed_writer_release_receipt_id',
                'source_writer_release_receipt_hash',
                'source_writer_release_signable_payload_hash',
                'source_writer_release_post_signature_runbook_hash',
                'workspace',
                'obra',
                'provider_identity',
                'validated_workspace_identity_hash',
                'validated_obra_identity_hash',
                'validated_provider_identity_hash',
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
                'workspace_identity_matches_forge_workspace',
                'obra_identity_matches_self_construction_obra',
                'provider_identity_matches_declared_agent',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template',
            ready: $runbookReady,
            payloadKey: 'template',
            payload: $template,
            humanSummary: $runbookReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release signed receipt template is ready as a provider-neutral non-persisting Forge Workspace contract. It still does not accept signatures, create a writer, write ledger, persist receipts, approve or merge.'
                : 'Agent review merge post-execution action signed receipt persistence writer release signed receipt template is blocked until writer release post-signature runbook is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_signed_receipt_template_ready';

        $blockingConditions = $templateReady
            ? [
                'missing_external_validated_writer_release_signature_evidence',
                'workspace_identity_not_revalidated_against_forge_workspace',
                'obra_identity_not_revalidated_against_self_construction_obra',
                'provider_identity_not_revalidated_against_declared_agent',
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
            'preflight_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-EXECUTION-CONTRACT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($template, 'workspace'),
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
                'workspace_identity_matches_forge_workspace',
                'obra_identity_matches_self_construction_obra',
                'provider_identity_matches_declared_agent',
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
                'writer_release_provider_identity_evidence_hash',
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight',
            ready: $templateReady,
            payloadKey: 'preflight',
            payload: $preflight,
            humanSummary: $templateReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release execution contract preflight is ready as a provider-neutral blocker report. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release execution contract preflight is blocked until writer release signed receipt template is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_preflight_ready';

        $blockingConditions = $preflightReady
            ? (array) data_get($preflight, 'blocking_conditions', [])
            : [
                'writer_release_execution_contract_preflight_not_ready',
            ];

        $contract = [
            'contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-EXECUTION-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($preflight, 'workspace'),
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
                'workspace_id_must_match' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'obra_id_must_match' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
                'provider_identity_must_match_declared_agent' => true,
                'forbidden_scope' => [
                    'merge_execution',
                    'dispatch_execution',
                    'receipt_persistence_execution',
                    'policy_mutation',
                    'hot_scope_mutation',
                    'unscoped_file_creation',
                    'cross_workspace_execution',
                    'cross_obra_execution',
                ],
                'writer_contract_expected_capability' => 'signed_post_execution_action_receipt_persistence_writer_only',
                'writer_contract_forbidden_capabilities' => [
                    'merge_authority',
                    'dispatch_authority',
                    'signature_validation_authority',
                    'approval_authority',
                    'self_release_authority',
                    'provider_identity_override_authority',
                ],
            ],
            'required_actor_evidence' => [
                'writer_release_executor_identity',
                'writer_release_executor_provider',
                'writer_release_executor_session',
                'writer_release_execution_reason',
                'writer_release_execution_scope_hash',
                'writer_release_execution_contract_reviewer_identity',
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
                'writer_release_provider_identity_evidence_hash',
            ],
            'required_recheck_evidence' => [
                'writer_contract_hash_rechecked_against_patch',
                'hot_scope_clean_recheck_hash',
                'writer_capability_test_output_hash',
                'writer_no_merge_authority_evidence_hash',
                'writer_no_dispatch_authority_evidence_hash',
                'workspace_identity_recheck_hash',
                'obra_identity_recheck_hash',
                'provider_identity_recheck_hash',
                'rollback_and_disable_plan_hash',
            ],
            'future_post_execution_outputs' => [
                'writer_release_execution_contract_hash',
                'writer_release_disable_contract_hash',
                'writer_release_observability_contract_hash',
                'writer_release_post_execution_receipt_hash',
                'writer_release_provider_identity_evidence_hash',
                'writer_release_workspace_identity_evidence_hash',
                'writer_release_obra_identity_evidence_hash',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template',
            ready: $preflightReady,
            payloadKey: 'contract',
            payload: $contract,
            humanSummary: $preflightReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release execution contract template is ready as a provider-neutral non-authorizing Forge Workspace contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release execution contract template is blocked until execution contract preflight is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate(array $options = []): array
    {
        $contractPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'contract', []);
        $contractReady = data_get($contractPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_execution_contract_template_ready';

        $disableTriggers = [
            'writer_contract_hash_drift_detected',
            'workspace_identity_drift_detected',
            'obra_identity_drift_detected',
            'provider_identity_drift_detected',
            'hot_scope_dirty_after_release',
            'writer_capability_tests_failed_after_release',
            'writer_merge_authority_detected',
            'writer_dispatch_authority_detected',
            'unexpected_signature_validation_attempt',
            'unexpected_receipt_persistence_attempt',
            'unexpected_ledger_write_attempt',
            'cross_workspace_execution_attempt_detected',
            'cross_obra_execution_attempt_detected',
            'operator_revocation_requested',
            'rollback_plan_missing_or_invalid',
        ];

        $disableContract = [
            'disable_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-DISABLE-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($contract, 'workspace'),
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
                'rerun_workspace_identity_check',
                'rerun_obra_identity_check',
                'rerun_provider_identity_check',
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
                'post_disable_workspace_identity_evidence_hash',
                'post_disable_obra_identity_evidence_hash',
                'post_disable_provider_identity_evidence_hash',
                'post_disable_no_merge_authority_evidence_hash',
                'post_disable_no_dispatch_authority_evidence_hash',
            ],
            'future_disable_outputs' => [
                'writer_release_disable_contract_hash',
                'writer_release_disable_receipt_hash',
                'writer_release_revocation_event_hash',
                'writer_release_reenable_review_packet_hash',
                'writer_release_post_disable_workspace_identity_hash',
                'writer_release_post_disable_obra_identity_hash',
                'writer_release_post_disable_provider_identity_hash',
            ],
            'reenable_requirements' => [
                'new_execution_contract_preflight',
                'new_execution_contract_template',
                'new_disable_contract_template',
                'fresh_human_authorization',
                'fresh_workspace_identity_recheck',
                'fresh_obra_identity_recheck',
                'fresh_provider_identity_recheck',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template',
            ready: $contractReady,
            payloadKey: 'disable_contract',
            payload: $disableContract,
            humanSummary: $contractReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release disable contract template is ready as a provider-neutral rollback contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release disable contract template is blocked until execution contract template is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate(array $options = []): array
    {
        $disablePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options);
        $disableContract = (array) data_get($disablePayload, 'disable_contract', []);
        $disableReady = data_get($disablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_disable_contract_template_ready';

        $signals = [
            'writer_release_execution_contract_loaded',
            'writer_release_workspace_identity_checked',
            'writer_release_obra_identity_checked',
            'writer_release_provider_identity_checked',
            'writer_release_runtime_started',
            'writer_release_capability_flag_checked',
            'writer_release_receipt_persistence_attempted',
            'writer_release_ledger_write_attempted',
            'writer_release_forbidden_merge_attempt_detected',
            'writer_release_forbidden_dispatch_attempt_detected',
            'writer_release_cross_workspace_execution_attempt_detected',
            'writer_release_cross_obra_execution_attempt_detected',
            'writer_release_workspace_identity_drift_detected',
            'writer_release_obra_identity_drift_detected',
            'writer_release_provider_identity_drift_detected',
            'writer_release_disable_trigger_detected',
            'writer_release_disable_completed',
            'writer_release_reenable_requested',
            'writer_release_post_execution_receipt_generated',
            'writer_release_human_review_required',
        ];

        $observabilityContract = [
            'observability_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-OBSERVABILITY-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($disableContract, 'workspace'),
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
                'writer_release_workspace_identity_check_count',
                'writer_release_obra_identity_check_count',
                'writer_release_provider_identity_check_count',
                'writer_release_workspace_identity_drift_count',
                'writer_release_obra_identity_drift_count',
                'writer_release_provider_identity_drift_count',
                'writer_release_cross_workspace_attempt_count',
                'writer_release_cross_obra_attempt_count',
                'writer_release_disable_trigger_count',
                'writer_release_forbidden_merge_attempt_count',
                'writer_release_forbidden_dispatch_attempt_count',
                'writer_release_unexpected_ledger_write_attempt_count',
                'writer_release_unexpected_receipt_persistence_attempt_count',
                'writer_release_time_to_disable_ms',
            ],
            'required_alerts' => [
                'alert_on_writer_contract_hash_drift',
                'alert_on_workspace_identity_drift',
                'alert_on_obra_identity_drift',
                'alert_on_provider_identity_drift',
                'alert_on_cross_workspace_execution',
                'alert_on_cross_obra_execution',
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
                'workspace_id',
                'obra_id',
                'provider_id',
                'declared_agent_id',
                'writer_release_contract_hash',
                'writer_release_disable_contract_hash',
                'workspace_identity_evidence_hash',
                'obra_identity_evidence_hash',
                'provider_identity_evidence_hash',
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
                'writer_release_workspace_identity_monitoring_hash',
                'writer_release_obra_identity_monitoring_hash',
                'writer_release_provider_identity_monitoring_hash',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_observability_contract_template',
            ready: $disableReady,
            payloadKey: 'observability_contract',
            payload: $observabilityContract,
            humanSummary: $disableReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release observability contract template is ready as a provider-neutral Forge Workspace monitoring contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release observability contract template is blocked until disable contract template is ready.',
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options);
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
            'review_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-POST-MONITORING-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($observabilityContract, 'workspace'),
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
                'workspace_identity_evidence_hash',
                'obra_identity_evidence_hash',
                'provider_identity_evidence_hash',
                'human_reviewer_identity',
            ],
            'health_checks' => [
                'monitoring_window_completed',
                'all_required_signals_present',
                'metrics_snapshot_present',
                'alert_policy_present',
                'workspace_identity_still_matches',
                'obra_identity_still_matches',
                'provider_identity_still_matches_declared_agent',
                'no_cross_workspace_attempts',
                'no_cross_obra_attempts',
                'no_forbidden_merge_attempts',
                'no_forbidden_dispatch_attempts',
                'no_unexpected_ledger_writes',
                'no_unexpected_receipt_persistence',
                'disable_path_still_available',
                'human_review_completed',
            ],
            'failure_to_decision_map' => [
                'workspace_identity_drift' => 'request_disable_execution',
                'obra_identity_drift' => 'request_disable_execution',
                'provider_identity_drift' => 'request_disable_execution',
                'cross_workspace_execution_attempt' => 'request_disable_execution',
                'cross_obra_execution_attempt' => 'request_disable_execution',
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
                'workspace_identity_review_hash',
                'obra_identity_review_hash',
                'provider_identity_review_hash',
                'disable_path_verification_hash',
            ],
            'future_review_outputs' => [
                'writer_release_post_monitoring_review_hash',
                'writer_release_health_decision_hash',
                'writer_release_reenable_review_packet_hash',
                'writer_release_disable_request_hash',
                'writer_release_workspace_identity_review_hash',
                'writer_release_obra_identity_review_hash',
                'writer_release_provider_identity_review_hash',
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

        return ReadinessReviewMergeEnvelope::project(
            slug: 'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template',
            statusSlug: 'merge_post_execution_action_signed_receipt_persistence_writer_release_post_monitoring_review_template',
            ready: $observabilityReady,
            payloadKey: 'review_template',
            payload: $reviewTemplate,
            humanSummary: $observabilityReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release post-monitoring review template is ready as a provider-neutral Forge Workspace health review. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release post-monitoring review template is blocked until observability contract template is ready.',
        );
    }
}
