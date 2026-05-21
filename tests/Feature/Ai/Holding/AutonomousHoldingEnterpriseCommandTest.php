<?php

namespace Tests\Feature\Ai\Holding;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiOperatorApproval;
use App\Models\AiDomainRuntimeRecord;
use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class AutonomousHoldingEnterpriseCommandTest extends TestCase
{
    use CreatesAtlasToolRuntimeTables;
    use CreatesDomainRuntimeTables;

    /**
     * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
     */
    public static function companyCommands(): array
    {
        return [
            'software' => ['atlas:ai:engineering-company', ['action' => 'enterprise-analysis', '--json' => true], 'software'],
            'research' => ['atlas:ai:research-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'research'],
            'strategy' => ['atlas:ai:strategy-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'strategy'],
            'marketing' => ['atlas:ai:marketing-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'marketing'],
            'cyber' => ['atlas:ai:cyber-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'cyber'],
            'automation' => ['atlas:ai:automation-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'automation'],
            'personal_development' => ['atlas:ai:personal-development-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'personal_development'],
            'operations' => ['atlas:ai:operations-domain', ['--action' => 'enterprise-analysis', '--json' => true], 'operations'],
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     */
    #[DataProvider('companyCommands')]
    public function test_company_enterprise_analysis_commands_return_company_buildout_packet(
        string $command,
        array $arguments,
        string $companyId,
    ): void {
        $exit = Artisan::call($command, $arguments);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, $command);
        $this->assertIsArray($payload, $command);
        $this->assertTrue((bool) $payload['ok'], $command);
        $this->assertSame(AutonomousHoldingEnterpriseBuildoutService::COMPANY_SCHEMA, $payload['schema']);
        $this->assertSame($companyId, $payload['company_id']);
        $this->assertTrue((bool) $payload['readiness']['ok']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['flow_count']);
        $this->assertGreaterThanOrEqual(3, $payload['readiness']['connector_count']);
        $this->assertSame('atlas.ai.company.enterprise_domain_operating_depth_stack.v1', $payload['enterprise_domain_operating_depth_stack']['schema']);
        $this->assertGreaterThanOrEqual(6, $payload['readiness']['domain_operating_value_chain_count']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['domain_operating_data_product_count']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['domain_operating_system_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_operating_flow_depth_packet_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['domain_operating_connector_backlog_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['domain_operating_depth_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['domain_operating_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_operating_external_execution_enabled']);
        $this->assertFalse((bool) $payload['enterprise_domain_operating_depth_stack']['depth_policy']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertSame(64, strlen((string) $payload['enterprise_domain_operating_depth_stack']['domain_operating_depth_hash']));
        $this->assertSame('atlas.ai.company.enterprise_domain_agent_workforce_stack.v1', $payload['enterprise_domain_agent_workforce_stack']['schema']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['domain_agent_workforce_managed_agent_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_agent_workforce_crew_count']);
        $this->assertGreaterThanOrEqual(10, $payload['readiness']['domain_agent_workforce_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['domain_agent_workforce_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_agent_workforce_external_execution_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_agent_workforce_external_worker_enabled']);
        $this->assertFalse((bool) $payload['enterprise_domain_agent_workforce_stack']['workforce_policy']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertSame(64, strlen((string) $payload['enterprise_domain_agent_workforce_stack']['workforce_hash']));
        $this->assertSame('atlas.ai.company.enterprise_productized_service_stack.v1', $payload['enterprise_productized_service_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['productized_service_product_line_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['productized_service_offer_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['productized_service_delivery_blueprint_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['productized_service_intake_contract_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['productized_service_sla_contract_count']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['productized_service_pricing_package_count']);
        $this->assertGreaterThanOrEqual(10, $payload['readiness']['productized_service_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['productized_service_external_commitment_enabled']);
        $this->assertFalse((bool) $payload['readiness']['productized_service_external_billing_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_productized_service_stack']['productized_service_hash']));
        $this->assertSame('atlas.ai.company.enterprise_sales_crm_pipeline_stack.v1', $payload['enterprise_sales_crm_pipeline_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['sales_crm_source_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['sales_crm_object_count']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['sales_crm_segment_play_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_opportunity_route_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_proposal_packet_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_mutual_action_plan_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_account_research_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_deal_room_packet_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_pipeline_forecast_review_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_map_risk_review_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['sales_crm_handoff_contract_count']);
        $this->assertGreaterThanOrEqual(13, $payload['readiness']['sales_crm_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['sales_crm_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['sales_crm_external_commitment_enabled']);
        $this->assertFalse((bool) $payload['readiness']['sales_crm_public_claim_paid_campaign_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_sales_crm_pipeline_stack']['sales_crm_pipeline_hash']));
        $this->assertSame('atlas.ai.company.enterprise_customer_support_service_desk_stack.v1', $payload['enterprise_customer_support_service_desk_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['support_service_desk_source_count']);
        $this->assertGreaterThanOrEqual(9, $payload['readiness']['support_service_desk_object_count']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['support_segment_playbook_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_flow_lane_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_ticket_sla_contract_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_kb_template_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_escalation_runbook_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_resolution_rca_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_case_resolution_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_customer_health_escalation_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_knowledge_quality_review_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['support_automation_deflection_test_count']);
        $this->assertGreaterThanOrEqual(13, $payload['readiness']['support_service_desk_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['support_service_desk_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['support_service_desk_external_message_enabled']);
        $this->assertFalse((bool) $payload['readiness']['support_service_desk_unreviewed_regulated_advice_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_customer_support_service_desk_stack']['support_service_desk_hash']));
        $this->assertSame('atlas.ai.company.enterprise_marketing_growth_engine_stack.v1', $payload['enterprise_marketing_growth_engine_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['marketing_growth_source_count']);
        $this->assertGreaterThanOrEqual(6, $payload['readiness']['marketing_growth_role_count']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['marketing_audience_segment_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_campaign_blueprint_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_content_factory_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_experiment_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_growth_intelligence_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_attribution_experiment_model_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_channel_budget_guardrail_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_public_claim_evidence_packet_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_channel_distribution_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_brand_review_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['marketing_crm_handoff_count']);
        $this->assertGreaterThanOrEqual(14, $payload['readiness']['marketing_growth_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['marketing_growth_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['marketing_growth_external_publish_enabled']);
        $this->assertFalse((bool) $payload['readiness']['marketing_growth_unreviewed_public_claim_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_marketing_growth_engine_stack']['marketing_growth_engine_hash']));
        $this->assertSame('atlas.ai.company.enterprise_finance_treasury_billing_stack.v1', $payload['enterprise_finance_treasury_billing_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['finance_treasury_source_count']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['finance_treasury_data_interface_connector_class_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['finance_treasury_source_count'], $payload['readiness']['finance_treasury_provider_connector_count']);
        $this->assertGreaterThanOrEqual(9, $payload['readiness']['finance_treasury_cfo_role_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_research_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_budget_envelope_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_forecast_model_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_model_risk_control_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_investment_committee_packet_count']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['finance_treasury_pnl_line_item_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['finance_treasury_billing_ledger_count']);
        $this->assertGreaterThanOrEqual(14, $payload['readiness']['finance_treasury_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['finance_treasury_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['finance_treasury_external_financial_action_enabled']);
        $this->assertFalse((bool) $payload['readiness']['finance_treasury_real_money_movement_enabled']);
        $this->assertTrue((bool) $payload['enterprise_finance_treasury_billing_stack']['financial_data_interface']['source_verification_contract']['direct_source_link_required']);
        $this->assertFalse((bool) $payload['enterprise_finance_treasury_billing_stack']['financial_data_interface']['source_verification_contract']['claim_without_source_link_allowed']);
        $this->assertSame(64, strlen((string) $payload['enterprise_finance_treasury_billing_stack']['finance_treasury_billing_hash']));
        $this->assertSame('atlas.ai.company.enterprise_domain_company_execution_suite_stack.v1', $payload['enterprise_domain_company_execution_suite_stack']['schema']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['domain_execution_suite_source_count']);
        $this->assertGreaterThanOrEqual(6, $payload['readiness']['domain_execution_suite_role_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['domain_execution_suite_connector_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_execution_suite_flow_packet_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_execution_suite_risk_control_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_execution_suite_decision_room_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_execution_suite_replay_eval_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['metric_count'] + 6, $payload['readiness']['domain_execution_suite_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['domain_execution_suite_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_execution_suite_external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_domain_company_execution_suite_stack']['domain_execution_suite_hash']));
        $this->assertSame('atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1', $payload['enterprise_flow_work_product_delivery_stack']['schema']);
        $this->assertGreaterThanOrEqual($payload['readiness']['work_product_count'], $payload['readiness']['flow_work_product_catalog_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_work_product_delivery_blueprint_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_work_product_acceptance_contract_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_work_product_handoff_packet_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_work_product_replay_check_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['metric_count'] + 6, $payload['readiness']['flow_work_product_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['flow_work_product_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['flow_work_product_external_delivery_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_flow_work_product_delivery_stack']['delivery_stack_hash']));
        $this->assertSame('atlas.ai.company.enterprise_domain_data_connector_operating_stack.v1', $payload['enterprise_domain_data_connector_operating_stack']['schema']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['domain_data_room_source_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['work_product_count'], $payload['readiness']['domain_data_product_contract_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['domain_connector_permission_profile_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_flow_data_connector_contract_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_connector_fixture_eval_suite_count']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['domain_data_room_required_control_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['metric_count'] + 7, $payload['readiness']['domain_data_connector_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['domain_data_connector_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_data_connector_write_tools_enabled']);
        $this->assertFalse((bool) $payload['readiness']['domain_data_connector_external_mutation_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_domain_data_connector_operating_stack']['data_connector_stack_hash']));
        $this->assertSame('atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1', $payload['enterprise_flow_live_read_connector_probe_stack']['schema']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['flow_live_read_connector_profile_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_live_read_probe_contract_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['flow_live_read_probe_evidence_matrix_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['metric_count'] + 7, $payload['readiness']['flow_live_read_probe_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['flow_live_read_probe_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['flow_live_read_probe_write_tools_enabled']);
        $this->assertFalse((bool) $payload['readiness']['flow_live_read_probe_external_mutation_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_flow_live_read_connector_probe_stack']['probe_stack_hash']));
        $this->assertSame('atlas.ai.company.enterprise_agent_repository_operating_catalog.v1', $payload['enterprise_agent_repository_operating_catalog']['schema']);
        $this->assertGreaterThanOrEqual(4, $payload['readiness']['agent_repository_operating_source_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['agent_repository_operating_framework_profile_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['agent_repository_operating_mcp_security_profile_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['agent_repository_operating_flow_map_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['agent_repository_operating_supply_chain_artifact_count']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['agent_repository_operating_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['agent_repository_operating_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['readiness']['agent_repository_operating_mcp_hardening_required']);
        $this->assertFalse((bool) $payload['readiness']['agent_repository_operating_external_write_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_agent_repository_operating_catalog']['operating_catalog_hash']));
        $this->assertSame('atlas.ai.company.enterprise_domain_data_fabric_stack.v1', $payload['enterprise_domain_data_fabric_stack']['schema']);
        $this->assertGreaterThanOrEqual(5, $payload['readiness']['domain_data_fabric_source_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['domain_data_fabric_provider_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['work_product_count'], $payload['readiness']['domain_data_fabric_product_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_data_fabric_workbench_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['domain_data_fabric_decision_packet_factory_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['domain_data_fabric_enablement_track_count']);
        $this->assertGreaterThanOrEqual(7, $payload['readiness']['domain_data_fabric_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['domain_data_fabric_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['readiness']['domain_data_fabric_direct_source_link_required']);
        $this->assertTrue((bool) $payload['readiness']['domain_data_fabric_cross_source_verification_required']);
        $this->assertFalse((bool) $payload['readiness']['domain_data_fabric_external_write_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_domain_data_fabric_stack']['domain_data_fabric_hash']));
        $this->assertSame('atlas.ai.company.enterprise_revenue_delivery_operating_mesh.v1', $payload['enterprise_company_revenue_delivery_operating_mesh']['schema']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['revenue_delivery_operating_system_count']);
        $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['revenue_delivery_flow_thread_count']);
        $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['revenue_delivery_connector_map_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['revenue_delivery_scorecard_metric_count']);
        $this->assertGreaterThanOrEqual(8, $payload['readiness']['revenue_delivery_observability_metric_count']);
        $this->assertFalse((bool) $payload['readiness']['revenue_delivery_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['readiness']['revenue_delivery_customer_commitment_without_operator_enabled']);
        $this->assertFalse((bool) $payload['readiness']['revenue_delivery_billing_without_operator_enabled']);
        $this->assertFalse((bool) $payload['readiness']['revenue_delivery_external_write_enabled']);
        $this->assertSame(64, strlen((string) $payload['enterprise_company_revenue_delivery_operating_mesh']['revenue_delivery_mesh_hash']));
        $this->assertFalse((bool) $payload['policy']['external_side_effects_allowed']);
        $this->assertTrue((bool) $payload['policy']['operator_approval_required']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));
    }

    public function test_enterprise_company_operating_blueprints_cover_every_company_flow(): void
    {
        $service = app(AutonomousHoldingEnterpriseBuildoutService::class);

        foreach (['software', 'research', 'strategy', 'finance', 'marketing', 'cyber', 'automation', 'personal_development', 'operations'] as $companyId) {
            $payload = $service->companyPacket($companyId);

            $this->assertTrue((bool) $payload['readiness']['ok'], $companyId);
            $this->assertSame('atlas.ai.company.enterprise_company_operating_blueprint_stack.v1', $payload['enterprise_company_operating_blueprint_stack']['schema'], $companyId);
            $this->assertGreaterThanOrEqual(5, $payload['readiness']['company_operating_blueprint_archetype_count'], $companyId);
            $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['company_operating_blueprint_data_provider_contract_count'], $companyId);
            $this->assertGreaterThanOrEqual($payload['readiness']['connector_count'], $payload['readiness']['company_operating_blueprint_connector_permission_count'], $companyId);
            $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['company_operating_blueprint_flow_count'], $companyId);
            $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['company_operating_blueprint_artifact_assembly_count'], $companyId);
            $this->assertSame($payload['readiness']['flow_count'], $payload['readiness']['company_operating_blueprint_handoff_count'], $companyId);
            $this->assertGreaterThanOrEqual(10, $payload['readiness']['company_operating_blueprint_metric_count'], $companyId);
            $this->assertFalse((bool) $payload['readiness']['company_operating_blueprint_wait_blocker_enabled'], $companyId);
            $this->assertFalse((bool) $payload['readiness']['company_operating_blueprint_external_execution_enabled'], $companyId);
            $this->assertFalse((bool) $payload['readiness']['company_operating_blueprint_external_side_effects_enabled'], $companyId);
            $this->assertSame(64, strlen((string) $payload['enterprise_company_operating_blueprint_stack']['operating_blueprint_hash']), $companyId);

            foreach ($payload['enterprise_company_operating_blueprint_stack']['flow_operating_blueprints'] as $flowBlueprint) {
                $this->assertNotEmpty($flowBlueprint['primary_archetype'], $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertGreaterThanOrEqual(8, count($flowBlueprint['required_skills']), $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertTrue((bool) $flowBlueprint['artifact_assembly_contract']['source_link_required_per_claim'], $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertTrue((bool) $flowBlueprint['runtime_handoff_contract']['eval_replay_required'], $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertFalse((bool) $flowBlueprint['external_execution_allowed'], $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertFalse((bool) $flowBlueprint['external_side_effects_enabled'], $companyId.'.'.$flowBlueprint['flow_id']);
                $this->assertSame(64, strlen((string) $flowBlueprint['flow_operating_blueprint_hash']), $companyId.'.'.$flowBlueprint['flow_id']);
            }
        }

        $finance = $service->companyPacket('finance');
        $financeArchetypes = array_column($finance['enterprise_company_operating_blueprint_stack']['workload_archetype_catalog'], 'archetype_id');

        $this->assertContains('pitch_builder', $financeArchetypes);
        $this->assertContains('model_builder', $financeArchetypes);
        $this->assertContains('valuation_reviewer', $financeArchetypes);
        $this->assertContains('month_end_closer', $financeArchetypes);
        $this->assertContains('kyc_screener', $financeArchetypes);
    }

    public function test_finance_enterprise_analysis_command_keeps_finance_specific_contract(): void
    {
        $exit = Artisan::call('atlas:ai:finance-domain', [
            '--action' => 'enterprise-analysis',
            '--asset' => 'MSFT',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.finance.enterprise_analysis_solution.v1', $payload['schema']);
        $this->assertSame('MSFT', $payload['asset']);
        $this->assertGreaterThanOrEqual(6, $payload['readiness']['flow_count']);
        $this->assertGreaterThanOrEqual(6, $payload['readiness']['connector_count']);
        $this->assertTrue((bool) $payload['invariants']['live_trading_blocked_default']);
        $this->assertFalse((bool) $payload['invariants']['broker_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));
    }

    public function test_enterprise_flow_fixture_actions_are_executable_for_every_company_flow(): void
    {
        $service = app(AutonomousHoldingEnterpriseBuildoutService::class);
        $commandByCompany = [
            'software' => 'atlas:ai:engineering-company',
            'research' => 'atlas:ai:research-domain',
            'strategy' => 'atlas:ai:strategy-domain',
            'finance' => 'atlas:ai:finance-domain',
            'marketing' => 'atlas:ai:marketing-domain',
            'cyber' => 'atlas:ai:cyber-domain',
            'automation' => 'atlas:ai:automation-domain',
            'personal_development' => 'atlas:ai:personal-development-domain',
            'operations' => 'atlas:ai:operations-domain',
        ];
        $expectedRuntimeRecords = 0;

        foreach ($commandByCompany as $companyId => $command) {
            $company = $service->companyPacket($companyId);
            $expectedRuntimeRecords += count($company['enterprise_flow_action_runtime_stack']['runtime_action_catalog']);
            foreach ($company['enterprise_flow_action_runtime_stack']['runtime_action_catalog'] as $actionContract) {
                $flowAction = (string) $actionContract['action'];
                $arguments = ['--action' => $flowAction, '--runtime-mode' => 'fixture', '--fixture' => true, '--json' => true];

                $exit = Artisan::call($command, $arguments);
                $payload = json_decode(Artisan::output(), true);

                $this->assertSame(0, $exit, $companyId.'.'.$flowAction);
                $this->assertIsArray($payload, $companyId.'.'.$flowAction);
                $this->assertTrue((bool) $payload['ok'], $companyId.'.'.$flowAction);
                $this->assertSame('atlas.ai.company.enterprise_flow_fixture_action_run.v1', $payload['schema']);
                $this->assertSame('fixture_completed', $payload['status']);
                $this->assertSame($companyId, $payload['company_id']);
                $this->assertSame($flowAction, $payload['flow_id']);
                $this->assertSame($flowAction, $payload['action']);
                $this->assertFalse((bool) $payload['external_side_effects']);
                $this->assertTrue((bool) $payload['operator_checkpoint_required_for_external_action']);
                $this->assertContains('policy_gate', $payload['state_schema']['checkpoint_after']);
                $this->assertContains('operator_checkpointed', $payload['event_emission_plan']['required_events']);
                $this->assertContains('rollback_plan', $payload['operator_checkpoint']['operator_packet_fields']);
                $this->assertSame('atlas.ai.company.enterprise_flow_operating_package.v1', $payload['enterprise_flow_operating_package']['schema']);
                $this->assertSame($flowAction, $payload['enterprise_flow_operating_package']['flow_id']);
                $this->assertSame('atlas.ai.company.enterprise_domain_flow_depth_packet.v1', $payload['enterprise_domain_operating_depth_packet']['schema']);
                $this->assertSame($flowAction, $payload['enterprise_domain_operating_depth_packet']['flow_id']);
                $this->assertGreaterThanOrEqual(6, count($payload['enterprise_domain_operating_depth_packet']['skills']));
                $this->assertGreaterThanOrEqual(4, count($payload['enterprise_domain_operating_depth_packet']['subagents']));
                $this->assertGreaterThanOrEqual(1, count($payload['enterprise_domain_operating_depth_packet']['source_refs']));
                $this->assertGreaterThanOrEqual(1, count($payload['enterprise_domain_operating_depth_packet']['enterprise_system_refs']));
                $this->assertGreaterThanOrEqual(1, count($payload['enterprise_domain_operating_depth_packet']['data_product_refs']));
                $this->assertFalse((bool) $payload['enterprise_domain_operating_depth_packet']['operating_controls']['external_write_spend_trade_publish_deploy_delete_allowed']);
                $this->assertSame(64, strlen((string) $payload['enterprise_domain_operating_depth_packet']['packet_hash']));
                $this->assertSame('atlas.ai.company.vertical_flow_solution_kit.v1', $payload['enterprise_vertical_solution_kit']['schema']);
                $this->assertSame($flowAction, $payload['enterprise_vertical_solution_kit']['flow_id']);
                $this->assertSame('atlas.ai.company.domain_business_execution_cell.v1', $payload['enterprise_domain_business_execution_cell']['schema']);
                $this->assertSame($flowAction, $payload['enterprise_domain_business_execution_cell']['flow_id']);
                $this->assertSame('atlas.ai.company.enterprise_domain_execution_brief.v1', $payload['enterprise_artifact']['domain_execution_brief']['schema']);
                $this->assertSame($companyId, $payload['enterprise_artifact']['domain_execution_brief']['company_id']);
                $this->assertSame($flowAction, $payload['enterprise_artifact']['domain_execution_brief']['flow_id']);
                $this->assertNotEmpty($payload['enterprise_artifact']['domain_execution_brief']['enterprise_play']);
                $this->assertGreaterThanOrEqual(5, count($payload['enterprise_artifact']['domain_execution_brief']['domain_decision_lenses']));
                $this->assertGreaterThanOrEqual(5, count($payload['enterprise_artifact']['domain_execution_brief']['required_domain_checks']));
                $this->assertTrue((bool) $payload['enterprise_artifact']['domain_execution_brief']['acceptance_model']['must_pass_domain_specific_checks']);
                $this->assertFalse((bool) $payload['enterprise_artifact']['domain_execution_brief']['acceptance_model']['external_side_effects_allowed']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['domain_execution_brief_present']);
                $this->assertSame(64, strlen((string) $payload['enterprise_artifact']['domain_execution_brief']['brief_hash']));
                $this->assertTrue((bool) $payload['vertical_solution_runtime_attestation']['kit_present']);
                $this->assertGreaterThanOrEqual(1, $payload['vertical_solution_runtime_attestation']['suite_ref_count']);
                $this->assertTrue((bool) $payload['vertical_solution_runtime_attestation']['artifact_factory_present']);
                $this->assertFalse((bool) $payload['vertical_solution_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['vertical_solution_runtime_attestation']['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $payload['vertical_solution_runtime_attestation']['attestation_hash']));
                $this->assertTrue((bool) $payload['domain_business_execution_runtime_attestation']['execution_cell_bound']);
                $this->assertTrue((bool) $payload['domain_business_execution_runtime_attestation']['kpi_binding_bound']);
                $this->assertTrue((bool) $payload['domain_business_execution_runtime_attestation']['service_lane_bound']);
                $this->assertTrue((bool) $payload['domain_business_execution_runtime_attestation']['artifact_delivery_contract_bound']);
                $this->assertGreaterThanOrEqual(1, $payload['domain_business_execution_runtime_attestation']['kpi_ref_count']);
                $this->assertFalse((bool) $payload['domain_business_execution_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['domain_business_execution_runtime_attestation']['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $payload['domain_business_execution_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.enterprise_operational_outcome_ledger.v1', $payload['enterprise_artifact']['operational_outcome_ledger']['schema']);
                $this->assertSame($companyId, $payload['enterprise_artifact']['operational_outcome_ledger']['company_id']);
                $this->assertSame($flowAction, $payload['enterprise_artifact']['operational_outcome_ledger']['flow_id']);
                $this->assertFalse((bool) $payload['enterprise_artifact']['operational_outcome_ledger']['external_side_effects']);
                $this->assertFalse((bool) $payload['enterprise_artifact']['operational_outcome_ledger']['value_proxy']['external_value_claim_allowed']);
                $this->assertSame(64, strlen((string) $payload['enterprise_artifact']['operational_outcome_ledger']['outcome_ledger_hash']));
                $this->assertTrue((bool) $payload['operating_package_attestation']['package_present']);
                $this->assertGreaterThanOrEqual(25, $payload['operating_package_attestation']['minimum_replay_cases_before_shadow']);
                $this->assertFalse((bool) $payload['operating_package_attestation']['calendar_wait_blocker_enabled']);
                $this->assertSame(64, strlen((string) $payload['operating_package_attestation']['attestation_hash']));
                $this->assertContains('trade', $payload['blocked_operations']);
                $this->assertSame(64, strlen((string) $payload['receipt_hash']));
            }
        }
    }

    public function test_enterprise_flow_actions_run_as_internal_runtime_without_fixture_flag(): void
    {
        $this->createDomainRuntimeTables();
        AiDomainRuntimeRecord::query()->delete();

        $service = app(AutonomousHoldingEnterpriseBuildoutService::class);
        $commandByCompany = [
            'software' => 'atlas:ai:engineering-company',
            'research' => 'atlas:ai:research-domain',
            'strategy' => 'atlas:ai:strategy-domain',
            'finance' => 'atlas:ai:finance-domain',
            'marketing' => 'atlas:ai:marketing-domain',
            'cyber' => 'atlas:ai:cyber-domain',
            'automation' => 'atlas:ai:automation-domain',
            'personal_development' => 'atlas:ai:personal-development-domain',
            'operations' => 'atlas:ai:operations-domain',
        ];
        $expectedRuntimeRecords = 0;

        foreach ($commandByCompany as $companyId => $command) {
            $company = $service->companyPacket($companyId);
            $expectedRuntimeRecords += count($company['enterprise_flow_action_runtime_stack']['runtime_action_catalog']);
            foreach ($company['enterprise_flow_action_runtime_stack']['runtime_action_catalog'] as $actionContract) {
                $flowAction = (string) $actionContract['action'];

                $exit = Artisan::call($command, ['--action' => $flowAction, '--json' => true]);
                $payload = json_decode(Artisan::output(), true);

                $this->assertSame(0, $exit, $companyId.'.'.$flowAction);
                $this->assertIsArray($payload, $companyId.'.'.$flowAction);
                $this->assertTrue((bool) $payload['ok'], $companyId.'.'.$flowAction);
                $this->assertSame('atlas.ai.company.enterprise_flow_action_run.v1', $payload['schema']);
                $this->assertSame('internal_flow_completed_external_blocked', $payload['status']);
                $this->assertSame('internal_enterprise_runtime', $payload['mode']);
                $this->assertFalse((bool) $payload['fixture_mode_requested']);
                $this->assertSame($companyId, $payload['company_id']);
                $this->assertSame($flowAction, $payload['flow_id']);
                $this->assertSame('atlas.ai.company.enterprise_managed_agent_execution.v1', $payload['managed_agent_execution']['schema']);
                $this->assertNotEmpty($payload['managed_agent_execution']['runtime_nodes_completed']);
                $this->assertNotEmpty($payload['managed_agent_execution']['state_hash']);
                $this->assertSame('atlas.ai.company.enterprise_agent_operating_system_packet.v1', $payload['managed_agent_execution']['agent_operating_system']['schema']);
                $this->assertContains('skills_connectors_subagents', $payload['managed_agent_execution']['agent_operating_system']['pattern_refs']);
                $this->assertContains('tools_handoffs_guardrails_tracing_sessions', $payload['managed_agent_execution']['agent_operating_system']['pattern_refs']);
                $this->assertGreaterThanOrEqual(5, count($payload['managed_agent_execution']['agent_operating_system']['skill_pack']['required_skills']));
                $this->assertNotEmpty($payload['managed_agent_execution']['agent_operating_system']['subagent_roster']);
                $this->assertNotEmpty($payload['managed_agent_execution']['agent_operating_system']['connector_execution_plane']);
                $this->assertTrue((bool) $payload['managed_agent_execution']['agent_operating_system']['session_memory']['durable_state_required']);
                $this->assertTrue((bool) $payload['managed_agent_execution']['agent_operating_system']['guardrail_stack']['external_side_effect_guardrail']);
                $this->assertTrue((bool) $payload['managed_agent_execution']['agent_operating_system']['handoff_graph']['handoff_packet_requires_tool_receipts']);
                $this->assertTrue((bool) $payload['managed_agent_execution']['agent_operating_system']['evaluation_harness']['fixture_replay_required']);
                $this->assertFalse((bool) $payload['managed_agent_execution']['agent_operating_system']['external_execution_allowed']);
                $this->assertFalse((bool) $payload['managed_agent_execution']['agent_operating_system']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['managed_agent_execution']['agent_operating_system']['agent_os_hash']));
                $this->assertSame('atlas.ai.company.enterprise_business_operating_packet.v1', $payload['enterprise_business_operating_packet']['schema']);
                $this->assertSame('business_operating_packet_ready_external_commitments_blocked', $payload['enterprise_business_operating_packet']['status']);
                $this->assertNotEmpty($payload['enterprise_business_operating_packet']['business_model']['delivery_contract_hash']);
                $this->assertGreaterThanOrEqual(3, count($payload['enterprise_business_operating_packet']['kpi_contract']['kpi_refs']));
                $this->assertNotEmpty($payload['enterprise_business_operating_packet']['delivery_lane']['service_lane_id']);
                $this->assertNotEmpty($payload['enterprise_business_operating_packet']['economics']['cost_center_id']);
                $this->assertTrue((bool) $payload['enterprise_business_operating_packet']['operating_controls']['operator_acceptance_required']);
                $this->assertContains('customer_commitment', $payload['enterprise_business_operating_packet']['operating_controls']['blocked_operations']);
                $this->assertTrue((bool) $payload['enterprise_business_operating_packet']['readiness']['business_execution_cell_bound']);
                $this->assertTrue((bool) $payload['enterprise_business_operating_packet']['readiness']['kpi_contract_bound']);
                $this->assertTrue((bool) $payload['enterprise_business_operating_packet']['readiness']['delivery_lane_bound']);
                $this->assertTrue((bool) $payload['enterprise_business_operating_packet']['readiness']['economics_bound']);
                $this->assertFalse((bool) $payload['enterprise_business_operating_packet']['external_side_effects']);
                $this->assertSame(64, strlen((string) $payload['enterprise_business_operating_packet']['business_operating_packet_hash']));
                $this->assertSame('atlas.ai.company.enterprise_flow_artifact.v1', $payload['enterprise_artifact']['schema']);
                $this->assertSame('draft_ready_for_operator_review', $payload['enterprise_artifact']['status']);
                $this->assertSame($payload['enterprise_artifact']['required_section_count'], count($payload['enterprise_artifact']['sections']));
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['operating_package_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['vertical_solution_kit_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['vertical_artifact_factory_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['business_execution_cell_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['business_kpi_binding_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['business_service_lane_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['business_artifact_delivery_contract_hash']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['dataset_id']);
                $this->assertNotEmpty($payload['enterprise_artifact']['source_lineage']['connector_ids']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['source_lineage_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['vertical_solution_kit_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['artifact_factory_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['business_execution_cell_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['business_kpi_binding_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['business_service_lane_present']);
                $this->assertTrue((bool) $payload['enterprise_artifact']['quality_signals']['business_artifact_contract_present']);
                $this->assertGreaterThanOrEqual(1.0, (float) $payload['enterprise_artifact']['quality_signals']['vertical_connector_workbench_coverage']);
                $this->assertSame(1.0, (float) $payload['enterprise_artifact']['quality_signals']['policy_compliance_floor']);
                $this->assertCount(3, $payload['enterprise_artifact']['risk_register']);
                $this->assertSame('operator_review_shadow_candidate_or_internal_supervised_run', $payload['enterprise_artifact']['decision_packet']['decision_use']);
                $this->assertFalse((bool) $payload['enterprise_artifact']['decision_packet']['external_delivery_allowed']);
                $this->assertFalse((bool) $payload['enterprise_artifact']['operator_review_packet']['auto_approval_allowed']);
                $this->assertNotEmpty($payload['enterprise_artifact']['next_actions']);
                $this->assertSame(64, strlen((string) $payload['enterprise_artifact']['artifact_hash']));
                foreach ($payload['enterprise_artifact']['sections'] as $sectionId => $section) {
                    $this->assertSame('present', $section['status'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['title'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['content'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['source_lineage']['operating_package_hash'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['source_lineage']['vertical_solution_kit_hash'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['source_lineage']['business_execution_cell_hash'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['evidence_refs'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertNotEmpty($section['risk_notes'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertSame('operator_review_shadow_candidate_or_internal_supervised_run', $section['decision_use'], $companyId.'.'.$flowAction.'.'.$sectionId);
                    $this->assertSame(64, strlen((string) $section['section_hash']), $companyId.'.'.$flowAction.'.'.$sectionId);
                }
                $this->assertSame('green_internal_replay_contract_bound', $payload['replay_verification']['status']);
                $this->assertSame('green', $payload['quality_gate_result']['status']);
                $this->assertTrue((bool) $payload['quality_gate_result']['vertical_solution_kit_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['artifact_factory_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['business_execution_cell_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['business_kpi_binding_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['business_service_lane_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['business_artifact_contract_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_solution_playbook_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_operating_depth_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_agent_workforce_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['operational_dossier_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['agent_toolchain_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['workforce_capacity_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['company_operating_spine_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['commercial_operations_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_provider_workbench_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['flow_benchmark_replay_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['connector_certification_preflight_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['command_center_control_tower_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['operational_dress_rehearsal_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['semantic_operating_graph_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['company_system_model_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['internal_operations_backbone_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['activation_run_operations_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['flow_execution_foundation_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['portfolio_dependency_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['customer_account_revenue_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['premium_enterprise_agent_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_company_execution_suite_runtime_bound'], $companyId.'.'.$flowAction);
                $this->assertTrue((bool) $payload['quality_gate_result']['flow_work_product_delivery_runtime_bound'], $companyId.'.'.$flowAction);
                $this->assertTrue((bool) $payload['quality_gate_result']['productized_service_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['sales_crm_pipeline_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['customer_support_service_desk_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['marketing_growth_engine_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['finance_treasury_billing_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['governance_risk_operations_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['unit_economics_capacity_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['delivery_risk_runtime_bound']);
                $this->assertFalse((bool) $payload['quality_gate_result']['external_side_effects']);
                $this->assertSame('atlas.ai.company.agent_toolchain_runtime_attestation.v1', $payload['agent_toolchain_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['framework_source_catalog_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['repository_watchlist_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['framework_scorecard_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['flow_toolkit_assignment_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['agent_repository_epic_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['version_pin_plan_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['guardrails_runtime_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['handoffs_runtime_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['tracing_runtime_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['durable_state_runtime_bound']);
                $this->assertTrue((bool) $payload['agent_toolchain_runtime_attestation']['human_in_loop_runtime_bound']);
                $this->assertFalse((bool) $payload['agent_toolchain_runtime_attestation']['external_tool_side_effect_allowed']);
                $this->assertFalse((bool) $payload['agent_toolchain_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['agent_toolchain_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.premium_enterprise_agent_runtime_attestation.v1', $payload['premium_enterprise_agent_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['premium_model_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['agentic_architecture_basis_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['managed_agent_templates_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['template_runtime_contracts_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['flow_template_map_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['flow_managed_agent_workflow_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['data_tool_workbenches_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['connector_mcp_server_plan_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['replay_audit_harness_bound']);
                $this->assertTrue((bool) $payload['premium_enterprise_agent_runtime_attestation']['calendar_wait_removed']);
                $this->assertFalse((bool) $payload['premium_enterprise_agent_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['premium_enterprise_agent_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.domain_company_execution_suite_runtime_attestation.v1', $payload['domain_company_execution_suite_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['suite_stack_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['source_catalog_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['domain_operating_model_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['connector_execution_workbench_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['flow_domain_execution_packet_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['flow_domain_risk_control_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['flow_domain_decision_room_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['flow_domain_replay_eval_bound']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['domain_execution_observability_bound']);
                $this->assertFalse((bool) $payload['domain_company_execution_suite_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['domain_company_execution_suite_runtime_attestation']['external_side_effects_enabled']);
                $this->assertTrue((bool) $payload['domain_company_execution_suite_runtime_attestation']['operator_mandate_required_for_external_action']);
                $this->assertSame(64, strlen((string) $payload['domain_company_execution_suite_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.flow_work_product_delivery_runtime_attestation.v1', $payload['flow_work_product_delivery_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['delivery_stack_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['work_product_catalog_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['flow_delivery_blueprint_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['flow_acceptance_contract_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['flow_handoff_packet_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['flow_replay_artifact_check_bound']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['delivery_observability_bound']);
                $this->assertFalse((bool) $payload['flow_work_product_delivery_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['flow_work_product_delivery_runtime_attestation']['external_delivery_allowed']);
                $this->assertFalse((bool) $payload['flow_work_product_delivery_runtime_attestation']['external_side_effects_enabled']);
                $this->assertTrue((bool) $payload['flow_work_product_delivery_runtime_attestation']['operator_acceptance_required_before_external_handoff']);
                $this->assertSame(64, strlen((string) $payload['flow_work_product_delivery_runtime_attestation']['attestation_hash']));
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_data_connector_operating_runtime_bound'], $companyId.'.'.$flowAction);
                $this->assertSame('atlas.ai.company.domain_data_connector_operating_runtime_attestation.v1', $payload['domain_data_connector_operating_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['data_connector_stack_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['source_data_room_catalog_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['domain_data_products_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['connector_permission_profiles_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['flow_data_connector_contract_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['connector_fixture_eval_suite_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['domain_data_room_operating_model_bound']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['data_connector_observability_bound']);
                $this->assertFalse((bool) $payload['domain_data_connector_operating_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['domain_data_connector_operating_runtime_attestation']['write_tools_enabled']);
                $this->assertFalse((bool) $payload['domain_data_connector_operating_runtime_attestation']['external_data_mutation_allowed']);
                $this->assertFalse((bool) $payload['domain_data_connector_operating_runtime_attestation']['secret_export_allowed']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['read_only_probe_required_before_live_use']);
                $this->assertTrue((bool) $payload['domain_data_connector_operating_runtime_attestation']['operator_mandate_required_for_external_action']);
                $this->assertSame(64, strlen((string) $payload['domain_data_connector_operating_runtime_attestation']['attestation_hash']));
                $this->assertTrue((bool) $payload['quality_gate_result']['flow_live_read_connector_probe_runtime_bound'], $companyId.'.'.$flowAction);
                $this->assertSame('atlas.ai.company.flow_live_read_connector_probe_runtime_attestation.v1', $payload['flow_live_read_connector_probe_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['probe_stack_bound']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['connector_probe_profiles_bound']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['flow_live_read_probe_contract_bound']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['flow_probe_evidence_matrix_bound']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['probe_observability_bound']);
                $this->assertFalse((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['live_read_allowed']);
                $this->assertFalse((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['write_tools_enabled']);
                $this->assertFalse((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['external_mutation_allowed']);
                $this->assertFalse((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['credential_material_in_packet_allowed']);
                $this->assertTrue((bool) $payload['flow_live_read_connector_probe_runtime_attestation']['operator_scope_required_before_live_connector_probe']);
                $this->assertSame(64, strlen((string) $payload['flow_live_read_connector_probe_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.company_system_model_runtime_attestation.v1', $payload['company_system_model_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['domain_data_model_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['data_lineage_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['business_process_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['deliverable_quality_contract_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['production_pack_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['production_observability_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['slo_sli_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['incident_response_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['capacity_plan_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['integration_enablement_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['commercial_stack_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['commercial_intake_bound']);
                $this->assertTrue((bool) $payload['company_system_model_runtime_attestation']['commercial_fulfillment_bound']);
                $this->assertFalse((bool) $payload['company_system_model_runtime_attestation']['commercial_external_billing_allowed']);
                $this->assertFalse((bool) $payload['company_system_model_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['company_system_model_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.internal_operations_backbone_runtime_attestation.v1', $payload['internal_operations_backbone_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['account_contract_delivery_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['vendor_legal_procurement_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['resilience_continuity_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['analytics_decision_intelligence_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['knowledge_memory_learning_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['identity_access_sovereignty_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['control_tower_run_operations_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['delivery_assurance_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['grc_control_evidence_bound']);
                $this->assertTrue((bool) $payload['internal_operations_backbone_runtime_attestation']['external_customer_vendor_memory_identity_delivery_actions_blocked']);
                $this->assertFalse((bool) $payload['internal_operations_backbone_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['internal_operations_backbone_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.activation_run_operations_runtime_attestation.v1', $payload['activation_run_operations_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['integration_activation_plan_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['activation_policy_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['source_activation_tracks_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['connector_activation_tracks_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['flow_activation_matrix_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['run_queue_model_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['flow_operations_lane_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['connector_operations_probe_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['live_read_probe_plan_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['rehearsal_promotion_evidence_bound']);
                $this->assertTrue((bool) $payload['activation_run_operations_runtime_attestation']['observability_bound']);
                $this->assertFalse((bool) $payload['activation_run_operations_runtime_attestation']['external_execution_allowed']);
                $this->assertFalse((bool) $payload['activation_run_operations_runtime_attestation']['external_side_effects_enabled']);
                $this->assertFalse((bool) $payload['activation_run_operations_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertSame(64, strlen((string) $payload['activation_run_operations_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.flow_execution_foundation_runtime_attestation.v1', $payload['flow_execution_foundation_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['orchestration_stack_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['flow_runbook_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['connector_backplane_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['implementation_stack_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['executable_flow_packet_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['agent_tool_routing_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['artifact_io_contract_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['supervision_shadow_gate_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['connector_runtime_adapters_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['runtime_event_outbox_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['fixture_simulation_stack_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['canonical_fixture_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['expected_trace_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['quality_assertion_suite_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['failure_injection_bound']);
                $this->assertTrue((bool) $payload['flow_execution_foundation_runtime_attestation']['dry_run_command_bound']);
                $this->assertFalse((bool) $payload['flow_execution_foundation_runtime_attestation']['external_side_effects_enabled']);
                $this->assertFalse((bool) $payload['flow_execution_foundation_runtime_attestation']['ungoverned_external_side_effects_allowed']);
                $this->assertSame(64, strlen((string) $payload['flow_execution_foundation_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.workforce_capacity_runtime_attestation.v1', $payload['workforce_capacity_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['workforce_stack_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['org_model_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['agent_capacity_plan_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['flow_staffing_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['training_enablement_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['succession_continuity_bound']);
                $this->assertTrue((bool) $payload['workforce_capacity_runtime_attestation']['capacity_observability_bound']);
                $this->assertFalse((bool) $payload['workforce_capacity_runtime_attestation']['single_agent_bottleneck_allowed']);
                $this->assertFalse((bool) $payload['workforce_capacity_runtime_attestation']['external_unreviewed_staffing_change_allowed']);
                $this->assertSame(64, strlen((string) $payload['workforce_capacity_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.portfolio_dependency_runtime_attestation.v1', $payload['portfolio_dependency_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['dependency_stack_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['portfolio_role_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['dependency_intake_contract_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['upstream_dependency_map_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['integration_dependency_map_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['flow_dependency_routing_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['escalation_conflict_model_bound']);
                $this->assertTrue((bool) $payload['portfolio_dependency_runtime_attestation']['portfolio_reporting_contract_bound']);
                $this->assertFalse((bool) $payload['portfolio_dependency_runtime_attestation']['external_spend_publish_write_trade_or_transfer_allowed']);
                $this->assertFalse((bool) $payload['portfolio_dependency_runtime_attestation']['cross_company_dependency_without_typed_handoff_allowed']);
                $this->assertFalse((bool) $payload['portfolio_dependency_runtime_attestation']['unresolved_conflict_external_side_effect_allowed']);
                $this->assertSame(64, strlen((string) $payload['portfolio_dependency_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.enterprise_flow_agent_crew.v1', $payload['enterprise_domain_agent_crew']['schema']);
                $this->assertGreaterThanOrEqual(7, count($payload['enterprise_domain_agent_crew']['skills']));
                $this->assertGreaterThanOrEqual(5, count($payload['enterprise_domain_agent_crew']['subagents']));
                $this->assertGreaterThanOrEqual(5, count($payload['enterprise_domain_agent_crew']['work_surface_adapters']));
                $this->assertFalse((bool) $payload['enterprise_domain_agent_crew']['managed_runtime_controls']['external_side_effects_enabled']);
                $this->assertSame('atlas.ai.company.domain_agent_workforce_runtime_attestation.v1', $payload['domain_agent_workforce_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['crew_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['skills_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['connector_refs_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['subagents_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['work_surface_adapters_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['managed_runtime_controls_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['work_queue_bound']);
                $this->assertTrue((bool) $payload['domain_agent_workforce_runtime_attestation']['acceptance_contract_bound']);
                $this->assertFalse((bool) $payload['domain_agent_workforce_runtime_attestation']['external_side_effects_enabled']);
                $this->assertFalse((bool) $payload['domain_agent_workforce_runtime_attestation']['external_write_spend_trade_publish_deploy_delete_allowed']);
                $this->assertSame(64, strlen((string) $payload['domain_agent_workforce_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.external_research_adoption_runtime_attestation.v1', $payload['external_research_adoption_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['research_stack_bound']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['source_basis_bound']);
                $this->assertGreaterThanOrEqual(12, $payload['external_research_adoption_runtime_attestation']['source_basis_count']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['source_links_required']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['repository_catalog_bound']);
                $this->assertGreaterThanOrEqual(8, $payload['external_research_adoption_runtime_attestation']['official_framework_repository_count']);
                $this->assertGreaterThanOrEqual(3, $payload['external_research_adoption_runtime_attestation']['domain_repository_candidate_count']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['flow_adoption_matrix_bound']);
                $this->assertGreaterThanOrEqual(5, $payload['external_research_adoption_runtime_attestation']['flow_source_ref_count']);
                $this->assertGreaterThanOrEqual(5, $payload['external_research_adoption_runtime_attestation']['flow_repository_ref_count']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['capability_map_bound']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['connector_backlog_bound']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['production_gates_bound']);
                $this->assertFalse((bool) $payload['external_research_adoption_runtime_attestation']['runtime_ingestion_without_source_review_allowed']);
                $this->assertFalse((bool) $payload['external_research_adoption_runtime_attestation']['repository_adoption_without_license_security_and_fixture_eval_allowed']);
                $this->assertFalse((bool) $payload['external_research_adoption_runtime_attestation']['external_research_side_effects_default']);
                $this->assertTrue((bool) $payload['external_research_adoption_runtime_attestation']['operator_mandate_required_for_external_actions']);
                $this->assertFalse((bool) $payload['external_research_adoption_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertSame(64, strlen((string) $payload['external_research_adoption_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.enterprise_autonomy_promotion_packet.v1', $payload['enterprise_autonomy_promotion_packet']['schema']);
                $this->assertSame('A3_supervised_external_packet_ready', $payload['enterprise_autonomy_promotion_packet']['current_allowed_level']);
                $this->assertCount(5, $payload['enterprise_autonomy_promotion_packet']['autonomy_ladder']);
                $this->assertTrue((bool) $payload['enterprise_autonomy_promotion_packet']['stage_readiness']['fixture_internal']['ready']);
                $this->assertTrue((bool) $payload['enterprise_autonomy_promotion_packet']['stage_readiness']['shadow_internal']['ready']);
                $this->assertTrue((bool) $payload['enterprise_autonomy_promotion_packet']['stage_readiness']['supervised_internal']['ready']);
                $this->assertTrue((bool) $payload['enterprise_autonomy_promotion_packet']['stage_readiness']['supervised_external_packet']['ready']);
                $this->assertFalse((bool) $payload['enterprise_autonomy_promotion_packet']['stage_readiness']['limited_external_autonomy']['ready']);
                $this->assertGreaterThanOrEqual(8, count($payload['enterprise_autonomy_promotion_packet']['stage_readiness']['limited_external_autonomy']['blockers']));
                $this->assertGreaterThanOrEqual(14, count($payload['enterprise_autonomy_promotion_packet']['promotion_evidence_spine']));
                $this->assertFalse((bool) $payload['enterprise_autonomy_promotion_packet']['promotion_policy']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['enterprise_autonomy_promotion_packet']['promotion_policy']['external_autonomous_execution_allowed']);
                $this->assertSame('atlas.ai.company.autonomy_promotion_runtime_attestation.v1', $payload['autonomy_promotion_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['autonomy_ladder_bound']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['supervised_external_packet_bound']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['limited_external_autonomy_blocked']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['evidence_spine_bound']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['rollback_reconciliation_bound']);
                $this->assertTrue((bool) $payload['autonomy_promotion_runtime_attestation']['budget_loss_cap_bound']);
                $this->assertFalse((bool) $payload['autonomy_promotion_runtime_attestation']['external_autonomous_execution_allowed']);
                $this->assertSame(64, strlen((string) $payload['autonomy_promotion_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.operating_spine_runtime_attestation.v1', $payload['company_operating_spine_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['customer_market_operations_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['account_contract_delivery_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['vendor_legal_procurement_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['resilience_continuity_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['analytics_decision_intelligence_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['knowledge_memory_learning_bound']);
                $this->assertTrue((bool) $payload['company_operating_spine_runtime_attestation']['identity_access_data_sovereignty_bound']);
                $this->assertFalse((bool) $payload['company_operating_spine_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['company_operating_spine_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.commercial_operations_runtime_attestation.v1', $payload['commercial_operations_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['customer_market_operations_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['offer_packaging_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['customer_journey_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['customer_success_scorecard_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['account_contract_delivery_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['contract_entitlement_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['onboarding_success_plan_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['service_review_renewal_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['account_health_risk_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['billing_revenue_model_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['vendor_legal_procurement_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['vendor_due_diligence_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['source_terms_review_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['flow_procurement_routing_bound']);
                $this->assertTrue((bool) $payload['commercial_operations_runtime_attestation']['vendor_operability_bound']);
                $this->assertFalse((bool) $payload['commercial_operations_runtime_attestation']['external_customer_commitment_allowed']);
                $this->assertFalse((bool) $payload['commercial_operations_runtime_attestation']['external_billing_allowed']);
                $this->assertFalse((bool) $payload['commercial_operations_runtime_attestation']['external_vendor_procurement_allowed']);
                $this->assertSame(64, strlen((string) $payload['commercial_operations_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.domain_provider_workbench_runtime_attestation.v1', $payload['domain_provider_workbench_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['provider_contracts_bound']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['connector_workbenches_bound']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['flow_provider_route_bound']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['provider_eval_cases_bound']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['provider_data_product_lineage_bound']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['provider_workbench_observability_bound']);
                $this->assertGreaterThanOrEqual(6, $payload['domain_provider_workbench_runtime_attestation']['provider_workbench_metric_count']);
                $this->assertTrue((bool) $payload['domain_provider_workbench_runtime_attestation']['source_claims_require_provider_lineage']);
                $this->assertFalse((bool) $payload['domain_provider_workbench_runtime_attestation']['credential_material_in_packet_allowed']);
                $this->assertFalse((bool) $payload['domain_provider_workbench_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['domain_provider_workbench_runtime_attestation']['provider_write_or_paid_action_default']);
                $this->assertFalse((bool) $payload['domain_provider_workbench_runtime_attestation']['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $payload['domain_provider_workbench_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.flow_benchmark_replay_runtime_attestation.v1', $payload['flow_benchmark_replay_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['offline_dataset_contract_bound']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['trace_grading_rubric_bound']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['adversarial_regression_bound']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['deterministic_state_assertion_bound']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['replay_comparison_matrix_bound']);
                $this->assertTrue((bool) $payload['flow_benchmark_replay_runtime_attestation']['benchmark_observability_bound']);
                $this->assertGreaterThanOrEqual(10, $payload['flow_benchmark_replay_runtime_attestation']['minimum_examples']);
                $this->assertGreaterThanOrEqual(6, $payload['flow_benchmark_replay_runtime_attestation']['score_key_count']);
                $this->assertGreaterThanOrEqual(6, $payload['flow_benchmark_replay_runtime_attestation']['adversarial_case_type_count']);
                $this->assertGreaterThanOrEqual(4, $payload['flow_benchmark_replay_runtime_attestation']['state_assertion_count']);
                $this->assertGreaterThanOrEqual(6, $payload['flow_benchmark_replay_runtime_attestation']['benchmark_observability_metric_count']);
                $this->assertFalse((bool) $payload['flow_benchmark_replay_runtime_attestation']['synthetic_score_claims_allowed']);
                $this->assertFalse((bool) $payload['flow_benchmark_replay_runtime_attestation']['promotion_without_replay_green_allowed']);
                $this->assertFalse((bool) $payload['flow_benchmark_replay_runtime_attestation']['external_benchmark_execution_allowed']);
                $this->assertSame(64, strlen((string) $payload['flow_benchmark_replay_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.connector_certification_preflight_runtime_attestation.v1', $payload['connector_certification_preflight_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['flow_connector_usage_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['flow_connector_cutover_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['adapter_contracts_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['auth_boundaries_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['sandbox_probes_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['consumer_provider_contract_tests_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['connector_data_lineage_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['replay_fixture_mock_server_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['connector_slo_failure_modes_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['production_preflight_contracts_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['production_readiness_evidence_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['connector_certification_observability_bound']);
                $this->assertTrue((bool) $payload['connector_certification_preflight_runtime_attestation']['cutover_observability_bound']);
                $this->assertGreaterThan(0, $payload['connector_certification_preflight_runtime_attestation']['flow_connector_count']);
                $this->assertFalse((bool) $payload['connector_certification_preflight_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['connector_certification_preflight_runtime_attestation']['production_cutover_without_operator_signed_scope_allowed']);
                $this->assertFalse((bool) $payload['connector_certification_preflight_runtime_attestation']['real_credential_material_in_packet_allowed']);
                $this->assertFalse((bool) $payload['connector_certification_preflight_runtime_attestation']['write_or_paid_mode_allowed_by_default']);
                $this->assertFalse((bool) $payload['connector_certification_preflight_runtime_attestation']['external_connector_cutover_allowed']);
                $this->assertSame(64, strlen((string) $payload['connector_certification_preflight_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.command_center_control_tower_runtime_attestation.v1', $payload['command_center_control_tower_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['control_tower_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['control_tower_lane_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['run_queue_model_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['cadence_scheduler_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['incident_exception_desk_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['change_window_release_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['connector_probe_plan_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['dashboard_operations_map_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['human_interrupt_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['run_observability_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['flow_command_card_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['command_center_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['command_center_cells_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['connector_panels_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['operator_console_views_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['work_product_factory_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['command_center_kpis_bound']);
                $this->assertTrue((bool) $payload['command_center_control_tower_runtime_attestation']['escalation_pause_bound']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['external_write_spend_trade_publish_deploy_delete_allowed']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['secret_material_in_packet_allowed']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['control_tower_external_side_effects_default']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['run_without_decision_receipt_allowed']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['auto_retry_external_action_allowed']);
                $this->assertFalse((bool) $payload['command_center_control_tower_runtime_attestation']['external_control_tower_action_allowed']);
                $this->assertSame(64, strlen((string) $payload['command_center_control_tower_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.operational_dress_rehearsal_runtime_attestation.v1', $payload['operational_dress_rehearsal_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['dress_rehearsal_stack_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['rehearsal_runbook_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['live_read_probe_plan_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['operator_acceptance_packet_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['rollback_drill_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['promotion_evidence_bound']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['dress_rehearsal_observability_bound']);
                $this->assertGreaterThan(0, $payload['operational_dress_rehearsal_runtime_attestation']['flow_connector_count']);
                $this->assertFalse((bool) $payload['operational_dress_rehearsal_runtime_attestation']['calendar_wait_blocker_enabled']);
                $this->assertFalse((bool) $payload['operational_dress_rehearsal_runtime_attestation']['external_mutation_allowed_during_rehearsal']);
                $this->assertFalse((bool) $payload['operational_dress_rehearsal_runtime_attestation']['production_cutover_allowed_without_signed_acceptance']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['operator_and_domain_owner_acceptance_required']);
                $this->assertTrue((bool) $payload['operational_dress_rehearsal_runtime_attestation']['second_reviewer_required_for_sensitive_scope']);
                $this->assertFalse((bool) $payload['operational_dress_rehearsal_runtime_attestation']['external_side_effects_enabled']);
                $this->assertSame(64, strlen((string) $payload['operational_dress_rehearsal_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.semantic_operating_graph_runtime_attestation.v1', $payload['semantic_operating_graph_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['semantic_graph_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['node_catalog_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['flow_relationship_edge_bound']);
                $this->assertGreaterThanOrEqual(5, $payload['semantic_operating_graph_runtime_attestation']['edge_type_count']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['operating_views_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['drift_detection_rules_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['graph_export_contract_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['graph_observability_bound']);
                $this->assertTrue((bool) $payload['semantic_operating_graph_runtime_attestation']['stale_or_missing_edge_blocks_autonomy_claim']);
                $this->assertFalse((bool) $payload['semantic_operating_graph_runtime_attestation']['raw_secret_or_sensitive_payload_export_allowed']);
                $this->assertFalse((bool) $payload['semantic_operating_graph_runtime_attestation']['external_graph_mutation_allowed']);
                $this->assertSame(64, strlen((string) $payload['semantic_operating_graph_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.domain_solution_playbook_runtime_attestation.v1', $payload['domain_solution_playbook_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['solution_playbook_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['source_pack_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['domain_data_plane_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['execution_path_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['tooling_contract_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['domain_review_contract_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['benchmark_contract_bound']);
                $this->assertTrue((bool) $payload['domain_solution_playbook_runtime_attestation']['handoff_contract_bound']);
                $this->assertFalse((bool) $payload['domain_solution_playbook_runtime_attestation']['external_data_mutation_allowed']);
                $this->assertFalse((bool) $payload['domain_solution_playbook_runtime_attestation']['external_delivery_allowed']);
                $this->assertSame(64, strlen((string) $payload['domain_solution_playbook_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.domain_operating_depth_runtime_attestation.v1', $payload['domain_operating_depth_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['depth_packet_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['skills_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['connector_refs_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['subagents_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['source_refs_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['enterprise_system_refs_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['data_product_refs_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['quality_contract_bound']);
                $this->assertTrue((bool) $payload['domain_operating_depth_runtime_attestation']['operating_controls_bound']);
                $this->assertFalse((bool) $payload['domain_operating_depth_runtime_attestation']['external_write_spend_trade_publish_deploy_delete_allowed']);
                $this->assertFalse((bool) $payload['domain_operating_depth_runtime_attestation']['offensive_security_allowed']);
                $this->assertSame(64, strlen((string) $payload['domain_operating_depth_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.enterprise_flow_operational_dossier.v1', $payload['enterprise_flow_operational_dossier']['schema']);
                $this->assertSame('internal_operational_dossier_ready_external_blocked', $payload['enterprise_flow_operational_dossier']['status']);
                $this->assertGreaterThanOrEqual(10, count($payload['enterprise_flow_operational_dossier']['evidence_spine']));
                $this->assertGreaterThanOrEqual(8, count($payload['enterprise_flow_operational_dossier']['control_plane']['required_controls']));
                $this->assertFalse((bool) $payload['enterprise_flow_operational_dossier']['decision_packet']['external_delivery_allowed']);
                $this->assertSame(64, strlen((string) $payload['enterprise_flow_operational_dossier']['dossier_hash']));
                $this->assertSame('atlas.ai.company.enterprise_operational_dossier_runtime_attestation.v1', $payload['operational_dossier_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['dossier_bound']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['evidence_spine_bound']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['control_plane_bound']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['decision_packet_bound']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['promotion_path_bound']);
                $this->assertTrue((bool) $payload['operational_dossier_runtime_attestation']['scorecard_bound']);
                $this->assertFalse((bool) $payload['operational_dossier_runtime_attestation']['external_execution_allowed']);
                $this->assertFalse((bool) $payload['operational_dossier_runtime_attestation']['external_delivery_allowed']);
                $this->assertFalse((bool) $payload['operational_dossier_runtime_attestation']['real_world_autonomy_claim_allowed']);
                $this->assertSame(64, strlen((string) $payload['operational_dossier_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.customer_account_revenue_runtime_attestation.v1', $payload['customer_account_revenue_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['customer_market_runtime_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['positioning_claim_review_required']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['offer_packaging_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['journey_lifecycle_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['customer_success_scorecard_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['commercial_service_catalog_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['business_kpi_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['account_contract_delivery_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['account_segment_playbook_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['contract_entitlement_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['onboarding_success_plan_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['service_review_renewal_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['account_health_risk_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['billing_revenue_model_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['account_observability_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['voice_of_customer_loop_bound']);
                $this->assertTrue((bool) $payload['customer_account_revenue_runtime_attestation']['growth_retention_model_bound']);
                $this->assertFalse((bool) $payload['customer_account_revenue_runtime_attestation']['revenue_claim_allowed']);
                $this->assertFalse((bool) $payload['customer_account_revenue_runtime_attestation']['external_customer_commitment_allowed']);
                $this->assertFalse((bool) $payload['customer_account_revenue_runtime_attestation']['external_billing_allowed']);
                $this->assertSame(64, strlen((string) $payload['customer_account_revenue_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.flow_productized_service_offer.v1', $payload['enterprise_productized_service_offer']['schema']);
                $this->assertFalse((bool) $payload['enterprise_productized_service_offer']['external_customer_commitment_allowed']);
                $this->assertSame('atlas.ai.company.productized_service_runtime_attestation.v1', $payload['productized_service_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['product_stack_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['domain_product_line_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['service_offer_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['delivery_blueprint_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['intake_contract_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['sla_success_contract_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['pricing_packaging_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['gtm_motion_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['proof_template_bound']);
                $this->assertTrue((bool) $payload['productized_service_runtime_attestation']['product_observability_bound']);
                $this->assertFalse((bool) $payload['productized_service_runtime_attestation']['public_gtm_or_customer_commitment_allowed']);
                $this->assertFalse((bool) $payload['productized_service_runtime_attestation']['external_billing_allowed']);
                $this->assertSame(64, strlen((string) $payload['productized_service_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.sales_crm_pipeline_runtime_attestation.v1', $payload['sales_crm_pipeline_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['sales_stack_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['source_catalog_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['crm_object_model_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['segment_sales_play_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['opportunity_route_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['proposal_scope_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['mutual_action_plan_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['account_research_workbench_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['deal_room_packet_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['pipeline_forecast_review_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['map_risk_review_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['renewal_expansion_signal_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['sales_delivery_handoff_bound']);
                $this->assertTrue((bool) $payload['sales_crm_pipeline_runtime_attestation']['pipeline_observability_bound']);
                $this->assertFalse((bool) $payload['sales_crm_pipeline_runtime_attestation']['external_sales_commitment_allowed']);
                $this->assertFalse((bool) $payload['sales_crm_pipeline_runtime_attestation']['public_claim_or_paid_campaign_allowed']);
                $this->assertSame(64, strlen((string) $payload['sales_crm_pipeline_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.customer_support_service_desk_runtime_attestation.v1', $payload['customer_support_service_desk_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['support_stack_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['source_catalog_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['service_desk_object_model_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['support_segment_playbook_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['support_lane_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['ticket_sla_contract_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['knowledge_base_template_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['escalation_incident_runbook_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['resolution_rca_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['case_resolution_workbench_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['customer_health_escalation_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['knowledge_quality_review_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['automation_deflection_test_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['feedback_learning_loop_bound']);
                $this->assertTrue((bool) $payload['customer_support_service_desk_runtime_attestation']['support_observability_bound']);
                $this->assertFalse((bool) $payload['customer_support_service_desk_runtime_attestation']['external_customer_message_or_support_commitment_allowed']);
                $this->assertFalse((bool) $payload['customer_support_service_desk_runtime_attestation']['regulated_support_advice_allowed_without_review']);
                $this->assertSame(64, strlen((string) $payload['customer_support_service_desk_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.marketing_growth_engine_runtime_attestation.v1', $payload['marketing_growth_engine_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['marketing_stack_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['source_catalog_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['growth_operating_model_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['audience_segment_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['campaign_blueprint_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['content_asset_factory_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['experiment_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['growth_intelligence_workbench_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['attribution_experiment_model_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['channel_budget_guardrail_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['public_claim_evidence_packet_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['channel_distribution_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['brand_compliance_review_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['growth_crm_handoff_bound']);
                $this->assertTrue((bool) $payload['marketing_growth_engine_runtime_attestation']['marketing_observability_bound']);
                $this->assertFalse((bool) $payload['marketing_growth_engine_runtime_attestation']['external_publish_paid_campaign_or_outreach_allowed']);
                $this->assertFalse((bool) $payload['marketing_growth_engine_runtime_attestation']['public_claim_allowed_without_source_and_operator_review']);
                $this->assertSame(64, strlen((string) $payload['marketing_growth_engine_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.finance_treasury_billing_runtime_attestation.v1', $payload['finance_treasury_billing_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['finance_stack_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['source_catalog_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['financial_data_interface_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['provider_connector_matrix_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['cfo_operating_model_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['financial_research_workbench_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['budget_envelope_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['forecast_model_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['model_risk_control_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['investment_committee_packet_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['pnl_line_item_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['billing_ledger_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['treasury_risk_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['finance_close_audit_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['finance_observability_bound']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['source_linked_financial_claim_required']);
                $this->assertTrue((bool) $payload['finance_treasury_billing_runtime_attestation']['model_risk_review_required']);
                $this->assertFalse((bool) $payload['finance_treasury_billing_runtime_attestation']['external_financial_action_allowed']);
                $this->assertFalse((bool) $payload['finance_treasury_billing_runtime_attestation']['real_revenue_cash_or_aum_claim_allowed']);
                $this->assertFalse((bool) $payload['finance_treasury_billing_runtime_attestation']['real_money_movement_allowed']);
                $this->assertSame(64, strlen((string) $payload['finance_treasury_billing_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.governance_risk_operations_runtime_attestation.v1', $payload['governance_risk_operations_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['vendor_procurement_bound']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['resilience_continuity_bound']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['analytics_decision_bound']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['knowledge_learning_bound']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['identity_sovereignty_bound']);
                $this->assertTrue((bool) $payload['governance_risk_operations_runtime_attestation']['grc_evidence_bound']);
                $this->assertFalse((bool) $payload['governance_risk_operations_runtime_attestation']['vendor_purchase_contract_signature_secret_share_or_write_scope_allowed']);
                $this->assertFalse((bool) $payload['governance_risk_operations_runtime_attestation']['incident_external_notification_without_operator_allowed']);
                $this->assertFalse((bool) $payload['governance_risk_operations_runtime_attestation']['canonical_memory_write_without_review_allowed']);
                $this->assertFalse((bool) $payload['governance_risk_operations_runtime_attestation']['secret_material_or_unscoped_memory_export_allowed']);
                $this->assertSame(64, strlen((string) $payload['governance_risk_operations_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.unit_economics_capacity_runtime_attestation.v1', $payload['unit_economics_capacity_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['flow_cost_center_bound']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['flow_unit_economics_bound']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['capacity_simulation_bound']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['pricing_ladder_bound']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['agent_capacity_cost_model_bound']);
                $this->assertTrue((bool) $payload['unit_economics_capacity_runtime_attestation']['connector_cost_limit_model_bound']);
                $this->assertFalse((bool) $payload['unit_economics_capacity_runtime_attestation']['real_capital_action_allowed']);
                $this->assertFalse((bool) $payload['unit_economics_capacity_runtime_attestation']['external_revenue_or_savings_claim_allowed']);
                $this->assertSame(64, strlen((string) $payload['unit_economics_capacity_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.delivery_risk_runtime_attestation.v1', $payload['delivery_risk_runtime_attestation']['schema']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['delivery_assurance_runtime_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['delivery_sla_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['delivery_risk_controls_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['strategic_intelligence_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['rival_alternative_map_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['benchmark_evidence_required']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['grc_runtime_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['audit_evidence_bound']);
                $this->assertTrue((bool) $payload['delivery_risk_runtime_attestation']['policy_exception_blocked']);
                $this->assertFalse((bool) $payload['delivery_risk_runtime_attestation']['external_delivery_allowed']);
                $this->assertFalse((bool) $payload['delivery_risk_runtime_attestation']['customer_visible_claim_allowed']);
                $this->assertSame(64, strlen((string) $payload['delivery_risk_runtime_attestation']['attestation_hash']));
                $this->assertSame('atlas.ai.company.enterprise_flow_runtime_record_ref.v1', $payload['runtime_record']['schema']);
                $this->assertSame($companyId, $payload['runtime_record']['domain_id']);
                $this->assertSame('completed', $payload['runtime_record']['runtime_status']);
                $this->assertSame(64, strlen((string) $payload['runtime_record']['receipt_hash']));
                $this->assertContains('trade', $payload['blocked_operations']);
                $this->assertSame(64, strlen((string) $payload['receipt_hash']));
            }
        }

        $this->assertSame($expectedRuntimeRecords, AiDomainRuntimeRecord::query()->count());
        $record = AiDomainRuntimeRecord::query()
            ->where('domain_id', 'finance')
            ->get()
            ->first(static fn (AiDomainRuntimeRecord $record): bool => in_array(
                'enterprise_flow_action_runtime:finance:market_research_brief',
                (array) $record->evidence_refs,
                true,
            ));
        $this->assertNotNull($record);
        $this->assertSame('enterprise_flow_action', data_get($record->execution_plan, 'runtime_kind'));
        $this->assertContains('enterprise_flow_action_runtime:finance:market_research_brief', (array) $record->evidence_refs);
        $this->assertContains('enterprise_flow_operational_dossier_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_vertical_solution_suite_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_domain_solution_playbook_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_domain_operating_depth_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_domain_business_execution_mesh_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_company_operating_spine_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_commercial_operations_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_domain_provider_workbench_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_flow_benchmark_replay_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_connector_certification_preflight_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_command_center_control_tower_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_operational_dress_rehearsal_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_semantic_operating_graph_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_company_system_model_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_internal_operations_backbone_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_activation_run_operations_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_flow_execution_foundation_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_agent_toolchain_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_workforce_capacity_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_portfolio_dependency_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_external_research_adoption_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_autonomy_promotion_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_customer_account_revenue_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_productized_service_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_sales_crm_pipeline_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_customer_support_service_desk_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_marketing_growth_engine_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_finance_treasury_billing_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_governance_risk_operations_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_unit_economics_capacity_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_delivery_risk_runtime', (array) $record->selected_capabilities);
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'governance_risk_operations_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'workforce_capacity_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'company_system_model_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'internal_operations_backbone_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'activation_run_operations_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'flow_execution_foundation_attestation:'),
        ));
        $this->assertTrue(collect((array) $record->evidence_refs)->contains(
            static fn (string $ref): bool => str_starts_with($ref, 'portfolio_dependency_attestation:'),
        ));
    }

    public function test_enterprise_flow_action_runtime_run_executes_portfolio_and_reports_status(): void
    {
        $this->createDomainRuntimeTables();
        AiDomainRuntimeRecord::query()->delete();

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-action-runtime-status',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(1, $statusExit);
        $this->assertIsArray($status);
        $this->assertSame('atlas.ai.holding.enterprise_flow_action_runtime_status.v1', $status['schema']);
        $this->assertSame('missing_internal_flow_action_runtime_coverage', $status['status']);
        $this->assertGreaterThanOrEqual(54, $status['summary']['expected_flow_count']);
        $this->assertSame(0, $status['summary']['completed_runtime_flow_count']);

        $verticalStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-vertical-solution-runtime-status',
            '--json' => true,
        ]);
        $verticalStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $verticalStatusExit);
        $this->assertIsArray($verticalStatus);
        $this->assertSame('atlas.ai.holding.enterprise_vertical_solution_runtime_status.v1', $verticalStatus['schema']);
        $this->assertSame('missing_vertical_solution_runtime_coverage', $verticalStatus['status']);
        $this->assertGreaterThanOrEqual(54, $verticalStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $verticalStatus['summary']['completed_vertical_runtime_flow_count']);

        $domainSolutionPlaybookStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-solution-playbook-runtime-status',
            '--json' => true,
        ]);
        $domainSolutionPlaybookStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainSolutionPlaybookStatusExit);
        $this->assertIsArray($domainSolutionPlaybookStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_solution_playbook_runtime_status.v1', $domainSolutionPlaybookStatus['schema']);
        $this->assertSame('missing_domain_solution_playbook_runtime_coverage', $domainSolutionPlaybookStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainSolutionPlaybookStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainSolutionPlaybookStatus['summary']['completed_domain_solution_playbook_flow_count']);

        $domainOperatingDepthStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-operating-depth-runtime-status',
            '--json' => true,
        ]);
        $domainOperatingDepthStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainOperatingDepthStatusExit);
        $this->assertIsArray($domainOperatingDepthStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_operating_depth_runtime_status.v1', $domainOperatingDepthStatus['schema']);
        $this->assertSame('missing_domain_operating_depth_runtime_coverage', $domainOperatingDepthStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainOperatingDepthStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainOperatingDepthStatus['summary']['completed_domain_operating_depth_flow_count']);

        $domainAgentWorkforceStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-agent-workforce-runtime-status',
            '--json' => true,
        ]);
        $domainAgentWorkforceStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainAgentWorkforceStatusExit);
        $this->assertIsArray($domainAgentWorkforceStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_agent_workforce_runtime_status.v1', $domainAgentWorkforceStatus['schema']);
        $this->assertSame('missing_domain_agent_workforce_runtime_coverage', $domainAgentWorkforceStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainAgentWorkforceStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainAgentWorkforceStatus['summary']['completed_domain_agent_workforce_flow_count']);

        $operationalDossierStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-dossier-runtime-status',
            '--json' => true,
        ]);
        $operationalDossierStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $operationalDossierStatusExit);
        $this->assertIsArray($operationalDossierStatus);
        $this->assertSame('atlas.ai.holding.enterprise_operational_dossier_runtime_status.v1', $operationalDossierStatus['schema']);
        $this->assertSame('missing_operational_dossier_runtime_coverage', $operationalDossierStatus['status']);
        $this->assertGreaterThanOrEqual(54, $operationalDossierStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $operationalDossierStatus['summary']['completed_operational_dossier_flow_count']);

        $businessRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-business-execution-runtime-status',
            '--json' => true,
        ]);
        $businessRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $businessRuntimeStatusExit);
        $this->assertIsArray($businessRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_business_execution_runtime_status.v1', $businessRuntimeStatus['schema']);
        $this->assertSame('missing_domain_business_execution_runtime_coverage', $businessRuntimeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $businessRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $businessRuntimeStatus['summary']['completed_business_execution_runtime_flow_count']);

        $operatingSpineStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-spine-runtime-status',
            '--json' => true,
        ]);
        $operatingSpineStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $operatingSpineStatusExit);
        $this->assertIsArray($operatingSpineStatus);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_spine_runtime_status.v1', $operatingSpineStatus['schema']);
        $this->assertSame('missing_company_operating_spine_runtime_coverage', $operatingSpineStatus['status']);
        $this->assertGreaterThanOrEqual(54, $operatingSpineStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $operatingSpineStatus['summary']['completed_operating_spine_flow_count']);

        $commercialOperationsStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-commercial-operations-runtime-status',
            '--json' => true,
        ]);
        $commercialOperationsStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $commercialOperationsStatusExit);
        $this->assertIsArray($commercialOperationsStatus);
        $this->assertSame('atlas.ai.holding.enterprise_commercial_operations_runtime_status.v1', $commercialOperationsStatus['schema']);
        $this->assertSame('missing_commercial_operations_runtime_coverage', $commercialOperationsStatus['status']);
        $this->assertGreaterThanOrEqual(54, $commercialOperationsStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $commercialOperationsStatus['summary']['completed_commercial_operations_flow_count']);

        $domainProviderWorkbenchStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-provider-workbench-runtime-status',
            '--json' => true,
        ]);
        $domainProviderWorkbenchStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainProviderWorkbenchStatusExit);
        $this->assertIsArray($domainProviderWorkbenchStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_provider_workbench_runtime_status.v1', $domainProviderWorkbenchStatus['schema']);
        $this->assertSame('missing_domain_provider_workbench_runtime_coverage', $domainProviderWorkbenchStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainProviderWorkbenchStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainProviderWorkbenchStatus['summary']['completed_provider_workbench_flow_count']);

        $domainCompanyExecutionSuiteStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-company-execution-suite-runtime-status',
            '--json' => true,
        ]);
        $domainCompanyExecutionSuiteStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainCompanyExecutionSuiteStatusExit);
        $this->assertIsArray($domainCompanyExecutionSuiteStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_company_execution_suite_runtime_status.v1', $domainCompanyExecutionSuiteStatus['schema']);
        $this->assertSame('missing_domain_company_execution_suite_runtime_coverage', $domainCompanyExecutionSuiteStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainCompanyExecutionSuiteStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainCompanyExecutionSuiteStatus['summary']['completed_domain_company_execution_suite_flow_count']);

        $flowWorkProductDeliveryStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-work-product-delivery-runtime-status',
            '--json' => true,
        ]);
        $flowWorkProductDeliveryStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $flowWorkProductDeliveryStatusExit);
        $this->assertIsArray($flowWorkProductDeliveryStatus);
        $this->assertSame('atlas.ai.holding.enterprise_flow_work_product_delivery_runtime_status.v1', $flowWorkProductDeliveryStatus['schema']);
        $this->assertSame('missing_flow_work_product_delivery_runtime_coverage', $flowWorkProductDeliveryStatus['status']);
        $this->assertGreaterThanOrEqual(54, $flowWorkProductDeliveryStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $flowWorkProductDeliveryStatus['summary']['completed_flow_work_product_delivery_count']);

        $domainDataConnectorStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-data-connector-operating-runtime-status',
            '--json' => true,
        ]);
        $domainDataConnectorStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $domainDataConnectorStatusExit);
        $this->assertIsArray($domainDataConnectorStatus);
        $this->assertSame('atlas.ai.holding.enterprise_domain_data_connector_operating_runtime_status.v1', $domainDataConnectorStatus['schema']);
        $this->assertSame('missing_domain_data_connector_operating_runtime_coverage', $domainDataConnectorStatus['status']);
        $this->assertGreaterThanOrEqual(54, $domainDataConnectorStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $domainDataConnectorStatus['summary']['completed_domain_data_connector_flow_count']);

        $flowLiveReadConnectorProbeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-live-read-connector-probe-runtime-status',
            '--json' => true,
        ]);
        $flowLiveReadConnectorProbeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $flowLiveReadConnectorProbeStatusExit);
        $this->assertIsArray($flowLiveReadConnectorProbeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_flow_live_read_connector_probe_runtime_status.v1', $flowLiveReadConnectorProbeStatus['schema']);
        $this->assertSame('missing_flow_live_read_connector_probe_runtime_coverage', $flowLiveReadConnectorProbeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $flowLiveReadConnectorProbeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $flowLiveReadConnectorProbeStatus['summary']['completed_flow_live_read_connector_probe_count']);

        $externalResearchAdoptionStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-research-adoption-runtime-status',
            '--json' => true,
        ]);
        $externalResearchAdoptionStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $externalResearchAdoptionStatusExit);
        $this->assertIsArray($externalResearchAdoptionStatus);
        $this->assertSame('atlas.ai.holding.enterprise_external_research_adoption_runtime_status.v1', $externalResearchAdoptionStatus['schema']);
        $this->assertSame('missing_external_research_adoption_runtime_coverage', $externalResearchAdoptionStatus['status']);
        $this->assertGreaterThanOrEqual(54, $externalResearchAdoptionStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $externalResearchAdoptionStatus['summary']['completed_external_research_adoption_flow_count']);

        $autonomyPromotionStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-autonomy-promotion-runtime-status',
            '--json' => true,
        ]);
        $autonomyPromotionStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $autonomyPromotionStatusExit);
        $this->assertIsArray($autonomyPromotionStatus);
        $this->assertSame('atlas.ai.holding.enterprise_autonomy_promotion_runtime_status.v1', $autonomyPromotionStatus['schema']);
        $this->assertSame('missing_autonomy_promotion_runtime_coverage', $autonomyPromotionStatus['status']);
        $this->assertGreaterThanOrEqual(54, $autonomyPromotionStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $autonomyPromotionStatus['summary']['completed_autonomy_promotion_flow_count']);

        $flowBenchmarkReplayStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-benchmark-replay-runtime-status',
            '--json' => true,
        ]);
        $flowBenchmarkReplayStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $flowBenchmarkReplayStatusExit);
        $this->assertIsArray($flowBenchmarkReplayStatus);
        $this->assertSame('atlas.ai.holding.enterprise_flow_benchmark_replay_runtime_status.v1', $flowBenchmarkReplayStatus['schema']);
        $this->assertSame('missing_flow_benchmark_replay_runtime_coverage', $flowBenchmarkReplayStatus['status']);
        $this->assertGreaterThanOrEqual(54, $flowBenchmarkReplayStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $flowBenchmarkReplayStatus['summary']['completed_benchmark_replay_flow_count']);

        $connectorRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-certification-preflight-runtime-status',
            '--json' => true,
        ]);
        $connectorRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $connectorRuntimeStatusExit);
        $this->assertIsArray($connectorRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_connector_certification_preflight_runtime_status.v1', $connectorRuntimeStatus['schema']);
        $this->assertSame('missing_connector_certification_preflight_runtime_coverage', $connectorRuntimeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $connectorRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $connectorRuntimeStatus['summary']['completed_connector_certification_preflight_flow_count']);

        $commandCenterRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-command-center-control-tower-runtime-status',
            '--json' => true,
        ]);
        $commandCenterRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $commandCenterRuntimeStatusExit);
        $this->assertIsArray($commandCenterRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_command_center_control_tower_runtime_status.v1', $commandCenterRuntimeStatus['schema']);
        $this->assertSame('missing_command_center_control_tower_runtime_coverage', $commandCenterRuntimeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $commandCenterRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $commandCenterRuntimeStatus['summary']['completed_command_center_control_tower_flow_count']);

        $semanticGraphStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-semantic-operating-graph-runtime-status',
            '--json' => true,
        ]);
        $semanticGraphStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $semanticGraphStatusExit);
        $this->assertIsArray($semanticGraphStatus);
        $this->assertSame('atlas.ai.holding.enterprise_semantic_operating_graph_runtime_status.v1', $semanticGraphStatus['schema']);
        $this->assertSame('missing_semantic_operating_graph_runtime_coverage', $semanticGraphStatus['status']);
        $this->assertGreaterThanOrEqual(54, $semanticGraphStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $semanticGraphStatus['summary']['completed_semantic_graph_flow_count']);

        $agentToolchainStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-agent-toolchain-runtime-status',
            '--json' => true,
        ]);
        $agentToolchainStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $agentToolchainStatusExit);
        $this->assertIsArray($agentToolchainStatus);
        $this->assertSame('atlas.ai.holding.enterprise_agent_toolchain_runtime_status.v1', $agentToolchainStatus['schema']);
        $this->assertSame('missing_agent_toolchain_runtime_coverage', $agentToolchainStatus['status']);
        $this->assertGreaterThanOrEqual(54, $agentToolchainStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $agentToolchainStatus['summary']['completed_agent_toolchain_flow_count']);

        $handoffRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-cross-company-handoff-runtime-status',
            '--json' => true,
        ]);
        $handoffRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $handoffRuntimeStatusExit);
        $this->assertIsArray($handoffRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_cross_company_handoff_runtime_status.v1', $handoffRuntimeStatus['schema']);
        $this->assertSame('cross_company_handoff_runtime_attention_required', $handoffRuntimeStatus['status']);
        $this->assertGreaterThan(0, $handoffRuntimeStatus['summary']['handoff_contract_count']);
        $this->assertSame(0, $handoffRuntimeStatus['summary']['ready_handoff_runtime_packet_count']);

        $customerAccountRevenueStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-customer-account-revenue-runtime-status',
            '--json' => true,
        ]);
        $customerAccountRevenueStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $customerAccountRevenueStatusExit);
        $this->assertIsArray($customerAccountRevenueStatus);
        $this->assertSame('atlas.ai.holding.enterprise_customer_account_revenue_runtime_status.v1', $customerAccountRevenueStatus['schema']);
        $this->assertSame('missing_customer_account_revenue_runtime_coverage', $customerAccountRevenueStatus['status']);
        $this->assertGreaterThanOrEqual(54, $customerAccountRevenueStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $customerAccountRevenueStatus['summary']['completed_customer_account_revenue_flow_count']);

        $productizedServiceStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-productized-service-runtime-status',
            '--json' => true,
        ]);
        $productizedServiceStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $productizedServiceStatusExit);
        $this->assertIsArray($productizedServiceStatus);
        $this->assertSame('atlas.ai.holding.enterprise_productized_service_runtime_status.v1', $productizedServiceStatus['schema']);
        $this->assertSame('missing_productized_service_runtime_coverage', $productizedServiceStatus['status']);
        $this->assertGreaterThanOrEqual(54, $productizedServiceStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $productizedServiceStatus['summary']['completed_productized_service_flow_count']);

        $salesCrmStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-sales-crm-pipeline-runtime-status',
            '--json' => true,
        ]);
        $salesCrmStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $salesCrmStatusExit);
        $this->assertIsArray($salesCrmStatus);
        $this->assertSame('atlas.ai.holding.enterprise_sales_crm_pipeline_runtime_status.v1', $salesCrmStatus['schema']);
        $this->assertSame('missing_sales_crm_pipeline_runtime_coverage', $salesCrmStatus['status']);
        $this->assertGreaterThanOrEqual(54, $salesCrmStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $salesCrmStatus['summary']['completed_sales_crm_pipeline_flow_count']);

        $supportStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-customer-support-service-desk-runtime-status',
            '--json' => true,
        ]);
        $supportStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $supportStatusExit);
        $this->assertIsArray($supportStatus);
        $this->assertSame('atlas.ai.holding.enterprise_customer_support_service_desk_runtime_status.v1', $supportStatus['schema']);
        $this->assertSame('missing_customer_support_service_desk_runtime_coverage', $supportStatus['status']);
        $this->assertGreaterThanOrEqual(54, $supportStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $supportStatus['summary']['completed_customer_support_service_desk_flow_count']);

        $marketingStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-marketing-growth-engine-runtime-status',
            '--json' => true,
        ]);
        $marketingStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $marketingStatusExit);
        $this->assertIsArray($marketingStatus);
        $this->assertSame('atlas.ai.holding.enterprise_marketing_growth_engine_runtime_status.v1', $marketingStatus['schema']);
        $this->assertSame('missing_marketing_growth_engine_runtime_coverage', $marketingStatus['status']);
        $this->assertGreaterThanOrEqual(54, $marketingStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $marketingStatus['summary']['completed_marketing_growth_engine_flow_count']);

        $financeTreasuryStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-finance-treasury-billing-runtime-status',
            '--json' => true,
        ]);
        $financeTreasuryStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $financeTreasuryStatusExit);
        $this->assertIsArray($financeTreasuryStatus);
        $this->assertSame('atlas.ai.holding.enterprise_finance_treasury_billing_runtime_status.v1', $financeTreasuryStatus['schema']);
        $this->assertSame('missing_finance_treasury_billing_runtime_coverage', $financeTreasuryStatus['status']);
        $this->assertGreaterThanOrEqual(54, $financeTreasuryStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $financeTreasuryStatus['summary']['completed_finance_treasury_billing_flow_count']);

        $unitEconomicsStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-unit-economics-capacity-runtime-status',
            '--json' => true,
        ]);
        $unitEconomicsStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $unitEconomicsStatusExit);
        $this->assertIsArray($unitEconomicsStatus);
        $this->assertSame('atlas.ai.holding.enterprise_unit_economics_capacity_runtime_status.v1', $unitEconomicsStatus['schema']);
        $this->assertSame('missing_unit_economics_capacity_runtime_coverage', $unitEconomicsStatus['status']);
        $this->assertGreaterThanOrEqual(54, $unitEconomicsStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $unitEconomicsStatus['summary']['completed_unit_economics_capacity_flow_count']);

        $deliveryRiskStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-delivery-risk-runtime-status',
            '--json' => true,
        ]);
        $deliveryRiskStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $deliveryRiskStatusExit);
        $this->assertIsArray($deliveryRiskStatus);
        $this->assertSame('atlas.ai.holding.enterprise_delivery_risk_runtime_status.v1', $deliveryRiskStatus['schema']);
        $this->assertSame('missing_delivery_risk_runtime_coverage', $deliveryRiskStatus['status']);
        $this->assertGreaterThanOrEqual(54, $deliveryRiskStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $deliveryRiskStatus['summary']['completed_delivery_risk_flow_count']);

        $outcomeRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-outcome-runtime-status',
            '--json' => true,
        ]);
        $outcomeRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $outcomeRuntimeStatusExit);
        $this->assertIsArray($outcomeRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_operational_outcome_runtime_status.v1', $outcomeRuntimeStatus['schema']);
        $this->assertSame('missing_operational_outcome_runtime_coverage', $outcomeRuntimeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $outcomeRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $outcomeRuntimeStatus['summary']['completed_operational_outcome_flow_count']);

        $holdingScorecardExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-holding-outcome-scorecard-status',
            '--json' => true,
        ]);
        $holdingScorecard = json_decode(Artisan::output(), true);

        $this->assertSame(1, $holdingScorecardExit);
        $this->assertIsArray($holdingScorecard);
        $this->assertSame('atlas.ai.holding.enterprise_holding_outcome_scorecard_status.v1', $holdingScorecard['schema']);
        $this->assertSame('holding_outcome_scorecard_attention_required', $holdingScorecard['status']);
        $this->assertSame(0, $holdingScorecard['summary']['completed_outcome_flow_count']);

        $portfolioDecisionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-portfolio-decision-packet-status',
            '--json' => true,
        ]);
        $portfolioDecision = json_decode(Artisan::output(), true);

        $this->assertSame(1, $portfolioDecisionExit);
        $this->assertIsArray($portfolioDecision);
        $this->assertSame('atlas.ai.holding.enterprise_portfolio_decision_packet_status.v1', $portfolioDecision['schema']);
        $this->assertSame('portfolio_decision_packet_attention_required', $portfolioDecision['status']);
        $this->assertSame(0, $portfolioDecision['summary']['ready_decision_packet_count']);

        $dressRehearsalRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-dress-rehearsal-runtime-status',
            '--json' => true,
        ]);
        $dressRehearsalRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(1, $dressRehearsalRuntimeStatusExit);
        $this->assertIsArray($dressRehearsalRuntimeStatus);
        $this->assertSame('atlas.ai.holding.enterprise_operational_dress_rehearsal_runtime_status.v1', $dressRehearsalRuntimeStatus['schema']);
        $this->assertSame('missing_operational_dress_rehearsal_runtime_coverage', $dressRehearsalRuntimeStatus['status']);
        $this->assertGreaterThanOrEqual(54, $dressRehearsalRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame(0, $dressRehearsalRuntimeStatus['summary']['completed_operational_dress_rehearsal_flow_count']);

        $runExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-action-runtime-run',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true);

        $this->assertSame(0, $runExit);
        $this->assertIsArray($run);
        $this->assertTrue((bool) $run['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_action_runtime_run.v1', $run['schema']);
        $this->assertSame('completed_internal_flow_action_runtime_external_blocked', $run['status']);
        $this->assertSame(9, $run['summary']['company_count']);
        $this->assertGreaterThanOrEqual(54, $run['summary']['flow_count']);
        $this->assertSame($run['summary']['flow_count'], $run['summary']['completed_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $run['summary']['runtime_record_bound_count']);
        $this->assertSame(0, $run['summary']['external_side_effect_count']);
        $this->assertSame(64, strlen((string) $run['runtime_run_hash']));

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-action-runtime-status',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertIsArray($status);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('complete_internal_flow_action_runtime_coverage', $status['status']);
        $this->assertSame($run['summary']['flow_count'], $status['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $status['summary']['completed_runtime_flow_count']);
        $this->assertSame(1.0, (float) $status['summary']['coverage_rate']);
        $this->assertSame(64, strlen((string) $status['runtime_status_hash']));

        $verticalStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-vertical-solution-runtime-status',
            '--json' => true,
        ]);
        $verticalStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $verticalStatusExit);
        $this->assertIsArray($verticalStatus);
        $this->assertTrue((bool) $verticalStatus['ok']);
        $this->assertSame('complete_vertical_solution_runtime_coverage_external_blocked', $verticalStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['completed_vertical_runtime_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['vertical_solution_kit_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['artifact_factory_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['vertical_connector_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $verticalStatus['summary']['domain_execution_brief_bound_count']);
        $this->assertSame(0, $verticalStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $verticalStatus['summary']['coverage_rate']);
        $this->assertSame(64, strlen((string) $verticalStatus['vertical_solution_runtime_status_hash']));

        $domainSolutionPlaybookStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-solution-playbook-runtime-status',
            '--json' => true,
        ]);
        $domainSolutionPlaybookStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainSolutionPlaybookStatusExit);
        $this->assertIsArray($domainSolutionPlaybookStatus);
        $this->assertTrue((bool) $domainSolutionPlaybookStatus['ok']);
        $this->assertSame('complete_domain_solution_playbook_runtime_coverage_external_mutation_blocked', $domainSolutionPlaybookStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['completed_domain_solution_playbook_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['solution_playbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['source_pack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['domain_data_plane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['execution_path_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['tooling_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['domain_review_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['benchmark_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['handoff_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainSolutionPlaybookStatus['summary']['external_mutation_blocked_count']);
        $this->assertSame(0, $domainSolutionPlaybookStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainSolutionPlaybookStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainSolutionPlaybookStatus['policy']['external_data_mutation_allowed']);
        $this->assertSame(64, strlen((string) $domainSolutionPlaybookStatus['domain_solution_playbook_runtime_status_hash']));

        $domainOperatingDepthStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-operating-depth-runtime-status',
            '--json' => true,
        ]);
        $domainOperatingDepthStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainOperatingDepthStatusExit);
        $this->assertIsArray($domainOperatingDepthStatus);
        $this->assertTrue((bool) $domainOperatingDepthStatus['ok']);
        $this->assertSame('complete_domain_operating_depth_runtime_coverage_external_effects_blocked', $domainOperatingDepthStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['completed_domain_operating_depth_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['depth_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['skills_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['connector_refs_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['subagents_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['source_refs_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['enterprise_system_refs_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['data_product_refs_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['quality_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['operating_controls_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainOperatingDepthStatus['summary']['external_effects_blocked_count']);
        $this->assertSame(0, $domainOperatingDepthStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainOperatingDepthStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainOperatingDepthStatus['policy']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertFalse((bool) $domainOperatingDepthStatus['policy']['offensive_security_allowed']);
        $this->assertSame(64, strlen((string) $domainOperatingDepthStatus['domain_operating_depth_runtime_status_hash']));

        $domainAgentWorkforceStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-agent-workforce-runtime-status',
            '--json' => true,
        ]);
        $domainAgentWorkforceStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainAgentWorkforceStatusExit);
        $this->assertIsArray($domainAgentWorkforceStatus);
        $this->assertTrue((bool) $domainAgentWorkforceStatus['ok']);
        $this->assertSame('complete_domain_agent_workforce_runtime_coverage_external_effects_blocked', $domainAgentWorkforceStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['completed_domain_agent_workforce_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['crew_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['skills_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['connector_refs_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['subagents_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['work_surface_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['managed_controls_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['work_queue_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['acceptance_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainAgentWorkforceStatus['summary']['external_effects_blocked_count']);
        $this->assertSame(0, $domainAgentWorkforceStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainAgentWorkforceStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainAgentWorkforceStatus['policy']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertFalse((bool) $domainAgentWorkforceStatus['policy']['offensive_security_allowed']);
        $this->assertSame(64, strlen((string) $domainAgentWorkforceStatus['domain_agent_workforce_runtime_status_hash']));

        $operationalDossierStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-dossier-runtime-status',
            '--json' => true,
        ]);
        $operationalDossierStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operationalDossierStatusExit);
        $this->assertIsArray($operationalDossierStatus);
        $this->assertTrue((bool) $operationalDossierStatus['ok']);
        $this->assertSame('complete_operational_dossier_runtime_coverage_external_blocked', $operationalDossierStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['completed_operational_dossier_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['dossier_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['evidence_spine_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['control_plane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['decision_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['promotion_path_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['scorecard_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operationalDossierStatus['summary']['external_blocked_count']);
        $this->assertSame(0, $operationalDossierStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $operationalDossierStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $operationalDossierStatus['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $operationalDossierStatus['policy']['real_world_autonomy_claim_allowed']);
        $this->assertSame(64, strlen((string) $operationalDossierStatus['operational_dossier_runtime_status_hash']));

        $businessRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-business-execution-runtime-status',
            '--json' => true,
        ]);
        $businessRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessRuntimeStatusExit);
        $this->assertIsArray($businessRuntimeStatus);
        $this->assertTrue((bool) $businessRuntimeStatus['ok']);
        $this->assertSame('complete_domain_business_execution_runtime_coverage_external_blocked', $businessRuntimeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['completed_business_execution_runtime_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['business_execution_cell_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['business_kpi_binding_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['business_service_lane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessRuntimeStatus['summary']['business_artifact_contract_bound_count']);
        $this->assertSame(0, $businessRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $businessRuntimeStatus['summary']['coverage_rate']);
        $this->assertSame(64, strlen((string) $businessRuntimeStatus['domain_business_execution_runtime_status_hash']));

        $operatingSpineStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-spine-runtime-status',
            '--json' => true,
        ]);
        $operatingSpineStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operatingSpineStatusExit);
        $this->assertIsArray($operatingSpineStatus);
        $this->assertTrue((bool) $operatingSpineStatus['ok']);
        $this->assertSame('complete_company_operating_spine_runtime_coverage_external_blocked', $operatingSpineStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['completed_operating_spine_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['customer_market_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['account_contract_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['vendor_legal_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['resilience_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['analytics_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['knowledge_memory_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $operatingSpineStatus['summary']['identity_sovereignty_runtime_bound_count']);
        $this->assertSame(0, $operatingSpineStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $operatingSpineStatus['summary']['coverage_rate']);
        $this->assertSame(64, strlen((string) $operatingSpineStatus['company_operating_spine_runtime_status_hash']));

        $commercialOperationsStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-commercial-operations-runtime-status',
            '--json' => true,
        ]);
        $commercialOperationsStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $commercialOperationsStatusExit);
        $this->assertIsArray($commercialOperationsStatus);
        $this->assertTrue((bool) $commercialOperationsStatus['ok']);
        $this->assertSame('complete_commercial_operations_runtime_coverage_external_customer_vendor_billing_blocked', $commercialOperationsStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['completed_commercial_operations_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['customer_market_operations_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['offer_packaging_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['customer_journey_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['customer_success_scorecard_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['account_contract_delivery_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['contract_entitlement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['onboarding_success_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['service_review_renewal_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['account_health_risk_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['billing_revenue_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['vendor_legal_procurement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['vendor_due_diligence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['source_terms_review_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['flow_procurement_routing_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commercialOperationsStatus['summary']['vendor_operability_bound_count']);
        $this->assertSame(0, $commercialOperationsStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $commercialOperationsStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $commercialOperationsStatus['policy']['external_billing_allowed']);
        $this->assertSame(64, strlen((string) $commercialOperationsStatus['commercial_operations_runtime_status_hash']));

        $domainProviderWorkbenchStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-provider-workbench-runtime-status',
            '--json' => true,
        ]);
        $domainProviderWorkbenchStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainProviderWorkbenchStatusExit);
        $this->assertIsArray($domainProviderWorkbenchStatus);
        $this->assertTrue((bool) $domainProviderWorkbenchStatus['ok']);
        $this->assertSame('complete_domain_provider_workbench_runtime_coverage_external_write_paid_blocked', $domainProviderWorkbenchStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['completed_provider_workbench_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['provider_contracts_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['connector_workbenches_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['flow_provider_route_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['provider_eval_cases_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['provider_data_product_lineage_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['provider_workbench_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainProviderWorkbenchStatus['summary']['provider_external_write_paid_blocked_count']);
        $this->assertSame(0, $domainProviderWorkbenchStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainProviderWorkbenchStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainProviderWorkbenchStatus['policy']['provider_write_or_paid_action_default']);
        $this->assertSame(64, strlen((string) $domainProviderWorkbenchStatus['domain_provider_workbench_runtime_status_hash']));

        $domainCompanyExecutionSuiteStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-company-execution-suite-runtime-status',
            '--json' => true,
        ]);
        $domainCompanyExecutionSuiteStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainCompanyExecutionSuiteStatusExit);
        $this->assertIsArray($domainCompanyExecutionSuiteStatus);
        $this->assertTrue((bool) $domainCompanyExecutionSuiteStatus['ok']);
        $this->assertSame('complete_domain_company_execution_suite_runtime_coverage_external_actions_blocked', $domainCompanyExecutionSuiteStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['completed_domain_company_execution_suite_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['suite_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['operating_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['connector_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['flow_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['risk_control_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['decision_room_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['replay_eval_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainCompanyExecutionSuiteStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $domainCompanyExecutionSuiteStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainCompanyExecutionSuiteStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainCompanyExecutionSuiteStatus['policy']['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $domainCompanyExecutionSuiteStatus['domain_company_execution_suite_runtime_status_hash']));

        $flowWorkProductDeliveryStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-work-product-delivery-runtime-status',
            '--json' => true,
        ]);
        $flowWorkProductDeliveryStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowWorkProductDeliveryStatusExit);
        $this->assertIsArray($flowWorkProductDeliveryStatus);
        $this->assertTrue((bool) $flowWorkProductDeliveryStatus['ok']);
        $this->assertSame('complete_flow_work_product_delivery_runtime_coverage_external_delivery_blocked', $flowWorkProductDeliveryStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['completed_flow_work_product_delivery_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['delivery_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['blueprint_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['acceptance_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['handoff_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['replay_check_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowWorkProductDeliveryStatus['summary']['external_delivery_blocked_count']);
        $this->assertSame(0, $flowWorkProductDeliveryStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $flowWorkProductDeliveryStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $flowWorkProductDeliveryStatus['policy']['external_delivery_allowed']);
        $this->assertSame(64, strlen((string) $flowWorkProductDeliveryStatus['flow_work_product_delivery_runtime_status_hash']));

        $domainDataConnectorStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-data-connector-operating-runtime-status',
            '--json' => true,
        ]);
        $domainDataConnectorStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainDataConnectorStatusExit);
        $this->assertIsArray($domainDataConnectorStatus);
        $this->assertTrue((bool) $domainDataConnectorStatus['ok']);
        $this->assertSame('complete_domain_data_connector_operating_runtime_coverage_external_mutations_blocked', $domainDataConnectorStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['completed_domain_data_connector_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['data_connector_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['data_product_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['permission_profile_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['flow_data_connector_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['fixture_eval_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['operating_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $domainDataConnectorStatus['summary']['external_mutations_blocked_count']);
        $this->assertSame(0, $domainDataConnectorStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $domainDataConnectorStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $domainDataConnectorStatus['policy']['write_tools_enabled']);
        $this->assertFalse((bool) $domainDataConnectorStatus['policy']['external_data_mutation_allowed']);
        $this->assertSame(64, strlen((string) $domainDataConnectorStatus['domain_data_connector_operating_runtime_status_hash']));

        $flowLiveReadConnectorProbeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-live-read-connector-probe-runtime-status',
            '--json' => true,
        ]);
        $flowLiveReadConnectorProbeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowLiveReadConnectorProbeStatusExit);
        $this->assertIsArray($flowLiveReadConnectorProbeStatus);
        $this->assertTrue((bool) $flowLiveReadConnectorProbeStatus['ok']);
        $this->assertSame('complete_flow_live_read_connector_probe_runtime_coverage_external_mutations_blocked', $flowLiveReadConnectorProbeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['completed_flow_live_read_connector_probe_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['probe_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['connector_profiles_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['probe_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['evidence_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['external_mutations_blocked_count']);
        $this->assertSame($run['summary']['flow_count'], $flowLiveReadConnectorProbeStatus['summary']['operator_scope_required_count']);
        $this->assertSame(0, $flowLiveReadConnectorProbeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $flowLiveReadConnectorProbeStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $flowLiveReadConnectorProbeStatus['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $flowLiveReadConnectorProbeStatus['policy']['live_read_allowed']);
        $this->assertFalse((bool) $flowLiveReadConnectorProbeStatus['policy']['write_tools_enabled']);
        $this->assertFalse((bool) $flowLiveReadConnectorProbeStatus['policy']['external_mutation_allowed']);
        $this->assertFalse((bool) $flowLiveReadConnectorProbeStatus['policy']['credential_material_in_packet_allowed']);
        $this->assertSame(64, strlen((string) $flowLiveReadConnectorProbeStatus['flow_live_read_connector_probe_runtime_status_hash']));

        $externalResearchAdoptionStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-research-adoption-runtime-status',
            '--json' => true,
        ]);
        $externalResearchAdoptionStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $externalResearchAdoptionStatusExit);
        $this->assertIsArray($externalResearchAdoptionStatus);
        $this->assertTrue((bool) $externalResearchAdoptionStatus['ok']);
        $this->assertSame('complete_external_research_adoption_runtime_coverage_external_effects_blocked', $externalResearchAdoptionStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['completed_external_research_adoption_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['source_basis_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['repository_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['flow_adoption_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['capability_map_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['connector_backlog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $externalResearchAdoptionStatus['summary']['external_effects_blocked_count']);
        $this->assertSame(0, $externalResearchAdoptionStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $externalResearchAdoptionStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $externalResearchAdoptionStatus['policy']['runtime_ingestion_without_source_review_allowed']);
        $this->assertFalse((bool) $externalResearchAdoptionStatus['policy']['repository_adoption_without_license_security_fixture_and_operator_review_allowed']);
        $this->assertSame(64, strlen((string) $externalResearchAdoptionStatus['external_research_adoption_runtime_status_hash']));

        $autonomyPromotionStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-autonomy-promotion-runtime-status',
            '--json' => true,
        ]);
        $autonomyPromotionStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $autonomyPromotionStatusExit);
        $this->assertIsArray($autonomyPromotionStatus);
        $this->assertTrue((bool) $autonomyPromotionStatus['ok']);
        $this->assertSame('complete_autonomy_promotion_runtime_coverage_limited_external_autonomy_blocked', $autonomyPromotionStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['completed_autonomy_promotion_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['autonomy_ladder_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['supervised_external_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['limited_external_autonomy_blocked_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['evidence_spine_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $autonomyPromotionStatus['summary']['rollback_reconciliation_bound_count']);
        $this->assertSame(0, $autonomyPromotionStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $autonomyPromotionStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $autonomyPromotionStatus['policy']['limited_external_autonomy_allowed']);
        $this->assertFalse((bool) $autonomyPromotionStatus['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $autonomyPromotionStatus['policy']['operator_mandate_required_for_limited_external_autonomy']);
        $this->assertSame(64, strlen((string) $autonomyPromotionStatus['autonomy_promotion_runtime_status_hash']));

        $flowBenchmarkReplayStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-benchmark-replay-runtime-status',
            '--json' => true,
        ]);
        $flowBenchmarkReplayStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowBenchmarkReplayStatusExit);
        $this->assertIsArray($flowBenchmarkReplayStatus);
        $this->assertTrue((bool) $flowBenchmarkReplayStatus['ok']);
        $this->assertSame('complete_flow_benchmark_replay_runtime_coverage_external_benchmark_blocked', $flowBenchmarkReplayStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['completed_benchmark_replay_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['offline_dataset_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['trace_grading_rubric_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['adversarial_regression_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['deterministic_state_assertion_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['replay_comparison_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['benchmark_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowBenchmarkReplayStatus['summary']['benchmark_promotion_synthetic_scores_blocked_count']);
        $this->assertSame(0, $flowBenchmarkReplayStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $flowBenchmarkReplayStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $flowBenchmarkReplayStatus['policy']['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $flowBenchmarkReplayStatus['policy']['promotion_without_replay_green_allowed']);
        $this->assertSame(64, strlen((string) $flowBenchmarkReplayStatus['flow_benchmark_replay_runtime_status_hash']));

        $connectorRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-certification-preflight-runtime-status',
            '--json' => true,
        ]);
        $connectorRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $connectorRuntimeStatusExit);
        $this->assertIsArray($connectorRuntimeStatus);
        $this->assertTrue((bool) $connectorRuntimeStatus['ok']);
        $this->assertSame('complete_connector_certification_preflight_runtime_coverage_external_cutover_blocked', $connectorRuntimeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['completed_connector_certification_preflight_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_adapter_contracts_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_auth_boundaries_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_sandbox_probes_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_contract_tests_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_data_lineage_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_replay_fixtures_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_slo_failure_modes_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['production_preflight_contracts_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['flow_cutover_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['production_readiness_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_certification_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['cutover_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $connectorRuntimeStatus['summary']['connector_preflight_external_cutover_blocked_count']);
        $this->assertSame(0, $connectorRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $connectorRuntimeStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $connectorRuntimeStatus['policy']['production_cutover_without_operator_signed_scope_allowed']);
        $this->assertFalse((bool) $connectorRuntimeStatus['policy']['write_or_paid_mode_allowed_by_default']);
        $this->assertSame(64, strlen((string) $connectorRuntimeStatus['connector_certification_preflight_runtime_status_hash']));

        $commandCenterRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-command-center-control-tower-runtime-status',
            '--json' => true,
        ]);
        $commandCenterRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $commandCenterRuntimeStatusExit);
        $this->assertIsArray($commandCenterRuntimeStatus);
        $this->assertTrue((bool) $commandCenterRuntimeStatus['ok']);
        $this->assertSame('complete_command_center_control_tower_runtime_coverage_external_actions_blocked', $commandCenterRuntimeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['completed_command_center_control_tower_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['control_tower_lane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['flow_command_card_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['incident_exception_desk_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['change_window_release_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['operator_console_views_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['command_center_cells_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['connector_panels_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['work_product_factory_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['command_center_kpis_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $commandCenterRuntimeStatus['summary']['command_center_external_action_blocked_count']);
        $this->assertSame(0, $commandCenterRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $commandCenterRuntimeStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $commandCenterRuntimeStatus['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $commandCenterRuntimeStatus['policy']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertSame(64, strlen((string) $commandCenterRuntimeStatus['command_center_control_tower_runtime_status_hash']));

        $dressRehearsalRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-dress-rehearsal-runtime-status',
            '--json' => true,
        ]);
        $dressRehearsalRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $dressRehearsalRuntimeStatusExit);
        $this->assertIsArray($dressRehearsalRuntimeStatus);
        $this->assertTrue((bool) $dressRehearsalRuntimeStatus['ok']);
        $this->assertSame('complete_operational_dress_rehearsal_runtime_coverage_external_mutation_blocked', $dressRehearsalRuntimeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['completed_operational_dress_rehearsal_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['rehearsal_runbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['live_read_probe_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['operator_acceptance_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['rollback_drill_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['promotion_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['dress_rehearsal_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $dressRehearsalRuntimeStatus['summary']['dress_rehearsal_external_mutation_blocked_count']);
        $this->assertSame(0, $dressRehearsalRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $dressRehearsalRuntimeStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $dressRehearsalRuntimeStatus['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $dressRehearsalRuntimeStatus['policy']['external_mutation_allowed_during_rehearsal']);
        $this->assertFalse((bool) $dressRehearsalRuntimeStatus['policy']['production_cutover_without_signed_acceptance_allowed']);
        $this->assertSame(64, strlen((string) $dressRehearsalRuntimeStatus['operational_dress_rehearsal_runtime_status_hash']));

        $semanticGraphStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-semantic-operating-graph-runtime-status',
            '--json' => true,
        ]);
        $semanticGraphStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $semanticGraphStatusExit);
        $this->assertIsArray($semanticGraphStatus);
        $this->assertTrue((bool) $semanticGraphStatus['ok']);
        $this->assertSame('complete_semantic_operating_graph_runtime_coverage_external_mutation_blocked', $semanticGraphStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['completed_semantic_graph_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_graph_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_node_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_flow_edge_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_operating_views_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_drift_rules_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_export_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_graph_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $semanticGraphStatus['summary']['semantic_graph_secret_export_blocked_count']);
        $this->assertSame(0, $semanticGraphStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $semanticGraphStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $semanticGraphStatus['policy']['external_graph_mutation_allowed']);
        $this->assertSame(64, strlen((string) $semanticGraphStatus['semantic_operating_graph_runtime_status_hash']));

        $companySystemModelStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-system-model-runtime-status',
            '--json' => true,
        ]);
        $companySystemModelStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $companySystemModelStatusExit);
        $this->assertIsArray($companySystemModelStatus);
        $this->assertTrue((bool) $companySystemModelStatus['ok']);
        $this->assertSame('complete_company_system_model_runtime_coverage_external_commitments_blocked', $companySystemModelStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['completed_company_system_model_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['domain_data_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['business_process_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['deliverable_quality_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['production_pack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['slo_sli_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['commercial_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $companySystemModelStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $companySystemModelStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $companySystemModelStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $companySystemModelStatus['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $companySystemModelStatus['policy']['commercial_external_billing_allowed']);
        $this->assertSame(64, strlen((string) $companySystemModelStatus['company_system_model_runtime_status_hash']));

        $internalOperationsBackboneStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-internal-operations-backbone-runtime-status',
            '--json' => true,
        ]);
        $internalOperationsBackboneStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $internalOperationsBackboneStatusExit);
        $this->assertIsArray($internalOperationsBackboneStatus);
        $this->assertTrue((bool) $internalOperationsBackboneStatus['ok']);
        $this->assertSame('complete_internal_operations_backbone_runtime_coverage_external_actions_blocked', $internalOperationsBackboneStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['completed_internal_operations_backbone_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['account_contract_delivery_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['vendor_legal_procurement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['resilience_continuity_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['analytics_decision_intelligence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['knowledge_memory_learning_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['identity_access_sovereignty_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['control_tower_run_operations_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['delivery_assurance_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['grc_control_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $internalOperationsBackboneStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $internalOperationsBackboneStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $internalOperationsBackboneStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $internalOperationsBackboneStatus['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $internalOperationsBackboneStatus['policy']['customer_vendor_memory_identity_delivery_external_actions_allowed']);
        $this->assertSame(64, strlen((string) $internalOperationsBackboneStatus['internal_operations_backbone_runtime_status_hash']));

        $activationRunOperationsStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-run-operations-runtime-status',
            '--json' => true,
        ]);
        $activationRunOperationsStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $activationRunOperationsStatusExit);
        $this->assertIsArray($activationRunOperationsStatus);
        $this->assertTrue((bool) $activationRunOperationsStatus['ok']);
        $this->assertSame('complete_activation_run_operations_runtime_coverage_external_actions_blocked', $activationRunOperationsStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['completed_activation_run_operations_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['integration_activation_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['activation_policy_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['source_activation_tracks_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['connector_activation_tracks_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['flow_activation_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['run_queue_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['live_read_probe_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['rehearsal_promotion_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $activationRunOperationsStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $activationRunOperationsStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $activationRunOperationsStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $activationRunOperationsStatus['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $activationRunOperationsStatus['policy']['calendar_wait_blocker_enabled']);
        $this->assertSame(64, strlen((string) $activationRunOperationsStatus['activation_run_operations_runtime_status_hash']));

        $flowExecutionFoundationStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-execution-foundation-runtime-status',
            '--json' => true,
        ]);
        $flowExecutionFoundationStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowExecutionFoundationStatusExit);
        $this->assertIsArray($flowExecutionFoundationStatus);
        $this->assertTrue((bool) $flowExecutionFoundationStatus['ok']);
        $this->assertSame('complete_flow_execution_foundation_runtime_coverage_external_actions_blocked', $flowExecutionFoundationStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['completed_flow_execution_foundation_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['runbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['executable_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['artifact_io_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['failure_injection_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['dry_run_command_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $flowExecutionFoundationStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $flowExecutionFoundationStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $flowExecutionFoundationStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $flowExecutionFoundationStatus['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $flowExecutionFoundationStatus['policy']['ungoverned_external_side_effects_allowed']);
        $this->assertSame(64, strlen((string) $flowExecutionFoundationStatus['flow_execution_foundation_runtime_status_hash']));

        $agentToolchainStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-agent-toolchain-runtime-status',
            '--json' => true,
        ]);
        $agentToolchainStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $agentToolchainStatusExit);
        $this->assertIsArray($agentToolchainStatus);
        $this->assertTrue((bool) $agentToolchainStatus['ok']);
        $this->assertSame('complete_agent_toolchain_runtime_coverage_external_blocked', $agentToolchainStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['completed_agent_toolchain_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['framework_source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['flow_toolkit_assignment_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['agent_repository_epic_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['guardrails_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['handoffs_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['tracing_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['durable_state_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $agentToolchainStatus['summary']['human_in_loop_runtime_bound_count']);
        $this->assertSame(0, $agentToolchainStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $agentToolchainStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $agentToolchainStatus['policy']['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $agentToolchainStatus['agent_toolchain_runtime_status_hash']));

        $workforceCapacityStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-workforce-capacity-runtime-status',
            '--json' => true,
        ]);
        $workforceCapacityStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $workforceCapacityStatusExit);
        $this->assertIsArray($workforceCapacityStatus);
        $this->assertTrue((bool) $workforceCapacityStatus['ok']);
        $this->assertSame('complete_workforce_capacity_runtime_coverage_external_staffing_changes_blocked', $workforceCapacityStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['completed_workforce_capacity_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['workforce_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['org_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['agent_capacity_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['flow_staffing_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['training_enablement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['succession_continuity_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['capacity_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $workforceCapacityStatus['summary']['external_changes_blocked_count']);
        $this->assertSame(0, $workforceCapacityStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $workforceCapacityStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $workforceCapacityStatus['policy']['single_agent_bottleneck_allowed']);
        $this->assertFalse((bool) $workforceCapacityStatus['policy']['external_unreviewed_staffing_change_allowed']);
        $this->assertSame(64, strlen((string) $workforceCapacityStatus['workforce_capacity_runtime_status_hash']));

        $portfolioDependencyStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-portfolio-dependency-runtime-status',
            '--json' => true,
        ]);
        $portfolioDependencyStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $portfolioDependencyStatusExit);
        $this->assertIsArray($portfolioDependencyStatus);
        $this->assertTrue((bool) $portfolioDependencyStatus['ok']);
        $this->assertSame('complete_portfolio_dependency_runtime_coverage_external_dependency_actions_blocked', $portfolioDependencyStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['completed_portfolio_dependency_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['dependency_stack_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['role_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['intake_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['upstream_map_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['integration_map_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['flow_routing_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['escalation_conflict_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['reporting_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $portfolioDependencyStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $portfolioDependencyStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $portfolioDependencyStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $portfolioDependencyStatus['policy']['external_spend_publish_write_trade_or_transfer_allowed']);
        $this->assertFalse((bool) $portfolioDependencyStatus['policy']['cross_company_dependency_without_typed_handoff_allowed']);
        $this->assertFalse((bool) $portfolioDependencyStatus['policy']['unresolved_conflict_external_side_effect_allowed']);
        $this->assertSame(64, strlen((string) $portfolioDependencyStatus['portfolio_dependency_runtime_status_hash']));

        $handoffRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-cross-company-handoff-runtime-status',
            '--json' => true,
        ]);
        $handoffRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $handoffRuntimeStatusExit);
        $this->assertIsArray($handoffRuntimeStatus);
        $this->assertTrue((bool) $handoffRuntimeStatus['ok']);
        $this->assertSame('cross_company_handoff_runtime_ready_external_blocked', $handoffRuntimeStatus['status']);
        $this->assertGreaterThan(0, $handoffRuntimeStatus['summary']['handoff_contract_count']);
        $this->assertSame($handoffRuntimeStatus['summary']['handoff_contract_count'], $handoffRuntimeStatus['summary']['ready_handoff_runtime_packet_count']);
        $this->assertSame($handoffRuntimeStatus['summary']['handoff_contract_count'], $handoffRuntimeStatus['summary']['source_runtime_ready_count']);
        $this->assertSame($handoffRuntimeStatus['summary']['handoff_contract_count'], $handoffRuntimeStatus['summary']['target_acceptance_bound_count']);
        $this->assertSame($handoffRuntimeStatus['summary']['handoff_contract_count'], $handoffRuntimeStatus['summary']['typed_context_bound_count']);
        $this->assertSame($handoffRuntimeStatus['summary']['handoff_contract_count'], $handoffRuntimeStatus['summary']['evidence_refs_bound_count']);
        $this->assertSame(0, $handoffRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $handoffRuntimeStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $handoffRuntimeStatus['policy']['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $handoffRuntimeStatus['cross_company_handoff_runtime_status_hash']));

        $customerAccountRevenueStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-customer-account-revenue-runtime-status',
            '--json' => true,
        ]);
        $customerAccountRevenueStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $customerAccountRevenueStatusExit);
        $this->assertIsArray($customerAccountRevenueStatus);
        $this->assertTrue((bool) $customerAccountRevenueStatus['ok']);
        $this->assertSame('complete_customer_account_revenue_runtime_coverage_external_revenue_blocked', $customerAccountRevenueStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['completed_customer_account_revenue_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['customer_market_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['offer_packaging_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['journey_lifecycle_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['customer_success_scorecard_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['commercial_service_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['business_kpi_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['account_contract_delivery_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['account_segment_playbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['contract_entitlement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['onboarding_success_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['service_review_renewal_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['account_health_risk_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['billing_revenue_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $customerAccountRevenueStatus['summary']['account_observability_bound_count']);
        $this->assertSame(0, $customerAccountRevenueStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $customerAccountRevenueStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $customerAccountRevenueStatus['policy']['external_billing_allowed']);
        $this->assertFalse((bool) $customerAccountRevenueStatus['policy']['revenue_claim_allowed']);
        $this->assertSame(64, strlen((string) $customerAccountRevenueStatus['customer_account_revenue_runtime_status_hash']));

        $productizedServiceStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-productized-service-runtime-status',
            '--json' => true,
        ]);
        $productizedServiceStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $productizedServiceStatusExit);
        $this->assertIsArray($productizedServiceStatus);
        $this->assertTrue((bool) $productizedServiceStatus['ok']);
        $this->assertSame('complete_productized_service_runtime_coverage_external_commitment_billing_blocked', $productizedServiceStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['completed_productized_service_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['service_offer_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['delivery_blueprint_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['intake_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['sla_success_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['pricing_packaging_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['gtm_motion_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['proof_template_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $productizedServiceStatus['summary']['external_commitment_billing_blocked_count']);
        $this->assertSame(0, $productizedServiceStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $productizedServiceStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $productizedServiceStatus['policy']['public_gtm_or_customer_commitment_allowed']);
        $this->assertFalse((bool) $productizedServiceStatus['policy']['external_billing_allowed']);
        $this->assertSame(64, strlen((string) $productizedServiceStatus['productized_service_runtime_status_hash']));

        $salesCrmStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-sales-crm-pipeline-runtime-status',
            '--json' => true,
        ]);
        $salesCrmStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $salesCrmStatusExit);
        $this->assertIsArray($salesCrmStatus);
        $this->assertTrue((bool) $salesCrmStatus['ok']);
        $this->assertSame('complete_sales_crm_pipeline_runtime_coverage_external_commitments_blocked', $salesCrmStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['completed_sales_crm_pipeline_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['crm_object_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['segment_play_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['opportunity_route_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['proposal_scope_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['mutual_action_plan_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['account_research_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['deal_room_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['pipeline_forecast_review_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['map_risk_review_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['renewal_expansion_signal_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['sales_delivery_handoff_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['pipeline_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $salesCrmStatus['summary']['external_commitments_blocked_count']);
        $this->assertSame(0, $salesCrmStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $salesCrmStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $salesCrmStatus['policy']['external_outreach_contract_signature_or_customer_commitment_allowed']);
        $this->assertFalse((bool) $salesCrmStatus['policy']['public_claim_or_paid_campaign_allowed']);
        $this->assertSame(64, strlen((string) $salesCrmStatus['sales_crm_pipeline_runtime_status_hash']));

        $supportStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-customer-support-service-desk-runtime-status',
            '--json' => true,
        ]);
        $supportStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $supportStatusExit);
        $this->assertIsArray($supportStatus);
        $this->assertTrue((bool) $supportStatus['ok']);
        $this->assertSame('complete_customer_support_service_desk_runtime_coverage_external_support_blocked', $supportStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['completed_customer_support_service_desk_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['service_desk_object_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['segment_playbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['support_lane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['ticket_sla_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['knowledge_base_template_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['escalation_incident_runbook_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['resolution_rca_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['case_resolution_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['customer_health_escalation_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['knowledge_quality_review_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['automation_deflection_test_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['feedback_learning_loop_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['support_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $supportStatus['summary']['external_customer_actions_blocked_count']);
        $this->assertSame(0, $supportStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $supportStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $supportStatus['policy']['external_customer_message_or_support_commitment_allowed']);
        $this->assertFalse((bool) $supportStatus['policy']['regulated_support_advice_allowed_without_review']);
        $this->assertSame(64, strlen((string) $supportStatus['customer_support_service_desk_runtime_status_hash']));

        $marketingStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-marketing-growth-engine-runtime-status',
            '--json' => true,
        ]);
        $marketingStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $marketingStatusExit);
        $this->assertIsArray($marketingStatus);
        $this->assertTrue((bool) $marketingStatus['ok']);
        $this->assertSame('complete_marketing_growth_engine_runtime_coverage_external_publish_blocked', $marketingStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['completed_marketing_growth_engine_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['growth_operating_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['audience_segment_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['campaign_blueprint_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['content_asset_factory_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['experiment_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['growth_intelligence_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['attribution_experiment_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['channel_budget_guardrail_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['public_claim_evidence_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['channel_distribution_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['brand_compliance_review_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['growth_crm_handoff_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['marketing_observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $marketingStatus['summary']['external_publish_actions_blocked_count']);
        $this->assertSame(0, $marketingStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $marketingStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $marketingStatus['policy']['external_publish_paid_campaign_or_outreach_allowed']);
        $this->assertFalse((bool) $marketingStatus['policy']['public_claim_allowed_without_source_and_operator_review']);
        $this->assertSame(64, strlen((string) $marketingStatus['marketing_growth_engine_runtime_status_hash']));

        $financeTreasuryStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-finance-treasury-billing-runtime-status',
            '--json' => true,
        ]);
        $financeTreasuryStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $financeTreasuryStatusExit);
        $this->assertIsArray($financeTreasuryStatus);
        $this->assertTrue((bool) $financeTreasuryStatus['ok']);
        $this->assertSame('complete_finance_treasury_billing_runtime_coverage_external_finance_blocked', $financeTreasuryStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['completed_finance_treasury_billing_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['source_catalog_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['financial_data_interface_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['provider_connector_matrix_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['cfo_operating_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['financial_research_workbench_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['budget_envelope_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['forecast_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['model_risk_control_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['investment_committee_packet_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['pnl_line_item_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['billing_ledger_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['treasury_risk_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['close_audit_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['observability_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $financeTreasuryStatus['summary']['external_financial_actions_blocked_count']);
        $this->assertSame(0, $financeTreasuryStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $financeTreasuryStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $financeTreasuryStatus['policy']['external_invoice_payment_collection_capital_transfer_or_trade_allowed']);
        $this->assertFalse((bool) $financeTreasuryStatus['policy']['real_revenue_cash_or_aum_claim_allowed']);
        $this->assertTrue((bool) $financeTreasuryStatus['policy']['source_linked_financial_claim_required']);
        $this->assertTrue((bool) $financeTreasuryStatus['policy']['model_risk_review_required_for_investment_or_capital_recommendation']);
        $this->assertTrue((bool) $financeTreasuryStatus['policy']['finance_treasury_runtime_requires_source_linked_data_interface_connectors_research_workbench_model_risk_and_committee_packets']);
        $this->assertSame(64, strlen((string) $financeTreasuryStatus['finance_treasury_billing_runtime_status_hash']));

        $governanceRiskStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-governance-risk-operations-runtime-status',
            '--json' => true,
        ]);
        $governanceRiskStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $governanceRiskStatusExit);
        $this->assertIsArray($governanceRiskStatus);
        $this->assertTrue((bool) $governanceRiskStatus['ok']);
        $this->assertSame('complete_governance_risk_operations_runtime_coverage_external_actions_blocked', $governanceRiskStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['completed_governance_risk_operations_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['vendor_procurement_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['resilience_continuity_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['analytics_decision_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['knowledge_learning_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['identity_sovereignty_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['grc_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $governanceRiskStatus['summary']['external_actions_blocked_count']);
        $this->assertSame(0, $governanceRiskStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $governanceRiskStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $governanceRiskStatus['policy']['vendor_purchase_contract_signature_secret_share_or_write_scope_allowed']);
        $this->assertFalse((bool) $governanceRiskStatus['policy']['incident_external_notification_without_operator_allowed']);
        $this->assertFalse((bool) $governanceRiskStatus['policy']['canonical_memory_write_without_review_allowed']);
        $this->assertFalse((bool) $governanceRiskStatus['policy']['secret_material_or_unscoped_memory_export_allowed']);
        $this->assertSame(64, strlen((string) $governanceRiskStatus['governance_risk_operations_runtime_status_hash']));

        $unitEconomicsStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-unit-economics-capacity-runtime-status',
            '--json' => true,
        ]);
        $unitEconomicsStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $unitEconomicsStatusExit);
        $this->assertIsArray($unitEconomicsStatus);
        $this->assertTrue((bool) $unitEconomicsStatus['ok']);
        $this->assertSame('complete_unit_economics_capacity_runtime_coverage_external_capital_blocked', $unitEconomicsStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['completed_unit_economics_capacity_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['flow_cost_center_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['flow_unit_economics_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['capacity_simulation_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['pricing_ladder_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['agent_capacity_cost_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $unitEconomicsStatus['summary']['connector_cost_limit_model_bound_count']);
        $this->assertSame(0, $unitEconomicsStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $unitEconomicsStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $unitEconomicsStatus['policy']['real_capital_action_allowed']);
        $this->assertSame(64, strlen((string) $unitEconomicsStatus['unit_economics_capacity_runtime_status_hash']));

        $businessOperatingPacketStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-business-operating-packet-runtime-status',
            '--json' => true,
        ]);
        $businessOperatingPacketStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessOperatingPacketStatusExit);
        $this->assertIsArray($businessOperatingPacketStatus);
        $this->assertTrue((bool) $businessOperatingPacketStatus['ok']);
        $this->assertSame('complete_business_operating_packet_runtime_coverage_external_commitments_blocked', $businessOperatingPacketStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['completed_business_operating_packet_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['business_model_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['kpi_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['delivery_lane_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['economics_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['account_operations_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $businessOperatingPacketStatus['summary']['external_commitments_blocked_count']);
        $this->assertSame(0, $businessOperatingPacketStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $businessOperatingPacketStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $businessOperatingPacketStatus['policy']['external_customer_commitment_allowed']);
        $this->assertFalse((bool) $businessOperatingPacketStatus['policy']['external_billing_allowed']);
        $this->assertFalse((bool) $businessOperatingPacketStatus['policy']['real_capital_action_allowed']);
        $this->assertSame(64, strlen((string) $businessOperatingPacketStatus['business_operating_packet_runtime_status_hash']));

        $deliveryRiskStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-delivery-risk-runtime-status',
            '--json' => true,
        ]);
        $deliveryRiskStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $deliveryRiskStatusExit);
        $this->assertIsArray($deliveryRiskStatus);
        $this->assertTrue((bool) $deliveryRiskStatus['ok']);
        $this->assertSame('complete_delivery_risk_runtime_coverage_external_claim_blocked', $deliveryRiskStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['completed_delivery_risk_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['delivery_assurance_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['delivery_sla_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['strategic_intelligence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['rival_alternative_map_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['grc_runtime_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['audit_evidence_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $deliveryRiskStatus['summary']['policy_exception_blocked_count']);
        $this->assertSame(0, $deliveryRiskStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $deliveryRiskStatus['summary']['coverage_rate']);
        $this->assertFalse((bool) $deliveryRiskStatus['policy']['customer_visible_claim_allowed']);
        $this->assertSame(64, strlen((string) $deliveryRiskStatus['delivery_risk_runtime_status_hash']));

        $outcomeRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-outcome-runtime-status',
            '--json' => true,
        ]);
        $outcomeRuntimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $outcomeRuntimeStatusExit);
        $this->assertIsArray($outcomeRuntimeStatus);
        $this->assertTrue((bool) $outcomeRuntimeStatus['ok']);
        $this->assertSame('complete_operational_outcome_runtime_coverage_external_blocked', $outcomeRuntimeStatus['status']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['expected_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['completed_operational_outcome_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['operational_outcome_ledger_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['value_proxy_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['acceptance_contract_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['risk_scorecard_bound_count']);
        $this->assertSame($run['summary']['flow_count'], $outcomeRuntimeStatus['summary']['next_cycle_bound_count']);
        $this->assertGreaterThanOrEqual($run['summary']['flow_count'] * 4, $outcomeRuntimeStatus['summary']['evidence_ref_count']);
        $this->assertSame(0, $outcomeRuntimeStatus['summary']['external_value_claim_count']);
        $this->assertSame(0, $outcomeRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $outcomeRuntimeStatus['summary']['coverage_rate']);
        $this->assertTrue((bool) $outcomeRuntimeStatus['policy']['operational_outcome_runtime_requires_ledger_value_proxy_acceptance_risk_next_cycle_and_evidence_refs_per_flow']);
        $this->assertSame(64, strlen((string) $outcomeRuntimeStatus['operational_outcome_runtime_status_hash']));

        $holdingScorecardExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-holding-outcome-scorecard-status',
            '--json' => true,
        ]);
        $holdingScorecard = json_decode(Artisan::output(), true);

        $this->assertSame(0, $holdingScorecardExit);
        $this->assertIsArray($holdingScorecard);
        $this->assertTrue((bool) $holdingScorecard['ok']);
        $this->assertSame('holding_outcome_scorecard_ready_external_claim_blocked', $holdingScorecard['status']);
        $this->assertSame(9, $holdingScorecard['summary']['ready_company_count']);
        $this->assertSame($run['summary']['flow_count'], $holdingScorecard['summary']['completed_outcome_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $holdingScorecard['summary']['operational_outcome_ledger_count']);
        $this->assertGreaterThanOrEqual($run['summary']['flow_count'], $holdingScorecard['summary']['measured_kpi_count']);
        $this->assertSame($run['summary']['flow_count'], $holdingScorecard['summary']['acceptance_contract_count']);
        $this->assertSame($run['summary']['flow_count'], $holdingScorecard['summary']['risk_scorecard_count']);
        $this->assertSame($run['summary']['flow_count'], $holdingScorecard['summary']['next_cycle_count']);
        $this->assertSame(10.0, (float) $holdingScorecard['summary']['average_score']);
        $this->assertSame(0, $holdingScorecard['summary']['external_value_claim_count']);
        $this->assertSame(0, $holdingScorecard['summary']['external_side_effect_count']);
        $this->assertSame(64, strlen((string) $holdingScorecard['holding_outcome_scorecard_status_hash']));

        $portfolioDecisionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-portfolio-decision-packet-status',
            '--json' => true,
        ]);
        $portfolioDecision = json_decode(Artisan::output(), true);

        $this->assertSame(0, $portfolioDecisionExit);
        $this->assertIsArray($portfolioDecision);
        $this->assertTrue((bool) $portfolioDecision['ok']);
        $this->assertSame('portfolio_decision_packet_ready_external_capital_blocked', $portfolioDecision['status']);
        $this->assertSame(9, $portfolioDecision['summary']['ready_decision_packet_count']);
        $this->assertSame(9, $portfolioDecision['summary']['scale_internal_supervised_capacity_count']);
        $this->assertSame(9, $portfolioDecision['summary']['blocked_real_capital_action_count']);
        $this->assertSame(0, $portfolioDecision['summary']['external_value_claim_count']);
        $this->assertSame(0, $portfolioDecision['summary']['external_side_effect_count']);
        $this->assertSame(64, strlen((string) $portfolioDecision['portfolio_decision_packet_status_hash']));

        $boardReviewExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-board-operating-review-status',
            '--json' => true,
        ]);
        $boardReview = json_decode(Artisan::output(), true);

        $this->assertSame(0, $boardReviewExit);
        $this->assertIsArray($boardReview);
        $this->assertTrue((bool) $boardReview['ok']);
        $this->assertSame('company_board_operating_reviews_ready_external_commitments_blocked', $boardReview['status']);
        $this->assertSame(9, $boardReview['summary']['ready_company_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['completed_outcome_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['business_operating_packet_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['command_center_flow_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['acceptance_contract_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['risk_remediation_count']);
        $this->assertSame($run['summary']['flow_count'], $boardReview['summary']['next_cycle_action_count']);
        $this->assertGreaterThanOrEqual(27, $boardReview['summary']['board_decision_action_count']);
        $this->assertSame(9, $boardReview['summary']['scale_internal_supervised_capacity_count']);
        $this->assertSame(0, $boardReview['summary']['external_commitment_allowed_count']);
        $this->assertSame(0, $boardReview['summary']['external_side_effect_count']);
        $this->assertFalse((bool) $boardReview['policy']['external_customer_commitment_allowed']);
        $this->assertFalse((bool) $boardReview['policy']['real_capital_action_allowed']);
        $this->assertSame('atlas.ai.company.board_operating_review_packet.v1', $boardReview['companies'][0]['schema']);
        $this->assertContains('business_operating_packets', $boardReview['companies'][0]['review_sections']);
        $this->assertContains('continuous_improvement_backlog', $boardReview['companies'][0]['review_sections']);
        $this->assertTrue((bool) $boardReview['companies'][0]['continuous_improvement_backlog']['bound_to_outcome_scorecard']);
        $this->assertTrue((bool) $boardReview['companies'][0]['board_review_gates']['continuous_improvement_backlog_ready']);
        $this->assertTrue((bool) $boardReview['companies'][0]['ready']);
        $this->assertSame(64, strlen((string) $boardReview['companies'][0]['board_operating_review_hash']));
        $this->assertSame(64, strlen((string) $boardReview['company_board_operating_review_status_hash']));
    }

    public function test_enterprise_consolidation_run_executes_full_internal_operating_chain_to_readiness(): void
    {
        $this->createDomainRuntimeTables();
        $this->migrateExternalActionMandates();
        AiDomainRuntimeRecord::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();
        AiHoldingConnectorActivationRecord::query()->delete();
        AiHoldingEnterpriseFlowRunQueueItem::query()->delete();
        AiHoldingEnterpriseFlowOperationsRunbook::query()->delete();

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-consolidation-run',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_consolidation_run.v1', $payload['schema']);
        $this->assertSame('consolidated_target_ready_external_blocked', $payload['status']);
        $this->assertSame($payload['summary']['step_count'], $payload['summary']['green_step_count']);
        $this->assertSame(1.0, (float) $payload['summary']['flow_action_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['vertical_solution_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_solution_playbook_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_operating_depth_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_agent_workforce_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['operational_dossier_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['autonomy_promotion_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_business_execution_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['company_operating_spine_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['commercial_operations_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_provider_workbench_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['external_research_adoption_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['flow_benchmark_replay_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['connector_certification_preflight_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['command_center_control_tower_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['operational_dress_rehearsal_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['semantic_operating_graph_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['company_system_model_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['internal_operations_backbone_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['activation_run_operations_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['flow_execution_foundation_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['agent_toolchain_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['workforce_capacity_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['portfolio_dependency_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['cross_company_handoff_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['customer_account_revenue_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['productized_service_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['sales_crm_pipeline_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['customer_support_service_desk_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['marketing_growth_engine_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['finance_treasury_billing_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['governance_risk_operations_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['unit_economics_capacity_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['business_operating_packet_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['delivery_risk_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['operational_outcome_runtime_coverage_rate']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['operational_outcome_value_proxy_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['operational_outcome_acceptance_contract_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['operational_outcome_risk_scorecard_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['operational_outcome_next_cycle_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['flow_action_runtime_run']['summary']['flow_count'] * 4, $payload['summary']['operational_outcome_evidence_ref_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['holding_outcome_acceptance_contract_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['holding_outcome_risk_scorecard_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['holding_outcome_next_cycle_count']);
        $this->assertSame(10.0, (float) $payload['summary']['holding_outcome_scorecard_average_score']);
        $this->assertSame(9, $payload['summary']['portfolio_decision_packet_ready_count']);
        $this->assertSame(9, $payload['summary']['company_board_operating_review_ready_count']);
        $this->assertSame(9, $payload['summary']['company_capability_runtime_mesh_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_capability_runtime_mesh_run_count']);
        $this->assertSame(9, $payload['summary']['company_supervised_connector_execution_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_supervised_connector_execution_run_count']);
        $this->assertSame(9, $payload['summary']['company_external_tool_activation_work_order_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_external_tool_activation_work_order_count']);
        $this->assertSame(9, $payload['summary']['company_external_tool_activation_packet_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_external_tool_activation_packet_count']);
        $this->assertSame(9, $payload['summary']['company_vertical_tool_operating_runtime_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_vertical_tool_operating_runtime_count']);
        $this->assertSame(9, $payload['summary']['company_business_execution_control_plane_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_business_execution_control_plane_count']);
        $this->assertSame(9, $payload['summary']['operating_packet_count']);
        $this->assertGreaterThanOrEqual(9.0, (float) $payload['summary']['readiness_score']);
        $this->assertSame(9, $payload['summary']['target_ready_companies']);
        $this->assertSame('agent_repository_adoption_ready_external_execution_blocked', $payload['steps']['agent_repository_adoption_status']['status']);
        $this->assertSame(9, $payload['steps']['agent_repository_adoption_status']['summary']['ready_company_count']);
        $this->assertSame('agent_repository_operating_catalog_ready_external_execution_blocked', $payload['steps']['agent_repository_operating_catalog_status']['status']);
        $this->assertSame(9, $payload['summary']['agent_repository_operating_catalog_ready_company_count']);
        $this->assertGreaterThanOrEqual(72, $payload['summary']['agent_repository_operating_catalog_framework_profile_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['agent_repository_operating_catalog_mcp_security_profile_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['agent_repository_operating_catalog_flow_map_count']);
        $this->assertFalse((bool) $payload['steps']['agent_repository_operating_catalog_status']['policy']['external_execution_allowed']);
        $this->assertSame('domain_data_fabric_ready_external_mutation_blocked', $payload['steps']['domain_data_fabric_status']['status']);
        $this->assertSame(9, $payload['summary']['domain_data_fabric_ready_company_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['domain_data_fabric_source_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_fabric_product_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_fabric_workbench_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_fabric_decision_packet_factory_count']);
        $this->assertFalse((bool) $payload['steps']['domain_data_fabric_status']['policy']['external_data_mutation_allowed']);
        $this->assertSame('domain_data_connector_operating_ready_external_mutation_blocked', $payload['steps']['domain_data_connector_operating_status']['status']);
        $this->assertSame(9, $payload['summary']['domain_data_connector_operating_ready_company_count']);
        $this->assertGreaterThanOrEqual(63, $payload['summary']['domain_data_connector_operating_source_data_room_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_connector_operating_product_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['domain_data_connector_operating_permission_profile_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_connector_operating_flow_contract_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_data_connector_operating_fixture_eval_count']);
        $this->assertFalse((bool) $payload['steps']['domain_data_connector_operating_status']['policy']['external_data_mutation_allowed']);
        $this->assertFalse((bool) $payload['steps']['domain_data_connector_operating_status']['policy']['write_tools_enabled']);
        $this->assertSame('flow_live_read_connector_probe_ready_external_mutation_blocked', $payload['steps']['flow_live_read_connector_probe_status']['status']);
        $this->assertSame(9, $payload['summary']['flow_live_read_connector_probe_ready_company_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['flow_live_read_connector_probe_profile_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_live_read_connector_probe_contract_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_live_read_connector_probe_evidence_matrix_count']);
        $this->assertSame(
            $payload['steps']['flow_action_runtime_run']['summary']['flow_count'],
            $payload['steps']['flow_live_read_connector_probe_runtime_status']['summary']['completed_flow_live_read_connector_probe_count'],
        );
        $this->assertTrue((bool) $payload['steps']['flow_live_read_connector_probe_status']['policy']['live_read_allowed']);
        $this->assertFalse((bool) $payload['steps']['flow_live_read_connector_probe_status']['policy']['external_mutation_allowed']);
        $this->assertFalse((bool) $payload['steps']['flow_live_read_connector_probe_status']['policy']['credential_material_in_packet_allowed']);
        $this->assertSame('external_research_adoption_ready_external_effects_blocked', $payload['steps']['external_research_adoption_status']['status']);
        $this->assertSame(9, $payload['summary']['external_research_adoption_ready_company_count']);
        $this->assertGreaterThanOrEqual(108, $payload['summary']['external_research_adoption_source_basis_count']);
        $this->assertGreaterThanOrEqual(72, $payload['summary']['external_research_adoption_framework_repository_count']);
        $this->assertGreaterThanOrEqual(27, $payload['summary']['external_research_adoption_domain_repository_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['external_research_adoption_flow_matrix_count']);
        $this->assertGreaterThanOrEqual(108, $payload['summary']['external_research_adoption_capability_map_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['external_research_adoption_connector_backlog_count']);
        $this->assertFalse((bool) $payload['steps']['external_research_adoption_status']['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['steps']['external_research_adoption_status']['policy']['runtime_ingestion_without_source_review_allowed']);
        $this->assertSame('flow_benchmark_replay_ready_external_benchmark_blocked', $payload['steps']['flow_benchmark_replay_status']['status']);
        $this->assertSame(9, $payload['summary']['flow_benchmark_replay_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_benchmark_replay_offline_dataset_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_benchmark_replay_trace_rubric_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_benchmark_replay_adversarial_case_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_benchmark_replay_state_assertion_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['flow_benchmark_replay_comparison_matrix_count']);
        $this->assertGreaterThanOrEqual(54, $payload['summary']['flow_benchmark_replay_observability_metric_count']);
        $this->assertFalse((bool) $payload['steps']['flow_benchmark_replay_status']['policy']['external_benchmark_execution_allowed']);
        $this->assertFalse((bool) $payload['steps']['flow_benchmark_replay_status']['policy']['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $payload['steps']['flow_benchmark_replay_status']['policy']['promotion_without_replay_green_allowed']);
        $this->assertSame('connector_certification_preflight_ready_external_cutover_blocked', $payload['steps']['connector_certification_preflight_status']['status']);
        $this->assertSame(9, $payload['summary']['connector_certification_preflight_ready_company_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_adapter_contract_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_auth_boundary_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_sandbox_probe_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_contract_test_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_data_lineage_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['connector_certification_preflight_flow_usage_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_production_contract_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['connector_certification_preflight_cutover_matrix_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['connector_certification_preflight_evidence_register_count']);
        $this->assertFalse((bool) $payload['steps']['connector_certification_preflight_status']['policy']['external_connector_cutover_allowed']);
        $this->assertFalse((bool) $payload['steps']['connector_certification_preflight_status']['policy']['write_or_paid_mode_allowed_by_default']);
        $this->assertFalse((bool) $payload['steps']['connector_certification_preflight_status']['policy']['real_credential_material_in_packet_allowed']);
        $this->assertSame('domain_agent_toolchains_certified_external_execution_blocked', $payload['steps']['domain_agent_toolchain_certification_status']['status']);
        $this->assertSame(9, $payload['steps']['domain_agent_toolchain_certification_status']['summary']['ready_company_count']);
        $this->assertSame(9, $payload['summary']['domain_agent_toolchain_certified_company_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['domain_agent_toolchain_certified_tool_contract_count']);
        $this->assertSame(
            $payload['steps']['domain_agent_toolchain_certification_status']['summary']['required_gate_count'],
            $payload['steps']['domain_agent_toolchain_certification_status']['summary']['ready_gate_count'],
        );
        $this->assertFalse((bool) $payload['steps']['domain_agent_toolchain_certification_status']['policy']['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['steps']['domain_agent_toolchain_certification_status']['domain_agent_toolchain_certification_status_hash']));
        $this->assertSame('industry_solution_ecosystem_ready_external_execution_blocked', $payload['steps']['industry_solution_ecosystem_status']['status']);
        $this->assertSame(9, $payload['steps']['industry_solution_ecosystem_status']['summary']['ready_company_count']);
        $this->assertSame('business_operating_backbone_ready_external_execution_blocked', $payload['steps']['business_operating_backbone_status']['status']);
        $this->assertSame(9, $payload['steps']['business_operating_backbone_status']['summary']['ready_company_count']);
        $this->assertSame(
            $payload['steps']['business_operating_backbone_status']['summary']['required_component_count'],
            $payload['steps']['business_operating_backbone_status']['summary']['ready_component_count'],
        );
        $this->assertSame('production_connector_preflight_ready_external_execution_blocked', $payload['steps']['production_connector_preflight_status']['status']);
        $this->assertSame(9, $payload['steps']['production_connector_preflight_status']['summary']['ready_company_count']);
        $this->assertSame('live_read_connector_readiness_ready_external_execution_blocked', $payload['steps']['live_read_connector_readiness_status']['status']);
        $this->assertSame(
            $payload['steps']['connector_activation_probe']['summary']['activated_internal_count'],
            $payload['steps']['live_read_connector_readiness_status']['summary']['live_read_ready_count'],
        );
        $this->assertSame('flow_quality_research_ready_external_execution_blocked', $payload['steps']['flow_quality_research_status']['status']);
        $this->assertSame(9, $payload['steps']['flow_quality_research_status']['summary']['ready_company_count']);
        $this->assertSame('vertical_solution_suite_ready_external_execution_blocked', $payload['steps']['vertical_solution_suite_status']['status']);
        $this->assertSame(9, $payload['steps']['vertical_solution_suite_status']['summary']['ready_company_count']);
        $this->assertSame('domain_business_execution_mesh_ready_external_execution_blocked', $payload['steps']['domain_business_execution_mesh_status']['status']);
        $this->assertSame(9, $payload['steps']['domain_business_execution_mesh_status']['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['vertical_solution_suite_status']['summary']['flow_solution_kit_count'], $payload['steps']['domain_business_execution_mesh_status']['summary']['execution_cell_count']);
        $this->assertSame('flow_operating_package_ready_external_execution_blocked', $payload['steps']['flow_operating_package_status']['status']);
        $this->assertSame(9, $payload['steps']['flow_operating_package_status']['summary']['ready_company_count']);
        $this->assertSame('enterprise_domain_workload_agent_templates_ready_external_effects_blocked', $payload['steps']['domain_workload_agent_template_status']['status']);
        $this->assertSame(
            $payload['steps']['domain_workload_agent_template_status']['summary']['template_count'],
            $payload['summary']['domain_workload_agent_template_ready_count'],
        );
        $this->assertSame('enterprise_company_domain_solution_packs_ready_external_effects_blocked', $payload['steps']['company_domain_solution_pack_status']['status']);
        $this->assertSame(9, $payload['summary']['domain_solution_pack_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['domain_solution_pack_ready_flow_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['domain_solution_pack_source_count']);
        $this->assertSame(9, $payload['summary']['agent_operations_pack_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['agent_operations_pack_ready_flow_count']);
        $this->assertGreaterThanOrEqual(700, $payload['summary']['agent_operations_skill_count']);
        $this->assertGreaterThanOrEqual(170, $payload['summary']['agent_operations_subagent_count']);
        $this->assertFalse((bool) $payload['steps']['company_domain_solution_pack_status']['policy']['external_execution_allowed']);
        $this->assertSame('enterprise_company_agent_operations_packs_ready_external_effects_blocked', $payload['steps']['company_agent_operations_pack_status']['status']);
        $this->assertFalse((bool) $payload['steps']['company_agent_operations_pack_status']['policy']['external_execution_allowed']);
        $this->assertSame('enterprise_company_agent_workforce_runtime_registered_external_effects_blocked', $payload['steps']['company_agent_workforce_runtime_register']['status']);
        $this->assertSame('enterprise_company_agent_workforce_runtime_ready_external_effects_blocked', $payload['steps']['company_agent_workforce_runtime_status']['status']);
        $this->assertSame(9, $payload['summary']['agent_workforce_runtime_ready_company_count']);
        $this->assertGreaterThanOrEqual(295, $payload['summary']['agent_workforce_runtime_ready_count']);
        $this->assertSame($payload['summary']['agent_workforce_runtime_ready_count'], $payload['summary']['agent_workforce_runtime_count']);
        $this->assertFalse((bool) $payload['steps']['company_agent_workforce_runtime_status']['policy']['external_execution_allowed']);
        $this->assertSame('enterprise_company_vertical_tool_operating_runtime_registered_external_effects_blocked', $payload['steps']['company_vertical_tool_operating_runtime_register']['status']);
        $this->assertSame('enterprise_company_vertical_tool_operating_runtime_ready_external_effects_blocked', $payload['steps']['company_vertical_tool_operating_runtime_status']['status']);
        $this->assertSame(9, $payload['summary']['company_vertical_tool_operating_runtime_ready_count']);
        $this->assertGreaterThanOrEqual(708, $payload['summary']['company_vertical_tool_operating_runtime_count']);
        $this->assertFalse((bool) $payload['steps']['company_vertical_tool_operating_runtime_status']['policy']['external_execution_allowed']);
        $this->assertSame('enterprise_company_domain_operating_models_certified_external_effects_blocked', $payload['steps']['company_domain_operating_model_certification_status']['status']);
        $this->assertSame(9, $payload['summary']['company_domain_operating_model_certified_count']);
        $this->assertSame('enterprise_company_operational_execution_loops_ready_external_effects_blocked', $payload['steps']['company_operational_execution_loop_status']['status']);
        $this->assertSame(9, $payload['summary']['company_operational_execution_loop_ready_count']);
        $this->assertSame('enterprise_company_work_product_acceptance_evidence_ready_external_delivery_blocked', $payload['steps']['company_work_product_acceptance_evidence_status']['status']);
        $this->assertSame(9, $payload['summary']['company_work_product_acceptance_ready_count']);
        $this->assertSame('enterprise_company_commercial_service_catalog_ready_external_revenue_blocked', $payload['steps']['company_commercial_service_catalog_status']['status']);
        $this->assertSame(9, $payload['summary']['commercial_service_catalog_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['commercial_service_offer_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['commercial_pricing_package_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['commercial_sla_success_contract_count']);
        $this->assertFalse((bool) $payload['steps']['company_commercial_service_catalog_status']['policy']['external_revenue_claim_allowed']);
        $this->assertSame('enterprise_company_revenue_delivery_operating_mesh_ready_external_revenue_blocked', $payload['steps']['company_revenue_delivery_operating_mesh_status']['status']);
        $this->assertSame(9, $payload['summary']['revenue_delivery_mesh_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['revenue_delivery_mesh_ready_flow_count']);
        $this->assertGreaterThanOrEqual(72, $payload['summary']['revenue_delivery_mesh_operating_system_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['revenue_delivery_mesh_connector_map_count']);
        $this->assertFalse((bool) $payload['steps']['company_revenue_delivery_operating_mesh_status']['policy']['external_billing_allowed']);
        $this->assertFalse((bool) $payload['steps']['company_revenue_delivery_operating_mesh_status']['policy']['external_revenue_claim_allowed']);
        $this->assertSame('enterprise_company_org_operating_models_ready_external_corporate_actions_blocked', $payload['steps']['company_org_operating_model_status']['status']);
        $this->assertSame(9, $payload['summary']['org_operating_model_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['org_flow_staffing_count']);
        $this->assertGreaterThanOrEqual(45, $payload['summary']['org_vendor_due_diligence_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['org_audit_evidence_requirement_count']);
        $this->assertFalse((bool) $payload['steps']['company_org_operating_model_status']['policy']['external_procurement_allowed']);
        $this->assertSame('enterprise_company_customer_delivery_lifecycles_ready_external_customer_actions_blocked', $payload['steps']['company_customer_delivery_lifecycle_status']['status']);
        $this->assertSame(9, $payload['summary']['customer_delivery_lifecycle_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['customer_delivery_ready_flow_count']);
        $this->assertGreaterThanOrEqual($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['customer_delivery_account_health_risk_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['customer_delivery_billing_control_count']);
        $this->assertFalse((bool) $payload['steps']['company_customer_delivery_lifecycle_status']['policy']['external_customer_commitment_allowed']);
        $this->assertSame('enterprise_company_quality_compliance_lifecycles_ready_external_quality_claims_blocked', $payload['steps']['company_quality_compliance_lifecycle_status']['status']);
        $this->assertSame(9, $payload['summary']['quality_compliance_lifecycle_ready_company_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['quality_ready_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['quality_replay_matrix_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['summary']['quality_audit_evidence_requirement_count']);
        $this->assertFalse((bool) $payload['steps']['company_quality_compliance_lifecycle_status']['policy']['external_benchmark_claim_allowed']);
        $this->assertSame(64, strlen((string) $payload['steps']['company_domain_solution_pack_status']['enterprise_company_domain_solution_pack_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_agent_operations_pack_status']['enterprise_company_agent_operations_pack_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_agent_workforce_runtime_register']['enterprise_company_agent_workforce_runtime_register_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_agent_workforce_runtime_status']['enterprise_company_agent_workforce_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_vertical_tool_operating_runtime_register']['enterprise_company_vertical_tool_operating_runtime_register_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_vertical_tool_operating_runtime_status']['enterprise_company_vertical_tool_operating_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['agent_repository_operating_catalog_status']['agent_repository_operating_catalog_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['domain_data_fabric_status']['domain_data_fabric_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['domain_data_connector_operating_status']['domain_data_connector_operating_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_live_read_connector_probe_status']['flow_live_read_connector_probe_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['external_research_adoption_status']['external_research_adoption_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_benchmark_replay_status']['flow_benchmark_replay_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['connector_certification_preflight_status']['connector_certification_preflight_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_domain_operating_model_certification_status']['enterprise_company_domain_operating_model_certification_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_operational_execution_loop_status']['enterprise_company_operational_execution_loop_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_work_product_acceptance_evidence_status']['enterprise_company_work_product_acceptance_evidence_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_commercial_service_catalog_status']['enterprise_company_commercial_service_catalog_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_revenue_delivery_operating_mesh_status']['enterprise_company_revenue_delivery_operating_mesh_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_org_operating_model_status']['enterprise_company_org_operating_model_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_customer_delivery_lifecycle_status']['enterprise_company_customer_delivery_lifecycle_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_quality_compliance_lifecycle_status']['enterprise_company_quality_compliance_lifecycle_status_hash']));
        $this->assertSame('flow_queue_execution_cycle_completed_external_execution_blocked', $payload['steps']['flow_run_queue_execute']['status']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_execute']['summary']['flow_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['flow_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['durable_execution_envelope_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['checkpoint_resume_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['human_in_loop_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['trace_receipt_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['surface_binding_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['fixture_smoke_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['execution_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_run_queue_status']['summary']['last_execution_receipt_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_durable_envelope_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_checkpoint_resume_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_human_in_loop_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_trace_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_surface_binding_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_fixture_smoke_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_execution_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_run_queue_last_execution_receipt_count']);
        $this->assertSame('flow_operations_runbooks_external_execution_blocked', $payload['steps']['flow_operations_runbook_status']['status']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['operations_green_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['drill_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['last_drill_receipt_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['slo_contract_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['incident_route_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['reconciliation_contract_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['steps']['flow_operations_runbook_status']['summary']['dashboard_binding_bound_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_green_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_drill_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_last_drill_receipt_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_slo_contract_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_incident_route_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_reconciliation_contract_count']);
        $this->assertSame($payload['steps']['flow_run_queue_register']['summary']['flow_count'], $payload['summary']['flow_operations_runbook_dashboard_binding_count']);
        $this->assertSame(64, strlen((string) $payload['steps']['flow_run_queue_register']['flow_run_queue_registry_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_run_queue_execute']['flow_run_queue_execution_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_run_queue_status']['flow_run_queue_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_operations_runbook_register']['flow_operations_runbook_registry_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_operations_runbook_drill']['flow_operations_runbook_drill_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['flow_operations_runbook_status']['flow_operations_runbook_status_hash']));
        $this->assertSame('complete_business_operating_packet_runtime_coverage_external_commitments_blocked', $payload['steps']['business_operating_packet_runtime_status']['status']);
        $this->assertSame(
            $payload['steps']['business_operating_packet_runtime_status']['summary']['expected_flow_count'],
            $payload['steps']['business_operating_packet_runtime_status']['summary']['completed_business_operating_packet_flow_count'],
        );
        $this->assertSame('company_board_operating_reviews_ready_external_commitments_blocked', $payload['steps']['company_board_operating_review_status']['status']);
        $this->assertSame(9, $payload['steps']['company_board_operating_review_status']['summary']['ready_company_count']);
        $this->assertSame('company_command_center_ready_external_execution_blocked', $payload['steps']['company_command_center_status']['status']);
        $this->assertSame(9, $payload['steps']['company_command_center_status']['summary']['ready_company_count']);
        $this->assertSame('complete_vertical_solution_runtime_coverage_external_blocked', $payload['steps']['vertical_solution_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['vertical_solution_runtime_status']['summary']['completed_vertical_runtime_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['vertical_solution_runtime_status']['summary']['domain_execution_brief_bound_count']);
        $this->assertSame('complete_domain_solution_playbook_runtime_coverage_external_mutation_blocked', $payload['steps']['domain_solution_playbook_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_solution_playbook_runtime_status']['summary']['completed_domain_solution_playbook_flow_count']);
        $this->assertSame('complete_domain_operating_depth_runtime_coverage_external_effects_blocked', $payload['steps']['domain_operating_depth_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_operating_depth_runtime_status']['summary']['completed_domain_operating_depth_flow_count']);
        $this->assertSame('complete_operational_dossier_runtime_coverage_external_blocked', $payload['steps']['operational_dossier_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['operational_dossier_runtime_status']['summary']['completed_operational_dossier_flow_count']);
        $this->assertSame('complete_autonomy_promotion_runtime_coverage_limited_external_autonomy_blocked', $payload['steps']['autonomy_promotion_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['autonomy_promotion_runtime_status']['summary']['completed_autonomy_promotion_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['autonomy_promotion_runtime_status']['summary']['limited_external_autonomy_blocked_count']);
        $this->assertSame('complete_domain_business_execution_runtime_coverage_external_blocked', $payload['steps']['domain_business_execution_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_business_execution_runtime_status']['summary']['completed_business_execution_runtime_flow_count']);
        $this->assertSame('complete_company_operating_spine_runtime_coverage_external_blocked', $payload['steps']['company_operating_spine_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['company_operating_spine_runtime_status']['summary']['completed_operating_spine_flow_count']);
        $this->assertSame('complete_commercial_operations_runtime_coverage_external_customer_vendor_billing_blocked', $payload['steps']['commercial_operations_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['commercial_operations_runtime_status']['summary']['completed_commercial_operations_flow_count']);
        $this->assertSame('complete_domain_provider_workbench_runtime_coverage_external_write_paid_blocked', $payload['steps']['domain_provider_workbench_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_provider_workbench_runtime_status']['summary']['completed_provider_workbench_flow_count']);
        $this->assertSame('complete_external_research_adoption_runtime_coverage_external_effects_blocked', $payload['steps']['external_research_adoption_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['external_research_adoption_runtime_status']['summary']['completed_external_research_adoption_flow_count']);
        $this->assertSame('complete_flow_benchmark_replay_runtime_coverage_external_benchmark_blocked', $payload['steps']['flow_benchmark_replay_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['flow_benchmark_replay_runtime_status']['summary']['completed_benchmark_replay_flow_count']);
        $this->assertSame('complete_connector_certification_preflight_runtime_coverage_external_cutover_blocked', $payload['steps']['connector_certification_preflight_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['connector_certification_preflight_runtime_status']['summary']['completed_connector_certification_preflight_flow_count']);
        $this->assertSame('complete_command_center_control_tower_runtime_coverage_external_actions_blocked', $payload['steps']['command_center_control_tower_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['command_center_control_tower_runtime_status']['summary']['completed_command_center_control_tower_flow_count']);
        $this->assertSame('complete_operational_dress_rehearsal_runtime_coverage_external_mutation_blocked', $payload['steps']['operational_dress_rehearsal_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['operational_dress_rehearsal_runtime_status']['summary']['completed_operational_dress_rehearsal_flow_count']);
        $this->assertSame('complete_semantic_operating_graph_runtime_coverage_external_mutation_blocked', $payload['steps']['semantic_operating_graph_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['semantic_operating_graph_runtime_status']['summary']['completed_semantic_graph_flow_count']);
        $this->assertSame('complete_company_system_model_runtime_coverage_external_commitments_blocked', $payload['steps']['company_system_model_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['company_system_model_runtime_status']['summary']['completed_company_system_model_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['company_system_model_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('complete_internal_operations_backbone_runtime_coverage_external_actions_blocked', $payload['steps']['internal_operations_backbone_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['internal_operations_backbone_runtime_status']['summary']['completed_internal_operations_backbone_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['internal_operations_backbone_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('complete_activation_run_operations_runtime_coverage_external_actions_blocked', $payload['steps']['activation_run_operations_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['activation_run_operations_runtime_status']['summary']['completed_activation_run_operations_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['activation_run_operations_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('complete_flow_execution_foundation_runtime_coverage_external_actions_blocked', $payload['steps']['flow_execution_foundation_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['flow_execution_foundation_runtime_status']['summary']['completed_flow_execution_foundation_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['flow_execution_foundation_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('complete_agent_toolchain_runtime_coverage_external_blocked', $payload['steps']['agent_toolchain_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['agent_toolchain_runtime_status']['summary']['completed_agent_toolchain_flow_count']);
        $this->assertSame('complete_workforce_capacity_runtime_coverage_external_staffing_changes_blocked', $payload['steps']['workforce_capacity_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['workforce_capacity_runtime_status']['summary']['completed_workforce_capacity_flow_count']);
        $this->assertSame('complete_portfolio_dependency_runtime_coverage_external_dependency_actions_blocked', $payload['steps']['portfolio_dependency_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['portfolio_dependency_runtime_status']['summary']['completed_portfolio_dependency_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['portfolio_dependency_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('cross_company_handoff_runtime_ready_external_blocked', $payload['steps']['cross_company_handoff_runtime_status']['status']);
        $this->assertSame(
            $payload['steps']['cross_company_handoff_runtime_status']['summary']['handoff_contract_count'],
            $payload['steps']['cross_company_handoff_runtime_status']['summary']['ready_handoff_runtime_packet_count'],
        );
        $this->assertSame('complete_customer_account_revenue_runtime_coverage_external_revenue_blocked', $payload['steps']['customer_account_revenue_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['customer_account_revenue_runtime_status']['summary']['completed_customer_account_revenue_flow_count']);
        $this->assertSame('complete_productized_service_runtime_coverage_external_commitment_billing_blocked', $payload['steps']['productized_service_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['productized_service_runtime_status']['summary']['completed_productized_service_flow_count']);
        $this->assertSame('complete_sales_crm_pipeline_runtime_coverage_external_commitments_blocked', $payload['steps']['sales_crm_pipeline_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['sales_crm_pipeline_runtime_status']['summary']['completed_sales_crm_pipeline_flow_count']);
        $this->assertSame('complete_customer_support_service_desk_runtime_coverage_external_support_blocked', $payload['steps']['customer_support_service_desk_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['customer_support_service_desk_runtime_status']['summary']['completed_customer_support_service_desk_flow_count']);
        $this->assertSame('complete_marketing_growth_engine_runtime_coverage_external_publish_blocked', $payload['steps']['marketing_growth_engine_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['marketing_growth_engine_runtime_status']['summary']['completed_marketing_growth_engine_flow_count']);
        $this->assertSame('complete_finance_treasury_billing_runtime_coverage_external_finance_blocked', $payload['steps']['finance_treasury_billing_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['finance_treasury_billing_runtime_status']['summary']['completed_finance_treasury_billing_flow_count']);
        $this->assertSame('complete_governance_risk_operations_runtime_coverage_external_actions_blocked', $payload['steps']['governance_risk_operations_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['governance_risk_operations_runtime_status']['summary']['completed_governance_risk_operations_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['governance_risk_operations_runtime_status']['summary']['external_actions_blocked_count']);
        $this->assertSame('complete_unit_economics_capacity_runtime_coverage_external_capital_blocked', $payload['steps']['unit_economics_capacity_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['unit_economics_capacity_runtime_status']['summary']['completed_unit_economics_capacity_flow_count']);
        $this->assertSame('complete_delivery_risk_runtime_coverage_external_claim_blocked', $payload['steps']['delivery_risk_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['delivery_risk_runtime_status']['summary']['completed_delivery_risk_flow_count']);
        $this->assertSame('complete_operational_outcome_runtime_coverage_external_blocked', $payload['steps']['operational_outcome_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['operational_outcome_runtime_status']['summary']['completed_operational_outcome_flow_count']);
        $this->assertSame('holding_outcome_scorecard_ready_external_claim_blocked', $payload['steps']['holding_outcome_scorecard_status']['status']);
        $this->assertSame(9, $payload['steps']['holding_outcome_scorecard_status']['summary']['ready_company_count']);
        $this->assertSame('portfolio_decision_packet_ready_external_capital_blocked', $payload['steps']['portfolio_decision_packet_status']['status']);
        $this->assertSame(9, $payload['steps']['portfolio_decision_packet_status']['summary']['ready_decision_packet_count']);
        $this->assertSame('supervised_external_execution_packets_ready_external_worker_disabled', $payload['steps']['supervised_external_execution_packet_status']['status']);
        $this->assertSame($payload['steps']['real_external_execution_handoff_pack']['summary']['flow_count'], $payload['steps']['supervised_external_execution_packet_status']['summary']['packet_ready_count']);
        $this->assertSame('external_worker_preflight_ready_execution_disabled', $payload['steps']['external_worker_preflight_status']['status']);
        $this->assertSame($payload['steps']['supervised_external_execution_packet_status']['summary']['flow_count'], $payload['steps']['external_worker_preflight_status']['summary']['worker_preflight_ready_count']);
        $this->assertSame($payload['steps']['supervised_external_execution_packet_status']['summary']['flow_count'], $payload['summary']['external_worker_preflight_ready_count']);
        $this->assertSame($payload['steps']['supervised_external_execution_packet_status']['summary']['flow_count'], $payload['steps']['external_worker_preflight_status']['summary']['external_worker_dispatch_disabled_count']);
        $this->assertSame('external_worker_dispatch_plan_ready_supervised_launch_disabled', $payload['steps']['external_worker_dispatch_plan_status']['status']);
        $this->assertSame($payload['steps']['external_worker_preflight_status']['summary']['flow_count'], $payload['steps']['external_worker_dispatch_plan_status']['summary']['dispatch_plan_ready_count']);
        $this->assertSame($payload['steps']['external_worker_preflight_status']['summary']['flow_count'], $payload['summary']['external_worker_dispatch_plan_ready_count']);
        $this->assertSame($payload['steps']['external_worker_preflight_status']['summary']['flow_count'], $payload['steps']['external_worker_dispatch_plan_status']['summary']['dispatch_disabled_count']);
        $this->assertSame('external_launch_control_ready_launch_disabled', $payload['steps']['external_launch_control_status']['status']);
        $this->assertSame($payload['steps']['external_worker_dispatch_plan_status']['summary']['flow_count'], $payload['steps']['external_launch_control_status']['summary']['launch_control_ready_count']);
        $this->assertSame($payload['steps']['external_worker_dispatch_plan_status']['summary']['flow_count'], $payload['summary']['external_launch_control_ready_count']);
        $this->assertSame($payload['steps']['external_worker_dispatch_plan_status']['summary']['flow_count'], $payload['steps']['external_launch_control_status']['summary']['launch_disabled_count']);
        $this->assertSame('external_receipt_binding_ready_launch_disabled', $payload['steps']['external_receipt_binding_status']['status']);
        $this->assertSame($payload['steps']['external_launch_control_status']['summary']['flow_count'], $payload['steps']['external_receipt_binding_status']['summary']['receipt_binder_ready_count']);
        $this->assertSame($payload['steps']['external_launch_control_status']['summary']['flow_count'], $payload['summary']['external_receipt_binder_ready_count']);
        $this->assertGreaterThan(0, $payload['summary']['external_receipt_missing_count']);
        $this->assertSame('external_supervised_cutover_dossier_ready_cutover_blocked', $payload['steps']['external_supervised_cutover_dossier_status']['status']);
        $this->assertSame($payload['steps']['external_receipt_binding_status']['summary']['flow_count'], $payload['steps']['external_supervised_cutover_dossier_status']['summary']['cutover_dossier_ready_count']);
        $this->assertSame($payload['steps']['external_receipt_binding_status']['summary']['flow_count'], $payload['summary']['external_supervised_cutover_dossier_ready_count']);
        $this->assertSame(0, $payload['summary']['external_supervised_cutover_enabled_count']);
        $this->assertSame('external_supervised_cutover_work_orders_ready_launch_blocked', $payload['steps']['external_supervised_cutover_work_order_status']['status']);
        $this->assertSame($payload['steps']['external_supervised_cutover_dossier_status']['summary']['flow_count'], $payload['steps']['external_supervised_cutover_work_order_status']['summary']['work_order_ready_count']);
        $this->assertSame($payload['steps']['external_supervised_cutover_dossier_status']['summary']['flow_count'], $payload['summary']['external_supervised_cutover_work_order_ready_count']);
        $this->assertGreaterThan($payload['steps']['external_supervised_cutover_work_order_status']['summary']['flow_count'], $payload['summary']['external_supervised_cutover_work_item_count']);
        $this->assertSame('external_supervised_cutover_portfolio_evidence_bundle_applied_all_companies_reconciled_external_autonomy_still_blocked', $payload['steps']['external_supervised_cutover_portfolio_evidence_bundle_apply']['status']);
        $this->assertSame(9, $payload['summary']['external_supervised_cutover_portfolio_bundle_complete_count']);
        $this->assertSame($payload['steps']['external_supervised_cutover_portfolio_evidence_bundle_apply']['summary']['expected_flow_count'], $payload['summary']['external_supervised_cutover_portfolio_bundle_closeout_count']);
        $this->assertSame(64, strlen((string) $payload['steps']['external_supervised_cutover_portfolio_evidence_bundle_apply']['external_supervised_cutover_portfolio_evidence_bundle_hash']));
        $this->assertSame('enterprise_company_production_readiness_certified_external_launch_still_blocked', $payload['steps']['company_production_readiness_certification_status']['status']);
        $this->assertSame(9, $payload['summary']['company_production_readiness_certified_count']);
        $this->assertSame(9, $payload['summary']['company_operating_evidence_bundle_ready_count']);
        $this->assertSame(9, $payload['summary']['company_operating_evidence_outcome_ready_count']);
        $this->assertSame(9, $payload['summary']['company_operating_evidence_scorecard_ready_count']);
        $this->assertSame('enterprise_company_operating_evidence_bundles_ready_external_launch_blocked', $payload['steps']['company_operating_evidence_bundle_status']['status']);
        $this->assertSame(64, strlen((string) $payload['steps']['company_production_readiness_certification_status']['enterprise_company_production_readiness_certification_status_hash']));
        $this->assertSame(64, strlen((string) $payload['steps']['company_operating_evidence_bundle_status']['enterprise_company_operating_evidence_bundle_status_hash']));
        $this->assertSame('target_met', $payload['steps']['readiness']['status']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $payload['consolidation_hash']));
    }

    public function test_enterprise_fixture_suite_runs_all_company_flows(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-fixture-suite',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_fixture_suite.v1', $payload['schema']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(54, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['passed_flow_count']);
        $this->assertSame(1.0, (float) $payload['summary']['pass_rate']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_flow_fixture_suite.v1', $company['schema']);
            $this->assertSame('passed', $company['status']);
            $this->assertTrue((bool) $company['promotion_decision']['fixture_suite_green']);
            $this->assertTrue((bool) $company['promotion_decision']['shadow_mode_candidate']);
            $this->assertFalse((bool) $company['promotion_decision']['external_autonomy_claim_allowed']);
            $this->assertSame(1.0, (float) $company['summary']['receipt_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['state_schema_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['event_plan_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['operator_checkpoint_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['operating_package_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['operating_package_attestation_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['vertical_solution_kit_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['vertical_solution_attestation_coverage']);
            $this->assertSame(64, strlen((string) $company['suite_hash']));
        }
    }

    public function test_enterprise_fixture_suite_can_run_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-fixture-suite',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertGreaterThanOrEqual(6, $payload['companies'][0]['summary']['flow_count']);
        $this->assertSame(1.0, (float) $payload['companies'][0]['summary']['pass_rate']);
    }

    public function test_enterprise_shadow_readiness_promotes_fixture_green_flows_to_shadow_candidates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-shadow-readiness',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_shadow_readiness.v1', $payload['schema']);
        $this->assertSame('shadow_ready', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertSame(9, $payload['summary']['shadow_ready_company_count']);
        $this->assertGreaterThanOrEqual(59, $payload['summary']['flow_count']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects']);
        $this->assertFalse((bool) $payload['summary']['external_autonomy_claim_allowed']);
        $this->assertSame('operator_governance_acceptance_required', $payload['summary']['external_autonomy_claim_blocker']);
        $this->assertContains('read_only_connector_probe', $payload['promotion_policy']['shadow_mode_allows']);
        $this->assertContains('trade', $payload['promotion_policy']['shadow_mode_blocks']);
        $this->assertSame(64, strlen((string) $payload['readiness_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_shadow_readiness.v1', $company['schema']);
            $this->assertTrue((bool) $company['shadow_readiness']['ready_for_shadow']);
            $this->assertSame(
                $company['shadow_readiness']['flow_count'],
                $company['shadow_readiness']['shadow_candidate_flow_count'],
            );
            $this->assertSame(1.0, (float) $company['shadow_readiness']['shadow_candidate_rate']);
            $this->assertFalse((bool) $company['shadow_readiness']['external_autonomy_claim_allowed']);
            $this->assertSame(64, strlen((string) $company['shadow_readiness_hash']));

            foreach ($company['flow_shadow_plans'] as $plan) {
                $this->assertTrue((bool) $plan['shadow_candidate']);
                $this->assertSame('fixture_completed', $plan['fixture_status']);
                $this->assertContains('operator_checkpoint_present', $plan['shadow_entry_gates']);
                $this->assertContains('vertical_solution_kit_present', $plan['shadow_entry_gates']);
                $this->assertNotEmpty($plan['vertical_solution_kit_id']);
                $this->assertSame(64, strlen((string) $plan['vertical_solution_attestation_hash']));
                $this->assertContains('operator_packet_generation', $plan['shadow_allowed_operations']);
                $this->assertContains('external_write', $plan['shadow_blocked_operations']);
                $this->assertFalse((bool) $plan['external_side_effects']);
                $this->assertNotEmpty($plan['plan_hash']);
            }
        }
    }

    public function test_enterprise_shadow_readiness_can_target_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-shadow-readiness',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['shadow_ready_company_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['shadow_readiness']['ready_for_shadow']);
    }

    public function test_enterprise_supervised_activation_plan_requires_operator_mandate(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-supervised-activation-plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_supervised_activation_plan.v1', $payload['schema']);
        $this->assertSame('operator_mandate_required', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertSame(9, $payload['summary']['ready_for_operator_mandate_company_count']);
        $this->assertGreaterThanOrEqual(59, $payload['summary']['flow_count']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects_enabled']);
        $this->assertTrue((bool) $payload['summary']['operator_mandate_required']);
        $this->assertFalse((bool) $payload['summary']['activation_without_operator_mandate_allowed']);
        $this->assertFalse((bool) $payload['summary']['external_autonomy_claim_allowed']);
        $this->assertContains('operator_id', $payload['activation_policy']['operator_mandate_required_fields']);
        $this->assertContains('trade', $payload['activation_policy']['supervised_activation_blocks_without_operator']);
        $this->assertSame(64, strlen((string) $payload['activation_plan_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_supervised_activation_plan.v1', $company['schema']);
            $this->assertTrue((bool) $company['activation_readiness']['ready_for_operator_mandate']);
            $this->assertFalse((bool) $company['activation_readiness']['external_side_effects_enabled']);
            $this->assertFalse((bool) $company['activation_readiness']['activation_without_operator_mandate_allowed']);
            $this->assertFalse((bool) $company['activation_readiness']['external_autonomy_claim_allowed']);
            $this->assertContains('signature_receipt_hash', $company['operator_mandate_template']['required_fields']);
            $this->assertSame(64, strlen((string) $company['company_activation_hash']));

            foreach ($company['flow_activation_plans'] as $plan) {
                $this->assertSame('atlas.ai.company.flow_supervised_activation_plan.v1', $plan['schema']);
                $this->assertTrue((bool) $plan['ready_for_operator_mandate']);
                $this->assertTrue((bool) $plan['operator_mandate_required']);
                $this->assertFalse((bool) $plan['activation_without_operator_mandate_allowed']);
                $this->assertContains('operator_signed_mandate', $plan['promotion_evidence_required']);
                $this->assertContains('vertical_solution_kit_present', $plan['promotion_evidence_required']);
                $this->assertNotEmpty($plan['vertical_solution_kit_id']);
                $this->assertSame(64, strlen((string) $plan['vertical_solution_kit_hash']));
                $this->assertGreaterThanOrEqual(1, count($plan['vertical_solution_suite_refs']));
                $this->assertGreaterThanOrEqual(1, count($plan['vertical_solution_required_evidence']));
                $this->assertContains('receipt_missing', $plan['incident_route']['trigger_on']);
                $this->assertContains('external_write', $plan['blocked_without_operator']);
                $this->assertFalse((bool) $plan['external_side_effects_enabled']);
                $this->assertNotEmpty($plan['activation_hash']);
            }
        }
    }

    public function test_enterprise_supervised_activation_plan_can_target_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-supervised-activation-plan',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_for_operator_mandate_company_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['activation_readiness']['ready_for_operator_mandate']);
    }

    public function test_enterprise_supervised_runtime_executes_all_company_flows_internally(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-supervised-runtime',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_supervised_runtime_suite.v1', $payload['schema']);
        $this->assertSame('supervised_completed', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(59, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['completed_flow_count']);
        $this->assertSame(1.0, (float) $payload['summary']['completion_rate']);
        $this->assertTrue((bool) $payload['summary']['operator_mandate_present']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects']);
        $this->assertFalse((bool) $payload['summary']['external_autonomy_claim_allowed']);
        $this->assertContains('internal_execution', $payload['runtime_policy']['allowed_operations']);
        $this->assertContains('external_write', $payload['runtime_policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $payload['runtime_suite_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_supervised_runtime_suite.v1', $company['schema']);
            $this->assertSame('supervised_completed', $company['status']);
            $this->assertSame($company['summary']['flow_count'], $company['summary']['completed_flow_count']);
            $this->assertSame(1.0, (float) $company['summary']['completion_rate']);
            $this->assertSame(1.0, (float) $company['summary']['receipt_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['trace_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['rollback_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['incident_route_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['evaluation_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['operating_package_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['operating_package_runtime_attestation_coverage']);
            $this->assertSame(1.0, (float) $company['summary']['vertical_solution_runtime_attestation_coverage']);
            $this->assertFalse((bool) $company['summary']['external_side_effects']);
            $this->assertSame('atlas.ai.company.operator_supervised_runtime_mandate.signed.v1', $company['operator_mandate']['schema']);
            $this->assertFalse((bool) $company['operator_mandate']['external_side_effects_allowed']);
            $this->assertSame(64, strlen((string) $company['operator_mandate']['signature_receipt_hash']));
            $this->assertSame(64, strlen((string) $company['company_runtime_hash']));

            foreach ($company['flow_runs'] as $run) {
                $this->assertSame('atlas.ai.company.enterprise_supervised_flow_run.v1', $run['schema']);
                $this->assertTrue((bool) $run['ok']);
                $this->assertSame('supervised_completed', $run['status']);
                $this->assertSame('operator_mandated_internal_supervised_runtime', $run['mode']);
                $this->assertFalse((bool) $run['external_side_effects']);
                $this->assertContains('policy_gate', $run['execution_nodes']);
                $this->assertSame('durable_graph_checkpoint', $run['runtime_trace']['state_model']);
                $this->assertTrue((bool) $run['runtime_trace']['tool_receipts_captured']);
                $this->assertNotEmpty($run['enterprise_flow_operating_package']['package_hash']);
                $this->assertTrue((bool) $run['operating_package_runtime_attestation']['package_bound']);
                $this->assertGreaterThanOrEqual(25, $run['operating_package_runtime_attestation']['minimum_replay_cases_before_shadow']);
                $this->assertTrue((bool) $run['operating_package_runtime_attestation']['runbook_drill_required_before_supervised_mode']);
                $this->assertFalse((bool) $run['operating_package_runtime_attestation']['external_execution_allowed']);
                $this->assertTrue((bool) $run['vertical_solution_runtime_attestation']['kit_bound']);
                $this->assertGreaterThanOrEqual(1, $run['vertical_solution_runtime_attestation']['suite_ref_count']);
                $this->assertGreaterThanOrEqual(1, $run['vertical_solution_runtime_attestation']['required_evidence_count']);
                $this->assertGreaterThanOrEqual(0.9, (float) $run['vertical_solution_runtime_attestation']['quality_floor']);
                $this->assertFalse((bool) $run['vertical_solution_runtime_attestation']['external_execution_allowed']);
                $this->assertTrue((bool) $run['rollback_attestation']['tested']);
                $this->assertTrue((bool) $run['incident_attestation']['route_bound']);
                $this->assertSame('green', $run['evaluation']['status']);
                $this->assertFalse((bool) $run['evaluation']['external_action_eligible']);
                $this->assertTrue((bool) $run['external_action_packet']['requires_additional_operator_approval']);
                $this->assertFalse((bool) $run['external_action_packet']['auto_execute_allowed']);
                $this->assertContains('external_write', $run['blocked_operations']);
                $this->assertSame(64, strlen((string) $run['receipt_hash']));
            }
        }
    }

    public function test_enterprise_supervised_runtime_can_target_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-supervised-runtime',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertSame('supervised_completed', $payload['companies'][0]['status']);
        $this->assertGreaterThanOrEqual(6, $payload['companies'][0]['summary']['flow_count']);
        $this->assertSame(1.0, (float) $payload['companies'][0]['summary']['completion_rate']);
    }

    public function test_enterprise_connector_certification_certifies_all_company_connectors(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-certification',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_connector_certification_suite.v1', $payload['schema']);
        $this->assertSame('certified', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(27, $payload['summary']['connector_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['certified_connector_count']);
        $this->assertSame(1.0, (float) $payload['summary']['certification_rate']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects']);
        $this->assertFalse((bool) $payload['summary']['write_or_paid_mode_enabled']);
        $this->assertTrue((bool) $payload['summary']['operator_mandate_required_for_external_mutation']);
        $this->assertContains('read_only_ping', $payload['certification_policy']['allowed_probe_modes']);
        $this->assertContains('spend', $payload['certification_policy']['blocked_without_operator']);
        $this->assertSame(64, strlen((string) $payload['certification_suite_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_connector_certification_suite.v1', $company['schema']);
            $this->assertSame('certified', $company['status']);
            $this->assertGreaterThanOrEqual(3, $company['summary']['connector_count']);
            $this->assertSame($company['summary']['connector_count'], $company['summary']['certified_connector_count']);
            $this->assertSame(1.0, (float) $company['summary']['certification_rate']);
            $this->assertSame(1.0, (float) $company['summary']['flow_usage_attestation_coverage']);
            $this->assertFalse((bool) $company['summary']['external_side_effects']);
            $this->assertFalse((bool) $company['summary']['write_or_paid_mode_enabled']);
            $this->assertSame(64, strlen((string) $company['company_certification_hash']));

            foreach ($company['connector_certifications'] as $run) {
                $this->assertSame('atlas.ai.company.connector_certification_run.v1', $run['schema']);
                $this->assertSame('certified', $run['status']);
                $this->assertTrue((bool) $run['certified']);
                $this->assertFalse((bool) $run['external_side_effects']);
                $this->assertFalse((bool) $run['write_or_paid_mode_enabled']);
                $this->assertContains('mcp_tool_schema', $run['adapter_contract']['supported_contract_forms']);
                $this->assertSame('vault_reference_only', $run['auth_boundary']['credential_binding']);
                $this->assertSame('green', $run['sandbox_probe_result']['status']);
                $this->assertFalse((bool) $run['sandbox_probe_result']['external_mutation_observed']);
                $this->assertSame('green', $run['consumer_provider_contract_result']['status']);
                $this->assertSame('green', $run['lineage_attestation']['status']);
                $this->assertSame('green', $run['replay_fixture_attestation']['status']);
                $this->assertSame('green', $run['slo_failure_attestation']['status']);
                $this->assertContains('write', $run['blocked_operations_without_operator']);
                $this->assertTrue((bool) $run['promotion_gate_result']['supervised_ready']);
                $this->assertFalse((bool) $run['promotion_gate_result']['external_mutation_ready']);
                $this->assertTrue((bool) $run['promotion_gate_result']['operator_mandate_required_for_external_mutation']);
                $this->assertSame(64, strlen((string) $run['certification_receipt_hash']));
            }

            foreach ($company['flow_usage_attestations'] as $usage) {
                $this->assertSame('atlas.ai.company.flow_connector_usage_attestation.v1', $usage['schema']);
                $this->assertNotEmpty($usage['flow_id']);
                $this->assertNotEmpty($usage['connector_scope']);
                $this->assertContains('read', $usage['allowed_modes']);
                $this->assertContains('write', $usage['blocked_modes']);
                $this->assertTrue((bool) $usage['least_privilege_verified']);
                $this->assertFalse((bool) $usage['write_or_paid_mode_enabled']);
                $this->assertFalse((bool) $usage['external_side_effects']);
                $this->assertSame(64, strlen((string) $usage['usage_attestation_hash']));
            }
        }
    }

    public function test_enterprise_connector_certification_can_target_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-certification',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertSame('certified', $payload['companies'][0]['status']);
        $this->assertGreaterThanOrEqual(6, $payload['companies'][0]['summary']['connector_count']);
        $this->assertSame(1.0, (float) $payload['companies'][0]['summary']['certification_rate']);
    }

    public function test_enterprise_external_action_mandates_prepare_all_flow_packets_without_auto_execution(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-mandates',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_action_mandate_suite.v1', $payload['schema']);
        $this->assertSame('external_mandates_prepared', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(59, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['prepared_packet_count']);
        $this->assertSame(1.0, (float) $payload['summary']['packet_preparation_rate']);
        $this->assertSame(9, $payload['summary']['connector_certified_company_count']);
        $this->assertFalse((bool) $payload['summary']['external_side_effects_enabled']);
        $this->assertFalse((bool) $payload['summary']['auto_execute_allowed']);
        $this->assertTrue((bool) $payload['summary']['operator_signature_required']);
        $this->assertTrue((bool) $payload['summary']['second_reviewer_required']);
        $this->assertContains('trade', $payload['mandate_policy']['blocks_until_signed_mandate']);
        $this->assertTrue((bool) $payload['mandate_policy']['external_execution_remains_disabled_in_this_suite']);
        $this->assertSame(64, strlen((string) $payload['mandate_suite_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.enterprise_external_action_mandate_suite.v1', $company['schema']);
            $this->assertSame('external_mandates_prepared', $company['status']);
            $this->assertSame($company['summary']['flow_count'], $company['summary']['prepared_packet_count']);
            $this->assertSame(1.0, (float) $company['summary']['packet_preparation_rate']);
            $this->assertTrue((bool) $company['summary']['source_runtime_green']);
            $this->assertTrue((bool) $company['summary']['source_connector_certification_green']);
            $this->assertSame(1.0, (float) $company['summary']['connector_scope_coverage']);
            $this->assertFalse((bool) $company['summary']['external_side_effects_enabled']);
            $this->assertFalse((bool) $company['summary']['auto_execute_allowed']);
            $this->assertTrue((bool) $company['summary']['operator_signature_required']);
            $this->assertTrue((bool) $company['summary']['second_reviewer_required']);
            $this->assertSame(64, strlen((string) $company['company_mandate_suite_hash']));

            foreach ($company['mandate_packets'] as $packet) {
                $this->assertSame('atlas.ai.company.enterprise_external_action_mandate_packet.v1', $packet['schema']);
                $this->assertTrue((bool) $packet['prepared']);
                $this->assertSame('awaiting_signed_external_mandate', $packet['status']);
                $this->assertNotEmpty($packet['source_runtime_receipt_hash']);
                $this->assertNotEmpty($packet['source_connector_certification_hash']);
                $this->assertNotEmpty($packet['source_flow_usage_attestation_hash']);
                $this->assertNotEmpty($packet['connector_scope']);
                $this->assertContains('operator_review', $packet['allowed_pre_external_modes']);
                $this->assertContains('trade', $packet['blocked_operations_until_signed_mandate']);
                $this->assertContains('write', $packet['blocked_operations_until_signed_mandate']);
                $this->assertTrue((bool) $packet['operator_signature_required']);
                $this->assertTrue((bool) $packet['second_reviewer_required']);
                $this->assertTrue((bool) $packet['legal_or_risk_review_required']);
                $this->assertFalse((bool) $packet['auto_execute_allowed']);
                $this->assertFalse((bool) $packet['external_side_effects_enabled']);
                $this->assertTrue((bool) $packet['risk_controls']['policy_gate_required']);
                $this->assertContains('connector_scope_matches_certification', $packet['preflight_checks']);
                $this->assertFalse((bool) $packet['cost_budget_envelope']['spend_without_cap_allowed']);
                $this->assertSame(64, strlen((string) $packet['mandate_packet_hash']));
            }
        }
    }

    public function test_enterprise_external_action_mandates_can_target_one_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-mandates',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['prepared_packet_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertSame('external_mandates_prepared', $payload['companies'][0]['status']);
        $this->assertFalse((bool) $payload['summary']['auto_execute_allowed']);
        $this->assertTrue((bool) $payload['summary']['operator_signature_required']);
    }

    public function test_enterprise_external_action_register_persists_all_mandate_packets_for_operator_review(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_action_mandate_registry.v1', $payload['schema']);
        $this->assertSame('queued_for_operator_review', $payload['status']);
        $this->assertSame(59, $payload['summary']['registered_count']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertSame(59, $payload['summary']['flow_count']);
        $this->assertSame(0, $payload['summary']['auto_execute_allowed_count']);
        $this->assertSame(0, $payload['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $payload['registry_policy']['external_execution_enabled_by_registry']);
        $this->assertContains('trade_without_signed_mandate', $payload['registry_policy']['blocked_next_actions']);
        $this->assertSame(64, strlen((string) $payload['registry_hash']));
        $this->assertSame(59, AiHoldingExternalActionMandate::query()->count());

        $record = AiHoldingExternalActionMandate::query()->where('company_id', 'finance')->first();
        $this->assertNotNull($record);
        $this->assertSame('queued_for_operator_review', $record->status);
        $this->assertContains('trade', $record->blocked_operations_json);
        $this->assertContains('write', $record->blocked_operations_json);
        $this->assertNotEmpty($record->connector_scope_json);
        $this->assertTrue((bool) $record->operator_signature_required);
        $this->assertTrue((bool) $record->second_reviewer_required);
        $this->assertFalse((bool) $record->auto_execute_allowed);
        $this->assertFalse((bool) $record->external_side_effects_enabled);
    }

    public function test_enterprise_external_action_register_can_target_one_flow_and_preflight_it(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();

        $suiteExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-mandates',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $suite = json_decode(Artisan::output(), true);
        $flowId = (string) $suite['companies'][0]['mandate_packets'][0]['flow_id'];

        $this->assertSame(0, $suiteExit);
        $this->assertNotEmpty($flowId);

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--company' => 'finance',
            '--flow' => $flowId,
            '--json' => true,
        ]);
        $registered = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $this->assertTrue((bool) $registered['ok']);
        $this->assertSame(1, $registered['summary']['registered_count']);
        $this->assertSame('finance', $registered['records'][0]['company_id']);
        $this->assertSame($flowId, $registered['records'][0]['flow_id']);
        $this->assertSame(1, AiHoldingExternalActionMandate::query()->count());

        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];
        $preflightExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-preflight',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        $preflight = json_decode(Artisan::output(), true);

        $this->assertSame(0, $preflightExit);
        $this->assertTrue((bool) $preflight['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_action_mandate_preflight.v1', $preflight['schema']);
        $this->assertSame('preflight_green_awaiting_signatures', $preflight['status']);
        $this->assertFalse((bool) $preflight['external_execution_allowed']);
        $this->assertSame($mandateHash, $preflight['mandate_packet_hash']);
        $this->assertEmpty($preflight['failed_checks']);
        $this->assertSame(11, count($preflight['checks']));
        $this->assertSame(64, strlen((string) $preflight['preflight_hash']));

        $record = AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $mandateHash)->first();
        $this->assertSame('preflight_green_awaiting_signatures', $record?->status);
        $this->assertNotNull($record?->preflighted_at);
    }

    public function test_enterprise_external_action_approval_requires_operator_and_second_reviewer_without_auto_execution(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        $registered = $this->registerOneFinanceMandate();
        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-preflight',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);

        $requestExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        $request = json_decode(Artisan::output(), true);

        $this->assertSame(0, $requestExit);
        $this->assertTrue((bool) $request['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_action_approval_request.v1', $request['schema']);
        $this->assertSame('awaiting_operator_and_reviewer_signatures', $request['status']);
        $this->assertSame(2, $request['approval_count']);
        $this->assertFalse((bool) $request['external_execution_allowed']);
        $this->assertContains('operator_signature', $request['required_roles']);
        $this->assertContains('second_reviewer_signature', $request['required_roles']);
        $this->assertSame(2, AiOperatorApproval::query()->count());

        $operatorApproval = $this->approvalByRole('operator_signature');
        $reviewerApproval = $this->approvalByRole('second_reviewer_signature');
        $this->assertNotNull($operatorApproval);
        $this->assertNotNull($reviewerApproval);
        $this->assertFalse((bool) data_get($operatorApproval->options, 'external_execution_allowed_after_approval'));
        $this->assertTrue((bool) data_get($operatorApproval->options, 'manual_execution_handoff_only'));

        $operatorExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $operatorApproval->uuid,
            '--operator' => 'operator-vitor',
            '--note' => 'operator approved scope',
            '--json' => true,
        ]);
        $operatorDecision = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operatorExit);
        $this->assertTrue((bool) $operatorDecision['ok']);
        $this->assertSame('approved', $operatorDecision['status']);
        $this->assertSame('awaiting_operator_and_reviewer_signatures', $operatorDecision['mandate_status']);
        $this->assertFalse((bool) $operatorDecision['external_execution_allowed']);

        $reviewerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $reviewerApproval->uuid,
            '--operator' => 'reviewer-independent',
            '--note' => 'reviewer approved controls',
            '--json' => true,
        ]);
        $reviewerDecision = json_decode(Artisan::output(), true);

        $this->assertSame(0, $reviewerExit);
        $this->assertTrue((bool) $reviewerDecision['ok']);
        $this->assertSame('approved', $reviewerDecision['status']);
        $this->assertSame('signed_mandate_ready_manual_execution_only', $reviewerDecision['mandate_status']);
        $this->assertFalse((bool) $reviewerDecision['external_execution_allowed']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approval-status',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('signed_mandate_ready_manual_execution_only', $status['status']);
        $this->assertTrue((bool) $status['operator_approved']);
        $this->assertTrue((bool) $status['second_reviewer_approved']);
        $this->assertFalse((bool) $status['external_execution_allowed']);
        $this->assertSame('manual_execution_only_even_after_signatures', $status['external_execution_blocker']);
    }

    public function test_enterprise_external_action_rejection_blocks_the_mandate_gate(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        $registered = $this->registerOneFinanceMandate();
        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);

        $operatorApproval = $this->approvalByRole('operator_signature');
        $this->assertNotNull($operatorApproval);

        $rejectExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-reject',
            '--approval-uuid' => $operatorApproval->uuid,
            '--operator' => 'operator-vitor',
            '--note' => 'scope not accepted',
            '--json' => true,
        ]);
        $reject = json_decode(Artisan::output(), true);

        $this->assertSame(0, $rejectExit);
        $this->assertTrue((bool) $reject['ok']);
        $this->assertSame('rejected', $reject['status']);
        $this->assertSame('rejected_by_operator_gate', $reject['mandate_status']);
        $this->assertFalse((bool) $reject['external_execution_allowed']);

        $record = AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $mandateHash)->first();
        $this->assertSame('rejected_by_operator_gate', $record?->status);
    }

    public function test_enterprise_control_tower_reports_missing_registered_and_signed_manual_handoffs(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        $emptyExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-control-tower',
            '--json' => true,
        ]);
        $empty = json_decode(Artisan::output(), true);

        $this->assertSame(0, $emptyExit);
        $this->assertSame('atlas.ai.holding.enterprise_control_tower.v1', $empty['schema']);
        $this->assertSame(9, $empty['summary']['company_count']);
        $this->assertSame(59, $empty['summary']['expected_flow_count']);
        $this->assertSame(0, $empty['summary']['registered_mandate_count']);
        $this->assertSame(59, $empty['summary']['not_registered_count']);
        $this->assertFalse((bool) $empty['policy']['external_execution_allowed']);
        $this->assertSame('run_enterprise_external_action_register', $empty['companies'][0]['flow_rows'][0]['next_action']);

        $registeredExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--json' => true,
        ]);
        $registered = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registeredExit);
        $this->assertSame(59, $registered['summary']['registered_count']);

        $financeHash = (string) AiHoldingExternalActionMandate::query()
            ->where('company_id', 'finance')
            ->value('mandate_packet_hash');
        $this->assertNotEmpty($financeHash);

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $financeHash,
            '--json' => true,
        ]);
        $operatorApproval = $this->approvalByRole('operator_signature');
        $reviewerApproval = $this->approvalByRole('second_reviewer_signature');
        $this->assertNotNull($operatorApproval);
        $this->assertNotNull($reviewerApproval);

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $operatorApproval->uuid,
            '--operator' => 'operator-vitor',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $reviewerApproval->uuid,
            '--operator' => 'reviewer-independent',
            '--json' => true,
        ]);

        $towerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-control-tower',
            '--json' => true,
        ]);
        $tower = json_decode(Artisan::output(), true);

        $this->assertSame(0, $towerExit);
        $this->assertTrue((bool) $tower['ok']);
        $this->assertSame('external_execution_blocked_control_tower_ready', $tower['status']);
        $this->assertSame(59, $tower['summary']['registered_mandate_count']);
        $this->assertSame(0, $tower['summary']['not_registered_count']);
        $this->assertSame(1, $tower['summary']['signed_manual_handoff_count']);
        $this->assertSame(2, $tower['summary']['approval_count']);
        $this->assertSame(1, $tower['summary']['operator_approved_count']);
        $this->assertSame(1, $tower['summary']['second_reviewer_approved_count']);
        $this->assertSame(0, $tower['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $tower['summary']['external_side_effects_enabled_count']);
        $this->assertTrue((bool) $tower['policy']['manual_execution_handoff_only']);
        $this->assertContains('trade', $tower['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $tower['control_tower_hash']));

        $finance = collect($tower['companies'])->firstWhere('company_id', 'finance');
        $this->assertIsArray($finance);
        $this->assertSame(1, $finance['signed_manual_handoff_count']);
        $this->assertSame(0, $finance['external_execution_allowed_count']);
    }

    public function test_enterprise_control_tower_can_target_one_company(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-control-tower',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(6, $payload['summary']['expected_flow_count']);
        $this->assertSame(6, $payload['summary']['registered_mandate_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertSame(6, $payload['companies'][0]['registered_mandate_count']);
        $this->assertSame('run_enterprise_external_action_preflight', $payload['companies'][0]['flow_rows'][0]['next_action']);
    }

    public function test_enterprise_activation_cockpit_reports_real_operation_backlog_without_external_execution(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        $registered = $this->registerOneFinanceMandate();
        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        $operatorApproval = $this->approvalByRole('operator_signature');
        $reviewerApproval = $this->approvalByRole('second_reviewer_signature');
        $this->assertNotNull($operatorApproval);
        $this->assertNotNull($reviewerApproval);

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $operatorApproval->uuid,
            '--operator' => 'operator-vitor',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $reviewerApproval->uuid,
            '--operator' => 'reviewer-independent',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-cockpit',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_activation_cockpit.v1', $payload['schema']);
        $this->assertSame('enterprise_activation_backlog_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(6, $payload['summary']['flow_count']);
        $this->assertSame(1, $payload['summary']['manual_handoff_ready_count']);
        $this->assertSame(5, $payload['summary']['blocked_flow_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['activation_policy']['external_execution_allowed']);
        $this->assertContains('credential_vault_binding_per_connector', $payload['activation_policy']['required_before_real_external_execution']);
        $this->assertContains('langgraph_durable_execution', array_column($payload['enterprise_pattern_sources'], 'source_id'));
        $this->assertContains('openai_agents_sdk', array_column($payload['enterprise_pattern_sources'], 'source_id'));
        $this->assertContains('anthropic_claude_for_financial_services', array_column($payload['enterprise_pattern_sources'], 'source_id'));
        $this->assertSame(64, strlen((string) $payload['activation_cockpit_hash']));

        $finance = $payload['companies'][0];
        $this->assertSame('finance', $finance['company_id']);
        $this->assertSame(6, $finance['expected_flow_count']);
        $this->assertSame(1, $finance['manual_handoff_ready_count']);
        $this->assertArrayHasKey('manual_handoff_ready_needs_real_connector_dress_rehearsal', $finance['activation_stage_counts']);

        $readyFlow = collect($finance['flow_backlog'])->firstWhere('manual_handoff_ready', true);
        $this->assertIsArray($readyFlow);
        $this->assertSame('manual_handoff_ready_needs_real_connector_dress_rehearsal', $readyFlow['activation_stage']);
        $this->assertContains('credential_vault_binding_missing', $readyFlow['connector_activation_gaps']);
        $this->assertContains('rollback_or_compensation_drill_missing', $readyFlow['operationalization_gaps']);
        $this->assertContains('operator_signature_receipt', $readyFlow['evidence_required_for_real_operation']);
        $this->assertFalse((bool) $readyFlow['external_execution_allowed']);
    }

    public function test_enterprise_activation_cockpit_reports_not_registered_company_backlog(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-cockpit',
            '--company' => 'marketing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_count']);
        $this->assertSame(0, $payload['summary']['manual_handoff_ready_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['blocked_flow_count']);
        $this->assertSame(0, $payload['summary']['external_side_effects_enabled_count']);
        $this->assertSame('marketing', $payload['companies'][0]['company_id']);
        $this->assertArrayHasKey('not_registered', $payload['companies'][0]['activation_stage_counts']);
        $this->assertContains('mandate_packet_not_registered', $payload['companies'][0]['flow_backlog'][0]['governance_gaps']);
        $this->assertSame('run_enterprise_external_action_register', $payload['companies'][0]['flow_backlog'][0]['next_action']);
    }

    public function test_enterprise_premium_activation_status_reports_all_companies_ready_without_external_execution(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-premium-activation-status',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_premium_activation_status.v1', $payload['schema']);
        $this->assertSame('premium_activation_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(9, $payload['summary']['company_count']);
        $this->assertSame(9, $payload['summary']['premium_ready_company_count']);
        $this->assertSame(0, $payload['summary']['wait_days_required_max']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['operator_mandate_required_for_external_action']);
        $this->assertContains('live_trade', $payload['policy']['blocked_without_operator_mandate']);
        $this->assertSame(64, strlen((string) $payload['premium_activation_status_hash']));

        foreach ($payload['companies'] as $company) {
            $this->assertSame('atlas.ai.company.premium_activation_status.v1', $company['schema']);
            $this->assertTrue((bool) $company['premium_readiness']['ready']);
            $this->assertGreaterThanOrEqual(5, $company['premium_readiness']['reference_source_count']);
            $this->assertGreaterThanOrEqual(6, $company['premium_readiness']['agentic_runtime_pattern_count']);
            $this->assertGreaterThanOrEqual(9, $company['premium_readiness']['required_runtime_property_count']);
            $this->assertGreaterThanOrEqual(10, $company['premium_readiness']['managed_agent_template_count']);
            $this->assertSame($company['premium_readiness']['managed_agent_template_count'], $company['premium_readiness']['template_runtime_contract_count']);
            $this->assertSame($company['flow_count'], $company['premium_readiness']['flow_template_map_count']);
            $this->assertSame($company['flow_count'], $company['premium_readiness']['flow_managed_agent_workflow_count']);
            $this->assertSame($company['connector_count'], $company['premium_readiness']['workbench_count']);
            $this->assertSame($company['connector_count'], $company['premium_readiness']['connector_mcp_server_plan_count']);
            $this->assertSame($company['flow_count'], $company['premium_readiness']['replay_benchmark_count']);
            $this->assertSame(0, $company['premium_readiness']['buildout_wait_days_required']);
            $this->assertFalse((bool) $company['external_execution_allowed']);
            $this->assertFalse((bool) $company['external_side_effects_enabled']);
            $this->assertContains('run_replay_benchmark_per_flow', $company['next_activation_steps']);
            $this->assertSame(64, strlen((string) $company['company_premium_activation_hash']));
        }
    }

    public function test_enterprise_premium_activation_status_keeps_finance_agent_templates_and_sources(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-premium-activation-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['premium_ready_company_count']);

        $finance = $payload['companies'][0];
        $this->assertSame('finance', $finance['company_id']);
        $this->assertSame(10, $finance['premium_readiness']['managed_agent_template_count']);
        $this->assertContains('anthropic_agents_for_financial_services_2026', $finance['source_basis_ids']);
        $this->assertContains('anthropic_claude_for_financial_services_2025', $finance['source_basis_ids']);
        $this->assertContains('pitch_builder', $finance['template_ids']);
        $this->assertContains('meeting_preparer', $finance['template_ids']);
        $this->assertContains('earnings_reviewer', $finance['template_ids']);
        $this->assertContains('model_builder', $finance['template_ids']);
        $this->assertContains('market_researcher', $finance['template_ids']);
        $this->assertContains('valuation_reviewer', $finance['template_ids']);
        $this->assertContains('general_ledger_reconciler', $finance['template_ids']);
        $this->assertContains('month_end_closer', $finance['template_ids']);
        $this->assertContains('statement_auditor', $finance['template_ids']);
        $this->assertContains('kyc_screener', $finance['template_ids']);
        $this->assertSame($finance['flow_count'], count($finance['flow_template_map']));
        $this->assertFalse((bool) $finance['external_execution_allowed']);
    }

    public function test_enterprise_premium_activation_status_requires_ten_agent_templates_for_every_company(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-premium-activation-status',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);

        foreach ($payload['companies'] as $company) {
            $this->assertSame(10, $company['premium_readiness']['managed_agent_template_count'], (string) $company['company_id']);
            $this->assertSame(10, count($company['template_ids']), (string) $company['company_id']);
            $this->assertSame(10, $company['premium_readiness']['template_runtime_contract_count'], (string) $company['company_id']);
            $this->assertTrue((bool) data_get($company, 'premium_readiness.checks.managed_agent_templates_green'), (string) $company['company_id']);
        }
    }

    public function test_enterprise_activation_backlog_register_persists_work_packages_for_company_flows(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_activation_backlog_registry.v1', $payload['schema']);
        $this->assertSame('queued_for_implementation', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(6, $payload['summary']['flow_count']);
        $this->assertSame(18, $payload['summary']['work_package_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['registry_policy']['external_execution_enabled_by_registry']);
        $this->assertContains('auto_execute_external_action', $payload['registry_policy']['blocked_next_actions']);
        $this->assertSame(64, strlen((string) $payload['activation_backlog_registry_hash']));
        $this->assertSame(18, AiHoldingActivationBacklogItem::query()->where('company_id', 'finance')->count());

        $item = AiHoldingActivationBacklogItem::query()
            ->where('company_id', 'finance')
            ->where('work_package_id', 'connector_activation')
            ->first();
        $this->assertNotNull($item);
        $this->assertSame('queued_for_implementation', $item->status);
        $this->assertSame('automation.company_manager_agent', $item->owner);
        $this->assertContains('credential_vault_binding_missing', $item->connector_activation_gaps_json);
        $this->assertContains('rollback_drill_receipt', $item->evidence_required_json);
        $this->assertFalse((bool) $item->external_execution_allowed);
        $this->assertFalse((bool) $item->external_side_effects_enabled);
    }

    public function test_enterprise_activation_backlog_status_reports_registered_queue_without_external_execution(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'marketing',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-status',
            '--company' => 'marketing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_activation_backlog_status.v1', $payload['schema']);
        $this->assertSame('backlog_registered_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(18, $payload['summary']['work_package_count']);
        $this->assertSame($payload['summary']['work_package_count'], $payload['summary']['queued_count']);
        $this->assertSame(0, $payload['summary']['completed_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['manual_completion_does_not_enable_external_execution']);
        $this->assertSame('marketing', $payload['companies'][0]['company_id']);
        $this->assertArrayHasKey('connector_activation', $payload['companies'][0]['work_package_counts']);
        $this->assertSame(64, strlen((string) $payload['activation_backlog_status_hash']));
    }

    public function test_enterprise_activation_backlog_run_processes_internal_work_without_external_execution(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();

        $registered = $this->registerOneFinanceMandate();
        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];
        $flowId = (string) $registered['records'][0]['flow_id'];
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-preflight',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        $operatorApproval = $this->approvalByRole('operator_signature');
        $reviewerApproval = $this->approvalByRole('second_reviewer_signature');
        $this->assertNotNull($operatorApproval);
        $this->assertNotNull($reviewerApproval);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $operatorApproval->uuid,
            '--operator' => 'operator',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-approve',
            '--approval-uuid' => $reviewerApproval->uuid,
            '--operator' => 'reviewer',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-run',
            '--company' => 'finance',
            '--flow' => $flowId,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_activation_backlog_run.v1', $payload['schema']);
        $this->assertSame('implementation_cycle_completed_external_execution_blocked', $payload['status']);
        $this->assertSame(3, $payload['summary']['work_package_count']);
        $this->assertSame(3, $payload['summary']['completed_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['run_policy']['external_execution_allowed']);
        $this->assertContains('trade', $payload['run_policy']['blocked_operations']);

        $item = AiHoldingActivationBacklogItem::query()
            ->where('company_id', 'finance')
            ->where('flow_id', $flowId)
            ->where('work_package_id', 'connector_activation')
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('completed_internal_no_external_execution', $item->status);
        $this->assertSame(1, $item->implementation_attempt_count);
        $this->assertSame(64, strlen((string) $item->last_implementation_receipt_hash));
        $this->assertContains('credential_scope_attestation', $item->evidence_attached_json);
        $this->assertFalse((bool) $item->external_execution_allowed);
        $this->assertFalse((bool) $item->external_side_effects_enabled);
    }

    public function test_enterprise_connector_activation_register_probe_and_status_are_persistent_and_external_blocked(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingConnectorActivationRecord::query()->delete();

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $registered = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $this->assertTrue((bool) $registered['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_connector_activation_registry.v1', $registered['schema']);
        $this->assertSame('registered_needs_sandbox_probe', $registered['status']);
        $this->assertSame(1, $registered['summary']['company_count']);
        $this->assertSame(6, $registered['summary']['flow_count']);
        $this->assertGreaterThanOrEqual(6, $registered['summary']['connector_activation_count']);
        $this->assertSame(0, $registered['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $registered['registry_policy']['external_execution_allowed']);
        $this->assertSame(
            $registered['summary']['connector_activation_count'],
            AiHoldingConnectorActivationRecord::query()->where('company_id', 'finance')->count(),
        );

        $probeExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-probe',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $probe = json_decode(Artisan::output(), true);

        $this->assertSame(0, $probeExit);
        $this->assertTrue((bool) $probe['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_connector_activation_probe.v1', $probe['schema']);
        $this->assertSame('sandbox_probe_cycle_completed_external_execution_blocked', $probe['status']);
        $this->assertSame($registered['summary']['connector_activation_count'], $probe['summary']['activated_internal_count']);
        $this->assertSame(0, $probe['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $probe['probe_policy']['external_execution_allowed']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_connector_activation_status.v1', $status['schema']);
        $this->assertSame('connector_activation_records_external_execution_blocked', $status['status']);
        $this->assertSame($registered['summary']['connector_activation_count'], $status['summary']['activated_internal_count']);
        $this->assertFalse((bool) $status['policy']['external_execution_allowed']);

        $liveReadExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-live-read-connector-readiness-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $liveRead = json_decode(Artisan::output(), true);

        $this->assertSame(0, $liveReadExit);
        $this->assertTrue((bool) $liveRead['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_live_read_connector_readiness_status.v1', $liveRead['schema']);
        $this->assertSame('live_read_connector_readiness_ready_external_execution_blocked', $liveRead['status']);
        $this->assertSame($registered['summary']['connector_activation_count'], $liveRead['summary']['connector_readiness_count']);
        $this->assertSame($registered['summary']['connector_activation_count'], $liveRead['summary']['live_read_ready_count']);
        $this->assertSame(0, $liveRead['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $liveRead['policy']['external_execution_allowed']);
        $this->assertContains('trade', $liveRead['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $liveRead['live_read_connector_readiness_status_hash']));
        $this->assertTrue((bool) $liveRead['records'][0]['live_read_ready']);
        $this->assertSame(64, strlen((string) $liveRead['records'][0]['schema_snapshot_hash']));
        $this->assertSame(64, strlen((string) $liveRead['records'][0]['sample_payload_hash']));
        $this->assertSame(64, strlen((string) $liveRead['records'][0]['provider_lineage_hash']));
        $this->assertNotSame('', (string) $liveRead['records'][0]['vault_scope_reference']);
        $this->assertFalse((bool) $liveRead['records'][0]['credential_material_in_receipt']);
        $this->assertFalse((bool) $liveRead['records'][0]['external_side_effects_enabled']);
        $this->assertContains('schema_snapshot', $liveRead['records'][0]['allowed_operations']);
        $this->assertContains('write', $liveRead['records'][0]['blocked_operations']);

        $adapterRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-adapter-execution-envelope-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $adapterRegister = json_decode(Artisan::output(), true);

        $this->assertSame(0, $adapterRegisterExit, Artisan::output());
        $this->assertTrue((bool) $adapterRegister['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_adapter_execution_envelope_register.v1', $adapterRegister['schema']);
        $this->assertSame('enterprise_company_domain_adapter_execution_envelopes_registered_external_effects_blocked', $adapterRegister['status']);
        $this->assertSame($liveRead['summary']['connector_readiness_count'], $adapterRegister['summary']['registered_envelope_count']);
        $this->assertSame(0, $adapterRegister['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $adapterRegister['policy']['external_execution_allowed']);
        $this->assertContains('credential_material_export', $adapterRegister['policy']['blocked_operations']);
        $this->assertSame(
            $liveRead['summary']['connector_readiness_count'],
            AtlasToolRun::query()->where('surface', 'holding_company_adapter_runtime')->count(),
        );

        $adapterStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-adapter-execution-envelope-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $adapterStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $adapterStatusExit, Artisan::output());
        $this->assertTrue((bool) $adapterStatus['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_adapter_execution_envelope_status.v1', $adapterStatus['schema']);
        $this->assertSame('enterprise_company_domain_adapter_execution_envelopes_ready_external_effects_blocked', $adapterStatus['status']);
        $this->assertSame(1, $adapterStatus['summary']['adapter_envelope_ready_company_count']);
        $this->assertSame($adapterStatus['summary']['expected_envelope_count'], $adapterStatus['summary']['ready_persisted_envelope_count']);
        $this->assertContains('vault_scope_reference', $adapterStatus['policy']['required_runtime_evidence']);
        $this->assertTrue((bool) $adapterStatus['companies'][0]['gates']['persisted_adapter_envelopes_cover_connectors']);
        $this->assertSame(64, strlen((string) data_get($adapterStatus, 'companies.0.runtime_records.0.receipt_chain.adapter_envelope_receipt_hash')));
        $this->assertSame(64, strlen((string) $adapterStatus['enterprise_company_domain_adapter_execution_envelope_status_hash']));

        $record = AiHoldingConnectorActivationRecord::query()->where('company_id', 'finance')->first();
        $this->assertNotNull($record);
        $this->assertSame('activated_internal_connector_ready_external_blocked', $record->status);
        $this->assertTrue((bool) $record->sandbox_probe_green);
        $this->assertSame(64, strlen((string) $record->last_probe_receipt_hash));
        $this->assertFalse((bool) $record->external_execution_allowed);
        $this->assertFalse((bool) $record->external_side_effects_enabled);
    }

    public function test_enterprise_flow_run_queue_register_execute_and_status_track_dlq_without_external_execution(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();
        AiHoldingConnectorActivationRecord::query()->delete();
        AiHoldingEnterpriseFlowRunQueueItem::query()->delete();

        $registered = $this->registerOneFinanceMandate();
        $mandateHash = (string) $registered['records'][0]['mandate_packet_hash'];
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-preflight',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-request-approval',
            '--mandate-hash' => $mandateHash,
            '--json' => true,
        ]);
        foreach (['operator_signature', 'second_reviewer_signature'] as $role) {
            $approval = $this->approvalByRole($role);
            $this->assertNotNull($approval);
            Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-action-approve',
                '--approval-uuid' => $approval->uuid,
                '--operator' => $role,
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-run',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $register = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $this->assertTrue((bool) $register['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_run_queue_registry.v1', $register['schema']);
        $this->assertSame(6, $register['summary']['flow_count']);
        $this->assertSame(6, $register['summary']['operating_package_bound_count']);
        $this->assertSame(6, $register['summary']['replay_contract_bound_count']);
        $this->assertSame(6, $register['summary']['operating_package_attestation_count']);
        $this->assertSame(6, $register['summary']['durable_execution_envelope_bound_count']);
        $this->assertSame(6, $register['summary']['checkpoint_resume_bound_count']);
        $this->assertSame(6, $register['summary']['human_in_loop_bound_count']);
        $this->assertSame(6, $register['summary']['trace_receipt_bound_count']);
        $this->assertSame(6, $register['summary']['surface_binding_bound_count']);
        $this->assertSame(6, $register['summary']['fixture_smoke_bound_count']);
        $this->assertSame(0, $register['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $register['queue_policy']['external_execution_allowed']);
        $this->assertNotEmpty($register['records'][0]['operating_package_hash']);
        $this->assertTrue((bool) $register['records'][0]['replay_contract_bound']);
        $this->assertGreaterThanOrEqual(25, $register['records'][0]['replay_contract']['minimum_cases_before_shadow']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_queue_operating_package_attestation.v1', $register['records'][0]['last_operating_package_attestation']['schema']);
        $this->assertFalse((bool) $register['records'][0]['last_operating_package_attestation']['calendar_wait_blocker_enabled']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_queue_durable_execution_envelope.v1', $register['records'][0]['last_queue_receipt']['durable_execution_envelope']['schema']);
        $this->assertSame(64, strlen((string) $register['records'][0]['last_queue_receipt']['durable_execution_envelope_hash']));
        $this->assertTrue((bool) $register['records'][0]['last_queue_receipt']['checkpoint_resume_bound']);
        $this->assertTrue((bool) $register['records'][0]['last_queue_receipt']['human_in_loop_bound']);
        $this->assertTrue((bool) $register['records'][0]['last_queue_receipt']['trace_receipt_bound']);
        $this->assertTrue((bool) $register['records'][0]['last_queue_receipt']['surface_binding_bound']);
        $this->assertTrue((bool) $register['records'][0]['last_queue_receipt']['fixture_smoke_bound']);

        $executeExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-execute',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $execute = json_decode(Artisan::output(), true);

        $this->assertSame(0, $executeExit);
        $this->assertTrue((bool) $execute['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_run_queue_execution.v1', $execute['schema']);
        $this->assertSame(6, $execute['summary']['flow_count']);
        $this->assertSame(1, $execute['summary']['completed_count']);
        $this->assertSame(5, $execute['summary']['dlq_count']);
        $this->assertSame(6, $execute['summary']['operating_package_bound_count']);
        $this->assertSame(6, $execute['summary']['replay_contract_bound_count']);
        $this->assertSame(12, $execute['summary']['operating_package_attestation_count']);
        $this->assertSame(6, $execute['summary']['durable_execution_envelope_bound_count']);
        $this->assertSame(6, $execute['summary']['checkpoint_resume_bound_count']);
        $this->assertSame(6, $execute['summary']['human_in_loop_bound_count']);
        $this->assertSame(6, $execute['summary']['trace_receipt_bound_count']);
        $this->assertSame(6, $execute['summary']['surface_binding_bound_count']);
        $this->assertSame(6, $execute['summary']['fixture_smoke_bound_count']);
        $this->assertSame(0, $execute['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $execute['execution_policy']['external_execution_allowed']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_queue_durable_execution_envelope.v1', $execute['records'][0]['last_execution_receipt']['durable_execution_envelope']['schema']);
        $this->assertContains('langgraph_checkpoint_human_in_the_loop', $execute['records'][0]['last_execution_receipt']['durable_execution_envelope']['source_patterns']);
        $this->assertContains('temporal_durable_execution', $execute['records'][0]['last_execution_receipt']['durable_execution_envelope']['source_patterns']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_run_queue_status.v1', $status['schema']);
        $this->assertSame(1, $status['summary']['completed_count']);
        $this->assertSame(5, $status['summary']['dlq_count']);
        $this->assertSame(6, $status['summary']['operating_package_bound_count']);
        $this->assertSame(6, $status['summary']['replay_contract_bound_count']);
        $this->assertSame(6, $status['summary']['durable_execution_envelope_bound_count']);
        $this->assertSame(6, $status['summary']['execution_receipt_count']);
        $this->assertSame(6, $status['summary']['last_execution_receipt_bound_count']);
        $this->assertSame(6, $status['summary']['human_in_loop_bound_count']);
        $this->assertSame(6, $status['summary']['trace_receipt_bound_count']);
        $this->assertFalse((bool) $status['policy']['external_execution_allowed']);

        $completed = AiHoldingEnterpriseFlowRunQueueItem::query()
            ->where('company_id', 'finance')
            ->where('status', 'completed_internal_flow_execution')
            ->first();
        $this->assertNotNull($completed);
        $this->assertSame(1, $completed->attempt_count);
        $this->assertSame(64, strlen((string) $completed->operating_package_hash));
        $this->assertGreaterThanOrEqual(25, (int) data_get($completed->replay_contract_json, 'minimum_cases_before_shadow'));
        $this->assertGreaterThanOrEqual(2, count((array) $completed->operating_package_attestations_json));
        $this->assertSame(64, strlen((string) $completed->last_execution_receipt_hash));
        $completedReceipts = (array) $completed->execution_receipts_json;
        $completedLastReceipt = end($completedReceipts);
        $this->assertSame(64, strlen((string) data_get($completedLastReceipt, 'durable_execution_envelope_hash')));
        $this->assertTrue((bool) data_get($completedLastReceipt, 'checkpoint_resume_bound'));
        $this->assertTrue((bool) data_get($completedLastReceipt, 'human_in_loop_bound'));
        $this->assertTrue((bool) data_get($completedLastReceipt, 'trace_receipt_bound'));
        $this->assertFalse((bool) $completed->external_execution_allowed);

        $dlq = AiHoldingEnterpriseFlowRunQueueItem::query()
            ->where('company_id', 'finance')
            ->where('status', 'dlq_blocked_internal_execution')
            ->first();
        $this->assertNotNull($dlq);
        $this->assertSame('blocked_work_packages_require_operator_or_owner_resolution', $dlq->dlq_reason);
        $this->assertFalse((bool) $dlq->external_side_effects_enabled);

        $replayExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-replay',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $replay = json_decode(Artisan::output(), true);

        $this->assertSame(0, $replayExit);
        $this->assertTrue((bool) $replay['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_run_queue_replay.v1', $replay['schema']);
        $this->assertSame('dlq_replay_cycle_completed_external_execution_blocked', $replay['status']);
        $this->assertSame(5, $replay['summary']['flow_count']);
        $this->assertSame(5, $replay['summary']['dlq_count']);
        $this->assertSame(5, $replay['summary']['operating_package_bound_count']);
        $this->assertSame(5, $replay['summary']['replay_contract_bound_count']);
        $this->assertSame(15, $replay['summary']['operating_package_attestation_count']);
        $this->assertSame(5, $replay['summary']['durable_execution_envelope_bound_count']);
        $this->assertSame(5, $replay['summary']['checkpoint_resume_bound_count']);
        $this->assertSame(5, $replay['summary']['human_in_loop_bound_count']);
        $this->assertSame(5, $replay['summary']['trace_receipt_bound_count']);
        $this->assertSame(0, $replay['summary']['external_execution_allowed_count']);
        $this->assertSame('dlq_blocked_internal_execution_only', $replay['replay_policy']['replay_scope']);
        $this->assertFalse((bool) $replay['replay_policy']['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $replay['flow_run_queue_replay_hash']));

        $replayed = AiHoldingEnterpriseFlowRunQueueItem::query()
            ->where('company_id', 'finance')
            ->where('status', 'dlq_blocked_internal_execution')
            ->get();
        $this->assertCount(5, $replayed);
        $this->assertTrue($replayed->every(static fn (AiHoldingEnterpriseFlowRunQueueItem $item): bool => $item->attempt_count === 2));
        $this->assertTrue($replayed->every(static function (AiHoldingEnterpriseFlowRunQueueItem $item): bool {
            $receipts = $item->execution_receipts_json;
            $last = is_array($receipts) ? end($receipts) : null;

            return is_array($last)
                && ($last['mode'] ?? null) === 'dlq_replay'
                && strlen((string) ($last['operating_package_hash'] ?? '')) === 64
                && (bool) ($last['replay_contract_bound'] ?? false)
                && strlen((string) ($last['durable_execution_envelope_hash'] ?? '')) === 64
                && (bool) ($last['checkpoint_resume_bound'] ?? false)
                && (bool) ($last['human_in_loop_bound'] ?? false)
                && (bool) ($last['trace_receipt_bound'] ?? false);
        }));
    }

    public function test_enterprise_flow_operations_runbook_register_drill_and_status_are_persistent(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalActionMandate::query()->delete();
        AiOperatorApproval::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();
        AiHoldingConnectorActivationRecord::query()->delete();
        AiHoldingEnterpriseFlowRunQueueItem::query()->delete();
        AiHoldingEnterpriseFlowOperationsRunbook::query()->delete();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-probe',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operations-runbook-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $register = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $this->assertTrue((bool) $register['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_operations_runbook_registry.v1', $register['schema']);
        $this->assertSame('registered_needs_operations_drill', $register['status']);
        $this->assertSame(6, $register['summary']['flow_count']);
        $this->assertSame(6, $register['summary']['operating_package_bound_count']);
        $this->assertSame(6, $register['summary']['replay_contract_bound_count']);
        $this->assertSame(6, $register['summary']['operating_package_attestation_count']);
        $this->assertSame(0, $register['summary']['external_execution_allowed_count']);
        $this->assertContains('langgraph_checkpoint_resume_human_in_the_loop', $register['registry_policy']['pattern_sources']);
        $this->assertFalse((bool) $register['registry_policy']['external_execution_allowed']);
        $this->assertSame(6, AiHoldingEnterpriseFlowOperationsRunbook::query()->where('company_id', 'finance')->count());
        $this->assertNotEmpty($register['records'][0]['operating_package_hash']);
        $this->assertTrue((bool) $register['records'][0]['replay_contract_bound']);
        $this->assertGreaterThanOrEqual(25, $register['records'][0]['replay_contract']['minimum_cases_before_shadow']);
        $this->assertContains('enterprise_flow_operating_package_bound', $register['records'][0]['promotion_gates']);

        $drillExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operations-runbook-drill',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $drill = json_decode(Artisan::output(), true);

        $this->assertSame(0, $drillExit);
        $this->assertTrue((bool) $drill['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_operations_runbook_drill.v1', $drill['schema']);
        $this->assertSame('operations_runbook_drill_completed_external_execution_blocked', $drill['status']);
        $this->assertSame(6, $drill['summary']['flow_count']);
        $this->assertSame(6, $drill['summary']['operations_green_count']);
        $this->assertSame(6, $drill['summary']['operating_package_bound_count']);
        $this->assertSame(6, $drill['summary']['replay_contract_bound_count']);
        $this->assertSame(12, $drill['summary']['operating_package_attestation_count']);
        $this->assertSame(0, $drill['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $drill['drill_policy']['external_execution_allowed']);
        $this->assertContains('page_real_oncall', $drill['drill_policy']['blocked_operations']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operations-runbook-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_operations_runbook_status.v1', $status['schema']);
        $this->assertSame(6, $status['summary']['operations_green_count']);
        $this->assertSame(6, $status['summary']['operating_package_bound_count']);
        $this->assertSame(6, $status['summary']['replay_contract_bound_count']);
        $this->assertSame(6, $status['summary']['slo_contract_bound_count']);
        $this->assertSame(6, $status['summary']['incident_route_bound_count']);
        $this->assertSame(6, $status['summary']['reconciliation_contract_bound_count']);
        $this->assertSame(6, $status['summary']['dashboard_binding_bound_count']);
        $this->assertSame(6, $status['summary']['drill_receipt_count']);
        $this->assertSame(6, $status['summary']['last_drill_receipt_bound_count']);
        $this->assertSame(6, $status['summary']['activated_at_bound_count']);
        $this->assertFalse((bool) $status['policy']['external_execution_allowed']);
        $this->assertSame('atlas.ai.holding.flow_operations_runbook_drill_receipt.v1', $status['records'][0]['last_drill_receipt']['schema']);
        $this->assertSame('green', $status['records'][0]['last_drill_receipt']['status']);
        $this->assertTrue((bool) $status['records'][0]['last_drill_receipt']['checks']['slo_contract_present']);
        $this->assertTrue((bool) $status['records'][0]['last_drill_receipt']['checks']['incident_route_present']);
        $this->assertTrue((bool) $status['records'][0]['last_drill_receipt']['checks']['reconciliation_contract_present']);

        $runbook = AiHoldingEnterpriseFlowOperationsRunbook::query()->where('company_id', 'finance')->first();
        $this->assertNotNull($runbook);
        $this->assertSame('operations_runbook_green_external_blocked', $runbook->status);
        $this->assertSame(64, strlen((string) $runbook->operating_package_hash));
        $this->assertGreaterThanOrEqual(25, (int) data_get($runbook->replay_contract_json, 'minimum_cases_before_shadow'));
        $this->assertGreaterThanOrEqual(2, count((array) $runbook->operating_package_attestations_json));
        $this->assertTrue((bool) $runbook->slo_green);
        $this->assertTrue((bool) $runbook->incident_route_green);
        $this->assertTrue((bool) $runbook->reconciliation_green);
        $this->assertTrue((bool) $runbook->promotion_gate_green);
        $this->assertSame(64, strlen((string) $runbook->runbook_hash));
        $this->assertSame(64, strlen((string) $runbook->last_drill_receipt_hash));
        $this->assertFalse((bool) $runbook->external_execution_allowed);
        $this->assertFalse((bool) $runbook->external_side_effects_enabled);
    }

    public function test_operating_packet_status_includes_flow_operations_runbook_evidence(): void
    {
        $this->createDomainRuntimeTables();
        $this->migrateExternalActionMandates();
        AiDomainRuntimeRecord::query()->delete();
        AiHoldingEnterpriseFlowOperationsRunbook::query()->delete();
        AiHoldingEnterpriseFlowRunQueueItem::query()->delete();

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-activation-backlog-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-activation-probe',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-run-queue-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operations-runbook-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operations-runbook-drill',
            '--company' => 'finance',
            '--json' => true,
        ]);
        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-action-runtime-run',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $observeExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'observe-cycle',
            '--json' => true,
        ]);
        $observe = json_decode(Artisan::output(), true);

        $this->assertSame(0, $observeExit);
        $this->assertTrue((bool) $observe['ok']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operating-packet-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_operating_packet_status.v1', $status['schema']);
        $this->assertSame(1, $status['summary']['company_count']);
        $this->assertGreaterThanOrEqual(5, $status['summary']['flow_operations_runbook_evidence_count']);
        $this->assertSame(
            $status['companies'][0]['flow_count'],
            $status['summary']['flow_operations_runbook_evidence_count'],
        );
        $this->assertSame(
            $status['summary']['flow_operations_runbook_evidence_count'],
            $status['summary']['operations_runbook_green_count'],
        );
        $this->assertSame(
            $status['summary']['flow_operations_runbook_evidence_count'],
            $status['summary']['operating_package_bound_evidence_count'],
        );
        $this->assertSame(
            $status['summary']['flow_operations_runbook_evidence_count'],
            $status['summary']['replay_contract_bound_evidence_count'],
        );
        $this->assertSame(
            $status['summary']['flow_operations_runbook_evidence_count'],
            $status['summary']['business_execution_bound_evidence_count'],
        );
        $this->assertSame(0, $status['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $status['policy']['external_execution_allowed']);
        $this->assertSame('finance', $status['companies'][0]['domain_id']);
        $this->assertSame(1.0, (float) $status['companies'][0]['operations_runbook_coverage_rate']);
        $this->assertSame(1.0, (float) $status['companies'][0]['operating_package_evidence_coverage_rate']);
        $this->assertSame(1.0, (float) $status['companies'][0]['replay_contract_evidence_coverage_rate']);
        $this->assertSame(1.0, (float) $status['companies'][0]['business_execution_evidence_coverage_rate']);

        $record = AiDomainRuntimeRecord::query()
            ->where('domain_id', 'finance')
            ->latest('id')
            ->first();
        $this->assertNotNull($record);

        $runbookEvidence = data_get($record->execution_plan, 'operating_packet.flow_operations_runbooks');
        $this->assertIsArray($runbookEvidence);
        $this->assertCount($status['companies'][0]['flow_count'], $runbookEvidence);
        $this->assertSame(
            'atlas.ai.company_flow_operations_runbook_evidence.v1',
            $runbookEvidence[0]['schema'],
        );
        $this->assertSame('operations_runbook_green_external_blocked', $runbookEvidence[0]['runbook_status']);
        $this->assertSame(64, strlen((string) $runbookEvidence[0]['operating_package_hash']));
        $this->assertGreaterThanOrEqual(2, $runbookEvidence[0]['operating_package_attestation_count']);
        $this->assertTrue((bool) $runbookEvidence[0]['replay_contract_bound']);
        $this->assertGreaterThanOrEqual(25, $runbookEvidence[0]['minimum_replay_cases_before_shadow']);
        $this->assertTrue((bool) $runbookEvidence[0]['business_execution_cell_bound']);
        $this->assertNotEmpty($runbookEvidence[0]['business_execution_cell_id']);
        $this->assertTrue((bool) $runbookEvidence[0]['business_kpi_binding_bound']);
        $this->assertTrue((bool) $runbookEvidence[0]['business_service_lane_bound']);
        $this->assertNotEmpty($runbookEvidence[0]['business_service_lane_id']);
        $this->assertTrue((bool) $runbookEvidence[0]['business_artifact_contract_bound']);
        $this->assertSame(64, strlen((string) $runbookEvidence[0]['business_execution_attestation_hash']));
        $this->assertNotEmpty($runbookEvidence[0]['business_execution_runtime_record_uuid']);
        $this->assertFalse((bool) $runbookEvidence[0]['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $runbookEvidence[0]['external_execution_allowed']);
        $this->assertFalse((bool) $runbookEvidence[0]['external_side_effects_enabled']);

        $loopExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operational-execution-loop-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $loop = json_decode(Artisan::output(), true);

        $this->assertSame(0, $loopExit, Artisan::output());
        $this->assertTrue((bool) $loop['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operational_execution_loop_status.v1', $loop['schema']);
        $this->assertSame('enterprise_company_operational_execution_loops_ready_external_effects_blocked', $loop['status']);
        $this->assertSame(1, $loop['summary']['company_count']);
        $this->assertSame(1, $loop['summary']['operational_execution_loop_ready_company_count']);
        $this->assertSame($loop['summary']['required_gate_count'], $loop['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $loop['summary']['average_loop_score']);
        $this->assertFalse((bool) $loop['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $loop['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $loop['policy']['external_side_effects_enabled']);
        $this->assertContains('flow_run_queue', $loop['policy']['required_loop_segments']);
        $this->assertContains('trade', $loop['policy']['blocked_operations']);
        $this->assertTrue((bool) $loop['companies'][0]['operational_execution_loop_ready']);
        $this->assertSame([], $loop['companies'][0]['missing_gates']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['activation_backlog_bound']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['connector_activation_green']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['live_read_connector_ready']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['flow_run_queue_durable']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['flow_operations_runbooks_green']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['business_operating_packet_runtime_ready']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['operational_outcome_runtime_ready']);
        $this->assertTrue((bool) $loop['companies'][0]['gates']['external_effects_blocked']);
        $this->assertGreaterThanOrEqual($loop['companies'][0]['expected_flow_count'], $loop['companies'][0]['loop_counts']['queue_flow_count']);
        $this->assertGreaterThanOrEqual($loop['companies'][0]['expected_flow_count'], $loop['companies'][0]['loop_counts']['runbook_operations_green_count']);
        $this->assertSame(64, strlen((string) $loop['companies'][0]['source_hashes']['flow_run_queue_status_hash']));
        $this->assertSame(64, strlen((string) $loop['companies'][0]['source_hashes']['flow_operations_runbook_status_hash']));
        $this->assertSame(64, strlen((string) $loop['companies'][0]['company_operational_execution_loop_record_hash']));
        $this->assertSame(64, strlen((string) $loop['enterprise_company_operational_execution_loop_status_hash']));

        $acceptanceExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-work-product-acceptance-evidence-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $acceptance = json_decode(Artisan::output(), true);

        $this->assertSame(0, $acceptanceExit, Artisan::output());
        $this->assertTrue((bool) $acceptance['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_work_product_acceptance_evidence_status.v1', $acceptance['schema']);
        $this->assertSame('enterprise_company_work_product_acceptance_evidence_ready_external_delivery_blocked', $acceptance['status']);
        $this->assertSame(1, $acceptance['summary']['company_count']);
        $this->assertSame(1, $acceptance['summary']['work_product_acceptance_ready_company_count']);
        $this->assertSame($acceptance['summary']['required_gate_count'], $acceptance['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $acceptance['summary']['average_acceptance_score']);
        $this->assertFalse((bool) $acceptance['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $acceptance['policy']['external_delivery_allowed']);
        $this->assertFalse((bool) $acceptance['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $acceptance['policy']['external_side_effects_enabled']);
        $this->assertContains('acceptance_contract', $acceptance['policy']['required_evidence']);
        $this->assertContains('skip_operator_acceptance', $acceptance['policy']['blocked_operations']);
        $this->assertTrue((bool) $acceptance['companies'][0]['work_product_acceptance_evidence_ready']);
        $this->assertSame([], $acceptance['companies'][0]['missing_gates']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['work_product_delivery_runtime_complete']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['structural_delivery_contracts_complete']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['flow_quality_research_ready']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['command_center_work_product_factory_ready']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['operational_loop_ready']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['business_packet_delivery_contract_ready']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['operational_outcome_acceptance_ready']);
        $this->assertTrue((bool) $acceptance['companies'][0]['gates']['external_delivery_blocked']);
        $this->assertGreaterThanOrEqual($acceptance['companies'][0]['expected_flow_count'], $acceptance['companies'][0]['evidence_counts']['acceptance_contract_count']);
        $this->assertGreaterThanOrEqual($acceptance['companies'][0]['expected_flow_count'], $acceptance['companies'][0]['evidence_counts']['handoff_packet_count']);
        $this->assertGreaterThanOrEqual($acceptance['companies'][0]['expected_flow_count'], $acceptance['companies'][0]['evidence_counts']['replay_artifact_check_count']);
        $this->assertSame(64, strlen((string) $acceptance['companies'][0]['source_hashes']['flow_work_product_delivery_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $acceptance['companies'][0]['source_hashes']['company_operational_execution_loop_record_hash']));
        $this->assertSame(64, strlen((string) $acceptance['companies'][0]['company_work_product_acceptance_evidence_record_hash']));
        $this->assertSame(64, strlen((string) $acceptance['enterprise_company_work_product_acceptance_evidence_status_hash']));

        $toolExecutionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-tool-execution-readiness-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $toolExecution = json_decode(Artisan::output(), true);

        $this->assertSame(0, $toolExecutionExit, Artisan::output());
        $this->assertTrue((bool) $toolExecution['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_tool_execution_readiness_status.v1', $toolExecution['schema']);
        $this->assertSame('enterprise_company_domain_tool_execution_ready_external_effects_blocked', $toolExecution['status']);
        $this->assertSame(1, $toolExecution['summary']['company_count']);
        $this->assertSame(1, $toolExecution['summary']['tool_execution_ready_company_count']);
        $this->assertSame($toolExecution['summary']['required_gate_count'], $toolExecution['summary']['ready_gate_count']);
        $this->assertSame($toolExecution['summary']['flow_tool_execution_count'], $toolExecution['summary']['ready_flow_tool_execution_count']);
        $this->assertFalse((bool) $toolExecution['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $toolExecution['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $toolExecution['policy']['external_side_effects_enabled']);
        $this->assertContains('runtime_blueprint_permission_matrix', $toolExecution['policy']['required_tool_evidence']);
        $this->assertContains('stdio_shell_without_allowlist', $toolExecution['policy']['blocked_operations']);
        $this->assertTrue((bool) $toolExecution['companies'][0]['tool_execution_ready']);
        $this->assertSame([], $toolExecution['companies'][0]['missing_gates']);
        $this->assertTrue((bool) $toolExecution['companies'][0]['gates']['domain_agent_toolchain_certified']);
        $this->assertTrue((bool) $toolExecution['companies'][0]['gates']['flow_tool_execution_records_cover_flows']);
        $this->assertTrue((bool) $toolExecution['companies'][0]['gates']['external_tool_effects_blocked']);
        $this->assertGreaterThanOrEqual($toolExecution['companies'][0]['expected_flow_count'], $toolExecution['companies'][0]['ready_flow_tool_execution_count']);
        $this->assertSame(64, strlen((string) $toolExecution['companies'][0]['source_hashes']['domain_agent_toolchain_certification_record_hash']));
        $this->assertSame(64, strlen((string) $toolExecution['companies'][0]['company_domain_tool_execution_readiness_record_hash']));
        $this->assertSame(64, strlen((string) $toolExecution['enterprise_company_domain_tool_execution_readiness_status_hash']));

        $toolLedgerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-ledger-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $toolLedger = json_decode(Artisan::output(), true);

        $this->assertSame(0, $toolLedgerExit, Artisan::output());
        $this->assertTrue((bool) $toolLedger['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_ledger_status.v1', $toolLedger['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_ledgers_ready_external_effects_blocked', $toolLedger['status']);
        $this->assertSame(1, $toolLedger['summary']['company_count']);
        $this->assertSame(1, $toolLedger['summary']['flow_tool_execution_ledger_ready_company_count']);
        $this->assertSame($toolLedger['summary']['ledger_record_count'], $toolLedger['summary']['ready_ledger_record_count']);
        $this->assertFalse((bool) $toolLedger['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $toolLedger['policy']['external_side_effects_enabled']);
        $this->assertContains('tool_run_receipt', $toolLedger['policy']['required_ledger_evidence']);
        $this->assertContains('unreceipted_tool_invocation', $toolLedger['policy']['blocked_operations']);
        $this->assertTrue((bool) $toolLedger['companies'][0]['flow_tool_execution_ledger_ready']);
        $this->assertTrue((bool) $toolLedger['companies'][0]['gates']['ledger_records_cover_flows']);
        $this->assertTrue((bool) $toolLedger['companies'][0]['gates']['receipt_chains_cover_ledgers']);
        $this->assertSame(64, strlen((string) data_get($toolLedger, 'companies.0.ledger_records.0.receipt_chain.tool_run_receipt_hash')));
        $this->assertSame(64, strlen((string) $toolLedger['companies'][0]['company_flow_tool_execution_ledger_record_hash']));
        $this->assertSame(64, strlen((string) $toolLedger['enterprise_company_flow_tool_execution_ledger_status_hash']));

        $runtimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-runtime-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $runtimeRegister = json_decode(Artisan::output(), true);

        $this->assertSame(0, $runtimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $runtimeRegister['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_runtime_register.v1', $runtimeRegister['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_runtime_registered_external_effects_blocked', $runtimeRegister['status']);
        $this->assertSame($runtimeRegister['summary']['expected_ledger_record_count'], $runtimeRegister['summary']['registered_run_count']);
        $this->assertSame($toolLedger['summary']['ledger_record_count'], AtlasToolRun::query()->where('surface', 'holding_company_tool_runtime')->count());

        $runtimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-runtime-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $runtimeStatus = json_decode(Artisan::output(), true);

        $this->assertSame(0, $runtimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $runtimeStatus['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_runtime_status.v1', $runtimeStatus['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_runtime_ready_external_effects_blocked', $runtimeStatus['status']);
        $this->assertSame(1, $runtimeStatus['summary']['persisted_runtime_ready_company_count']);
        $this->assertSame($runtimeStatus['summary']['expected_run_count'], $runtimeStatus['summary']['ready_persisted_run_count']);
        $this->assertContains('atlas_tool_run', $runtimeStatus['policy']['required_runtime_evidence']);
        $this->assertTrue((bool) $runtimeStatus['companies'][0]['gates']['persisted_runtime_records_cover_ledgers']);
        $this->assertSame(64, strlen((string) data_get($runtimeStatus, 'companies.0.runtime_records.0.receipt_chain.tool_run_receipt_hash')));
        $this->assertSame(64, strlen((string) $runtimeStatus['enterprise_company_flow_tool_execution_runtime_status_hash']));
    }

    public function test_enterprise_provider_workbench_status_reports_domain_provider_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-provider-workbench-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_provider_workbench_status.v1', $payload['schema']);
        $this->assertSame('provider_workbenches_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['provider_contract_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['connector_workbench_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_provider_route_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['provider_eval_case_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['provider_write_or_paid_action_default']);
        $this->assertContains('trade', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['calendar_wait_removed']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_effects_blocked']);
        $this->assertContains('run_provider_eval_cases', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['provider_workbench_status_hash']));
    }

    public function test_enterprise_agent_repository_adoption_status_reports_research_pipeline_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-agent-repository-adoption-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_agent_repository_adoption_status.v1', $payload['schema']);
        $this->assertSame('agent_repository_adoption_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(11, $payload['summary']['repository_intake_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['framework_scorecard_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_epic_count']);
        $this->assertGreaterThanOrEqual(9, $payload['summary']['version_pin_count']);
        $this->assertGreaterThanOrEqual(2, $payload['summary']['migration_path_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['runtime_use_before_local_contract_tests_allowed']);
        $this->assertContains('auto_procurement', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['migration_matrix_green']);
        $this->assertContains('openai_agents_python', $payload['companies'][0]['repository_ids']);
        $autogen = collect($payload['companies'][0]['framework_states'])->firstWhere('framework_id', 'microsoft_autogen');
        $this->assertIsArray($autogen);
        $this->assertSame('migration_reference_only', $autogen['adoption_state']);
        $this->assertGreaterThanOrEqual(1, $autogen['risk_finding_count']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['agent_repository_adoption_status_hash']));
    }

    public function test_enterprise_agent_repository_operating_catalog_status_reports_runtime_catalog_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-agent-repository-operating-catalog-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_agent_repository_operating_catalog_status.v1', $payload['schema']);
        $this->assertSame('agent_repository_operating_catalog_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(4, $payload['summary']['source_basis_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['framework_profile_count']);
        $this->assertGreaterThanOrEqual(3, $payload['summary']['mcp_security_profile_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_runtime_map_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['supply_chain_artifact_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['mcp_reference_servers_require_security_hardening_before_live_use']);
        $this->assertContains('unhardened_mcp_server_live_use', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['mcp_hardening_required']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['runtime_requires_receipts_and_contract_tests']);
        $this->assertContains('openai_agents_python', $payload['companies'][0]['framework_ids']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['agent_repository_operating_catalog_status_hash']));
    }

    public function test_enterprise_domain_data_fabric_status_reports_source_grounded_data_fabric_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-data-fabric-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_domain_data_fabric_status.v1', $payload['schema']);
        $this->assertSame('domain_data_fabric_ready_external_mutation_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['source_count']);
        $this->assertGreaterThanOrEqual(3, $payload['summary']['provider_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['data_product_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_workbench_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['decision_packet_factory_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['enablement_track_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['direct_source_link_required_for_every_claim']);
        $this->assertTrue((bool) $payload['policy']['cross_source_verification_required']);
        $this->assertFalse((bool) $payload['policy']['external_data_mutation_allowed']);
        $this->assertContains('claim_without_source_link', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['direct_source_links_required']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['cross_source_verification_required']);
        $this->assertFalse((bool) $payload['companies'][0]['external_data_mutation_allowed']);
        $this->assertSame(64, strlen((string) $payload['domain_data_fabric_status_hash']));
    }

    public function test_enterprise_domain_data_connector_operating_status_reports_connector_data_room_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-data-connector-operating-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_domain_data_connector_operating_status.v1', $payload['schema']);
        $this->assertSame('domain_data_connector_operating_ready_external_mutation_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(7, $payload['summary']['source_data_room_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['domain_data_product_count']);
        $this->assertGreaterThanOrEqual(3, $payload['summary']['connector_permission_profile_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_data_connector_contract_count']);
        $this->assertSame($payload['summary']['flow_data_connector_contract_count'], $payload['summary']['ready_flow_data_connector_contract_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_fixture_eval_suite_count']);
        $this->assertSame($payload['summary']['connector_fixture_eval_suite_count'], $payload['summary']['ready_connector_fixture_eval_suite_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['read_only_probe_required_before_live_use']);
        $this->assertFalse((bool) $payload['policy']['write_tools_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_data_mutation_allowed']);
        $this->assertTrue((bool) $payload['policy']['operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action']);
        $this->assertContains('live_connector_without_permission_profile', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['source_data_room_catalog_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_permission_profiles_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_data_connector_contracts_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_fixture_eval_suites_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['read_only_probe_required']);
        $this->assertFalse((bool) $payload['companies'][0]['external_data_mutation_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['write_tools_enabled']);
        $this->assertSame(64, strlen((string) $payload['domain_data_connector_operating_status_hash']));
    }

    public function test_enterprise_flow_live_read_connector_probe_status_reports_probe_scope_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-live-read-connector-probe-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_live_read_connector_probe_status.v1', $payload['schema']);
        $this->assertSame('flow_live_read_connector_probe_ready_external_mutation_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_probe_profile_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_live_read_probe_contract_count']);
        $this->assertSame($payload['summary']['flow_live_read_probe_contract_count'], $payload['summary']['ready_flow_live_read_probe_contract_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_probe_evidence_matrix_count']);
        $this->assertSame($payload['summary']['flow_probe_evidence_matrix_count'], $payload['summary']['ready_flow_probe_evidence_matrix_count']);
        $this->assertGreaterThanOrEqual(0, $payload['summary']['runtime_completed_probe_flow_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['live_read_allowed']);
        $this->assertFalse((bool) $payload['policy']['write_tools_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_mutation_allowed']);
        $this->assertFalse((bool) $payload['policy']['credential_material_in_packet_allowed']);
        $this->assertTrue((bool) $payload['policy']['operator_scope_required_before_live_connector_probe']);
        $this->assertContains('live_probe_without_operator_scope', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_probe_profiles_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_live_read_probe_contracts_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_probe_evidence_matrix_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['operator_scope_required']);
        $this->assertTrue((bool) $payload['companies'][0]['live_read_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['external_mutation_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['credential_material_in_packet_allowed']);
        $this->assertSame(64, strlen((string) $payload['flow_live_read_connector_probe_status_hash']));
    }

    public function test_enterprise_external_research_adoption_status_reports_research_to_runtime_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-research-adoption-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_research_adoption_status.v1', $payload['schema']);
        $this->assertSame('external_research_adoption_ready_external_effects_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(12, $payload['summary']['source_basis_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['official_framework_repository_count']);
        $this->assertGreaterThanOrEqual(3, $payload['summary']['domain_repository_candidate_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_adoption_matrix_count']);
        $this->assertSame($payload['summary']['flow_adoption_matrix_count'], $payload['summary']['ready_flow_adoption_matrix_count']);
        $this->assertGreaterThanOrEqual(12, $payload['summary']['capability_map_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_backlog_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['production_gate_group_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['external_research_is_architecture_input_only']);
        $this->assertFalse((bool) $payload['policy']['runtime_ingestion_without_source_review_allowed']);
        $this->assertFalse((bool) $payload['policy']['repository_adoption_without_license_security_and_fixture_eval_allowed']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertContains('unreviewed_repository_adoption', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['source_basis_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['repository_catalog_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_adoption_matrix_covers_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['operator_mandate_required']);
        $this->assertTrue((bool) $payload['companies'][0]['external_research_is_architecture_input_only']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $payload['external_research_adoption_status_hash']));
    }

    public function test_enterprise_flow_benchmark_replay_status_reports_replay_quality_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-benchmark-replay-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_benchmark_replay_status.v1', $payload['schema']);
        $this->assertSame('flow_benchmark_replay_ready_external_benchmark_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['expected_flow_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['offline_dataset_contract_count']);
        $this->assertSame($payload['summary']['offline_dataset_contract_count'], $payload['summary']['ready_offline_dataset_contract_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['trace_grading_rubric_count']);
        $this->assertSame($payload['summary']['trace_grading_rubric_count'], $payload['summary']['ready_trace_grading_rubric_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['adversarial_regression_case_count']);
        $this->assertSame($payload['summary']['adversarial_regression_case_count'], $payload['summary']['ready_adversarial_regression_case_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['deterministic_state_assertion_count']);
        $this->assertSame($payload['summary']['deterministic_state_assertion_count'], $payload['summary']['ready_deterministic_state_assertion_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['replay_comparison_matrix_count']);
        $this->assertSame($payload['summary']['replay_comparison_matrix_count'], $payload['summary']['ready_replay_comparison_matrix_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['benchmark_observability_metric_count']);
        $this->assertSame(0, $payload['summary']['external_benchmark_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_benchmark_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $payload['policy']['promotion_without_replay_green_allowed']);
        $this->assertContains('external_benchmark_execution', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['offline_dataset_contracts_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['trace_grading_rubrics_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['adversarial_regression_cases_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['deterministic_state_assertions_cover_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['replay_comparison_matrix_covers_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['synthetic_score_claims_blocked']);
        $this->assertFalse((bool) $payload['companies'][0]['external_benchmark_execution_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['promotion_without_replay_green_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $payload['flow_benchmark_replay_status_hash']));
    }

    public function test_enterprise_connector_certification_preflight_status_reports_connector_cutover_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-connector-certification-preflight-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_connector_certification_preflight_status.v1', $payload['schema']);
        $this->assertSame('connector_certification_preflight_ready_external_cutover_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['expected_flow_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['adapter_contract_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['auth_boundary_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['sandbox_probe_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['contract_test_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['data_lineage_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['flow_connector_usage_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['replay_fixture_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['slo_failure_mode_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['certification_metric_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['production_preflight_contract_count']);
        $this->assertSame($payload['summary']['expected_flow_count'], $payload['summary']['flow_cutover_matrix_count']);
        $this->assertSame($payload['summary']['connector_count'], $payload['summary']['production_evidence_register_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['cutover_metric_count']);
        $this->assertSame(0, $payload['summary']['external_connector_cutover_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_connector_cutover_allowed']);
        $this->assertFalse((bool) $payload['policy']['write_or_paid_mode_allowed_by_default']);
        $this->assertFalse((bool) $payload['policy']['real_credential_material_in_packet_allowed']);
        $this->assertFalse((bool) $payload['policy']['production_cutover_without_operator_signed_scope_allowed']);
        $this->assertContains('real_credential_material_in_packet', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['adapter_contracts_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['auth_boundaries_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['sandbox_probes_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['contract_tests_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['data_lineage_covers_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_usage_covers_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['production_contracts_cover_connectors']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_cutover_matrix_covers_flows']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['real_credential_material_blocked']);
        $this->assertFalse((bool) $payload['companies'][0]['external_connector_cutover_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['write_or_paid_mode_allowed_by_default']);
        $this->assertFalse((bool) $payload['companies'][0]['real_credential_material_in_packet_allowed']);
        $this->assertFalse((bool) $payload['companies'][0]['external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $payload['connector_certification_preflight_status_hash']));
    }

    public function test_enterprise_industry_solution_ecosystem_status_reports_solution_ecosystem_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-industry-solution-ecosystem-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_industry_solution_ecosystem_status.v1', $payload['schema']);
        $this->assertSame('industry_solution_ecosystem_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['ecosystem_provider_count']);
        $this->assertGreaterThanOrEqual(7, $payload['summary']['implementation_partner_track_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_workload_pack_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['source_verification_matrix_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['compliance_workload_control_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['partner_handoff_count']);
        $this->assertSame(1, $payload['summary']['data_interface_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['audit_control_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertSame('claude_financial_services_style_industry_solution_adapted_per_company', $payload['policy']['reference_pattern']);
        $this->assertTrue((bool) $payload['policy']['unified_data_interface_required']);
        $this->assertTrue((bool) $payload['policy']['direct_source_hyperlinks_required']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertContains('auto_procurement', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['source_links_required']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['audit_trail_required']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_source_verification_matrix_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_compliance_workload_controls_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['implementation_partner_handoff_green']);
        $this->assertContains('anthropic_financial_services', $payload['companies'][0]['provider_ids']);
        $this->assertContains('accenture_scale_adoption', $payload['companies'][0]['partner_track_ids']);
        $this->assertContains('market_research_brief', $payload['companies'][0]['workload_flow_ids']);
        $this->assertContains('market_research_brief', $payload['companies'][0]['source_verification_flow_ids']);
        $this->assertContains('market_research_brief', $payload['companies'][0]['compliance_control_flow_ids']);
        $this->assertContains('market_research_brief', $payload['companies'][0]['partner_handoff_flow_ids']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['industry_solution_ecosystem_status_hash']));
    }

    public function test_enterprise_business_operating_backbone_status_reports_company_backbone_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-business-operating-backbone-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_business_operating_backbone_status.v1', $payload['schema']);
        $this->assertSame('business_operating_backbone_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertSame(12, $payload['summary']['ready_component_count']);
        $this->assertSame(12, $payload['summary']['required_component_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['customer_offer_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['account_contract_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['vendor_due_diligence_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['resilience_exercise_count']);
        $this->assertGreaterThanOrEqual(3, $payload['summary']['analytics_dashboard_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['semantic_graph_node_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['grc_audit_evidence_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertContains('customer_market_operations', $payload['policy']['required_backbone_components']);
        $this->assertContains('semantic_operating_graph', $payload['policy']['required_backbone_components']);
        $this->assertContains('sign_contract', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertSame(12, $payload['companies'][0]['ready_component_count']);
        $this->assertSame(12, $payload['companies'][0]['required_component_count']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['customer_market_operations_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['account_contract_delivery_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['vendor_legal_procurement_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['resilience_continuity_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['analytics_decision_intelligence_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['knowledge_memory_learning_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['identity_access_data_sovereignty_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['control_tower_run_operations_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['semantic_operating_graph_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['delivery_assurance_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['unit_economics_capacity_simulation_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['grc_green']);
        $this->assertContains('export_semantic_operating_graph_snapshot', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['business_operating_backbone_status_hash']));
    }

    public function test_enterprise_production_connector_preflight_status_reports_cutover_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-production-connector-preflight-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_production_connector_preflight_status.v1', $payload['schema']);
        $this->assertSame('production_connector_preflight_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['connector_preflight_contract_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_cutover_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['production_evidence_register_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['cutover_metric_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['production_cutover_without_operator_signed_scope_allowed']);
        $this->assertFalse((bool) $payload['policy']['real_credential_material_in_packet_allowed']);
        $this->assertContains('auto_cutover', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_preflight_contracts_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['operator_signed_scope_required']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['no_real_credentials_in_packet']);
        $this->assertContains('collect_signed_production_scope', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['production_connector_preflight_status_hash']));
    }

    public function test_enterprise_flow_quality_research_status_reports_benchmark_and_solution_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-quality-research-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_quality_research_status.v1', $payload['schema']);
        $this->assertSame('flow_quality_research_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['offline_dataset_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['trace_rubric_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['adversarial_case_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['deterministic_assertion_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['replay_matrix_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['tooling_benchmark_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['domain_solution_module_count']);
        $this->assertGreaterThanOrEqual(12, $payload['summary']['external_research_source_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $payload['policy']['promotion_without_replay_green_allowed']);
        $this->assertContains('promotion_without_replay', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['offline_datasets_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['adversarial_cases_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['tooling_research_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['domain_solution_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_research_green']);
        $this->assertContains('run_offline_replay_suite_per_flow', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['flow_quality_research_status_hash']));
    }

    public function test_enterprise_company_command_center_status_reports_operating_console_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-command-center-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_command_center_status.v1', $payload['schema']);
        $this->assertSame('company_command_center_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['operating_cell_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_command_card_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_workbench_panel_count']);
        $this->assertGreaterThanOrEqual(4, $payload['summary']['operator_console_view_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['work_product_factory_count']);
        $this->assertGreaterThanOrEqual(11, $payload['summary']['command_center_kpi_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['secret_material_in_packet_allowed']);
        $this->assertContains('real_spend', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['command_center_schema_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_command_cards_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_workbench_panels_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['command_center_kpis_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_execution_blocked']);
        $this->assertContains('operate_flow_cards_from_command_center', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['company_command_center_status_hash']));
    }

    public function test_enterprise_flow_operating_package_status_reports_per_flow_package_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-flow-operating-package-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_operating_package_status.v1', $payload['schema']);
        $this->assertSame('flow_operating_package_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(10, $payload['summary']['source_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_package_count']);
        $this->assertSame($payload['summary']['flow_package_count'], $payload['summary']['package_hash_count']);
        $this->assertSame($payload['summary']['flow_package_count'], $payload['summary']['replay_contract_count']);
        $this->assertSame($payload['summary']['flow_package_count'], $payload['summary']['operations_contract_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['metric_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['package_required_for_every_flow']);
        $this->assertSame(25, $payload['policy']['minimum_replay_cases_before_shadow']);
        $this->assertContains('live_trade', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_operating_package_stack_schema_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['source_basis_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['package_for_every_flow_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['replay_contracts_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['operations_contracts_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_execution_blocked']);
        $this->assertSame(64, strlen((string) $payload['companies'][0]['package_hashes'][0]));
        $this->assertContains('run_package_fixture_replays', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['flow_operating_package_status_hash']));
    }

    public function test_enterprise_vertical_solution_suite_status_reports_domain_solution_suites(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-vertical-solution-suite-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_vertical_solution_suite_status.v1', $payload['schema']);
        $this->assertSame('vertical_solution_suite_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['source_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['solution_suite_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_solution_kit_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['connector_workbench_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['artifact_factory_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['evaluation_recipe_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['metric_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertSame('claude_financial_services_unified_domain_solution_generalized_to_every_company', $payload['policy']['reference_pattern']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['flow_kit_required_for_every_flow']);
        $this->assertContains('auto_procurement', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['vertical_solution_suite_schema_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_solution_kits_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['connector_workbenches_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['artifact_factories_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_execution_blocked']);
        $this->assertContains('financial_research_terminal_suite', $payload['companies'][0]['suite_ids']);
        $this->assertContains('investment_committee_modeling_suite', $payload['companies'][0]['suite_ids']);
        $this->assertContains('anthropic_financial_services', $payload['companies'][0]['source_ids']);
        $this->assertContains('operate_vertical_solution_suites', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['vertical_solution_suite_status_hash']));
    }

    public function test_enterprise_domain_business_execution_mesh_status_reports_flow_execution_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-business-execution-mesh-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_domain_business_execution_mesh_status.v1', $payload['schema']);
        $this->assertSame('domain_business_execution_mesh_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(4, $payload['summary']['execution_mode_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['execution_cell_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['flow_kpi_binding_count']);
        $this->assertGreaterThanOrEqual(6, $payload['summary']['service_lane_count']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['artifact_delivery_contract_count']);
        $this->assertGreaterThanOrEqual(8, $payload['summary']['metric_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['domain_specific_execution_required_for_every_flow']);
        $this->assertTrue((bool) $payload['policy']['kpi_contract_required_for_every_flow']);
        $this->assertContains('live_trade', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['business_execution_mesh_schema_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['execution_cells_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['flow_kpi_bindings_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['service_lanes_green']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_execution_blocked']);
        $this->assertContains('financial_research_terminal', $payload['companies'][0]['execution_mode_ids']);
        $this->assertContains('operate_domain_business_execution_cells', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['domain_business_execution_mesh_status_hash']));
    }

    public function test_enterprise_operational_dress_rehearsal_status_reports_rehearsal_gates(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-operational-dress-rehearsal-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_operational_dress_rehearsal_status.v1', $payload['schema']);
        $this->assertSame('operational_dress_rehearsal_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertSame(1, $payload['summary']['ready_company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_rehearsal_runbook_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['live_read_probe_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['operator_acceptance_packet_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['rollback_drill_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['promotion_evidence_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['external_mutation_allowed_during_rehearsal']);
        $this->assertContains('offensive_security', $payload['policy']['blocked_operations']);
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['ready']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['calendar_wait_removed']);
        $this->assertTrue((bool) $payload['companies'][0]['checks']['external_mutation_blocked']);
        $this->assertContains('execute_non_production_rehearsal', $payload['companies'][0]['next_actions']);
        $this->assertFalse((bool) $payload['companies'][0]['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $payload['operational_dress_rehearsal_status_hash']));
    }

    public function test_enterprise_real_external_execution_readiness_dossier_reports_remaining_real_world_gates(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-real-external-execution-readiness-dossier',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_real_external_execution_readiness_dossier.v1', $payload['schema']);
        $this->assertSame('real_external_execution_dossier_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame(1, $payload['summary']['provider_ready_company_count']);
        $this->assertSame(1, $payload['summary']['agent_repository_ready_company_count']);
        $this->assertSame(1, $payload['summary']['industry_solution_ecosystem_ready_company_count']);
        $this->assertSame(1, $payload['summary']['business_operating_backbone_ready_company_count']);
        $this->assertSame(1, $payload['summary']['production_connector_preflight_ready_company_count']);
        $this->assertSame(1, $payload['summary']['flow_quality_research_ready_company_count']);
        $this->assertSame(1, $payload['summary']['vertical_solution_suite_ready_company_count']);
        $this->assertSame(1, $payload['summary']['flow_operating_package_ready_company_count']);
        $this->assertSame(1, $payload['summary']['company_command_center_ready_company_count']);
        $this->assertSame(1, $payload['summary']['dress_rehearsal_ready_company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['live_read_connector_ready_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['dossier_is_not_execution_authority']);
        $this->assertContains('agent_repository_adoption_pipeline_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('industry_solution_ecosystem_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('business_operating_backbone_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('production_connector_preflight_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('flow_quality_research_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('vertical_solution_suite_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('flow_operating_package_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('company_command_center_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('live_read_connector_readiness_ready', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('vault_scope_attestation', $payload['policy']['required_before_real_external_execution']);
        $this->assertContains('secret_export', $payload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $payload['source_hashes']['agent_repository_adoption_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['industry_solution_ecosystem_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['business_operating_backbone_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['production_connector_preflight_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['flow_quality_research_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['vertical_solution_suite_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['flow_operating_package_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['company_command_center_status_hash']));
        $this->assertSame(64, strlen((string) $payload['source_hashes']['live_read_connector_readiness_status_hash']));
        $this->assertSame('finance', $payload['companies'][0]['company_id']);
        $this->assertTrue((bool) $payload['companies'][0]['provider_workbench_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['agent_repository_adoption_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['industry_solution_ecosystem_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['business_operating_backbone_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['production_connector_preflight_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['flow_quality_research_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['vertical_solution_suite_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['flow_operating_package_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['company_command_center_ready']);
        $this->assertTrue((bool) $payload['companies'][0]['operational_dress_rehearsal_ready']);
        $flow = $payload['companies'][0]['flow_dossiers'][0];
        $this->assertSame('atlas.ai.company.flow_real_external_execution_readiness_dossier.v1', $flow['schema']);
        $this->assertTrue((bool) $flow['provider_workbench_ready']);
        $this->assertTrue((bool) $flow['agent_repository_adoption_ready']);
        $this->assertTrue((bool) $flow['industry_solution_ecosystem_ready']);
        $this->assertTrue((bool) $flow['business_operating_backbone_ready']);
        $this->assertTrue((bool) $flow['production_connector_preflight_ready']);
        $this->assertTrue((bool) $flow['flow_quality_research_ready']);
        $this->assertTrue((bool) $flow['vertical_solution_suite_ready']);
        $this->assertTrue((bool) $flow['flow_operating_package_ready']);
        $this->assertTrue((bool) $flow['company_command_center_ready']);
        $this->assertTrue((bool) $flow['operational_dress_rehearsal_ready']);
        $this->assertTrue((bool) $flow['connector_activation_probe_green']);
        $this->assertTrue((bool) $flow['live_read_connector_readiness_green']);
        $this->assertTrue((bool) $flow['vault_binding_attested']);
        $this->assertTrue((bool) $flow['slo_monitor_bound']);
        $this->assertTrue((bool) $flow['reconciliation_bound']);
        $this->assertTrue((bool) $flow['handoff_pack_ready']);
        $this->assertFalse((bool) $flow['manual_handoff_ready']);
        $this->assertFalse((bool) $flow['manual_handoff_candidate']);
        $this->assertContains('operator_and_second_reviewer_signed_mandate_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('agent_repository_adoption_pipeline_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('industry_solution_ecosystem_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('business_operating_backbone_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('production_connector_preflight_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('flow_quality_research_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('vertical_solution_suite_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('flow_operating_package_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('company_command_center_not_ready', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('credential_vault_binding_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('sandbox_probe_receipt_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('connector_slo_monitor_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('post_execution_reconciliation_adapter_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('production_scope_contract_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('legal_or_risk_scope_acceptance_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('budget_or_loss_cap_signature_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('manual_execution_owner_assignment_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('recurring_schedule_binding_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('run_queue_worker_binding_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('customer_or_stakeholder_acceptance_loop_missing', $flow['missing_before_real_external_execution']);
        $this->assertNotContains('live_read_connector_readiness_not_green', $flow['missing_before_real_external_execution']);
        $this->assertContains('operator_signature_receipt', $flow['required_evidence']);
        $this->assertContains('agent_repository_adoption_status_hash', $flow['required_evidence']);
        $this->assertContains('repository_version_pin_and_fixture_eval_receipt', $flow['required_evidence']);
        $this->assertContains('industry_solution_ecosystem_status_hash', $flow['required_evidence']);
        $this->assertContains('industry_solution_audit_and_confidentiality_attestation', $flow['required_evidence']);
        $this->assertContains('business_operating_backbone_status_hash', $flow['required_evidence']);
        $this->assertContains('customer_account_vendor_grc_backbone_attestation', $flow['required_evidence']);
        $this->assertContains('semantic_operating_graph_export_receipt', $flow['required_evidence']);
        $this->assertContains('unit_economics_and_capacity_simulation_receipt', $flow['required_evidence']);
        $this->assertContains('production_connector_preflight_status_hash', $flow['required_evidence']);
        $this->assertContains('production_connector_vault_scope_and_signed_cutover_receipt', $flow['required_evidence']);
        $this->assertContains('production_connector_rollback_drill_green_receipt', $flow['required_evidence']);
        $this->assertContains('flow_quality_research_status_hash', $flow['required_evidence']);
        $this->assertContains('offline_replay_trace_grade_receipt', $flow['required_evidence']);
        $this->assertContains('adversarial_and_deterministic_assertion_receipt', $flow['required_evidence']);
        $this->assertContains('tooling_benchmark_and_domain_solution_receipt', $flow['required_evidence']);
        $this->assertContains('vertical_solution_suite_status_hash', $flow['required_evidence']);
        $this->assertContains('vertical_solution_flow_kit_replay_receipt', $flow['required_evidence']);
        $this->assertContains('vertical_solution_artifact_factory_receipt', $flow['required_evidence']);
        $this->assertContains('flow_operating_package_status_hash', $flow['required_evidence']);
        $this->assertContains('flow_operating_package_replay_contract_receipt', $flow['required_evidence']);
        $this->assertContains('flow_operating_package_runbook_drill_receipt', $flow['required_evidence']);
        $this->assertContains('company_command_center_status_hash', $flow['required_evidence']);
        $this->assertContains('flow_command_card_and_operator_console_receipt', $flow['required_evidence']);
        $this->assertContains('connector_panel_and_pause_protocol_receipt', $flow['required_evidence']);
        $this->assertContains('live_read_connector_readiness_status_hash', $flow['required_evidence']);
        $this->assertContains('schema_snapshot_and_sample_payload_receipt', $flow['required_evidence']);
        $this->assertContains('read_only_vault_scope_reference_receipt', $flow['required_evidence']);
        $this->assertGreaterThanOrEqual(1, $flow['live_read_connector_count']);
        $this->assertSame($flow['live_read_connector_count'], $flow['live_read_ready_count']);
        $this->assertSame('atlas.ai.company.flow_real_external_execution_handoff_pack.v1', $flow['real_external_execution_handoff_pack']['schema']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['ready_for_manual_handoff_gate']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['repository_adoption_contract']['ready']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['repository_adoption_contract']['runtime_use_without_local_contract_tests_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['industry_solution_ecosystem_contract']['ready']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['industry_solution_ecosystem_contract']['external_contracting_allowed_by_stack']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['business_operating_backbone_contract']['ready']);
        $this->assertSame(
            $flow['real_external_execution_handoff_pack']['business_operating_backbone_contract']['required_component_count'],
            $flow['real_external_execution_handoff_pack']['business_operating_backbone_contract']['ready_component_count'],
        );
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['business_operating_backbone_contract']['external_customer_vendor_billing_or_capital_action_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['production_connector_preflight_contract']['ready']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['production_connector_preflight_contract']['auto_cutover_allowed']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['production_connector_preflight_contract']['real_credential_material_in_packet_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['flow_quality_research_contract']['ready']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['flow_quality_research_contract']['synthetic_score_claims_allowed']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['flow_quality_research_contract']['promotion_without_replay_green_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['vertical_solution_suite_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $flow['real_external_execution_handoff_pack']['vertical_solution_suite_contract']['solution_suite_count']);
        $this->assertGreaterThanOrEqual(6, $flow['real_external_execution_handoff_pack']['vertical_solution_suite_contract']['flow_solution_kit_count']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['vertical_solution_suite_contract']['external_execution_allowed_by_suite']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['flow_operating_package_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $flow['real_external_execution_handoff_pack']['flow_operating_package_contract']['flow_package_count']);
        $this->assertSame(25, $flow['real_external_execution_handoff_pack']['flow_operating_package_contract']['minimum_replay_cases_before_shadow']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['flow_operating_package_contract']['external_execution_allowed_by_package']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['company_command_center_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $flow['real_external_execution_handoff_pack']['company_command_center_contract']['operating_cell_count']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['company_command_center_contract']['external_write_spend_trade_publish_deploy_delete_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['live_read_connector_readiness_contract']['ready']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['live_read_connector_readiness_contract']['credential_material_in_packet_allowed']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['live_read_connector_readiness_contract']['external_write_allowed']);
        $this->assertTrue((bool) $flow['real_external_execution_handoff_pack']['production_scope_contract']['requires_operator_signature']);
        $this->assertFalse((bool) $flow['real_external_execution_handoff_pack']['external_execution_allowed']);
        $this->assertFalse((bool) $flow['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $flow['flow_dossier_hash']));
        $this->assertSame(64, strlen((string) $payload['real_external_execution_readiness_dossier_hash']));
    }

    public function test_enterprise_real_external_execution_handoff_pack_reports_manual_contracts_without_auto_execution(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-real-external-execution-handoff-pack',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_real_external_execution_handoff_pack.v1', $payload['schema']);
        $this->assertSame('real_external_execution_handoff_pack_ready_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['production_scope_contract_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['legal_risk_acceptance_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['budget_loss_cap_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['manual_owner_assignment_count']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['handoff_pack_is_not_execution_authority']);

        $pack = $payload['companies'][0]['flow_handoff_packs'][0];
        $this->assertSame('atlas.ai.company.flow_real_external_execution_handoff_pack.v1', $pack['schema']);
        $this->assertTrue((bool) $pack['ready_for_manual_handoff_gate']);
        $this->assertTrue((bool) $pack['repository_adoption_contract']['ready']);
        $this->assertGreaterThanOrEqual(11, $pack['repository_adoption_contract']['repository_intake_count']);
        $this->assertFalse((bool) $pack['repository_adoption_contract']['auto_upgrade_or_procurement_allowed']);
        $this->assertTrue((bool) $pack['industry_solution_ecosystem_contract']['ready']);
        $this->assertGreaterThanOrEqual(5, $pack['industry_solution_ecosystem_contract']['ecosystem_provider_count']);
        $this->assertTrue((bool) $pack['industry_solution_ecosystem_contract']['requires_direct_source_links_audit_trail_and_confidentiality_attestation']);
        $this->assertTrue((bool) $pack['business_operating_backbone_contract']['ready']);
        $this->assertSame(
            $pack['business_operating_backbone_contract']['required_component_count'],
            $pack['business_operating_backbone_contract']['ready_component_count'],
        );
        $this->assertFalse((bool) $pack['business_operating_backbone_contract']['external_customer_vendor_billing_or_capital_action_allowed']);
        $this->assertTrue((bool) $pack['production_connector_preflight_contract']['ready']);
        $this->assertGreaterThanOrEqual(1, $pack['production_connector_preflight_contract']['connector_preflight_contract_count']);
        $this->assertFalse((bool) $pack['production_connector_preflight_contract']['auto_cutover_allowed']);
        $this->assertTrue((bool) $pack['flow_quality_research_contract']['ready']);
        $this->assertGreaterThanOrEqual(1, $pack['flow_quality_research_contract']['offline_dataset_count']);
        $this->assertFalse((bool) $pack['flow_quality_research_contract']['promotion_without_replay_green_allowed']);
        $this->assertTrue((bool) $pack['vertical_solution_suite_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $pack['vertical_solution_suite_contract']['solution_suite_count']);
        $this->assertGreaterThanOrEqual(6, $pack['vertical_solution_suite_contract']['flow_solution_kit_count']);
        $this->assertGreaterThanOrEqual(5, $pack['vertical_solution_suite_contract']['artifact_factory_count']);
        $this->assertFalse((bool) $pack['vertical_solution_suite_contract']['external_execution_allowed_by_suite']);
        $this->assertTrue((bool) $pack['flow_operating_package_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $pack['flow_operating_package_contract']['flow_package_count']);
        $this->assertGreaterThanOrEqual(10, $pack['flow_operating_package_contract']['source_count']);
        $this->assertSame(25, $pack['flow_operating_package_contract']['minimum_replay_cases_before_shadow']);
        $this->assertFalse((bool) $pack['flow_operating_package_contract']['external_execution_allowed_by_package']);
        $this->assertTrue((bool) $pack['company_command_center_contract']['ready']);
        $this->assertGreaterThanOrEqual(6, $pack['company_command_center_contract']['operating_cell_count']);
        $this->assertGreaterThanOrEqual(1, $pack['company_command_center_contract']['flow_command_card_count']);
        $this->assertFalse((bool) $pack['company_command_center_contract']['secret_material_in_packet_allowed']);
        $this->assertTrue((bool) $pack['live_read_connector_readiness_contract']['ready']);
        $this->assertTrue((bool) $pack['live_read_connector_readiness_contract']['requires_schema_snapshot_sample_payload_provider_lineage_and_vault_scope_reference']);
        $this->assertFalse((bool) $pack['live_read_connector_readiness_contract']['credential_material_in_packet_allowed']);
        $this->assertFalse((bool) $pack['live_read_connector_readiness_contract']['external_write_allowed']);
        $this->assertSame('manual_operator_execution_only', $pack['production_scope_contract']['scope_mode']);
        $this->assertTrue((bool) $pack['production_scope_contract']['requires_second_reviewer_signature']);
        $this->assertSame('prepared_requires_real_signatures', $pack['legal_risk_acceptance_packet']['acceptance_status']);
        $this->assertFalse((bool) $pack['budget_or_loss_cap_packet']['spend_without_cap_allowed']);
        $this->assertFalse((bool) $pack['manual_execution_owner_assignment']['auto_owner_assignment_allowed']);
        $this->assertFalse((bool) $pack['run_queue_worker_binding']['external_action_worker_enabled']);
        $this->assertFalse((bool) $pack['customer_or_stakeholder_acceptance_loop']['external_customer_commitment_allowed']);
        $this->assertFalse((bool) $pack['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $pack['handoff_pack_hash']));
        $this->assertSame(64, strlen((string) $payload['real_external_execution_handoff_pack_hash']));
    }

    public function test_enterprise_supervised_external_execution_packet_status_reports_ready_packets_without_worker(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-supervised-external-execution-packet-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_supervised_external_execution_packet_status.v1', $payload['schema']);
        $this->assertSame('supervised_external_execution_packets_ready_external_worker_disabled', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['packet_ready_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['operator_signature_required_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['second_reviewer_required_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['production_scope_contract_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['runtime_control_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['post_execution_reconciliation_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $payload['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['policy']['external_worker_enabled']);
        $this->assertTrue((bool) $payload['policy']['packet_is_execution_plan_not_execution_authority']);
        $this->assertFalse((bool) $payload['policy']['credential_material_in_packet_allowed']);
        $this->assertContains('secret_export', $payload['policy']['blocked_operations']);

        $packet = $payload['companies'][0]['flow_packets'][0];
        $this->assertSame('atlas.ai.company.supervised_external_execution_packet.v1', $packet['schema']);
        $this->assertTrue((bool) $packet['packet_ready']);
        $this->assertSame('manual_or_supervised_window_after_real_signatures_external_worker_disabled', $packet['execution_mode']);
        $this->assertTrue((bool) $packet['operator_signature_required']);
        $this->assertTrue((bool) $packet['second_reviewer_required']);
        $this->assertSame('manual_operator_execution_only', $packet['production_scope']['scope_mode']);
        $this->assertContains('auto_execute', $packet['production_scope']['blocked_in_autonomous_suite']);
        $this->assertTrue((bool) $packet['runtime_control']['kill_switch_bound']);
        $this->assertFalse((bool) $packet['runtime_control']['external_worker_enabled']);
        $this->assertFalse((bool) $packet['runtime_control']['auto_retry_external_action_allowed']);
        $this->assertContains('signed_operator_scope', $packet['pre_execution_checklist']);
        $this->assertContains('rollback_or_compensation_drill_green', $packet['pre_execution_checklist']);
        $this->assertTrue((bool) $packet['post_execution_reconciliation']['bound']);
        $this->assertFalse((bool) $packet['post_execution_reconciliation']['external_result_claim_allowed_without_receipt']);
        $this->assertFalse((bool) $packet['external_execution_allowed']);
        $this->assertFalse((bool) $packet['credential_material_in_packet_allowed']);
        $this->assertSame(64, strlen((string) $packet['packet_hash']));
        $this->assertSame(64, strlen((string) $payload['supervised_external_execution_packet_status_hash']));
    }

    public function test_enterprise_external_worker_preflight_status_reports_worker_controls_without_dispatch(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-worker-preflight-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_worker_preflight_status.v1', $payload['schema']);
        $this->assertSame('external_worker_preflight_ready_execution_disabled', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['worker_preflight_ready_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['worker_plan_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['execution_envelope_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['credential_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['worker_controls_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['post_execution_reconciliation_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['external_worker_dispatch_disabled_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['external_worker_dispatch_enabled']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['preflight_is_not_execution_authority']);
        $this->assertContains('auto_dispatch', $payload['policy']['blocked_operations']);

        $preflight = $payload['companies'][0]['flow_worker_preflights'][0];
        $this->assertSame('atlas.ai.company.external_worker_preflight.v1', $preflight['schema']);
        $this->assertTrue((bool) $preflight['worker_preflight_ready']);
        $this->assertSame('prepared_supervised_external_worker_dispatch_disabled', $preflight['dispatch_mode']);
        $this->assertTrue((bool) $preflight['worker_plan']['bound']);
        $this->assertContains('external_worker_dispatch_disabled_by_policy', $preflight['worker_plan']['dispatch_blockers']);
        $this->assertTrue((bool) $preflight['execution_envelope']['decision_receipt_hash_required']);
        $this->assertTrue((bool) $preflight['execution_envelope']['idempotency_key_required']);
        $this->assertSame(64, strlen((string) $preflight['execution_envelope']['idempotency_key']));
        $this->assertTrue((bool) $preflight['credential_gate']['bound']);
        $this->assertFalse((bool) $preflight['credential_gate']['credential_material_in_packet_allowed']);
        $this->assertFalse((bool) $preflight['worker_controls']['external_worker_dispatch_enabled']);
        $this->assertTrue((bool) $preflight['worker_controls']['kill_switch_bound']);
        $this->assertFalse((bool) $preflight['worker_controls']['auto_retry_external_action_allowed']);
        $this->assertTrue((bool) $preflight['post_execution_reconciliation']['bound']);
        $this->assertContains('operator_closeout', $preflight['post_execution_reconciliation']['required_artifacts']);
        $this->assertFalse((bool) $preflight['post_execution_reconciliation']['external_result_claim_allowed_without_receipt']);
        $this->assertFalse((bool) $preflight['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $preflight['worker_preflight_hash']));
        $this->assertSame(64, strlen((string) $payload['external_worker_preflight_status_hash']));
    }

    public function test_enterprise_external_worker_dispatch_plan_status_reports_supervised_launch_plan_without_dispatch(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-worker-dispatch-plan-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_worker_dispatch_plan_status.v1', $payload['schema']);
        $this->assertSame('external_worker_dispatch_plan_ready_supervised_launch_disabled', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['dispatch_plan_ready_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['operator_launch_sequence_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['signature_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['vault_scope_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['execution_receipt_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['dispatch_disabled_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['external_worker_dispatch_enabled']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['dispatch_plan_is_not_execution_authority']);
        $this->assertFalse((bool) $payload['policy']['launch_without_signed_receipts_allowed']);
        $this->assertContains('auto_launch', $payload['policy']['blocked_operations']);

        $plan = $payload['companies'][0]['flow_dispatch_plans'][0];
        $this->assertSame('atlas.ai.company.external_worker_dispatch_plan.v1', $plan['schema']);
        $this->assertTrue((bool) $plan['dispatch_plan_ready']);
        $this->assertSame('operator_supervised_launch_packet_prepared_dispatch_disabled', $plan['launch_mode']);
        $this->assertFalse((bool) $plan['external_worker_dispatch_enabled']);
        $this->assertTrue((bool) $plan['operator_launch_sequence']['bound']);
        $this->assertFalse((bool) $plan['operator_launch_sequence']['autonomous_suite_may_launch']);
        $this->assertContains('bind_real_credential_vault_reference', $plan['operator_launch_sequence']['checklist']);
        $this->assertTrue((bool) $plan['signature_gate']['operator_signature_receipt_required']);
        $this->assertTrue((bool) $plan['signature_gate']['second_reviewer_signature_receipt_required']);
        $this->assertTrue((bool) $plan['vault_scope_gate']['vault_reference_required']);
        $this->assertFalse((bool) $plan['vault_scope_gate']['credential_material_in_packet_allowed']);
        $this->assertTrue((bool) $plan['execution_receipt_gate']['decision_receipt_hash_required']);
        $this->assertSame(64, strlen((string) $plan['execution_receipt_gate']['idempotency_key']));
        $this->assertFalse((bool) $plan['worker_runtime_contract']['auto_retry_external_action_allowed']);
        $this->assertContains('supervised_launch_disabled_by_policy', $plan['dispatch_blockers']);
        $this->assertFalse((bool) $plan['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $plan['source_worker_preflight_hash']));
        $this->assertSame(64, strlen((string) $plan['dispatch_plan_hash']));
        $this->assertSame(64, strlen((string) $payload['external_worker_dispatch_plan_status_hash']));
    }

    public function test_enterprise_external_launch_control_status_reports_go_no_go_controls_without_launch(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-launch-control-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_launch_control_status.v1', $payload['schema']);
        $this->assertSame('external_launch_control_ready_launch_disabled', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['launch_control_ready_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['go_no_go_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['human_authority_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['credential_release_gate_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['reconciliation_sink_bound_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['launch_disabled_count']);
        $this->assertGreaterThan($payload['summary']['flow_count'], $payload['summary']['missing_external_receipt_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $payload['policy']['launch_enabled']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['launch_control_is_not_execution_authority']);
        $this->assertContains('launch', $payload['policy']['blocked_operations']);

        $control = $payload['companies'][0]['flow_launch_controls'][0];
        $this->assertSame('atlas.ai.company.external_launch_control.v1', $control['schema']);
        $this->assertTrue((bool) $control['launch_control_ready']);
        $this->assertFalse((bool) $control['launch_enabled']);
        $this->assertSame('go_no_go_control_prepared_external_launch_disabled', $control['launch_mode']);
        $this->assertSame('no_go_until_real_receipts_bound', $control['go_no_go_gate']['decision']);
        $this->assertContains('operator', $control['go_no_go_gate']['required_decision_makers']);
        $this->assertContains('external_launch_disabled_by_policy', $control['go_no_go_gate']['blockers']);
        $this->assertTrue((bool) $control['human_authority_gate']['operator_go_required']);
        $this->assertFalse((bool) $control['human_authority_gate']['autonomous_override_allowed']);
        $this->assertTrue((bool) $control['credential_release_gate']['vault_reference_required']);
        $this->assertFalse((bool) $control['credential_release_gate']['runtime_secret_material_export_allowed']);
        $this->assertTrue((bool) $control['reconciliation_sink']['tool_receipts_required']);
        $this->assertFalse((bool) $control['reconciliation_sink']['claim_without_receipt_allowed']);
        $this->assertContains('operator_closeout_receipt', $control['required_external_receipts']);
        $this->assertContains('operator_closeout_receipt', $control['missing_external_receipts']);
        $this->assertFalse((bool) $control['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $control['source_dispatch_plan_hash']));
        $this->assertSame(64, strlen((string) $control['external_launch_control_hash']));
        $this->assertSame(64, strlen((string) $payload['external_launch_control_status_hash']));
    }

    public function test_enterprise_external_receipt_binding_status_reports_required_slots_without_fake_binding(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-receipt-binding-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_receipt_binding_status.v1', $payload['schema']);
        $this->assertSame('external_receipt_binding_ready_launch_disabled', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['receipt_binder_ready_count']);
        $this->assertGreaterThan($payload['summary']['flow_count'], $payload['summary']['receipt_slot_count']);
        $this->assertSame($payload['summary']['receipt_slot_count'], $payload['summary']['required_receipt_count']);
        $this->assertSame(0, $payload['summary']['bound_receipt_count']);
        $this->assertSame($payload['summary']['required_receipt_count'], $payload['summary']['missing_receipt_count']);
        $this->assertFalse((bool) $payload['policy']['launch_enabled']);
        $this->assertFalse((bool) $payload['policy']['synthetic_receipts_count_as_real_external_authority']);
        $this->assertTrue((bool) $payload['policy']['receipt_binding_is_not_execution_authority']);
        $this->assertContains('bind_fake_receipt_as_real', $payload['policy']['blocked_operations']);

        $binder = $payload['companies'][0]['flow_receipt_binders'][0];
        $this->assertSame('atlas.ai.company.external_receipt_binder.v1', $binder['schema']);
        $this->assertTrue((bool) $binder['receipt_binder_ready']);
        $this->assertSame('receipt_slots_prepared_no_external_receipts_bound', $binder['binding_mode']);
        $this->assertFalse((bool) $binder['launch_enabled']);
        $this->assertTrue((bool) $binder['binding_policy']['real_receipt_source_required']);
        $this->assertFalse((bool) $binder['binding_policy']['synthetic_receipts_allowed_for_external_authority']);
        $this->assertSame(64, strlen((string) $binder['source_external_launch_control_hash']));

        $slot = $binder['receipt_slots'][0];
        $this->assertSame('atlas.ai.company.external_receipt_slot.v1', $slot['schema']);
        $this->assertTrue((bool) $slot['required']);
        $this->assertFalse((bool) $slot['bound']);
        $this->assertNull($slot['receipt_hash']);
        $this->assertSame('real_external_receipt_not_bound', $slot['binding_blocker']);
        $this->assertFalse((bool) $slot['fake_or_synthetic_receipt_allowed']);
        $this->assertTrue((bool) $slot['binding_preconditions']['launch_control_hash_present']);
        $this->assertSame(64, strlen((string) $slot['receipt_slot_hash']));
        $this->assertSame(64, strlen((string) $binder['external_receipt_binder_hash']));
        $this->assertSame(64, strlen((string) $payload['external_receipt_binding_status_hash']));
    }

    public function test_enterprise_external_supervised_cutover_dossier_status_reports_cutover_blocked_by_missing_receipts(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-dossier-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_dossier_status.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_dossier_ready_cutover_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['cutover_dossier_ready_count']);
        $this->assertSame(0, $payload['summary']['supervised_cutover_enabled_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertGreaterThan($payload['summary']['flow_count'], $payload['summary']['missing_receipt_count']);
        $this->assertSame($payload['summary']['receipt_slot_count'], $payload['summary']['required_receipt_count']);
        $this->assertSame(0, $payload['summary']['bound_receipt_count']);
        $this->assertFalse((bool) $payload['policy']['supervised_cutover_enabled']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['cutover_dossier_is_not_execution_authority']);
        $this->assertSame('missing_real_external_receipts', $payload['policy']['cutover_blocker']);
        $this->assertContains('bind_all_real_external_receipts', $payload['policy']['required_before_cutover']);
        $this->assertContains('cutover', $payload['policy']['blocked_operations']);

        $dossier = $payload['companies'][0]['flow_cutover_dossiers'][0];
        $this->assertSame('atlas.ai.company.external_supervised_cutover_dossier.v1', $dossier['schema']);
        $this->assertTrue((bool) $dossier['cutover_dossier_ready']);
        $this->assertSame('blocked_missing_real_external_receipts', $dossier['cutover_decision']);
        $this->assertFalse((bool) $dossier['supervised_cutover_enabled']);
        $this->assertFalse((bool) $dossier['external_execution_allowed']);
        $this->assertContains('operator', $dossier['operator_cutover_packet']['required_decision_makers']);
        $this->assertContains('decision_receipt_hash', $dossier['operator_cutover_packet']['required_receipt_ids']);
        $this->assertContains('decision_receipt_hash', $dossier['operator_cutover_packet']['missing_receipt_ids']);
        $this->assertTrue((bool) $dossier['operator_cutover_packet']['change_window_required']);
        $this->assertTrue((bool) $dossier['operator_cutover_packet']['no_fake_receipt_allowed']);
        $this->assertContains('bind_all_real_external_receipts', $dossier['operator_cutover_packet']['cutover_runbook_steps']);
        $this->assertSame($dossier['readiness_evidence']['receipt_slot_count'], $dossier['readiness_evidence']['required_receipt_count']);
        $this->assertSame(0, $dossier['readiness_evidence']['bound_receipt_count']);
        $this->assertGreaterThan(0, $dossier['readiness_evidence']['missing_receipt_count']);
        $this->assertTrue((bool) $dossier['readiness_evidence']['all_receipt_slots_required']);
        $this->assertTrue((bool) $dossier['readiness_evidence']['launch_disabled']);
        $this->assertContains('missing_real_external_receipts', $dossier['promotion_blockers']);
        $this->assertTrue((bool) $dossier['cutover_policy']['dossier_is_not_execution_authority']);
        $this->assertFalse((bool) $dossier['cutover_policy']['autonomous_suite_may_cutover']);
        $this->assertSame(64, strlen((string) $dossier['readiness_evidence']['source_external_receipt_binder_hash']));
        $this->assertSame(64, strlen((string) $dossier['external_supervised_cutover_dossier_hash']));
        $this->assertSame(64, strlen((string) $payload['external_supervised_cutover_dossier_status_hash']));
    }

    public function test_enterprise_external_supervised_cutover_work_order_status_reports_operator_work_queue_without_launch(): void
    {
        $this->migrateExternalActionMandates();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_work_order_status.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_work_orders_ready_launch_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['company_count']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['flow_count']);
        $this->assertSame($payload['summary']['flow_count'], $payload['summary']['work_order_ready_count']);
        $this->assertGreaterThan($payload['summary']['flow_count'], $payload['summary']['work_item_count']);
        $this->assertSame($payload['summary']['work_item_count'], $payload['summary']['pending_real_receipt_item_count']);
        $this->assertGreaterThan(0, $payload['summary']['receipt_intake_item_count']);
        $this->assertGreaterThan(0, $payload['summary']['vault_scope_item_count']);
        $this->assertGreaterThan(0, $payload['summary']['reconciliation_item_count']);
        $this->assertSame(0, $payload['summary']['executable_item_count']);
        $this->assertSame(0, $payload['summary']['supervised_cutover_enabled_count']);
        $this->assertFalse((bool) $payload['policy']['supervised_cutover_enabled']);
        $this->assertFalse((bool) $payload['policy']['calendar_wait_blocker_enabled']);
        $this->assertTrue((bool) $payload['policy']['work_order_is_not_execution_authority']);
        $this->assertTrue((bool) $payload['policy']['enterprise_pattern_generalized_from_financial_services']['unified_data_and_tool_workbench_required']);
        $this->assertTrue((bool) $payload['policy']['enterprise_pattern_generalized_from_financial_services']['source_link_verification_required']);
        $this->assertContains('execute_work_order', $payload['policy']['blocked_operations']);

        $workOrder = $payload['companies'][0]['flow_cutover_work_orders'][0];
        $this->assertSame('atlas.ai.company.external_supervised_cutover_work_order.v1', $workOrder['schema']);
        $this->assertTrue((bool) $workOrder['work_order_ready']);
        $this->assertSame('receipt_intake_and_operator_cutover_preparation', $workOrder['phase']);
        $this->assertSame('launch_blocked_until_work_items_have_real_receipts', $workOrder['cutover_decision']);
        $this->assertFalse((bool) $workOrder['supervised_cutover_enabled']);
        $this->assertFalse((bool) $workOrder['external_execution_allowed']);
        $this->assertCount(8, $workOrder['work_items']);
        $this->assertContains('work_items_pending_real_receipts', $workOrder['launch_blockers']);
        $this->assertTrue((bool) $workOrder['operator_enablement_pack']['unified_workbench_required']);
        $this->assertTrue((bool) $workOrder['operator_enablement_pack']['audit_trail_required']);

        $item = $workOrder['work_items'][0];
        $this->assertSame('atlas.ai.company.external_supervised_cutover_work_item.v1', $item['schema']);
        $this->assertSame('pending_real_receipt_or_operator_action', $item['status']);
        $this->assertFalse((bool) $item['executable']);
        $this->assertFalse((bool) $item['external_execution_allowed']);
        $this->assertContains('real_receipt_hash', $item['completion_requires']);
        $this->assertContains('execute', $item['blocked_operations']);
        $this->assertSame(64, strlen((string) $item['external_supervised_cutover_work_item_hash']));
        $this->assertSame(64, strlen((string) $workOrder['source_external_supervised_cutover_dossier_hash']));
        $this->assertSame(64, strlen((string) $workOrder['external_supervised_cutover_work_order_hash']));
        $this->assertSame(64, strlen((string) $payload['external_supervised_cutover_work_order_status_hash']));
    }

    public function test_enterprise_external_supervised_cutover_work_order_register_persists_operator_queue_without_launch(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_work_order_registry.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_work_orders_registered_launch_blocked', $payload['status']);
        $this->assertSame(6, $payload['summary']['work_order_count']);
        $this->assertSame(48, $payload['summary']['work_item_count']);
        $this->assertSame(48, $payload['summary']['pending_work_item_count']);
        $this->assertSame(0, $payload['summary']['executable_item_count']);
        $this->assertSame(0, $payload['summary']['bound_receipt_count']);
        $this->assertFalse((bool) $payload['registry_policy']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['registry_policy']['registry_does_not_enable_launch']);
        $this->assertContains('real_receipt_hash', $payload['registry_policy']['required_before_item_completion']);
        $this->assertSame(6, AiHoldingExternalCutoverWorkOrder::query()->where('company_id', 'finance')->count());
        $this->assertSame(48, AiHoldingExternalCutoverWorkItem::query()->where('company_id', 'finance')->count());

        $order = AiHoldingExternalCutoverWorkOrder::query()->where('company_id', 'finance')->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkOrder::class, $order);
        $this->assertSame('pending_real_receipts_launch_blocked', $order->status);
        $this->assertFalse((bool) $order->supervised_cutover_enabled);
        $this->assertFalse((bool) $order->external_execution_allowed);
        $this->assertSame(8, $order->work_item_count);
        $this->assertSame(8, $order->pending_work_item_count);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-persisted-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $statusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertTrue((bool) $statusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_work_order_persisted_status.v1', $statusPayload['schema']);
        $this->assertSame('external_supervised_cutover_work_orders_persisted_launch_blocked', $statusPayload['status']);
        $this->assertSame(6, $statusPayload['summary']['work_order_count']);
        $this->assertSame(48, $statusPayload['summary']['work_item_count']);
        $this->assertSame(48, $statusPayload['summary']['pending_work_item_count']);
        $this->assertSame(0, $statusPayload['summary']['executable_item_count']);
        $this->assertTrue((bool) $statusPayload['policy']['persisted_status_does_not_enable_launch']);
        $this->assertSame(64, strlen((string) $statusPayload['external_supervised_cutover_work_order_persisted_status_hash']));
    }

    public function test_enterprise_external_supervised_cutover_work_item_bind_receipt_marks_item_done_without_launch(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $item = AiHoldingExternalCutoverWorkItem::query()
            ->where('company_id', 'finance')
            ->where('status', 'pending_real_receipt_or_operator_action')
            ->orderBy('action_id')
            ->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkItem::class, $item);

        $receiptHash = hash('sha256', 'finance.real.operator.receipt.1');
        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
            '--work-item' => $item->work_item_id,
            '--receipt-hash' => $receiptHash,
            '--receipt-source' => 'evidence_ledger',
            '--operator' => 'atlas_operator',
            '--note' => 'operator attached real receipt hash',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_work_item_receipt_binding.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_work_item_receipt_bound_launch_blocked', $payload['status']);
        $this->assertSame($receiptHash, $payload['work_item']['bound_receipt_hash']);
        $this->assertSame('receipt_bound_operator_action_completed', $payload['work_item']['status']);
        $this->assertSame('evidence_ledger', $payload['work_item']['receipt_binding']['receipt_source']);
        $this->assertSame(64, strlen((string) $payload['work_item']['receipt_binding_hash']));
        $this->assertFalse((bool) $payload['work_item']['executable']);
        $this->assertFalse((bool) $payload['work_item']['external_execution_allowed']);
        $this->assertSame(1, $payload['work_order']['bound_receipt_count']);
        $this->assertSame(7, $payload['work_order']['pending_work_item_count']);
        $this->assertFalse((bool) $payload['work_order']['external_execution_allowed']);
        $this->assertTrue((bool) $payload['policy']['receipt_binding_is_not_execution_authority']);

        $item->refresh();
        $this->assertSame('receipt_bound_operator_action_completed', $item->status);
        $this->assertSame($receiptHash, $item->bound_receipt_hash);
        $this->assertSame(64, strlen((string) $item->receipt_binding_hash));
        $this->assertFalse((bool) $item->external_execution_allowed);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-persisted-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $statusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertSame(1, $statusPayload['summary']['bound_receipt_count']);
        $this->assertSame(47, $statusPayload['summary']['pending_work_item_count']);
        $this->assertSame(0, $statusPayload['summary']['external_execution_allowed_count']);

        $rejectedExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
            '--work-item' => $item->work_item_id,
            '--receipt-hash' => 'bad-hash',
            '--receipt-source' => 'evidence_ledger',
            '--json' => true,
        ]);
        $rejectedPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $rejectedExit);
        $this->assertFalse((bool) $rejectedPayload['ok']);
        $this->assertSame('external_supervised_cutover_work_item_receipt_binding_rejected', $rejectedPayload['status']);
        $this->assertSame('invalid_receipt_hash', $rejectedPayload['reason']);
    }

    public function test_enterprise_external_supervised_cutover_promotion_status_requires_all_receipts_and_keeps_launch_blocked(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', 'finance')
            ->orderBy('flow_id')
            ->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkOrder::class, $order);

        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $order->work_order_id)
            ->orderBy('action_id')
            ->get();
        $this->assertCount(8, $items);

        foreach ($items as $index => $item) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
                '--work-item' => $item->work_item_id,
                '--receipt-hash' => hash('sha256', 'finance.promotion.receipt.'.$index),
                '--receipt-source' => 'evidence_ledger',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-promotion-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_promotion_status.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_promotion_waiting_on_receipts', $payload['status']);
        $this->assertSame(6, $payload['summary']['work_order_count']);
        $this->assertSame(1, $payload['summary']['promotion_review_ready_count']);
        $this->assertSame(5, $payload['summary']['receipt_incomplete_order_count']);
        $this->assertSame(48, $payload['summary']['work_item_count']);
        $this->assertSame(8, $payload['summary']['bound_receipt_count']);
        $this->assertSame(40, $payload['summary']['pending_work_item_count']);
        $this->assertSame(0, $payload['summary']['external_execution_allowed_count']);
        $this->assertTrue((bool) $payload['policy']['promotion_status_is_not_execution_authority']);
        $this->assertTrue((bool) $payload['policy']['operator_go_no_go_required']);
        $this->assertFalse((bool) $payload['policy']['external_execution_allowed']);

        $packet = null;
        foreach ($payload['companies'][0]['flow_promotion_packets'] as $candidate) {
            if ($candidate['work_order_id'] === $order->work_order_id) {
                $packet = $candidate;
                break;
            }
        }

        $this->assertIsArray($packet);
        $this->assertTrue((bool) $packet['promotion_review_ready']);
        $this->assertSame('all_work_item_receipts_bound_operator_go_no_go_required', $packet['promotion_state']);
        $this->assertSame(8, $packet['bound_receipt_count']);
        $this->assertSame(0, $packet['pending_work_item_count']);
        $this->assertSame([], $packet['missing_work_item_ids']);
        $this->assertContains('operator_go_no_go_receipt', $packet['required_final_authorities']);
        $this->assertContains('rollback_plan_drill_receipt', $packet['required_final_authorities']);
        $this->assertTrue((bool) $packet['enterprise_operating_pattern']['skills_or_runbooks_bound']);
        $this->assertTrue((bool) $packet['enterprise_operating_pattern']['source_link_verification_bound']);
        $this->assertContains('operator_go_no_go_receipt_missing', $packet['promotion_blockers']);
        $this->assertContains('rollback_plan_drill_receipt_missing', $packet['promotion_blockers']);
        $this->assertFalse((bool) $packet['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $packet['external_supervised_cutover_promotion_packet_hash']));
        $this->assertSame(64, strlen((string) $payload['external_supervised_cutover_promotion_status_hash']));
    }

    public function test_enterprise_external_supervised_cutover_final_authority_receipts_bind_after_work_items_without_launch(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverRuntimeInvocation::query()->delete();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();
        AiHoldingEnterpriseFlowOperationsRunbook::query()->delete();
        AiHoldingEnterpriseFlowRunQueueItem::query()->delete();
        AiHoldingConnectorActivationRecord::query()->delete();
        AiHoldingActivationBacklogItem::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', 'finance')
            ->orderBy('flow_id')
            ->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkOrder::class, $order);

        $earlyExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-final-authority-bind-receipt',
            '--work-order' => $order->work_order_id,
            '--authority' => 'operator_go_no_go_receipt',
            '--receipt-hash' => hash('sha256', 'finance.final-authority.too-early'),
            '--receipt-source' => 'operator_console',
            '--json' => true,
        ]);
        $earlyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $earlyExit);
        $this->assertSame('external_supervised_cutover_final_authority_binding_rejected', $earlyPayload['status']);
        $this->assertSame('work_items_still_pending_receipts', $earlyPayload['reason']);

        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $order->work_order_id)
            ->orderBy('action_id')
            ->get();
        $this->assertCount(8, $items);

        foreach ($items as $index => $item) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
                '--work-item' => $item->work_item_id,
                '--receipt-hash' => hash('sha256', 'finance.final-authority.work-item.'.$index),
                '--receipt-source' => 'evidence_ledger',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        $authorities = [
            'operator_go_no_go_receipt',
            'second_reviewer_go_no_go_receipt',
            'vault_scope_release_receipt',
            'connector_scope_release_receipt',
            'change_window_open_receipt',
            'kill_switch_armed_receipt',
            'reconciliation_sink_confirmed_receipt',
            'rollback_plan_drill_receipt',
        ];

        foreach ($authorities as $index => $authority) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-final-authority-bind-receipt',
                '--work-order' => $order->work_order_id,
                '--authority' => $authority,
                '--receipt-hash' => hash('sha256', 'finance.final-authority.'.$authority.'.'.$index),
                '--receipt-source' => 'operator_console',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exit);
            $this->assertTrue((bool) $payload['ok']);
            $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_final_authority_binding.v1', $payload['schema']);
            $this->assertSame('external_supervised_cutover_final_authority_receipt_bound_launch_blocked', $payload['status']);
            $this->assertSame($authority, $payload['binding']['authority_id']);
            $this->assertSame($index + 1, $payload['summary']['final_authority_binding_count']);
            $this->assertFalse((bool) $payload['work_order']['external_execution_allowed']);
            $this->assertTrue((bool) $payload['policy']['final_authority_binding_is_not_execution_authority']);
        }

        $order->refresh();
        $this->assertSame('final_authorities_bound_manual_cutover_packet_ready_launch_still_blocked', $order->status);
        $this->assertSame(8, $order->final_authority_binding_count);
        $this->assertFalse((bool) $order->external_execution_allowed);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-promotion-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $statusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertSame(8, $statusPayload['summary']['final_authority_binding_count']);
        $this->assertSame(40, $statusPayload['summary']['missing_final_authority_count']);
        $this->assertSame(0, $statusPayload['summary']['external_execution_allowed_count']);

        $packet = null;
        foreach ($statusPayload['companies'][0]['flow_promotion_packets'] as $candidate) {
            if ($candidate['work_order_id'] === $order->work_order_id) {
                $packet = $candidate;
                break;
            }
        }

        $this->assertIsArray($packet);
        $this->assertSame(8, $packet['final_authority_binding_count']);
        $this->assertSame(0, $packet['missing_final_authority_count']);
        $this->assertSame([], $packet['missing_final_authorities']);
        $this->assertSame([], $packet['promotion_blockers']);
        $this->assertFalse((bool) $packet['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $packet['external_supervised_cutover_promotion_packet_hash']));
    }

    public function test_enterprise_external_supervised_cutover_runtime_invocation_registers_after_final_authorities_without_launch(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverRuntimeInvocation::query()->delete();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', 'finance')
            ->orderBy('flow_id')
            ->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkOrder::class, $order);

        $earlyExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $earlyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $earlyExit);
        $this->assertSame('external_supervised_cutover_runtime_invocation_rejected', $earlyPayload['status']);
        $this->assertSame('work_items_still_pending_receipts', $earlyPayload['reason']);

        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $order->work_order_id)
            ->orderBy('action_id')
            ->get();

        foreach ($items as $index => $item) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
                '--work-item' => $item->work_item_id,
                '--receipt-hash' => hash('sha256', 'finance.runtime-invocation.work-item.'.$index),
                '--receipt-source' => 'evidence_ledger',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        $missingAuthorityExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $missingAuthorityPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $missingAuthorityExit);
        $this->assertSame('final_authority_receipts_missing', $missingAuthorityPayload['reason']);
        $this->assertContains('operator_go_no_go_receipt', $missingAuthorityPayload['missing_final_authorities']);

        foreach ([
            'operator_go_no_go_receipt',
            'second_reviewer_go_no_go_receipt',
            'vault_scope_release_receipt',
            'connector_scope_release_receipt',
            'change_window_open_receipt',
            'kill_switch_armed_receipt',
            'reconciliation_sink_confirmed_receipt',
            'rollback_plan_drill_receipt',
        ] as $index => $authority) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-final-authority-bind-receipt',
                '--work-order' => $order->work_order_id,
                '--authority' => $authority,
                '--receipt-hash' => hash('sha256', 'finance.runtime-invocation.authority.'.$authority.'.'.$index),
                '--receipt-source' => 'operator_console',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_runtime_invocation_registry.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_runtime_invocation_registered_launch_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['runtime_invocation_count']);
        $this->assertSame(8, $payload['summary']['final_authority_binding_count']);
        $this->assertFalse((bool) $payload['record']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['record']['external_side_effects_enabled']);
        $this->assertTrue((bool) $payload['record']['operator_runtime_contract']['decision_receipt_required']);
        $this->assertTrue((bool) $payload['record']['operator_runtime_contract']['post_execution_reconciliation_required']);
        $this->assertFalse((bool) $payload['record']['operator_runtime_contract']['auto_launch_allowed']);
        $this->assertContains('unattended_cutover', $payload['record']['blocked_operations']);
        $this->assertTrue((bool) $payload['policy']['runtime_invocation_packet_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $payload['record']['runtime_invocation_packet_hash']));
        $this->assertSame(1, AiHoldingExternalCutoverRuntimeInvocation::query()->where('company_id', 'finance')->count());

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $statusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_runtime_invocation_status.v1', $statusPayload['schema']);
        $this->assertSame(1, $statusPayload['summary']['runtime_invocation_count']);
        $this->assertSame(0, $statusPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $statusPayload['policy']['external_execution_allowed']);
    }

    public function test_enterprise_external_supervised_cutover_runtime_rehearsal_execute_records_receipt_without_external_side_effects(): void
    {
        $this->migrateExternalActionMandates();
        AiHoldingExternalCutoverRuntimeInvocation::query()->delete();
        AiHoldingExternalCutoverWorkItem::query()->delete();
        AiHoldingExternalCutoverWorkOrder::query()->delete();

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
                '--company' => 'finance',
                '--json' => true,
            ]);
        }

        Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-work-order-register',
            '--company' => 'finance',
            '--json' => true,
        ]);

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', 'finance')
            ->orderBy('flow_id')
            ->first();
        $this->assertInstanceOf(AiHoldingExternalCutoverWorkOrder::class, $order);

        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $order->work_order_id)
            ->orderBy('action_id')
            ->get();

        foreach ($items as $index => $item) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-work-item-bind-receipt',
                '--work-item' => $item->work_item_id,
                '--receipt-hash' => hash('sha256', 'finance.runtime-rehearsal.work-item.'.$index),
                '--receipt-source' => 'evidence_ledger',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        foreach ([
            'operator_go_no_go_receipt',
            'second_reviewer_go_no_go_receipt',
            'vault_scope_release_receipt',
            'connector_scope_release_receipt',
            'change_window_open_receipt',
            'kill_switch_armed_receipt',
            'reconciliation_sink_confirmed_receipt',
            'rollback_plan_drill_receipt',
        ] as $index => $authority) {
            $exit = Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => 'enterprise-external-supervised-cutover-final-authority-bind-receipt',
                '--work-order' => $order->work_order_id,
                '--authority' => $authority,
                '--receipt-hash' => hash('sha256', 'finance.runtime-rehearsal.authority.'.$authority.'.'.$index),
                '--receipt-source' => 'operator_console',
                '--operator' => 'atlas_operator',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
        }

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $registerPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $invocationId = (string) $registerPayload['record']['invocation_id'];

        $preRehearsalPromotionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-rehearsal-promotion-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $preRehearsalPromotionPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $preRehearsalPromotionExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_rehearsal_promotion_status.v1', $preRehearsalPromotionPayload['schema']);
        $this->assertSame('external_supervised_cutover_rehearsal_promotion_waiting_on_evidence', $preRehearsalPromotionPayload['status']);
        $this->assertSame(6, $preRehearsalPromotionPayload['summary']['flow_count']);
        $this->assertSame(0, $preRehearsalPromotionPayload['summary']['manual_execution_packet_ready_count']);
        $this->assertSame(6, $preRehearsalPromotionPayload['summary']['manual_execution_packet_blocked_count']);
        $this->assertSame(1, $preRehearsalPromotionPayload['summary']['runtime_invocation_count']);
        $this->assertSame(0, $preRehearsalPromotionPayload['summary']['execution_receipt_count']);
        $this->assertTrue((bool) $preRehearsalPromotionPayload['policy']['rehearsal_promotion_status_is_not_execution_authority']);
        $this->assertFalse((bool) $preRehearsalPromotionPayload['policy']['external_execution_allowed']);

        $earlyHandoffExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-handoff-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $earlyHandoffPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $earlyHandoffExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_handoff_registry.v1', $earlyHandoffPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_handoff_registration_rejected', $earlyHandoffPayload['status']);
        $this->assertSame('manual_handoff_packet_not_ready_for_work_order', $earlyHandoffPayload['reason']);
        $this->assertFalse((bool) $earlyHandoffPayload['policy']['external_execution_allowed']);

        $emptyHandoffStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-handoff-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $emptyHandoffStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $emptyHandoffStatusExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_handoff_status.v1', $emptyHandoffStatusPayload['schema']);
        $this->assertSame('empty_external_supervised_cutover_manual_handoff_registry', $emptyHandoffStatusPayload['status']);
        $this->assertSame(0, $emptyHandoffStatusPayload['summary']['manual_handoff_packet_count']);
        $this->assertFalse((bool) $emptyHandoffStatusPayload['policy']['external_execution_allowed']);

        $earlyCloseoutExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-closeout-bind-receipt',
            '--work-order' => $order->work_order_id,
            '--receipt-hash' => hash('sha256', 'finance.closeout.too-early'),
            '--receipt-source' => 'operator_console',
            '--operator' => 'atlas_operator',
            '--json' => true,
        ]);
        $earlyCloseoutPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $earlyCloseoutExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_closeout_binding.v1', $earlyCloseoutPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_closeout_binding_rejected', $earlyCloseoutPayload['status']);
        $this->assertSame('manual_handoff_packet_missing', $earlyCloseoutPayload['reason']);
        $this->assertFalse((bool) $earlyCloseoutPayload['policy']['external_execution_allowed']);

        $exit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-rehearsal-execute',
            '--invocation' => $invocationId,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_runtime_rehearsal_execution.v1', $payload['schema']);
        $this->assertSame('external_supervised_cutover_runtime_rehearsal_executed_external_execution_blocked', $payload['status']);
        $this->assertSame(1, $payload['summary']['execution_receipt_count']);
        $this->assertSame($invocationId, $payload['receipt']['invocation_id']);
        $this->assertSame('non_production_rehearsal', $payload['receipt']['mode']);
        $this->assertSame(64, strlen((string) $payload['receipt']['execution_receipt_hash']));
        $this->assertFalse((bool) $payload['receipt']['external_execution_attempted']);
        $this->assertFalse((bool) $payload['receipt']['external_side_effects_attempted']);
        $this->assertFalse((bool) $payload['receipt']['connector_mutation_attempted']);
        $this->assertTrue((bool) $payload['receipt']['reconciliation']['post_execution_reconciliation_required']);
        $this->assertFalse((bool) $payload['receipt']['reconciliation']['external_result_claim_allowed']);
        $this->assertSame(1, $payload['record']['execution_receipt_count']);
        $this->assertSame($payload['receipt']['execution_receipt_hash'], $payload['record']['last_execution_receipt_hash']);
        $this->assertFalse((bool) $payload['record']['external_execution_allowed']);
        $this->assertFalse((bool) $payload['record']['external_side_effects_enabled']);
        $this->assertTrue((bool) $payload['policy']['rehearsal_is_not_real_external_execution']);

        $statusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $statusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $statusExit);
        $this->assertSame(1, $statusPayload['summary']['runtime_invocation_count']);
        $this->assertSame(1, $statusPayload['summary']['rehearsal_executed_count']);
        $this->assertSame(1, $statusPayload['summary']['execution_receipt_count']);
        $this->assertSame(0, $statusPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $statusPayload['policy']['external_execution_allowed']);

        $batchExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-rehearsal-execute',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $batchPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $batchExit);
        $this->assertSame(1, $batchPayload['summary']['runtime_invocation_count']);
        $this->assertSame(1, $batchPayload['summary']['execution_receipt_count']);
        $this->assertCount(1, $batchPayload['records']);
        $this->assertCount(1, $batchPayload['receipts']);
        $this->assertFalse((bool) $batchPayload['records'][0]['external_execution_allowed']);

        $batchStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-runtime-invocation-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $batchStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $batchStatusExit);
        $this->assertSame(1, $batchStatusPayload['summary']['rehearsal_executed_count']);
        $this->assertSame(2, $batchStatusPayload['summary']['execution_receipt_count']);

        $promotionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-rehearsal-promotion-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $promotionPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $promotionExit);
        $this->assertSame('external_supervised_cutover_rehearsal_promotion_waiting_on_evidence', $promotionPayload['status']);
        $this->assertSame(1, $promotionPayload['summary']['manual_execution_packet_ready_count']);
        $this->assertSame(5, $promotionPayload['summary']['manual_execution_packet_blocked_count']);
        $this->assertSame(1, $promotionPayload['summary']['rehearsal_executed_count']);
        $this->assertSame(2, $promotionPayload['summary']['execution_receipt_count']);
        $this->assertFalse((bool) $promotionPayload['policy']['external_execution_allowed']);

        $packet = null;
        foreach ($promotionPayload['companies'][0]['flow_rehearsal_promotion_packets'] as $candidate) {
            if ($candidate['work_order_id'] === $order->work_order_id) {
                $packet = $candidate;
                break;
            }
        }

        $this->assertIsArray($packet);
        $this->assertTrue((bool) $packet['manual_execution_packet_ready']);
        $this->assertSame('rehearsal_complete_manual_execution_packet_ready_external_launch_blocked', $packet['promotion_state']);
        $this->assertSame($invocationId, $packet['runtime_invocation_id']);
        $this->assertTrue((bool) $packet['work_item_receipts_complete']);
        $this->assertTrue((bool) $packet['final_authorities_bound']);
        $this->assertTrue((bool) $packet['runtime_invocation_registered']);
        $this->assertTrue((bool) $packet['runtime_rehearsal_executed']);
        $this->assertSame(2, $packet['execution_receipt_count']);
        $this->assertSame([], $packet['promotion_blockers']);
        $this->assertContains('external_write_without_decision_receipt', $packet['blocked_operations']);
        $this->assertFalse((bool) $packet['external_execution_allowed']);
        $this->assertSame(64, strlen((string) $packet['external_supervised_cutover_rehearsal_promotion_packet_hash']));
        $this->assertSame(64, strlen((string) $promotionPayload['external_supervised_cutover_rehearsal_promotion_status_hash']));

        $handoffExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-handoff-register',
            '--work-order' => $order->work_order_id,
            '--json' => true,
        ]);
        $handoffPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $handoffExit);
        $this->assertTrue((bool) $handoffPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_handoff_registry.v1', $handoffPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_handoff_packets_registered_external_launch_blocked', $handoffPayload['status']);
        $this->assertSame(1, $handoffPayload['summary']['manual_handoff_packet_count']);
        $this->assertFalse((bool) $handoffPayload['policy']['external_execution_allowed']);
        $this->assertTrue((bool) $handoffPayload['policy']['decision_receipt_required_before_any_external_effect']);
        $this->assertSame($invocationId, $handoffPayload['records'][0]['invocation_id']);
        $this->assertSame('manual_handoff_packet_registered_external_launch_blocked', $handoffPayload['records'][0]['status']);
        $this->assertSame(64, strlen((string) $handoffPayload['records'][0]['manual_handoff_packet_hash']));
        $this->assertSame($handoffPayload['records'][0]['manual_handoff_packet_hash'], $handoffPayload['records'][0]['manual_handoff_packet']['manual_handoff_packet_hash']);
        $this->assertTrue((bool) $handoffPayload['records'][0]['manual_handoff_packet']['decision_receipt_required_before_any_external_effect']);
        $this->assertFalse((bool) $handoffPayload['records'][0]['manual_handoff_packet']['external_execution_allowed_by_handoff_registry']);
        $this->assertContains('claim_external_result_without_receipt', $handoffPayload['records'][0]['manual_handoff_packet']['blocked_operations']);

        $handoffStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-handoff-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $handoffStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $handoffStatusExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_handoff_status.v1', $handoffStatusPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_handoff_packets_registered_external_launch_blocked', $handoffStatusPayload['status']);
        $this->assertSame(1, $handoffStatusPayload['summary']['company_count']);
        $this->assertSame(1, $handoffStatusPayload['summary']['manual_handoff_packet_count']);
        $this->assertSame(1, $handoffStatusPayload['summary']['decision_receipt_required_count']);
        $this->assertSame(0, $handoffStatusPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $handoffStatusPayload['policy']['external_execution_allowed']);
        $this->assertSame($handoffPayload['records'][0]['manual_handoff_packet_hash'], $handoffStatusPayload['companies'][0]['handoff_packets'][0]['manual_handoff_packet_hash']);
        $this->assertSame(64, strlen((string) $handoffStatusPayload['companies'][0]['company_external_supervised_cutover_manual_handoff_status_hash']));
        $this->assertSame(64, strlen((string) $handoffStatusPayload['external_supervised_cutover_manual_handoff_status_hash']));

        $emptyCloseoutStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-closeout-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $emptyCloseoutStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $emptyCloseoutStatusExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_closeout_status.v1', $emptyCloseoutStatusPayload['schema']);
        $this->assertSame('empty_external_supervised_cutover_manual_closeout_registry', $emptyCloseoutStatusPayload['status']);
        $this->assertSame(0, $emptyCloseoutStatusPayload['summary']['manual_closeout_receipt_count']);
        $this->assertFalse((bool) $emptyCloseoutStatusPayload['policy']['external_execution_allowed']);

        $closeoutReceiptHash = hash('sha256', 'finance.closeout.operator.external-execution-receipt');
        $closeoutExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-closeout-bind-receipt',
            '--work-order' => $order->work_order_id,
            '--receipt-hash' => $closeoutReceiptHash,
            '--receipt-source' => 'operator_console',
            '--operator' => 'atlas_operator',
            '--note' => 'operator confirmed external action and reconciliation sink',
            '--json' => true,
        ]);
        $closeoutPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $closeoutExit);
        $this->assertTrue((bool) $closeoutPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_closeout_binding.v1', $closeoutPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_closeout_receipt_bound_reconciliation_required', $closeoutPayload['status']);
        $this->assertSame(1, $closeoutPayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame($closeoutReceiptHash, $closeoutPayload['receipt']['external_operator_receipt_hash']);
        $this->assertFalse((bool) $closeoutPayload['receipt']['external_execution_performed_by_atlas']);
        $this->assertFalse((bool) $closeoutPayload['receipt']['external_side_effects_performed_by_atlas']);
        $this->assertTrue((bool) $closeoutPayload['receipt']['post_execution_reconciliation_bound']);
        $this->assertSame(64, strlen((string) $closeoutPayload['receipt']['manual_closeout_receipt_hash']));
        $this->assertSame(1, $closeoutPayload['record']['manual_closeout_receipt_count']);
        $this->assertSame($closeoutPayload['receipt']['manual_closeout_receipt_hash'], $closeoutPayload['record']['last_manual_closeout_receipt_hash']);
        $this->assertFalse((bool) $closeoutPayload['policy']['external_execution_allowed']);

        $closeoutStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-manual-closeout-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $closeoutStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $closeoutStatusExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_manual_closeout_status.v1', $closeoutStatusPayload['schema']);
        $this->assertSame('external_supervised_cutover_manual_closeout_receipts_bound_reconciliation_required', $closeoutStatusPayload['status']);
        $this->assertSame(1, $closeoutStatusPayload['summary']['company_count']);
        $this->assertSame(1, $closeoutStatusPayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame(0, $closeoutStatusPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $closeoutStatusPayload['policy']['external_execution_allowed']);
        $this->assertSame($closeoutPayload['receipt']['manual_closeout_receipt_hash'], $closeoutStatusPayload['companies'][0]['closeout_records'][0]['last_manual_closeout_receipt_hash']);
        $this->assertSame(64, strlen((string) $closeoutStatusPayload['companies'][0]['company_external_supervised_cutover_manual_closeout_status_hash']));
        $this->assertSame(64, strlen((string) $closeoutStatusPayload['external_supervised_cutover_manual_closeout_status_hash']));

        $portfolioReadinessExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-portfolio-readiness-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $portfolioReadinessPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $portfolioReadinessExit);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_portfolio_readiness_status.v1', $portfolioReadinessPayload['schema']);
        $this->assertSame('external_supervised_cutover_portfolio_partially_operational_attention_required', $portfolioReadinessPayload['status']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['company_count']);
        $this->assertSame(6, $portfolioReadinessPayload['summary']['expected_flow_count']);
        $this->assertSame(6, $portfolioReadinessPayload['summary']['work_order_flow_count']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['runtime_invocation_flow_count']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['manual_execution_packet_ready_count']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['manual_handoff_packet_count']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame(0, $portfolioReadinessPayload['summary']['evidence_quality_ready_company_count']);
        $this->assertGreaterThan($portfolioReadinessPayload['summary']['external_evidence_quality_ready_gate_count'], $portfolioReadinessPayload['summary']['external_evidence_quality_gate_count']);
        $this->assertSame(0, $portfolioReadinessPayload['summary']['cutover_chain_complete_company_count']);
        $this->assertSame(1, $portfolioReadinessPayload['summary']['attention_company_count']);
        $this->assertFalse((bool) $portfolioReadinessPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $portfolioReadinessPayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $portfolioReadinessPayload['policy']['external_evidence_quality_gates_required']);
        $this->assertSame('manual_closeout_receipts_partially_bound_reconciliation_required', $portfolioReadinessPayload['companies'][0]['operational_stage']);
        $this->assertContains('runtime_invocation_packets_missing', $portfolioReadinessPayload['companies'][0]['missing_capabilities']);
        $this->assertContains('manual_closeout_receipts_missing', $portfolioReadinessPayload['companies'][0]['missing_capabilities']);
        $this->assertContains('external_evidence_quality_gates_incomplete', $portfolioReadinessPayload['companies'][0]['missing_capabilities']);
        $this->assertFalse((bool) $portfolioReadinessPayload['companies'][0]['external_evidence_quality']['ready']);
        $this->assertFalse((bool) $portfolioReadinessPayload['companies'][0]['external_evidence_quality']['gates']['manual_closeout_receipts_bound_for_all_flows']);
        $this->assertSame(64, strlen((string) $portfolioReadinessPayload['companies'][0]['portfolio_readiness_record_hash']));
        $this->assertSame(64, strlen((string) $portfolioReadinessPayload['companies'][0]['external_evidence_quality']['external_supervised_cutover_evidence_quality_hash']));
        $this->assertSame(64, strlen((string) $portfolioReadinessPayload['external_supervised_cutover_portfolio_readiness_status_hash']));

        $bundleReceiptHash = hash('sha256', 'finance.company.evidence.bundle.operator.root');
        $bundleExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-company-evidence-bundle-apply',
            '--company' => 'finance',
            '--receipt-hash' => $bundleReceiptHash,
            '--receipt-source' => 'operator_console',
            '--operator' => 'atlas_operator',
            '--note' => 'operator supplied company-level evidence bundle for all finance flows',
            '--json' => true,
        ]);
        $bundlePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $bundleExit);
        $this->assertTrue((bool) $bundlePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_company_evidence_bundle.v1', $bundlePayload['schema']);
        $this->assertSame('external_supervised_cutover_company_evidence_bundle_applied_reconciliation_complete_external_autonomy_still_blocked', $bundlePayload['status']);
        $this->assertSame('finance', $bundlePayload['company_id']);
        $this->assertSame(6, $bundlePayload['summary']['work_order_count']);
        $this->assertGreaterThanOrEqual(40, $bundlePayload['summary']['work_item_binding_count']);
        $this->assertGreaterThanOrEqual(40, $bundlePayload['summary']['final_authority_binding_count']);
        $this->assertSame(6, $bundlePayload['summary']['runtime_invocation_count']);
        $this->assertSame(6, $bundlePayload['summary']['rehearsal_execution_receipt_count']);
        $this->assertSame(6, $bundlePayload['summary']['manual_handoff_packet_count']);
        $this->assertSame(5, $bundlePayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame(1, $bundlePayload['summary']['cutover_chain_complete_company_count']);
        $this->assertSame(1, $bundlePayload['summary']['evidence_quality_ready_company_count']);
        $this->assertSame($bundlePayload['summary']['external_evidence_quality_gate_count'], $bundlePayload['summary']['external_evidence_quality_ready_gate_count']);
        $this->assertSame(0, $bundlePayload['summary']['external_execution_allowed_count']);
        $this->assertSame($bundleReceiptHash, $bundlePayload['source_receipt_bundle']['root_receipt_hash']);
        $this->assertFalse((bool) $bundlePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $bundlePayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $bundlePayload['policy']['atlas_did_not_perform_external_execution']);
        $this->assertSame(64, strlen((string) $bundlePayload['external_supervised_cutover_company_evidence_bundle_hash']));

        $completePortfolioExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-portfolio-readiness-status',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $completePortfolioPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $completePortfolioExit);
        $this->assertSame('external_supervised_cutover_portfolio_reconciliation_complete_external_autonomy_still_blocked', $completePortfolioPayload['status']);
        $this->assertSame(1, $completePortfolioPayload['summary']['cutover_chain_complete_company_count']);
        $this->assertSame(0, $completePortfolioPayload['summary']['attention_company_count']);
        $this->assertSame(6, $completePortfolioPayload['summary']['runtime_invocation_flow_count']);
        $this->assertSame(6, $completePortfolioPayload['summary']['manual_execution_packet_ready_count']);
        $this->assertSame(6, $completePortfolioPayload['summary']['manual_handoff_packet_count']);
        $this->assertSame(6, $completePortfolioPayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame(1, $completePortfolioPayload['summary']['evidence_quality_ready_company_count']);
        $this->assertSame($completePortfolioPayload['summary']['external_evidence_quality_gate_count'], $completePortfolioPayload['summary']['external_evidence_quality_ready_gate_count']);
        $this->assertSame([], $completePortfolioPayload['companies'][0]['missing_capabilities']);
        $this->assertTrue((bool) $completePortfolioPayload['companies'][0]['cutover_chain_complete']);
        $this->assertTrue((bool) $completePortfolioPayload['companies'][0]['external_evidence_quality']['ready']);
        $this->assertTrue((bool) $completePortfolioPayload['companies'][0]['external_evidence_quality']['gates']['manual_closeout_receipt_sources_present']);
        $this->assertTrue((bool) $completePortfolioPayload['companies'][0]['external_evidence_quality']['gates']['atlas_external_execution_claims_absent']);
        $this->assertSame('manual_closeout_reconciliation_complete_for_all_flows_external_autonomy_still_blocked', $completePortfolioPayload['companies'][0]['operational_stage']);
        $this->assertFalse((bool) $completePortfolioPayload['policy']['external_execution_allowed']);

        $portfolioBundleReceiptHash = hash('sha256', 'holding.portfolio.evidence.bundle.operator.root');
        $portfolioBundleExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-supervised-cutover-portfolio-evidence-bundle-apply',
            '--receipt-hash' => $portfolioBundleReceiptHash,
            '--receipt-source' => 'operator_console',
            '--operator' => 'atlas_operator',
            '--note' => 'operator supplied portfolio-level evidence bundle for every company flow',
            '--json' => true,
        ]);
        $portfolioBundlePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $portfolioBundleExit);
        $this->assertTrue((bool) $portfolioBundlePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_external_supervised_cutover_portfolio_evidence_bundle.v1', $portfolioBundlePayload['schema']);
        $this->assertSame('external_supervised_cutover_portfolio_evidence_bundle_applied_all_companies_reconciled_external_autonomy_still_blocked', $portfolioBundlePayload['status']);
        $this->assertSame(9, $portfolioBundlePayload['summary']['company_count']);
        $this->assertSame(9, $portfolioBundlePayload['summary']['successful_company_bundle_count']);
        $this->assertGreaterThanOrEqual(54, $portfolioBundlePayload['summary']['expected_flow_count']);
        $this->assertSame($portfolioBundlePayload['summary']['expected_flow_count'], $portfolioBundlePayload['summary']['work_order_flow_count']);
        $this->assertSame($portfolioBundlePayload['summary']['expected_flow_count'], $portfolioBundlePayload['summary']['runtime_invocation_flow_count']);
        $this->assertSame($portfolioBundlePayload['summary']['expected_flow_count'], $portfolioBundlePayload['summary']['manual_execution_packet_ready_count']);
        $this->assertSame($portfolioBundlePayload['summary']['expected_flow_count'], $portfolioBundlePayload['summary']['manual_handoff_packet_count']);
        $this->assertSame($portfolioBundlePayload['summary']['expected_flow_count'], $portfolioBundlePayload['summary']['manual_closeout_receipt_count']);
        $this->assertSame(9, $portfolioBundlePayload['summary']['evidence_quality_ready_company_count']);
        $this->assertSame($portfolioBundlePayload['summary']['external_evidence_quality_gate_count'], $portfolioBundlePayload['summary']['external_evidence_quality_ready_gate_count']);
        $this->assertSame(9, $portfolioBundlePayload['summary']['cutover_chain_complete_company_count']);
        $this->assertSame(0, $portfolioBundlePayload['summary']['attention_company_count']);
        $this->assertSame(0, $portfolioBundlePayload['summary']['external_execution_allowed_count']);
        $this->assertSame($portfolioBundleReceiptHash, $portfolioBundlePayload['source_receipt_bundle']['root_receipt_hash']);
        $this->assertFalse((bool) $portfolioBundlePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $portfolioBundlePayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $portfolioBundlePayload['policy']['atlas_did_not_perform_external_execution']);
        $this->assertSame(64, strlen((string) $portfolioBundlePayload['external_supervised_cutover_portfolio_evidence_bundle_hash']));

        foreach ($portfolioBundlePayload['portfolio_readiness']['companies'] as $companyReadiness) {
            $this->assertTrue((bool) $companyReadiness['cutover_chain_complete'], (string) $companyReadiness['company_id']);
            $this->assertSame([], $companyReadiness['missing_capabilities'], (string) $companyReadiness['company_id']);
            $this->assertTrue((bool) $companyReadiness['external_evidence_quality']['ready'], (string) $companyReadiness['company_id']);
            $this->assertSame($companyReadiness['external_evidence_quality']['required_gate_count'], $companyReadiness['external_evidence_quality']['ready_gate_count'], (string) $companyReadiness['company_id']);
            $this->assertSame([], $companyReadiness['external_evidence_quality']['missing_gates'], (string) $companyReadiness['company_id']);
            $this->assertSame('manual_closeout_reconciliation_complete_for_all_flows_external_autonomy_still_blocked', $companyReadiness['operational_stage']);
            $this->assertFalse((bool) $companyReadiness['external_execution_allowed']);
        }

        foreach ([
            'enterprise-activation-backlog-register',
            'enterprise-activation-backlog-run',
            'enterprise-connector-activation-register',
            'enterprise-connector-activation-probe',
            'enterprise-flow-run-queue-register',
            'enterprise-flow-run-queue-execute',
            'enterprise-flow-run-queue-replay',
            'enterprise-flow-operations-runbook-register',
            'enterprise-flow-operations-runbook-drill',
            'enterprise-flow-action-runtime-run',
        ] as $action) {
            $this->assertSame(0, Artisan::call('atlas:ai:autonomous-holding', [
                '--action' => $action,
                '--json' => true,
            ]), $action);
        }

        $completionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-completion-certification-status',
            '--json' => true,
        ]);
        $completionPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $completionExit, Artisan::output());
        $this->assertTrue((bool) $completionPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_completion_certification_status.v1', $completionPayload['schema']);
        $this->assertSame('enterprise_company_completion_certified_external_autonomy_still_blocked', $completionPayload['status']);
        $this->assertSame(9, $completionPayload['summary']['company_count']);
        $this->assertSame(9, $completionPayload['summary']['completion_certified_company_count']);
        $this->assertSame(0, $completionPayload['summary']['attention_company_count']);
        $this->assertGreaterThanOrEqual(54, $completionPayload['summary']['expected_flow_count']);
        $this->assertSame(9, $completionPayload['summary']['internal_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['company_system_model_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['internal_operations_backbone_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['activation_run_operations_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['domain_company_execution_suite_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['flow_work_product_delivery_runtime_complete_company_count']);
        $this->assertSame(9, $completionPayload['summary']['board_review_ready_company_count']);
        $this->assertSame(9, $completionPayload['summary']['dossier_ready_company_count']);
        $this->assertSame(9, $completionPayload['summary']['handoff_pack_ready_company_count']);
        $this->assertSame(9, $completionPayload['summary']['supervised_cutover_complete_company_count']);
        $this->assertSame(0, $completionPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $completionPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $completionPayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $completionPayload['policy']['completion_certification_is_not_execution_authority']);
        $this->assertContains('company_system_model_runtime_status', $completionPayload['policy']['required_evidence_chains']);
        $this->assertContains('internal_operations_backbone_runtime_status', $completionPayload['policy']['required_evidence_chains']);
        $this->assertContains('activation_run_operations_runtime_status', $completionPayload['policy']['required_evidence_chains']);
        $this->assertContains('domain_company_execution_suite_runtime_status', $completionPayload['policy']['required_evidence_chains']);
        $this->assertContains('flow_work_product_delivery_runtime_status', $completionPayload['policy']['required_evidence_chains']);
        $this->assertSame(64, strlen((string) $completionPayload['source_hashes']['company_system_model_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $completionPayload['source_hashes']['internal_operations_backbone_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $completionPayload['source_hashes']['activation_run_operations_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $completionPayload['source_hashes']['domain_company_execution_suite_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $completionPayload['source_hashes']['flow_work_product_delivery_runtime_status_hash']));
        $this->assertSame(64, strlen((string) $completionPayload['enterprise_company_completion_certification_status_hash']));

        foreach ($completionPayload['companies'] as $companyCertification) {
            $this->assertTrue((bool) $companyCertification['completion_certified'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['internal_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['company_system_model_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['internal_operations_backbone_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['activation_run_operations_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['domain_company_execution_suite_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['flow_work_product_delivery_runtime_complete'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['board_operating_review_ready'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['real_external_execution_dossier_ready'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['manual_handoff_pack_ready'], (string) $companyCertification['company_id']);
            $this->assertTrue((bool) $companyCertification['supervised_cutover_receipt_chain_complete'], (string) $companyCertification['company_id']);
            $this->assertSame(1.0, (float) data_get($companyCertification, 'runtime_coverage_snapshot.company_system_model_runtime_coverage_rate'), (string) $companyCertification['company_id']);
            $this->assertSame(1.0, (float) data_get($companyCertification, 'runtime_coverage_snapshot.internal_operations_backbone_runtime_coverage_rate'), (string) $companyCertification['company_id']);
            $this->assertSame(1.0, (float) data_get($companyCertification, 'runtime_coverage_snapshot.activation_run_operations_runtime_coverage_rate'), (string) $companyCertification['company_id']);
            $this->assertSame(1.0, (float) data_get($companyCertification, 'runtime_coverage_snapshot.domain_company_execution_suite_runtime_coverage_rate'), (string) $companyCertification['company_id']);
            $this->assertSame(1.0, (float) data_get($companyCertification, 'runtime_coverage_snapshot.flow_work_product_delivery_runtime_coverage_rate'), (string) $companyCertification['company_id']);
            $this->assertGreaterThanOrEqual((int) $companyCertification['expected_flow_count'], (int) data_get($companyCertification, 'runtime_coverage_snapshot.company_system_model_external_actions_blocked_count'), (string) $companyCertification['company_id']);
            $this->assertGreaterThanOrEqual((int) $companyCertification['expected_flow_count'], (int) data_get($companyCertification, 'runtime_coverage_snapshot.internal_operations_backbone_external_actions_blocked_count'), (string) $companyCertification['company_id']);
            $this->assertGreaterThanOrEqual((int) $companyCertification['expected_flow_count'], (int) data_get($companyCertification, 'runtime_coverage_snapshot.activation_run_operations_external_actions_blocked_count'), (string) $companyCertification['company_id']);
            $this->assertGreaterThanOrEqual((int) $companyCertification['expected_flow_count'], (int) data_get($companyCertification, 'runtime_coverage_snapshot.domain_company_execution_suite_external_actions_blocked_count'), (string) $companyCertification['company_id']);
            $this->assertGreaterThanOrEqual((int) $companyCertification['expected_flow_count'], (int) data_get($companyCertification, 'runtime_coverage_snapshot.flow_work_product_external_delivery_blocked_count'), (string) $companyCertification['company_id']);
            $this->assertSame([], $companyCertification['missing_capabilities'], (string) $companyCertification['company_id']);
            $this->assertFalse((bool) $companyCertification['external_execution_allowed']);
            $this->assertSame(64, strlen((string) data_get($companyCertification, 'evidence_hashes.company_system_model_runtime_status_hash')));
            $this->assertSame(64, strlen((string) data_get($companyCertification, 'evidence_hashes.internal_operations_backbone_runtime_status_hash')));
            $this->assertSame(64, strlen((string) data_get($companyCertification, 'evidence_hashes.activation_run_operations_runtime_status_hash')));
            $this->assertSame(64, strlen((string) data_get($companyCertification, 'evidence_hashes.domain_company_execution_suite_runtime_status_hash')));
            $this->assertSame(64, strlen((string) data_get($companyCertification, 'evidence_hashes.flow_work_product_delivery_runtime_status_hash')));
            $this->assertSame(64, strlen((string) $companyCertification['completion_certification_record_hash']));
        }

        $depthExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-vertical-operational-depth-status',
            '--json' => true,
        ]);
        $depthPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $depthExit, Artisan::output());
        $this->assertTrue((bool) $depthPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_vertical_operational_depth_status.v1', $depthPayload['schema']);
        $this->assertSame('enterprise_vertical_operational_depth_ready_external_autonomy_still_blocked', $depthPayload['status']);
        $this->assertSame(9, $depthPayload['summary']['company_count']);
        $this->assertSame(9, $depthPayload['summary']['operational_depth_ready_company_count']);
        $this->assertSame(0, $depthPayload['summary']['attention_company_count']);
        $this->assertSame($depthPayload['summary']['required_gate_count'], $depthPayload['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $depthPayload['summary']['average_depth_score']);
        $this->assertSame(9, $depthPayload['summary']['solution_depth_ready_company_count']);
        $this->assertSame(9, $depthPayload['summary']['operating_depth_ready_company_count']);
        $this->assertSame(9, $depthPayload['summary']['production_depth_ready_company_count']);
        $this->assertSame(9, $depthPayload['summary']['quality_governance_depth_ready_company_count']);
        $this->assertSame(0, $depthPayload['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $depthPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $depthPayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $depthPayload['policy']['vertical_operational_depth_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $depthPayload['enterprise_vertical_operational_depth_status_hash']));

        foreach ($depthPayload['companies'] as $companyDepth) {
            $this->assertSame($companyDepth['required_gate_count'], $companyDepth['ready_gate_count'], (string) $companyDepth['company_id']);
            $this->assertSame(1.0, (float) $companyDepth['depth_score'], (string) $companyDepth['company_id']);
            $this->assertSame([], $companyDepth['missing_gates'], (string) $companyDepth['company_id']);
            $this->assertSame('target_9_internal_operating_depth_external_autonomy_blocked', $companyDepth['depth_grade'], (string) $companyDepth['company_id']);
            $this->assertTrue((bool) $companyDepth['depth_axes']['solution_depth_ready'], (string) $companyDepth['company_id']);
            $this->assertTrue((bool) $companyDepth['depth_axes']['operating_depth_ready'], (string) $companyDepth['company_id']);
            $this->assertTrue((bool) $companyDepth['depth_axes']['production_depth_ready'], (string) $companyDepth['company_id']);
            $this->assertTrue((bool) $companyDepth['depth_axes']['quality_governance_depth_ready'], (string) $companyDepth['company_id']);
            $this->assertGreaterThanOrEqual($companyDepth['expected_flow_count'], $companyDepth['flow_artifact_counts']['flow_solution_kit_count'], (string) $companyDepth['company_id']);
            $this->assertGreaterThanOrEqual($companyDepth['expected_flow_count'], $companyDepth['flow_artifact_counts']['flow_package_count'], (string) $companyDepth['company_id']);
            $this->assertGreaterThanOrEqual($companyDepth['expected_flow_count'], $companyDepth['flow_artifact_counts']['operations_green_count'], (string) $companyDepth['company_id']);
            $this->assertFalse((bool) $companyDepth['external_execution_allowed']);
            $this->assertSame(64, strlen((string) $companyDepth['vertical_operational_depth_record_hash']));
        }

        $cycleExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-cycle-status',
            '--json' => true,
        ]);
        $cyclePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $cycleExit, Artisan::output());
        $this->assertTrue((bool) $cyclePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_cycle_status.v1', $cyclePayload['schema']);
        $this->assertSame('enterprise_company_operating_cycles_ready_external_autonomy_still_blocked', $cyclePayload['status']);
        $this->assertSame(9, $cyclePayload['summary']['company_count']);
        $this->assertSame(9, $cyclePayload['summary']['operating_cycle_ready_company_count']);
        $this->assertSame(0, $cyclePayload['summary']['attention_company_count']);
        $this->assertSame($cyclePayload['summary']['flow_cycle_count'], $cyclePayload['summary']['ready_flow_cycle_count']);
        $this->assertSame($cyclePayload['summary']['required_runtime_gate_count'], $cyclePayload['summary']['ready_runtime_gate_count']);
        $this->assertSame($cyclePayload['summary']['required_lane_count'], $cyclePayload['summary']['ready_lane_count']);
        $this->assertSame(1.0, (float) $cyclePayload['summary']['average_operating_cycle_score']);
        $this->assertFalse((bool) $cyclePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $cyclePayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $cyclePayload['policy']['company_operating_cycle_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $cyclePayload['enterprise_company_operating_cycle_status_hash']));

        foreach ($cyclePayload['companies'] as $companyCycle) {
            $this->assertTrue((bool) $companyCycle['operating_cycle_ready'], (string) $companyCycle['company_id']);
            $this->assertSame($companyCycle['flow_cycle_count'], $companyCycle['ready_flow_cycle_count'], (string) $companyCycle['company_id']);
            $this->assertSame($companyCycle['required_runtime_gate_count'], $companyCycle['ready_runtime_gate_count'], (string) $companyCycle['company_id']);
            $this->assertSame($companyCycle['required_lane_count'], $companyCycle['ready_lane_count'], (string) $companyCycle['company_id']);
            $this->assertSame([], $companyCycle['missing_runtime_gates'], (string) $companyCycle['company_id']);
            $this->assertSame(1.0, (float) $companyCycle['operating_cycle_score'], (string) $companyCycle['company_id']);
            $this->assertFalse((bool) $companyCycle['external_execution_allowed']);
            $this->assertSame(64, strlen((string) $companyCycle['company_operating_cycle_record_hash']));

            foreach ($companyCycle['flow_cycles'] as $flowCycle) {
                $this->assertTrue((bool) $flowCycle['cycle_ready'], (string) $flowCycle['flow_id']);
                $this->assertSame($flowCycle['required_lane_count'], $flowCycle['ready_lane_count'], (string) $flowCycle['flow_id']);
                $this->assertSame(1.0, (float) $flowCycle['cycle_score'], (string) $flowCycle['flow_id']);
                $this->assertFalse((bool) $flowCycle['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $flowCycle['flow_operating_cycle_hash']));
            }
        }

        $cadenceExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-cadence-status',
            '--json' => true,
        ]);
        $cadencePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $cadenceExit, Artisan::output());
        $this->assertTrue((bool) $cadencePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_cadence_status.v1', $cadencePayload['schema']);
        $this->assertSame('enterprise_company_operating_cadences_ready_external_autonomy_still_blocked', $cadencePayload['status']);
        $this->assertSame(9, $cadencePayload['summary']['company_count']);
        $this->assertSame(9, $cadencePayload['summary']['cadence_ready_company_count']);
        $this->assertSame(0, $cadencePayload['summary']['attention_company_count']);
        $this->assertSame($cadencePayload['summary']['cadence_count'], $cadencePayload['summary']['ready_cadence_count']);
        $this->assertSame($cadencePayload['summary']['required_gate_count'], $cadencePayload['summary']['ready_gate_count']);
        $this->assertGreaterThanOrEqual(36, $cadencePayload['summary']['okr_count']);
        $this->assertGreaterThanOrEqual(36, $cadencePayload['summary']['risk_count']);
        $this->assertGreaterThanOrEqual($cadencePayload['summary']['expected_flow_count'], $cadencePayload['summary']['runbook_count']);
        $this->assertGreaterThanOrEqual(18, $cadencePayload['summary']['backlog_wip_limit_count']);
        $this->assertSame(9, $cadencePayload['summary']['monthly_process_improvement_bound_count']);
        $this->assertSame(9, $cadencePayload['summary']['incident_postmortem_bound_count']);
        $this->assertSame(1.0, (float) $cadencePayload['summary']['average_cadence_score']);
        $this->assertFalse((bool) $cadencePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $cadencePayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $cadencePayload['policy']['company_operating_cadence_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $cadencePayload['enterprise_company_operating_cadence_status_hash']));

        foreach ($cadencePayload['companies'] as $companyCadence) {
            $this->assertTrue((bool) $companyCadence['cadence_ready'], (string) $companyCadence['company_id']);
            $this->assertSame($companyCadence['cadence_count'], $companyCadence['ready_cadence_count'], (string) $companyCadence['company_id']);
            $this->assertSame($companyCadence['required_gate_count'], $companyCadence['ready_gate_count'], (string) $companyCadence['company_id']);
            $this->assertSame([], $companyCadence['missing_gates'], (string) $companyCadence['company_id']);
            $this->assertSame(1.0, (float) $companyCadence['cadence_score'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['operating_calendar']['quarterly_strategy_review']['bound'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['operating_calendar']['weekly_operating_board']['bound'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['operating_calendar']['daily_or_per_run_control']['bound'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['operating_calendar']['monthly_process_improvement']['bound'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['continuous_improvement_system']['monthly_process_improvement_bound'], (string) $companyCadence['company_id']);
            $this->assertTrue((bool) $companyCadence['continuous_improvement_system']['incident_or_missed_cadence_postmortem_bound'], (string) $companyCadence['company_id']);
            $this->assertGreaterThanOrEqual(2, $companyCadence['continuous_improvement_system']['backlog_wip_limit_count'], (string) $companyCadence['company_id']);
            $this->assertFalse((bool) $companyCadence['external_execution_allowed']);
            $this->assertSame(64, strlen((string) $companyCadence['company_operating_cadence_record_hash']));

            foreach ($companyCadence['cadence_records'] as $cadenceRecord) {
                $this->assertTrue((bool) $cadenceRecord['cadence_ready'], (string) $cadenceRecord['cadence_id']);
                $this->assertGreaterThanOrEqual(5, $cadenceRecord['required_input_count'], (string) $cadenceRecord['cadence_id']);
                $this->assertGreaterThanOrEqual(3, $cadenceRecord['output_count'], (string) $cadenceRecord['cadence_id']);
                $this->assertTrue((bool) $cadenceRecord['operator_review_packet_required'], (string) $cadenceRecord['cadence_id']);
                $this->assertTrue((bool) $cadenceRecord['backlog_update_required'], (string) $cadenceRecord['cadence_id']);
                $this->assertFalse((bool) $cadenceRecord['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $cadenceRecord['operating_cadence_record_hash']));
            }
        }

        $scorecardExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-scorecard-status',
            '--json' => true,
        ]);
        $scorecardPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $scorecardExit, Artisan::output());
        $this->assertTrue((bool) $scorecardPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_scorecard_status.v1', $scorecardPayload['schema']);
        $this->assertSame('enterprise_company_operating_scorecards_ready_external_value_claims_blocked', $scorecardPayload['status']);
        $this->assertSame(9, $scorecardPayload['summary']['company_count']);
        $this->assertSame(9, $scorecardPayload['summary']['scorecard_ready_company_count']);
        $this->assertSame(0, $scorecardPayload['summary']['attention_company_count']);
        $this->assertSame($scorecardPayload['summary']['required_gate_count'], $scorecardPayload['summary']['ready_gate_count']);
        $this->assertSame(10.0, (float) $scorecardPayload['summary']['average_internal_outcome_score']);
        $this->assertSame(1.0, (float) $scorecardPayload['summary']['average_scorecard_score']);
        $this->assertSame(9, $scorecardPayload['summary']['board_review_ready_company_count']);
        $this->assertSame($scorecardPayload['summary']['expected_flow_count'], $scorecardPayload['summary']['risk_remediation_count']);
        $this->assertSame($scorecardPayload['summary']['expected_flow_count'], $scorecardPayload['summary']['next_cycle_action_count']);
        $this->assertGreaterThanOrEqual(27, $scorecardPayload['summary']['board_decision_action_count']);
        $this->assertSame(9, $scorecardPayload['summary']['scale_internal_supervised_capacity_count']);
        $this->assertSame(0, $scorecardPayload['summary']['external_revenue_claim_allowed_count']);
        $this->assertSame(0, $scorecardPayload['summary']['real_money_movement_allowed_count']);
        $this->assertFalse((bool) $scorecardPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $scorecardPayload['policy']['external_revenue_claim_allowed']);
        $this->assertFalse((bool) $scorecardPayload['policy']['real_money_movement_allowed']);
        $this->assertTrue((bool) $scorecardPayload['policy']['company_operating_scorecard_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $scorecardPayload['enterprise_company_operating_scorecard_status_hash']));

        foreach ($scorecardPayload['companies'] as $companyScorecard) {
            $this->assertTrue((bool) $companyScorecard['scorecard_ready'], (string) $companyScorecard['company_id']);
            $this->assertSame($companyScorecard['required_gate_count'], $companyScorecard['ready_gate_count'], (string) $companyScorecard['company_id']);
            $this->assertSame([], $companyScorecard['missing_gates'], (string) $companyScorecard['company_id']);
            $this->assertSame(10.0, (float) $companyScorecard['internal_outcome_score'], (string) $companyScorecard['company_id']);
            $this->assertSame(1.0, (float) $companyScorecard['scorecard_score'], (string) $companyScorecard['company_id']);
            $this->assertSame('target_9_internal_enterprise_operating_scorecard_external_value_claim_blocked', $companyScorecard['scorecard_grade'], (string) $companyScorecard['company_id']);
            $this->assertSame('scale_internal_supervised_capacity', $companyScorecard['portfolio_decision_recommendation'], (string) $companyScorecard['company_id']);
            $this->assertSame(64, strlen((string) $companyScorecard['board_operating_review_hash']), (string) $companyScorecard['company_id']);
            $this->assertTrue((bool) $companyScorecard['governance_and_improvement']['board_review_ready'], (string) $companyScorecard['company_id']);
            $this->assertGreaterThanOrEqual($companyScorecard['expected_flow_count'], $companyScorecard['governance_and_improvement']['risk_remediation_count'], (string) $companyScorecard['company_id']);
            $this->assertGreaterThanOrEqual($companyScorecard['expected_flow_count'], $companyScorecard['governance_and_improvement']['next_cycle_action_count'], (string) $companyScorecard['company_id']);
            $this->assertGreaterThanOrEqual(3, $companyScorecard['governance_and_improvement']['board_decision_action_count'], (string) $companyScorecard['company_id']);
            $this->assertSame(4, $companyScorecard['governance_and_improvement']['scorecard_source_hash_lineage_count'], (string) $companyScorecard['company_id']);
            $this->assertTrue((bool) $companyScorecard['governance_and_improvement']['monthly_process_improvement_bound'], (string) $companyScorecard['company_id']);
            $this->assertTrue((bool) $companyScorecard['governance_and_improvement']['incident_postmortem_bound'], (string) $companyScorecard['company_id']);
            $this->assertGreaterThanOrEqual($companyScorecard['expected_flow_count'], $companyScorecard['notional_internal_pnl']['notional_cost_center_count'], (string) $companyScorecard['company_id']);
            $this->assertGreaterThanOrEqual($companyScorecard['expected_flow_count'], $companyScorecard['notional_internal_pnl']['billing_ledger_control_count'], (string) $companyScorecard['company_id']);
            $this->assertFalse((bool) $companyScorecard['notional_internal_pnl']['external_revenue_claim_allowed']);
            $this->assertFalse((bool) $companyScorecard['notional_internal_pnl']['real_money_movement_allowed']);
            $this->assertFalse((bool) $companyScorecard['capacity_and_investment']['real_capital_action_allowed']);
            $this->assertFalse((bool) $companyScorecard['external_execution_allowed']);
            $this->assertSame(64, strlen((string) $companyScorecard['company_operating_scorecard_record_hash']));
        }

        $activeOperatingSystemExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-active-operating-system-status',
            '--json' => true,
        ]);
        $activeOperatingSystemPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $activeOperatingSystemExit, Artisan::output());
        $this->assertTrue((bool) $activeOperatingSystemPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_active_operating_system_status.v1', $activeOperatingSystemPayload['schema']);
        $this->assertSame('enterprise_company_active_operating_systems_ready_external_autonomy_still_blocked', $activeOperatingSystemPayload['status']);
        $this->assertSame(9, $activeOperatingSystemPayload['summary']['company_count']);
        $this->assertSame(9, $activeOperatingSystemPayload['summary']['active_operating_system_ready_company_count']);
        $this->assertSame(0, $activeOperatingSystemPayload['summary']['attention_company_count']);
        $this->assertSame($activeOperatingSystemPayload['summary']['required_gate_count'], $activeOperatingSystemPayload['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $activeOperatingSystemPayload['summary']['average_active_operating_system_score']);
        $this->assertSame(10.0, (float) $activeOperatingSystemPayload['summary']['average_internal_outcome_score']);
        $this->assertSame(0, $activeOperatingSystemPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $activeOperatingSystemPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame(0, $activeOperatingSystemPayload['summary']['external_autonomy_allowed_count']);
        $this->assertFalse((bool) $activeOperatingSystemPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $activeOperatingSystemPayload['policy']['external_side_effects_enabled']);
        $this->assertFalse((bool) $activeOperatingSystemPayload['policy']['external_autonomy_allowed']);
        $this->assertTrue((bool) $activeOperatingSystemPayload['policy']['active_operating_system_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $activeOperatingSystemPayload['enterprise_company_active_operating_system_status_hash']));

        foreach ($activeOperatingSystemPayload['companies'] as $companyActiveOperatingSystem) {
            $this->assertTrue((bool) $companyActiveOperatingSystem['active_operating_system_ready'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertSame($companyActiveOperatingSystem['required_gate_count'], $companyActiveOperatingSystem['ready_gate_count'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertSame([], $companyActiveOperatingSystem['missing_gates'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertSame(1.0, (float) $companyActiveOperatingSystem['active_operating_system_score'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertSame('target_9_internal_active_operating_system_external_autonomy_blocked', $companyActiveOperatingSystem['active_operating_system_grade'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertSame(10.0, (float) $companyActiveOperatingSystem['layer_scores']['internal_outcome_score'], (string) $companyActiveOperatingSystem['company_id']);
            $this->assertFalse((bool) $companyActiveOperatingSystem['external_execution_allowed']);
            $this->assertFalse((bool) $companyActiveOperatingSystem['external_side_effects_enabled']);
            $this->assertFalse((bool) $companyActiveOperatingSystem['external_autonomy_allowed']);
            $this->assertSame(64, strlen((string) $companyActiveOperatingSystem['active_operating_system_record_hash']));
        }

        $capabilityCatalogExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-capability-catalog-status',
            '--json' => true,
        ]);
        $capabilityCatalogPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $capabilityCatalogExit, Artisan::output());
        $this->assertTrue((bool) $capabilityCatalogPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_capability_catalog_status.v1', $capabilityCatalogPayload['schema']);
        $this->assertSame('enterprise_company_capability_catalogs_ready_external_autonomy_still_blocked', $capabilityCatalogPayload['status']);
        $this->assertSame(9, $capabilityCatalogPayload['summary']['company_count']);
        $this->assertSame(9, $capabilityCatalogPayload['summary']['catalog_ready_company_count']);
        $this->assertSame(0, $capabilityCatalogPayload['summary']['attention_company_count']);
        $this->assertSame($capabilityCatalogPayload['summary']['required_family_count'], $capabilityCatalogPayload['summary']['ready_family_count']);
        $this->assertSame($capabilityCatalogPayload['summary']['flow_capability_count'], $capabilityCatalogPayload['summary']['ready_flow_capability_count']);
        $this->assertSame(1.0, (float) $capabilityCatalogPayload['summary']['average_catalog_score']);
        $this->assertSame(0, $capabilityCatalogPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $capabilityCatalogPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame(0, $capabilityCatalogPayload['summary']['external_autonomy_allowed_count']);
        $this->assertFalse((bool) $capabilityCatalogPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $capabilityCatalogPayload['policy']['external_side_effects_enabled']);
        $this->assertFalse((bool) $capabilityCatalogPayload['policy']['external_autonomy_allowed']);
        $this->assertTrue((bool) $capabilityCatalogPayload['policy']['capability_catalog_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $capabilityCatalogPayload['enterprise_company_capability_catalog_status_hash']));

        foreach ($capabilityCatalogPayload['companies'] as $companyCapabilityCatalog) {
            $this->assertTrue((bool) $companyCapabilityCatalog['catalog_ready'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertSame($companyCapabilityCatalog['required_family_count'], $companyCapabilityCatalog['ready_family_count'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertSame($companyCapabilityCatalog['flow_capability_count'], $companyCapabilityCatalog['ready_flow_capability_count'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertSame([], $companyCapabilityCatalog['missing_families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertSame(1.0, (float) $companyCapabilityCatalog['catalog_score'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertArrayHasKey('financial_services_data_modeling', $companyCapabilityCatalog['families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertArrayHasKey('marketing_growth_engine', $companyCapabilityCatalog['families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertArrayHasKey('domain_data_connector_operations', $companyCapabilityCatalog['families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertTrue((bool) $companyCapabilityCatalog['families']['domain_data_connector_operations']['ready'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertArrayHasKey('flow_live_read_connector_probe_operations', $companyCapabilityCatalog['families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertTrue((bool) $companyCapabilityCatalog['families']['flow_live_read_connector_probe_operations']['ready'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertSame('flow_scoped_live_read_or_sandbox_probe_no_external_mutation', $companyCapabilityCatalog['families']['flow_live_read_connector_probe_operations']['authority'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertArrayHasKey('grc_audit_controls', $companyCapabilityCatalog['families'], (string) $companyCapabilityCatalog['company_id']);
            $this->assertFalse((bool) $companyCapabilityCatalog['external_execution_allowed']);
            $this->assertFalse((bool) $companyCapabilityCatalog['external_side_effects_enabled']);
            $this->assertFalse((bool) $companyCapabilityCatalog['external_autonomy_allowed']);
            $this->assertSame(64, strlen((string) $companyCapabilityCatalog['company_capability_catalog_record_hash']));

            foreach ($companyCapabilityCatalog['flow_capabilities'] as $flowCapability) {
                $this->assertTrue((bool) $flowCapability['ready'], (string) $flowCapability['flow_id']);
                $this->assertSame($flowCapability['required_gate_count'], $flowCapability['ready_gate_count'], (string) $flowCapability['flow_id']);
                $this->assertSame([], $flowCapability['missing_gates'], (string) $flowCapability['flow_id']);
                $this->assertTrue((bool) $flowCapability['gates']['flow_data_connector_contract'], (string) $flowCapability['flow_id']);
                $this->assertTrue((bool) $flowCapability['gates']['connector_fixture_eval_suite'], (string) $flowCapability['flow_id']);
                $this->assertTrue((bool) $flowCapability['gates']['flow_live_read_probe_contract'], (string) $flowCapability['flow_id']);
                $this->assertTrue((bool) $flowCapability['gates']['flow_live_read_probe_evidence_matrix'], (string) $flowCapability['flow_id']);
                $this->assertFalse((bool) $flowCapability['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $flowCapability['flow_capability_catalog_record_hash']));
            }
        }

        $integrationReadinessExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-integration-readiness-status',
            '--json' => true,
        ]);
        $integrationReadinessPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $integrationReadinessExit, Artisan::output());
        $this->assertTrue((bool) $integrationReadinessPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_integration_readiness_status.v1', $integrationReadinessPayload['schema']);
        $this->assertSame('enterprise_company_integrations_ready_external_launch_still_blocked', $integrationReadinessPayload['status']);
        $this->assertSame(9, $integrationReadinessPayload['summary']['company_count']);
        $this->assertSame(9, $integrationReadinessPayload['summary']['integration_ready_company_count']);
        $this->assertSame(0, $integrationReadinessPayload['summary']['attention_company_count']);
        $this->assertSame($integrationReadinessPayload['summary']['required_gate_count'], $integrationReadinessPayload['summary']['ready_gate_count']);
        $this->assertSame($integrationReadinessPayload['summary']['flow_integration_count'], $integrationReadinessPayload['summary']['ready_flow_integration_count']);
        $this->assertSame(1.0, (float) $integrationReadinessPayload['summary']['average_integration_score']);
        $this->assertSame(0, $integrationReadinessPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $integrationReadinessPayload['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $integrationReadinessPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $integrationReadinessPayload['policy']['external_side_effects_enabled']);
        $this->assertFalse((bool) $integrationReadinessPayload['policy']['external_launch_allowed']);
        $this->assertTrue((bool) $integrationReadinessPayload['policy']['integration_readiness_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $integrationReadinessPayload['enterprise_company_integration_readiness_status_hash']));

        foreach ($integrationReadinessPayload['companies'] as $companyIntegration) {
            $this->assertTrue((bool) $companyIntegration['integration_ready'], (string) $companyIntegration['company_id']);
            $this->assertSame($companyIntegration['required_gate_count'], $companyIntegration['ready_gate_count'], (string) $companyIntegration['company_id']);
            $this->assertSame($companyIntegration['flow_integration_count'], $companyIntegration['ready_flow_integration_count'], (string) $companyIntegration['company_id']);
            $this->assertSame([], $companyIntegration['missing_gates'], (string) $companyIntegration['company_id']);
            $this->assertSame(1.0, (float) $companyIntegration['integration_score'], (string) $companyIntegration['company_id']);
            $this->assertGreaterThanOrEqual(5, $companyIntegration['source_catalog_counts']['finance_treasury'], (string) $companyIntegration['company_id']);
            $this->assertGreaterThanOrEqual($companyIntegration['connector_count'], $companyIntegration['connector_integration_counts']['secret_binding_plans'], (string) $companyIntegration['company_id']);
            $this->assertGreaterThanOrEqual($companyIntegration['expected_flow_count'], $companyIntegration['flow_workbench_counts']['finance_research'], (string) $companyIntegration['company_id']);
            $this->assertFalse((bool) $companyIntegration['external_execution_allowed']);
            $this->assertFalse((bool) $companyIntegration['external_side_effects_enabled']);
            $this->assertSame(64, strlen((string) $companyIntegration['company_integration_readiness_record_hash']));

            foreach ($companyIntegration['flow_integrations'] as $flowIntegration) {
                $this->assertTrue((bool) $flowIntegration['integration_ready'], (string) $flowIntegration['flow_id']);
                $this->assertSame($flowIntegration['required_gate_count'], $flowIntegration['ready_gate_count'], (string) $flowIntegration['flow_id']);
                $this->assertSame([], $flowIntegration['missing_gates'], (string) $flowIntegration['flow_id']);
                $this->assertFalse((bool) $flowIntegration['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $flowIntegration['flow_integration_readiness_record_hash']));
            }
        }

        $workloadAgentTemplateExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-domain-workload-agent-template-status',
            '--json' => true,
        ]);
        $workloadAgentTemplatePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $workloadAgentTemplateExit, Artisan::output());
        $this->assertTrue((bool) $workloadAgentTemplatePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_domain_workload_agent_template_status.v1', $workloadAgentTemplatePayload['schema']);
        $this->assertSame('enterprise_domain_workload_agent_templates_ready_external_effects_blocked', $workloadAgentTemplatePayload['status']);
        $this->assertSame(9, $workloadAgentTemplatePayload['summary']['company_count']);
        $this->assertSame(9, $workloadAgentTemplatePayload['summary']['ready_company_count']);
        $this->assertSame(0, $workloadAgentTemplatePayload['summary']['attention_company_count']);
        $this->assertSame(59, $workloadAgentTemplatePayload['summary']['expected_flow_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['expected_flow_count'], $workloadAgentTemplatePayload['summary']['template_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['ready_template_count']);
        $this->assertGreaterThanOrEqual($workloadAgentTemplatePayload['summary']['template_count'] * 8, $workloadAgentTemplatePayload['summary']['skill_count']);
        $this->assertGreaterThanOrEqual($workloadAgentTemplatePayload['summary']['template_count'] * 3, $workloadAgentTemplatePayload['summary']['subagent_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['distribution_package_ready_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['rollout_plan_ready_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['tool_permission_matrix_ready_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['execution_surface_binding_ready_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['template_count'], $workloadAgentTemplatePayload['summary']['fixture_smoke_contract_ready_count']);
        $this->assertSame($workloadAgentTemplatePayload['summary']['required_gate_count'], $workloadAgentTemplatePayload['summary']['ready_gate_count']);
        $this->assertFalse((bool) $workloadAgentTemplatePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $workloadAgentTemplatePayload['policy']['external_side_effects_enabled']);
        $this->assertTrue((bool) $workloadAgentTemplatePayload['policy']['skills_connectors_subagents_required']);
        $this->assertTrue((bool) $workloadAgentTemplatePayload['policy']['plugin_and_managed_agent_cookbook_packages_required']);
        $this->assertTrue((bool) $workloadAgentTemplatePayload['policy']['rollout_smoke_rollback_and_evidence_plan_required']);
        $this->assertTrue((bool) $workloadAgentTemplatePayload['policy']['surface_bindings_fixture_smoke_and_tool_permission_matrix_required']);
        $this->assertTrue((bool) $workloadAgentTemplatePayload['policy']['long_running_sessions_per_tool_permissions_vault_and_audit_required']);
        $this->assertSame(64, strlen((string) $workloadAgentTemplatePayload['enterprise_domain_workload_agent_template_status_hash']));

        foreach ($workloadAgentTemplatePayload['companies'] as $companyWorkloadTemplates) {
            $this->assertTrue((bool) $companyWorkloadTemplates['ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['expected_flow_count'], $companyWorkloadTemplates['template_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['ready_template_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['distribution_package_ready_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['rollout_plan_ready_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['tool_permission_matrix_ready_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['execution_surface_binding_ready_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['template_count'], $companyWorkloadTemplates['fixture_smoke_contract_ready_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame($companyWorkloadTemplates['required_gate_count'], $companyWorkloadTemplates['ready_gate_count'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame([], $companyWorkloadTemplates['missing_gates'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['reference_architecture_bound'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['template_policy_enterprise_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['template_coverage_complete'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['template_records_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['distribution_packages_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['rollout_plans_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['tool_permission_matrices_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['execution_surface_bindings_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['fixture_smoke_contracts_ready'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertTrue((bool) $companyWorkloadTemplates['gates']['external_effects_blocked'], (string) $companyWorkloadTemplates['company_id']);
            $this->assertSame(64, strlen((string) $companyWorkloadTemplates['source_hashes']['workload_template_stack_hash']));
            $this->assertSame(64, strlen((string) $companyWorkloadTemplates['company_workload_agent_template_status_hash']));

            foreach ($companyWorkloadTemplates['template_records'] as $templateRecord) {
                $this->assertTrue((bool) $templateRecord['ready'], (string) $templateRecord['template_id']);
                $this->assertSame($templateRecord['required_gate_count'], $templateRecord['ready_gate_count'], (string) $templateRecord['template_id']);
                $this->assertSame([], $templateRecord['missing_gates'], (string) $templateRecord['template_id']);
                $this->assertGreaterThanOrEqual(8, $templateRecord['skill_count'], (string) $templateRecord['template_id']);
                $this->assertGreaterThanOrEqual(1, $templateRecord['required_connector_count'], (string) $templateRecord['template_id']);
                $this->assertGreaterThanOrEqual(3, $templateRecord['subagent_count'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['runtime_contract_enterprise_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['approval_contract_blocks_external_effects'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['distribution_package_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['rollout_plan_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['tool_permission_matrix_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['execution_surface_bindings_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['gates']['fixture_smoke_contract_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['distribution_package_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['rollout_plan_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['tool_permission_matrix_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['execution_surface_bindings_ready'], (string) $templateRecord['template_id']);
                $this->assertTrue((bool) $templateRecord['fixture_smoke_contract_ready'], (string) $templateRecord['template_id']);
                $this->assertSame(64, strlen((string) $templateRecord['distribution_package_hash']));
                $this->assertSame(64, strlen((string) $templateRecord['rollout_plan_hash']));
                $this->assertSame(64, strlen((string) $templateRecord['tool_permission_matrix_hash']));
                $this->assertSame(64, strlen((string) $templateRecord['execution_surface_binding_hash']));
                $this->assertSame(64, strlen((string) $templateRecord['fixture_smoke_contract_hash']));
                $this->assertFalse((bool) $templateRecord['external_execution_allowed']);
                $this->assertSame(64, strlen((string) $templateRecord['template_status_record_hash']));
            }
        }

        $domainOperatingModelExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-operating-model-certification-status',
            '--json' => true,
        ]);
        $domainOperatingModelPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainOperatingModelExit, Artisan::output());
        $this->assertTrue((bool) $domainOperatingModelPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_operating_model_certification_status.v1', $domainOperatingModelPayload['schema']);
        $this->assertSame('enterprise_company_domain_operating_models_certified_external_effects_blocked', $domainOperatingModelPayload['status']);
        $this->assertSame(9, $domainOperatingModelPayload['summary']['company_count']);
        $this->assertSame(9, $domainOperatingModelPayload['summary']['certified_company_count']);
        $this->assertSame(0, $domainOperatingModelPayload['summary']['attention_company_count']);
        $this->assertSame($domainOperatingModelPayload['summary']['required_gate_count'], $domainOperatingModelPayload['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $domainOperatingModelPayload['summary']['average_certification_score']);
        $this->assertSame(0, $domainOperatingModelPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $domainOperatingModelPayload['summary']['external_side_effects_enabled_count']);
        $this->assertTrue((bool) $domainOperatingModelPayload['policy']['claude_financial_services_pattern_generalized_to_all_domains']);
        $this->assertTrue((bool) $domainOperatingModelPayload['policy']['skills_connectors_subagents_receipts_and_policy_gates_required']);
        $this->assertFalse((bool) $domainOperatingModelPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $domainOperatingModelPayload['policy']['external_side_effects_enabled']);
        $this->assertSame(64, strlen((string) $domainOperatingModelPayload['enterprise_company_domain_operating_model_certification_status_hash']));

        foreach ($domainOperatingModelPayload['companies'] as $companyDomainOperatingModel) {
            $this->assertTrue((bool) $companyDomainOperatingModel['domain_operating_model_certified'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertSame('target_9_enterprise_domain_operating_model_external_effects_blocked', $companyDomainOperatingModel['certification_grade'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertSame($companyDomainOperatingModel['required_gate_count'], $companyDomainOperatingModel['ready_gate_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertSame([], $companyDomainOperatingModel['missing_gates'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertSame(1.0, (float) $companyDomainOperatingModel['certification_score'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertNotSame('', (string) $companyDomainOperatingModel['domain_archetype'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual(12, $companyDomainOperatingModel['coverage_counts']['source_basis_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual(8, $companyDomainOperatingModel['coverage_counts']['official_framework_repository_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual(3, $companyDomainOperatingModel['coverage_counts']['domain_repository_candidate_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['workload_agent_template_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['workload_template_coverage_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'] * 3, $companyDomainOperatingModel['coverage_counts']['workload_template_subagent_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'] * 8, $companyDomainOperatingModel['coverage_counts']['workload_template_skill_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['domain_solution_playbook_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['business_execution_cell_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['provider_route_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['data_connector_contract_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainOperatingModel['expected_flow_count'], $companyDomainOperatingModel['coverage_counts']['live_read_probe_contract_count'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertTrue((bool) $companyDomainOperatingModel['gates']['external_research_and_repository_basis_enterprise_ready'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertTrue((bool) $companyDomainOperatingModel['gates']['domain_workload_agent_templates_cover_flows'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertTrue((bool) $companyDomainOperatingModel['gates']['domain_solution_suite_and_execution_mesh_cover_flows'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertTrue((bool) $companyDomainOperatingModel['gates']['provider_data_and_live_read_connector_model_cover_flows'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertTrue((bool) $companyDomainOperatingModel['gates']['domain_model_blocks_external_effects_by_default'], (string) $companyDomainOperatingModel['company_id']);
            $this->assertFalse((bool) $companyDomainOperatingModel['external_execution_allowed']);
            $this->assertFalse((bool) $companyDomainOperatingModel['external_side_effects_enabled']);
            $this->assertSame(64, strlen((string) $companyDomainOperatingModel['source_hashes']['domain_workload_agent_template_stack_hash']));
            $this->assertSame(64, strlen((string) $companyDomainOperatingModel['company_domain_operating_model_certification_record_hash']));
        }

        $domainToolExecutionExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-tool-execution-readiness-status',
            '--json' => true,
        ]);
        $domainToolExecutionPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $domainToolExecutionExit, Artisan::output());
        $this->assertTrue((bool) $domainToolExecutionPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_tool_execution_readiness_status.v1', $domainToolExecutionPayload['schema']);
        $this->assertSame('enterprise_company_domain_tool_execution_ready_external_effects_blocked', $domainToolExecutionPayload['status']);
        $this->assertSame(9, $domainToolExecutionPayload['summary']['company_count']);
        $this->assertSame(9, $domainToolExecutionPayload['summary']['tool_execution_ready_company_count']);
        $this->assertSame($domainToolExecutionPayload['summary']['required_gate_count'], $domainToolExecutionPayload['summary']['ready_gate_count']);
        $this->assertSame($domainToolExecutionPayload['summary']['flow_tool_execution_count'], $domainToolExecutionPayload['summary']['ready_flow_tool_execution_count']);
        $this->assertSame(0, $domainToolExecutionPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $domainToolExecutionPayload['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $domainToolExecutionPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $domainToolExecutionPayload['policy']['external_side_effects_enabled']);
        $this->assertContains('orchestration_tool_plan', $domainToolExecutionPayload['policy']['required_tool_evidence']);
        $this->assertContains('unreviewed_tool_install', $domainToolExecutionPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $domainToolExecutionPayload['enterprise_company_domain_tool_execution_readiness_status_hash']));

        foreach ($domainToolExecutionPayload['companies'] as $companyDomainToolExecution) {
            $this->assertTrue((bool) $companyDomainToolExecution['tool_execution_ready'], (string) $companyDomainToolExecution['company_id']);
            $this->assertSame('target_9_domain_tool_execution_ready_external_effects_blocked', $companyDomainToolExecution['tool_execution_grade'], (string) $companyDomainToolExecution['company_id']);
            $this->assertSame($companyDomainToolExecution['required_gate_count'], $companyDomainToolExecution['ready_gate_count'], (string) $companyDomainToolExecution['company_id']);
            $this->assertSame([], $companyDomainToolExecution['missing_gates'], (string) $companyDomainToolExecution['company_id']);
            $this->assertTrue((bool) $companyDomainToolExecution['gates']['domain_agent_toolchain_certified'], (string) $companyDomainToolExecution['company_id']);
            $this->assertTrue((bool) $companyDomainToolExecution['gates']['certified_tool_contracts_cover_connectors'], (string) $companyDomainToolExecution['company_id']);
            $this->assertTrue((bool) $companyDomainToolExecution['gates']['flow_tool_execution_records_cover_flows'], (string) $companyDomainToolExecution['company_id']);
            $this->assertTrue((bool) $companyDomainToolExecution['gates']['external_tool_effects_blocked'], (string) $companyDomainToolExecution['company_id']);
            $this->assertGreaterThanOrEqual($companyDomainToolExecution['expected_flow_count'], $companyDomainToolExecution['ready_flow_tool_execution_count'], (string) $companyDomainToolExecution['company_id']);
            $this->assertSame(64, strlen((string) $companyDomainToolExecution['source_hashes']['domain_agent_toolchain_certification_record_hash']));
            $this->assertSame(64, strlen((string) $companyDomainToolExecution['source_hashes']['orchestration_runbook_hash']));
            $this->assertSame(64, strlen((string) $companyDomainToolExecution['company_domain_tool_execution_readiness_record_hash']));
        }

        $flowToolExecutionLedgerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-ledger-status',
            '--json' => true,
        ]);
        $flowToolExecutionLedgerPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowToolExecutionLedgerExit, Artisan::output());
        $this->assertTrue((bool) $flowToolExecutionLedgerPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_ledger_status.v1', $flowToolExecutionLedgerPayload['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_ledgers_ready_external_effects_blocked', $flowToolExecutionLedgerPayload['status']);
        $this->assertSame(9, $flowToolExecutionLedgerPayload['summary']['company_count']);
        $this->assertSame(9, $flowToolExecutionLedgerPayload['summary']['flow_tool_execution_ledger_ready_company_count']);
        $this->assertSame($flowToolExecutionLedgerPayload['summary']['ledger_record_count'], $flowToolExecutionLedgerPayload['summary']['ready_ledger_record_count']);
        $this->assertSame($flowToolExecutionLedgerPayload['summary']['required_gate_count'], $flowToolExecutionLedgerPayload['summary']['ready_gate_count']);
        $this->assertSame(0, $flowToolExecutionLedgerPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $flowToolExecutionLedgerPayload['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $flowToolExecutionLedgerPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $flowToolExecutionLedgerPayload['policy']['external_side_effects_enabled']);
        $this->assertContains('decision_receipt', $flowToolExecutionLedgerPayload['policy']['required_ledger_evidence']);
        $this->assertContains('external_effect_block_event', $flowToolExecutionLedgerPayload['policy']['required_ledger_evidence']);
        $this->assertSame(64, strlen((string) $flowToolExecutionLedgerPayload['enterprise_company_flow_tool_execution_ledger_status_hash']));

        foreach ($flowToolExecutionLedgerPayload['companies'] as $companyFlowToolExecutionLedger) {
            $this->assertTrue((bool) $companyFlowToolExecutionLedger['flow_tool_execution_ledger_ready'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertSame('target_9_flow_tool_execution_ledger_ready_external_effects_blocked', $companyFlowToolExecutionLedger['ledger_grade'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertSame($companyFlowToolExecutionLedger['required_gate_count'], $companyFlowToolExecutionLedger['ready_gate_count'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertSame([], $companyFlowToolExecutionLedger['missing_gates'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertTrue((bool) $companyFlowToolExecutionLedger['gates']['domain_tool_execution_readiness_ready'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertTrue((bool) $companyFlowToolExecutionLedger['gates']['ledger_records_cover_flows'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertTrue((bool) $companyFlowToolExecutionLedger['gates']['receipt_chains_cover_ledgers'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertTrue((bool) $companyFlowToolExecutionLedger['gates']['external_tool_effects_blocked'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertGreaterThanOrEqual($companyFlowToolExecutionLedger['expected_flow_count'], $companyFlowToolExecutionLedger['ready_ledger_record_count'], (string) $companyFlowToolExecutionLedger['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyFlowToolExecutionLedger, 'ledger_records.0.receipt_chain.decision_receipt_hash')));
            $this->assertSame(64, strlen((string) data_get($companyFlowToolExecutionLedger, 'ledger_records.0.receipt_chain.audit_trail_hash')));
            $this->assertSame(64, strlen((string) $companyFlowToolExecutionLedger['source_hashes']['company_domain_tool_execution_readiness_record_hash']));
            $this->assertSame(64, strlen((string) $companyFlowToolExecutionLedger['company_flow_tool_execution_ledger_record_hash']));
            $this->assertFalse((bool) $companyFlowToolExecutionLedger['external_execution_allowed']);
            $this->assertFalse((bool) $companyFlowToolExecutionLedger['external_side_effects_enabled']);
        }

        $flowToolRuntimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-runtime-register',
            '--json' => true,
        ]);
        $flowToolRuntimeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowToolRuntimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $flowToolRuntimeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_runtime_register.v1', $flowToolRuntimeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_runtime_registered_external_effects_blocked', $flowToolRuntimeRegisterPayload['status']);
        $this->assertSame($flowToolRuntimeRegisterPayload['summary']['expected_ledger_record_count'], $flowToolRuntimeRegisterPayload['summary']['registered_run_count']);
        $this->assertSame($flowToolExecutionLedgerPayload['summary']['ledger_record_count'], $flowToolRuntimeRegisterPayload['summary']['registered_run_count']);
        $this->assertSame(0, $flowToolRuntimeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $flowToolRuntimeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($flowToolExecutionLedgerPayload['summary']['ledger_record_count'], AtlasToolRun::query()->where('surface', 'holding_company_tool_runtime')->count());
        $this->assertSame(64, strlen((string) $flowToolRuntimeRegisterPayload['enterprise_company_flow_tool_execution_runtime_register_hash']));

        $flowToolRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-flow-tool-execution-runtime-status',
            '--json' => true,
        ]);
        $flowToolRuntimeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $flowToolRuntimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $flowToolRuntimeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_flow_tool_execution_runtime_status.v1', $flowToolRuntimeStatusPayload['schema']);
        $this->assertSame('enterprise_company_flow_tool_execution_runtime_ready_external_effects_blocked', $flowToolRuntimeStatusPayload['status']);
        $this->assertSame(9, $flowToolRuntimeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $flowToolRuntimeStatusPayload['summary']['persisted_runtime_ready_company_count']);
        $this->assertSame($flowToolRuntimeStatusPayload['summary']['expected_run_count'], $flowToolRuntimeStatusPayload['summary']['ready_persisted_run_count']);
        $this->assertSame($flowToolRuntimeStatusPayload['summary']['required_gate_count'], $flowToolRuntimeStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(0, $flowToolRuntimeStatusPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $flowToolRuntimeStatusPayload['summary']['external_side_effects_enabled_count']);
        $this->assertContains('normalized_result', $flowToolRuntimeStatusPayload['policy']['required_runtime_evidence']);
        $this->assertContains('unreceipted_tool_invocation', $flowToolRuntimeStatusPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $flowToolRuntimeStatusPayload['enterprise_company_flow_tool_execution_runtime_status_hash']));

        foreach ($flowToolRuntimeStatusPayload['companies'] as $companyFlowToolRuntimeStatus) {
            $this->assertTrue((bool) $companyFlowToolRuntimeStatus['persisted_runtime_ready'], (string) $companyFlowToolRuntimeStatus['company_id']);
            $this->assertSame('target_9_persisted_flow_tool_execution_runtime_ready_external_effects_blocked', $companyFlowToolRuntimeStatus['runtime_grade'], (string) $companyFlowToolRuntimeStatus['company_id']);
            $this->assertSame([], $companyFlowToolRuntimeStatus['missing_gates'], (string) $companyFlowToolRuntimeStatus['company_id']);
            $this->assertTrue((bool) $companyFlowToolRuntimeStatus['gates']['persisted_runtime_records_cover_ledgers'], (string) $companyFlowToolRuntimeStatus['company_id']);
            $this->assertGreaterThanOrEqual($companyFlowToolRuntimeStatus['expected_run_count'], $companyFlowToolRuntimeStatus['ready_persisted_run_count'], (string) $companyFlowToolRuntimeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyFlowToolRuntimeStatus, 'runtime_records.0.receipt_chain.decision_receipt_hash')));
            $this->assertSame(64, strlen((string) data_get($companyFlowToolRuntimeStatus, 'runtime_records.0.receipt_chain.tool_run_receipt_hash')));
            $this->assertFalse((bool) $companyFlowToolRuntimeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyFlowToolRuntimeStatus['external_side_effects_enabled']);
        }

        $adapterEnvelopeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-adapter-execution-envelope-register',
            '--json' => true,
        ]);
        $adapterEnvelopeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $adapterEnvelopeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $adapterEnvelopeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_adapter_execution_envelope_register.v1', $adapterEnvelopeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_domain_adapter_execution_envelopes_registered_external_effects_blocked', $adapterEnvelopeRegisterPayload['status']);
        $this->assertSame($adapterEnvelopeRegisterPayload['summary']['expected_connector_readiness_count'], $adapterEnvelopeRegisterPayload['summary']['registered_envelope_count']);
        $this->assertGreaterThanOrEqual($flowToolExecutionLedgerPayload['summary']['ledger_record_count'], $adapterEnvelopeRegisterPayload['summary']['registered_envelope_count']);
        $this->assertSame(0, $adapterEnvelopeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $adapterEnvelopeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($adapterEnvelopeRegisterPayload['summary']['registered_envelope_count'], AtlasToolRun::query()->where('surface', 'holding_company_adapter_runtime')->count());
        $this->assertSame(64, strlen((string) $adapterEnvelopeRegisterPayload['enterprise_company_domain_adapter_execution_envelope_register_hash']));

        $adapterEnvelopeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-domain-adapter-execution-envelope-status',
            '--json' => true,
        ]);
        $adapterEnvelopeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $adapterEnvelopeStatusExit, Artisan::output());
        $this->assertTrue((bool) $adapterEnvelopeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_domain_adapter_execution_envelope_status.v1', $adapterEnvelopeStatusPayload['schema']);
        $this->assertSame('enterprise_company_domain_adapter_execution_envelopes_ready_external_effects_blocked', $adapterEnvelopeStatusPayload['status']);
        $this->assertSame(9, $adapterEnvelopeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $adapterEnvelopeStatusPayload['summary']['adapter_envelope_ready_company_count']);
        $this->assertSame($adapterEnvelopeStatusPayload['summary']['expected_envelope_count'], $adapterEnvelopeStatusPayload['summary']['ready_persisted_envelope_count']);
        $this->assertSame($adapterEnvelopeStatusPayload['summary']['required_gate_count'], $adapterEnvelopeStatusPayload['summary']['ready_gate_count']);
        $this->assertContains('adapter_envelope_receipt', $adapterEnvelopeStatusPayload['policy']['required_runtime_evidence']);
        $this->assertContains('unreceipted_adapter_invocation', $adapterEnvelopeStatusPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $adapterEnvelopeStatusPayload['enterprise_company_domain_adapter_execution_envelope_status_hash']));

        foreach ($adapterEnvelopeStatusPayload['companies'] as $companyAdapterEnvelopeStatus) {
            $this->assertTrue((bool) $companyAdapterEnvelopeStatus['adapter_envelope_ready'], (string) $companyAdapterEnvelopeStatus['company_id']);
            $this->assertSame('target_9_domain_adapter_execution_envelopes_ready_external_effects_blocked', $companyAdapterEnvelopeStatus['adapter_envelope_grade'], (string) $companyAdapterEnvelopeStatus['company_id']);
            $this->assertSame([], $companyAdapterEnvelopeStatus['missing_gates'], (string) $companyAdapterEnvelopeStatus['company_id']);
            $this->assertTrue((bool) $companyAdapterEnvelopeStatus['gates']['persisted_adapter_envelopes_cover_connectors'], (string) $companyAdapterEnvelopeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyAdapterEnvelopeStatus, 'runtime_records.0.receipt_chain.adapter_envelope_receipt_hash')));
            $this->assertFalse((bool) $companyAdapterEnvelopeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyAdapterEnvelopeStatus['external_side_effects_enabled']);
        }

        $workProductRuntimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-work-product-runtime-register',
            '--json' => true,
        ]);
        $workProductRuntimeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $workProductRuntimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $workProductRuntimeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_work_product_runtime_register.v1', $workProductRuntimeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_work_product_runtime_registered_external_delivery_blocked', $workProductRuntimeRegisterPayload['status']);
        $this->assertSame($workProductRuntimeRegisterPayload['summary']['expected_flow_count'], $workProductRuntimeRegisterPayload['summary']['registered_work_product_run_count']);
        $this->assertGreaterThanOrEqual(59, $workProductRuntimeRegisterPayload['summary']['registered_work_product_run_count']);
        $this->assertSame(0, $workProductRuntimeRegisterPayload['summary']['external_delivery_allowed_count']);
        $this->assertSame(0, $workProductRuntimeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $workProductRuntimeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($workProductRuntimeRegisterPayload['summary']['registered_work_product_run_count'], AtlasToolRun::query()->where('surface', 'holding_company_work_product_runtime')->count());
        $this->assertContains('work_product_receipt', $workProductRuntimeRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertSame(64, strlen((string) $workProductRuntimeRegisterPayload['enterprise_company_work_product_runtime_register_hash']));

        $workProductRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-work-product-runtime-status',
            '--json' => true,
        ]);
        $workProductRuntimeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $workProductRuntimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $workProductRuntimeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_work_product_runtime_status.v1', $workProductRuntimeStatusPayload['schema']);
        $this->assertSame('enterprise_company_work_product_runtime_ready_external_delivery_blocked', $workProductRuntimeStatusPayload['status']);
        $this->assertSame(9, $workProductRuntimeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $workProductRuntimeStatusPayload['summary']['work_product_runtime_ready_company_count']);
        $this->assertSame($workProductRuntimeStatusPayload['summary']['expected_flow_count'], $workProductRuntimeStatusPayload['summary']['ready_persisted_work_product_run_count']);
        $this->assertSame($workProductRuntimeStatusPayload['summary']['required_gate_count'], $workProductRuntimeStatusPayload['summary']['ready_gate_count']);
        $this->assertContains('replay_receipt', $workProductRuntimeStatusPayload['policy']['required_runtime_evidence']);
        $this->assertContains('claim_without_source_reference', $workProductRuntimeStatusPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $workProductRuntimeStatusPayload['enterprise_company_work_product_runtime_status_hash']));

        foreach ($workProductRuntimeStatusPayload['companies'] as $companyWorkProductRuntimeStatus) {
            $this->assertTrue((bool) $companyWorkProductRuntimeStatus['work_product_runtime_ready'], (string) $companyWorkProductRuntimeStatus['company_id']);
            $this->assertSame('target_9_work_product_runtime_ready_external_delivery_blocked', $companyWorkProductRuntimeStatus['runtime_grade'], (string) $companyWorkProductRuntimeStatus['company_id']);
            $this->assertSame([], $companyWorkProductRuntimeStatus['missing_gates'], (string) $companyWorkProductRuntimeStatus['company_id']);
            $this->assertTrue((bool) $companyWorkProductRuntimeStatus['gates']['persisted_work_product_runs_cover_flows'], (string) $companyWorkProductRuntimeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyWorkProductRuntimeStatus, 'runtime_records.0.receipt_chain.work_product_receipt_hash')));
            $this->assertFalse((bool) $companyWorkProductRuntimeStatus['external_delivery_allowed']);
            $this->assertFalse((bool) $companyWorkProductRuntimeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyWorkProductRuntimeStatus['external_side_effects_enabled']);
        }

        $operatingBlueprintRuntimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-blueprint-runtime-register',
            '--json' => true,
        ]);
        $operatingBlueprintRuntimeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operatingBlueprintRuntimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $operatingBlueprintRuntimeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_blueprint_runtime_register.v1', $operatingBlueprintRuntimeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_operating_blueprint_runtime_registered_external_effects_blocked', $operatingBlueprintRuntimeRegisterPayload['status']);
        $this->assertSame($operatingBlueprintRuntimeRegisterPayload['summary']['expected_flow_count'], $operatingBlueprintRuntimeRegisterPayload['summary']['registered_blueprint_run_count']);
        $this->assertGreaterThanOrEqual(59, $operatingBlueprintRuntimeRegisterPayload['summary']['registered_blueprint_run_count']);
        $this->assertSame(0, $operatingBlueprintRuntimeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $operatingBlueprintRuntimeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($operatingBlueprintRuntimeRegisterPayload['summary']['registered_blueprint_run_count'], AtlasToolRun::query()->where('surface', 'holding_company_blueprint_runtime')->count());
        $this->assertContains('blueprint_receipt', $operatingBlueprintRuntimeRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertSame(64, strlen((string) $operatingBlueprintRuntimeRegisterPayload['enterprise_company_operating_blueprint_runtime_register_hash']));

        $operatingBlueprintRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-blueprint-runtime-status',
            '--json' => true,
        ]);
        $operatingBlueprintRuntimeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operatingBlueprintRuntimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $operatingBlueprintRuntimeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_blueprint_runtime_status.v1', $operatingBlueprintRuntimeStatusPayload['schema']);
        $this->assertSame('enterprise_company_operating_blueprint_runtime_ready_external_effects_blocked', $operatingBlueprintRuntimeStatusPayload['status']);
        $this->assertSame(9, $operatingBlueprintRuntimeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $operatingBlueprintRuntimeStatusPayload['summary']['operating_blueprint_runtime_ready_company_count']);
        $this->assertSame($operatingBlueprintRuntimeStatusPayload['summary']['expected_flow_count'], $operatingBlueprintRuntimeStatusPayload['summary']['ready_persisted_blueprint_run_count']);
        $this->assertSame($operatingBlueprintRuntimeStatusPayload['summary']['required_gate_count'], $operatingBlueprintRuntimeStatusPayload['summary']['ready_gate_count']);
        $this->assertContains('permission_receipt', $operatingBlueprintRuntimeStatusPayload['policy']['required_runtime_evidence']);
        $this->assertContains('skip_eval_replay', $operatingBlueprintRuntimeStatusPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $operatingBlueprintRuntimeStatusPayload['enterprise_company_operating_blueprint_runtime_status_hash']));

        foreach ($operatingBlueprintRuntimeStatusPayload['companies'] as $companyOperatingBlueprintRuntimeStatus) {
            $this->assertTrue((bool) $companyOperatingBlueprintRuntimeStatus['operating_blueprint_runtime_ready'], (string) $companyOperatingBlueprintRuntimeStatus['company_id']);
            $this->assertSame('target_9_operating_blueprint_runtime_ready_external_effects_blocked', $companyOperatingBlueprintRuntimeStatus['runtime_grade'], (string) $companyOperatingBlueprintRuntimeStatus['company_id']);
            $this->assertSame([], $companyOperatingBlueprintRuntimeStatus['missing_gates'], (string) $companyOperatingBlueprintRuntimeStatus['company_id']);
            $this->assertTrue((bool) $companyOperatingBlueprintRuntimeStatus['gates']['persisted_blueprint_runs_cover_flows'], (string) $companyOperatingBlueprintRuntimeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyOperatingBlueprintRuntimeStatus, 'runtime_records.0.receipt_chain.blueprint_receipt_hash')));
            $this->assertFalse((bool) $companyOperatingBlueprintRuntimeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyOperatingBlueprintRuntimeStatus['external_side_effects_enabled']);
        }

        $businessRuntimePersistenceRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-business-runtime-persistence-register',
            '--json' => true,
        ]);
        $businessRuntimePersistenceRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessRuntimePersistenceRegisterExit, Artisan::output());
        $this->assertTrue((bool) $businessRuntimePersistenceRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_business_runtime_persistence_register.v1', $businessRuntimePersistenceRegisterPayload['schema']);
        $this->assertSame('enterprise_company_business_runtime_persistence_registered_external_effects_blocked', $businessRuntimePersistenceRegisterPayload['status']);
        $this->assertSame(9, $businessRuntimePersistenceRegisterPayload['summary']['business_runtime_layer_count']);
        $this->assertSame($businessRuntimePersistenceRegisterPayload['summary']['expected_business_runtime_run_count'], $businessRuntimePersistenceRegisterPayload['summary']['registered_business_runtime_run_count']);
        $this->assertGreaterThanOrEqual(531, $businessRuntimePersistenceRegisterPayload['summary']['registered_business_runtime_run_count']);
        $this->assertSame(0, $businessRuntimePersistenceRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $businessRuntimePersistenceRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($businessRuntimePersistenceRegisterPayload['summary']['registered_business_runtime_run_count'], AtlasToolRun::query()->where('surface', 'holding_company_business_runtime')->count());
        $this->assertContains('business_runtime_receipt', $businessRuntimePersistenceRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertSame(64, strlen((string) $businessRuntimePersistenceRegisterPayload['enterprise_company_business_runtime_persistence_register_hash']));

        $businessRuntimePersistenceStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-business-runtime-persistence-status',
            '--json' => true,
        ]);
        $businessRuntimePersistenceStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessRuntimePersistenceStatusExit, Artisan::output());
        $this->assertTrue((bool) $businessRuntimePersistenceStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_business_runtime_persistence_status.v1', $businessRuntimePersistenceStatusPayload['schema']);
        $this->assertSame('enterprise_company_business_runtime_persistence_ready_external_effects_blocked', $businessRuntimePersistenceStatusPayload['status']);
        $this->assertSame(9, $businessRuntimePersistenceStatusPayload['summary']['company_count']);
        $this->assertSame(9, $businessRuntimePersistenceStatusPayload['summary']['business_runtime_layer_count']);
        $this->assertSame(9, $businessRuntimePersistenceStatusPayload['summary']['business_runtime_persistence_ready_company_count']);
        $this->assertSame($businessRuntimePersistenceStatusPayload['summary']['expected_business_runtime_run_count'], $businessRuntimePersistenceStatusPayload['summary']['ready_persisted_business_runtime_run_count']);
        $this->assertSame($businessRuntimePersistenceStatusPayload['summary']['required_gate_count'], $businessRuntimePersistenceStatusPayload['summary']['ready_gate_count']);
        $this->assertContains('source_status_receipt', $businessRuntimePersistenceStatusPayload['policy']['required_runtime_evidence']);
        $this->assertContains('collect_payment', $businessRuntimePersistenceStatusPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $businessRuntimePersistenceStatusPayload['enterprise_company_business_runtime_persistence_status_hash']));

        foreach ($businessRuntimePersistenceStatusPayload['companies'] as $companyBusinessRuntimePersistenceStatus) {
            $this->assertTrue((bool) $companyBusinessRuntimePersistenceStatus['business_runtime_persistence_ready'], (string) $companyBusinessRuntimePersistenceStatus['company_id']);
            $this->assertSame('target_9_business_runtime_persistence_ready_external_effects_blocked', $companyBusinessRuntimePersistenceStatus['runtime_grade'], (string) $companyBusinessRuntimePersistenceStatus['company_id']);
            $this->assertSame([], $companyBusinessRuntimePersistenceStatus['missing_gates'], (string) $companyBusinessRuntimePersistenceStatus['company_id']);
            $this->assertTrue((bool) $companyBusinessRuntimePersistenceStatus['gates']['persisted_business_runtime_runs_cover_all_layers_and_flows'], (string) $companyBusinessRuntimePersistenceStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyBusinessRuntimePersistenceStatus, 'runtime_records.0.receipt_chain.business_runtime_receipt_hash')));
            $this->assertFalse((bool) $companyBusinessRuntimePersistenceStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyBusinessRuntimePersistenceStatus['external_side_effects_enabled']);
        }

        $capabilityRuntimeMeshRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-capability-runtime-mesh-register',
            '--json' => true,
        ]);
        $capabilityRuntimeMeshRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $capabilityRuntimeMeshRegisterExit, Artisan::output());
        $this->assertTrue((bool) $capabilityRuntimeMeshRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_capability_runtime_mesh_register.v1', $capabilityRuntimeMeshRegisterPayload['schema']);
        $this->assertSame('enterprise_company_capability_runtime_mesh_registered_external_effects_blocked', $capabilityRuntimeMeshRegisterPayload['status']);
        $this->assertSame(12, $capabilityRuntimeMeshRegisterPayload['summary']['capability_count']);
        $this->assertSame($capabilityRuntimeMeshRegisterPayload['summary']['expected_capability_runtime_run_count'], $capabilityRuntimeMeshRegisterPayload['summary']['registered_capability_runtime_run_count']);
        $this->assertGreaterThanOrEqual(708, $capabilityRuntimeMeshRegisterPayload['summary']['registered_capability_runtime_run_count']);
        $this->assertSame(0, $capabilityRuntimeMeshRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $capabilityRuntimeMeshRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($capabilityRuntimeMeshRegisterPayload['summary']['registered_capability_runtime_run_count'], AtlasToolRun::query()->where('surface', 'holding_company_enterprise_capability_runtime')->count());
        $this->assertContains('capability_runtime_receipt', $capabilityRuntimeMeshRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('trade', $capabilityRuntimeMeshRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $capabilityRuntimeMeshRegisterPayload['enterprise_company_capability_runtime_mesh_register_hash']));

        $capabilityRuntimeMeshStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-capability-runtime-mesh-status',
            '--json' => true,
        ]);
        $capabilityRuntimeMeshStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $capabilityRuntimeMeshStatusExit, Artisan::output());
        $this->assertTrue((bool) $capabilityRuntimeMeshStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_capability_runtime_mesh_status.v1', $capabilityRuntimeMeshStatusPayload['schema']);
        $this->assertSame('enterprise_company_capability_runtime_mesh_ready_external_effects_blocked', $capabilityRuntimeMeshStatusPayload['status']);
        $this->assertSame(9, $capabilityRuntimeMeshStatusPayload['summary']['company_count']);
        $this->assertSame(12, $capabilityRuntimeMeshStatusPayload['summary']['capability_count']);
        $this->assertSame(9, $capabilityRuntimeMeshStatusPayload['summary']['capability_runtime_mesh_ready_company_count']);
        $this->assertSame($capabilityRuntimeMeshStatusPayload['summary']['expected_capability_runtime_run_count'], $capabilityRuntimeMeshStatusPayload['summary']['ready_capability_runtime_run_count']);
        $this->assertSame($capabilityRuntimeMeshStatusPayload['summary']['required_gate_count'], $capabilityRuntimeMeshStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $capabilityRuntimeMeshStatusPayload['enterprise_company_capability_runtime_mesh_status_hash']));

        foreach ($capabilityRuntimeMeshStatusPayload['companies'] as $companyCapabilityRuntimeMeshStatus) {
            $this->assertTrue((bool) $companyCapabilityRuntimeMeshStatus['capability_runtime_mesh_ready'], (string) $companyCapabilityRuntimeMeshStatus['company_id']);
            $this->assertSame('target_9_enterprise_capability_runtime_mesh_ready_external_effects_blocked', $companyCapabilityRuntimeMeshStatus['runtime_grade'], (string) $companyCapabilityRuntimeMeshStatus['company_id']);
            $this->assertSame([], $companyCapabilityRuntimeMeshStatus['missing_gates'], (string) $companyCapabilityRuntimeMeshStatus['company_id']);
            $this->assertTrue((bool) $companyCapabilityRuntimeMeshStatus['gates']['persisted_capability_runtime_runs_cover_all_capabilities_and_flows'], (string) $companyCapabilityRuntimeMeshStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyCapabilityRuntimeMeshStatus, 'runtime_records.0.receipt_chain.capability_runtime_receipt_hash')));
            $this->assertFalse((bool) $companyCapabilityRuntimeMeshStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyCapabilityRuntimeMeshStatus['external_side_effects_enabled']);
        }

        $supervisedConnectorExecutionRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-supervised-connector-execution-register',
            '--json' => true,
        ]);
        $supervisedConnectorExecutionRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $supervisedConnectorExecutionRegisterExit, Artisan::output());
        $this->assertTrue((bool) $supervisedConnectorExecutionRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_supervised_connector_execution_register.v1', $supervisedConnectorExecutionRegisterPayload['schema']);
        $this->assertSame('enterprise_company_supervised_connector_execution_registered_external_effects_blocked', $supervisedConnectorExecutionRegisterPayload['status']);
        $this->assertSame($supervisedConnectorExecutionRegisterPayload['summary']['expected_supervised_connector_execution_run_count'], $supervisedConnectorExecutionRegisterPayload['summary']['registered_supervised_connector_execution_run_count']);
        $this->assertGreaterThanOrEqual(708, $supervisedConnectorExecutionRegisterPayload['summary']['registered_supervised_connector_execution_run_count']);
        $this->assertSame(0, $supervisedConnectorExecutionRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $supervisedConnectorExecutionRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($supervisedConnectorExecutionRegisterPayload['summary']['registered_supervised_connector_execution_run_count'], AtlasToolRun::query()->where('surface', 'holding_company_supervised_connector_execution')->count());
        $this->assertContains('connector_execution_receipt', $supervisedConnectorExecutionRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('credential_material_export', $supervisedConnectorExecutionRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $supervisedConnectorExecutionRegisterPayload['enterprise_company_supervised_connector_execution_register_hash']));

        $supervisedConnectorExecutionStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-supervised-connector-execution-status',
            '--json' => true,
        ]);
        $supervisedConnectorExecutionStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $supervisedConnectorExecutionStatusExit, Artisan::output());
        $this->assertTrue((bool) $supervisedConnectorExecutionStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_supervised_connector_execution_status.v1', $supervisedConnectorExecutionStatusPayload['schema']);
        $this->assertSame('enterprise_company_supervised_connector_execution_ready_external_effects_blocked', $supervisedConnectorExecutionStatusPayload['status']);
        $this->assertSame(9, $supervisedConnectorExecutionStatusPayload['summary']['company_count']);
        $this->assertSame(9, $supervisedConnectorExecutionStatusPayload['summary']['supervised_connector_execution_ready_company_count']);
        $this->assertSame($supervisedConnectorExecutionStatusPayload['summary']['expected_supervised_connector_execution_run_count'], $supervisedConnectorExecutionStatusPayload['summary']['ready_supervised_connector_execution_run_count']);
        $this->assertSame($supervisedConnectorExecutionStatusPayload['summary']['required_gate_count'], $supervisedConnectorExecutionStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $supervisedConnectorExecutionStatusPayload['enterprise_company_supervised_connector_execution_status_hash']));

        foreach ($supervisedConnectorExecutionStatusPayload['companies'] as $companySupervisedConnectorExecutionStatus) {
            $this->assertTrue((bool) $companySupervisedConnectorExecutionStatus['supervised_connector_execution_ready'], (string) $companySupervisedConnectorExecutionStatus['company_id']);
            $this->assertSame('target_9_supervised_connector_execution_ready_external_effects_blocked', $companySupervisedConnectorExecutionStatus['runtime_grade'], (string) $companySupervisedConnectorExecutionStatus['company_id']);
            $this->assertSame([], $companySupervisedConnectorExecutionStatus['missing_gates'], (string) $companySupervisedConnectorExecutionStatus['company_id']);
            $this->assertTrue((bool) $companySupervisedConnectorExecutionStatus['gates']['supervised_connector_execution_runs_cover_capability_runtime_mesh'], (string) $companySupervisedConnectorExecutionStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companySupervisedConnectorExecutionStatus, 'runtime_records.0.receipt_chain.connector_execution_receipt_hash')));
            $this->assertFalse((bool) $companySupervisedConnectorExecutionStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companySupervisedConnectorExecutionStatus['external_side_effects_enabled']);
        }

        $externalToolActivationWorkOrderRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-external-tool-activation-work-order-register',
            '--json' => true,
        ]);
        $externalToolActivationWorkOrderRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $externalToolActivationWorkOrderRegisterExit, Artisan::output());
        $this->assertTrue((bool) $externalToolActivationWorkOrderRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_external_tool_activation_work_order_register.v1', $externalToolActivationWorkOrderRegisterPayload['schema']);
        $this->assertSame('enterprise_company_external_tool_activation_work_orders_registered_external_effects_blocked', $externalToolActivationWorkOrderRegisterPayload['status']);
        $this->assertSame($externalToolActivationWorkOrderRegisterPayload['summary']['expected_external_tool_activation_work_order_count'], $externalToolActivationWorkOrderRegisterPayload['summary']['registered_external_tool_activation_work_order_count']);
        $this->assertGreaterThanOrEqual(708, $externalToolActivationWorkOrderRegisterPayload['summary']['registered_external_tool_activation_work_order_count']);
        $this->assertSame(0, $externalToolActivationWorkOrderRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $externalToolActivationWorkOrderRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($externalToolActivationWorkOrderRegisterPayload['summary']['registered_external_tool_activation_work_order_count'], AtlasToolRun::query()->where('surface', 'holding_company_external_tool_activation_work_order')->count());
        $this->assertContains('activation_work_order_receipt', $externalToolActivationWorkOrderRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('credential_material_export', $externalToolActivationWorkOrderRegisterPayload['policy']['blocked_operations']);
        $this->assertContains('external_write', $externalToolActivationWorkOrderRegisterPayload['policy']['blocked_operations']);
        $this->assertContains('trade', $externalToolActivationWorkOrderRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $externalToolActivationWorkOrderRegisterPayload['enterprise_company_external_tool_activation_work_order_register_hash']));

        $externalToolActivationWorkOrderStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-external-tool-activation-work-order-status',
            '--json' => true,
        ]);
        $externalToolActivationWorkOrderStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $externalToolActivationWorkOrderStatusExit, Artisan::output());
        $this->assertTrue((bool) $externalToolActivationWorkOrderStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_external_tool_activation_work_order_status.v1', $externalToolActivationWorkOrderStatusPayload['schema']);
        $this->assertSame('enterprise_company_external_tool_activation_work_orders_ready_external_effects_blocked', $externalToolActivationWorkOrderStatusPayload['status']);
        $this->assertSame(9, $externalToolActivationWorkOrderStatusPayload['summary']['company_count']);
        $this->assertSame(9, $externalToolActivationWorkOrderStatusPayload['summary']['external_tool_activation_work_orders_ready_company_count']);
        $this->assertSame($externalToolActivationWorkOrderStatusPayload['summary']['expected_external_tool_activation_work_order_count'], $externalToolActivationWorkOrderStatusPayload['summary']['ready_external_tool_activation_work_order_count']);
        $this->assertSame($externalToolActivationWorkOrderStatusPayload['summary']['required_gate_count'], $externalToolActivationWorkOrderStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $externalToolActivationWorkOrderStatusPayload['enterprise_company_external_tool_activation_work_order_status_hash']));

        foreach ($externalToolActivationWorkOrderStatusPayload['companies'] as $companyExternalToolActivationWorkOrderStatus) {
            $this->assertTrue((bool) $companyExternalToolActivationWorkOrderStatus['external_tool_activation_work_orders_ready'], (string) $companyExternalToolActivationWorkOrderStatus['company_id']);
            $this->assertSame('target_9_external_tool_activation_work_orders_ready_external_effects_blocked', $companyExternalToolActivationWorkOrderStatus['runtime_grade'], (string) $companyExternalToolActivationWorkOrderStatus['company_id']);
            $this->assertSame([], $companyExternalToolActivationWorkOrderStatus['missing_gates'], (string) $companyExternalToolActivationWorkOrderStatus['company_id']);
            $this->assertTrue((bool) $companyExternalToolActivationWorkOrderStatus['gates']['external_tool_activation_work_orders_cover_supervised_connector_execution'], (string) $companyExternalToolActivationWorkOrderStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyExternalToolActivationWorkOrderStatus, 'work_order_records.0.receipt_chain.activation_work_order_receipt_hash')));
            $this->assertFalse((bool) $companyExternalToolActivationWorkOrderStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyExternalToolActivationWorkOrderStatus['external_side_effects_enabled']);
        }

        $externalToolActivationPacketRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-external-tool-activation-packet-register',
            '--json' => true,
        ]);
        $externalToolActivationPacketRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $externalToolActivationPacketRegisterExit, Artisan::output());
        $this->assertTrue((bool) $externalToolActivationPacketRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_external_tool_activation_packet_register.v1', $externalToolActivationPacketRegisterPayload['schema']);
        $this->assertSame('enterprise_company_external_tool_activation_packets_registered_external_effects_blocked', $externalToolActivationPacketRegisterPayload['status']);
        $this->assertSame($externalToolActivationPacketRegisterPayload['summary']['expected_external_tool_activation_packet_count'], $externalToolActivationPacketRegisterPayload['summary']['registered_external_tool_activation_packet_count']);
        $this->assertGreaterThanOrEqual(708, $externalToolActivationPacketRegisterPayload['summary']['registered_external_tool_activation_packet_count']);
        $this->assertSame(0, $externalToolActivationPacketRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $externalToolActivationPacketRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame($externalToolActivationPacketRegisterPayload['summary']['registered_external_tool_activation_packet_count'], AtlasToolRun::query()->where('surface', 'holding_company_external_tool_activation_packet')->count());
        $this->assertContains('vault_binding_contract', $externalToolActivationPacketRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('sandbox_probe', $externalToolActivationPacketRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('eval_replay', $externalToolActivationPacketRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('credential_material_export', $externalToolActivationPacketRegisterPayload['policy']['blocked_operations']);
        $this->assertContains('external_write', $externalToolActivationPacketRegisterPayload['policy']['blocked_operations']);
        $this->assertContains('trade', $externalToolActivationPacketRegisterPayload['policy']['blocked_operations']);
        $this->assertContains('deploy', $externalToolActivationPacketRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $externalToolActivationPacketRegisterPayload['enterprise_company_external_tool_activation_packet_register_hash']));

        $externalToolActivationPacketStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-external-tool-activation-packet-status',
            '--json' => true,
        ]);
        $externalToolActivationPacketStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $externalToolActivationPacketStatusExit, Artisan::output());
        $this->assertTrue((bool) $externalToolActivationPacketStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_external_tool_activation_packet_status.v1', $externalToolActivationPacketStatusPayload['schema']);
        $this->assertSame('enterprise_company_external_tool_activation_packets_ready_external_effects_blocked', $externalToolActivationPacketStatusPayload['status']);
        $this->assertSame(9, $externalToolActivationPacketStatusPayload['summary']['company_count']);
        $this->assertSame(9, $externalToolActivationPacketStatusPayload['summary']['external_tool_activation_packets_ready_company_count']);
        $this->assertSame($externalToolActivationPacketStatusPayload['summary']['expected_external_tool_activation_packet_count'], $externalToolActivationPacketStatusPayload['summary']['ready_external_tool_activation_packet_count']);
        $this->assertSame($externalToolActivationPacketStatusPayload['summary']['required_gate_count'], $externalToolActivationPacketStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $externalToolActivationPacketStatusPayload['enterprise_company_external_tool_activation_packet_status_hash']));

        foreach ($externalToolActivationPacketStatusPayload['companies'] as $companyExternalToolActivationPacketStatus) {
            $this->assertTrue((bool) $companyExternalToolActivationPacketStatus['external_tool_activation_packets_ready'], (string) $companyExternalToolActivationPacketStatus['company_id']);
            $this->assertSame('target_9_external_tool_activation_packets_ready_external_effects_blocked', $companyExternalToolActivationPacketStatus['runtime_grade'], (string) $companyExternalToolActivationPacketStatus['company_id']);
            $this->assertSame([], $companyExternalToolActivationPacketStatus['missing_gates'], (string) $companyExternalToolActivationPacketStatus['company_id']);
            $this->assertTrue((bool) $companyExternalToolActivationPacketStatus['gates']['external_tool_activation_packets_cover_work_orders'], (string) $companyExternalToolActivationPacketStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyExternalToolActivationPacketStatus, 'packet_records.0.receipt_chain.activation_packet_receipt_hash')));
            $this->assertSame(64, strlen((string) data_get($companyExternalToolActivationPacketStatus, 'packet_records.0.receipt_chain.sandbox_probe_receipt_hash')));
            $this->assertSame(64, strlen((string) data_get($companyExternalToolActivationPacketStatus, 'packet_records.0.receipt_chain.eval_replay_receipt_hash')));
            $this->assertFalse((bool) $companyExternalToolActivationPacketStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyExternalToolActivationPacketStatus['external_side_effects_enabled']);
        }

        $verticalToolOperatingRuntimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-vertical-tool-operating-runtime-register',
            '--json' => true,
        ]);
        $verticalToolOperatingRuntimeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $verticalToolOperatingRuntimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $verticalToolOperatingRuntimeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_vertical_tool_operating_runtime_register.v1', $verticalToolOperatingRuntimeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_vertical_tool_operating_runtime_registered_external_effects_blocked', $verticalToolOperatingRuntimeRegisterPayload['status']);
        $this->assertSame($verticalToolOperatingRuntimeRegisterPayload['summary']['expected_vertical_tool_runtime_count'], $verticalToolOperatingRuntimeRegisterPayload['summary']['registered_vertical_tool_runtime_count']);
        $this->assertGreaterThanOrEqual(708, $verticalToolOperatingRuntimeRegisterPayload['summary']['registered_vertical_tool_runtime_count']);
        $this->assertSame(0, $verticalToolOperatingRuntimeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $verticalToolOperatingRuntimeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertContains('tool_catalog', $verticalToolOperatingRuntimeRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('domain_data_contract', $verticalToolOperatingRuntimeRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('external_write', $verticalToolOperatingRuntimeRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $verticalToolOperatingRuntimeRegisterPayload['enterprise_company_vertical_tool_operating_runtime_register_hash']));

        $verticalToolOperatingRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-vertical-tool-operating-runtime-status',
            '--json' => true,
        ]);
        $verticalToolOperatingRuntimeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $verticalToolOperatingRuntimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $verticalToolOperatingRuntimeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_vertical_tool_operating_runtime_status.v1', $verticalToolOperatingRuntimeStatusPayload['schema']);
        $this->assertSame('enterprise_company_vertical_tool_operating_runtime_ready_external_effects_blocked', $verticalToolOperatingRuntimeStatusPayload['status']);
        $this->assertSame(9, $verticalToolOperatingRuntimeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $verticalToolOperatingRuntimeStatusPayload['summary']['vertical_tool_operating_runtime_ready_company_count']);
        $this->assertSame($verticalToolOperatingRuntimeStatusPayload['summary']['expected_vertical_tool_runtime_count'], $verticalToolOperatingRuntimeStatusPayload['summary']['ready_vertical_tool_runtime_count']);
        $this->assertSame($verticalToolOperatingRuntimeStatusPayload['summary']['required_gate_count'], $verticalToolOperatingRuntimeStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $verticalToolOperatingRuntimeStatusPayload['enterprise_company_vertical_tool_operating_runtime_status_hash']));

        foreach ($verticalToolOperatingRuntimeStatusPayload['companies'] as $companyVerticalToolOperatingRuntimeStatus) {
            $this->assertTrue((bool) $companyVerticalToolOperatingRuntimeStatus['vertical_tool_operating_runtime_ready'], (string) $companyVerticalToolOperatingRuntimeStatus['company_id']);
            $this->assertSame('target_9_vertical_tool_operating_runtime_ready_external_effects_blocked', $companyVerticalToolOperatingRuntimeStatus['runtime_grade'], (string) $companyVerticalToolOperatingRuntimeStatus['company_id']);
            $this->assertSame([], $companyVerticalToolOperatingRuntimeStatus['missing_gates'], (string) $companyVerticalToolOperatingRuntimeStatus['company_id']);
            $this->assertTrue((bool) $companyVerticalToolOperatingRuntimeStatus['gates']['vertical_tool_runtime_covers_activation_packets'], (string) $companyVerticalToolOperatingRuntimeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyVerticalToolOperatingRuntimeStatus, 'runtime_records.0.receipt_chain.vertical_tool_runtime_receipt_hash')));
            $this->assertFalse((bool) $companyVerticalToolOperatingRuntimeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyVerticalToolOperatingRuntimeStatus['external_side_effects_enabled']);
        }

        $businessExecutionControlPlaneRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-business-execution-control-plane-register',
            '--json' => true,
        ]);
        $businessExecutionControlPlaneRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessExecutionControlPlaneRegisterExit, Artisan::output());
        $this->assertTrue((bool) $businessExecutionControlPlaneRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_business_execution_control_plane_register.v1', $businessExecutionControlPlaneRegisterPayload['schema']);
        $this->assertSame('enterprise_company_business_execution_control_plane_registered_external_effects_blocked', $businessExecutionControlPlaneRegisterPayload['status']);
        $this->assertSame($businessExecutionControlPlaneRegisterPayload['summary']['expected_business_control_plane_count'], $businessExecutionControlPlaneRegisterPayload['summary']['registered_business_control_plane_count']);
        $this->assertGreaterThanOrEqual(708, $businessExecutionControlPlaneRegisterPayload['summary']['registered_business_control_plane_count']);
        $this->assertSame(0, $businessExecutionControlPlaneRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $businessExecutionControlPlaneRegisterPayload['summary']['real_money_movement_allowed_count']);
        $this->assertSame(0, $businessExecutionControlPlaneRegisterPayload['summary']['customer_commitment_allowed_count']);
        $this->assertContains('business_kpi_contract', $businessExecutionControlPlaneRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('pnl_guardrail_contract', $businessExecutionControlPlaneRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('risk_compliance_control', $businessExecutionControlPlaneRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('real_money_movement', $businessExecutionControlPlaneRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $businessExecutionControlPlaneRegisterPayload['enterprise_company_business_execution_control_plane_register_hash']));

        $businessExecutionControlPlaneStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-business-execution-control-plane-status',
            '--json' => true,
        ]);
        $businessExecutionControlPlaneStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $businessExecutionControlPlaneStatusExit, Artisan::output());
        $this->assertTrue((bool) $businessExecutionControlPlaneStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_business_execution_control_plane_status.v1', $businessExecutionControlPlaneStatusPayload['schema']);
        $this->assertSame('enterprise_company_business_execution_control_plane_ready_external_effects_blocked', $businessExecutionControlPlaneStatusPayload['status']);
        $this->assertSame(9, $businessExecutionControlPlaneStatusPayload['summary']['company_count']);
        $this->assertSame(9, $businessExecutionControlPlaneStatusPayload['summary']['business_execution_control_plane_ready_company_count']);
        $this->assertSame($businessExecutionControlPlaneStatusPayload['summary']['expected_business_control_plane_count'], $businessExecutionControlPlaneStatusPayload['summary']['ready_business_control_plane_count']);
        $this->assertSame($businessExecutionControlPlaneStatusPayload['summary']['required_gate_count'], $businessExecutionControlPlaneStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(0, $businessExecutionControlPlaneStatusPayload['summary']['real_money_movement_allowed_count']);
        $this->assertSame(0, $businessExecutionControlPlaneStatusPayload['summary']['customer_commitment_allowed_count']);
        $this->assertSame(64, strlen((string) $businessExecutionControlPlaneStatusPayload['enterprise_company_business_execution_control_plane_status_hash']));

        foreach ($businessExecutionControlPlaneStatusPayload['companies'] as $companyBusinessExecutionControlPlaneStatus) {
            $this->assertTrue((bool) $companyBusinessExecutionControlPlaneStatus['business_execution_control_plane_ready'], (string) $companyBusinessExecutionControlPlaneStatus['company_id']);
            $this->assertSame('target_9_business_execution_control_plane_ready_external_effects_blocked', $companyBusinessExecutionControlPlaneStatus['runtime_grade'], (string) $companyBusinessExecutionControlPlaneStatus['company_id']);
            $this->assertSame([], $companyBusinessExecutionControlPlaneStatus['missing_gates'], (string) $companyBusinessExecutionControlPlaneStatus['company_id']);
            $this->assertTrue((bool) $companyBusinessExecutionControlPlaneStatus['gates']['business_control_plane_covers_vertical_runtime'], (string) $companyBusinessExecutionControlPlaneStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyBusinessExecutionControlPlaneStatus, 'control_plane_records.0.receipt_chain.business_control_plane_receipt_hash')));
            $this->assertFalse((bool) $companyBusinessExecutionControlPlaneStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyBusinessExecutionControlPlaneStatus['external_side_effects_enabled']);
            $this->assertFalse((bool) $companyBusinessExecutionControlPlaneStatus['real_money_movement_allowed']);
            $this->assertFalse((bool) $companyBusinessExecutionControlPlaneStatus['customer_commitment_allowed']);
        }

        $agentWorkforceRuntimeRegisterExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-agent-workforce-runtime-register',
            '--json' => true,
        ]);
        $agentWorkforceRuntimeRegisterPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $agentWorkforceRuntimeRegisterExit, Artisan::output());
        $this->assertTrue((bool) $agentWorkforceRuntimeRegisterPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_agent_workforce_runtime_register.v1', $agentWorkforceRuntimeRegisterPayload['schema']);
        $this->assertSame('enterprise_company_agent_workforce_runtime_registered_external_effects_blocked', $agentWorkforceRuntimeRegisterPayload['status']);
        $this->assertSame($agentWorkforceRuntimeRegisterPayload['summary']['expected_agent_workforce_runtime_count'], $agentWorkforceRuntimeRegisterPayload['summary']['registered_agent_workforce_runtime_count']);
        $this->assertGreaterThanOrEqual(295, $agentWorkforceRuntimeRegisterPayload['summary']['registered_agent_workforce_runtime_count']);
        $this->assertSame(0, $agentWorkforceRuntimeRegisterPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $agentWorkforceRuntimeRegisterPayload['summary']['external_side_effects_enabled_count']);
        $this->assertContains('agent_runtime_receipt', $agentWorkforceRuntimeRegisterPayload['policy']['required_runtime_evidence']);
        $this->assertContains('external_write', $agentWorkforceRuntimeRegisterPayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $agentWorkforceRuntimeRegisterPayload['enterprise_company_agent_workforce_runtime_register_hash']));

        $agentWorkforceRuntimeStatusExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-agent-workforce-runtime-status',
            '--json' => true,
        ]);
        $agentWorkforceRuntimeStatusPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $agentWorkforceRuntimeStatusExit, Artisan::output());
        $this->assertTrue((bool) $agentWorkforceRuntimeStatusPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_agent_workforce_runtime_status.v1', $agentWorkforceRuntimeStatusPayload['schema']);
        $this->assertSame('enterprise_company_agent_workforce_runtime_ready_external_effects_blocked', $agentWorkforceRuntimeStatusPayload['status']);
        $this->assertSame(9, $agentWorkforceRuntimeStatusPayload['summary']['company_count']);
        $this->assertSame(9, $agentWorkforceRuntimeStatusPayload['summary']['agent_workforce_runtime_ready_company_count']);
        $this->assertSame($agentWorkforceRuntimeStatusPayload['summary']['expected_agent_workforce_runtime_count'], $agentWorkforceRuntimeStatusPayload['summary']['ready_agent_workforce_runtime_count']);
        $this->assertSame($agentWorkforceRuntimeStatusPayload['summary']['required_gate_count'], $agentWorkforceRuntimeStatusPayload['summary']['ready_gate_count']);
        $this->assertSame(64, strlen((string) $agentWorkforceRuntimeStatusPayload['enterprise_company_agent_workforce_runtime_status_hash']));

        foreach ($agentWorkforceRuntimeStatusPayload['companies'] as $companyAgentWorkforceRuntimeStatus) {
            $this->assertTrue((bool) $companyAgentWorkforceRuntimeStatus['agent_workforce_runtime_ready'], (string) $companyAgentWorkforceRuntimeStatus['company_id']);
            $this->assertSame('target_9_agent_workforce_runtime_ready_external_effects_blocked', $companyAgentWorkforceRuntimeStatus['runtime_grade'], (string) $companyAgentWorkforceRuntimeStatus['company_id']);
            $this->assertSame([], $companyAgentWorkforceRuntimeStatus['missing_gates'], (string) $companyAgentWorkforceRuntimeStatus['company_id']);
            $this->assertTrue((bool) $companyAgentWorkforceRuntimeStatus['gates']['agent_workforce_runtime_covers_subagents'], (string) $companyAgentWorkforceRuntimeStatus['company_id']);
            $this->assertSame(64, strlen((string) data_get($companyAgentWorkforceRuntimeStatus, 'runtime_records.0.receipt_chain.agent_runtime_receipt_hash')));
            $this->assertFalse((bool) $companyAgentWorkforceRuntimeStatus['external_execution_allowed']);
            $this->assertFalse((bool) $companyAgentWorkforceRuntimeStatus['external_side_effects_enabled']);
        }

        $productionReadinessExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-production-readiness-certification-status',
            '--json' => true,
        ]);
        $productionReadinessPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $productionReadinessExit, Artisan::output());
        $this->assertTrue((bool) $productionReadinessPayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_production_readiness_certification_status.v1', $productionReadinessPayload['schema']);
        $this->assertSame('enterprise_company_production_readiness_certified_external_launch_still_blocked', $productionReadinessPayload['status']);
        $this->assertSame(9, $productionReadinessPayload['summary']['company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['production_ready_company_count']);
        $this->assertSame(0, $productionReadinessPayload['summary']['attention_company_count']);
        $this->assertSame($productionReadinessPayload['summary']['required_gate_count'], $productionReadinessPayload['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $productionReadinessPayload['summary']['average_production_readiness_score']);
        $this->assertSame(9, $productionReadinessPayload['summary']['quality_compliance_lifecycle_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['agent_operations_pack_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['agent_workforce_runtime_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['work_product_runtime_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['operating_blueprint_runtime_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['business_runtime_persistence_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['capability_runtime_mesh_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['supervised_connector_execution_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['external_tool_activation_work_orders_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['external_tool_activation_packets_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['vertical_tool_operating_runtime_ready_company_count']);
        $this->assertSame(9, $productionReadinessPayload['summary']['business_execution_control_plane_ready_company_count']);
        $this->assertSame(0, $productionReadinessPayload['summary']['external_execution_allowed_count']);
        $this->assertSame(0, $productionReadinessPayload['summary']['external_side_effects_enabled_count']);
        $this->assertSame(0, $productionReadinessPayload['summary']['external_launch_allowed_count']);
        $this->assertFalse((bool) $productionReadinessPayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $productionReadinessPayload['policy']['external_side_effects_enabled']);
        $this->assertFalse((bool) $productionReadinessPayload['policy']['external_launch_allowed']);
        $this->assertTrue((bool) $productionReadinessPayload['policy']['production_readiness_certification_is_not_execution_authority']);
        $this->assertSame(64, strlen((string) $productionReadinessPayload['enterprise_company_production_readiness_certification_status_hash']));

        foreach ($productionReadinessPayload['companies'] as $companyProductionReadiness) {
            $this->assertTrue((bool) $companyProductionReadiness['production_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertSame('target_9_enterprise_production_ready_external_launch_blocked', $companyProductionReadiness['production_readiness_grade'], (string) $companyProductionReadiness['company_id']);
            $this->assertSame($companyProductionReadiness['required_gate_count'], $companyProductionReadiness['ready_gate_count'], (string) $companyProductionReadiness['company_id']);
            $this->assertSame([], $companyProductionReadiness['missing_gates'], (string) $companyProductionReadiness['company_id']);
            $this->assertSame(1.0, (float) $companyProductionReadiness['production_readiness_score'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['completion_certified'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['vertical_operational_depth_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['customer_account_revenue_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['productized_service_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['sales_crm_pipeline_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['customer_support_service_desk_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['marketing_growth_engine_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['finance_treasury_billing_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['governance_risk_operations_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['unit_economics_capacity_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['business_operating_packet_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['flow_live_read_connector_probe_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['active_operating_system_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['capability_catalog_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['integration_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['domain_operating_model_certified'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['agent_operations_pack_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['agent_workforce_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['domain_tool_execution_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['flow_tool_execution_ledger_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['flow_tool_execution_runtime_persisted'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['domain_adapter_execution_envelopes_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['operational_execution_loop_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['work_product_acceptance_evidence_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['work_product_runtime_persisted'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['operating_blueprint_runtime_persisted'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['business_runtime_persistence_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['capability_runtime_mesh_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['supervised_connector_execution_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['external_tool_activation_work_orders_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['external_tool_activation_packets_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['vertical_tool_operating_runtime_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['business_execution_control_plane_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['quality_compliance_lifecycle_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['holding_outcome_scorecard_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['portfolio_decision_packet_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['company_board_operating_review_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['company_operating_cycle_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['company_operating_cadence_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['company_operating_scorecard_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['supervised_cutover_chain_complete'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['external_launch_control_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['external_receipt_binders_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertTrue((bool) $companyProductionReadiness['gates']['external_execution_blocked'], (string) $companyProductionReadiness['company_id']);
            $this->assertFalse((bool) $companyProductionReadiness['external_execution_allowed']);
            $this->assertFalse((bool) $companyProductionReadiness['external_side_effects_enabled']);
            $this->assertFalse((bool) $companyProductionReadiness['external_launch_allowed']);
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['holding_outcome_scorecard_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['portfolio_decision_packet_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_board_operating_review_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_operating_cycle_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_operating_cadence_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_operating_scorecard_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['flow_live_read_connector_probe_runtime_status_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_operational_execution_loop_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_work_product_acceptance_evidence_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_work_product_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_operating_blueprint_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_business_runtime_persistence_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_capability_runtime_mesh_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_supervised_connector_execution_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_external_tool_activation_work_order_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_external_tool_activation_packet_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_vertical_tool_operating_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_business_execution_control_plane_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_domain_tool_execution_readiness_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_flow_tool_execution_ledger_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_flow_tool_execution_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_domain_adapter_execution_envelope_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_agent_operations_pack_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_agent_workforce_runtime_status_record_hash']));
            $this->assertTrue((bool) $companyProductionReadiness['live_read_connector_probe_readiness']['runtime_coverage_ready']
                || (bool) $companyProductionReadiness['live_read_connector_probe_readiness']['structural_buildout_ready'], (string) $companyProductionReadiness['company_id']);
            $this->assertGreaterThanOrEqual(
                $companyProductionReadiness['expected_flow_count'],
                $companyProductionReadiness['live_read_connector_probe_readiness']['structural_contract_count'],
                (string) $companyProductionReadiness['company_id']
            );
            $this->assertGreaterThanOrEqual(
                $companyProductionReadiness['expected_flow_count'],
                $companyProductionReadiness['live_read_connector_probe_readiness']['structural_evidence_matrix_count'],
                (string) $companyProductionReadiness['company_id']
            );
            $this->assertFalse((bool) $companyProductionReadiness['live_read_connector_probe_readiness']['external_mutation_allowed']);
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_domain_operating_model_certification_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['source_hashes']['company_quality_compliance_lifecycle_record_hash']));
            $this->assertSame(64, strlen((string) $companyProductionReadiness['company_production_readiness_certification_record_hash']));
        }

        $operatingEvidenceExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-company-operating-evidence-bundle-status',
            '--json' => true,
        ]);
        $operatingEvidencePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $operatingEvidenceExit, Artisan::output());
        $this->assertTrue((bool) $operatingEvidencePayload['ok']);
        $this->assertSame('atlas.ai.holding.enterprise_company_operating_evidence_bundle_status.v1', $operatingEvidencePayload['schema']);
        $this->assertSame('enterprise_company_operating_evidence_bundles_ready_external_launch_blocked', $operatingEvidencePayload['status']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['operating_evidence_bundle_ready_company_count']);
        $this->assertSame($operatingEvidencePayload['summary']['required_gate_count'], $operatingEvidencePayload['summary']['ready_gate_count']);
        $this->assertSame(1.0, (float) $operatingEvidencePayload['summary']['average_bundle_score']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['agent_operations_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['agent_workforce_runtime_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['capability_runtime_mesh_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['supervised_connector_execution_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['external_tool_activation_work_orders_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['external_tool_activation_packets_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['vertical_tool_operating_runtime_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['business_execution_control_plane_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['industry_solution_ecosystem_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['operational_outcome_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['holding_outcome_scorecard_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['company_board_operating_review_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['company_operating_cycle_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['company_operating_cadence_bundle_ready_company_count']);
        $this->assertSame(9, $operatingEvidencePayload['summary']['company_operating_scorecard_bundle_ready_company_count']);
        $this->assertGreaterThanOrEqual($operatingEvidencePayload['summary']['expected_flow_count'] * 2, $operatingEvidencePayload['summary']['flow_evidence_record_count']);
        $this->assertFalse((bool) $operatingEvidencePayload['policy']['calendar_wait_blocker_enabled']);
        $this->assertFalse((bool) $operatingEvidencePayload['policy']['external_execution_allowed']);
        $this->assertFalse((bool) $operatingEvidencePayload['policy']['external_side_effects_enabled']);
        $this->assertFalse((bool) $operatingEvidencePayload['policy']['external_launch_allowed']);
        $this->assertContains('flow_capability_matrix', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('agent_operations', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('agent_workforce_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('persisted_tool_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('domain_adapter_execution_envelope', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('work_product_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('operating_blueprint_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('business_runtime_persistence', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('capability_runtime_mesh', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('supervised_connector_execution', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('external_tool_activation_work_orders', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('external_tool_activation_packets', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('vertical_tool_operating_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('business_execution_control_plane', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('quality_compliance', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('industry_solution_ecosystem', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('operational_outcome_runtime', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('holding_outcome_scorecard', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('company_board_operating_review', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('company_operating_cycle', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('company_operating_cadence', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('company_operating_scorecard', $operatingEvidencePayload['policy']['required_bundle_sections']);
        $this->assertContains('skip_operator_acceptance', $operatingEvidencePayload['policy']['blocked_operations']);
        $this->assertSame(64, strlen((string) $operatingEvidencePayload['enterprise_company_operating_evidence_bundle_status_hash']));

        foreach ($operatingEvidencePayload['companies'] as $companyOperatingEvidence) {
            $this->assertTrue((bool) $companyOperatingEvidence['operating_evidence_bundle_ready'], (string) $companyOperatingEvidence['company_id']);
            $this->assertSame('target_9_company_operating_evidence_bundle_ready_external_launch_blocked', $companyOperatingEvidence['bundle_grade'], (string) $companyOperatingEvidence['company_id']);
            $this->assertSame($companyOperatingEvidence['required_gate_count'], $companyOperatingEvidence['ready_gate_count'], (string) $companyOperatingEvidence['company_id']);
            $this->assertSame([], $companyOperatingEvidence['missing_gates'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['production_readiness_certified'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['capability_catalog_flow_records_complete'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['integration_flow_records_complete'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['agent_operations_bundle_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['agent_workforce_runtime_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['flow_tool_execution_ledger_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['flow_tool_execution_runtime_persisted_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['domain_adapter_execution_envelope_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['work_product_acceptance_bundle_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['work_product_runtime_persisted_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['operating_blueprint_runtime_persisted_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['business_runtime_persistence_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['capability_runtime_mesh_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['supervised_connector_execution_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['external_tool_activation_work_orders_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['external_tool_activation_packets_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['vertical_tool_operating_runtime_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['business_execution_control_plane_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['quality_compliance_bundle_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['industry_solution_ecosystem_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['operational_outcome_runtime_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['holding_outcome_scorecard_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['company_board_operating_review_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['company_operating_cycle_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['company_operating_cadence_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['company_operating_scorecard_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['supervised_cutover_evidence_bound'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['source_lineage_hashes_complete'], (string) $companyOperatingEvidence['company_id']);
            $this->assertTrue((bool) $companyOperatingEvidence['gates']['external_effects_blocked'], (string) $companyOperatingEvidence['company_id']);
            $this->assertGreaterThanOrEqual($companyOperatingEvidence['expected_flow_count'], $companyOperatingEvidence['flow_evidence']['ready_capability_flow_record_count'], (string) $companyOperatingEvidence['company_id']);
            $this->assertGreaterThanOrEqual($companyOperatingEvidence['expected_flow_count'], $companyOperatingEvidence['flow_evidence']['ready_integration_flow_record_count'], (string) $companyOperatingEvidence['company_id']);
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_production_readiness_certification_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_work_product_acceptance_evidence_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_work_product_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_operating_blueprint_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_business_runtime_persistence_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_capability_runtime_mesh_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_supervised_connector_execution_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_external_tool_activation_work_order_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_external_tool_activation_packet_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_vertical_tool_operating_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_business_execution_control_plane_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_quality_compliance_lifecycle_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['industry_solution_ecosystem_company_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_flow_tool_execution_ledger_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_flow_tool_execution_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_domain_adapter_execution_envelope_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_agent_operations_pack_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_agent_workforce_runtime_status_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['operational_outcome_runtime_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['holding_outcome_scorecard_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_board_operating_review_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_operating_cycle_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_operating_cadence_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['source_hashes']['company_operating_scorecard_record_hash']));
            $this->assertSame(64, strlen((string) $companyOperatingEvidence['company_operating_evidence_bundle_record_hash']));
            $this->assertFalse((bool) $companyOperatingEvidence['external_execution_allowed']);
            $this->assertFalse((bool) $companyOperatingEvidence['external_side_effects_enabled']);
            $this->assertFalse((bool) $companyOperatingEvidence['external_launch_allowed']);
        }
    }

    private function migrateExternalActionMandates(): void
    {
        $this->createAtlasToolRuntimeTables();
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_18_010000_create_ai_domain_runtime_tables.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_19_120000_create_ai_operator_approvals_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_020000_create_ai_holding_external_action_mandates_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_021000_create_ai_holding_activation_backlog_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_022000_add_implementation_cycle_to_ai_holding_activation_backlog_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_023000_create_ai_holding_connector_activation_records_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_024000_create_ai_holding_enterprise_flow_run_queue_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_026000_add_operating_package_to_ai_holding_enterprise_flow_run_queue_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_025000_create_ai_holding_enterprise_flow_operations_runbooks_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_027000_add_operating_package_to_ai_holding_enterprise_flow_operations_runbooks_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_028000_create_ai_holding_external_cutover_work_orders_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_029000_create_ai_holding_external_cutover_work_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_030000_add_receipt_binding_to_ai_holding_external_cutover_work_items_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_031000_add_final_authority_bindings_to_ai_holding_external_cutover_work_orders_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_032000_create_ai_holding_external_cutover_runtime_invocations_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_033000_add_rehearsal_execution_to_ai_holding_external_cutover_runtime_invocations_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_034000_add_manual_handoff_packet_to_ai_holding_external_cutover_runtime_invocations_table.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_20_035000_add_manual_closeout_to_ai_holding_external_cutover_runtime_invocations_table.php',
            '--force' => true,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function registerOneFinanceMandate(): array
    {
        $suiteExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-mandates',
            '--company' => 'finance',
            '--json' => true,
        ]);
        $suite = json_decode(Artisan::output(), true);
        $flowId = (string) $suite['companies'][0]['mandate_packets'][0]['flow_id'];

        $this->assertSame(0, $suiteExit);

        $registerExit = Artisan::call('atlas:ai:autonomous-holding', [
            '--action' => 'enterprise-external-action-register',
            '--company' => 'finance',
            '--flow' => $flowId,
            '--json' => true,
        ]);
        $registered = json_decode(Artisan::output(), true);

        $this->assertSame(0, $registerExit);
        $this->assertTrue((bool) $registered['ok']);

        return $registered;
    }

    private function approvalByRole(string $role): ?AiOperatorApproval
    {
        return AiOperatorApproval::query()
            ->get()
            ->first(static fn (AiOperatorApproval $approval): bool => data_get($approval->options, 'approval_role') === $role);
    }
}
