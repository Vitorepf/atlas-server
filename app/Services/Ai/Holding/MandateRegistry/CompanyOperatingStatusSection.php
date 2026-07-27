<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class CompanyOperatingStatusSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function domainAgentToolchainCertificationStatus(?string $companyId = null): array
    {
        $provider = $this->hub->companyCockpit->providerWorkbenchStatus($companyId);
        $repository = $this->hub->companyCockpit->agentRepositoryAdoptionStatus($companyId);
        $providerByCompany = $this->hub->companyRowsById($provider);
        $repositoryByCompany = $this->hub->companyRowsById($repository);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->domainAgentToolchainCertificationCompany(
                (array) $company,
                (array) ($providerByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
                (array) ($repositoryByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::DOMAIN_AGENT_TOOLCHAIN_CERTIFICATION_STATUS_SCHEMA,
            'domain_agent_toolchains_certified_external_execution_blocked',
            'domain_agent_toolchains_attention_required',
            [
                'agent_toolkit_profile_count' => array_sum(array_map(static fn (array $company): int => (int) $company['agent_toolkit_profile_count'], $companyRows)),
                'toolkit_certification_count' => array_sum(array_map(static fn (array $company): int => (int) $company['toolkit_certification_count'], $companyRows)),
                'flow_toolkit_assignment_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_toolkit_assignment_count'], $companyRows)),
                'certified_tool_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['certified_tool_contract_count'], $companyRows)),
                'repository_reference_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_reference_count'], $companyRows)),
                'domain_source_reference_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_source_reference_count'], $companyRows)),
                'repository_flow_adoption_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_flow_adoption_matrix_count'], $companyRows)),
                'repository_tool_permission_manifest_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_tool_permission_manifest_count'], $companyRows)),
                'repository_eval_replay_recipe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_eval_replay_recipe_count'], $companyRows)),
                'repository_license_security_review_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_license_security_review_count'], $companyRows)),
                'repository_runtime_boundary_review_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_runtime_boundary_review_count'], $companyRows)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_gate_count'], $companyRows)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) $company['required_gate_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'runtime_use_before_toolkit_certification_allowed' => false,
                'mcp_stdio_or_shell_execution_without_allowlist_allowed' => false,
                'repository_adoption_requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'source_basis' => [
                    'openai_agents_sdk_guardrails_handoffs_tracing',
                    'microsoft_agent_framework_and_autogen_lineage',
                    'langgraph_durable_execution_checkpoint_human_interrupt',
                    'model_context_protocol_servers_with_allowlisted_adapter_boundary',
                    'temporal_durable_workflow_replay',
                    'opentelemetry_agent_trace_metrics_logs',
                    'anthropic_financial_services_unified_data_source_link_audit_pattern',
                ],
                'blocked_operations' => ['runtime_use_without_certification', 'unreviewed_mcp_server_install', 'stdio_shell_without_allowlist', 'auto_upgrade', 'auto_procurement', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
            'domain_agent_toolchain_certification_status_hash',
            [
                'provider_workbench_status_hash' => $provider['provider_workbench_status_hash'] ?? null,
                'agent_repository_adoption_status_hash' => $repository['agent_repository_adoption_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function industrySolutionEcosystemStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->industrySolutionEcosystemCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::INDUSTRY_SOLUTION_ECOSYSTEM_STATUS_SCHEMA,
            'industry_solution_ecosystem_ready_external_execution_blocked',
            'industry_solution_ecosystem_attention_required',
            [
                'ecosystem_provider_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ecosystem_provider_count'], $companyRows)),
                'implementation_partner_track_count' => array_sum(array_map(static fn (array $company): int => (int) $company['implementation_partner_track_count'], $companyRows)),
                'flow_workload_pack_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_workload_pack_count'], $companyRows)),
                'source_verification_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_verification_matrix_count'], $companyRows)),
                'compliance_workload_control_count' => array_sum(array_map(static fn (array $company): int => (int) $company['compliance_workload_control_count'], $companyRows)),
                'partner_handoff_count' => array_sum(array_map(static fn (array $company): int => (int) $company['partner_handoff_count'], $companyRows)),
                'data_interface_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_interface_count'], $companyRows)),
                'audit_control_count' => array_sum(array_map(static fn (array $company): int => (int) $company['audit_control_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'reference_pattern' => 'claude_financial_services_style_industry_solution_adapted_per_company',
                'unified_data_interface_required' => true,
                'direct_source_hyperlinks_required' => true,
                'audit_trail_required_for_every_claim_and_artifact' => true,
                'operator_mandate_required_for_external_write_or_procurement' => true,
                'blocked_operations' => ['external_write', 'auto_procurement', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'secret_export'],
            ],
            'industry_solution_ecosystem_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function businessOperatingBackboneStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->businessOperatingBackboneCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::BUSINESS_OPERATING_BACKBONE_STATUS_SCHEMA,
            'business_operating_backbone_ready_external_execution_blocked',
            'business_operating_backbone_attention_required',
            [
                'ready_component_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_component_count'], $companyRows)),
                'required_component_count' => array_sum(array_map(static fn (array $company): int => (int) $company['required_component_count'], $companyRows)),
                'customer_offer_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_offer_count'], $companyRows)),
                'account_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_contract_count'], $companyRows)),
                'vendor_due_diligence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_due_diligence_count'], $companyRows)),
                'resilience_exercise_count' => array_sum(array_map(static fn (array $company): int => (int) $company['resilience_exercise_count'], $companyRows)),
                'analytics_dashboard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['analytics_dashboard_count'], $companyRows)),
                'semantic_graph_node_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_graph_node_count'], $companyRows)),
                'grc_audit_evidence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['grc_audit_evidence_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'required_backbone_components' => [
                    'customer_market_operations',
                    'account_contract_delivery',
                    'vendor_legal_procurement',
                    'resilience_continuity',
                    'analytics_decision_intelligence',
                    'knowledge_memory_learning',
                    'identity_access_data_sovereignty',
                    'control_tower_run_operations',
                    'semantic_operating_graph',
                    'delivery_assurance',
                    'unit_economics_capacity_simulation',
                    'grc',
                ],
                'operator_mandate_required_for_external_customer_vendor_billing_or_capital_action' => true,
                'blocked_operations' => ['external_publish', 'customer_commitment', 'billing', 'procurement', 'sign_contract', 'paid_spend', 'capital_commitment', 'write', 'deploy', 'delete', 'secret_export'],
            ],
            'business_operating_backbone_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function productionConnectorPreflightStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->productionConnectorPreflightCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::PRODUCTION_CONNECTOR_PREFLIGHT_STATUS_SCHEMA,
            'production_connector_preflight_ready_external_execution_blocked',
            'production_connector_preflight_attention_required',
            [
                'connector_preflight_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_preflight_contract_count'], $companyRows)),
                'flow_cutover_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_cutover_count'], $companyRows)),
                'production_evidence_register_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_evidence_register_count'], $companyRows)),
                'cutover_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['cutover_metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'real_credential_material_in_packet_allowed' => false,
                'manual_execution_handoff_only_after_signed_mandate' => true,
                'blocked_operations' => ['auto_cutover', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'production_connector_preflight_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function flowQualityResearchStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowQualityResearchCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::FLOW_QUALITY_RESEARCH_STATUS_SCHEMA,
            'flow_quality_research_ready_external_execution_blocked',
            'flow_quality_research_attention_required',
            [
                'offline_dataset_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offline_dataset_count'], $companyRows)),
                'trace_rubric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['trace_rubric_count'], $companyRows)),
                'adversarial_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['adversarial_case_count'], $companyRows)),
                'deterministic_assertion_count' => array_sum(array_map(static fn (array $company): int => (int) $company['deterministic_assertion_count'], $companyRows)),
                'replay_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_matrix_count'], $companyRows)),
                'tooling_benchmark_count' => array_sum(array_map(static fn (array $company): int => (int) $company['tooling_benchmark_count'], $companyRows)),
                'domain_solution_module_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_solution_module_count'], $companyRows)),
                'external_research_source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_research_source_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
                'blocked_operations' => ['synthetic_score_claim', 'promotion_without_replay', 'external_model_benchmark_without_approval', 'repository_adoption_without_review', 'paid_benchmark', 'write', 'publish', 'deploy', 'secret_export'],
            ],
            'flow_quality_research_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function companyCommandCenterStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->companyCommandCenterCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::COMPANY_COMMAND_CENTER_STATUS_SCHEMA,
            'company_command_center_ready_external_execution_blocked',
            'company_command_center_attention_required',
            [
                'operating_cell_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operating_cell_count'], $companyRows)),
                'flow_command_card_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_command_card_count'], $companyRows)),
                'connector_workbench_panel_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_panel_count'], $companyRows)),
                'operator_console_view_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_console_view_count'], $companyRows)),
                'work_product_factory_count' => array_sum(array_map(static fn (array $company): int => (int) $company['work_product_factory_count'], $companyRows)),
                'command_center_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_kpi_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'flow_card_required_before_shadow_or_supervised_runtime' => true,
                'operator_interrupt_required_for_external_side_effect' => true,
                'secret_material_in_packet_allowed' => false,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'company_command_center_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperatingPackageStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowOperatingPackageCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::FLOW_OPERATING_PACKAGE_STATUS_SCHEMA,
            'flow_operating_package_ready_external_execution_blocked',
            'flow_operating_package_attention_required',
            [
                'source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_count'], $companyRows)),
                'flow_package_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_package_count'], $companyRows)),
                'package_hash_count' => array_sum(array_map(static fn (array $company): int => (int) $company['package_hash_count'], $companyRows)),
                'replay_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_contract_count'], $companyRows)),
                'operations_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operations_contract_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'package_required_for_every_flow' => true,
                'minimum_replay_cases_before_shadow' => 25,
                'runbook_drill_required_before_supervised_mode' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'flow_operating_package_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalSolutionSuiteStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->verticalSolutionSuiteCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::VERTICAL_SOLUTION_SUITE_STATUS_SCHEMA,
            'vertical_solution_suite_ready_external_execution_blocked',
            'vertical_solution_suite_attention_required',
            [
                'source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_count'], $companyRows)),
                'solution_suite_count' => array_sum(array_map(static fn (array $company): int => (int) $company['solution_suite_count'], $companyRows)),
                'flow_solution_kit_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_solution_kit_count'], $companyRows)),
                'connector_workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_count'], $companyRows)),
                'artifact_factory_count' => array_sum(array_map(static fn (array $company): int => (int) $company['artifact_factory_count'], $companyRows)),
                'evaluation_recipe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evaluation_recipe_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'reference_pattern' => 'claude_financial_services_unified_domain_solution_generalized_to_every_company',
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'flow_kit_required_for_every_flow' => true,
                'connector_workbench_required_for_every_connector' => true,
                'artifact_factory_required_for_core_work_products' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export', 'auto_procurement'],
            ],
            'vertical_solution_suite_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionMeshStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->domainBusinessExecutionMeshCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::DOMAIN_BUSINESS_EXECUTION_MESH_STATUS_SCHEMA,
            'domain_business_execution_mesh_ready_external_execution_blocked',
            'domain_business_execution_mesh_attention_required',
            [
                'execution_mode_count' => array_sum(array_map(static fn (array $company): int => (int) $company['execution_mode_count'], $companyRows)),
                'execution_cell_count' => array_sum(array_map(static fn (array $company): int => (int) $company['execution_cell_count'], $companyRows)),
                'flow_kpi_binding_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_kpi_binding_count'], $companyRows)),
                'service_lane_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_lane_count'], $companyRows)),
                'artifact_delivery_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['artifact_delivery_contract_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'domain_specific_execution_required_for_every_flow' => true,
                'service_lane_required_for_every_flow' => true,
                'kpi_contract_required_for_every_flow' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export', 'auto_procurement'],
            ],
            'domain_business_execution_mesh_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->operationalDressRehearsalCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::OPERATIONAL_DRESS_REHEARSAL_STATUS_SCHEMA,
            'operational_dress_rehearsal_ready_external_execution_blocked',
            'operational_dress_rehearsal_attention_required',
            [
                'flow_rehearsal_runbook_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_rehearsal_runbook_count'], $companyRows)),
                'live_read_probe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['live_read_probe_count'], $companyRows)),
                'operator_acceptance_packet_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_acceptance_packet_count'], $companyRows)),
                'rollback_drill_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rollback_drill_count'], $companyRows)),
                'promotion_evidence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['promotion_evidence_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'operator_and_domain_owner_acceptance_required' => true,
                'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security'],
            ],
            'operational_dress_rehearsal_status_hash',
        );
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $providerRow
     * @param array<string,mixed> $repositoryRow
     * @return array<string,mixed>
     */
    public function domainAgentToolchainCertificationCompany(array $company, array $providerRow, array $repositoryRow): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $toolkit = (array) data_get($company, 'enterprise_domain_agent_toolkit_stack', []);
        $repositoryPipeline = (array) data_get($company, 'enterprise_agent_repository_adoption_pipeline', []);
        $toolchain = array_values((array) data_get($company, 'toolchain', []));
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $agentRoleCount = count((array) ($company['agent_roles'] ?? []));
        $frameworkCatalog = (array) data_get($toolkit, 'framework_source_catalog', []);
        $toolkitProfiles = (array) data_get($toolkit, 'agent_toolkit_profiles', []);
        $flowAssignments = (array) data_get($toolkit, 'flow_toolkit_assignments', []);
        $certificationMatrix = (array) data_get($toolkit, 'toolkit_certification_matrix', []);
        $watchlist = (array) data_get($toolkit, 'repository_and_agent_watchlist.global_agent_frameworks', []);
        $domainSources = (array) data_get($toolkit, 'repository_and_agent_watchlist.domain_specific_sources', []);
        $toolkitMetrics = (array) data_get($toolkit, 'toolkit_observability.required_metrics', []);
        $repositoryIntake = (array) data_get($repositoryPipeline, 'repository_intake_queue', []);
        $repositoryAdoptionMatrix = (array) data_get($repositoryPipeline, 'flow_repository_adoption_matrix', []);
        $permissionManifests = (array) data_get($repositoryPipeline, 'flow_tool_permission_manifests', []);
        $evalReplayRecipes = (array) data_get($repositoryPipeline, 'flow_eval_replay_recipes', []);
        $versionPins = (array) data_get($repositoryPipeline, 'version_pin_and_supply_chain_plan', []);

        $certifiedToolContracts = array_values(array_filter(
            $toolchain,
            static fn (array $tool): bool => (bool) ($tool['mcp_or_adapter_ready'] ?? false)
                && (bool) ($tool['requires_source_links'] ?? false)
                && (bool) ($tool['requires_receipt'] ?? false)
                && (bool) ($tool['external_side_effects'] ?? true) === false
                && strlen((string) ($tool['tool_contract_hash'] ?? '')) === 64,
        ));

        $checks = [
            'toolkit_schema_green' => ($toolkit['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_agent_toolkit_stack.v1',
            'framework_source_catalog_green' => count($frameworkCatalog) >= 8,
            'agent_toolkit_profiles_cover_roles' => $agentRoleCount > 0 && count($toolkitProfiles) >= $agentRoleCount,
            'flow_toolkit_assignments_cover_flows' => $flowCount > 0 && count($flowAssignments) >= $flowCount,
            'toolkit_certification_matrix_covers_roles' => $agentRoleCount > 0 && count($certificationMatrix) >= $agentRoleCount,
            'tool_contracts_cover_connectors' => $connectorCount > 0 && count($certifiedToolContracts) >= $connectorCount,
            'repository_watchlist_green' => count($watchlist) >= 8,
            'domain_source_watchlist_green' => count($domainSources) >= 3,
            'repository_intake_and_version_pins_green' => count($repositoryIntake) >= 11 && count($versionPins) >= count($repositoryIntake),
            'repository_flow_adoption_matrix_covers_flows' => $flowCount > 0 && count($repositoryAdoptionMatrix) >= $flowCount,
            'repository_tool_permission_manifests_cover_flows' => $flowCount > 0 && count($permissionManifests) >= $flowCount,
            'repository_eval_replay_recipes_cover_flows' => $flowCount > 0 && count($evalReplayRecipes) >= $flowCount,
            'repository_license_security_reviews_cover_intake' => (int) ($repositoryRow['license_security_review_count'] ?? 0) >= count($repositoryIntake),
            'repository_runtime_boundary_reviews_cover_intake' => (int) ($repositoryRow['runtime_boundary_review_count'] ?? 0) >= count($repositoryIntake),
            'provider_workbench_ready' => (bool) ($providerRow['ready'] ?? false),
            'agent_repository_adoption_ready' => (bool) ($repositoryRow['ready'] ?? false),
            'toolkit_observability_green' => count($toolkitMetrics) >= 7,
            'runtime_before_toolkit_certification_blocked' => (bool) data_get($toolkit, 'toolkit_policy.runtime_use_before_toolkit_certification_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($toolkit, 'toolkit_policy.external_side_effects_default', true) === false
                && count(array_filter($toolchain, static fn (array $tool): bool => (bool) ($tool['external_side_effects'] ?? true))) === 0,
            'mcp_or_shell_unreviewed_execution_blocked' => true,
        ];

        $readyGateCount = count(array_filter($checks));
        $row = [
            'schema' => 'atlas.ai.company.domain_agent_toolchain_certification_record.v1',
            'company_id' => $companyId,
            'ready' => $readyGateCount === count($checks),
            'ready_gate_count' => $readyGateCount,
            'required_gate_count' => count($checks),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'agent_role_count' => $agentRoleCount,
            'framework_source_count' => count($frameworkCatalog),
            'agent_toolkit_profile_count' => count($toolkitProfiles),
            'toolkit_certification_count' => count($certificationMatrix),
            'flow_toolkit_assignment_count' => count($flowAssignments),
            'certified_tool_contract_count' => count($certifiedToolContracts),
            'repository_reference_count' => count($watchlist),
            'domain_source_reference_count' => count($domainSources),
            'repository_intake_count' => count($repositoryIntake),
            'repository_flow_adoption_matrix_count' => count($repositoryAdoptionMatrix),
            'repository_tool_permission_manifest_count' => count($permissionManifests),
            'repository_eval_replay_recipe_count' => count($evalReplayRecipes),
            'repository_license_security_review_count' => (int) ($repositoryRow['license_security_review_count'] ?? 0),
            'repository_runtime_boundary_review_count' => (int) ($repositoryRow['runtime_boundary_review_count'] ?? 0),
            'version_pin_count' => count($versionPins),
            'toolkit_metric_count' => count($toolkitMetrics),
            'framework_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_framework'),
                $frameworkCatalog,
            )),
            'certified_tool_contracts' => array_values(array_map(
                static fn (array $tool): array => [
                    'connector_id' => (string) ($tool['connector_id'] ?? 'unknown_connector'),
                    'adapter_kind' => (string) ($tool['adapter_kind'] ?? 'unknown_adapter'),
                    'permission_model' => (string) ($tool['permission_model'] ?? 'unknown_permission_model'),
                    'tool_contract_hash' => (string) ($tool['tool_contract_hash'] ?? ''),
                ],
                $certifiedToolContracts,
            )),
            'supply_chain_controls' => [
                'license_review_required' => true,
                'security_review_required' => true,
                'sbom_required' => true,
                'version_pin_required' => true,
                'fixture_eval_required' => true,
                'operator_acceptance_required_before_runtime_use' => true,
                'mcp_stdio_command_allowlist_required' => true,
                'unreviewed_shell_or_stdio_execution_allowed' => false,
            ],
            'source_hashes' => [
                'toolkit_stack_hash' => (string) data_get($toolkit, 'toolkit_stack_hash', ''),
                'agent_repository_adoption_company_hash' => (string) ($repositoryRow['agent_repository_adoption_company_hash'] ?? ''),
                'provider_workbench_company_hash' => (string) ($providerRow['provider_workbench_company_hash'] ?? ''),
                'repository_pipeline_hash' => (string) data_get($repositoryPipeline, 'pipeline_hash', ''),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['domain_agent_toolchain_certification_record_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function industrySolutionEcosystemCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_industry_solution_ecosystem_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $dataInterface = (array) data_get($stack, 'industry_data_interface', []);
        $providers = (array) data_get($stack, 'ecosystem_provider_catalog', []);
        $partnerTracks = (array) data_get($stack, 'implementation_partner_tracks', []);
        $workloadPacks = (array) data_get($stack, 'flow_solution_workload_packs', []);
        $sourceVerificationMatrix = (array) data_get($stack, 'flow_source_verification_matrix', []);
        $complianceWorkloadControls = (array) data_get($stack, 'flow_compliance_workload_controls', []);
        $partnerHandoffs = (array) data_get($stack, 'implementation_partner_handoff_matrix', []);
        $metrics = (array) data_get($stack, 'ecosystem_observability.required_metrics', []);
        $auditControls = (array) data_get($stack, 'audit_and_confidentiality_controls', []);

        $checks = [
            'data_interface_green' => ($dataInterface['schema'] ?? null) === 'atlas.ai.company.industry_data_interface.v1'
                && count((array) ($dataInterface['connector_ids'] ?? [])) >= $connectorCount
                && $connectorCount > 0,
            'ecosystem_providers_green' => count($providers) >= 5,
            'implementation_partner_tracks_green' => count($partnerTracks) >= 7,
            'flow_workload_packs_green' => count($workloadPacks) >= $flowCount && $flowCount > 0,
            'flow_source_verification_matrix_green' => count($sourceVerificationMatrix) >= $flowCount && $flowCount > 0
                && count(array_filter($sourceVerificationMatrix, static fn (array $row): bool => (bool) ($row['cross_source_check_required'] ?? false)
                    && (bool) ($row['claim_to_source_map_required'] ?? false)
                    && (bool) ($row['missing_source_blocks_external_delivery'] ?? false)
                    && count((array) ($row['verification_steps'] ?? [])) >= 5
                    && count((array) ($row['primary_source_ids'] ?? [])) >= (int) ($row['minimum_source_count'] ?? 1)
                    && (bool) ($row['external_execution_allowed'] ?? true) === false
                )) >= $flowCount,
            'flow_compliance_workload_controls_green' => count($complianceWorkloadControls) >= $flowCount && $flowCount > 0
                && count(array_filter($complianceWorkloadControls, static fn (array $row): bool => count((array) ($row['required_controls'] ?? [])) >= 6
                    && (bool) data_get($row, 'capacity_model.expanded_workload_capacity_required', false)
                    && (bool) data_get($row, 'capacity_model.queue_and_dlq_required', false)
                    && (bool) data_get($row, 'capacity_model.cost_latency_metering_required', false)
                    && (bool) data_get($row, 'capacity_model.event_spike_replay_required', false)
                    && (bool) ($row['operator_acceptance_required'] ?? false)
                    && (bool) ($row['external_claim_or_delivery_allowed'] ?? true) === false
                )) >= $flowCount,
            'implementation_partner_handoff_green' => count($partnerHandoffs) >= $flowCount && $flowCount > 0
                && count(array_filter($partnerHandoffs, static fn (array $row): bool => count((array) ($row['handoff_artifacts'] ?? [])) >= 6
                    && (bool) ($row['expert_implementation_support_required'] ?? false)
                    && (bool) ($row['procurement_or_external_contracting_allowed'] ?? true) === false
                )) >= $flowCount,
            'observability_green' => count($metrics) >= 8,
            'source_links_required' => (bool) data_get($stack, 'ecosystem_policy.direct_source_hyperlinks_required', false),
            'mcp_or_api_workbench_required' => (bool) data_get($stack, 'ecosystem_policy.mcp_or_api_connector_workbench_required', false),
            'audit_trail_required' => (bool) data_get($stack, 'ecosystem_policy.audit_trail_required_for_every_claim_and_artifact', false),
            'confidentiality_green' => (bool) data_get($stack, 'audit_and_confidentiality_controls.client_or_private_data_training_exclusion_attestation_required', false)
                && (bool) data_get($stack, 'audit_and_confidentiality_controls.secret_material_in_packet_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'ecosystem_policy.external_side_effects_enabled', true) === false
                && (bool) data_get($stack, 'enterprise_adoption_program.external_contracting_allowed_by_stack', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.industry_solution_ecosystem_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'data_interface_count' => $dataInterface === [] ? 0 : 1,
            'ecosystem_provider_count' => count($providers),
            'implementation_partner_track_count' => count($partnerTracks),
            'flow_workload_pack_count' => count($workloadPacks),
            'source_verification_matrix_count' => count($sourceVerificationMatrix),
            'compliance_workload_control_count' => count($complianceWorkloadControls),
            'partner_handoff_count' => count($partnerHandoffs),
            'metric_count' => count($metrics),
            'audit_control_count' => count($auditControls),
            'provider_ids' => array_values(array_map(
                static fn (array $provider): string => (string) ($provider['provider_id'] ?? 'unknown_provider'),
                $providers,
            )),
            'partner_track_ids' => array_values(array_map(
                static fn (array $track): string => (string) ($track['track_id'] ?? 'unknown_track'),
                $partnerTracks,
            )),
            'workload_flow_ids' => array_values(array_map(
                static fn (array $pack): string => (string) ($pack['flow_id'] ?? 'unknown_flow'),
                $workloadPacks,
            )),
            'source_verification_flow_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['flow_id'] ?? 'unknown_flow'),
                $sourceVerificationMatrix,
            )),
            'compliance_control_flow_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['flow_id'] ?? 'unknown_flow'),
                $complianceWorkloadControls,
            )),
            'partner_handoff_flow_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['flow_id'] ?? 'unknown_flow'),
                $partnerHandoffs,
            )),
            'next_actions' => ['run_provider_terms_reviews', 'bind_read_only_data_interface', 'execute_workload_fixture_eval', 'attach_audit_and_confidentiality_attestations', 'request_operator_acceptance_before_external_contracting'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['industry_solution_ecosystem_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function businessOperatingBackboneCompany(array $company): array
    {
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $cadenceCount = count((array) ($company['cadences'] ?? []));
        $nodeFloor = count((array) ($company['functions'] ?? []))
            + count((array) ($company['agent_roles'] ?? []))
            + $flowCount
            + $connectorCount
            + $metricCount;

        $checks = [
            'customer_market_operations_green' => data_get($company, 'enterprise_customer_market_operations_stack.schema') === 'atlas.ai.company.enterprise_customer_market_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                && (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_operations_guardrails.external_side_effects_default', true) === false,
            'account_contract_delivery_green' => data_get($company, 'enterprise_account_contract_delivery_stack.schema') === 'atlas.ai.company.enterprise_account_contract_delivery_stack.v1'
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.account_operations_policy.external_billing_allowed', true) === false,
            'vendor_legal_procurement_green' => data_get($company, 'enterprise_vendor_legal_procurement_stack.schema') === 'atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1'
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', [])) >= $flowCount
                && data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_policy.purchase_authority') === 'operator_only_for_real_spend',
            'resilience_continuity_green' => data_get($company, 'enterprise_resilience_continuity_stack.schema') === 'atlas.ai.company.enterprise_resilience_continuity_stack.v1'
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false,
            'analytics_decision_intelligence_green' => data_get($company, 'enterprise_analytics_decision_intelligence_stack.schema') === 'atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1'
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= $metricCount
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.synthetic_scores_allowed', true) === false,
            'knowledge_memory_learning_green' => data_get($company, 'enterprise_knowledge_memory_learning_stack.schema') === 'atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1'
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false,
            'identity_access_data_sovereignty_green' => data_get($company, 'enterprise_identity_access_data_sovereignty_stack.schema') === 'atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1'
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= $connectorCount
                && data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.default_access') === 'deny',
            'control_tower_run_operations_green' => data_get($company, 'enterprise_control_tower_run_operations_stack.schema') === 'atlas.ai.company.enterprise_control_tower_run_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= $cadenceCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
            'semantic_operating_graph_green' => data_get($company, 'enterprise_semantic_operating_graph_stack.schema') === 'atlas.ai.company.enterprise_semantic_operating_graph_stack.v1'
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= $nodeFloor
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4
                && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.external_side_effects_from_graph_allowed', true) === false,
            'delivery_assurance_green' => data_get($company, 'enterprise_delivery_assurance_stack.schema') === 'atlas.ai.company.enterprise_delivery_assurance_stack.v1'
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_intake_contract.reject_when_missing_acceptance_criteria', false)
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
            'unit_economics_capacity_simulation_green' => data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.schema') === 'atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1'
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_policy.synthetic_financial_claims_allowed', true) === false,
            'grc_green' => data_get($company, 'enterprise_grc_stack.schema') === 'atlas.ai.company.enterprise_grc_stack.v1'
                && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 4
                && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
        ];
        $readyComponentCount = count(array_filter($checks));

        $row = [
            'schema' => 'atlas.ai.company.business_operating_backbone_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => $readyComponentCount === count($checks),
            'checks' => $checks,
            'ready_component_count' => $readyComponentCount,
            'required_component_count' => count($checks),
            'customer_offer_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
            'account_contract_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
            'vendor_due_diligence_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
            'resilience_exercise_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])),
            'analytics_dashboard_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])),
            'semantic_graph_node_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])),
            'grc_audit_evidence_count' => count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])),
            'next_actions' => ['review_customer_account_vendor_backbone', 'run_resilience_and_control_tower_drills', 'export_semantic_operating_graph_snapshot', 'attach_grc_audit_receipts', 'request_operator_mandate_before_external_business_action'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['business_operating_backbone_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function productionConnectorPreflightCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_production_connector_preflight_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $contracts = (array) data_get($stack, 'connector_preflight_contracts', []);
        $cutovers = (array) data_get($stack, 'flow_connector_cutover_matrix', []);
        $evidence = (array) data_get($stack, 'production_readiness_evidence_register', []);
        $metrics = (array) data_get($stack, 'cutover_observability.required_metrics', []);

        $checks = [
            'schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_production_connector_preflight_stack.v1',
            'connector_preflight_contracts_green' => count($contracts) >= $connectorCount && $connectorCount > 0,
            'flow_cutover_matrix_green' => count($cutovers) >= $flowCount && $flowCount > 0,
            'production_evidence_register_green' => count($evidence) >= $connectorCount && $connectorCount > 0,
            'observability_green' => count($metrics) >= 6,
            'calendar_wait_removed' => (bool) data_get($stack, 'preflight_policy.calendar_wait_blocker_enabled', true) === false,
            'operator_signed_scope_required' => (bool) data_get($stack, 'preflight_policy.production_cutover_without_operator_signed_scope_allowed', true) === false,
            'no_real_credentials_in_packet' => (bool) data_get($stack, 'preflight_policy.real_credential_material_in_packet_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'preflight_policy.external_side_effects_default', true) === false,
            'manual_handoff_only' => (bool) data_get($stack, 'preflight_policy.manual_execution_handoff_only_after_signed_mandate', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.production_connector_preflight_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'connector_preflight_contract_count' => count($contracts),
            'flow_cutover_count' => count($cutovers),
            'production_evidence_register_count' => count($evidence),
            'cutover_metric_count' => count($metrics),
            'connector_ids' => array_values(array_map(
                static fn (array $contract): string => (string) ($contract['connector_id'] ?? 'unknown_connector'),
                $contracts,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $cutover): string => (string) ($cutover['flow_id'] ?? 'unknown_flow'),
                $cutovers,
            )),
            'next_actions' => ['bind_real_vault_references', 'collect_signed_production_scope', 'assign_manual_execution_owner', 'verify_rollback_drill_green', 'request_operator_mandate_before_cutover'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['production_connector_preflight_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function flowQualityResearchCompany(array $company): array
    {
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $benchmark = (array) data_get($company, 'enterprise_flow_benchmark_replay_stack', []);
        $tooling = (array) data_get($company, 'enterprise_tooling_research_stack', []);
        $solution = (array) data_get($company, 'enterprise_domain_solution_stack', []);
        $research = (array) data_get($company, 'enterprise_external_research_adoption_stack', []);
        $datasets = (array) data_get($benchmark, 'offline_dataset_contracts', []);
        $rubrics = (array) data_get($benchmark, 'trace_grading_rubrics', []);
        $adversarial = (array) data_get($benchmark, 'adversarial_regression_cases', []);
        $assertions = (array) data_get($benchmark, 'deterministic_state_assertions', []);
        $replayMatrix = (array) data_get($benchmark, 'replay_and_comparison_matrix', []);
        $metrics = (array) data_get($benchmark, 'benchmark_observability.required_metrics', []);
        $toolingBenchmarks = (array) data_get($tooling, 'per_flow_tooling_benchmark', []);
        $toolingBacklog = (array) data_get($tooling, 'connector_integration_backlog', []);
        $solutionModules = (array) data_get($solution, 'solution_modules', []);
        $solutionTemplates = (array) data_get($solution, 'managed_agent_templates', []);
        $dataProducts = (array) data_get($solution, 'data_product_catalog', []);
        $researchSources = (array) data_get($research, 'source_basis', []);
        $frameworkRepos = (array) data_get($research, 'repository_and_framework_catalog.official_framework_repositories', []);
        $domainRepos = (array) data_get($research, 'repository_and_framework_catalog.domain_repository_candidates', []);
        $flowAdoption = (array) data_get($research, 'per_flow_adoption_matrix', []);
        $connectorBacklog = (array) data_get($research, 'connector_and_data_provider_backlog', []);

        $checks = [
            'benchmark_schema_green' => ($benchmark['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1',
            'offline_datasets_green' => count($datasets) >= $flowCount && $flowCount > 0,
            'trace_rubrics_green' => count($rubrics) >= $flowCount && $flowCount > 0,
            'adversarial_cases_green' => count($adversarial) >= $flowCount && $flowCount > 0,
            'deterministic_assertions_green' => count($assertions) >= $flowCount && $flowCount > 0,
            'replay_matrix_green' => count($replayMatrix) >= $flowCount && $flowCount > 0,
            'benchmark_observability_green' => count($metrics) >= 5,
            'synthetic_scores_blocked' => (bool) data_get($benchmark, 'benchmark_policy.synthetic_score_claims_allowed', true) === false,
            'promotion_without_replay_blocked' => (bool) data_get($benchmark, 'benchmark_policy.promotion_without_replay_green_allowed', true) === false,
            'tooling_research_green' => ($tooling['schema'] ?? null) === 'atlas.ai.company.enterprise_tooling_research_stack.v1'
                && count((array) data_get($tooling, 'source_catalog', [])) >= 9
                && count($toolingBenchmarks) >= $flowCount
                && count($toolingBacklog) >= $connectorCount,
            'domain_solution_green' => ($solution['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_solution_stack.v1'
                && count((array) data_get($solution, 'domain_source_catalog', [])) >= 5
                && count($solutionModules) >= $flowCount
                && count($solutionTemplates) >= 4
                && count($dataProducts) >= 5
                && (bool) data_get($solution, 'solution_operating_model.external_side_effects_default', true) === false,
            'external_research_green' => ($research['schema'] ?? null) === 'atlas.ai.company.enterprise_external_research_adoption_stack.v1'
                && count($researchSources) >= 12
                && count($frameworkRepos) >= 8
                && count($domainRepos) >= 3
                && count($flowAdoption) >= $flowCount
                && count($connectorBacklog) >= $connectorCount
                && (bool) data_get($research, 'research_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($research, 'research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_quality_research_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'offline_dataset_count' => count($datasets),
            'trace_rubric_count' => count($rubrics),
            'adversarial_case_count' => count($adversarial),
            'deterministic_assertion_count' => count($assertions),
            'replay_matrix_count' => count($replayMatrix),
            'benchmark_metric_count' => count($metrics),
            'tooling_benchmark_count' => count($toolingBenchmarks),
            'tooling_integration_backlog_count' => count($toolingBacklog),
            'domain_solution_module_count' => count($solutionModules),
            'domain_solution_template_count' => count($solutionTemplates),
            'domain_solution_data_product_count' => count($dataProducts),
            'external_research_source_count' => count($researchSources),
            'external_research_framework_repo_count' => count($frameworkRepos),
            'external_research_domain_repo_count' => count($domainRepos),
            'flow_ids' => array_values(array_map(
                static fn (array $dataset): string => (string) ($dataset['flow_id'] ?? 'unknown_flow'),
                $datasets,
            )),
            'next_actions' => ['run_offline_replay_suite_per_flow', 'capture_trace_grades_and_adversarial_regressions', 'verify_deterministic_state_assertions', 'review_tooling_benchmarks', 'bind_domain_solution_modules_before_promotion'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_quality_research_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function companyCommandCenterCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_company_command_center_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $operatingCells = (array) data_get($stack, 'operating_cells', []);
        $flowCards = (array) data_get($stack, 'flow_command_cards', []);
        $connectorPanels = (array) data_get($stack, 'connector_workbench_panels', []);
        $consoleViews = (array) data_get($stack, 'operator_console_views', []);
        $workProductFactory = (array) data_get($stack, 'work_product_factory_map', []);
        $kpis = (array) data_get($stack, 'command_center_kpis', []);

        $checks = [
            'command_center_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_company_command_center_stack.v1',
            'operating_cells_green' => count($operatingCells) >= 6,
            'flow_command_cards_green' => count($flowCards) >= $flowCount && $flowCount > 0,
            'connector_workbench_panels_green' => count($connectorPanels) >= $connectorCount && $connectorCount > 0,
            'operator_console_views_green' => count($consoleViews) >= 4,
            'work_product_factory_green' => count($workProductFactory) >= 5,
            'command_center_kpis_green' => count($kpis) >= $metricCount && $metricCount > 0,
            'calendar_wait_removed' => (bool) data_get($stack, 'command_center_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
            'secret_material_blocked' => (bool) data_get($stack, 'command_center_policy.secret_material_in_packet_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.command_center_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'metric_count' => $metricCount,
            'operating_cell_count' => count($operatingCells),
            'flow_command_card_count' => count($flowCards),
            'connector_workbench_panel_count' => count($connectorPanels),
            'operator_console_view_count' => count($consoleViews),
            'work_product_factory_count' => count($workProductFactory),
            'command_center_kpi_count' => count($kpis),
            'operating_cell_ids' => array_values(array_map(
                static fn (array $cell): string => (string) ($cell['cell_id'] ?? 'unknown_cell'),
                $operatingCells,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $card): string => (string) ($card['flow_id'] ?? 'unknown_flow'),
                $flowCards,
            )),
            'connector_panel_ids' => array_values(array_map(
                static fn (array $panel): string => (string) ($panel['panel_id'] ?? 'unknown_panel'),
                $connectorPanels,
            )),
            'console_view_ids' => array_values(array_map(
                static fn (array $view): string => (string) ($view['view_id'] ?? 'unknown_view'),
                $consoleViews,
            )),
            'next_actions' => ['operate_flow_cards_from_command_center', 'run_read_only_connector_panels', 'review_command_center_kpis', 'resolve_pause_triggers_before_handoff', 'collect_operator_interrupt_receipt_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['company_command_center_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function flowOperatingPackageCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_flow_operating_packages', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $sources = (array) data_get($stack, 'source_basis', []);
        $packages = (array) data_get($stack, 'flow_packages', []);
        $metrics = (array) data_get($stack, 'package_observability.required_metrics', []);
        $packageHashes = array_values(array_filter(
            array_map(static fn (array $package): string => (string) ($package['package_hash'] ?? ''), $packages),
            static fn (string $hash): bool => strlen($hash) === 64,
        ));
        $replayContracts = array_values(array_filter($packages, static fn (array $package): bool => (int) data_get($package, 'quality_replay_cell.minimum_cases_before_shadow', 0) >= 25
            && (bool) data_get($package, 'quality_replay_cell.promotion_without_green_replay_allowed', true) === false));
        $operationsContracts = array_values(array_filter($packages, static fn (array $package): bool => (bool) data_get($package, 'operations_cell.runbook_drill_required_before_supervised_mode', false)
            && (bool) data_get($package, 'operations_cell.post_run_reconciliation_required', false)));
        $packageSchemas = array_values(array_filter($packages, static fn (array $package): bool => ($package['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_operating_package.v1'));

        $checks = [
            'flow_operating_package_stack_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_operating_package_stack.v1',
            'source_basis_green' => count($sources) >= 10,
            'package_for_every_flow_green' => count($packages) >= $flowCount && $flowCount > 0,
            'package_schema_green' => count($packageSchemas) === count($packages) && $packages !== [],
            'package_hashes_green' => count($packageHashes) === count($packages) && $packages !== [],
            'replay_contracts_green' => count($replayContracts) === count($packages) && $packages !== [],
            'operations_contracts_green' => count($operationsContracts) === count($packages) && $packages !== [],
            'observability_metrics_green' => count($metrics) >= 6,
            'calendar_wait_removed' => (bool) data_get($stack, 'operating_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'operating_policy.external_execution_allowed_by_package', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_operating_package_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'source_count' => count($sources),
            'flow_package_count' => count($packages),
            'package_hash_count' => count($packageHashes),
            'replay_contract_count' => count($replayContracts),
            'operations_contract_count' => count($operationsContracts),
            'metric_count' => count($metrics),
            'minimum_replay_cases_before_shadow' => 25,
            'flow_ids' => array_values(array_map(
                static fn (array $package): string => (string) ($package['flow_id'] ?? 'unknown_flow'),
                $packages,
            )),
            'package_ids' => array_values(array_map(
                static fn (array $package): string => (string) ($package['package_id'] ?? 'unknown_package'),
                $packages,
            )),
            'package_hashes' => $packageHashes,
            'next_actions' => ['run_package_fixture_replays', 'verify_package_workbench_scopes', 'drill_package_runbooks', 'refresh_operating_packet_from_green_packages', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_operating_package_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function verticalSolutionSuiteCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_vertical_solution_suite_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $sources = (array) data_get($stack, 'source_basis', []);
        $suites = (array) data_get($stack, 'solution_suites', []);
        $flowKits = (array) data_get($stack, 'flow_solution_kits', []);
        $connectorWorkbenches = (array) data_get($stack, 'connector_solution_workbenches', []);
        $artifactFactories = (array) data_get($stack, 'artifact_factory_catalog', []);
        $evaluationRecipes = (array) data_get($stack, 'suite_evaluation_recipes', []);
        $metrics = (array) data_get($stack, 'suite_observability.required_metrics', []);

        $checks = [
            'vertical_solution_suite_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_vertical_solution_suite_stack.v1',
            'source_basis_green' => count($sources) >= 5,
            'solution_suites_green' => count($suites) >= 6,
            'flow_solution_kits_green' => count($flowKits) >= $flowCount && $flowCount > 0,
            'connector_workbenches_green' => count($connectorWorkbenches) >= $connectorCount && $connectorCount > 0,
            'artifact_factories_green' => count($artifactFactories) >= 5,
            'evaluation_recipes_green' => count($evaluationRecipes) >= $flowCount && $flowCount > 0,
            'observability_metrics_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'suite_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'suite_policy.external_execution_allowed_by_suite', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.vertical_solution_suite_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'source_count' => count($sources),
            'solution_suite_count' => count($suites),
            'flow_solution_kit_count' => count($flowKits),
            'connector_workbench_count' => count($connectorWorkbenches),
            'artifact_factory_count' => count($artifactFactories),
            'evaluation_recipe_count' => count($evaluationRecipes),
            'metric_count' => count($metrics),
            'suite_ids' => array_values(array_map(
                static fn (array $suite): string => (string) ($suite['suite_id'] ?? 'unknown_suite'),
                $suites,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $kit): string => (string) ($kit['flow_id'] ?? 'unknown_flow'),
                $flowKits,
            )),
            'source_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_source'),
                $sources,
            )),
            'next_actions' => ['operate_vertical_solution_suites', 'run_flow_solution_kit_replays', 'verify_connector_solution_workbenches', 'publish_internal_artifact_factory_receipts', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['vertical_solution_suite_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionMeshCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $executionModes = (array) data_get($stack, 'execution_mode_catalog', []);
        $executionCells = (array) data_get($stack, 'flow_execution_cells', []);
        $kpiBindings = (array) data_get($stack, 'flow_tool_kpi_matrix', []);
        $serviceLanes = (array) data_get($stack, 'domain_service_lanes', []);
        $artifactContracts = (array) data_get($stack, 'business_artifact_delivery_contracts', []);
        $metrics = (array) data_get($stack, 'execution_observability.required_metrics', []);

        $checks = [
            'business_execution_mesh_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_business_execution_mesh_stack.v1',
            'execution_modes_green' => count($executionModes) >= 4,
            'execution_cells_green' => count($executionCells) >= $flowCount && $flowCount > 0,
            'flow_kpi_bindings_green' => count($kpiBindings) >= $flowCount && $flowCount > 0,
            'service_lanes_green' => count($serviceLanes) >= $flowCount && $flowCount > 0,
            'artifact_delivery_contracts_green' => count($artifactContracts) >= 5,
            'observability_metrics_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'execution_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'execution_policy.autonomous_external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
            'operator_signed_scope_required' => (bool) data_get($stack, 'execution_policy.operator_signed_scope_required_for_external_effect', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.domain_business_execution_mesh_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'execution_mode_count' => count($executionModes),
            'execution_cell_count' => count($executionCells),
            'flow_kpi_binding_count' => count($kpiBindings),
            'service_lane_count' => count($serviceLanes),
            'artifact_delivery_contract_count' => count($artifactContracts),
            'metric_count' => count($metrics),
            'execution_mode_ids' => array_values(array_map(
                static fn (array $mode): string => (string) ($mode['mode_id'] ?? 'unknown_mode'),
                $executionModes,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $cell): string => (string) ($cell['flow_id'] ?? 'unknown_flow'),
                $executionCells,
            )),
            'next_actions' => ['operate_domain_business_execution_cells', 'track_flow_tool_kpi_bindings', 'review_service_lane_slas', 'ship_internal_business_artifacts', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['domain_business_execution_mesh_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_operational_dress_rehearsal_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $runbooks = (array) data_get($stack, 'flow_rehearsal_runbooks', []);
        $probes = (array) data_get($stack, 'live_read_probe_plan', []);
        $acceptance = (array) data_get($stack, 'operator_acceptance_packets', []);
        $rollback = (array) data_get($stack, 'rollback_drill_matrix', []);
        $promotion = (array) data_get($stack, 'promotion_evidence_matrix', []);
        $metrics = (array) data_get($stack, 'dress_rehearsal_observability.required_metrics', []);

        $checks = [
            'flow_rehearsal_runbooks_green' => count($runbooks) >= $flowCount && $flowCount > 0,
            'live_read_probe_plan_green' => count($probes) >= $connectorCount && $connectorCount > 0,
            'operator_acceptance_packets_green' => count($acceptance) >= $flowCount && $flowCount > 0,
            'rollback_drill_matrix_green' => count($rollback) >= $flowCount && $flowCount > 0,
            'promotion_evidence_matrix_green' => count($promotion) >= $flowCount && $flowCount > 0,
            'observability_green' => count($metrics) >= 7,
            'calendar_wait_removed' => (bool) data_get($stack, 'rehearsal_policy.calendar_wait_blocker_enabled', true) === false,
            'external_mutation_blocked' => (bool) data_get($stack, 'rehearsal_policy.external_mutation_allowed_during_rehearsal', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.operational_dress_rehearsal_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'flow_rehearsal_runbook_count' => count($runbooks),
            'live_read_probe_count' => count($probes),
            'operator_acceptance_packet_count' => count($acceptance),
            'rollback_drill_count' => count($rollback),
            'promotion_evidence_count' => count($promotion),
            'dress_rehearsal_metric_count' => count($metrics),
            'next_actions' => ['execute_non_production_rehearsal', 'capture_live_read_probe_receipts', 'record_operator_acceptance', 'run_rollback_drill'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['operational_dress_rehearsal_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }
}
