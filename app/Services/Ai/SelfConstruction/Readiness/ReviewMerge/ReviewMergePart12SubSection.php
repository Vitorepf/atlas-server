<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 12 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate
 */
final class ReviewMergePart12SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate($options);
        $repairReview = (array) data_get($reviewPayload, 'disable_execution_later_cycle_authorization_repair_review', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_template_ready';

        $requiredRejectionFields = [
            'later_cycle_authorization_repair_review_hash',
            'selected_repair_review_outcome',
            'persistence_rejection_rationale',
            'repaired_evidence_packet_hash',
            'repaired_evidence_integrity_hash',
            'replacement_provider_identity_receipt_draft_hash',
            'replacement_workspace_obra_receipt_draft_hash',
            'replacement_validated_signer_provider_scope',
            'rejection_actor_identity',
            'rejection_actor_provider',
            'rejection_timestamp',
            'non_persistence_statement',
            'non_authorization_statement',
            'non_signature_authority_statement',
            'non_execution_statement',
        ];

        $persistenceRejection = [
            'disable_execution_later_cycle_authorization_persistence_rejection_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-LATER-CYCLE-AUTHORIZATION-PERSISTENCE-REJECTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($repairReview, 'workspace'),
            'status' => $reviewReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_hash' => data_get($reviewPayload, 'disable_execution_later_cycle_authorization_repair_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repaired_evidence_packet_hash' => data_get($repairReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repaired_evidence_packet_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_evidence_repair_request_hash' => data_get($repairReview, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_evidence_repair_request_hash'),
            'required_rejection_fields' => $requiredRejectionFields,
            'required_rejection_field_count' => count($requiredRejectionFields),
            'allowed_rejection_reasons' => [
                'missing_later_cycle_authorization_integrity',
                'missing_prior_authorization_reuse_denial',
                'missing_non_persistence_statement',
                'missing_non_signature_authority_statement',
                'missing_provider_identity_or_workspace_binding',
                'missing_human_reviewer_identity',
                'repaired_evidence_not_sufficient_for_receipt_persistence',
            ],
            'required_rejection_evidence' => [
                'later_cycle_authorization_repair_review_hash',
                'selected_repair_review_outcome',
                'persistence_rejection_rationale',
                'repaired_evidence_integrity_hash',
                'replacement_provider_identity_receipt_draft_hash',
                'replacement_workspace_obra_receipt_draft_hash',
                'replacement_validated_signer_provider_scope',
                'non_persistence_statement',
                'non_signature_authority_statement',
                'human_reviewer_identity',
            ],
            'required_rejection_evidence_count' => 10,
            'rejection_policy' => [
                'persistence_rejection_requires_repair_review_hash',
                'persistence_rejection_requires_allowed_review_outcome',
                'persistence_rejection_requires_integrity_hash',
                'persistence_rejection_requires_replacement_provider_identity_receipt_draft_hash',
                'persistence_rejection_requires_replacement_workspace_obra_receipt_draft_hash',
                'persistence_rejection_requires_replacement_validated_signer_provider_scope',
                'persistence_rejection_requires_non_persistence_statement',
                'persistence_rejection_requires_non_signature_authority_statement',
                'persistence_rejection_requires_later_cycle_authorized_flag_false',
                'persistence_rejection_requires_prior_authorization_reuse_allowed_flag_false',
                'persistence_rejection_requires_signature_authority_flag_false',
                'persistence_rejection_does_not_accept_signature',
                'persistence_rejection_does_not_become_signature_authority',
                'persistence_rejection_does_not_sign_receipt',
                'persistence_rejection_does_not_write_ledger',
                'persistence_rejection_does_not_persist_receipt',
                'persistence_rejection_does_not_record_decision',
                'persistence_rejection_does_not_authorize_later_cycle',
                'persistence_rejection_does_not_execute_disable',
                'persistence_rejection_does_not_mutate_writer_state',
            ],
            'future_rejection_outputs' => [
                'writer_release_fresh_authorization_later_cycle_authorization_persistence_rejection_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_rejection_integrity_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_follow_up_observability_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_human_escalation_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template' => [
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'signature_authority_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'receipt_signature_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'later_cycle_authorization_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'authorization_reuse_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
            ],
            'execution_allowed' => false,
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
            'disable_execution_later_cycle_authorization_persistence_rejection' => $persistenceRejection,
            'disable_execution_later_cycle_authorization_persistence_rejection_hash' => ReadinessHash::stable($persistenceRejection),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_become_signature_authority',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_sign_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_authorize_later_cycle',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_reuse_prior_authorization',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization persistence rejection template is ready as a provider-neutral non-writing persistence rejection template. It still does not accept signatures, become signature authority, sign receipts, persist receipts, grant approval, authorize a later cycle, reuse prior authorization, write ledger, record decisions, execute disable, mutate writer state, create a writer, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization persistence rejection template is blocked until fresh authorization new-cycle disable execution later-cycle authorization repair review template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate(array $options = []): array
    {
        $rejectionPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate($options);
        $persistenceRejection = (array) data_get($rejectionPayload, 'disable_execution_later_cycle_authorization_persistence_rejection', []);
        $rejectionReady = data_get($rejectionPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template_ready';

        $requiredEscalationFields = [
            'later_cycle_authorization_persistence_rejection_hash',
            'persistence_rejection_rationale',
            'selected_repair_review_outcome',
            'human_escalation_reason',
            'required_human_role',
            'review_packet_hash',
            'provider_identity_receipt_draft_hash',
            'workspace_obra_receipt_draft_hash',
            'non_dispatch_statement',
            'non_authorization_statement',
            'non_persistence_statement',
            'non_signature_authority_statement',
            'escalation_actor_identity',
            'escalation_actor_provider',
        ];

        $humanEscalation = [
            'disable_execution_later_cycle_authorization_human_escalation_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-LATER-CYCLE-AUTHORIZATION-HUMAN-ESCALATION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($persistenceRejection, 'workspace'),
            'status' => $rejectionReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_hash' => data_get($rejectionPayload, 'disable_execution_later_cycle_authorization_persistence_rejection_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_hash' => data_get($persistenceRejection, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repaired_evidence_packet_hash' => data_get($persistenceRejection, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repaired_evidence_packet_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_evidence_repair_request_hash' => data_get($persistenceRejection, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_evidence_repair_request_hash'),
            'required_escalation_fields' => $requiredEscalationFields,
            'required_escalation_field_count' => count($requiredEscalationFields),
            'allowed_human_roles' => [
                'owner',
                'security_reviewer',
                'architecture_reviewer',
                'governance_reviewer',
                'audit_reviewer',
            ],
            'required_escalation_evidence' => [
                'later_cycle_authorization_persistence_rejection_hash',
                'persistence_rejection_rationale',
                'selected_repair_review_outcome',
                'human_escalation_reason',
                'required_human_role',
                'provider_identity_receipt_draft_hash',
                'workspace_obra_receipt_draft_hash',
                'non_signature_authority_statement',
                'human_reviewer_identity',
            ],
            'required_escalation_evidence_count' => 9,
            'escalation_policy' => [
                'human_escalation_requires_persistence_rejection_hash',
                'human_escalation_requires_human_role',
                'human_escalation_requires_provider_identity_receipt_draft_hash',
                'human_escalation_requires_workspace_obra_receipt_draft_hash',
                'human_escalation_requires_non_dispatch_statement',
                'human_escalation_requires_non_authorization_statement',
                'human_escalation_requires_non_signature_authority_statement',
                'human_escalation_requires_later_cycle_authorized_flag_false',
                'human_escalation_requires_prior_authorization_reuse_allowed_flag_false',
                'human_escalation_requires_signature_authority_flag_false',
                'human_escalation_does_not_notify_human',
                'human_escalation_does_not_create_task',
                'human_escalation_does_not_accept_signature',
                'human_escalation_does_not_become_signature_authority',
                'human_escalation_does_not_sign_receipt',
                'human_escalation_does_not_write_ledger',
                'human_escalation_does_not_persist_receipt',
                'human_escalation_does_not_record_decision',
                'human_escalation_does_not_authorize_later_cycle',
                'human_escalation_does_not_execute_disable',
                'human_escalation_does_not_mutate_writer_state',
            ],
            'future_escalation_outputs' => [
                'writer_release_fresh_authorization_later_cycle_authorization_human_escalation_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_human_review_packet_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_follow_up_observability_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_manual_decision_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template' => [
                'human_notification_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'task_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'signature_authority_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'receipt_signature_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'later_cycle_authorization_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'authorization_reuse_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
            ],
            'execution_allowed' => false,
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
            'human_notified' => false,
            'human_task_created' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template.v1',
            'status' => $rejectionReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
            'human_notified' => false,
            'human_task_created' => false,
            'disable_execution_later_cycle_authorization_human_escalation' => $humanEscalation,
            'disable_execution_later_cycle_authorization_human_escalation_hash' => ReadinessHash::stable($humanEscalation),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_notify_human',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_create_task',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_become_signature_authority',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_sign_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_authorize_later_cycle',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_reuse_prior_authorization',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_does_not_dispatch_work',
            ],
            'human_summary' => $rejectionReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization human escalation template is ready as a provider-neutral non-dispatching escalation template. It still does not notify humans, create tasks, accept signatures, become signature authority, sign receipts, persist receipts, grant approval, authorize a later cycle, reuse prior authorization, write ledger, record decisions, execute disable, mutate writer state, create a writer, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization human escalation template is blocked until fresh authorization new-cycle disable execution later-cycle authorization persistence rejection template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate(array $options = []): array
    {
        $escalationPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate($options);
        $humanEscalation = (array) data_get($escalationPayload, 'disable_execution_later_cycle_authorization_human_escalation', []);
        $escalationReady = data_get($escalationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template_ready';

        $requiredDecisionRequestFields = [
            'later_cycle_authorization_human_escalation_hash',
            'required_human_role',
            'decision_question',
            'decision_context_hash',
            'available_decision_options',
            'risk_summary',
            'provider_identity_receipt_draft_hash',
            'workspace_obra_receipt_draft_hash',
            'validated_signer_provider_scope',
            'non_dispatch_statement',
            'non_authorization_statement',
            'non_persistence_statement',
            'non_signature_authority_statement',
            'request_actor_identity',
            'request_actor_provider',
        ];

        $manualDecisionRequest = [
            'disable_execution_later_cycle_authorization_manual_decision_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-DISABLE-EXECUTION-LATER-CYCLE-AUTHORIZATION-MANUAL-DECISION-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($humanEscalation, 'workspace'),
            'status' => $escalationReady ? 'ready_as_future_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_template',
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_human_escalation_hash' => data_get($escalationPayload, 'disable_execution_later_cycle_authorization_human_escalation_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_hash' => data_get($humanEscalation, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_persistence_rejection_hash'),
            'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_hash' => data_get($humanEscalation, 'source_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_repair_review_hash'),
            'required_decision_request_fields' => $requiredDecisionRequestFields,
            'required_decision_request_field_count' => count($requiredDecisionRequestFields),
            'allowed_decision_options' => [
                'reject_later_cycle_authorization',
                'request_more_evidence',
                'extend_observation_window',
                'escalate_to_security_reviewer',
                'escalate_to_owner',
            ],
            'required_decision_request_evidence' => [
                'later_cycle_authorization_human_escalation_hash',
                'required_human_role',
                'decision_question',
                'decision_context_hash',
                'risk_summary',
                'provider_identity_receipt_draft_hash',
                'workspace_obra_receipt_draft_hash',
                'validated_signer_provider_scope',
                'non_signature_authority_statement',
                'human_reviewer_identity',
            ],
            'required_decision_request_evidence_count' => 10,
            'decision_request_policy' => [
                'manual_decision_request_requires_human_escalation_hash',
                'manual_decision_request_requires_decision_question',
                'manual_decision_request_requires_allowed_decision_options',
                'manual_decision_request_requires_provider_identity_receipt_draft_hash',
                'manual_decision_request_requires_workspace_obra_receipt_draft_hash',
                'manual_decision_request_requires_validated_signer_provider_scope',
                'manual_decision_request_requires_non_dispatch_statement',
                'manual_decision_request_requires_non_authorization_statement',
                'manual_decision_request_requires_non_signature_authority_statement',
                'manual_decision_request_requires_later_cycle_authorized_flag_false',
                'manual_decision_request_requires_prior_authorization_reuse_allowed_flag_false',
                'manual_decision_request_requires_signature_authority_flag_false',
                'manual_decision_request_does_not_request_real_decision',
                'manual_decision_request_does_not_notify_human',
                'manual_decision_request_does_not_create_task',
                'manual_decision_request_does_not_accept_signature',
                'manual_decision_request_does_not_become_signature_authority',
                'manual_decision_request_does_not_sign_receipt',
                'manual_decision_request_does_not_write_ledger',
                'manual_decision_request_does_not_persist_receipt',
                'manual_decision_request_does_not_record_decision',
                'manual_decision_request_does_not_authorize_later_cycle',
                'manual_decision_request_does_not_execute_disable',
                'manual_decision_request_does_not_mutate_writer_state',
            ],
            'future_decision_request_outputs' => [
                'writer_release_fresh_authorization_later_cycle_authorization_manual_decision_request_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_manual_decision_context_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_manual_decision_response_hash',
                'writer_release_fresh_authorization_later_cycle_authorization_follow_up_observability_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template' => [
                'real_decision_request_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'human_notification_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'task_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'signature_authority_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'receipt_signature_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'approval_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'later_cycle_authorization_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'authorization_reuse_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'disable_execution_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
            ],
            'execution_allowed' => false,
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
            'human_notified' => false,
            'human_task_created' => false,
            'manual_decision_requested' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template.v1',
            'status' => $escalationReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template',
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'writer_file_creation_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'signature_accepted' => false,
            'signature_authority' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
            'prior_authorization_reuse_allowed' => false,
            'later_cycle_authorized' => false,
            'human_notified' => false,
            'human_task_created' => false,
            'manual_decision_requested' => false,
            'disable_execution_later_cycle_authorization_manual_decision_request' => $manualDecisionRequest,
            'disable_execution_later_cycle_authorization_manual_decision_request_hash' => ReadinessHash::stable($manualDecisionRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_request_real_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_notify_human',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_create_task',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_become_signature_authority',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_sign_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_authorize_later_cycle',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_reuse_prior_authorization',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_disable_execution_later_cycle_authorization_manual_decision_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $escalationReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization manual decision request template is ready as a provider-neutral non-dispatching manual decision request template. It still does not request a real decision, notify humans, create tasks, accept signatures, become signature authority, sign receipts, persist receipts, grant approval, authorize a later cycle, reuse prior authorization, write ledger, record decisions, execute disable, mutate writer state, create a writer, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle disable execution later-cycle authorization manual decision request template is blocked until fresh authorization new-cycle disable execution later-cycle authorization human escalation template is ready.',
        ];
    }
}
