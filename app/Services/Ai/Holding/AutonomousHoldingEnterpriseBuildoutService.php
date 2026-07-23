<?php

namespace App\Services\Ai\Holding;

use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService;
use App\Services\Ai\Holding\EnterpriseBuildout\EnterpriseBuildoutSupport;
use App\Services\Ai\Holding\EnterpriseBuildout\PortfolioReportSection;
use App\Services\Ai\Holding\EnterpriseBuildout\FlowRuntimeSection;
use App\Services\Ai\Holding\EnterpriseBuildout\AgentWorkforceSection;
use App\Services\Ai\Holding\EnterpriseBuildout\CommercialCustomerSection;
use App\Services\Ai\Holding\EnterpriseBuildout\GovernanceOperationsSection;
use App\Services\Ai\Holding\EnterpriseBuildout\ConnectorPlatformSection;
use App\Services\Ai\Holding\EnterpriseBuildout\DomainSolutionSection;
use App\Services\Ai\Holding\EnterpriseBuildout\CompanyOperatingSection;
use App\Services\Ai\Mission\MissionCanonicalHash;

class AutonomousHoldingEnterpriseBuildoutService
{
    public const SCHEMA = 'atlas.ai.autonomous_holding.enterprise_buildout.v1';

    public const COMPANY_SCHEMA = 'atlas.ai.company.enterprise_buildout.v1';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $reportCache = null;

    private readonly EnterpriseBuildoutSupport $support;

    private readonly PortfolioReportSection $portfolioReport;

    private readonly FlowRuntimeSection $flowRuntime;

    private readonly AgentWorkforceSection $agentWorkforce;

    private readonly CommercialCustomerSection $commercialCustomer;

    private readonly GovernanceOperationsSection $governanceOperations;

    private readonly ConnectorPlatformSection $connectorPlatform;

    private readonly DomainSolutionSection $domainSolution;

    private readonly CompanyOperatingSection $companyOperating;

    public function __construct(
        private readonly FinanceEnterpriseAnalysisService $finance,
    ) {
        $this->support = new EnterpriseBuildoutSupport($this->finance);
        $this->portfolioReport = new PortfolioReportSection();
        $this->flowRuntime = new FlowRuntimeSection($this->support);
        $this->agentWorkforce = new AgentWorkforceSection($this->support);
        $this->commercialCustomer = new CommercialCustomerSection($this->support);
        $this->governanceOperations = new GovernanceOperationsSection($this->support);
        $this->connectorPlatform = new ConnectorPlatformSection($this->support);
        $this->domainSolution = new DomainSolutionSection($this->support);
        $this->companyOperating = new CompanyOperatingSection($this->support);
    }

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        if ($this->reportCache !== null) {
            return $this->reportCache;
        }

        $companies = array_map(
            fn (array $manifest): array => $this->company($manifest),
            DomainSeedManifests::all(),
        );

        $report = [
            'ok' => collect($companies)->every(fn (array $company): bool => (bool) ($company['readiness']['ok'] ?? false)),
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'company_count' => count($companies),
            'enterprise_company_count' => count(array_filter(
                $companies,
                static fn (array $company): bool => (bool) ($company['readiness']['ok'] ?? false),
            )),
            'companies' => $companies,
            'cross_company_fabric' => $this->portfolioReport->crossCompanyFabric(DomainSeedManifests::all()),
            'portfolio_governance_stack' => $this->portfolioReport->portfolioGovernanceStack(DomainSeedManifests::all()),
            'structural_completion_policy' => [
                'schema' => 'atlas.ai.holding.structural_completion_policy.v1',
                'enterprise_buildout_requires_observed_history_window' => false,
                'target_9_external_autonomy_claim_requires_current_operational_evidence' => true,
                'calendar_window_treatment' => 'calendar_history_is_not_a_buildout_or_target_9_blocker',
                'safe_completion_boundary' => 'complete_internal_enterprise_structure_without_enabling_ungoverned_external_side_effects',
                'policy_hash' => hash('sha256', 'atlas|holding|structural_completion_policy|accelerated_operational_evidence_gate'),
            ],
            'portfolio_operating_model' => [
                'mode' => 'enterprise_buildout_execution_contract',
                'capital_allocation_allowed' => false,
                'external_side_effects_allowed' => false,
                'operator_approval_required_for_external_action' => true,
                'shared_gates' => [
                    'source_links_required',
                    'audit_trail_required',
                    'evidence_attached',
                    'policy_checked',
                    'rollback_or_review_packet_present',
                ],
                'portfolio_governance' => [
                    'cadence' => 'weekly_portfolio_operating_review',
                    'required_company_inputs' => ['okr_scorecard', 'risk_register', 'sla_report', 'backlog_health', 'decision_log'],
                    'capital_committee_mode' => 'advisory_until_operator_approval',
                    'portfolio_hash' => hash('sha256', 'atlas|portfolio_operating_model|enterprise_buildout_execution_contract'),
                ],
            ],
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($this->portfolioReport->reportHashInput($report));

        return $this->reportCache = $report;
    }

