<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowRegistry;
use Tests\TestCase;

final class AtlasApAgentWorkflowRegistryTest extends TestCase
{
    public function test_workflow_registry_declares_agent_ap_work_sequence_without_execution(): void
    {
        $payload = app(AtlasApAgentWorkflowRegistry::class)->workflow();

        $this->assertSame('atlas.ap_agent_workflow_registry.v1', $payload['schema_version']);
        $this->assertSame('implemented', $payload['status']);
        $this->assertSame('read_only_workflow_registry', $payload['mode']);
        $this->assertSame('ap_agent_workflow_registry_only_no_execution', $payload['authority']);
        $this->assertSame('ap_agent_documented_work_session', $payload['workflow_id']);
        $this->assertSame(5, $payload['step_count']);
        $this->assertSame(['AP-200', 'AP-201', 'AP-202', 'AP-205', 'AP-203'], array_column($payload['steps'], 'ap'));
        $this->assertSame([
            'AP-206',
            'AP-207',
            'AP-208',
            'AP-209',
            'AP-210',
            'AP-211',
            'AP-212',
            'AP-213',
            'AP-214',
            'AP-215',
            'AP-216',
            'AP-217',
            'AP-218',
            'AP-219',
            'AP-220',
            'AP-221',
            'AP-222',
            'AP-223',
            'AP-224',
            'AP-225',
            'AP-226',
            'AP-227',
            'AP-228',
            'AP-229',
            'AP-230',
            'AP-231',
            'AP-232',
            'AP-233',
            'AP-234',
            'AP-235',
            'AP-236',
            'AP-237',
            'AP-238',
            'AP-239',
            'AP-240',
            'AP-241',
        ], array_column($payload['post_completion_review_chain'], 'ap'));
        $this->assertSame([
            'AtlasApAgentHandoffPacket',
            'AtlasApGovernanceRepairProposalContract',
            'AtlasApAgentSessionGate',
            'AtlasApValidationEvidenceContract',
            'AtlasApAgentCompletionReport',
        ], array_column($payload['steps'], 'component'));
        $this->assertSame('AtlasApAgentHandoffPacket', data_get($payload, 'handoff_summary.start_with'));
        $this->assertSame('AtlasApValidationEvidenceContract', data_get($payload, 'handoff_summary.validate_before_completion'));
        $this->assertSame('AtlasApAgentCompletionReport', data_get($payload, 'handoff_summary.finish_with'));
        $this->assertSame('AtlasApAgentWorkflowTraceAudit', data_get($payload, 'handoff_summary.audit_trace_with'));
        $this->assertSame('AtlasApAgentWorkflowCloseoutAcceptanceReceipt', data_get($payload, 'handoff_summary.closeout_with'));
        $this->assertSame('AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract', data_get($payload, 'handoff_summary.review_dry_run_plan_with'));
        $this->assertSame('AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract', data_get($payload, 'handoff_summary.seal_dry_run_result_with'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.evaluates_runtime_state'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_parallel_flow'));
    }

    public function test_workflow_registry_declares_blocking_and_validation_contracts(): void
    {
        $payload = app(AtlasApAgentWorkflowRegistry::class)->workflow();

        $this->assertContains('blocked_by_documentation_repair', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_session_gate', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_validation_evidence_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_trace_audit', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_closeout_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('ready_for_existing_ap_work', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('complete', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('closeout_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_release_evidence_owner_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('release_evidence_candidate_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_future_release_or_ledger_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_future_dry_run_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('dry_run_result_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('post_dry_run_handoff_ready_for_future_release_or_ledger_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('consumer_readiness_ready_for_future_release_or_ledger_decision', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('consumer_readiness_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('consumer_readiness_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_execution_authorization_decision', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_authorization_approved_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_authorization_approval_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_authorization_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_execution_implementation_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_implementation_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_implementation_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_execution_activation_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_activation_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('execution_activation_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('blocked_by_consumer_readiness_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_consumer_readiness_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_consumer_readiness_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_consumer_readiness_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_authorization_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_authorization_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_authorization_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_authorization_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_authorization_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_authorization_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_authorization_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_implementation_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_implementation_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_implementation_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_implementation_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_implementation_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_activation_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_activation_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_execution_activation_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_activation_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_execution_activation_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('consumer_readiness_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('consumer_readiness_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('consumer_readiness_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('consumer_readiness_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_authorization_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_implementation_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_implementation_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_implementation_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_implementation_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_implementation_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_activation_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_activation_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_activation_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_activation_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('execution_activation_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_by_post_dry_run_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('consumer_readiness_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('consumer_readiness_ready_for_future_release_or_ledger_decision', data_get($payload, 'terminal_statuses.ready'));
        $this->assertSame('git diff --check', data_get($payload, 'required_validation_commands.diff_check'));
        $this->assertSame(
            'atlas engineering knowledge docs-health',
            data_get($payload, 'required_validation_commands.docs_health'),
        );
        $this->assertSame(
            'php artisan atlas:ai:architecture-readiness --json',
            data_get($payload, 'required_validation_commands.architecture_readiness'),
        );
        $this->assertSame(
            'atlas engineering knowledge index-code --prune',
            data_get($payload, 'required_validation_commands.code_intelligence_index'),
        );
        $this->assertSame(
            'php artisan atlas:ai:architecture-validate --json',
            data_get($payload, 'required_validation_commands.architecture_validate'),
        );
        $this->assertSame(['AP-201', 'AP-202'], data_get($payload, 'steps.0.allowed_next_steps'));
        $this->assertSame(['proposal_count_greater_than_zero'], data_get($payload, 'steps.1.blocks_when'));
        $this->assertSame(['AP-205'], data_get($payload, 'steps.2.allowed_next_steps'));
        $this->assertSame(['validation_evidence_invalid_shape'], data_get($payload, 'steps.3.blocks_when'));
        $this->assertSame([], data_get($payload, 'steps.4.allowed_next_steps'));
    }
}
