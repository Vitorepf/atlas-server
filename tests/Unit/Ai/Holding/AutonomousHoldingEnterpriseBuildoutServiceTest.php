<?php

namespace Tests\Unit\Ai\Holding;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Tests\TestCase;

class AutonomousHoldingEnterpriseBuildoutServiceTest extends TestCase
{
    public function test_enterprise_buildout_delivers_specific_company_operating_stacks(): void
    {
        $report = app(AutonomousHoldingEnterpriseBuildoutService::class)->report();

        $this->assertTrue($report['ok']);
        $this->assertSame(AutonomousHoldingEnterpriseBuildoutService::SCHEMA, $report['schema']);
        $this->assertSame(9, $report['company_count']);
        $this->assertSame(9, $report['enterprise_company_count']);
        $this->assertSame('atlas.ai.holding.cross_company_fabric.v1', $report['cross_company_fabric']['schema']);
        $this->assertGreaterThanOrEqual(18, $report['cross_company_fabric']['handoff_contract_count']);
        $this->assertNotEmpty($report['cross_company_fabric']['shared_service_catalog']);
        $this->assertCount(9, $report['cross_company_fabric']['dependency_map']);
        $this->assertContains('target_company_acceptance_required', $report['cross_company_fabric']['fabric_gates']);
        $this->assertNotEmpty($report['cross_company_fabric']['fabric_hash']);
        $this->assertSame('atlas.ai.holding.portfolio_governance_stack.v1', $report['portfolio_governance_stack']['schema']);
        $this->assertSame(9, $report['portfolio_governance_stack']['company_count']);
        $this->assertGreaterThanOrEqual(6, count($report['portfolio_governance_stack']['decision_rights_matrix']));
        $this->assertCount(9, $report['portfolio_governance_stack']['portfolio_dependency_registry']);
        $this->assertFalse($report['portfolio_governance_stack']['portfolio_resource_allocation']['real_spend_enabled']);
        $this->assertContains('cross_company_handoff_acceptance_rate', $report['portfolio_governance_stack']['portfolio_observability']['required_metrics']);
        $this->assertNotEmpty($report['portfolio_governance_stack']['governance_hash']);
        $this->assertSame('atlas.ai.holding.structural_completion_policy.v1', $report['structural_completion_policy']['schema']);
        $this->assertFalse($report['structural_completion_policy']['enterprise_buildout_requires_observed_history_window']);
        $this->assertTrue($report['structural_completion_policy']['target_9_external_autonomy_claim_requires_current_operational_evidence']);
        $this->assertSame(64, strlen((string) $report['receipt_hash']));

        foreach ($report['cross_company_fabric']['handoff_contracts'] as $handoff) {
            $this->assertSame('atlas.ai.holding.cross_company_handoff_contract.v1', $handoff['schema']);
            $this->assertTrue($handoff['typed_context_required']);
            $this->assertTrue($handoff['evidence_refs_required']);
            $this->assertFalse($handoff['external_side_effects']);
            $this->assertNotEmpty($handoff['contract_hash']);
        }

        $companies = collect($report['companies'])->keyBy('company_id');
        foreach (['software', 'research', 'strategy', 'finance', 'marketing', 'cyber', 'automation', 'personal_development', 'operations'] as $companyId) {
            $this->assertTrue($companies->has($companyId), $companyId);
            $company = $companies->get($companyId);
            $this->assertSame(AutonomousHoldingEnterpriseBuildoutService::COMPANY_SCHEMA, $company['schema']);
            $this->assertTrue($company['readiness']['ok'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['function_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['agent_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['flow_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_execution_contract_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_playbook_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_runtime_blueprint_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['enterprise_flow_runbook_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['enterprise_flow_connector_backplane_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['enterprise_flow_runbook_metric_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['flow_runtime_implementation_source_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['executable_flow_packet_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['agent_tool_routing_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_artifact_io_contract_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['supervision_shadow_gate_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_runtime_adapter_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['runtime_implementation_metric_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_fixture_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_stub_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['expected_trace_trajectory_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['quality_assertion_suite_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['failure_injection_case_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['dry_run_command_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['flow_fixture_simulation_metric_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['runtime_action_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['command_adapter_matrix_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['handler_state_schema_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['runtime_event_emission_plan_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['operator_checkpoint_contract_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['action_runtime_metric_count'], $companyId);
            $this->assertSame($company['readiness']['agent_count'], $company['readiness']['enterprise_agent_registry_count'], $companyId);
            $this->assertGreaterThanOrEqual(9, $company['readiness']['agent_toolkit_framework_source_count'], $companyId);
            $this->assertSame($company['readiness']['agent_count'], $company['readiness']['agent_toolkit_profile_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_toolkit_assignment_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['agent_repository_watch_count'], $companyId);
            $this->assertSame($company['readiness']['agent_count'], $company['readiness']['agent_toolkit_certification_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['agent_toolkit_metric_count'], $companyId);
            $this->assertGreaterThanOrEqual(11, $company['readiness']['agent_repository_intake_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['agent_repository_framework_scorecard_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['agent_repository_flow_epic_count'], $companyId);
            $this->assertGreaterThanOrEqual(11, $company['readiness']['agent_repository_version_pin_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['agent_repository_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['agent_repository_external_side_effects_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['workforce_agent_capacity_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['workforce_flow_staffing_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['workforce_training_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['portfolio_flow_dependency_routing_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['portfolio_reporting_metric_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['domain_data_entity_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['business_process_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['deliverable_quality_contract_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['production_slo_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['production_integration_enablement_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['service_catalog_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['business_kpi_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['customer_offer_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['customer_journey_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['customer_success_metric_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['account_playbook_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['account_entitlement_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['account_onboarding_success_plan_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['account_service_review_count'], $companyId);
            $this->assertSame($company['readiness']['metric_count'], $company['readiness']['account_health_risk_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['account_contract_metric_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['vendor_due_diligence_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['source_terms_review_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_procurement_routing_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['vendor_operability_scorecard_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_failure_mode_analysis_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_resilience_plan_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['incident_exercise_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['resilience_metric_count'], $companyId);
            $this->assertSame($company['readiness']['metric_count'], $company['readiness']['analytics_metric_lineage_count'], $companyId);
            $this->assertGreaterThanOrEqual(3, $company['readiness']['executive_dashboard_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_decision_register_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['scenario_forecast_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['work_product_analytics_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['knowledge_source_registry_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_learning_loop_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['postmortem_program_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['playbook_change_control_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['work_product_feedback_memory_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_knowledge_sync_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['agent_access_matrix_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_data_boundary_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_secret_binding_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['sensitive_data_handling_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['purpose_consent_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['control_tower_lane_count'], $companyId);
            $this->assertSame($company['readiness']['cadence_count'], $company['readiness']['control_tower_cadence_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['exception_desk_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['change_window_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_probe_plan_count'], $companyId);
            $this->assertGreaterThanOrEqual(3, $company['readiness']['dashboard_operations_map_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['command_center_operating_cell_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['command_center_flow_card_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['command_center_connector_panel_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['command_center_console_view_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['command_center_work_product_factory_count'], $companyId);
            $this->assertSame($company['readiness']['metric_count'], $company['readiness']['command_center_kpi_count'], $companyId);
            $this->assertFalse($company['readiness']['command_center_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['readiness']['command_center_external_execution_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(
                $company['readiness']['function_count']
                    + $company['readiness']['agent_count']
                    + $company['readiness']['flow_count']
                    + $company['readiness']['connector_count']
                    + $company['readiness']['metric_count'],
                $company['readiness']['semantic_graph_node_count'],
                $companyId,
            );
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['semantic_graph_edge_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['semantic_graph_view_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['semantic_graph_drift_rule_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['delivery_contract_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['delivery_sla_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['finance_cost_center_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_unit_economics_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['capacity_simulation_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['work_product_pricing_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['agent_capacity_cost_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_cost_limit_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['intelligence_rival_map_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['intelligence_kpi_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['grc_vendor_risk_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['grc_audit_evidence_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['capability_matrix_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['external_integration_contract_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_adapter_contract_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_auth_boundary_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_sandbox_probe_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_contract_test_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_data_mapping_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['flow_connector_usage_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_replay_fixture_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['connector_slo_failure_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['connector_certification_metric_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['production_connector_preflight_contract_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['production_connector_cutover_flow_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['production_connector_evidence_register_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['production_connector_cutover_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['production_connector_wait_blocker_enabled'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['evaluation_suite_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['benchmark_offline_dataset_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['benchmark_trace_rubric_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['benchmark_adversarial_case_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['benchmark_state_assertion_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['benchmark_replay_matrix_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['benchmark_observability_metric_count'], $companyId);
            $this->assertGreaterThanOrEqual(9, $company['readiness']['tooling_source_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['tooling_benchmark_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['tooling_integration_backlog_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_solution_source_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['domain_solution_module_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['domain_solution_agent_template_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_solution_data_product_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['domain_solution_playbook_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_solution_data_plane_source_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_solution_review_mode_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['business_execution_mode_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['business_execution_cell_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['business_execution_kpi_binding_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['business_execution_service_lane_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['business_execution_artifact_contract_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['business_execution_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['business_execution_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['readiness']['business_execution_external_execution_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_provider_contract_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['domain_provider_connector_workbench_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['domain_provider_flow_route_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['domain_provider_eval_case_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['domain_provider_lineage_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['domain_provider_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['domain_provider_wait_blocker_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['industry_solution_provider_count'], $companyId);
            $this->assertGreaterThanOrEqual(7, $company['readiness']['industry_solution_partner_track_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['industry_solution_workload_pack_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['industry_solution_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['industry_solution_external_side_effects_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['premium_reference_source_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['premium_managed_agent_template_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['premium_flow_template_map_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['premium_workbench_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['premium_domain_source_alignment_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['premium_replay_benchmark_count'], $companyId);
            $this->assertSame(0, $company['readiness']['premium_buildout_wait_days_required'], $companyId);
            $this->assertGreaterThanOrEqual(10, $company['readiness']['enterprise_flow_operating_package_source_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['enterprise_flow_operating_package_count'], $companyId);
            $this->assertGreaterThanOrEqual(6, $company['readiness']['enterprise_flow_operating_package_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['enterprise_flow_operating_package_wait_blocker_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['activation_source_track_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['activation_connector_track_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['activation_flow_matrix_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['operational_dress_rehearsal_flow_runbook_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['operational_dress_rehearsal_live_probe_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['operational_dress_rehearsal_acceptance_packet_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['operational_dress_rehearsal_rollback_drill_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['operational_dress_rehearsal_promotion_evidence_count'], $companyId);
            $this->assertGreaterThanOrEqual(7, $company['readiness']['operational_dress_rehearsal_metric_count'], $companyId);
            $this->assertFalse($company['readiness']['operational_dress_rehearsal_wait_blocker_enabled'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['autonomy_promotion_stage_count'], $companyId);
            $this->assertGreaterThanOrEqual(3, $company['readiness']['connector_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['toolchain_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['okr_count'], $companyId);
            $this->assertGreaterThanOrEqual(4, $company['readiness']['risk_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['runbook_count'], $companyId);
            $this->assertGreaterThanOrEqual(5, $company['readiness']['work_product_count'], $companyId);
            $this->assertGreaterThanOrEqual(3, $company['readiness']['cadence_count'], $companyId);
            $this->assertFalse($company['policy']['external_side_effects_allowed'], $companyId);
            $this->assertTrue($company['policy']['operator_approval_required'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_operating_system.v1', $company['enterprise_operating_system']['schema'], $companyId);
            $this->assertSame('weekly_operating_board', $company['enterprise_operating_system']['governance_board']['cadence'], $companyId);
            $this->assertNotEmpty($company['enterprise_operating_system']['backlog_system']['backlog_hash'], $companyId);
            $this->assertTrue($company['enterprise_operating_system']['audit']['receipt_hash_required'], $companyId);
            $this->assertSame('manager_with_specialist_handoffs_and_independent_reviewer', $company['agent_collaboration_model']['orchestration'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_orchestration_runbook_stack.v1', $company['enterprise_flow_orchestration_runbook_stack']['schema'], $companyId);
            $this->assertSame('supervised_enterprise_agentic_flow_runtime', $company['enterprise_flow_orchestration_runbook_stack']['orchestration_policy']['mode'], $companyId);
            $this->assertFalse($company['enterprise_flow_orchestration_runbook_stack']['orchestration_policy']['external_side_effects_default'], $companyId);
            $this->assertTrue($company['enterprise_flow_orchestration_runbook_stack']['orchestration_policy']['operator_checkpoint_required_for_external_action'], $companyId);
            $this->assertFalse($company['enterprise_flow_orchestration_runbook_stack']['orchestration_policy']['run_without_decision_receipt_allowed'], $companyId);
            $this->assertFalse($company['enterprise_flow_orchestration_runbook_stack']['orchestration_policy']['claim_autonomous_operation_without_observed_runs_allowed'], $companyId);
            $this->assertContains('tool_receipt_coverage', $company['enterprise_flow_orchestration_runbook_stack']['runbook_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_orchestration_runbook_stack']['orchestration_runbook_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_runtime_implementation_stack.v1', $company['enterprise_flow_runtime_implementation_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_flow_runtime_implementation_stack']['implementation_policy']['buildout_blocked_by_observed_history_window'], $companyId);
            $this->assertTrue($company['enterprise_flow_runtime_implementation_stack']['implementation_policy']['current_operational_evidence_required_for_autonomy_claim'], $companyId);
            $this->assertFalse($company['enterprise_flow_runtime_implementation_stack']['implementation_policy']['ungoverned_external_side_effects_allowed'], $companyId);
            $this->assertTrue($company['enterprise_flow_runtime_implementation_stack']['implementation_policy']['operator_checkpoint_required_before_write_publish_spend_trade_deploy_delete'], $companyId);
            $this->assertContains('checkpoint_commit_rate', $company['enterprise_flow_runtime_implementation_stack']['implementation_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_runtime_implementation_stack']['runtime_event_and_outbox_contract']['event_hash'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_runtime_implementation_stack']['implementation_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_fixture_simulation_stack.v1', $company['enterprise_flow_fixture_simulation_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_flow_fixture_simulation_stack']['simulation_policy']['buildout_blocked_by_observed_history_window'], $companyId);
            $this->assertTrue($company['enterprise_flow_fixture_simulation_stack']['simulation_policy']['fixtures_required_before_shadow_mode'], $companyId);
            $this->assertFalse($company['enterprise_flow_fixture_simulation_stack']['simulation_policy']['external_side_effects_allowed_in_simulation'], $companyId);
            $this->assertTrue($company['enterprise_flow_fixture_simulation_stack']['simulation_policy']['operator_approval_required_to_promote_fixture_to_shadow'], $companyId);
            $this->assertTrue($company['enterprise_flow_fixture_simulation_stack']['simulation_promotion_gates']['autonomy_claim_requires_current_operational_evidence'], $companyId);
            $this->assertContains('dry_run_pass_rate', $company['enterprise_flow_fixture_simulation_stack']['simulation_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_fixture_simulation_stack']['simulation_stack_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_action_runtime_stack.v1', $company['enterprise_flow_action_runtime_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_flow_action_runtime_stack']['action_runtime_policy']['buildout_blocked_by_observed_history_window'], $companyId);
            $this->assertFalse($company['enterprise_flow_action_runtime_stack']['action_runtime_policy']['unregistered_action_execution_allowed'], $companyId);
            $this->assertFalse($company['enterprise_flow_action_runtime_stack']['action_runtime_policy']['external_side_effects_allowed_by_default'], $companyId);
            $this->assertTrue($company['enterprise_flow_action_runtime_stack']['action_runtime_policy']['flow_specific_actions_required_before_supervised_runtime'], $companyId);
            $this->assertTrue($company['enterprise_flow_action_runtime_stack']['action_runtime_promotion_gates']['autonomy_claim_requires_current_operational_evidence'], $companyId);
            $this->assertContains('runtime_action_coverage', $company['enterprise_flow_action_runtime_stack']['action_runtime_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_action_runtime_stack']['action_runtime_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_domain_agent_toolkit_stack.v1', $company['enterprise_domain_agent_toolkit_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_domain_agent_toolkit_stack']['toolkit_policy']['buildout_blocked_by_observed_history_window'], $companyId);
            $this->assertFalse($company['enterprise_domain_agent_toolkit_stack']['toolkit_policy']['runtime_use_before_toolkit_certification_allowed'], $companyId);
            $this->assertFalse($company['enterprise_domain_agent_toolkit_stack']['toolkit_policy']['external_side_effects_default'], $companyId);
            $this->assertTrue($company['enterprise_domain_agent_toolkit_stack']['toolkit_policy']['operator_approval_required_for_toolkit_external_action'], $companyId);
            $this->assertContains('toolkit_drift_count', $company['enterprise_domain_agent_toolkit_stack']['toolkit_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_domain_agent_toolkit_stack']['toolkit_stack_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_workforce_capacity_stack.v1', $company['enterprise_workforce_capacity_stack']['schema'], $companyId);
            $this->assertSame('manager_specialists_independent_review_operator_checkpoint', $company['enterprise_workforce_capacity_stack']['org_model']['coverage_model'], $companyId);
            $this->assertFalse($company['enterprise_workforce_capacity_stack']['succession_and_continuity']['single_agent_bottleneck_allowed'], $companyId);
            $this->assertTrue($company['enterprise_workforce_capacity_stack']['succession_and_continuity']['manual_operator_fallback_required'], $companyId);
            $this->assertContains('review_queue_depth', $company['enterprise_workforce_capacity_stack']['capacity_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_workforce_capacity_stack']['workforce_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_portfolio_dependency_stack.v1', $company['enterprise_portfolio_dependency_stack']['schema'], $companyId);
            $this->assertSame('advisory_manager_with_operator_escalation', $company['enterprise_portfolio_dependency_stack']['portfolio_role']['portfolio_governor_relationship'], $companyId);
            $this->assertContains('typed_context', $company['enterprise_portfolio_dependency_stack']['dependency_intake_contract']['reject_when_missing'], $companyId);
            $this->assertTrue($company['enterprise_portfolio_dependency_stack']['escalation_and_conflict_model']['conflict_packet_required'], $companyId);
            $this->assertTrue($company['enterprise_portfolio_dependency_stack']['escalation_and_conflict_model']['external_side_effects_blocked_until_resolved'], $companyId);
            $this->assertContains('dependency_blockers', $company['enterprise_portfolio_dependency_stack']['portfolio_reporting_contract']['required_sections'], $companyId);
            $this->assertNotEmpty($company['enterprise_portfolio_dependency_stack']['dependency_hash'], $companyId);
            $this->assertSame('atlas.ai.company.domain_data_model.v1', $company['domain_data_model']['schema'], $companyId);
            $this->assertTrue($company['domain_data_model']['retention_and_lineage']['source_refs_required'], $companyId);
            $this->assertSame('atlas.ai.company.go_to_production_pack.v1', $company['go_to_production_pack']['schema'], $companyId);
            $this->assertSame('contract', $company['go_to_production_pack']['environment_model']['current_stage'], $companyId);
            $this->assertFalse($company['go_to_production_pack']['environment_model']['external_side_effects_default'], $companyId);
            $this->assertContains('policy_findings', $company['go_to_production_pack']['observability']['required_signals'], $companyId);
            $this->assertContains('sev1_external_side_effect_risk', $company['go_to_production_pack']['incident_response']['severity_levels'], $companyId);
            $this->assertTrue($company['go_to_production_pack']['capacity_plan']['human_checkpoint_capacity_required'], $companyId);
            $this->assertNotEmpty($company['go_to_production_pack']['production_readiness_hash'], $companyId);
            $this->assertSame('atlas.ai.company.commercial_operating_stack.v1', $company['commercial_operating_stack']['schema'], $companyId);
            $this->assertContains('cross_company_handoff', $company['commercial_operating_stack']['work_intake_model']['channels'], $companyId);
            $this->assertFalse($company['commercial_operating_stack']['pricing_and_cost_model']['external_billing_enabled'], $companyId);
            $this->assertTrue($company['commercial_operating_stack']['fulfillment_lifecycle']['quality_gate_before_delivery'], $companyId);
            $this->assertNotEmpty($company['commercial_operating_stack']['commercial_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_customer_market_operations_stack.v1', $company['enterprise_customer_market_operations_stack']['schema'], $companyId);
            $this->assertNotEmpty($company['enterprise_customer_market_operations_stack']['customer_and_stakeholder_model']['stakeholder_segments'], $companyId);
            $this->assertTrue($company['enterprise_customer_market_operations_stack']['market_positioning_system']['claim_review_required_before_external_use'], $companyId);
            $this->assertSame('atlas.ai.company_customer_feedback.v1', $company['enterprise_customer_market_operations_stack']['voice_of_customer_and_feedback_loop']['capture_contract'], $companyId);
            $this->assertContains('external_publish', $company['enterprise_customer_market_operations_stack']['growth_and_retention_operating_model']['blocked_without_operator_approval'], $companyId);
            $this->assertFalse($company['enterprise_customer_market_operations_stack']['market_operations_guardrails']['external_side_effects_default'], $companyId);
            $this->assertTrue($company['enterprise_customer_market_operations_stack']['market_operations_guardrails']['operator_approval_required_for_external_market_action'], $companyId);
            $this->assertNotEmpty($company['enterprise_customer_market_operations_stack']['customer_market_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1', $company['enterprise_vendor_legal_procurement_stack']['schema'], $companyId);
            $this->assertSame('operator_only_for_real_spend', $company['enterprise_vendor_legal_procurement_stack']['procurement_policy']['purchase_authority'], $companyId);
            $this->assertContains('sign_contract', $company['enterprise_vendor_legal_procurement_stack']['procurement_policy']['blocked_without_operator_approval'], $companyId);
            $this->assertFalse($company['enterprise_vendor_legal_procurement_stack']['contract_lifecycle_model']['auto_renewal_allowed'], $companyId);
            $this->assertSame('fail_closed_until_review_packet_accepted', $company['enterprise_vendor_legal_procurement_stack']['legal_and_compliance_review']['exception_policy'], $companyId);
            $this->assertContains('vendor_review_backlog', $company['enterprise_vendor_legal_procurement_stack']['procurement_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_vendor_legal_procurement_stack']['procurement_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_account_contract_delivery_stack.v1', $company['enterprise_account_contract_delivery_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_account_contract_delivery_stack']['account_operations_policy']['external_customer_commitment_allowed'], $companyId);
            $this->assertFalse($company['enterprise_account_contract_delivery_stack']['account_operations_policy']['external_billing_allowed'], $companyId);
            $this->assertFalse($company['enterprise_account_contract_delivery_stack']['account_operations_policy']['auto_renewal_allowed'], $companyId);
            $this->assertTrue($company['enterprise_account_contract_delivery_stack']['account_operations_policy']['operator_approval_required_for_contract_billing_or_customer_visible_commitment'], $companyId);
            $this->assertContains('health_score', $company['enterprise_account_contract_delivery_stack']['account_360_model']['required_fields'], $companyId);
            $this->assertSame('notional_internal_chargeback_and_invoice_draft_only', $company['enterprise_account_contract_delivery_stack']['billing_and_revenue_operations_model']['mode'], $companyId);
            $this->assertFalse($company['enterprise_account_contract_delivery_stack']['billing_and_revenue_operations_model']['external_payment_collection_allowed'], $companyId);
            $this->assertTrue($company['enterprise_account_contract_delivery_stack']['qbr_and_executive_reporting_pack']['customer_visible_export_requires_operator_approval'], $companyId);
            $this->assertContains('account_health_score', $company['enterprise_account_contract_delivery_stack']['account_contract_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_account_contract_delivery_stack']['account_contract_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_connector_certification_stack.v1', $company['enterprise_connector_certification_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_connector_certification_stack']['certification_policy']['write_or_paid_mode_allowed_by_default'], $companyId);
            $this->assertFalse($company['enterprise_connector_certification_stack']['certification_policy']['production_promotion_without_green_probe_allowed'], $companyId);
            $this->assertTrue($company['enterprise_connector_certification_stack']['certification_policy']['operator_approval_required_for_write_publish_spend_trade_delete_or_secret_scope_expansion'], $companyId);
            $this->assertContains('sandbox_probe_success_rate', $company['enterprise_connector_certification_stack']['connector_certification_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_connector_certification_stack']['connector_certification_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_production_connector_preflight_stack.v1', $company['enterprise_production_connector_preflight_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_production_connector_preflight_stack']['preflight_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_production_connector_preflight_stack']['preflight_policy']['production_cutover_without_operator_signed_scope_allowed'], $companyId);
            $this->assertFalse($company['enterprise_production_connector_preflight_stack']['preflight_policy']['real_credential_material_in_packet_allowed'], $companyId);
            $this->assertFalse($company['enterprise_production_connector_preflight_stack']['preflight_policy']['external_side_effects_default'], $companyId);
            $this->assertTrue($company['enterprise_production_connector_preflight_stack']['preflight_policy']['manual_execution_handoff_only_after_signed_mandate'], $companyId);
            $this->assertContains('rollback_drill_pass_rate', $company['enterprise_production_connector_preflight_stack']['cutover_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_production_connector_preflight_stack']['production_connector_preflight_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_resilience_continuity_stack.v1', $company['enterprise_resilience_continuity_stack']['schema'], $companyId);
            $this->assertSame('fail_closed_with_manual_operator_fallback', $company['enterprise_resilience_continuity_stack']['resilience_policy']['operating_mode'], $companyId);
            $this->assertFalse($company['enterprise_resilience_continuity_stack']['resilience_policy']['external_side_effects_during_incident_allowed'], $companyId);
            $this->assertSame('read_only_advisory_packets_with_manual_review', $company['enterprise_resilience_continuity_stack']['business_continuity_plan']['minimum_viable_operation'], $companyId);
            $this->assertFalse($company['enterprise_resilience_continuity_stack']['backup_restore_contract']['restore_without_operator_approval_allowed'], $companyId);
            $this->assertFalse($company['enterprise_resilience_continuity_stack']['crisis_communication_model']['external_notification_allowed_without_operator'], $companyId);
            $this->assertContains('restore_test_age_days', $company['enterprise_resilience_continuity_stack']['resilience_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_resilience_continuity_stack']['resilience_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1', $company['enterprise_analytics_decision_intelligence_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_analytics_decision_intelligence_stack']['decision_intelligence_policy']['synthetic_scores_allowed'], $companyId);
            $this->assertFalse($company['enterprise_analytics_decision_intelligence_stack']['decision_intelligence_policy']['external_action_from_dashboard_allowed'], $companyId);
            $this->assertTrue($company['enterprise_analytics_decision_intelligence_stack']['decision_intelligence_policy']['operator_review_required_for_capital_or_external_action'], $companyId);
            $this->assertContains('decision_receipt_coverage', $company['enterprise_analytics_decision_intelligence_stack']['decision_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_analytics_decision_intelligence_stack']['analytics_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1', $company['enterprise_knowledge_memory_learning_stack']['schema'], $companyId);
            $this->assertSame('repo_docs_and_domain_contracts', $company['enterprise_knowledge_memory_learning_stack']['memory_governance_policy']['authoring_source_of_truth'], $companyId);
            $this->assertTrue($company['enterprise_knowledge_memory_learning_stack']['memory_governance_policy']['cross_company_memory_write_requires_handoff'], $companyId);
            $this->assertFalse($company['enterprise_knowledge_memory_learning_stack']['memory_governance_policy']['automatic_canonical_doc_rewrite_allowed'], $companyId);
            $this->assertContains('playbook_delta_acceptance_rate', $company['enterprise_knowledge_memory_learning_stack']['learning_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_knowledge_memory_learning_stack']['knowledge_memory_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1', $company['enterprise_identity_access_data_sovereignty_stack']['schema'], $companyId);
            $this->assertSame('deny', $company['enterprise_identity_access_data_sovereignty_stack']['identity_access_policy']['default_access'], $companyId);
            $this->assertSame('vault_reference_only_no_secret_material_in_packet', $company['enterprise_identity_access_data_sovereignty_stack']['identity_access_policy']['credential_storage'], $companyId);
            $this->assertTrue($company['enterprise_identity_access_data_sovereignty_stack']['identity_access_policy']['cross_company_access_requires_handoff'], $companyId);
            $this->assertFalse($company['enterprise_identity_access_data_sovereignty_stack']['identity_access_policy']['privilege_escalation_auto_allowed'], $companyId);
            $this->assertFalse($company['enterprise_identity_access_data_sovereignty_stack']['tenant_isolation_model']['raw_context_pooling_allowed'], $companyId);
            $this->assertFalse($company['enterprise_identity_access_data_sovereignty_stack']['break_glass_and_revocation']['break_glass_allowed'], $companyId);
            $this->assertContains('least_privilege_coverage', $company['enterprise_identity_access_data_sovereignty_stack']['sovereignty_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_identity_access_data_sovereignty_stack']['sovereignty_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_control_tower_run_operations_stack.v1', $company['enterprise_control_tower_run_operations_stack']['schema'], $companyId);
            $this->assertSame('supervised_internal_control_tower', $company['enterprise_control_tower_run_operations_stack']['run_operations_policy']['mode'], $companyId);
            $this->assertFalse($company['enterprise_control_tower_run_operations_stack']['run_operations_policy']['external_side_effects_default'], $companyId);
            $this->assertFalse($company['enterprise_control_tower_run_operations_stack']['run_operations_policy']['run_without_decision_receipt_allowed'], $companyId);
            $this->assertTrue($company['enterprise_control_tower_run_operations_stack']['run_operations_policy']['operator_interrupt_supported'], $companyId);
            $this->assertContains('receipt_budget_allocated', $company['enterprise_control_tower_run_operations_stack']['run_queue_model']['admission_controls'], $companyId);
            $this->assertTrue($company['enterprise_control_tower_run_operations_stack']['human_interrupt_and_escalation_model']['handoff_packet_required'], $companyId);
            $this->assertContains('checkpoint_id', $company['enterprise_control_tower_run_operations_stack']['run_observability']['trace_fields'], $companyId);
            $this->assertNotEmpty($company['enterprise_control_tower_run_operations_stack']['control_tower_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_company_command_center_stack.v1', $company['enterprise_company_command_center_stack']['schema'], $companyId);
            $this->assertSame('domain_company_operating_command_center', $company['enterprise_company_command_center_stack']['command_center_policy']['mode'], $companyId);
            $this->assertFalse($company['enterprise_company_command_center_stack']['command_center_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_company_command_center_stack']['command_center_policy']['external_write_spend_trade_publish_deploy_delete_allowed'], $companyId);
            $this->assertFalse($company['enterprise_company_command_center_stack']['command_center_policy']['secret_material_in_packet_allowed'], $companyId);
            $this->assertContains('operator_interrupts', $company['enterprise_company_command_center_stack']['operator_console_views'][0]['sections'], $companyId);
            $this->assertNotEmpty($company['enterprise_company_command_center_stack']['command_center_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_semantic_operating_graph_stack.v1', $company['enterprise_semantic_operating_graph_stack']['schema'], $companyId);
            $this->assertSame('read_model_digital_twin_with_receipted_edges', $company['enterprise_semantic_operating_graph_stack']['graph_policy']['mode'], $companyId);
            $this->assertFalse($company['enterprise_semantic_operating_graph_stack']['graph_policy']['external_side_effects_from_graph_allowed'], $companyId);
            $this->assertTrue($company['enterprise_semantic_operating_graph_stack']['graph_policy']['stale_or_missing_edge_blocks_autonomy_claim'], $companyId);
            $this->assertTrue($company['enterprise_semantic_operating_graph_stack']['graph_export_contract']['operator_visualization_ready'], $companyId);
            $this->assertFalse($company['enterprise_semantic_operating_graph_stack']['graph_export_contract']['raw_secret_or_sensitive_payload_export_allowed'], $companyId);
            $this->assertContains('edge_coverage', $company['enterprise_semantic_operating_graph_stack']['graph_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_semantic_operating_graph_stack']['semantic_graph_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_delivery_assurance_stack.v1', $company['enterprise_delivery_assurance_stack']['schema'], $companyId);
            $this->assertTrue($company['enterprise_delivery_assurance_stack']['delivery_intake_contract']['reject_when_missing_acceptance_criteria'], $companyId);
            $this->assertContains('accepted_with_followup', $company['enterprise_delivery_assurance_stack']['acceptance_and_feedback_loop']['acceptance_states'], $companyId);
            $this->assertTrue($company['enterprise_delivery_assurance_stack']['delivery_risk_controls']['external_delivery_requires_operator_approval'], $companyId);
            $this->assertFalse($company['enterprise_delivery_assurance_stack']['delivery_risk_controls']['external_side_effects_default'], $companyId);
            $this->assertNotEmpty($company['enterprise_delivery_assurance_stack']['delivery_assurance_hash'], $companyId);
            $this->assertSame('atlas.ai.company.portfolio_finance_stack.v1', $company['portfolio_finance_stack']['schema'], $companyId);
            $this->assertFalse($company['portfolio_finance_stack']['budget_envelope']['external_spend_enabled'], $companyId);
            $this->assertContains('operator_signed_real_spend_mandate', $company['portfolio_finance_stack']['capital_allocation_gates'], $companyId);
            $this->assertTrue($company['portfolio_finance_stack']['investment_committee_packet']['operator_approval_required_for_real_capital'], $companyId);
            $this->assertSame('advisory_until_operator_approval', $company['portfolio_finance_stack']['resource_allocation_model']['portfolio_governor_mode'], $companyId);
            $this->assertNotEmpty($company['portfolio_finance_stack']['finance_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1', $company['enterprise_unit_economics_capacity_simulation_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_unit_economics_capacity_simulation_stack']['economics_policy']['synthetic_financial_claims_allowed'], $companyId);
            $this->assertTrue($company['enterprise_unit_economics_capacity_simulation_stack']['economics_policy']['real_pricing_or_capital_commitment_requires_operator_approval'], $companyId);
            $this->assertTrue($company['enterprise_unit_economics_capacity_simulation_stack']['economics_policy']['capacity_promotion_requires_observed_runs'], $companyId);
            $this->assertFalse($company['enterprise_unit_economics_capacity_simulation_stack']['economics_policy']['external_spend_default'], $companyId);
            $this->assertSame('impact_confidence_effort_risk_capacity_and_policy_weighted', $company['enterprise_unit_economics_capacity_simulation_stack']['investment_prioritization_model']['ranking_method'], $companyId);
            $this->assertFalse($company['enterprise_unit_economics_capacity_simulation_stack']['investment_prioritization_model']['real_capital_action_allowed'], $companyId);
            $this->assertContains('notional_cost_per_run', $company['enterprise_unit_economics_capacity_simulation_stack']['economics_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_unit_economics_capacity_simulation_stack']['economics_capacity_hash'], $companyId);
            $this->assertSame('atlas.ai.company.strategic_intelligence_stack.v1', $company['strategic_intelligence_stack']['schema'], $companyId);
            $this->assertTrue($company['strategic_intelligence_stack']['market_signal_system']['source_links_required'], $companyId);
            $this->assertFalse($company['strategic_intelligence_stack']['competitive_benchmark_model']['synthetic_scores_allowed'], $companyId);
            $this->assertContains('sandbox_integrations', $company['strategic_intelligence_stack']['roadmap']['horizon_2'], $companyId);
            $this->assertTrue($company['strategic_intelligence_stack']['learning_loop']['writes_require_review'], $companyId);
            $this->assertNotEmpty($company['strategic_intelligence_stack']['intelligence_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_grc_stack.v1', $company['enterprise_grc_stack']['schema'], $companyId);
            $this->assertTrue($company['enterprise_grc_stack']['control_framework']['exceptions_require_operator_review'], $companyId);
            $this->assertTrue($company['enterprise_grc_stack']['data_classification']['cross_company_sharing_requires_handoff'], $companyId);
            $this->assertSame('credential_vault_only', $company['enterprise_grc_stack']['privacy_and_security']['secret_handling'], $companyId);
            $this->assertTrue($company['enterprise_grc_stack']['business_continuity']['manual_fallback_required'], $companyId);
            $this->assertNotEmpty($company['enterprise_grc_stack']['grc_hash'], $companyId);
            $this->assertSame('atlas.ai.company.api_surface.v1', $company['api_surface']['schema'], $companyId);
            $this->assertNotEmpty($company['api_surface']['commands']['enterprise_analysis'], $companyId);
            $this->assertSame('atlas.ai.company.evaluation_harness.v1', $company['evaluation_harness']['schema'], $companyId);
            $this->assertContains('policy_violation_scan', $company['evaluation_harness']['evaluation_modes'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1', $company['enterprise_flow_benchmark_replay_stack']['schema'], $companyId);
            $this->assertSame('offline_eval_before_shadow_online_eval_after_observed_runs', $company['enterprise_flow_benchmark_replay_stack']['benchmark_policy']['mode'], $companyId);
            $this->assertFalse($company['enterprise_flow_benchmark_replay_stack']['benchmark_policy']['synthetic_score_claims_allowed'], $companyId);
            $this->assertFalse($company['enterprise_flow_benchmark_replay_stack']['benchmark_policy']['promotion_without_replay_green_allowed'], $companyId);
            $this->assertTrue($company['enterprise_flow_benchmark_replay_stack']['benchmark_policy']['external_model_or_paid_benchmark_requires_operator_approval'], $companyId);
            $this->assertTrue($company['enterprise_flow_benchmark_replay_stack']['promotion_quality_gates']['observed_online_runs_required_before_autonomy_claim'], $companyId);
            $this->assertContains('trace_grade_score', $company['enterprise_flow_benchmark_replay_stack']['benchmark_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_benchmark_replay_stack']['benchmark_replay_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_tooling_research_stack.v1', $company['enterprise_tooling_research_stack']['schema'], $companyId);
            $this->assertSame('typed_flow_with_specialist_crews_handoffs_guardrails_tracing_and_durable_resume', $company['enterprise_tooling_research_stack']['domain_adoption_strategy']['default_orchestration'], $companyId);
            $this->assertContains('evaluation_harness_green', $company['enterprise_tooling_research_stack']['enterprise_adoption_gates'], $companyId);
            $this->assertNotEmpty($company['enterprise_tooling_research_stack']['research_stack_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_domain_solution_stack.v1', $company['enterprise_domain_solution_stack']['schema'], $companyId);
            $this->assertSame('durable_flow_runtime_blueprint_with_domain_solution_module', $company['enterprise_domain_solution_stack']['solution_operating_model']['execution'], $companyId);
            $this->assertFalse($company['enterprise_domain_solution_stack']['solution_operating_model']['external_side_effects_default'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], count($company['enterprise_domain_solution_stack']['enterprise_solution_playbooks']), $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['enterprise_solution_playbooks'][0]['source_pack']['direct_hyperlinks_required'], $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['enterprise_solution_playbooks'][0]['execution_path']['durable_state_required'], $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['enterprise_solution_playbooks'][0]['tooling_contract']['mcp_or_api_adapter_required'], $companyId);
            $this->assertFalse($company['enterprise_domain_solution_stack']['enterprise_solution_playbooks'][0]['benchmark_contract']['synthetic_score_claims_allowed'], $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['domain_data_plane']['direct_source_hyperlinks_required'], $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['domain_data_plane']['cross_source_verification_required'], $companyId);
            $this->assertFalse($company['enterprise_domain_solution_stack']['domain_data_plane']['external_data_mutation_allowed'], $companyId);
            $this->assertTrue($company['enterprise_domain_solution_stack']['domain_expert_review_board']['second_reviewer_required_for_external_action'], $companyId);
            $this->assertNotEmpty($company['enterprise_domain_solution_stack']['domain_solution_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_vertical_solution_suite_stack.v1', $company['enterprise_vertical_solution_suite_stack']['schema'], $companyId);
            $this->assertSame('claude_financial_services_unified_domain_solution_generalized_to_every_company', $company['enterprise_vertical_solution_suite_stack']['suite_policy']['reference_pattern'], $companyId);
            $this->assertFalse($company['enterprise_vertical_solution_suite_stack']['suite_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_vertical_solution_suite_stack']['suite_policy']['external_execution_allowed_by_suite'], $companyId);
            $this->assertGreaterThanOrEqual(6, count($company['enterprise_vertical_solution_suite_stack']['solution_suites']), $companyId);
            $this->assertSame($company['readiness']['flow_count'], count($company['enterprise_vertical_solution_suite_stack']['flow_solution_kits']), $companyId);
            $this->assertSame($company['readiness']['connector_count'], count($company['enterprise_vertical_solution_suite_stack']['connector_solution_workbenches']), $companyId);
            $this->assertContains('external_effect_block_rate', $company['enterprise_vertical_solution_suite_stack']['suite_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_vertical_solution_suite_stack']['suite_stack_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_domain_business_execution_mesh_stack.v1', $company['enterprise_domain_business_execution_mesh_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_domain_business_execution_mesh_stack']['execution_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_domain_business_execution_mesh_stack']['execution_policy']['autonomous_external_write_spend_trade_publish_deploy_delete_allowed'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], count($company['enterprise_domain_business_execution_mesh_stack']['flow_execution_cells']), $companyId);
            $this->assertSame($company['readiness']['flow_count'], count($company['enterprise_domain_business_execution_mesh_stack']['flow_tool_kpi_matrix']), $companyId);
            $this->assertSame($company['readiness']['flow_count'], count($company['enterprise_domain_business_execution_mesh_stack']['domain_service_lanes']), $companyId);
            $this->assertContains('business_artifact_acceptance_rate', $company['enterprise_domain_business_execution_mesh_stack']['execution_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_domain_business_execution_mesh_stack']['execution_mesh_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_industry_solution_ecosystem_stack.v1', $company['enterprise_industry_solution_ecosystem_stack']['schema'], $companyId);
            $this->assertSame('claude_financial_services_style_industry_solution_adapted_per_company', $company['enterprise_industry_solution_ecosystem_stack']['ecosystem_policy']['reference_pattern'], $companyId);
            $this->assertTrue($company['enterprise_industry_solution_ecosystem_stack']['ecosystem_policy']['direct_source_hyperlinks_required'], $companyId);
            $this->assertTrue($company['enterprise_industry_solution_ecosystem_stack']['ecosystem_policy']['mcp_or_api_connector_workbench_required'], $companyId);
            $this->assertFalse($company['enterprise_industry_solution_ecosystem_stack']['ecosystem_policy']['external_side_effects_enabled'], $companyId);
            $this->assertContains('audit_trail_completeness', $company['enterprise_industry_solution_ecosystem_stack']['ecosystem_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_industry_solution_ecosystem_stack']['ecosystem_hash'], $companyId);
            $this->assertSame('atlas.ai.company.premium_enterprise_agent_reference_model.v1', $company['premium_enterprise_agent_reference_model']['schema'], $companyId);
            $this->assertFalse($company['premium_enterprise_agent_reference_model']['model_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['premium_enterprise_agent_reference_model']['model_policy']['external_side_effects_default'], $companyId);
            $this->assertTrue($company['premium_enterprise_agent_reference_model']['model_policy']['operator_mandate_required_for_external_write_spend_trade_publish_or_security_action'], $companyId);
            $this->assertSame(0, $company['premium_enterprise_agent_reference_model']['accelerated_activation_contract']['buildout_wait_days_required'], $companyId);
            $this->assertContains('fixture_suite_green', $company['premium_enterprise_agent_reference_model']['accelerated_activation_contract']['activation_sequence'], $companyId);
            $this->assertTrue($company['premium_enterprise_agent_reference_model']['replay_and_audit_harness']['determinism_and_faithfulness_measured_separately'], $companyId);
            $this->assertTrue($company['premium_enterprise_agent_reference_model']['replay_and_audit_harness']['telemetry_first_governance'], $companyId);
            $this->assertNotEmpty($company['premium_enterprise_agent_reference_model']['premium_model_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_flow_operating_package_stack.v1', $company['enterprise_flow_operating_packages']['schema'], $companyId);
            $this->assertFalse($company['enterprise_flow_operating_packages']['operating_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_flow_operating_packages']['operating_policy']['external_execution_allowed_by_package'], $companyId);
            $this->assertTrue($company['enterprise_flow_operating_packages']['operating_policy']['package_required_for_every_flow'], $companyId);
            $this->assertContains('runbook_drill_coverage', $company['enterprise_flow_operating_packages']['package_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_flow_operating_packages']['package_stack_hash'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_integration_activation_plan.v1', $company['enterprise_integration_activation_plan']['schema'], $companyId);
            $this->assertFalse($company['enterprise_integration_activation_plan']['activation_policy']['buildout_blocked_by_observed_history_window'], $companyId);
            $this->assertTrue($company['enterprise_integration_activation_plan']['activation_policy']['external_write_blocked_until_operator_mandate'], $companyId);
            $this->assertContains('shadow_mode', $company['enterprise_integration_activation_plan']['activation_policy']['promotion_sequence'], $companyId);
            $this->assertContains('receipt_hash', $company['enterprise_integration_activation_plan']['activation_observability']['required_signals'], $companyId);
            $this->assertNotEmpty($company['enterprise_integration_activation_plan']['activation_hash'], $companyId);
            $this->assertContains('guardrailed_tool_use', $company['enterprise_reference_architecture']['adopted_patterns'], $companyId);
            $this->assertTrue($company['enterprise_reference_architecture']['runtime_contract']['stateful_runs_required'], $companyId);
            $this->assertSame(64, strlen((string) $company['receipt_hash']), $companyId);

            foreach ($company['enterprise_agent_registry'] as $agent) {
                $this->assertSame('atlas.ai.company.enterprise_agent_registry_entry.v1', $agent['schema']);
                $this->assertContains('produce_typed_artifact', $agent['capabilities']);
                $this->assertTrue($agent['memory_scope']['cross_company_memory_requires_handoff']);
                $this->assertSame(0, $agent['evaluation_contract']['policy_findings_allowed']);
                $this->assertFalse($agent['external_side_effects']);
                $this->assertNotEmpty($agent['registry_hash']);
            }
            foreach ($company['enterprise_domain_agent_toolkit_stack']['framework_source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('benchmark_result', $source['evidence_required_before_adoption']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_domain_agent_toolkit_stack']['agent_toolkit_profiles'] as $profile) {
                $this->assertNotEmpty($profile['agent_role']);
                $this->assertNotEmpty($profile['toolkit_id']);
                $this->assertContains('domain_source_selection', $profile['core_skills']);
                $this->assertContains('policy_gate', $profile['tool_groups']['verification']);
                $this->assertContains('external_write', $profile['blocked_tool_groups_without_operator']);
                $this->assertTrue($profile['certification_required_before_shadow_mode']);
                $this->assertNotEmpty($profile['profile_hash']);
            }
            foreach ($company['enterprise_domain_agent_toolkit_stack']['flow_toolkit_assignments'] as $assignment) {
                $this->assertNotEmpty($assignment['flow_id']);
                $this->assertNotEmpty($assignment['assigned_toolkit']);
                $this->assertContains('source_grounding', $assignment['required_skill_sequence']);
                $this->assertContains('operator_checkpoint', $assignment['required_skill_sequence']);
                $this->assertSame('contract_and_fixture_green_before_shadow', $assignment['minimum_certification_state']);
                $this->assertNotEmpty($assignment['assignment_hash']);
            }
            $this->assertContains('https://github.com/crewAIInc/crewAI', $company['enterprise_domain_agent_toolkit_stack']['repository_and_agent_watchlist']['global_agent_frameworks'], $companyId);
            $this->assertContains('https://github.com/microsoft/autogen', $company['enterprise_domain_agent_toolkit_stack']['repository_and_agent_watchlist']['global_agent_frameworks'], $companyId);
            $this->assertContains('local_fixture_eval', $company['enterprise_domain_agent_toolkit_stack']['repository_and_agent_watchlist']['adoption_requires'], $companyId);
            $this->assertNotEmpty($company['enterprise_domain_agent_toolkit_stack']['repository_and_agent_watchlist']['watchlist_hash'], $companyId);
            foreach ($company['enterprise_domain_agent_toolkit_stack']['toolkit_certification_matrix'] as $certification) {
                $this->assertNotEmpty($certification['agent_role']);
                $this->assertContains('tool_schema_contract', $certification['required_checks']);
                $this->assertContains('receipt_export_green', $certification['required_checks']);
                $this->assertContains('policy_finding', $certification['promotion_blockers']);
                $this->assertNotEmpty($certification['certification_hash']);
            }
            $this->assertSame('atlas.ai.company.enterprise_external_research_adoption_stack.v1', $company['enterprise_external_research_adoption_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_external_research_adoption_stack']['research_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertTrue($company['enterprise_external_research_adoption_stack']['research_policy']['external_research_is_architecture_input_only'], $companyId);
            $this->assertFalse($company['enterprise_external_research_adoption_stack']['research_policy']['repository_adoption_without_license_security_and_fixture_eval_allowed'], $companyId);
            $this->assertContains('skills', $company['enterprise_external_research_adoption_stack']['domain_agent_operating_blueprint']['required_components'], $companyId);
            $this->assertContains('connectors', $company['enterprise_external_research_adoption_stack']['domain_agent_operating_blueprint']['required_components'], $companyId);
            $this->assertContains('subagents', $company['enterprise_external_research_adoption_stack']['domain_agent_operating_blueprint']['required_components'], $companyId);
            $this->assertContains('tool_receipts', $company['enterprise_external_research_adoption_stack']['domain_agent_operating_blueprint']['required_components'], $companyId);
            $this->assertGreaterThanOrEqual(12, $company['readiness']['external_research_source_count'], $companyId);
            $this->assertGreaterThanOrEqual(8, $company['readiness']['external_research_framework_repo_count'], $companyId);
            $this->assertGreaterThanOrEqual(3, $company['readiness']['external_research_domain_repo_count'], $companyId);
            $this->assertSame($company['readiness']['flow_count'], $company['readiness']['external_research_flow_adoption_count'], $companyId);
            $this->assertSame($company['readiness']['connector_count'], $company['readiness']['external_research_connector_backlog_count'], $companyId);
            $this->assertFalse($company['readiness']['external_research_wait_blocker_enabled'], $companyId);
            $sourceIds = array_column($company['enterprise_external_research_adoption_stack']['source_basis'], 'source_id');
            $this->assertContains('openai_agents_sdk', $sourceIds, $companyId);
            $this->assertContains('langgraph_durable_execution', $sourceIds, $companyId);
            $this->assertContains('anthropic_claude_for_financial_services', $sourceIds, $companyId);
            foreach ($company['enterprise_external_research_adoption_stack']['source_basis'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertSame('architecture_reviewed_local_runtime_adoption_requires_tests', $source['review_state']);
                $this->assertSame('reference_to_contract_only_until_license_security_fixture_and_operator_review', $source['adoption_boundary']);
                $this->assertFalse($source['external_side_effects_default']);
                $this->assertNotEmpty($source['source_hash']);
            }
            $frameworkRepos = array_column(
                $company['enterprise_external_research_adoption_stack']['repository_and_framework_catalog']['official_framework_repositories'],
                'repository_url',
            );
            $this->assertContains('https://github.com/openai/openai-agents-python', $frameworkRepos, $companyId);
            $this->assertContains('https://github.com/langchain-ai/langgraph', $frameworkRepos, $companyId);
            $this->assertContains('https://github.com/modelcontextprotocol/servers', $frameworkRepos, $companyId);
            foreach ($company['enterprise_external_research_adoption_stack']['repository_and_framework_catalog']['domain_repository_candidates'] as $repo) {
                $this->assertNotEmpty($repo['source_id']);
                $this->assertStringStartsWith('https://', $repo['repository_or_doc_url']);
                $this->assertContains('local_fixture_eval', $repo['adoption_requires']);
                $this->assertFalse($repo['external_side_effects_enabled']);
                $this->assertNotEmpty($repo['repository_hash']);
            }
            foreach ($company['enterprise_external_research_adoption_stack']['per_flow_adoption_matrix'] as $adoption) {
                $this->assertNotEmpty($adoption['flow_id']);
                $this->assertContains('local_fixture_eval_green', $adoption['adoption_gates']);
                $this->assertContains('operator_acceptance_green', $adoption['adoption_gates']);
                $this->assertContains('external_write', $adoption['blocked_until_gate_green']);
                $this->assertContains('quality_critic_agent', $adoption['subagent_roles']);
                $this->assertFalse($adoption['external_side_effects_enabled']);
                $this->assertNotEmpty($adoption['matrix_hash']);
            }
            foreach ($company['enterprise_external_research_adoption_stack']['connector_and_data_provider_backlog'] as $backlog) {
                $this->assertNotEmpty($backlog['connector_id']);
                $this->assertContains('mcp_server', $backlog['candidate_adapter_forms']);
                $this->assertContains('receipt_hash', $backlog['required_artifacts']);
                $this->assertFalse($backlog['external_side_effects_enabled']);
                $this->assertNotEmpty($backlog['backlog_hash']);
            }
            foreach ($company['enterprise_flow_orchestration_runbook_stack']['flow_runbooks'] as $flowRunbook) {
                $this->assertSame('atlas.ai.company.enterprise_flow_orchestration_runbook.v1', $flowRunbook['schema']);
                $this->assertNotEmpty($flowRunbook['flow_id']);
                $this->assertContains('policy_profile', $flowRunbook['intake_packet']['required_fields']);
                $this->assertTrue($flowRunbook['agent_graph']['handoff_packet_required']);
                $this->assertContains('tool_receipts_captured', $flowRunbook['checkpoint_lattice']['checkpoints']);
                $this->assertSame('resume_from_last_green_checkpoint_with_state_hash', $flowRunbook['checkpoint_lattice']['resume_strategy']);
                $this->assertSame(0, $flowRunbook['evaluation_and_acceptance']['policy_findings_allowed']);
                $this->assertTrue($flowRunbook['handoff_and_delivery']['external_delivery_requires_operator_approval']);
                $this->assertContains('checkpoint_resume_rate', $flowRunbook['observability_contract']['metrics']);
                $this->assertNotEmpty($flowRunbook['runbook_hash']);
            }
            foreach ($company['enterprise_flow_orchestration_runbook_stack']['shared_connector_backplane'] as $backplane) {
                $this->assertNotEmpty($backplane['connector_id']);
                $this->assertTrue($backplane['health_probe_required_before_run']);
                $this->assertTrue($backplane['receipt_export_required']);
                $this->assertSame('queue_or_degrade_to_manual_import_before_failure', $backplane['rate_limit_policy']);
                $this->assertNotEmpty($backplane['backplane_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('local_replay_result', $source['evidence_required_before_adoption']);
                $this->assertSame('pattern_reference_until_local_contract_tests_and_operator_review', $source['adoption_boundary']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['executable_flow_packets'] as $packet) {
                $this->assertSame('atlas.ai.company.executable_flow_packet.v1', $packet['schema']);
                $this->assertNotEmpty($packet['flow_id']);
                $this->assertContains('idempotency_key', $packet['input_contract']['required_fields']);
                $this->assertContains('connector_scope_uncertified', $packet['input_contract']['reject_when']);
                $this->assertSame('checkpoint_after_every_node_and_tool_receipt', $packet['execution_graph']['durability']);
                $this->assertTrue($packet['execution_graph']['resume_token_required']);
                $this->assertTrue($packet['execution_graph']['idempotency_required']);
                $this->assertContains('operator_checkpoint', $packet['execution_graph']['human_interrupt_points']);
                $this->assertGreaterThanOrEqual(0.88, $packet['output_contract']['quality_floor']);
                $this->assertSame(0, $packet['output_contract']['policy_findings_allowed']);
                $this->assertTrue($packet['runtime_state_contract']['replayable_without_external_mutation']);
                $this->assertNotEmpty($packet['packet_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['agent_tool_routing_matrix'] as $routing) {
                $this->assertNotEmpty($routing['flow_id']);
                $this->assertNotEmpty($routing['primary_agent']);
                $this->assertContains('least_privilege_connector_scope', $routing['routing_rules']);
                $this->assertContains('direct_external_write', $routing['blocked_routes']);
                $this->assertContains('direct_live_trade_or_offensive_security', $routing['blocked_routes']);
                $this->assertNotEmpty($routing['routing_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['flow_artifact_io_contracts'] as $contract) {
                $this->assertNotEmpty($contract['flow_id']);
                $this->assertContains('connector_receipts', $contract['required_lineage']);
                $this->assertTrue($contract['redaction_before_provider_payload']);
                $this->assertNotEmpty($contract['contract_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['supervision_and_shadow_runtime_gates'] as $gate) {
                $this->assertNotEmpty($gate['flow_id']);
                $this->assertContains('connector_certification_present', $gate['contract_stage_requires']);
                $this->assertContains('offline_replay_green', $gate['shadow_stage_requires']);
                $this->assertContains('operator_signed_mandate', $gate['supervised_stage_requires']);
                $this->assertContains('autonomy_claim', $gate['blocked_until_production_acceptance']);
                $this->assertNotEmpty($gate['gate_hash']);
            }
            foreach ($company['enterprise_flow_runtime_implementation_stack']['connector_runtime_adapters'] as $adapter) {
                $this->assertNotEmpty($adapter['connector_id']);
                $this->assertSame('contract_defined_probe_required_before_shadow', $adapter['adapter_state']);
                $this->assertContains('export_receipt', $adapter['supported_operations']);
                $this->assertContains('write', $adapter['blocked_operations']);
                $this->assertContains('openapi_or_mcp_schema', $adapter['adapter_requirements']);
                $this->assertNotEmpty($adapter['adapter_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['canonical_flow_fixtures'] as $fixture) {
                $this->assertSame('atlas.ai.company.canonical_flow_fixture.v1', $fixture['schema']);
                $this->assertNotEmpty($fixture['flow_id']);
                $this->assertSame('offline_fixture_no_external_mutation', $fixture['input_packet']['scope']);
                $this->assertContains('policy_findings_zero', $fixture['input_packet']['acceptance_criteria']);
                $this->assertGreaterThanOrEqual(0.88, $fixture['expected_output']['minimum_quality_score']);
                $this->assertNotEmpty($fixture['fixture_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['connector_stub_catalog'] as $stub) {
                $this->assertNotEmpty($stub['connector_id']);
                $this->assertContains('schema_drift', $stub['stub_modes']);
                $this->assertContains('no_external_mutation_attestation', $stub['must_emit']);
                $this->assertFalse($stub['external_side_effects']);
                $this->assertNotEmpty($stub['stub_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['expected_trace_trajectories'] as $trajectory) {
                $this->assertNotEmpty($trajectory['flow_id']);
                $this->assertContains('operator_checkpoint', $trajectory['expected_nodes']);
                $this->assertContains('tool_call_receipts', $trajectory['required_trace_fields']);
                $this->assertContains('external_write_call', $trajectory['must_not_include']);
                $this->assertNotEmpty($trajectory['trajectory_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['quality_assertion_suites'] as $assertionSuite) {
                $this->assertNotEmpty($assertionSuite['flow_id']);
                $this->assertContains('tool_receipt_coverage_full', $assertionSuite['assertions']);
                $this->assertSame('block_shadow_mode_open_flow_fixture_review', $assertionSuite['failure_action']);
                $this->assertNotEmpty($assertionSuite['assertion_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['failure_injection_cases'] as $caseSuite) {
                $this->assertNotEmpty($caseSuite['flow_id']);
                $this->assertContains('connector_schema_drift', $caseSuite['cases']);
                $this->assertSame('fail_closed_preserve_checkpoint_and_emit_review_packet', $caseSuite['expected_behavior']);
                $this->assertContains('mutate_external_system', $caseSuite['must_not_do']);
                $this->assertNotEmpty($caseSuite['case_hash']);
            }
            foreach ($company['enterprise_flow_fixture_simulation_stack']['dry_run_command_plan'] as $dryRun) {
                $this->assertNotEmpty($dryRun['flow_id']);
                $this->assertStringStartsWith('php artisan ', $dryRun['command']);
                $this->assertStringContainsString(' --action=smoke --json', $dryRun['command']);
                $this->assertSame(0, $dryRun['expected_exit_code']);
                $this->assertContains('ok', $dryRun['required_output_keys']);
                $this->assertNotEmpty($dryRun['dry_run_hash']);
            }
            foreach ($company['enterprise_flow_action_runtime_stack']['runtime_action_catalog'] as $action) {
                $this->assertSame('atlas.ai.company.runtime_action_contract.v1', $action['schema']);
                $this->assertNotEmpty($action['flow_id']);
                $this->assertSame($action['flow_id'], $action['action']);
                $this->assertStringStartsWith('php artisan ', $action['command']);
                $this->assertStringContainsString(' --fixture --json', $action['command']);
                $this->assertTrue($action['handler_contract']['idempotency_key_required']);
                $this->assertContains('operator_checkpoint', $action['runtime_phases']);
                $this->assertFalse($action['external_side_effects']);
                $this->assertNotEmpty($action['action_hash']);
            }
            foreach ($company['enterprise_flow_action_runtime_stack']['command_adapter_matrix'] as $adapter) {
                $this->assertNotEmpty($adapter['flow_id']);
                $this->assertNotEmpty($adapter['owner_agent']);
                $this->assertContains('connector_scope_certified', $adapter['required_before_handler_invocation']);
                $this->assertContains('trade', $adapter['blocked_without_operator']);
                $this->assertNotEmpty($adapter['adapter_hash']);
            }
            foreach ($company['enterprise_flow_action_runtime_stack']['handler_state_schemas'] as $stateSchema) {
                $this->assertNotEmpty($stateSchema['flow_id']);
                $this->assertContains('connector_receipts', $stateSchema['required_state_keys']);
                $this->assertContains('policy_gate', $stateSchema['checkpoint_after']);
                $this->assertTrue($stateSchema['state_hash_required']);
                $this->assertNotEmpty($stateSchema['schema_hash']);
            }
            foreach ($company['enterprise_flow_action_runtime_stack']['runtime_event_emission_plan'] as $eventPlan) {
                $this->assertNotEmpty($eventPlan['flow_id']);
                $this->assertContains('action_requested', $eventPlan['required_events']);
                $this->assertContains('operator_checkpointed', $eventPlan['required_events']);
                $this->assertStringEndsWith('.runtime_action_outbox', $eventPlan['outbox_topic']);
                $this->assertNotEmpty($eventPlan['event_hash']);
            }
            foreach ($company['enterprise_flow_action_runtime_stack']['operator_checkpoint_contracts'] as $checkpoint) {
                $this->assertNotEmpty($checkpoint['flow_id']);
                $this->assertContains('external_action_request', $checkpoint['checkpoint_required_for']);
                $this->assertContains('rollback_plan', $checkpoint['operator_packet_fields']);
                $this->assertFalse($checkpoint['auto_approval_allowed']);
                $this->assertNotEmpty($checkpoint['checkpoint_hash']);
            }
            foreach ($company['enterprise_workforce_capacity_stack']['agent_capacity_plan'] as $capacityPlan) {
                $this->assertNotEmpty($capacityPlan['agent_role']);
                $this->assertGreaterThanOrEqual(2, $capacityPlan['primary_capacity_units']);
                $this->assertTrue($capacityPlan['requires_backup']);
                $this->assertNotEmpty($capacityPlan['capacity_hash']);
            }
            foreach ($company['enterprise_workforce_capacity_stack']['flow_staffing_matrix'] as $staffing) {
                $this->assertNotEmpty($staffing['flow_id']);
                $this->assertNotEmpty($staffing['primary_agent']);
                $this->assertSame('independent_reviewer_agent', $staffing['reviewer']);
                $this->assertTrue($staffing['operator_checkpoint_required']);
                $this->assertSame('primary_backup_reviewer_defined', $staffing['minimum_staffing_state']);
                $this->assertNotEmpty($staffing['staffing_hash']);
            }
            foreach ($company['enterprise_workforce_capacity_stack']['training_and_enablement'] as $training) {
                $this->assertNotEmpty($training['agent_role']);
                $this->assertContains('policy_profile_handling', $training['required_training']);
                $this->assertTrue($training['certification_required_before_shadow_mode']);
                $this->assertNotEmpty($training['training_hash']);
            }
            foreach ($company['enterprise_portfolio_dependency_stack']['flow_dependency_routing'] as $routing) {
                $this->assertNotEmpty($routing['flow_id']);
                $this->assertTrue($routing['requires_dependency_check_before_execution']);
                $this->assertContains('cross_company_blocker', $routing['requires_portfolio_review_when']);
                $this->assertNotEmpty($routing['routing_hash']);
            }
            foreach ($company['enterprise_portfolio_dependency_stack']['integration_dependency_map'] as $dependency) {
                $this->assertNotEmpty($dependency['integration_contract']);
                $this->assertContains('read_only_probe', $dependency['required_controls']);
                $this->assertContains('operator_mandate_before_write', $dependency['required_controls']);
                $this->assertNotEmpty($dependency['dependency_hash']);
            }
            foreach ($company['business_process_map'] as $process) {
                $this->assertSame('atlas.ai.company.business_process_map.v1', $process['schema']);
                $this->assertContains('operator_checkpointed', $process['states']);
                $this->assertContains('receipt_after_tool_use', $process['controls']);
                $this->assertNotEmpty($process['process_hash']);
            }
            foreach ($company['deliverable_quality_contracts'] as $qualityContract) {
                $this->assertSame('atlas.ai.company.deliverable_quality_contract.v1', $qualityContract['schema']);
                $this->assertContains('evidence', $qualityContract['required_sections']);
                $this->assertContains('missing_evidence', $qualityContract['rejection_criteria']);
                $this->assertGreaterThanOrEqual(0.86, $qualityContract['quality_score_floor']);
                $this->assertNotEmpty($qualityContract['contract_hash']);
            }
            foreach ($company['go_to_production_pack']['slo_sli_catalog'] as $slo) {
                $this->assertNotEmpty($slo['flow_id']);
                $this->assertSame('critic_score_and_policy_findings', $slo['quality_sli']);
                $this->assertSame(0, $slo['target']['policy_findings_allowed']);
                $this->assertNotEmpty($slo['slo_hash']);
            }
            foreach ($company['go_to_production_pack']['integration_enablement_plan'] as $plan) {
                $this->assertContains('run_read_only_probe', $plan['enablement_steps']);
                $this->assertFalse($plan['external_side_effects_enabled']);
                $this->assertNotEmpty($plan['enablement_hash']);
            }
            foreach ($company['commercial_operating_stack']['service_catalog'] as $service) {
                $this->assertNotEmpty($service['service_id']);
                $this->assertTrue($service['external_delivery_requires_operator_approval']);
                $this->assertNotEmpty($service['service_hash']);
            }
            foreach ($company['commercial_operating_stack']['business_kpis'] as $kpi) {
                $this->assertSame('company_operating_packet.observed_metrics', $kpi['evidence_source']);
                $this->assertNotEmpty($kpi['kpi_hash']);
            }
            foreach ($company['enterprise_customer_market_operations_stack']['offer_and_packaging_catalog'] as $offer) {
                $this->assertNotEmpty($offer['offer_id']);
                $this->assertNotEmpty($offer['work_product']);
                $this->assertContains('receipt_hash', $offer['acceptance_artifacts']);
                $this->assertFalse($offer['external_offer_publication_allowed']);
                $this->assertNotEmpty($offer['offer_hash']);
            }
            foreach ($company['enterprise_customer_market_operations_stack']['journey_and_lifecycle_map'] as $journey) {
                $this->assertNotEmpty($journey['flow_id']);
                $this->assertContains('measure_outcome', $journey['journey_stages']);
                $this->assertContains('policy_block', $journey['dropoff_risks']);
                $this->assertNotEmpty($journey['journey_hash']);
            }
            foreach ($company['enterprise_customer_market_operations_stack']['customer_success_scorecard'] as $score) {
                $this->assertNotEmpty($score['metric']);
                $this->assertSame('company_operating_packet.observed_metrics_or_acceptance_records', $score['evidence_source']);
                $this->assertNotEmpty($score['scorecard_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('operator_approval_for_external_use', $source['evidence_required_before_adoption']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['account_segment_playbooks'] as $playbook) {
                $this->assertNotEmpty($playbook['segment']);
                $this->assertContains('review_health_score', $playbook['standard_actions']);
                $this->assertContains('external_invoice', $playbook['blocked_actions']);
                $this->assertTrue($playbook['operator_checkpoint_required']);
                $this->assertNotEmpty($playbook['playbook_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['contract_and_entitlement_catalog'] as $entitlement) {
                $this->assertNotEmpty($entitlement['work_product']);
                $this->assertContains('receipt_hash_present', $entitlement['acceptance_criteria']);
                $this->assertSame('internal_contract_template_ready', $entitlement['contract_status']);
                $this->assertFalse($entitlement['external_contract_signature_allowed']);
                $this->assertNotEmpty($entitlement['entitlement_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['onboarding_success_plans'] as $successPlan) {
                $this->assertNotEmpty($successPlan['flow_id']);
                $this->assertContains('first_value_packet_delivered', $successPlan['milestones']);
                $this->assertContains('feedback_record', $successPlan['required_artifacts']);
                $this->assertContains('next_review_scheduled', $successPlan['exit_criteria']);
                $this->assertNotEmpty($successPlan['success_plan_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['service_review_and_renewal_calendar'] as $calendar) {
                $this->assertNotEmpty($calendar['flow_id']);
                $this->assertContains('commercial_or_capacity_note', $calendar['required_review_sections']);
                $this->assertFalse($calendar['renewal_action_allowed']);
                $this->assertTrue($calendar['operator_review_required']);
                $this->assertNotEmpty($calendar['calendar_hash']);
            }
            foreach ($company['enterprise_account_contract_delivery_stack']['account_health_and_risk_register'] as $health) {
                $this->assertNotEmpty($health['metric']);
                $this->assertSame('open_account_risk_review', $health['playbook_trigger']);
                $this->assertSame('delivery_acceptance_or_observed_metric_receipt', $health['evidence_source']);
                $this->assertNotEmpty($health['health_hash']);
            }
            foreach ($company['enterprise_vendor_legal_procurement_stack']['vendor_due_diligence_register'] as $vendor) {
                $this->assertNotEmpty($vendor['vendor_or_tool_id']);
                $this->assertContains('license_or_terms', $vendor['risk_checks']);
                $this->assertContains('sandbox_probe_receipt', $vendor['required_evidence']);
                $this->assertFalse($vendor['external_side_effects_enabled']);
                $this->assertNotEmpty($vendor['vendor_hash']);
            }
            foreach ($company['enterprise_vendor_legal_procurement_stack']['source_terms_review_register'] as $sourceTerms) {
                $this->assertNotEmpty($sourceTerms['source_id']);
                $this->assertStringStartsWith('https://', $sourceTerms['url']);
                $this->assertSame('review_required_before_automated_ingestion', $sourceTerms['allowed_use_status']);
                $this->assertFalse($sourceTerms['external_ingestion_enabled']);
                $this->assertNotEmpty($sourceTerms['terms_hash']);
            }
            foreach ($company['enterprise_vendor_legal_procurement_stack']['flow_procurement_routing'] as $routing) {
                $this->assertNotEmpty($routing['flow_id']);
                $this->assertContains('new_connector', $routing['procurement_review_required_when']);
                $this->assertContains('credential_share', $routing['blocked_actions']);
                $this->assertNotEmpty($routing['routing_hash']);
            }
            foreach ($company['enterprise_vendor_legal_procurement_stack']['vendor_operability_scorecard'] as $scorecard) {
                $this->assertNotEmpty($scorecard['connector_id']);
                $this->assertContains('receipt_export', $scorecard['score_dimensions']);
                $this->assertGreaterThanOrEqual(0.86, $scorecard['minimum_score_before_shadow_mode']);
                $this->assertNotEmpty($scorecard['scorecard_hash']);
            }
            foreach ($company['enterprise_resilience_continuity_stack']['flow_failure_mode_analysis'] as $analysis) {
                $this->assertNotEmpty($analysis['flow_id']);
                $this->assertContains('policy_block', $analysis['failure_modes']);
                $this->assertContains('last_green_checkpoint', $analysis['recovery_evidence_required']);
                $this->assertSame('emit_blocked_packet_and_open_review_queue', $analysis['fallback_action']);
                $this->assertNotEmpty($analysis['fmea_hash']);
            }
            foreach ($company['enterprise_resilience_continuity_stack']['connector_resilience_plan'] as $connectorPlan) {
                $this->assertNotEmpty($connectorPlan['connector_id']);
                $this->assertContains('read_only_probe_receipt', $connectorPlan['health_checks']);
                $this->assertTrue($connectorPlan['fallback_required']);
                $this->assertTrue($connectorPlan['write_modes_remain_blocked']);
                $this->assertNotEmpty($connectorPlan['connector_resilience_hash']);
            }
            foreach ($company['enterprise_resilience_continuity_stack']['incident_exercise_program'] as $exercise) {
                $this->assertNotEmpty($exercise['flow_id']);
                $this->assertContains('operator_route_verified', $exercise['success_criteria']);
                $this->assertNotEmpty($exercise['exercise_hash']);
            }
            foreach ($company['enterprise_analytics_decision_intelligence_stack']['metric_lineage_catalog'] as $lineage) {
                $this->assertNotEmpty($lineage['metric_key']);
                $this->assertSame('company_operating_packet.observed_metrics', $lineage['source_of_record']);
                $this->assertContains('quality_review', $lineage['required_lineage']);
                $this->assertNotEmpty($lineage['lineage_hash']);
            }
            foreach ($company['enterprise_analytics_decision_intelligence_stack']['executive_dashboard_catalog'] as $dashboard) {
                $this->assertNotEmpty($dashboard['dashboard_id']);
                $this->assertNotEmpty($dashboard['sections']);
                $this->assertNotEmpty($dashboard['decision_use']);
                $this->assertNotEmpty($dashboard['dashboard_hash']);
            }
            foreach ($company['enterprise_analytics_decision_intelligence_stack']['flow_decision_register'] as $decision) {
                $this->assertNotEmpty($decision['flow_id']);
                $this->assertContains('evidence_refs', $decision['required_decision_fields']);
                $this->assertTrue($decision['action_register_required']);
                $this->assertNotEmpty($decision['decision_hash']);
            }
            foreach ($company['enterprise_analytics_decision_intelligence_stack']['scenario_and_forecast_model'] as $scenario) {
                $this->assertNotEmpty($scenario['flow_id']);
                $this->assertContains('blocked_case', $scenario['scenario_set']);
                $this->assertSame('advisory_only_until_observed_results', $scenario['promotion_use']);
                $this->assertNotEmpty($scenario['scenario_hash']);
            }
            foreach ($company['enterprise_analytics_decision_intelligence_stack']['work_product_analytics_map'] as $analytics) {
                $this->assertNotEmpty($analytics['work_product']);
                $this->assertSame('operator_or_target_company_acceptance', $analytics['acceptance_signal']);
                $this->assertNotEmpty($analytics['analytics_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['knowledge_source_registry'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertContains('quality_review', $source['required_evidence']);
                $this->assertFalse($source['canonical_write_allowed']);
                $this->assertNotEmpty($source['source_registry_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['flow_learning_loops'] as $learningLoop) {
                $this->assertNotEmpty($learningLoop['flow_id']);
                $this->assertContains('playbook_delta', $learningLoop['learning_outputs']);
                $this->assertSame('owner_doc_or_company_manager_review_before_canonical_write', $learningLoop['review_gate']);
                $this->assertNotEmpty($learningLoop['learning_loop_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['postmortem_and_retrospective_program'] as $postmortem) {
                $this->assertNotEmpty($postmortem['flow_id']);
                $this->assertContains('policy_block', $postmortem['triggers']);
                $this->assertContains('evidence_refs', $postmortem['required_sections']);
                $this->assertFalse($postmortem['external_disclosure_allowed_without_operator']);
                $this->assertNotEmpty($postmortem['postmortem_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['playbook_change_control'] as $changeControl) {
                $this->assertNotEmpty($changeControl['flow_id']);
                $this->assertContains('rollback_plan', $changeControl['required_diff_sections']);
                $this->assertContains('independent_reviewer_agent', $changeControl['approvers']);
                $this->assertFalse($changeControl['auto_apply_allowed']);
                $this->assertNotEmpty($changeControl['change_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['work_product_feedback_memory'] as $feedbackMemory) {
                $this->assertNotEmpty($feedbackMemory['work_product']);
                $this->assertContains('evidence_gap', $feedbackMemory['minimum_signals']);
                $this->assertStringContainsString('retain_summary_and_receipt_hash', $feedbackMemory['retention_policy']);
                $this->assertNotEmpty($feedbackMemory['feedback_hash']);
            }
            foreach ($company['enterprise_knowledge_memory_learning_stack']['connector_knowledge_sync_plan'] as $syncPlan) {
                $this->assertNotEmpty($syncPlan['connector_id']);
                $this->assertContains('sandbox_probe_green', $syncPlan['sync_preconditions']);
                $this->assertFalse($syncPlan['writeback_allowed']);
                $this->assertTrue($syncPlan['staleness_disclosure_required']);
                $this->assertNotEmpty($syncPlan['sync_hash']);
            }
            foreach ($company['enterprise_identity_access_data_sovereignty_stack']['agent_access_matrix'] as $accessRow) {
                $this->assertNotEmpty($accessRow['agent_role']);
                $this->assertContains('produce_typed_artifact', $accessRow['default_permissions']);
                $this->assertContains('approve_own_work', $accessRow['denied_permissions']);
                $this->assertTrue($accessRow['session_policy']['short_lived_token_required']);
                $this->assertNotEmpty($accessRow['access_hash']);
            }
            foreach ($company['enterprise_identity_access_data_sovereignty_stack']['flow_data_boundary_matrix'] as $boundary) {
                $this->assertNotEmpty($boundary['flow_id']);
                $this->assertContains('secret_or_credential', $boundary['blocked_data_classes_without_operator']);
                $this->assertContains('redaction_before_provider_or_external_tool', $boundary['egress_controls']);
                $this->assertSame('typed_handoff_packet_only', $boundary['cross_company_context_rule']);
                $this->assertNotEmpty($boundary['boundary_hash']);
            }
            foreach ($company['enterprise_identity_access_data_sovereignty_stack']['connector_secret_binding_plan'] as $binding) {
                $this->assertNotEmpty($binding['connector_id']);
                $this->assertSame('vault_path_reference_only', $binding['credential_binding']);
                $this->assertContains('operator_revoke', $binding['revocation_trigger']);
                $this->assertFalse($binding['secret_material_export_allowed']);
                $this->assertNotEmpty($binding['binding_hash']);
            }
            foreach ($company['enterprise_identity_access_data_sovereignty_stack']['sensitive_data_handling_catalog'] as $handling) {
                $this->assertNotEmpty($handling['data_class']);
                $this->assertNotEmpty($handling['provider_payload_rule']);
                $this->assertNotEmpty($handling['retention_rule']);
                $this->assertNotEmpty($handling['handling_hash']);
            }
            foreach ($company['enterprise_identity_access_data_sovereignty_stack']['purpose_consent_registry'] as $purpose) {
                $this->assertNotEmpty($purpose['flow_id']);
                $this->assertTrue($purpose['purpose_required']);
                $this->assertContains('personal_data', $purpose['consent_or_authority_required_when']);
                $this->assertSame('block_and_request_operator_review', $purpose['purpose_drift_action']);
                $this->assertNotEmpty($purpose['purpose_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['control_tower_lanes'] as $lane) {
                $this->assertNotEmpty($lane['flow_id']);
                $this->assertContains('state_checkpoint', $lane['required_run_artifacts']);
                $this->assertContains('identity_scope_green', $lane['blocked_until']);
                $this->assertNotEmpty($lane['lane_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['cadence_scheduler'] as $cadence) {
                $this->assertNotEmpty($cadence['cadence_id']);
                $this->assertContains('metric_snapshot', $cadence['required_inputs']);
                $this->assertSame('open_control_tower_exception', $cadence['missed_cadence_action']);
                $this->assertNotEmpty($cadence['cadence_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['incident_and_exception_desk'] as $exceptionDesk) {
                $this->assertNotEmpty($exceptionDesk['flow_id']);
                $this->assertContains('identity_scope_violation', $exceptionDesk['exception_types']);
                $this->assertContains('operator_escalated', $exceptionDesk['resolution_states']);
                $this->assertNotEmpty($exceptionDesk['exception_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['change_window_and_release_calendar'] as $changeWindow) {
                $this->assertNotEmpty($changeWindow['flow_id']);
                $this->assertContains('production_promotion_request', $changeWindow['change_types']);
                $this->assertTrue($changeWindow['rollback_required']);
                $this->assertNotEmpty($changeWindow['change_window_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['connector_operations_probe_plan'] as $probePlan) {
                $this->assertNotEmpty($probePlan['connector_id']);
                $this->assertSame('read_only_or_internal_no_mutation', $probePlan['probe_mode']);
                $this->assertContains('receipt_export_probe', $probePlan['health_checks']);
                $this->assertFalse($probePlan['auto_remediation_allowed']);
                $this->assertNotEmpty($probePlan['probe_hash']);
            }
            foreach ($company['enterprise_control_tower_run_operations_stack']['dashboard_operations_map'] as $dashboardMap) {
                $this->assertNotEmpty($dashboardMap['dashboard_id']);
                $this->assertContains('operator_interrupts', $dashboardMap['control_tower_sections']);
                $this->assertFalse($dashboardMap['external_action_buttons_allowed']);
                $this->assertNotEmpty($dashboardMap['dashboard_ops_hash']);
            }
            foreach ($company['enterprise_company_command_center_stack']['operating_cells'] as $cell) {
                $this->assertSame('atlas.ai.company.command_center_operating_cell.v1', $cell['schema']);
                $this->assertNotEmpty($cell['cell_id']);
                $this->assertContains('receipt_refs', $cell['required_outputs']);
                $this->assertContains('real_spend', $cell['blocked_actions_without_operator']);
                $this->assertNotEmpty($cell['cell_hash']);
            }
            foreach ($company['enterprise_company_command_center_stack']['flow_command_cards'] as $card) {
                $this->assertSame('atlas.ai.company.flow_command_card.v1', $card['schema']);
                $this->assertNotEmpty($card['flow_id']);
                $this->assertContains('source_verified', $card['run_states']);
                $this->assertContains('source_lineage_complete', $card['quality_gates']);
                $this->assertContains('operator_checkpoint_receipt', $card['required_receipts']);
                $this->assertFalse($card['external_execution_allowed']);
                $this->assertNotEmpty($card['card_hash']);
            }
            foreach ($company['enterprise_company_command_center_stack']['connector_workbench_panels'] as $panel) {
                $this->assertSame('atlas.ai.company.command_center_connector_panel.v1', $panel['schema']);
                $this->assertNotEmpty($panel['connector_id']);
                $this->assertContains('run_read_only_probe', $panel['allowed_actions']);
                $this->assertContains('secret_export', $panel['blocked_actions']);
                $this->assertFalse($panel['external_side_effects_enabled']);
                $this->assertNotEmpty($panel['panel_hash']);
            }
            foreach ($company['enterprise_semantic_operating_graph_stack']['node_catalog'] as $node) {
                $this->assertNotEmpty($node['node_id']);
                $this->assertNotEmpty($node['node_type']);
                $this->assertSame($companyId, $node['company_id']);
                $this->assertNotEmpty($node['node_hash']);
            }
            foreach ($company['enterprise_semantic_operating_graph_stack']['flow_relationship_edges'] as $edge) {
                $this->assertNotEmpty($edge['flow_id']);
                $this->assertContains('owned_by_agent', $edge['edge_types']);
                $this->assertContains('uses_connector', $edge['edge_types']);
                $this->assertContains('guarded_by_policy', $edge['edge_types']);
                $this->assertNotEmpty($edge['edge_hash']);
            }
            foreach ($company['enterprise_semantic_operating_graph_stack']['operating_views'] as $view) {
                $this->assertNotEmpty($view['view_id']);
                $this->assertNotEmpty($view['scope']);
                $this->assertNotEmpty($view['primary_use']);
                $this->assertNotEmpty($view['view_hash']);
            }
            foreach ($company['enterprise_semantic_operating_graph_stack']['drift_detection_rules'] as $rule) {
                $this->assertNotEmpty($rule['rule_id']);
                $this->assertNotEmpty($rule['detects']);
                $this->assertNotEmpty($rule['action']);
            }
            foreach ($company['enterprise_delivery_assurance_stack']['work_product_delivery_contracts'] as $deliveryContract) {
                $this->assertNotEmpty($deliveryContract['work_product']);
                $this->assertContains('receipt_hash', $deliveryContract['package_required_sections']);
                $this->assertContains('policy_findings_zero', $deliveryContract['acceptance_tests']);
                $this->assertSame('one_quality_repair_cycle_before_retriage', $deliveryContract['support_model']['revision_policy']);
                $this->assertNotEmpty($deliveryContract['delivery_hash']);
            }
            foreach ($company['enterprise_delivery_assurance_stack']['flow_delivery_sla'] as $deliverySla) {
                $this->assertNotEmpty($deliverySla['flow_id']);
                $this->assertSame(0, $deliverySla['quality_target']['policy_findings_allowed']);
                $this->assertTrue($deliverySla['quality_target']['receipt_required']);
                $this->assertNotEmpty($deliverySla['sla_hash']);
            }
            foreach ($company['portfolio_finance_stack']['flow_cost_centers'] as $costCenter) {
                $this->assertNotEmpty($costCenter['flow_id']);
                $this->assertNotEmpty($costCenter['cost_center']);
                $this->assertNotEmpty($costCenter['tracking_metric']);
                $this->assertNotEmpty($costCenter['cost_center_hash']);
            }
            foreach ($company['enterprise_unit_economics_capacity_simulation_stack']['flow_unit_economics'] as $unitEconomics) {
                $this->assertNotEmpty($unitEconomics['flow_id']);
                $this->assertContains('review_cycles', $unitEconomics['cost_drivers']);
                $this->assertContains('accepted_work_product', $unitEconomics['value_drivers']);
                $this->assertTrue($unitEconomics['requires_observed_receipts_before_claim']);
                $this->assertNotEmpty($unitEconomics['unit_hash']);
            }
            foreach ($company['enterprise_unit_economics_capacity_simulation_stack']['capacity_simulation_model'] as $simulation) {
                $this->assertNotEmpty($simulation['flow_id']);
                $this->assertContains('two_x_demand', $simulation['scenario_set']);
                $this->assertContains('connector_rate_limits', $simulation['simulation_inputs']);
                $this->assertSame('shadow_mode_capacity_green_with_operator_review', $simulation['promotion_gate']);
                $this->assertNotEmpty($simulation['simulation_hash']);
            }
            foreach ($company['enterprise_unit_economics_capacity_simulation_stack']['work_product_pricing_ladder'] as $pricing) {
                $this->assertNotEmpty($pricing['work_product']);
                $this->assertSame('notional_internal_transfer_price_until_external_offer_approved', $pricing['pricing_basis']);
                $this->assertFalse($pricing['external_price_publication_allowed']);
                $this->assertNotEmpty($pricing['pricing_hash']);
            }
            foreach ($company['enterprise_unit_economics_capacity_simulation_stack']['agent_capacity_cost_model'] as $agentCost) {
                $this->assertNotEmpty($agentCost['agent_role']);
                $this->assertContains('tool_invocations', $agentCost['cost_inputs']);
                $this->assertSame('pause_lower_priority_runs_and_open_capacity_review', $agentCost['overload_action']);
                $this->assertNotEmpty($agentCost['capacity_cost_hash']);
            }
            foreach ($company['enterprise_unit_economics_capacity_simulation_stack']['connector_cost_and_limit_model'] as $connectorCost) {
                $this->assertNotEmpty($connectorCost['connector_id']);
                $this->assertContains('receipt_export_required', $connectorCost['cost_controls']);
                $this->assertFalse($connectorCost['external_paid_upgrade_allowed']);
                $this->assertTrue($connectorCost['operator_review_required_for_paid_or_write_mode']);
                $this->assertNotEmpty($connectorCost['connector_cost_hash']);
            }
            foreach ($company['strategic_intelligence_stack']['rival_and_alternative_map'] as $rivalMap) {
                $this->assertNotEmpty($rivalMap['flow_id']);
                $this->assertContains('human_specialist_workflow', $rivalMap['rivals']);
                $this->assertContains('typed_handoffs', $rivalMap['differentiators']);
                $this->assertNotEmpty($rivalMap['map_hash']);
            }
            foreach ($company['strategic_intelligence_stack']['intelligence_kpis'] as $intelligenceKpi) {
                $this->assertSame('observed_metric_or_source_ref', $intelligenceKpi['minimum_evidence']);
                $this->assertNotEmpty($intelligenceKpi['kpi_hash']);
            }
            foreach ($company['enterprise_grc_stack']['vendor_and_tool_risk'] as $vendorRisk) {
                $this->assertNotEmpty($vendorRisk['connector_id']);
                $this->assertContains('least_privilege', $vendorRisk['required_assessments']);
                $this->assertSame('assessment_required_before_production', $vendorRisk['status']);
                $this->assertNotEmpty($vendorRisk['vendor_risk_hash']);
            }
            foreach ($company['enterprise_grc_stack']['audit_evidence_requirements'] as $auditRequirement) {
                $this->assertNotEmpty($auditRequirement['flow_id']);
                $this->assertContains('policy_check', $auditRequirement['required_evidence']);
                $this->assertNotEmpty($auditRequirement['audit_hash']);
            }
            foreach ($company['enterprise_capability_matrix'] as $row) {
                $this->assertSame('atlas.ai.company.enterprise_capability_matrix_row.v1', $row['schema']);
                $this->assertTrue($row['capability_stages']['handoff']['typed_handoff_packet']);
                $this->assertFalse($row['external_side_effects']);
                $this->assertNotEmpty($row['matrix_hash']);
            }
            foreach ($company['external_integration_catalog'] as $integration) {
                $this->assertSame('atlas.ai.company.external_integration_contract.v1', $integration['schema']);
                $this->assertSame('contract_ready_read_or_internal_only', $integration['current_mode']);
                $this->assertContains('operator_signed_side_effect_mandate', $integration['production_enablement_requirements']);
                $this->assertContains('write', $integration['blocked_until_requirements_met']);
                $this->assertNotEmpty($integration['integration_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('contract_test_result', $source['evidence_required_before_adoption']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['adapter_contract_catalog'] as $contract) {
                $this->assertNotEmpty($contract['connector_id']);
                $this->assertContains('openapi', $contract['supported_contract_forms']);
                $this->assertContains('mcp_tool_schema', $contract['supported_contract_forms']);
                $this->assertContains('auth_scope', $contract['required_contract_fields']);
                $this->assertTrue($contract['schema_validation_required']);
                $this->assertNotEmpty($contract['contract_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['auth_and_secret_boundary'] as $boundary) {
                $this->assertNotEmpty($boundary['connector_id']);
                $this->assertSame('vault_reference_only', $boundary['credential_binding']);
                $this->assertContains('write', $boundary['blocked_scope_expansions_without_operator']);
                $this->assertFalse($boundary['secret_material_in_packet_allowed']);
                $this->assertNotEmpty($boundary['auth_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['sandbox_probe_matrix'] as $probe) {
                $this->assertNotEmpty($probe['connector_id']);
                $this->assertContains('read_only_ping', $probe['probe_modes']);
                $this->assertContains('no_external_mutation', $probe['success_criteria']);
                $this->assertSame('block_connector_and_open_integration_review', $probe['failure_action']);
                $this->assertNotEmpty($probe['probe_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['consumer_provider_contract_tests'] as $contractTest) {
                $this->assertNotEmpty($contractTest['connector_id']);
                $this->assertContains('response_matches_schema', $contractTest['provider_verification']);
                $this->assertSame('cannot_promote_connector_until_contract_verified', $contractTest['deployment_gate']);
                $this->assertNotEmpty($contractTest['test_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['connector_data_mapping_and_lineage'] as $mapping) {
                $this->assertNotEmpty($mapping['connector_id']);
                $this->assertContains('transform_hash', $mapping['lineage_required']);
                $this->assertTrue($mapping['redaction_required_before_provider_payload']);
                $this->assertNotEmpty($mapping['mapping_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['flow_connector_usage_matrix'] as $usage) {
                $this->assertNotEmpty($usage['flow_id']);
                $this->assertContains('read', $usage['allowed_modes']);
                $this->assertContains('analyze', $usage['allowed_modes']);
                $this->assertContains('propose', $usage['allowed_modes']);
                $this->assertContains('write', $usage['blocked_modes']);
                $this->assertContains('sandbox_probe_green', $usage['pre_run_requirements']);
                $this->assertNotEmpty($usage['usage_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['replay_fixture_and_mock_server_plan'] as $fixture) {
                $this->assertNotEmpty($fixture['connector_id']);
                $this->assertContains('permission_denied_response', $fixture['fixture_requirements']);
                $this->assertTrue($fixture['required_for_offline_eval']);
                $this->assertNotEmpty($fixture['fixture_hash']);
            }
            foreach ($company['enterprise_connector_certification_stack']['connector_slo_and_failure_mode_catalog'] as $slo) {
                $this->assertNotEmpty($slo['connector_id']);
                $this->assertContains('schema_drift', $slo['failure_modes']);
                $this->assertSame('degrade_to_manual_import_or_cached_read_model_with_staleness_disclosure', $slo['fallback']);
                $this->assertNotEmpty($slo['slo_hash']);
            }
            foreach ($company['enterprise_production_connector_preflight_stack']['connector_preflight_contracts'] as $preflight) {
                $this->assertSame('atlas.ai.company.connector_production_preflight_contract.v1', $preflight['schema']);
                $this->assertNotEmpty($preflight['connector_id']);
                $this->assertSame('vault_reference_required_no_secret_material', $preflight['credential_vault_binding']['binding_mode']);
                $this->assertTrue($preflight['credential_vault_binding']['attestation_required']);
                $this->assertFalse($preflight['credential_vault_binding']['credential_material_in_packet_allowed']);
                $this->assertContains('write', $preflight['scope_contract']['blocked_scope_without_signed_mandate']);
                $this->assertContains('spend', $preflight['scope_contract']['blocked_scope_without_signed_mandate']);
                $this->assertContains('trade', $preflight['scope_contract']['blocked_scope_without_signed_mandate']);
                $this->assertContains('admin', $preflight['scope_contract']['blocked_scope_without_signed_mandate']);
                $this->assertContains('secret_export', $preflight['scope_contract']['blocked_scope_without_signed_mandate']);
                $this->assertTrue($preflight['live_data_readiness']['source_lineage_required']);
                $this->assertFalse($preflight['live_data_readiness']['live_mutation_allowed']);
                $this->assertContains('no_external_mutation_attestation', $preflight['non_production_dress_rehearsal']['must_emit']);
                $this->assertFalse($preflight['cost_and_rate_limit_envelope']['spend_without_cap_allowed']);
                $this->assertTrue($preflight['rollback_and_fallback']['rollback_drill_required_before_external_mutation']);
                $this->assertNotEmpty($preflight['preflight_hash']);
            }
            foreach ($company['enterprise_production_connector_preflight_stack']['flow_connector_cutover_matrix'] as $cutover) {
                $this->assertNotEmpty($cutover['flow_id']);
                $this->assertContains('operator_signed_scope', $cutover['required_cutover_evidence']);
                $this->assertTrue($cutover['manual_handoff_packet_required']);
                $this->assertFalse($cutover['auto_execute_allowed']);
                $this->assertFalse($cutover['external_side_effects_enabled']);
                $this->assertNotEmpty($cutover['cutover_hash']);
            }
            foreach ($company['enterprise_production_connector_preflight_stack']['production_readiness_evidence_register'] as $evidenceRegister) {
                $this->assertNotEmpty($evidenceRegister['connector_id']);
                $this->assertContains('vault_scope_attestation_hash', $evidenceRegister['required_evidence']);
                $this->assertContains('operator_mandate_hash', $evidenceRegister['required_evidence']);
                $this->assertContains('real_vault_binding', $evidenceRegister['missing_before_real_execution']);
                $this->assertFalse($evidenceRegister['external_side_effects_enabled']);
                $this->assertNotEmpty($evidenceRegister['evidence_register_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('benchmark_run_receipt', $source['evidence_required_before_adoption']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['offline_dataset_contracts'] as $dataset) {
                $this->assertNotEmpty($dataset['flow_id']);
                $this->assertGreaterThanOrEqual(10, $dataset['minimum_examples']);
                $this->assertContains('policy_boundary', $dataset['example_types']);
                $this->assertContains('expected_tool_trajectory', $dataset['required_fields']);
                $this->assertTrue($dataset['reference_output_required']);
                $this->assertNotEmpty($dataset['dataset_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['trace_grading_rubrics'] as $rubric) {
                $this->assertNotEmpty($rubric['flow_id']);
                $this->assertContains('tool_selection', $rubric['graded_trace_components']);
                $this->assertContains('trajectory_correctness', $rubric['score_keys']);
                $this->assertContains('wrong_tool', $rubric['failure_modes']);
                $this->assertGreaterThanOrEqual(0.86, $rubric['minimum_score']);
                $this->assertNotEmpty($rubric['rubric_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['adversarial_regression_cases'] as $case) {
                $this->assertNotEmpty($case['flow_id']);
                $this->assertContains('prompt_injection', $case['case_types']);
                $this->assertSame('fail_closed_emit_review_packet_and_preserve_checkpoint', $case['expected_behavior']);
                $this->assertContains('perform_external_side_effect', $case['must_not_do']);
                $this->assertNotEmpty($case['case_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['deterministic_state_assertions'] as $assertion) {
                $this->assertNotEmpty($assertion['flow_id']);
                $this->assertContains('tool_receipt', $assertion['state_objects']);
                $this->assertContains('no_external_action_without_operator_mandate', $assertion['assertions']);
                $this->assertNotEmpty($assertion['primary_metric']);
                $this->assertNotEmpty($assertion['assertion_hash']);
            }
            foreach ($company['enterprise_flow_benchmark_replay_stack']['replay_and_comparison_matrix'] as $replay) {
                $this->assertNotEmpty($replay['flow_id']);
                $this->assertContains('latest_runbook', $replay['replay_modes']);
                $this->assertContains('tool_receipt_coverage', $replay['comparison_dimensions']);
                $this->assertContains('replay_receipt_hash', $replay['required_artifacts']);
                $this->assertNotEmpty($replay['replay_hash']);
            }
            foreach ($company['enterprise_tooling_research_stack']['source_catalog'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertContains('benchmark_result', $source['evidence_required_before_adoption']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['enterprise_tooling_research_stack']['per_flow_tooling_benchmark'] as $benchmark) {
                $this->assertNotEmpty($benchmark['flow_id']);
                $this->assertGreaterThanOrEqual(4, count($benchmark['candidate_patterns']));
                $this->assertContains('durable_resume', $benchmark['minimum_benchmark_dimensions']);
                $this->assertContains('agent_trace', $benchmark['required_artifacts']);
                $this->assertNotEmpty($benchmark['benchmark_hash']);
            }
            foreach ($company['enterprise_tooling_research_stack']['connector_integration_backlog'] as $backlogItem) {
                $this->assertNotEmpty($backlogItem['connector_id']);
                $this->assertContains('mcp_server', $backlogItem['adapter_options']);
                $this->assertContains('run_read_only_probe', $backlogItem['enablement_sequence']);
                $this->assertFalse($backlogItem['external_side_effects_enabled']);
                $this->assertNotEmpty($backlogItem['backlog_hash']);
            }
            $this->assertSame('atlas.ai.company.enterprise_agent_repository_adoption_pipeline.v1', $company['enterprise_agent_repository_adoption_pipeline']['schema'], $companyId);
            $this->assertFalse($company['enterprise_agent_repository_adoption_pipeline']['pipeline_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_agent_repository_adoption_pipeline']['pipeline_policy']['runtime_use_before_local_contract_tests_allowed'], $companyId);
            $this->assertFalse($company['enterprise_agent_repository_adoption_pipeline']['pipeline_policy']['external_side_effects_enabled'], $companyId);
            $this->assertContains('version_pin_coverage', $company['enterprise_agent_repository_adoption_pipeline']['pipeline_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_agent_repository_adoption_pipeline']['pipeline_hash'], $companyId);
            foreach ($company['enterprise_agent_repository_adoption_pipeline']['repository_intake_queue'] as $intakeItem) {
                $this->assertSame('atlas.ai.company.repository_intake_item.v1', $intakeItem['schema']);
                $this->assertNotEmpty($intakeItem['repository_id']);
                $this->assertStringStartsWith('https://', $intakeItem['url']);
                $this->assertContains('license', $intakeItem['required_reviews']);
                $this->assertContains('fixture_eval_result', $intakeItem['required_artifacts']);
                $this->assertFalse($intakeItem['external_side_effects_enabled']);
                $this->assertNotEmpty($intakeItem['intake_hash']);
            }
            foreach ($company['enterprise_agent_repository_adoption_pipeline']['framework_adoption_scorecards'] as $scorecard) {
                $this->assertSame('atlas.ai.company.framework_adoption_scorecard.v1', $scorecard['schema']);
                $this->assertContains('durable_resume', $scorecard['fit_dimensions']);
                $this->assertContains('fixture_eval_green', $scorecard['minimum_evidence_before_adoption']);
                $this->assertFalse($scorecard['external_side_effects_enabled']);
                if ($scorecard['framework_id'] === 'microsoft_autogen') {
                    $this->assertSame('migration_reference_only', $scorecard['adoption_state']);
                    $this->assertContains('maintenance_mode_detected_use_microsoft_agent_framework_migration_path_before_new_adoption', $scorecard['risk_findings']);
                }
            }
            foreach ($company['enterprise_agent_repository_adoption_pipeline']['flow_repository_implementation_epics'] as $epic) {
                $this->assertSame('atlas.ai.company.flow_repository_implementation_epic.v1', $epic['schema']);
                $this->assertNotEmpty($epic['flow_id']);
                $this->assertContains('pin_repository_versions', $epic['implementation_steps']);
                $this->assertContains('sbom_or_dependency_snapshot_present', $epic['definition_of_done']);
                $this->assertFalse($epic['external_execution_allowed']);
                $this->assertNotEmpty($epic['epic_hash']);
            }
            foreach ($company['enterprise_agent_repository_adoption_pipeline']['version_pin_and_supply_chain_plan'] as $pinPlan) {
                $this->assertNotEmpty($pinPlan['repository_id']);
                $this->assertSame('explicit_version_or_commit_before_runtime', $pinPlan['pinning_mode']);
                $this->assertContains('upgrade_rollback_plan', $pinPlan['required_supply_chain_artifacts']);
                $this->assertFalse($pinPlan['auto_upgrade_allowed']);
                $this->assertFalse($pinPlan['production_runtime_allowed_before_pin']);
            }
            $this->assertContains('maintenance_mode', $company['enterprise_agent_repository_adoption_pipeline']['migration_and_deprecation_matrix']['tracked_risks'], $companyId);
            foreach ($company['enterprise_domain_solution_stack']['domain_source_catalog'] as $domainSource) {
                $this->assertNotEmpty($domainSource['source_id']);
                $this->assertStringStartsWith('https://', $domainSource['url']);
                $this->assertTrue($domainSource['source_links_required']);
                $this->assertFalse($domainSource['external_side_effects_default']);
                $this->assertNotEmpty($domainSource['source_hash']);
            }
            foreach ($company['enterprise_domain_solution_stack']['solution_modules'] as $module) {
                $this->assertNotEmpty($module['module_id']);
                $this->assertNotEmpty($module['flow_id']);
                $this->assertContains('source_ingestion', $module['capability_bundle']['sense']);
                $this->assertContains('operator_checkpoint_before_external_action', $module['capability_bundle']['act']);
                $this->assertSame(0, $module['service_level']['policy_findings_allowed']);
                $this->assertNotEmpty($module['module_hash']);
            }
            foreach ($company['enterprise_domain_solution_stack']['managed_agent_templates'] as $template) {
                $this->assertNotEmpty($template['agent_template_id']);
                $this->assertContains('domain_source_selection', $template['skills']);
                $this->assertTrue($template['collaboration_contract']['handoff_packet_required']);
                $this->assertTrue($template['collaboration_contract']['operator_checkpoint_for_external_action']);
                $this->assertNotEmpty($template['agent_template_hash']);
            }
            foreach ($company['enterprise_domain_solution_stack']['data_product_catalog'] as $dataProduct) {
                $this->assertNotEmpty($dataProduct['data_product_id']);
                $this->assertContains('source_ids', $dataProduct['required_lineage']);
                $this->assertSame('operator_or_cross_company_handoff', $dataProduct['consumer']);
                $this->assertNotEmpty($dataProduct['data_product_hash']);
            }
            $this->assertSame('atlas.ai.company.enterprise_domain_provider_workbench_stack.v1', $company['enterprise_domain_provider_workbench_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_domain_provider_workbench_stack']['workbench_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_domain_provider_workbench_stack']['workbench_policy']['provider_write_or_paid_action_default'], $companyId);
            $this->assertTrue($company['enterprise_domain_provider_workbench_stack']['workbench_policy']['real_provider_terms_review_required'], $companyId);
            $this->assertTrue($company['enterprise_domain_provider_workbench_stack']['workbench_policy']['source_claims_require_provider_lineage'], $companyId);
            $this->assertContains('provider_eval_pass_rate', $company['enterprise_domain_provider_workbench_stack']['provider_workbench_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_domain_provider_workbench_stack']['provider_workbench_hash'], $companyId);
            foreach ($company['enterprise_domain_provider_workbench_stack']['provider_contracts'] as $providerContract) {
                $this->assertSame('atlas.ai.company.domain_provider_contract.v1', $providerContract['schema']);
                $this->assertNotEmpty($providerContract['provider_id']);
                $this->assertStringStartsWith('https://', $providerContract['url']);
                $this->assertContains('read_only_api_probe', $providerContract['integration_modes']);
                $this->assertContains('terms', $providerContract['required_reviews']);
                $this->assertContains('read_probe_receipt_hash', $providerContract['minimum_evidence_before_runtime']);
                $this->assertFalse($providerContract['external_side_effects_enabled']);
                $this->assertNotEmpty($providerContract['contract_hash']);
            }
            foreach ($company['enterprise_domain_provider_workbench_stack']['connector_workbenches'] as $providerWorkbench) {
                $this->assertNotEmpty($providerWorkbench['connector_id']);
                $this->assertContains('read_only_probe', $providerWorkbench['supported_modes']);
                $this->assertContains('lineage_capture', $providerWorkbench['required_capabilities']);
                $this->assertContains('secret_export', $providerWorkbench['blocked_capabilities_without_signed_scope']);
                $this->assertTrue($providerWorkbench['mcp_or_api_adapter_contract_required']);
                $this->assertSame('vault_reference_only', $providerWorkbench['credential_binding']);
                $this->assertFalse($providerWorkbench['external_side_effects_enabled']);
                $this->assertNotEmpty($providerWorkbench['workbench_hash']);
            }
            foreach ($company['enterprise_domain_provider_workbench_stack']['flow_provider_routes'] as $providerRoute) {
                $this->assertNotEmpty($providerRoute['flow_id']);
                $this->assertNotEmpty($providerRoute['primary_provider_ids']);
                $this->assertContains('operator_review', $providerRoute['route_stages']);
                $this->assertContains('lineage_hash', $providerRoute['required_receipts']);
                $this->assertFalse($providerRoute['external_execution_allowed']);
                $this->assertNotEmpty($providerRoute['route_hash']);
            }
            foreach ($company['enterprise_domain_provider_workbench_stack']['provider_evaluation_cases'] as $providerEval) {
                $this->assertNotEmpty($providerEval['flow_id']);
                $this->assertGreaterThanOrEqual(15, $providerEval['minimum_cases_before_shadow']);
                $this->assertContains('schema_drift', $providerEval['case_types']);
                $this->assertContains('source_faithfulness', $providerEval['required_scores']);
                $this->assertTrue($providerEval['promotion_requires_green_provider_eval']);
                $this->assertNotEmpty($providerEval['case_hash']);
            }
            foreach ($company['enterprise_domain_provider_workbench_stack']['provider_data_product_lineage'] as $lineage) {
                $this->assertNotEmpty($lineage['provider_id']);
                $this->assertContains('source_uri', $lineage['required_fields']);
                $this->assertContains('output_hash', $lineage['required_fields']);
                $this->assertTrue($lineage['staleness_disclosure_required']);
                $this->assertTrue($lineage['redaction_before_model_or_external_tool_required']);
                $this->assertNotEmpty($lineage['lineage_hash']);
            }
            $this->assertSame('atlas.ai.company.industry_data_interface.v1', $company['enterprise_industry_solution_ecosystem_stack']['industry_data_interface']['schema'], $companyId);
            $this->assertContains('cross_source_check', $company['enterprise_industry_solution_ecosystem_stack']['industry_data_interface']['verification_controls'], $companyId);
            $this->assertContains('vault_reference_only', $company['enterprise_industry_solution_ecosystem_stack']['industry_data_interface']['data_protection_controls'], $companyId);
            foreach ($company['enterprise_industry_solution_ecosystem_stack']['ecosystem_provider_catalog'] as $ecosystemProvider) {
                $this->assertSame('atlas.ai.company.industry_ecosystem_provider.v1', $ecosystemProvider['schema']);
                $this->assertStringStartsWith('https://', $ecosystemProvider['url']);
                $this->assertContains('sandbox_probe', $ecosystemProvider['required_before_live_use']);
                $this->assertTrue($ecosystemProvider['claim_verification_required']);
                $this->assertFalse($ecosystemProvider['external_side_effects_enabled']);
                $this->assertNotEmpty($ecosystemProvider['provider_hash']);
            }
            foreach ($company['enterprise_industry_solution_ecosystem_stack']['implementation_partner_tracks'] as $partnerTrack) {
                $this->assertSame('atlas.ai.company.implementation_partner_track.v1', $partnerTrack['schema']);
                $this->assertSame('reference_track_no_auto_procurement', $partnerTrack['commercial_status']);
                $this->assertTrue($partnerTrack['operator_procurement_required']);
                $this->assertFalse($partnerTrack['external_side_effects_enabled']);
                $this->assertContains('measurement_model', $partnerTrack['deliverables']);
            }
            foreach ($company['enterprise_industry_solution_ecosystem_stack']['flow_solution_workload_packs'] as $workloadPack) {
                $this->assertSame('atlas.ai.company.flow_solution_workload_pack.v1', $workloadPack['schema']);
                $this->assertNotEmpty($workloadPack['flow_id']);
                $this->assertContains('audit_trail_export', $workloadPack['workload_patterns']);
                $this->assertTrue($workloadPack['capacity_profile']['requires_queue_and_dlq']);
                $this->assertTrue($workloadPack['source_verification']['every_material_claim_links_source']);
                $this->assertFalse($workloadPack['external_execution_allowed']);
                $this->assertNotEmpty($workloadPack['workload_hash']);
            }
            foreach ($company['premium_enterprise_agent_reference_model']['reference_source_basis'] as $source) {
                $this->assertNotEmpty($source['source_id']);
                $this->assertStringStartsWith('https://', $source['url']);
                $this->assertNotEmpty($source['adopted_pattern']);
                $this->assertSame('reference_reviewed_for_architecture_not_runtime_ingested', $source['review_state']);
                $this->assertNotEmpty($source['source_hash']);
            }
            foreach ($company['premium_enterprise_agent_reference_model']['managed_agent_templates'] as $template) {
                $this->assertNotEmpty($template['template_id']);
                $this->assertContains('receipt_export', $template['skills']);
                $this->assertSame('least_privilege_read_or_fixture_until_operator_mandate', $template['tool_permissions']);
                $this->assertTrue($template['audit_log_required']);
                $this->assertTrue($template['human_in_loop_required_before_external_action']);
                $this->assertNotEmpty($template['template_hash']);
            }
            foreach ($company['premium_enterprise_agent_reference_model']['flow_template_map'] as $flowTemplate) {
                $this->assertNotEmpty($flowTemplate['flow_id']);
                $this->assertNotEmpty($flowTemplate['primary_template_id']);
                $this->assertContains('operator_checkpoint', $flowTemplate['required_artifacts']);
                $this->assertFalse($flowTemplate['external_side_effects']);
                $this->assertNotEmpty($flowTemplate['map_hash']);
            }
            foreach ($company['premium_enterprise_agent_reference_model']['data_and_tool_workbenches'] as $workbench) {
                $this->assertNotEmpty($workbench['connector_id']);
                $this->assertSame('read_only_or_internal_fixture_until_operator_mandate', $workbench['access_mode']);
                $this->assertContains('external_write', $workbench['blocked_capabilities_without_operator']);
                $this->assertTrue($workbench['probe_required_before_shadow']);
                $this->assertNotEmpty($workbench['workbench_hash']);
            }
            foreach ($company['enterprise_flow_operating_packages']['flow_packages'] as $package) {
                $this->assertSame('atlas.ai.company.enterprise_flow_operating_package.v1', $package['schema']);
                $this->assertNotEmpty($package['package_id']);
                $this->assertNotEmpty($package['flow_id']);
                $this->assertNotEmpty($package['premium_template_id']);
                $this->assertSame('operator_signed_mandate_only', $package['operating_cell']['decision_rights']['external_action']);
                $this->assertNotEmpty($package['tool_and_data_cell']['required_connectors']);
                $this->assertSame(25, $package['quality_replay_cell']['minimum_cases_before_shadow']);
                $this->assertSame(1.0, $package['quality_replay_cell']['minimum_scores']['policy_compliance']);
                $this->assertContains('operator_checkpoint', $package['runtime_cell']['runtime_nodes']);
                $this->assertTrue($package['runtime_cell']['checkpoint_after_every_node']);
                $this->assertTrue($package['operations_cell']['runbook_drill_required_before_supervised_mode']);
                $this->assertContains('observed_runs_green', $package['promotion_gates']['autonomy_claim_ready']);
                $this->assertFalse($package['external_execution_allowed']);
                $this->assertSame(0, $package['buildout_wait_days_required']);
                $this->assertNotEmpty($package['package_hash']);
            }
            foreach ($company['enterprise_integration_activation_plan']['source_activation_tracks'] as $sourceTrack) {
                $this->assertNotEmpty($sourceTrack['source_id']);
                $this->assertStringStartsWith('https://', $sourceTrack['url']);
                $this->assertContains('run_sandbox_or_documentation_probe', $sourceTrack['activation_steps']);
                $this->assertContains('probe_receipt', $sourceTrack['required_evidence']);
                $this->assertFalse($sourceTrack['external_side_effects_enabled']);
                $this->assertNotEmpty($sourceTrack['track_hash']);
            }
            foreach ($company['enterprise_integration_activation_plan']['connector_activation_tracks'] as $connectorTrack) {
                $this->assertNotEmpty($connectorTrack['connector_id']);
                $this->assertContains('execute_read_only_health_check', $connectorTrack['activation_steps']);
                $this->assertTrue($connectorTrack['health_check_contract']['must_return_receipt']);
                $this->assertTrue($connectorTrack['health_check_contract']['must_not_mutate_external_state']);
                $this->assertContains('health_check_green', $connectorTrack['shadow_mode_ready_when']);
                $this->assertFalse($connectorTrack['external_side_effects_enabled']);
                $this->assertNotEmpty($connectorTrack['track_hash']);
            }
            foreach ($company['enterprise_integration_activation_plan']['flow_activation_matrix'] as $flowActivation) {
                $this->assertNotEmpty($flowActivation['flow_id']);
                $this->assertSame('sandbox_probe', $flowActivation['minimum_stage']);
                $this->assertContains('runtime_blueprint', $flowActivation['required_activation_evidence']);
                $this->assertContains('policy_findings_zero', $flowActivation['shadow_mode_entry_criteria']);
                $this->assertContains('operator_signed_mandate', $flowActivation['supervised_production_entry_criteria']);
                $this->assertNotEmpty($flowActivation['matrix_hash']);
            }
            $this->assertSame('atlas.ai.company.enterprise_operational_dress_rehearsal_stack.v1', $company['enterprise_operational_dress_rehearsal_stack']['schema'], $companyId);
            $this->assertFalse($company['enterprise_operational_dress_rehearsal_stack']['rehearsal_policy']['calendar_wait_blocker_enabled'], $companyId);
            $this->assertFalse($company['enterprise_operational_dress_rehearsal_stack']['rehearsal_policy']['external_mutation_allowed_during_rehearsal'], $companyId);
            $this->assertFalse($company['enterprise_operational_dress_rehearsal_stack']['rehearsal_policy']['production_cutover_allowed_without_signed_acceptance'], $companyId);
            $this->assertTrue($company['enterprise_operational_dress_rehearsal_stack']['rehearsal_policy']['operator_and_domain_owner_acceptance_required'], $companyId);
            $this->assertContains('operator_acceptance_coverage', $company['enterprise_operational_dress_rehearsal_stack']['dress_rehearsal_observability']['required_metrics'], $companyId);
            $this->assertNotEmpty($company['enterprise_operational_dress_rehearsal_stack']['dress_rehearsal_hash'], $companyId);
            foreach ($company['enterprise_operational_dress_rehearsal_stack']['flow_rehearsal_runbooks'] as $runbook) {
                $this->assertSame('atlas.ai.company.flow_operational_dress_rehearsal_runbook.v1', $runbook['schema']);
                $this->assertNotEmpty($runbook['flow_id']);
                $this->assertContains('execute_shadow_run_with_live_read_if_available', $runbook['staging_sequence']);
                $this->assertContains('operator_acceptance_recorded', $runbook['acceptance_criteria']);
                $this->assertContains('external_write', $runbook['blocked_during_rehearsal']);
                $this->assertContains('offensive_security', $runbook['blocked_during_rehearsal']);
                $this->assertNotEmpty($runbook['runbook_hash']);
            }
            foreach ($company['enterprise_operational_dress_rehearsal_stack']['live_read_probe_plan'] as $probe) {
                $this->assertNotEmpty($probe['connector_id']);
                $this->assertSame('live_read_only_or_sandbox_fixture', $probe['probe_mode']);
                $this->assertContains('vault_reference_attested', $probe['required_before_supervised_production']);
                $this->assertContains('no_external_mutation_attested', $probe['required_before_supervised_production']);
                $this->assertFalse($probe['mutation_allowed']);
                $this->assertNotEmpty($probe['probe_hash']);
            }
            foreach ($company['enterprise_operational_dress_rehearsal_stack']['operator_acceptance_packets'] as $acceptancePacket) {
                $this->assertNotEmpty($acceptancePacket['flow_id']);
                $this->assertContains('operator', $acceptancePacket['required_signatures']);
                $this->assertContains('domain_owner', $acceptancePacket['required_signatures']);
                $this->assertContains('rollback_drill_receipt', $acceptancePacket['required_artifacts']);
                $this->assertFalse($acceptancePacket['auto_accept_allowed']);
                $this->assertFalse($acceptancePacket['external_execution_enabled_by_packet']);
                $this->assertNotEmpty($acceptancePacket['acceptance_hash']);
            }
            foreach ($company['enterprise_operational_dress_rehearsal_stack']['rollback_drill_matrix'] as $rollbackDrill) {
                $this->assertNotEmpty($rollbackDrill['flow_id']);
                $this->assertContains('simulate_connector_failure', $rollbackDrill['drill_steps']);
                $this->assertContains('no_external_state_changed', $rollbackDrill['success_criteria']);
                $this->assertTrue($rollbackDrill['required_before_any_external_mutation']);
                $this->assertNotEmpty($rollbackDrill['rollback_hash']);
            }
            foreach ($company['enterprise_operational_dress_rehearsal_stack']['promotion_evidence_matrix'] as $promotionEvidence) {
                $this->assertNotEmpty($promotionEvidence['flow_id']);
                $this->assertSame('supervised_production_candidate_external_blocked_until_signed_scope', $promotionEvidence['promotion_stage']);
                $this->assertContains('live_read_probe', $promotionEvidence['must_have_green']);
                $this->assertContains('operator_acceptance', $promotionEvidence['must_have_green']);
                $this->assertSame(0, $promotionEvidence['calendar_wait_days_required']);
                $this->assertFalse($promotionEvidence['external_side_effects_enabled']);
                $this->assertNotEmpty($promotionEvidence['promotion_hash']);
            }
            foreach ($company['autonomy_promotion_ladder'] as $stage) {
                $this->assertNotEmpty($stage['stage']);
                $this->assertNotEmpty($stage['promotion_evidence']);
                $this->assertNotEmpty($stage['stage_hash']);
            }
            foreach ($company['flows'] as $flow) {
                $this->assertFalse($flow['external_side_effects'], $companyId.':'.$flow['id']);
                $this->assertContains('operator_review_for_external_action', $flow['required_gates']);
                $this->assertContains('durable_state_checkpoint', $flow['required_gates']);
                $this->assertSame('durable_graph_with_handoff_and_review_checkpoint', $flow['execution_model']);
                $this->assertGreaterThanOrEqual(7, $flow['playbook_step_count']);
                $this->assertNotEmpty($flow['flow_hash']);
            }
            foreach ($company['flow_execution_contracts'] as $contract) {
                $this->assertSame('atlas.ai.company.flow_execution_contract.v1', $contract['schema']);
                $this->assertContains('objective', $contract['input_contract']['required']);
                $this->assertContains('risks', $contract['output_contract']['required_sections']);
                $this->assertFalse($contract['budget_envelope']['external_spend_allowed']);
                $this->assertGreaterThanOrEqual(0.86, $contract['benchmark_hook']['minimum_score']);
                $this->assertTrue($contract['promotion_gate']['requires_green_evaluation']);
                $this->assertNotEmpty($contract['contract_hash']);
            }
            foreach ($company['flow_playbooks'] as $playbook) {
                $this->assertSame('checkpointed_graph_run', $playbook['state_model']);
                $this->assertTrue($playbook['human_review_checkpoint']['required_before_external_action']);
                $this->assertGreaterThanOrEqual(7, count($playbook['steps']));
                $this->assertNotEmpty($playbook['playbook_hash']);
            }
            foreach ($company['flow_runtime_blueprints'] as $runtimeBlueprint) {
                $this->assertSame('atlas.ai.company.flow_runtime_blueprint.v1', $runtimeBlueprint['schema']);
                $this->assertSame('durable_checkpointed_graph', $runtimeBlueprint['runtime_model']['engine']);
                $this->assertTrue($runtimeBlueprint['runtime_model']['idempotency_key_required']);
                $this->assertContains('operator_checkpoint', $runtimeBlueprint['state_machine']['states']);
                $this->assertSame('operator_checkpoint', $runtimeBlueprint['state_machine']['pause_state']);
                $this->assertContains('policy_violation', $runtimeBlueprint['retry_and_recovery']['non_retryable_failures']);
                $this->assertContains('tool_call_receipt', $runtimeBlueprint['runtime_observability']['required_events']);
                $this->assertTrue($runtimeBlueprint['runtime_observability']['trace_required']);
                $this->assertFalse($runtimeBlueprint['promotion_controls']['external_side_effects_default']);
                $this->assertNotEmpty($runtimeBlueprint['runtime_hash']);

                foreach ($runtimeBlueprint['tool_permission_matrix'] as $permission) {
                    $this->assertNotEmpty($permission['connector_id']);
                    $this->assertContains('read', $permission['allowed_modes']);
                    $this->assertContains('write', $permission['blocked_modes']);
                    $this->assertTrue($permission['requires_receipt']);
                    $this->assertTrue($permission['requires_operator_mandate_for_blocked_mode']);
                    $this->assertNotEmpty($permission['permission_hash']);
                }
            }
            foreach ($company['connectors'] as $connector) {
                $this->assertFalse($connector['external_side_effects'], $companyId.':'.$connector['id']);
                $this->assertTrue($connector['source_links_required']);
                $this->assertNotEmpty($connector['contract_hash']);
            }
            foreach ($company['toolchain'] as $tool) {
                $this->assertTrue($tool['mcp_or_adapter_ready']);
                $this->assertTrue($tool['requires_receipt']);
                $this->assertFalse($tool['external_side_effects']);
                $this->assertNotEmpty($tool['tool_contract_hash']);
            }
            foreach ($company['enterprise_operating_system']['sla_catalog'] as $sla) {
                $this->assertNotEmpty($sla['flow_id']);
                $this->assertSame('all_required_gates_green_or_blocked_with_reason', $sla['quality_sla']);
                $this->assertNotEmpty($sla['sla_hash']);
            }
            foreach ($company['enterprise_operating_system']['risk_register'] as $risk) {
                $this->assertSame('monitored', $risk['status']);
                $this->assertNotEmpty($risk['mitigation']);
                $this->assertNotEmpty($risk['risk_hash']);
            }
            foreach ($company['enterprise_operating_system']['runbooks'] as $runbook) {
                $this->assertNotEmpty($runbook['flow_id']);
                $this->assertContains('receipt_hash_attached', $runbook['exit_conditions']);
                $this->assertSame('manual_operator_review', $runbook['fallback']);
            }
        }
    }

    // GOD-DEBULK step 4c: the `atlas:ai:autonomous-holding` command surface was quarantined
    // (paper machinery — see archive/app/Console/Commands/AtlasAiAutonomousHoldingCommand.php).
    // This one method certified the retired command corpse; the buildout SERVICE it wrapped
    // stays fully covered by the direct-service tests above + the golden characterization suite.
}
