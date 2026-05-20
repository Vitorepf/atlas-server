<?php

namespace Tests\Unit\Ai\Holding;

use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Holding\AutonomousHoldingReadinessService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class AutonomousHoldingReadinessServiceTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDomainRuntimeTables();
    }

    public function test_real_enterprise_criterion_does_not_accept_manifest_only_shape(): void
    {
        $report = app(AutonomousHoldingReadinessService::class)->report();

        $this->assertSame(AutonomousHoldingReadinessService::SCHEMA, $report['schema']);
        $this->assertFalse($report['ok']);
        $this->assertSame('attention', $report['status']);
        $this->assertLessThan(AutonomousHoldingReadinessService::TARGET_SCORE, $report['current_score']);
        $this->assertLessThan(AutonomousHoldingReadinessService::TARGET_SCORE, $report['company_buildout_score']);
        $this->assertSame(0.0, $report['operational_history_score']);
        $this->assertSame(9, $report['summary']['active_company_count']);
        $this->assertSame(9, $report['summary']['supervised_execution_company_count']);
        $this->assertSame(1, $report['summary']['limited_autonomy_company_count']);
        $this->assertSame(9, $report['summary']['company_with_delivery_work_products_count']);
        $this->assertSame(9, $report['summary']['company_with_runtime_command_surface_count']);
        $this->assertSame(0, $report['summary']['enterprise_structural_model_complete_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_function_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_agent_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_agent_operational_scorecards_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_flow_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_flow_action_runtime_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_operations_runbook_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_operating_package_evidence_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_replay_contract_evidence_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_provider_workbench_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_agent_repository_pipeline_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_industry_solution_ecosystem_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_operational_dress_rehearsal_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_business_operating_backbone_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_work_product_coverage_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_recurring_execution_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_metric_system_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_integration_probe_count']);
        $this->assertSame(0, $report['summary']['company_with_observed_history_window_count']);
        $this->assertLessThan(9, $report['summary']['enterprise_operating_model_complete_count']);
        foreach ($report['companies'] as $company) {
            $this->assertSame([], $company['unregistered_runtime_commands'], $company['company_id']);
            $this->assertSame(
                $company['runtime_command_count'],
                $company['registered_runtime_command_count'],
                $company['company_id'],
            );
        }
        $this->assertNotSame([], $report['blockers']);
        $this->assertContains(
            'dimension_incomplete:observed_history_window',
            array_column($report['blockers'], 'id'),
        );
        $this->assertTrue($report['invariants']['read_model_only']);
        $this->assertTrue($report['invariants']['target_9_requires_every_company_to_have_real_operating_model']);
        $this->assertFalse($report['portfolio_governor']['autonomous_external_execution_allowed']);
    }

    public function test_command_emits_json_readiness_packet(): void
    {
        Artisan::call('atlas:ai:autonomous-holding', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(AutonomousHoldingReadinessService::SCHEMA, $payload['schema']);
        $this->assertSame('attention', $payload['status']);
        $this->assertEquals(9.0, $payload['target_score']);
        $this->assertFalse($payload['ok']);
        $this->assertGreaterThan(0, count($payload['blockers']));
    }

    public function test_observe_cycle_records_current_operational_evidence_without_calendar_delay(): void
    {
        $this->migrateEnterpriseOperationsTables();

        foreach ([
            'enterprise-activation-backlog-register',
            'enterprise-activation-backlog-run',
            'enterprise-connector-activation-register',
            'enterprise-connector-activation-probe',
            'enterprise-flow-run-queue-register',
            'enterprise-flow-operations-runbook-register',
            'enterprise-flow-operations-runbook-drill',
        ] as $action) {
            Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => $action,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
            $this->assertIsArray($payload, $action);
            $this->assertTrue((bool) ($payload['ok'] ?? false), $action.': '.Artisan::output());
            if ($action === 'enterprise-flow-operations-runbook-drill') {
                $blocked = array_values(array_filter(
                    (array) ($payload['records'] ?? []),
                    static fn (array $record): bool => ($record['status'] ?? null) !== 'operations_runbook_green_external_blocked',
                ));
                $this->assertSame(
                    $payload['summary']['flow_count'],
                    $payload['summary']['operations_green_count'],
                    json_encode($blocked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: Artisan::output(),
                );
            }
        }

        $this->seedEnterpriseFlowActionRuntimeEvidence();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'observe-cycle',
            '--json' => true,
        ]);

        $cycle = json_decode(Artisan::output(), true);

        $this->assertIsArray($cycle);
        $this->assertSame('atlas.ai.autonomous_holding.operating_cycle.v1', $cycle['schema']);
        $this->assertTrue($cycle['ok']);
        $this->assertTrue($cycle['invariants']['no_backfill']);

        $records = AiDomainRuntimeRecord::query()
            ->where('runtime_status', DomainRuntimeRecordService::STATUS_COMPLETED)
            ->orderBy('domain_id')
            ->get()
            ->filter(static fn (AiDomainRuntimeRecord $record): bool => collect((array) $record->evidence_refs)
                ->contains(static fn (mixed $ref): bool => is_string($ref) && str_starts_with($ref, 'autonomous_holding_operating_cycle:')))
            ->values();

        $this->assertCount(9, $records);

        foreach ($records as $record) {
            $packet = data_get($record->execution_plan, 'operating_packet');

            $this->assertIsArray($packet);
            $this->assertSame('atlas.ai.company_operating_packet.v1', $packet['schema']);
            $this->assertSame($record->domain_id, $packet['domain_id']);
            $this->assertNotEmpty($packet['functions']);
            $this->assertNotEmpty($packet['agent_roles']);
            $this->assertNotEmpty($packet['flow_profiles']);
            $this->assertNotEmpty($packet['flow_executions']);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['flow_executions']), $record->domain_id);
            foreach ($packet['flow_executions'] as $execution) {
                $this->assertSame('atlas.ai.company_flow_execution.v1', $execution['schema']);
                $this->assertSame($record->domain_id, $execution['domain_id']);
                $this->assertSame('observed', $execution['status']);
                $this->assertNotEmpty($execution['flow_profile']);
                $this->assertNotEmpty($execution['owned_function']);
                $this->assertNotEmpty($execution['agent_role']);
                $this->assertNotEmpty($execution['metric_key']);
                $this->assertNotEmpty($execution['flow_execution_hash']);
                $this->assertFalse($execution['external_side_effects']);
            }
            $this->assertNotEmpty($packet['flow_operations_runbooks']);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['flow_operations_runbooks']), $record->domain_id);
            foreach ($packet['flow_operations_runbooks'] as $runbook) {
                $this->assertSame('atlas.ai.company_flow_operations_runbook_evidence.v1', $runbook['schema']);
                $this->assertSame($record->domain_id, $runbook['domain_id']);
                $this->assertTrue($runbook['operations_green']);
                $this->assertSame(64, strlen($runbook['operating_package_hash']));
                $this->assertGreaterThan(0, $runbook['operating_package_attestation_count']);
                $this->assertTrue($runbook['replay_contract_bound']);
                $this->assertGreaterThanOrEqual(25, $runbook['minimum_replay_cases_before_shadow']);
                $this->assertTrue($runbook['business_execution_cell_bound']);
                $this->assertNotEmpty($runbook['business_execution_cell_id']);
                $this->assertTrue($runbook['business_kpi_binding_bound']);
                $this->assertTrue($runbook['business_service_lane_bound']);
                $this->assertNotEmpty($runbook['business_service_lane_id']);
                $this->assertTrue($runbook['business_artifact_contract_bound']);
                $this->assertSame(64, strlen((string) $runbook['business_execution_attestation_hash']));
                $this->assertFalse($runbook['calendar_wait_blocker_enabled']);
                $this->assertFalse($runbook['external_side_effects']);
            }
            $this->assertNotEmpty($packet['orchestration_traces']);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['orchestration_traces']), $record->domain_id);
            foreach ($packet['orchestration_traces'] as $trace) {
                $this->assertSame('atlas.ai.company_orchestration_trace.v1', $trace['schema']);
                $this->assertSame($record->domain_id, $trace['domain_id']);
                $this->assertSame('durable_graph_checkpoint', $trace['state_model']);
                $this->assertCount(3, $trace['handoff_events']);
                $this->assertContains('policy_checked', $trace['guardrail_events']);
                $this->assertTrue($trace['checkpoint']['human_in_loop_required_for_external_action']);
                $this->assertTrue($trace['tool_call_policy']['tool_calls_receipted']);
                $this->assertFalse($trace['tool_call_policy']['external_side_effects']);
                $this->assertNotEmpty($trace['trace_hash']);
            }
            $this->assertNotEmpty($packet['flow_evaluations']);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['flow_evaluations']), $record->domain_id);
            foreach ($packet['flow_evaluations'] as $evaluation) {
                $this->assertSame('atlas.ai.company_flow_evaluation.v1', $evaluation['schema']);
                $this->assertSame($record->domain_id, $evaluation['domain_id']);
                $this->assertSame('green', $evaluation['status']);
                $this->assertGreaterThanOrEqual(0.86, $evaluation['critic_score']);
                $this->assertSame('passed_internal_fixture', $evaluation['benchmark']['status']);
                $this->assertFalse($evaluation['budget_observation']['external_side_effects']);
                $this->assertTrue($evaluation['promotion_decision']['eligible_for_internal_supervised_operation']);
                $this->assertFalse($evaluation['promotion_decision']['eligible_for_external_action']);
                $this->assertNotEmpty($evaluation['evaluation_hash']);
            }
            $this->assertSame('atlas.ai.company_management_system_snapshot.v1', $packet['management_system_snapshot']['schema']);
            $this->assertSame($record->domain_id, $packet['management_system_snapshot']['domain_id']);
            $this->assertSame('ready_for_review', $packet['management_system_snapshot']['board_review']['status']);
            $this->assertGreaterThanOrEqual(4, count($packet['management_system_snapshot']['okr_scorecard']), $record->domain_id);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['management_system_snapshot']['sla_report']), $record->domain_id);
            $this->assertCount(4, $packet['management_system_snapshot']['risk_register'], $record->domain_id);
            $this->assertSame('green', $packet['management_system_snapshot']['backlog_health']['status']);
            $this->assertTrue($packet['management_system_snapshot']['escalation_policy']['operator_required_for_external_action']);
            $this->assertNotEmpty($packet['management_system_snapshot']['snapshot_hash']);
            $this->assertNotEmpty($packet['agent_assignments']);
            $this->assertGreaterThanOrEqual(count($packet['agent_roles']), count($packet['agent_assignments']), $record->domain_id);
            foreach ($packet['agent_assignments'] as $assignment) {
                $this->assertSame('atlas.ai.company_agent_assignment.v1', $assignment['schema']);
                $this->assertSame($record->domain_id, $assignment['domain_id']);
                $this->assertSame('observed', $assignment['status']);
                $this->assertNotEmpty($assignment['agent_role']);
                $this->assertNotEmpty($assignment['owned_function']);
                $this->assertNotEmpty($assignment['flow_profile']);
                $this->assertNotEmpty($assignment['metric_key']);
                $this->assertNotEmpty($assignment['assignment_hash']);
                $this->assertFalse($assignment['external_side_effects']);
            }
            $this->assertNotEmpty($packet['agent_operational_scorecards']);
            $this->assertGreaterThanOrEqual(count($packet['agent_roles']), count($packet['agent_operational_scorecards']), $record->domain_id);
            foreach ($packet['agent_operational_scorecards'] as $scorecard) {
                $this->assertSame('atlas.ai.company_agent_operational_scorecard.v1', $scorecard['schema']);
                $this->assertSame($record->domain_id, $scorecard['domain_id']);
                $this->assertNotEmpty($scorecard['agent_role']);
                $this->assertGreaterThanOrEqual(1, $scorecard['assigned_work_units']);
                $this->assertLessThanOrEqual(0.95, $scorecard['utilization']);
                $this->assertTrue($scorecard['utilization_green']);
                $this->assertTrue($scorecard['review_capacity_green']);
                $this->assertTrue($scorecard['backup_bound']);
                $this->assertTrue($scorecard['handoff_ready']);
                $this->assertSame(0, $scorecard['policy_findings']);
                $this->assertContains('external_write', $scorecard['blocked_external_actions']);
                $this->assertFalse($scorecard['external_side_effects']);
                $this->assertNotEmpty($scorecard['scorecard_hash']);
            }
            $this->assertSame('atlas.ai.company_workforce_operational_ledger.v1', $packet['workforce_operational_ledger']['schema']);
            $this->assertSame($record->domain_id, $packet['workforce_operational_ledger']['domain_id']);
            $this->assertSame(count($packet['agent_roles']), $packet['workforce_operational_ledger']['agent_count']);
            $this->assertSame(count($packet['agent_operational_scorecards']), $packet['workforce_operational_ledger']['green_scorecard_count']);
            $this->assertSame(1.0, (float) $packet['workforce_operational_ledger']['green_rate']);
            $this->assertGreaterThanOrEqual(count($packet['flow_profiles']), count($packet['workforce_operational_ledger']['flow_staffing_map']), $record->domain_id);
            $this->assertFalse($packet['workforce_operational_ledger']['external_side_effects']);
            $this->assertNotEmpty($packet['workforce_operational_ledger']['ledger_hash']);
            $this->assertNotEmpty($packet['function_executions']);
            $this->assertGreaterThanOrEqual(count($packet['functions']), count($packet['function_executions']), $record->domain_id);
            foreach ($packet['function_executions'] as $execution) {
                $this->assertSame('atlas.ai.company_function_execution.v1', $execution['schema']);
                $this->assertSame($record->domain_id, $execution['domain_id']);
                $this->assertSame('observed', $execution['status']);
                $this->assertNotEmpty($execution['agent_role']);
                $this->assertNotEmpty($execution['flow_profile']);
                $this->assertNotEmpty($execution['work_product_id']);
                $this->assertNotEmpty($execution['metric_key']);
                $this->assertFalse($execution['external_side_effects']);
            }
            $this->assertNotEmpty($packet['work_products']);
            $this->assertGreaterThanOrEqual(3, count($packet['work_products']), $record->domain_id);
            $this->assertGreaterThanOrEqual(count($packet['delivery_types']), count($packet['work_products']), $record->domain_id);
            foreach ($packet['work_products'] as $product) {
                $this->assertSame('atlas.ai.company_work_product.v1', $product['schema']);
                $this->assertSame($record->domain_id, $product['domain_id']);
                $this->assertSame('observed', $product['status']);
                $this->assertNotEmpty($product['artifact_id']);
                $this->assertNotEmpty($product['kind']);
                $this->assertNotEmpty($product['flow_profile']);
                $this->assertNotEmpty($product['metric_key']);
                $this->assertNotEmpty($product['work_product_hash']);
                $this->assertFalse($product['external_side_effects']);
            }
            $this->assertNotEmpty($packet['quality_gate_reviews']);
            $this->assertNotEmpty($packet['recurring_jobs']);
            $this->assertGreaterThanOrEqual(count($packet['recurring_cadences']), count($packet['recurring_jobs']), $record->domain_id);
            foreach ($packet['recurring_jobs'] as $job) {
                $this->assertSame('atlas.ai.company_recurring_job.v1', $job['schema']);
                $this->assertSame($record->domain_id, $job['domain_id']);
                $this->assertSame('observed', $job['status']);
                $this->assertNotEmpty($job['cadence']);
                $this->assertNotEmpty($job['flow_profile']);
                $this->assertNotEmpty($job['metric_key']);
                $this->assertNotEmpty($job['recurring_job_hash']);
                $this->assertFalse($job['external_side_effects']);
            }
            $this->assertCount(2, $packet['routine_runs'], $record->domain_id);
            foreach ($packet['routine_runs'] as $routine) {
                $this->assertSame('atlas.ai.company_routine_run.v1', $routine['schema']);
                $this->assertSame($record->domain_id, $routine['domain_id']);
                $this->assertIsInt($routine['exit_code']);
                $this->assertArrayHasKey('ok', $routine);
                $this->assertArrayHasKey('command', $routine);
                $this->assertFalse($routine['external_side_effects']);
            }
            $this->assertNotEmpty($packet['metric_observations']);
            $this->assertNotEmpty($packet['observed_metrics']);
            $this->assertGreaterThanOrEqual(5, count($packet['observed_metrics']), $record->domain_id);
            $this->assertNotEmpty($packet['integration_checks']);
            $this->assertGreaterThanOrEqual(3, count($packet['integration_checks']), $record->domain_id);
            foreach ($packet['integration_checks'] as $probe) {
                $this->assertSame('atlas.ai.company_integration_probe.v1', $probe['schema']);
                $this->assertSame('verified_governed_contract', $probe['status']);
                $this->assertTrue($probe['governed']);
                $this->assertFalse($probe['external_side_effects']);
                $this->assertNotEmpty($probe['contract_hash']);
            }
            $this->assertNotEmpty($packet['cross_company_handoff_executions']);
            foreach ($packet['cross_company_handoff_executions'] as $handoff) {
                $this->assertSame('atlas.ai.company_cross_handoff_execution.v1', $handoff['schema']);
                $this->assertSame($record->domain_id, $handoff['source_company']);
                $this->assertSame('observed_ready', $handoff['status']);
                $this->assertTrue($handoff['handoff_packet']['typed_context_present']);
                $this->assertTrue($handoff['handoff_packet']['evidence_refs_present']);
                $this->assertTrue($handoff['policy']['allowed_by_manifest']);
                $this->assertFalse($handoff['policy']['external_side_effects']);
                $this->assertGreaterThanOrEqual(0.90, $handoff['collaboration_score']);
                $this->assertNotEmpty($handoff['handoff_hash']);
            }
            $this->assertNotEmpty($packet['artifacts']);
            $this->assertContains('external_side_effects', $packet['blocked_actions']);
            $this->assertFalse($packet['evidence_contract']['backfill_allowed']);
        }

        $report = app(AutonomousHoldingReadinessService::class)->report();

        $this->assertSame(9, $report['summary']['company_with_observed_history_window_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_function_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_agent_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_agent_operational_scorecards_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_flow_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_flow_action_runtime_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_operations_runbook_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_operating_package_evidence_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_replay_contract_evidence_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_provider_workbench_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_operational_dress_rehearsal_count']);
        $this->assertSame(9, $report['summary']['company_with_enterprise_business_operating_backbone_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_work_product_coverage_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_recurring_execution_count']);
        $this->assertSame(9, $report['summary']['company_with_observed_integration_probe_count']);
        $this->assertSame(9, $report['summary']['enterprise_operating_model_complete_count']);
        $this->assertGreaterThan(0, $report['summary']['enterprise_structural_model_complete_count']);
        $this->assertSame(9, $report['summary']['target_9_claim_ready_company_count']);
        $this->assertGreaterThanOrEqual(AutonomousHoldingReadinessService::TARGET_SCORE, $report['current_score']);
        $this->assertTrue($report['ok']);
        $this->assertGreaterThan(0.0, $report['company_buildout_score']);
        $this->assertLessThanOrEqual(10.0, $report['company_buildout_score']);
        $this->assertSame(10.0, $report['operational_history_score']);
        $this->assertNotContains(
            'complete_every_company_operating_model',
            array_column($report['blockers'], 'id'),
        );
        $this->assertNotContains(
            'dimension_incomplete:observed_history_window',
            array_column($report['blockers'], 'id'),
        );
        $this->assertSame(1.0, $report['dimensions']['enterprise_provider_workbenches']['score']);
        $this->assertSame(1.0, $report['dimensions']['enterprise_operational_dress_rehearsal']['score']);
        $this->assertSame(1.0, $report['dimensions']['enterprise_business_operating_backbone']['score']);
        foreach ($report['companies'] as $company) {
            $this->assertTrue($company['has_enterprise_provider_workbench'], $company['company_id']);
            $this->assertTrue($company['has_enterprise_operational_dress_rehearsal'], $company['company_id']);
            $this->assertTrue($company['has_enterprise_business_operating_backbone'], $company['company_id']);
            $this->assertSame(
                $company['business_backbone_required_component_count'],
                $company['business_backbone_ready_component_count'],
                $company['company_id'],
            );
            $this->assertGreaterThanOrEqual(5, $company['provider_contract_count'], $company['company_id']);
            $this->assertGreaterThanOrEqual($company['flow_profile_count'], $company['provider_flow_route_count'], $company['company_id']);
            $this->assertGreaterThanOrEqual($company['flow_profile_count'], $company['dress_rehearsal_flow_runbook_count'], $company['company_id']);
            $this->assertGreaterThanOrEqual($company['flow_profile_count'], $company['dress_rehearsal_operator_acceptance_packet_count'], $company['company_id']);
        }
        $this->assertTrue($report['invariants']['target_9_uses_accelerated_operational_evidence']);
        $this->assertTrue($report['invariants']['calendar_history_blocks_neither_buildout_nor_target_9']);
    }

    private function seedEnterpriseFlowActionRuntimeEvidence(): void
    {
        $buildout = app(AutonomousHoldingEnterpriseBuildoutService::class);
        $runtime = app(EnterpriseFlowFixtureActionRuntimeService::class);

        foreach ((array) $buildout->report()['companies'] as $company) {
            foreach ((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []) as $action) {
                $payload = $runtime->run(
                    (string) $company['company_id'],
                    (string) $action['action'],
                    false,
                );

                $this->assertSame('atlas.ai.company.enterprise_flow_action_run.v1', $payload['schema']);
                $this->assertSame('internal_flow_completed_external_blocked', $payload['status']);
                $this->assertSame('atlas.ai.company.enterprise_flow_runtime_record_ref.v1', $payload['runtime_record']['schema']);
            }
        }
    }

    private function migrateEnterpriseOperationsTables(): void
    {
        foreach ([
            '2026_05_19_120000_create_ai_operator_approvals_table.php',
            '2026_05_20_020000_create_ai_holding_external_action_mandates_table.php',
            '2026_05_20_021000_create_ai_holding_activation_backlog_items_table.php',
            '2026_05_20_022000_add_implementation_cycle_to_ai_holding_activation_backlog_items_table.php',
            '2026_05_20_023000_create_ai_holding_connector_activation_records_table.php',
            '2026_05_20_024000_create_ai_holding_enterprise_flow_run_queue_items_table.php',
            '2026_05_20_026000_add_operating_package_to_ai_holding_enterprise_flow_run_queue_items_table.php',
            '2026_05_20_025000_create_ai_holding_enterprise_flow_operations_runbooks_table.php',
            '2026_05_20_027000_add_operating_package_to_ai_holding_enterprise_flow_operations_runbooks_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }
}
