<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 06 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate
 *           .. codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate
 */
final class CodexReviewMergePart06SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate(array $options = []): array
    {
        $observabilityPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options);
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
            'review_template_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-POST-MONITORING-REVIEW-TEMPLATE-SELF-CONSTRUCTION-0001',
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
                'no_fresh_authorization_forbidden_merge_attempts',
                'no_fresh_authorization_forbidden_dispatch_attempts',
                'no_fresh_authorization_unexpected_ledger_writes',
                'no_fresh_authorization_unexpected_receipt_persistence',
                'fresh_authorization_disable_path_still_available',
                'human_review_completed',
            ],
            'failure_to_decision_map' => [
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
                'fresh_authorization_reviewed_at',
                'selected_decision',
                'decision_rationale',
                'fresh_authorization_health_check_result_hash',
                'fresh_authorization_metrics_snapshot_hash',
                'fresh_authorization_alert_summary_hash',
                'fresh_authorization_disable_path_verification_hash',
            ],
            'future_review_outputs' => [
                'writer_release_fresh_authorization_post_monitoring_review_hash',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template.v1',
            'status' => $observabilityReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_post_monitoring_review_template_does_not_dispatch_work',
            ],
            'human_summary' => $observabilityReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization post-monitoring review template is ready as a non-authorizing health review. It still does not create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization post-monitoring review template is blocked until fresh authorization observability contract template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options);
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
            'health_decision_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-HEALTH-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
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
                'selected_review_decision',
                'decision_actor_identity',
                'decision_rationale',
                'fresh_authorization_health_check_result_hash',
                'fresh_authorization_metrics_snapshot_hash',
                'fresh_authorization_alert_summary_hash',
                'fresh_authorization_disable_path_verification_hash',
                'human_reviewer_identity',
            ],
            'decision_policy' => [
                'selected_review_decision_must_be_allowed',
                'forbidden_merge_or_dispatch_attempt_forces_disable_request',
                'unexpected_ledger_or_receipt_persistence_forces_disable_request',
                'missing_signal_or_incomplete_window_forces_watch_or_escalation',
                'new_fresh_authorization_cycle_requires_full_chain_restart',
                'human_reviewer_identity_required',
            ],
            'future_decision_outputs' => [
                'writer_release_fresh_authorization_health_decision_hash',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template.v1',
            'status' => $reviewReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization health decision template is ready as a non-recording decision template. It still does not create a writer, write ledger, persist receipts, record decisions, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization health decision template is blocked until fresh authorization post-monitoring review template is ready.',
        ];
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate(array $options = []): array
    {
        $decisionPayload = $this->parent->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
        $healthDecision = (array) data_get($decisionPayload, 'health_decision', []);
        $decisionReady = data_get($decisionPayload, 'status') === 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_health_decision_template_ready';

        $disableTriggers = [
            'selected_decision_request_fresh_authorization_disable_execution',
            'fresh_authorization_forbidden_merge_attempt_after_reenable',
            'fresh_authorization_forbidden_dispatch_attempt_after_reenable',
            'fresh_authorization_unexpected_ledger_write_after_reenable',
            'fresh_authorization_unexpected_receipt_persistence_after_reenable',
            'fresh_authorization_disable_path_compromised_or_unavailable',
        ];

        $disableRequest = [
            'disable_request_id' => 'CODEX-REVIEW-MERGE-POST-EXECUTION-ACTION-SIGNED-RECEIPT-PERSISTENCE-WRITER-RELEASE-FRESH-AUTHORIZATION-DISABLE-REQUEST-TEMPLATE-SELF-CONSTRUCTION-0001',
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
                'disable_request_rationale',
                'fresh_authorization_disable_path_verification_hash',
                'fresh_authorization_forbidden_action_evidence_hash',
                'human_reviewer_identity',
            ],
            'disable_request_policy' => [
                'disable_request_requires_health_decision_hash',
                'disable_request_requires_allowed_trigger',
                'disable_request_must_reference_existing_disable_contract_template',
                'disable_request_does_not_execute_disable',
                'disable_request_does_not_mutate_writer_state',
                'disable_request_does_not_record_decision',
            ],
            'future_disable_request_outputs' => [
                'writer_release_fresh_authorization_disable_request_hash',
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
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template.v1',
            'status' => $decisionReady ? 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_ready' : 'merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_blocked',
            'mode' => 'read_only_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template',
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
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_claim_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_complete_packets',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_execute_disable',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_mutate_writer_state',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_create_writer_file',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_accept_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_validate_signature',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_write_ledger',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_persist_receipt',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_record_decision',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_approve_code',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_merge',
                'codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_fresh_authorization_disable_request_template_does_not_dispatch_work',
            ],
            'human_summary' => $decisionReady
                ? 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization disable request template is ready as a non-executing disable request template. It still does not execute disable, mutate writer state, create a writer, write ledger, persist receipts, approve, merge or dispatch.'
                : 'Codex review merge post-execution action signed receipt persistence writer release fresh authorization disable request template is blocked until fresh authorization health decision template is ready.',
        ];
    }
}
