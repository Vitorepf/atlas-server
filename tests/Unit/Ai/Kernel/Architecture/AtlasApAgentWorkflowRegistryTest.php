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
        $this->assertSame('atlas.ap_agent_workflow_summary.v1', data_get($payload, 'summary.schema_version'));
        $this->assertSame(5, data_get($payload, 'summary.primary_trace.step_count'));
        $this->assertSame(['AP-200', 'AP-201', 'AP-202', 'AP-205', 'AP-203'], data_get($payload, 'summary.primary_trace.aps'));
        $this->assertSame('AP-206', data_get($payload, 'summary.post_completion_review_chain.first_ap'));
        $this->assertSame('AP-682', data_get($payload, 'summary.post_completion_review_chain.last_ap'));
        $this->assertSame('AP-228', data_get($payload, 'summary.post_completion_review_chain.human_display_until_ap'));
        $this->assertSame(23, data_get($payload, 'summary.post_completion_review_chain.human_display_count'));
        $this->assertTrue(data_get($payload, 'summary.machine_output.full_chain_included'));
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
            'AP-359',
            'AP-360',
            'AP-361',
            'AP-362',
            'AP-363',
            'AP-364',
            'AP-365',
            'AP-366',
            'AP-367',
            'AP-368',
            'AP-369',
            'AP-370',
            'AP-371',
            'AP-372',
            'AP-373',
            'AP-374',
            'AP-375',
            'AP-376',
            'AP-377',
            'AP-378',
            'AP-379',
            'AP-380',
            'AP-381',
            'AP-382',
            'AP-383',
            'AP-384',
            'AP-385',
            'AP-386',
            'AP-387',
            'AP-388',
            'AP-389',
            'AP-390',
            'AP-391',
            'AP-392',
            'AP-393',
            'AP-394',
            'AP-395',
            'AP-396',
            'AP-397',
            'AP-398',
            'AP-399',
            'AP-400',
            'AP-401',
            'AP-402',
            'AP-403',
            'AP-404',
            'AP-405',
            'AP-406',
            'AP-407',
            'AP-408',
            'AP-409',
            'AP-410',
            'AP-411',
            'AP-412',
            'AP-413',
            'AP-414',
            'AP-415',
            'AP-416',
            'AP-417',
            'AP-418',
            'AP-419',
            'AP-420',
            'AP-421',
            'AP-422',
            'AP-423',
            'AP-424',
            'AP-425',
            'AP-426',
            'AP-427',
            'AP-428',
            'AP-429',
            'AP-430',
            'AP-431',
            'AP-432',
            'AP-433',
            'AP-434',
            'AP-435',
            'AP-436',
            'AP-437',
            'AP-438',
            'AP-439',
            'AP-440',
            'AP-441',
            'AP-442',
            'AP-443',
            'AP-444',
            'AP-445',
            'AP-446',
            'AP-447',
            'AP-448',
            'AP-449',
            'AP-450',
            'AP-451',
            'AP-452',
            'AP-453',
            'AP-454',
            'AP-455',
            'AP-456',
            'AP-457',
            'AP-458',
            'AP-459',
            'AP-460',
            'AP-461',
            'AP-462',
            'AP-463',
            'AP-464',
            'AP-465',
            'AP-466',
            'AP-467',
            'AP-468',
            'AP-469',
            'AP-470',
            'AP-471',
            'AP-472',
            'AP-473',
            'AP-474',
            'AP-475',
            'AP-476',
            'AP-477',
            'AP-478',
            'AP-479',
            'AP-480',
            'AP-481',
            'AP-482',
            'AP-483',
            'AP-484',
            'AP-485',
            'AP-486',
            'AP-487',
            'AP-488',
            'AP-489',
            'AP-490',
            'AP-491',
            'AP-492',
            'AP-493',
            'AP-494',
            'AP-495',
            'AP-496',
            'AP-497',
            'AP-498',
            'AP-499',
            'AP-500',
            'AP-501',
            'AP-502',
            'AP-503',
            'AP-504',
            'AP-505',
            'AP-506',
            'AP-507',
            'AP-508',
            'AP-509',
            'AP-510',
            'AP-511',
            'AP-512',
            'AP-513',
            'AP-514',
            'AP-515',
            'AP-516',
            'AP-517',
            'AP-518',
            'AP-519',
            'AP-520',
            'AP-521',
            'AP-522',
            'AP-523',
            'AP-524',
            'AP-525',
            'AP-526',
            'AP-527',
            'AP-528',
            'AP-529',
            'AP-530',
            'AP-531',
            'AP-532',
            'AP-533',
            'AP-534',
            'AP-535',
            'AP-536',
            'AP-537',
            'AP-538',
            'AP-539',
            'AP-540',
            'AP-541',
            'AP-542',
            'AP-543',
            'AP-544',
            'AP-545',
            'AP-546',
            'AP-547',
            'AP-548',
            'AP-549',
            'AP-550',
            'AP-551',
            'AP-552',
            'AP-553',
            'AP-554',
            'AP-555',
            'AP-556',
            'AP-557',
            'AP-558',
            'AP-559',
            'AP-560',
            'AP-561',
            'AP-562',
            'AP-563',
            'AP-564',
            'AP-565',
            'AP-566',
            'AP-567',
            'AP-568',
            'AP-569',
            'AP-570',
            'AP-571',
            'AP-572',
            'AP-573',
            'AP-574',
            'AP-575',
            'AP-576',
            'AP-577',
            'AP-578',
            'AP-579',
            'AP-580',
            'AP-581',
            'AP-582',
            'AP-583',
            'AP-584',
            'AP-585',
            'AP-586',
            'AP-587',
            'AP-588',
            'AP-589',
            'AP-590',
            'AP-591',
            'AP-592',
            'AP-593',
            'AP-594',
            'AP-595',
            'AP-596',
            'AP-597',
            'AP-598',
            'AP-599',
            'AP-600',
            'AP-601',
            'AP-602',
            'AP-603',
            'AP-604',
            'AP-605',
            'AP-606',
            'AP-607',
            'AP-608',
            'AP-609',
            'AP-610',
            'AP-611',
            'AP-612',
            'AP-613',
            'AP-614',
            'AP-615',
            'AP-616',
            'AP-617',
            'AP-618',
            'AP-619',
            'AP-620',
            'AP-621',
            'AP-622',
            'AP-623',
            'AP-624',
            'AP-625',
            'AP-626',
            'AP-627',
            'AP-628',
            'AP-629',
            'AP-630',
            'AP-631',
            'AP-632',
            'AP-633',
            'AP-634',
            'AP-635',
            'AP-636',
            'AP-637',
            'AP-638',
            'AP-639',
            'AP-640',
            'AP-641',
            'AP-642',
            'AP-643',
            'AP-644',
            'AP-645',
            'AP-646',
            'AP-647',
            'AP-648',
            'AP-649',
            'AP-650',
            'AP-651',
            'AP-652',
            'AP-653',
            'AP-654',
            'AP-655',
            'AP-656',
            'AP-657',
            'AP-658',
            'AP-659',
            'AP-660',
            'AP-661',
            'AP-662',
            'AP-663',
            'AP-664',
            'AP-665',
            'AP-666',
            'AP-667',
            'AP-668',
            'AP-669',
            'AP-670',
            'AP-671',
            'AP-672',
            'AP-673',
            'AP-674',
            'AP-675',
            'AP-676',
            'AP-677',
            'AP-678',
            'AP-679',
            'AP-680',
            'AP-681',
            'AP-682',
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
        $this->assertSame(
            'AtlasApAgentWorkflowAp359Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp360DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp361DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp362HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp363Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp364DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp365DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp366HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp367Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp368DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp369DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp370HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp371Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp372DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp373DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp374HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp375Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex24_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp376DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex24_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp377DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex24_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp378HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex24_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp379Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex25_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp380DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex25_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp381DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex25_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp382HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex25_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp383Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex26_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp384DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex26_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp385DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex26_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp386HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex26_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp387Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex27_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp388DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex27_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp389DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex27_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp390HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex27_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp391Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex28_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp392DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex28_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp393DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex28_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp394HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex28_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp395Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex29_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp396DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex29_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp397DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex29_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp398HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex29_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp399Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex30_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp400DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex30_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp401DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex30_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp402HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex30_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp403Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex31_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp404DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex31_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp405DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex31_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp406HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex31_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp407Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex32_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp408DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex32_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp409DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex32_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp410HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex32_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp411Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex33_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp412DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex33_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp413DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex33_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp414HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex33_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp415Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex34_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp416DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex34_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp417DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex34_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp418HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex34_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp419Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex35_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp420DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex35_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp421DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex35_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp422HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex35_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp423Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex36_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp424DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex36_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp425DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex36_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp426HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex36_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp427Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex37_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp428DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex37_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp429DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex37_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp430HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex37_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp431Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex38_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp432DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex38_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp433DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex38_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp434HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex38_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp435Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex39_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp436DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex39_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp437DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex39_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp438HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex39_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp439Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex40_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp440DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex40_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp441DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex40_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp442HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex40_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp443Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex41_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp444DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex41_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp445DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex41_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp446HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex41_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp447Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex42_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp448DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex42_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp449DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex42_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp450HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex42_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp451Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex43_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp452DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex43_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp453DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex43_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp454HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex43_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp455Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex44_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp456DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex44_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp457DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex44_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp458HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex44_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp459Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex45_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp460DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex45_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp461DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex45_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp462HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex45_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp463Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex46_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp464DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex46_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp465DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex46_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp466HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex46_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp467Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex47_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp468DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex47_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp469DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex47_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp470HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex47_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp471Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex48_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp472DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex48_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp473DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex48_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp474HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex48_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp475Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex49_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp476DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex49_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp477DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex49_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp478HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex49_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp479Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex50_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp480DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex50_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp481DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex50_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp482HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex50_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp483Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex51_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp484DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex51_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp485DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex51_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp486HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex51_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp487Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex52_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp488DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex52_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp489DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex52_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp490HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex52_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp491Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex53_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp492DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex53_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp493DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex53_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp494HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex53_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp495Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex54_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp496DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex54_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp497DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex54_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp498HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex54_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp499Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex55_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp500DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex55_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp501DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex55_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp502HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex55_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp503Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex56_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp504DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex56_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp505DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex56_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp506HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex56_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp507Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex57_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp508DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex57_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp509DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex57_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp510HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex57_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp511Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex58_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp512DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex58_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp513DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex58_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp514HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex58_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp515Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex59_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp516DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex59_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp517DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex59_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp518HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex59_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp519Preflight',
            data_get($payload, 'handoff_summary.preflight_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex60_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp520DecisionContract',
            data_get($payload, 'handoff_summary.decide_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex60_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp521DecisionReceipt',
            data_get($payload, 'handoff_summary.receipt_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex60_decision_with'),
        );
        $this->assertSame(
            'AtlasApAgentWorkflowAp522HandoffPacket',
            data_get($payload, 'handoff_summary.handoff_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ex60_with'),
        );
        $this->assertSame(['AP-201', 'AP-202'], data_get($payload, 'steps.0.allowed_next_steps'));
        $this->assertSame(['proposal_count_greater_than_zero'], data_get($payload, 'steps.1.blocks_when'));
        $this->assertSame(['AP-205'], data_get($payload, 'steps.2.allowed_next_steps'));
        $this->assertSame(['validation_evidence_invalid_shape'], data_get($payload, 'steps.3.blocks_when'));
        $this->assertSame([], data_get($payload, 'steps.4.allowed_next_steps'));
    }
}
