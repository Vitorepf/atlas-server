<?php

namespace Tests\Feature\Ai\Holding;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiOperatorApproval;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class AutonomousHoldingEnterpriseCommandTest extends TestCase
{
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
        $this->assertFalse((bool) $payload['policy']['external_side_effects_allowed']);
        $this->assertTrue((bool) $payload['policy']['operator_approval_required']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));
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
                $this->assertTrue((bool) $payload['quality_gate_result']['operational_dossier_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['agent_toolchain_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['company_operating_spine_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['commercial_operations_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['domain_provider_workbench_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['semantic_operating_graph_runtime_bound']);
                $this->assertTrue((bool) $payload['quality_gate_result']['customer_account_revenue_runtime_bound']);
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
        $this->assertContains('enterprise_domain_business_execution_mesh_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_company_operating_spine_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_commercial_operations_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_domain_provider_workbench_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_semantic_operating_graph_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_agent_toolchain_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_customer_account_revenue_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_unit_economics_capacity_runtime', (array) $record->selected_capabilities);
        $this->assertContains('enterprise_delivery_risk_runtime', (array) $record->selected_capabilities);
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
        $this->assertSame(0, $outcomeRuntimeStatus['summary']['external_side_effect_count']);
        $this->assertSame(1.0, (float) $outcomeRuntimeStatus['summary']['coverage_rate']);
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
        $this->assertSame(1.0, (float) $payload['summary']['domain_business_execution_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['company_operating_spine_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['commercial_operations_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['domain_provider_workbench_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['semantic_operating_graph_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['agent_toolchain_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['cross_company_handoff_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['customer_account_revenue_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['unit_economics_capacity_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['delivery_risk_runtime_coverage_rate']);
        $this->assertSame(1.0, (float) $payload['summary']['operational_outcome_runtime_coverage_rate']);
        $this->assertSame(10.0, (float) $payload['summary']['holding_outcome_scorecard_average_score']);
        $this->assertSame(9, $payload['summary']['portfolio_decision_packet_ready_count']);
        $this->assertSame(9, $payload['summary']['operating_packet_count']);
        $this->assertGreaterThanOrEqual(9.0, (float) $payload['summary']['readiness_score']);
        $this->assertSame(9, $payload['summary']['target_ready_companies']);
        $this->assertSame('agent_repository_adoption_ready_external_execution_blocked', $payload['steps']['agent_repository_adoption_status']['status']);
        $this->assertSame(9, $payload['steps']['agent_repository_adoption_status']['summary']['ready_company_count']);
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
        $this->assertSame('company_command_center_ready_external_execution_blocked', $payload['steps']['company_command_center_status']['status']);
        $this->assertSame(9, $payload['steps']['company_command_center_status']['summary']['ready_company_count']);
        $this->assertSame('complete_vertical_solution_runtime_coverage_external_blocked', $payload['steps']['vertical_solution_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['vertical_solution_runtime_status']['summary']['completed_vertical_runtime_flow_count']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['vertical_solution_runtime_status']['summary']['domain_execution_brief_bound_count']);
        $this->assertSame('complete_domain_solution_playbook_runtime_coverage_external_mutation_blocked', $payload['steps']['domain_solution_playbook_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_solution_playbook_runtime_status']['summary']['completed_domain_solution_playbook_flow_count']);
        $this->assertSame('complete_domain_business_execution_runtime_coverage_external_blocked', $payload['steps']['domain_business_execution_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_business_execution_runtime_status']['summary']['completed_business_execution_runtime_flow_count']);
        $this->assertSame('complete_company_operating_spine_runtime_coverage_external_blocked', $payload['steps']['company_operating_spine_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['company_operating_spine_runtime_status']['summary']['completed_operating_spine_flow_count']);
        $this->assertSame('complete_commercial_operations_runtime_coverage_external_customer_vendor_billing_blocked', $payload['steps']['commercial_operations_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['commercial_operations_runtime_status']['summary']['completed_commercial_operations_flow_count']);
        $this->assertSame('complete_domain_provider_workbench_runtime_coverage_external_write_paid_blocked', $payload['steps']['domain_provider_workbench_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['domain_provider_workbench_runtime_status']['summary']['completed_provider_workbench_flow_count']);
        $this->assertSame('complete_semantic_operating_graph_runtime_coverage_external_mutation_blocked', $payload['steps']['semantic_operating_graph_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['semantic_operating_graph_runtime_status']['summary']['completed_semantic_graph_flow_count']);
        $this->assertSame('complete_agent_toolchain_runtime_coverage_external_blocked', $payload['steps']['agent_toolchain_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['agent_toolchain_runtime_status']['summary']['completed_agent_toolchain_flow_count']);
        $this->assertSame('cross_company_handoff_runtime_ready_external_blocked', $payload['steps']['cross_company_handoff_runtime_status']['status']);
        $this->assertSame(
            $payload['steps']['cross_company_handoff_runtime_status']['summary']['handoff_contract_count'],
            $payload['steps']['cross_company_handoff_runtime_status']['summary']['ready_handoff_runtime_packet_count'],
        );
        $this->assertSame('complete_customer_account_revenue_runtime_coverage_external_revenue_blocked', $payload['steps']['customer_account_revenue_runtime_status']['status']);
        $this->assertSame($payload['steps']['flow_action_runtime_run']['summary']['flow_count'], $payload['steps']['customer_account_revenue_runtime_status']['summary']['completed_customer_account_revenue_flow_count']);
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
            $this->assertGreaterThanOrEqual(6, $company['premium_readiness']['managed_agent_template_count']);
            $this->assertSame($company['flow_count'], $company['premium_readiness']['flow_template_map_count']);
            $this->assertSame($company['connector_count'], $company['premium_readiness']['workbench_count']);
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
        $this->assertSame(0, $register['summary']['external_execution_allowed_count']);
        $this->assertFalse((bool) $register['queue_policy']['external_execution_allowed']);
        $this->assertNotEmpty($register['records'][0]['operating_package_hash']);
        $this->assertTrue((bool) $register['records'][0]['replay_contract_bound']);
        $this->assertGreaterThanOrEqual(25, $register['records'][0]['replay_contract']['minimum_cases_before_shadow']);
        $this->assertSame('atlas.ai.holding.enterprise_flow_queue_operating_package_attestation.v1', $register['records'][0]['last_operating_package_attestation']['schema']);
        $this->assertFalse((bool) $register['records'][0]['last_operating_package_attestation']['calendar_wait_blocker_enabled']);

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
        $this->assertSame(0, $execute['summary']['external_side_effects_enabled_count']);
        $this->assertFalse((bool) $execute['execution_policy']['external_execution_allowed']);

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
                && (bool) ($last['replay_contract_bound'] ?? false);
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
        $this->assertFalse((bool) $status['policy']['external_execution_allowed']);

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
        $this->assertContains('anthropic_financial_services', $payload['companies'][0]['provider_ids']);
        $this->assertContains('accenture_scale_adoption', $payload['companies'][0]['partner_track_ids']);
        $this->assertContains('market_research_brief', $payload['companies'][0]['workload_flow_ids']);
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

    private function migrateExternalActionMandates(): void
    {
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
