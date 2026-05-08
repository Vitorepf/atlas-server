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
            'AP-242',
            'AP-243',
            'AP-244',
            'AP-245',
            'AP-246',
            'AP-247',
            'AP-248',
            'AP-249',
            'AP-250',
            'AP-251',
            'AP-252',
            'AP-253',
            'AP-254',
            'AP-255',
            'AP-256',
            'AP-257',
            'AP-258',
            'AP-259',
            'AP-260',
            'AP-261',
            'AP-262',
            'AP-263',
            'AP-264',
            'AP-265',
            'AP-266',
            'AP-267',
            'AP-268',
            'AP-269',
            'AP-270',
            'AP-271',
            'AP-272',
            'AP-273',
            'AP-274',
            'AP-275',
            'AP-276',
            'AP-277',
            'AP-278',
            'AP-279',
            'AP-280',
            'AP-281',
            'AP-282',
            'AP-283',
            'AP-284',
            'AP-285',
            'AP-286',
            'AP-287',
            'AP-288',
            'AP-289',
            'AP-290',
            'AP-291',
            'AP-292',
            'AP-293',
            'AP-294',
            'AP-295',
            'AP-296',
            'AP-297',
            'AP-298',
            'AP-299',
            'AP-300',
            'AP-301',
            'AP-302',
            'AP-303',
            'AP-304',
            'AP-305',
            'AP-306',
            'AP-307',
            'AP-308',
            'AP-309',
            'AP-310',
            'AP-311',
            'AP-312',
            'AP-313',
            'AP-314',
            'AP-315',
            'AP-316',
            'AP-317',
            'AP-318',
            'AP-319',
            'AP-320',
            'AP-321',
            'AP-322',
            'AP-323',
            'AP-324',
            'AP-325',
            'AP-326',
            'AP-327',
            'AP-328',
            'AP-329',
            'AP-330',
            'AP-331',
            'AP-332',
            'AP-333',
            'AP-334',
            'AP-335',
            'AP-336',
            'AP-337',
            'AP-338',
            'AP-339',
            'AP-340',
            'AP-341',
            'AP-342',
            'AP-343',
            'AP-344',
            'AP-345',
            'AP-346',
            'AP-347',
            'AP-348',
            'AP-349',
            'AP-350',
            'AP-351',
            'AP-352',
            'AP-353',
            'AP-354',
            'AP-355',
            'AP-356',
            'AP-357',
            'AP-358',
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
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
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
        $this->assertContains('runtime_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_implementation_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_implementation_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_implementation_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_handoff_ready_for_future_ledger_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_accepted_by_human', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_acceptance_reported', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'terminal_statuses.ready'));
        $this->assertContains('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_review', data_get($payload, 'terminal_statuses.ready'));
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
        $this->assertContains('blocked_by_runtime_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_implementation_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_implementation_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_implementation_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_implementation_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_implementation_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_envelope', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_review_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_decision', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_preflight', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.blocked'));
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
        $this->assertContains('runtime_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_implementation_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_implementation_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_implementation_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_implementation_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_implementation_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_shape', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_packet', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_changes_requested_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_rejected_by_human', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence_incomplete', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_shape', data_get($payload, 'terminal_statuses.attention'));
        $this->assertContains('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt', data_get($payload, 'terminal_statuses.attention'));
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
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionHandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionHandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionHandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp324DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp325DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp326HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp327Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp328DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp329DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp330HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp331Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp332DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp333DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp334HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp335Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp336DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp337DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp338HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp339Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp340DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp341DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp342HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp343Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp344DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp345DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp346HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp347Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp348DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp349DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp350HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp351Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp352DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp353DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp354HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp355Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp356DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp357DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp358HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(['AP-201', 'AP-202'], data_get($payload, 'steps.0.allowed_next_steps'));
        $this->assertSame(['proposal_count_greater_than_zero'], data_get($payload, 'steps.1.blocks_when'));
        $this->assertSame(['AP-205'], data_get($payload, 'steps.2.allowed_next_steps'));
        $this->assertSame(['validation_evidence_invalid_shape'], data_get($payload, 'steps.3.blocks_when'));
        $this->assertSame([], data_get($payload, 'steps.4.allowed_next_steps'));
    }
}
