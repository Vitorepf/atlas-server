<?php

namespace App\Services\Ai\SelfConstruction\Readiness\ReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * ReviewMerge pipeline sub-section 07 of 12, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls route through the injected
 * mother facade (`$this->parent->agentReviewMerge*`), which re-dispatches to
 * whichever sub-section owns the target stage.
 *
 * Stage range: agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate
 *           .. agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate
 */
final class ReviewMergePart07SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate(array $options = []): array
    {
        $disablePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options);
        $disableContract = (array) data_get($disablePayload, 'disable_contract', []);
        $disableReady = data_get($disablePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_contract_template_ready';
    
        $signals = [
            'fresh_authorization_execution_contract_loaded',
            'fresh_authorization_workspace_identity_rechecked',
            'fresh_authorization_obra_identity_rechecked',
            'fresh_authorization_provider_identity_rechecked',
            'fresh_authorization_provider_identity_substitution_detected',
            'fresh_authorization_cross_workspace_attempt_detected',
            'fresh_authorization_cross_obra_attempt_detected',
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
            'observability_contract_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-OBSERVABILITY-CONTRACT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($disableContract, 'workspace'),
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
                'fresh_authorization_workspace_identity_drift_count',
                'fresh_authorization_obra_identity_drift_count',
                'fresh_authorization_provider_identity_drift_count',
                'fresh_authorization_provider_identity_substitution_count',
                'fresh_authorization_cross_workspace_attempt_count',
                'fresh_authorization_cross_obra_attempt_count',
                'fresh_authorization_disable_trigger_count',
                'fresh_authorization_forbidden_merge_attempt_count',
                'fresh_authorization_forbidden_dispatch_attempt_count',
                'fresh_authorization_unexpected_ledger_write_attempt_count',
                'fresh_authorization_unexpected_receipt_persistence_attempt_count',
                'fresh_authorization_time_to_disable_ms',
            ],
            'required_alerts' => [
                'alert_on_fresh_authorization_contract_hash_drift',
                'alert_on_fresh_workspace_identity_drift',
                'alert_on_fresh_obra_identity_drift',
                'alert_on_fresh_provider_identity_drift',
                'alert_on_fresh_provider_identity_substitution',
                'alert_on_fresh_cross_workspace_execution_attempt',
                'alert_on_fresh_cross_obra_execution_attempt',
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
                'fresh_authorization_workspace_identity_evidence_hash',
                'fresh_authorization_obra_identity_evidence_hash',
                'fresh_authorization_provider_identity_evidence_hash',
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
                'writer_release_fresh_authorization_observability_workspace_identity_hash',
                'writer_release_fresh_authorization_observability_obra_identity_hash',
                'writer_release_fresh_authorization_observability_provider_identity_hash',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template.v1',
            'status' => $disableReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => $disableReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization observability contract template is ready as a provider-neutral Forge Workspace monitoring contract. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization observability contract template is blocked until fresh authorization disable contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options);
        $observabilityContract = (array) data_get($observabilityPayload, 'observability_contract', []);
        $observabilityReady = data_get($observabilityPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_observability_contract_template_ready';
    
        $allowedDecisions = [
            'keep_fresh_authorization_writer_disabled',
            'keep_fresh_authorization_writer_enabled_under_watch',
            'request_fresh_authorization_disable_execution',
            'request_new_fresh_authorization_cycle',
            'escalate_to_human_review',
        ];
    
        $reviewTemplate = [
            'review_template_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-POST-MONITORING-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($observabilityContract, 'workspace'),
            'status' => $observabilityReady ? 'ready_as_future_fresh_authorization_post_monitoring_review_template' : 'blocked_before_writer_release_fresh_authorization_observability_contract_template',
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($observabilityPayload, 'observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_preflight_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_execution_contract_preflight_hash'),
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_signed_receipt_template_hash'),
            'source_writer_release_fresh_authorization_post_signature_runbook_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_post_signature_runbook_hash'),
            'source_writer_release_fresh_authorization_signature_request_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_signature_request_hash'),
            'source_writer_release_fresh_authorization_request_hash' => data_get($observabilityContract, 'source_writer_release_fresh_authorization_request_hash'),
            'source_writer_release_reenable_review_packet_hash' => data_get($observabilityContract, 'source_writer_release_reenable_review_packet_hash'),
            'allowed_decisions' => $allowedDecisions,
            'allowed_decision_count' => count($allowedDecisions),
            'required_review_inputs' => [
                'writer_release_fresh_authorization_observability_contract_hash',
                'writer_release_fresh_authorization_observability_workspace_identity_hash',
                'writer_release_fresh_authorization_observability_obra_identity_hash',
                'writer_release_fresh_authorization_observability_provider_identity_hash',
                'writer_release_fresh_authorization_signal_manifest_hash',
                'writer_release_fresh_authorization_metrics_snapshot_hash',
                'writer_release_fresh_authorization_alert_policy_hash',
                'fresh_authorization_post_release_monitoring_window',
                'human_reviewer_identity',
            ],
            'health_checks' => [
                'fresh_authorization_monitoring_window_completed',
                'all_fresh_authorization_required_signals_present',
                'fresh_authorization_metrics_snapshot_present',
                'fresh_authorization_alert_policy_present',
                'fresh_authorization_workspace_identity_still_matches',
                'fresh_authorization_obra_identity_still_matches',
                'fresh_authorization_provider_identity_still_matches',
                'no_fresh_authorization_provider_identity_substitution',
                'no_fresh_authorization_cross_workspace_attempts',
                'no_fresh_authorization_cross_obra_attempts',
                'no_fresh_authorization_forbidden_merge_attempts',
                'no_fresh_authorization_forbidden_dispatch_attempts',
                'no_fresh_authorization_unexpected_ledger_writes',
                'no_fresh_authorization_unexpected_receipt_persistence',
                'fresh_authorization_disable_path_still_available',
                'human_review_completed',
            ],
            'failure_to_decision_map' => [
                'fresh_authorization_workspace_identity_drift' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_obra_identity_drift' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_provider_identity_drift' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_provider_identity_substitution' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_cross_workspace_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_cross_obra_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_forbidden_merge_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_forbidden_dispatch_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_unexpected_ledger_write_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_unexpected_receipt_persistence_attempt' => 'request_fresh_authorization_disable_execution',
                'fresh_authorization_missing_required_signal' => 'escalate_to_human_review',
                'fresh_authorization_monitoring_window_incomplete' => 'keep_fresh_authorization_writer_enabled_under_watch',
                'fresh_authorization_disable_path_unavailable' => 'escalate_to_human_review',
            ],
            'required_review_evidence' => [
                'fresh_authorization_review_actor_identity',
                'fresh_authorization_review_actor_provider',
                'fresh_authorization_review_actor_provider_identity_hash',
                'fresh_authorization_reviewed_at',
                'selected_decision',
                'decision_rationale',
                'fresh_authorization_health_check_result_hash',
                'fresh_authorization_workspace_identity_review_hash',
                'fresh_authorization_obra_identity_review_hash',
                'fresh_authorization_provider_identity_review_hash',
                'fresh_authorization_metrics_snapshot_hash',
                'fresh_authorization_alert_summary_hash',
                'fresh_authorization_disable_path_verification_hash',
            ],
            'future_review_outputs' => [
                'writer_release_fresh_authorization_post_monitoring_review_hash',
                'writer_release_fresh_authorization_post_monitoring_workspace_identity_hash',
                'writer_release_fresh_authorization_post_monitoring_obra_identity_hash',
                'writer_release_fresh_authorization_post_monitoring_provider_identity_hash',
                'writer_release_fresh_authorization_health_decision_hash',
                'writer_release_fresh_authorization_new_cycle_request_hash',
                'writer_release_fresh_authorization_disable_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_post_monitoring_review_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'ledger_write_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'signature_acceptance_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'signature_validation_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'receipt_persistence_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'decision_recording_by_writer_release_fresh_authorization_post_monitoring_review_template',
                'approval_from_writer_release_fresh_authorization_post_monitoring_review_template',
                'merge_from_writer_release_fresh_authorization_post_monitoring_review_template',
                'dispatch_from_writer_release_fresh_authorization_post_monitoring_review_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template.v1',
            'status' => $observabilityReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $observabilityReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization post-monitoring review template is ready as a provider-neutral Forge Workspace health review. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization post-monitoring review template is blocked until fresh authorization observability contract template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options);
        $reviewTemplate = (array) data_get($reviewPayload, 'review_template', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_ready';
    
        $allowedDecisionStates = [
            'keep_fresh_authorization_writer_disabled',
            'continue_fresh_authorization_watch',
            'request_fresh_authorization_disable_execution',
            'request_new_fresh_authorization_cycle',
            'escalate_to_human_review',
        ];
    
        $healthDecision = [
            'health_decision_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-HEALTH-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($reviewTemplate, 'workspace'),
            'status' => $reviewReady ? 'ready_as_future_fresh_authorization_health_decision_template' : 'blocked_before_writer_release_fresh_authorization_post_monitoring_review_template',
            'source_writer_release_fresh_authorization_post_monitoring_review_hash' => data_get($reviewPayload, 'review_template_hash'),
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'source_writer_release_fresh_authorization_signed_receipt_template_hash' => data_get($reviewTemplate, 'source_writer_release_fresh_authorization_signed_receipt_template_hash'),
            'allowed_decision_states' => $allowedDecisionStates,
            'allowed_decision_state_count' => count($allowedDecisionStates),
            'required_decision_evidence' => [
                'writer_release_fresh_authorization_post_monitoring_review_hash',
                'writer_release_fresh_authorization_post_monitoring_workspace_identity_hash',
                'writer_release_fresh_authorization_post_monitoring_obra_identity_hash',
                'writer_release_fresh_authorization_post_monitoring_provider_identity_hash',
                'selected_review_decision',
                'decision_actor_identity',
                'decision_actor_provider',
                'decision_actor_provider_identity_hash',
                'decision_rationale',
                'fresh_authorization_health_check_result_hash',
                'fresh_authorization_workspace_identity_review_hash',
                'fresh_authorization_obra_identity_review_hash',
                'fresh_authorization_provider_identity_review_hash',
                'fresh_authorization_metrics_snapshot_hash',
                'fresh_authorization_alert_summary_hash',
                'fresh_authorization_disable_path_verification_hash',
                'human_reviewer_identity',
            ],
            'decision_policy' => [
                'selected_review_decision_must_be_allowed',
                'workspace_identity_drift_forces_disable_request',
                'obra_identity_drift_forces_disable_request',
                'provider_identity_drift_forces_disable_request',
                'provider_identity_substitution_forces_disable_request',
                'cross_workspace_or_cross_obra_attempt_forces_disable_request',
                'forbidden_merge_or_dispatch_attempt_forces_disable_request',
                'unexpected_ledger_or_receipt_persistence_forces_disable_request',
                'missing_signal_or_incomplete_window_forces_watch_or_escalation',
                'new_fresh_authorization_cycle_requires_full_chain_restart',
                'human_reviewer_identity_required',
            ],
            'future_decision_outputs' => [
                'writer_release_fresh_authorization_health_decision_hash',
                'writer_release_fresh_authorization_health_decision_workspace_identity_hash',
                'writer_release_fresh_authorization_health_decision_obra_identity_hash',
                'writer_release_fresh_authorization_health_decision_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_request_hash',
                'writer_release_fresh_authorization_disable_request_hash',
                'writer_release_fresh_authorization_human_escalation_hash',
            ],
            'still_forbidden_by_fresh_authorization_health_decision_template' => [
                'writer_file_creation_by_writer_release_fresh_authorization_health_decision_template',
                'ledger_write_by_writer_release_fresh_authorization_health_decision_template',
                'signature_acceptance_by_writer_release_fresh_authorization_health_decision_template',
                'signature_validation_by_writer_release_fresh_authorization_health_decision_template',
                'receipt_persistence_by_writer_release_fresh_authorization_health_decision_template',
                'decision_recording_by_writer_release_fresh_authorization_health_decision_template',
                'approval_from_writer_release_fresh_authorization_health_decision_template',
                'merge_from_writer_release_fresh_authorization_health_decision_template',
                'dispatch_from_writer_release_fresh_authorization_health_decision_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization health decision template is ready as a provider-neutral Forge Workspace non-recording decision template. It still does not create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization health decision template is blocked until fresh authorization post-monitoring review template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate(array $options = []): array
    {
        $decisionPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
        $healthDecision = (array) data_get($decisionPayload, 'health_decision', []);
        $decisionReady = data_get($decisionPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_ready';
    
        $disableTriggers = [
            'selected_decision_request_fresh_authorization_disable_execution',
            'fresh_authorization_provider_identity_drift_after_reenable',
            'fresh_authorization_provider_identity_substitution_after_reenable',
            'fresh_authorization_cross_workspace_or_cross_obra_attempt_after_reenable',
            'fresh_authorization_forbidden_merge_attempt_after_reenable',
            'fresh_authorization_forbidden_dispatch_attempt_after_reenable',
            'fresh_authorization_unexpected_ledger_write_after_reenable',
            'fresh_authorization_unexpected_receipt_persistence_after_reenable',
            'fresh_authorization_disable_path_compromised_or_unavailable',
        ];
    
        $disableRequest = [
            'disable_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-DISABLE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($healthDecision, 'workspace'),
            'status' => $decisionReady ? 'ready_as_future_fresh_authorization_disable_request_template' : 'blocked_before_writer_release_fresh_authorization_health_decision_template',
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($decisionPayload, 'health_decision_hash'),
            'source_writer_release_fresh_authorization_post_monitoring_review_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_post_monitoring_review_hash'),
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'disable_triggers' => $disableTriggers,
            'trigger_count' => count($disableTriggers),
            'required_disable_request_evidence' => [
                'writer_release_fresh_authorization_health_decision_hash',
                'selected_health_decision_state',
                'disable_trigger',
                'disable_request_actor_identity',
                'disable_request_actor_provider',
                'disable_request_actor_provider_identity_hash',
                'disable_request_workspace_identity_hash',
                'disable_request_obra_identity_hash',
                'disable_request_rationale',
                'fresh_authorization_disable_path_verification_hash',
                'fresh_authorization_provider_identity_drift_evidence_hash',
                'fresh_authorization_cross_workspace_or_cross_obra_evidence_hash',
                'fresh_authorization_forbidden_action_evidence_hash',
                'human_reviewer_identity',
            ],
            'disable_request_policy' => [
                'disable_request_requires_health_decision_hash',
                'disable_request_requires_allowed_trigger',
                'disable_request_must_reference_existing_disable_contract_template',
                'disable_request_must_preserve_workspace_identity',
                'disable_request_must_preserve_obra_identity',
                'disable_request_must_preserve_provider_identity',
                'provider_identity_drift_requires_disable_request',
                'provider_identity_substitution_requires_disable_request',
                'cross_workspace_or_cross_obra_attempt_requires_disable_request',
                'disable_request_does_not_execute_disable',
                'disable_request_does_not_mutate_writer_state',
                'disable_request_does_not_record_decision',
            ],
            'future_disable_request_outputs' => [
                'writer_release_fresh_authorization_disable_request_hash',
                'writer_release_fresh_authorization_disable_request_workspace_identity_hash',
                'writer_release_fresh_authorization_disable_request_obra_identity_hash',
                'writer_release_fresh_authorization_disable_request_provider_identity_hash',
                'writer_release_fresh_authorization_disable_execution_preflight_hash',
                'writer_release_fresh_authorization_disable_execution_receipt_hash',
                'writer_release_fresh_authorization_disable_evidence_hash',
            ],
            'still_forbidden_by_fresh_authorization_disable_request_template' => [
                'disable_execution_by_writer_release_fresh_authorization_disable_request_template',
                'writer_state_mutation_by_writer_release_fresh_authorization_disable_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_disable_request_template',
                'ledger_write_by_writer_release_fresh_authorization_disable_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_disable_request_template',
                'signature_validation_by_writer_release_fresh_authorization_disable_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_disable_request_template',
                'decision_recording_by_writer_release_fresh_authorization_disable_request_template',
                'approval_from_writer_release_fresh_authorization_disable_request_template',
                'merge_from_writer_release_fresh_authorization_disable_request_template',
                'dispatch_from_writer_release_fresh_authorization_disable_request_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template.v1',
            'status' => $decisionReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template',
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
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_execute_disable',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_mutate_writer_state',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_approve_code',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $decisionReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization disable request template is ready as a provider-neutral non-executing disable request template. It still does not execute disable, mutate writer state, create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization disable request template is blocked until fresh authorization health decision template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate(array $options = []): array
    {
        $decisionPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
        $healthDecision = (array) data_get($decisionPayload, 'health_decision', []);
        $decisionReady = data_get($decisionPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_ready';
    
        $requiredRestartRequirements = [
            'new_cycle_requires_new_authorization_request_hash',
            'new_cycle_requires_new_receipt_draft_hash',
            'new_cycle_requires_new_signature_request_hash',
            'new_cycle_requires_new_signed_receipt_hash',
            'new_cycle_requires_new_execution_contract_preflight_hash',
            'new_cycle_requires_new_execution_contract_hash',
            'new_cycle_requires_new_disable_contract_hash',
            'new_cycle_requires_new_observability_contract_hash',
            'new_cycle_requires_new_post_monitoring_review_hash',
        ];
    
        $newCycleRequest = [
            'new_cycle_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($healthDecision, 'workspace'),
            'status' => $decisionReady ? 'ready_as_future_fresh_authorization_new_cycle_request_template' : 'blocked_before_writer_release_fresh_authorization_health_decision_template',
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($decisionPayload, 'health_decision_hash'),
            'source_writer_release_fresh_authorization_post_monitoring_review_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_post_monitoring_review_hash'),
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($healthDecision, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'required_restart_requirements' => $requiredRestartRequirements,
            'requirement_count' => count($requiredRestartRequirements),
            'required_new_cycle_evidence' => [
                'writer_release_fresh_authorization_health_decision_hash',
                'selected_health_decision_state',
                'new_cycle_request_actor_identity',
                'new_cycle_request_actor_provider',
                'new_cycle_request_actor_provider_identity_hash',
                'new_cycle_request_workspace_identity_hash',
                'new_cycle_request_obra_identity_hash',
                'new_cycle_request_rationale',
                'previous_cycle_summary_hash',
                'previous_cycle_failure_or_watch_result_hash',
                'previous_cycle_provider_identity_hash',
                'human_reviewer_identity',
            ],
            'reuse_forbidden' => [
                'reuse_previous_fresh_authorization_request_hash',
                'reuse_previous_fresh_authorization_receipt_draft_hash',
                'reuse_previous_fresh_authorization_signature_request_hash',
                'reuse_previous_fresh_authorization_signed_receipt_hash',
                'reuse_previous_fresh_authorization_execution_contract_hash',
                'reuse_previous_fresh_authorization_disable_contract_hash',
                'reuse_previous_fresh_authorization_observability_contract_hash',
                'reuse_previous_fresh_authorization_provider_identity_hash',
            ],
            'new_cycle_request_policy' => [
                'new_cycle_request_requires_health_decision_hash',
                'new_cycle_request_requires_selected_decision_request_new_cycle',
                'new_cycle_request_must_restart_entire_authorization_chain',
                'new_cycle_request_must_preserve_workspace_identity',
                'new_cycle_request_must_preserve_obra_identity',
                'new_cycle_request_must_revalidate_provider_identity',
                'new_cycle_request_does_not_grant_approval',
                'new_cycle_request_does_not_accept_or_validate_signature',
                'new_cycle_request_does_not_create_writer_file',
            ],
            'future_new_cycle_outputs' => [
                'writer_release_fresh_authorization_new_cycle_request_hash',
                'writer_release_fresh_authorization_new_cycle_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_authorization_request_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_signature_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_request_template' => [
                'approval_by_writer_release_fresh_authorization_new_cycle_request_template',
                'authorization_reuse_by_writer_release_fresh_authorization_new_cycle_request_template',
                'provider_identity_reuse_without_revalidation_by_writer_release_fresh_authorization_new_cycle_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_request_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_request_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template.v1',
            'status' => $decisionReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template',
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
            'new_cycle_request' => $newCycleRequest,
            'new_cycle_request_hash' => ReadinessHash::stable($newCycleRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_reuse_authorization',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_reuse_provider_identity_without_revalidation',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $decisionReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle request template is ready as a provider-neutral non-authorizing restart request. It still does not reuse authority, accept signatures, create a writer, write ledger, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle request template is blocked until fresh authorization health decision template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate(array $options = []): array
    {
        $newCyclePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate($options);
        $newCycleRequest = (array) data_get($newCyclePayload, 'new_cycle_request', []);
        $newCycleReady = data_get($newCyclePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_request_template_ready';
    
        $requiredAuthorizationEvidence = [
            'writer_release_fresh_authorization_new_cycle_request_hash',
            'writer_release_fresh_authorization_new_cycle_workspace_identity_hash',
            'writer_release_fresh_authorization_new_cycle_obra_identity_hash',
            'writer_release_fresh_authorization_new_cycle_provider_identity_hash',
            'new_cycle_authorization_actor_identity',
            'new_cycle_authorization_actor_provider',
            'new_cycle_authorization_actor_provider_identity_hash',
            'new_cycle_authorization_rationale',
            'new_cycle_scope_revalidation_hash',
            'new_cycle_required_restart_requirements_hash',
            'human_reviewer_identity',
        ];
    
        $authorizationRequest = [
            'authorization_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-AUTHORIZATION-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($newCycleRequest, 'workspace'),
            'status' => $newCycleReady ? 'ready_as_future_fresh_authorization_new_cycle_authorization_request_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_request_template',
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($newCyclePayload, 'new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($newCycleRequest, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'source_writer_release_fresh_authorization_post_monitoring_review_hash' => data_get($newCycleRequest, 'source_writer_release_fresh_authorization_post_monitoring_review_hash'),
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($newCycleRequest, 'source_writer_release_fresh_authorization_observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($newCycleRequest, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($newCycleRequest, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'required_authorization_evidence' => $requiredAuthorizationEvidence,
            'required_evidence_count' => count($requiredAuthorizationEvidence),
            'authorization_request_policy' => [
                'authorization_request_requires_new_cycle_request_hash',
                'authorization_request_requires_workspace_identity_revalidation',
                'authorization_request_requires_obra_identity_revalidation',
                'authorization_request_requires_provider_identity_revalidation',
                'authorization_request_must_not_reuse_previous_cycle_authority',
                'authorization_request_does_not_accept_signature',
                'authorization_request_does_not_validate_signature',
                'authorization_request_does_not_grant_approval',
                'authorization_request_does_not_create_writer_file',
            ],
            'reuse_forbidden' => [
                'reuse_previous_cycle_authorization_request_hash',
                'reuse_previous_cycle_receipt_draft_hash',
                'reuse_previous_cycle_signature_request_hash',
                'reuse_previous_cycle_signed_receipt_hash',
                'reuse_previous_cycle_execution_contract_hash',
                'reuse_previous_cycle_provider_identity_hash',
            ],
            'fresh_cycle_boundaries' => [
                'previous_cycle_hashes_are_context_only',
                'new_cycle_authorization_request_must_reference_new_cycle_request_hash',
                'new_cycle_receipt_must_be_drafted_after_this_authorization_request',
                'new_cycle_signature_request_must_reference_new_cycle_receipt_draft',
                'new_cycle_execution_contract_must_reference_new_cycle_signed_receipt_template',
            ],
            'future_authorization_outputs' => [
                'writer_release_fresh_authorization_new_cycle_authorization_request_hash',
                'writer_release_fresh_authorization_new_cycle_authorization_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_authorization_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_authorization_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_signature_request_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_authorization_request_template' => [
                'approval_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'authorization_reuse_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'provider_identity_reuse_without_revalidation_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_authorization_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_authorization_request_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template.v1',
            'status' => $newCycleReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template',
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
            'authorization_request' => $authorizationRequest,
            'authorization_request_hash' => ReadinessHash::stable($authorizationRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_reuse_authorization',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_reuse_provider_identity_without_revalidation',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $newCycleReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle authorization request template is ready as a provider-neutral non-approving authorization request. It still does not reuse authority, accept signatures, create a writer, write ledger, approve, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle authorization request template is blocked until fresh authorization new-cycle request template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate(array $options = []): array
    {
        $authorizationPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate($options);
        $authorizationRequest = (array) data_get($authorizationPayload, 'authorization_request', []);
        $authorizationReady = data_get($authorizationPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_authorization_request_template_ready';
    
        $requiredReceiptFields = [
            'new_cycle_authorization_request_hash',
            'receipt_subject',
            'receipt_scope',
            'required_signer_roles',
            'fresh_workspace_identity_statement',
            'fresh_obra_identity_statement',
            'fresh_provider_identity_statement',
            'fresh_cycle_boundary_statement',
            'forbidden_reuse_statement',
            'non_execution_statement',
            'rollback_and_disable_reference',
        ];
    
        $receiptDraft = [
            'receipt_draft_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-RECEIPT-DRAFT-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($authorizationRequest, 'workspace'),
            'status' => $authorizationReady ? 'ready_as_future_fresh_authorization_new_cycle_receipt_draft_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_authorization_request_template',
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($authorizationPayload, 'authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'source_writer_release_fresh_authorization_post_monitoring_review_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_post_monitoring_review_hash'),
            'source_writer_release_fresh_authorization_observability_contract_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_observability_contract_hash'),
            'source_writer_release_fresh_authorization_disable_contract_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_disable_contract_hash'),
            'source_writer_release_fresh_authorization_execution_contract_hash' => data_get($authorizationRequest, 'source_writer_release_fresh_authorization_execution_contract_hash'),
            'required_receipt_fields' => $requiredReceiptFields,
            'required_receipt_field_count' => count($requiredReceiptFields),
            'receipt_subject' => 'future_provider_neutral_fresh_authorization_new_cycle_writer_release',
            'required_receipt_evidence' => [
                'writer_release_fresh_authorization_new_cycle_authorization_request_hash',
                'writer_release_fresh_authorization_new_cycle_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_hash',
                'new_cycle_authorization_actor_identity',
                'new_cycle_authorization_actor_provider_identity_hash',
                'new_cycle_required_restart_requirements_hash',
                'previous_cycle_context_hash',
                'human_reviewer_identity',
            ],
            'receipt_policy' => [
                'receipt_draft_requires_authorization_request_hash',
                'receipt_draft_requires_workspace_identity_hash',
                'receipt_draft_requires_obra_identity_hash',
                'receipt_draft_requires_provider_identity_hash',
                'receipt_draft_must_be_unsigned',
                'receipt_draft_must_not_be_persisted',
                'receipt_draft_must_not_accept_signature',
                'receipt_draft_must_not_validate_signature',
                'receipt_draft_must_not_create_writer_file',
                'receipt_draft_must_not_grant_approval',
            ],
            'future_receipt_outputs' => [
                'writer_release_fresh_authorization_new_cycle_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_signature_request_hash',
                'writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_receipt_draft_template' => [
                'receipt_signing_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'approval_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template.v1',
            'status' => $authorizationReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
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
            'receipt_draft' => $receiptDraft,
            'receipt_draft_hash' => ReadinessHash::stable($receiptDraft),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_sign_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_does_not_dispatch_work',
            ],
            'human_summary' => $authorizationReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle receipt draft template is ready as a provider-neutral unsigned, non-persisted receipt draft. It still does not sign, persist, approve, create a writer, write ledger, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle receipt draft template is blocked until fresh authorization new-cycle authorization request template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate(array $options = []): array
    {
        $receiptPayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate($options);
        $receiptDraft = (array) data_get($receiptPayload, 'receipt_draft', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_receipt_draft_template_ready';
    
        $requiredSigners = [
            'atlas_operator',
            'self_construction_governance_reviewer',
            'writer_release_safety_reviewer',
        ];
    
        $signatureRequest = [
            'signature_request_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-SIGNATURE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($receiptDraft, 'workspace'),
            'status' => $receiptReady ? 'ready_as_future_fresh_authorization_new_cycle_signature_request_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_receipt_draft_template',
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($receiptPayload, 'receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($receiptDraft, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'required_signers' => $requiredSigners,
            'required_signer_count' => count($requiredSigners),
            'signature_payload_fields' => [
                'new_cycle_receipt_draft_hash',
                'new_cycle_workspace_identity_hash',
                'new_cycle_obra_identity_hash',
                'new_cycle_provider_identity_hash',
                'signer_role',
                'signer_identity',
                'signature_timestamp',
                'signature_purpose',
                'non_execution_acknowledgement',
                'fresh_cycle_boundary_acknowledgement',
            ],
            'required_signature_request_evidence' => [
                'writer_release_fresh_authorization_new_cycle_receipt_draft_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_receipt_provider_identity_hash',
                'receipt_subject',
                'required_signer_manifest_hash',
                'signature_request_actor_identity',
                'signature_request_actor_provider_identity_hash',
                'human_reviewer_identity',
            ],
            'signature_request_policy' => [
                'signature_request_requires_receipt_draft_hash',
                'signature_request_requires_workspace_identity_hash',
                'signature_request_requires_obra_identity_hash',
                'signature_request_requires_provider_identity_hash',
                'signature_request_requires_all_required_signers',
                'signature_request_does_not_accept_signature',
                'signature_request_does_not_validate_signature',
                'signature_request_does_not_persist_receipt',
                'signature_request_does_not_grant_approval',
            ],
            'future_signature_outputs' => [
                'writer_release_fresh_authorization_new_cycle_signature_request_hash',
                'writer_release_fresh_authorization_new_cycle_signature_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_signature_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_signature_provider_identity_hash',
                'writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_signature_request_template' => [
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'approval_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_signature_request_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_signature_request_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template.v1',
            'status' => $receiptReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template',
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
            'signature_request' => $signatureRequest,
            'signature_request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle signature request template is ready as a provider-neutral non-accepting signature request. It still does not accept or validate signatures, persist receipts, approve, create a writer, write ledger, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle signature request template is blocked until fresh authorization new-cycle receipt draft template is ready.',
        ];
    }
    
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate(array $options = []): array
    {
        $signaturePayload = $this->parent->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signatureRequestReady = data_get($signaturePayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_signature_request_template_ready';
    
        $runbookSteps = [
            'collect_future_new_cycle_signatures',
            'verify_all_required_signer_roles_present',
            'verify_signature_payload_references_new_cycle_receipt_draft_hash',
            'verify_new_cycle_workspace_identity_hash',
            'verify_new_cycle_obra_identity_hash',
            'verify_new_cycle_provider_identity_hash',
            'verify_no_previous_cycle_authority_reused',
            'prepare_new_cycle_signed_receipt_template',
            'prepare_new_cycle_execution_contract_preflight_template',
            'prepare_new_cycle_disable_contract_template',
            'prepare_new_cycle_observability_contract_template',
        ];
    
        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-NEW-CYCLE-POST-SIGNATURE-RUNBOOK-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => data_get($signatureRequest, 'workspace'),
            'status' => $signatureRequestReady ? 'ready_as_future_fresh_authorization_new_cycle_post_signature_runbook_template' : 'blocked_before_writer_release_fresh_authorization_new_cycle_signature_request_template',
            'source_writer_release_fresh_authorization_new_cycle_signature_request_hash' => data_get($signaturePayload, 'signature_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_new_cycle_receipt_draft_hash'),
            'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_new_cycle_authorization_request_hash'),
            'source_writer_release_fresh_authorization_new_cycle_request_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_new_cycle_request_hash'),
            'source_writer_release_fresh_authorization_health_decision_hash' => data_get($signatureRequest, 'source_writer_release_fresh_authorization_health_decision_hash'),
            'runbook_steps' => $runbookSteps,
            'step_count' => count($runbookSteps),
            'required_runbook_evidence' => [
                'writer_release_fresh_authorization_new_cycle_signature_request_hash',
                'writer_release_fresh_authorization_new_cycle_workspace_identity_hash',
                'writer_release_fresh_authorization_new_cycle_obra_identity_hash',
                'writer_release_fresh_authorization_new_cycle_provider_identity_hash',
                'future_signature_bundle_hash',
                'required_signer_manifest_hash',
                'signature_payload_integrity_hash',
                'no_previous_cycle_authority_reuse_evidence_hash',
                'human_reviewer_identity',
            ],
            'runbook_policy' => [
                'runbook_requires_signature_request_hash',
                'runbook_requires_fresh_workspace_identity_hash',
                'runbook_requires_fresh_obra_identity_hash',
                'runbook_requires_fresh_provider_identity_hash',
                'runbook_requires_all_future_signatures_before_signed_receipt_template',
                'runbook_does_not_accept_signature',
                'runbook_does_not_validate_signature',
                'runbook_does_not_persist_receipt',
                'runbook_does_not_grant_approval',
                'runbook_does_not_create_writer_file',
            ],
            'future_runbook_outputs' => [
                'writer_release_fresh_authorization_new_cycle_post_signature_runbook_hash',
                'writer_release_fresh_authorization_new_cycle_signed_receipt_template_hash',
                'writer_release_fresh_authorization_new_cycle_execution_contract_preflight_hash',
                'writer_release_fresh_authorization_new_cycle_disable_contract_hash',
                'writer_release_fresh_authorization_new_cycle_observability_contract_hash',
            ],
            'still_forbidden_by_fresh_authorization_new_cycle_post_signature_runbook_template' => [
                'signature_acceptance_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'signature_validation_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'receipt_persistence_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'approval_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'writer_file_creation_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'ledger_write_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'decision_recording_by_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'merge_from_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
                'dispatch_from_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
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
            'schema_version' => 'atlas.self_construction_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template.v1',
            'status' => $signatureRequestReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_blocked',
            'mode' => 'read_only_provider_neutral_agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template',
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
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_claim_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_complete_packets',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_accept_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_validate_signature',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_persist_receipt',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_grant_approval',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_create_writer_file',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_write_ledger',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_record_decision',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_merge',
                'agent_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_new_cycle_post_signature_runbook_template_does_not_dispatch_work',
            ],
            'human_summary' => $signatureRequestReady
                ? 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle post-signature runbook template is ready as a provider-neutral non-validating runbook. It still does not accept or validate signatures, persist receipts, approve, create a writer, write ledger, merge or dispatch.'
                : 'Agent review merge post-execution action signed receipt persistence writer release fresh authorization new-cycle post-signature runbook template is blocked until fresh authorization new-cycle signature request template is ready.',
        ];
    }
}