    /**
     * @return array<string,mixed>
     */
    public function companyPacket(string $domainId): array
    {
        $domainId = trim($domainId);

        if ($this->reportCache !== null) {
            foreach ((array) ($this->reportCache['companies'] ?? []) as $company) {
                if (($company['company_id'] ?? null) === $domainId) {
                    return (array) $company;
                }
            }
        }

        foreach (DomainSeedManifests::all() as $manifest) {
            if (($manifest['domain_id'] ?? null) === $domainId) {
                return $this->company($manifest);
            }
        }

        throw new \InvalidArgumentException("Unknown enterprise company domain [{$domainId}].");
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function company(array $manifest): array
    {
        $domainId = (string) ($manifest['domain_id'] ?? 'unknown');
        $blueprint = $this->support->blueprint($domainId);
        $functions = array_values((array) ($manifest['enterprise_functions'] ?? $manifest['departments'] ?? []));
        $agents = array_values((array) ($manifest['agent_roles'] ?? []));
        $flows = array_values((array) ($manifest['flow_profiles'] ?? []));
        $products = array_values((array) ($manifest['delivery_types'] ?? []));
        $metrics = array_values((array) ($manifest['metrics'] ?? []));
        $agentRoles = array_values(array_unique(array_merge($agents, $blueprint['agent_roles'])));
        $workProducts = array_values(array_unique(array_merge($products, $blueprint['work_products'])));
        $companyMetrics = array_values(array_unique(array_merge($metrics, $blueprint['metrics'])));
        $cadences = array_values(array_unique(array_merge(
            (array) ($manifest['recurring_cadences'] ?? []),
            $blueprint['cadences'],
        )));

        $company = [
            'schema' => self::COMPANY_SCHEMA,
            'company_id' => $domainId,
            'name' => (string) ($manifest['name'] ?? $domainId),
            'enterprise_tier' => 'ultra_premium_supervised_enterprise',
            'charter' => $manifest['charter'] ?? [],
            'functions' => $functions,
            'agent_roles' => $agentRoles,
            'flows' => $this->flowRuntime->flows($domainId, $blueprint),
            'flow_execution_contracts' => $this->flowRuntime->flowExecutionContracts($domainId, $blueprint),
            'flow_playbooks' => $this->flowRuntime->flowPlaybooks($domainId, $blueprint),
            'flow_runtime_blueprints' => $this->flowRuntime->flowRuntimeBlueprints($domainId, $blueprint),
            'enterprise_flow_orchestration_runbook_stack' => $this->flowRuntime->enterpriseFlowOrchestrationRunbookStack($domainId, $blueprint),
            'enterprise_flow_runtime_implementation_stack' => $this->flowRuntime->enterpriseFlowRuntimeImplementationStack($domainId, $blueprint),
            'enterprise_flow_fixture_simulation_stack' => $this->flowRuntime->enterpriseFlowFixtureSimulationStack($domainId, $blueprint),
            'enterprise_flow_action_runtime_stack' => $this->flowRuntime->enterpriseFlowActionRuntimeStack($domainId, $blueprint),
            'agent_collaboration_model' => $this->agentWorkforce->agentCollaborationModel($domainId, $blueprint),
            'enterprise_agent_registry' => $this->agentWorkforce->enterpriseAgentRegistry($domainId, $blueprint, $agentRoles),
            'enterprise_domain_agent_toolkit_stack' => $this->agentWorkforce->enterpriseDomainAgentToolkitStack($domainId, $blueprint, $agentRoles),
            'enterprise_domain_workload_agent_template_stack' => $this->agentWorkforce->enterpriseDomainWorkloadAgentTemplateStack($domainId, $blueprint, $agentRoles),
            'enterprise_company_operating_blueprint_stack' => $this->companyOperating->enterpriseCompanyOperatingBlueprintStack($domainId, $blueprint),
            'enterprise_external_research_adoption_stack' => $this->agentWorkforce->enterpriseExternalResearchAdoptionStack($domainId, $blueprint),
            'enterprise_agent_repository_adoption_pipeline' => $this->agentWorkforce->enterpriseAgentRepositoryAdoptionPipeline($domainId, $blueprint),
            'enterprise_agent_repository_operating_catalog' => $this->agentWorkforce->enterpriseAgentRepositoryOperatingCatalog($domainId, $blueprint),
            'enterprise_workforce_capacity_stack' => $this->agentWorkforce->enterpriseWorkforceCapacityStack($domainId, $blueprint),
            'enterprise_portfolio_dependency_stack' => $this->governanceOperations->enterprisePortfolioDependencyStack($domainId, $manifest, $blueprint),
            'domain_data_model' => $this->companyOperating->domainDataModel($domainId, $blueprint),
            'business_process_map' => $this->companyOperating->businessProcessMap($domainId, $blueprint),
            'deliverable_quality_contracts' => $this->companyOperating->deliverableQualityContracts($domainId, $blueprint),
            'go_to_production_pack' => $this->companyOperating->goToProductionPack($domainId, $blueprint),
            'commercial_operating_stack' => $this->commercialCustomer->commercialOperatingStack($domainId, $blueprint),
            'enterprise_customer_market_operations_stack' => $this->commercialCustomer->enterpriseCustomerMarketOperationsStack($domainId, $blueprint),
            'enterprise_account_contract_delivery_stack' => $this->commercialCustomer->enterpriseAccountContractDeliveryStack($domainId, $blueprint, $companyMetrics),
            'enterprise_productized_service_stack' => $this->commercialCustomer->enterpriseProductizedServiceStack($domainId, $blueprint, $companyMetrics),
            'enterprise_sales_crm_pipeline_stack' => $this->commercialCustomer->enterpriseSalesCrmPipelineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_customer_support_service_desk_stack' => $this->commercialCustomer->enterpriseCustomerSupportServiceDeskStack($domainId, $blueprint, $companyMetrics),
            'enterprise_marketing_growth_engine_stack' => $this->commercialCustomer->enterpriseMarketingGrowthEngineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_finance_treasury_billing_stack' => $this->commercialCustomer->enterpriseFinanceTreasuryBillingStack($domainId, $blueprint, $companyMetrics),
            'enterprise_vendor_legal_procurement_stack' => $this->governanceOperations->enterpriseVendorLegalProcurementStack($domainId, $blueprint),
            'enterprise_resilience_continuity_stack' => $this->governanceOperations->enterpriseResilienceContinuityStack($domainId, $blueprint),
            'enterprise_analytics_decision_intelligence_stack' => $this->governanceOperations->enterpriseAnalyticsDecisionIntelligenceStack($domainId, $blueprint, $metrics, $products),
            'enterprise_knowledge_memory_learning_stack' => $this->governanceOperations->enterpriseKnowledgeMemoryLearningStack($domainId, $blueprint, $metrics, $products),
            'enterprise_identity_access_data_sovereignty_stack' => $this->governanceOperations->enterpriseIdentityAccessDataSovereigntyStack($domainId, $blueprint),
            'enterprise_control_tower_run_operations_stack' => $this->governanceOperations->enterpriseControlTowerRunOperationsStack($domainId, $blueprint, (array) ($manifest['recurring_cadences'] ?? [])),
            'enterprise_company_command_center_stack' => $this->governanceOperations->enterpriseCompanyCommandCenterStack($domainId, $blueprint, $companyMetrics),
            'enterprise_semantic_operating_graph_stack' => $this->governanceOperations->enterpriseSemanticOperatingGraphStack($domainId, $blueprint, $functions, $agentRoles, $workProducts, $companyMetrics, $cadences),
            'enterprise_delivery_assurance_stack' => $this->governanceOperations->enterpriseDeliveryAssuranceStack($domainId, $blueprint),
            'portfolio_finance_stack' => $this->governanceOperations->portfolioFinanceStack($domainId, $blueprint),
            'enterprise_unit_economics_capacity_simulation_stack' => $this->governanceOperations->enterpriseUnitEconomicsCapacitySimulationStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'strategic_intelligence_stack' => $this->governanceOperations->strategicIntelligenceStack($domainId, $blueprint),
            'enterprise_grc_stack' => $this->governanceOperations->enterpriseGrcStack($domainId, $blueprint),
            'enterprise_capability_matrix' => $this->connectorPlatform->enterpriseCapabilityMatrix($domainId, $blueprint),
            'external_integration_catalog' => $this->connectorPlatform->externalIntegrationCatalog($domainId, $blueprint),
            'enterprise_connector_certification_stack' => $this->connectorPlatform->enterpriseConnectorCertificationStack($domainId, $blueprint),
            'enterprise_production_connector_preflight_stack' => $this->connectorPlatform->enterpriseProductionConnectorPreflightStack($domainId, $blueprint),
            'api_surface' => $this->connectorPlatform->apiSurface($domainId, $blueprint),
            'evaluation_harness' => $this->connectorPlatform->evaluationHarness($domainId, $blueprint),
            'enterprise_flow_benchmark_replay_stack' => $this->flowRuntime->enterpriseFlowBenchmarkReplayStack($domainId, $blueprint),
            'enterprise_tooling_research_stack' => $this->connectorPlatform->enterpriseToolingResearchStack($domainId, $blueprint),
            'enterprise_domain_operating_depth_stack' => $this->domainSolution->enterpriseDomainOperatingDepthStack($domainId, $blueprint),
            'enterprise_domain_agent_workforce_stack' => $this->agentWorkforce->enterpriseDomainAgentWorkforceStack($domainId, $blueprint),
            'enterprise_domain_solution_stack' => $this->domainSolution->enterpriseDomainSolutionStack($domainId, $blueprint),
            'enterprise_vertical_solution_suite_stack' => $this->domainSolution->enterpriseVerticalSolutionSuiteStack($domainId, $blueprint),
            'enterprise_domain_business_execution_mesh_stack' => $this->domainSolution->enterpriseDomainBusinessExecutionMeshStack($domainId, $blueprint, $companyMetrics),
            'enterprise_domain_provider_workbench_stack' => $this->domainSolution->enterpriseDomainProviderWorkbenchStack($domainId, $blueprint),
            'enterprise_industry_solution_ecosystem_stack' => $this->domainSolution->enterpriseIndustrySolutionEcosystemStack($domainId, $blueprint),
            'enterprise_domain_company_execution_suite_stack' => $this->domainSolution->enterpriseDomainCompanyExecutionSuiteStack($domainId, $blueprint, $companyMetrics),
            'enterprise_flow_work_product_delivery_stack' => $this->flowRuntime->enterpriseFlowWorkProductDeliveryStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_domain_data_connector_operating_stack' => $this->connectorPlatform->enterpriseDomainDataConnectorOperatingStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_flow_live_read_connector_probe_stack' => $this->flowRuntime->enterpriseFlowLiveReadConnectorProbeStack($domainId, $blueprint, $companyMetrics),
            'enterprise_domain_data_fabric_stack' => $this->domainSolution->enterpriseDomainDataFabricStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_company_revenue_delivery_operating_mesh' => $this->domainSolution->enterpriseCompanyRevenueDeliveryOperatingMesh($domainId, $blueprint, $workProducts, $companyMetrics),
            'premium_enterprise_agent_reference_model' => $this->agentWorkforce->premiumEnterpriseAgentReferenceModel($domainId, $blueprint),
            'enterprise_flow_operating_packages' => $this->flowRuntime->enterpriseFlowOperatingPackages($domainId, $blueprint),
            'enterprise_integration_activation_plan' => $this->connectorPlatform->enterpriseIntegrationActivationPlan($domainId, $blueprint),
            'enterprise_operational_dress_rehearsal_stack' => $this->connectorPlatform->enterpriseOperationalDressRehearsalStack($domainId, $blueprint),
            'autonomy_promotion_ladder' => $this->companyOperating->autonomyPromotionLadder($domainId),
            'connectors' => $this->connectorPlatform->connectors($domainId, $blueprint),
            'toolchain' => $this->connectorPlatform->toolchain($domainId, $blueprint),
            'enterprise_operating_system' => $this->companyOperating->enterpriseOperatingSystem($domainId, $blueprint),
            'work_products' => $workProducts,
            'metrics' => $companyMetrics,
            'cadences' => $cadences,
            'enterprise_reference_architecture' => $this->companyOperating->enterpriseReferenceArchitecture($domainId, $blueprint),
            'control_plane' => [
                'command_surfaces' => array_values((array) ($manifest['runtime_commands'] ?? [])),
                'dashboards' => $blueprint['dashboards'],
                'incident_or_review_queue' => $blueprint['review_queue'],
                'evidence_sources' => array_values((array) ($manifest['operational_history'] ?? [])),
            ],
            'policy' => [
                'autonomy' => (string) data_get($manifest, 'policy_profile.autonomy', 'review_only'),
                'risk' => (string) data_get($manifest, 'policy_profile.risk', 'medium'),
                'forbidden_actions' => array_values((array) ($manifest['forbidden_actions'] ?? [])),
                'external_side_effects_allowed' => false,
                'operator_approval_required' => true,
            ],
        ];
        $company['readiness'] = $this->portfolioReport->readiness($company);
        $company['receipt_hash'] = MissionCanonicalHash::sha256($this->portfolioReport->companyHashInput($company));

        return $company;
    }

}
