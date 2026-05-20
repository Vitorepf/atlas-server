<?php

namespace App\Services\Ai\Holding;

use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService;
use App\Services\Ai\Mission\MissionCanonicalHash;

class AutonomousHoldingEnterpriseBuildoutService
{
    public const SCHEMA = 'atlas.ai.autonomous_holding.enterprise_buildout.v1';

    public const COMPANY_SCHEMA = 'atlas.ai.company.enterprise_buildout.v1';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $reportCache = null;

    public function __construct(
        private readonly FinanceEnterpriseAnalysisService $finance,
    ) {}

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
            'cross_company_fabric' => $this->crossCompanyFabric(DomainSeedManifests::all()),
            'portfolio_governance_stack' => $this->portfolioGovernanceStack(DomainSeedManifests::all()),
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
        $report['receipt_hash'] = MissionCanonicalHash::sha256($this->reportHashInput($report));

        return $this->reportCache = $report;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function reportHashInput(array $report): array
    {
        $companies = array_values(array_map(
            static fn (array $company): array => [
                'company_id' => (string) ($company['company_id'] ?? ''),
                'schema' => (string) ($company['schema'] ?? ''),
                'ok' => (bool) ($company['ok'] ?? false),
                'readiness_ok' => (bool) data_get($company, 'readiness.ok', false),
                'flow_count' => (int) data_get($company, 'readiness.flow_count', 0),
                'connector_count' => (int) data_get($company, 'readiness.connector_count', 0),
                'receipt_hash' => (string) ($company['receipt_hash'] ?? ''),
            ],
            (array) ($report['companies'] ?? []),
        ));

        return [
            'schema' => (string) ($report['schema'] ?? ''),
            'company_count' => (int) ($report['company_count'] ?? 0),
            'enterprise_company_count' => (int) ($report['enterprise_company_count'] ?? 0),
            'company_receipts' => $companies,
            'cross_company_fabric_hash' => (string) data_get($report, 'cross_company_fabric.fabric_hash', ''),
            'portfolio_governance_hash' => (string) data_get($report, 'portfolio_governance_stack.governance_hash', ''),
            'structural_completion_policy_hash' => (string) data_get($report, 'structural_completion_policy.policy_hash', ''),
            'portfolio_operating_model_hash' => (string) data_get($report, 'portfolio_operating_model.portfolio_governance.portfolio_hash', ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $manifests
     * @return array<string,mixed>
     */
    private function crossCompanyFabric(array $manifests): array
    {
        $handoffs = [];
        foreach ($manifests as $manifest) {
            $source = (string) ($manifest['domain_id'] ?? 'unknown');
            foreach ((array) data_get($manifest, 'handoff_rules.allowed', []) as $target) {
                $target = (string) $target;
                $handoffs[] = [
                    'schema' => 'atlas.ai.holding.cross_company_handoff_contract.v1',
                    'source_company' => $source,
                    'target_company' => $target,
                    'contract' => $source.'->'.$target,
                    'handoff_packet_required' => true,
                    'typed_context_required' => true,
                    'evidence_refs_required' => true,
                    'acceptance_required' => true,
                    'external_side_effects' => false,
                    'contract_hash' => hash('sha256', 'cross_company_handoff|'.$source.'|'.$target),
                ];
            }
        }

        $sharedServices = [
            'evidence_ledger',
            'decision_receipts',
            'policy_gate',
            'source_registry',
            'review_queue',
            'benchmark_harness',
            'operator_approval_gate',
        ];

        return [
            'schema' => 'atlas.ai.holding.cross_company_fabric.v1',
            'handoff_contract_count' => count($handoffs),
            'handoff_contracts' => $handoffs,
            'shared_service_catalog' => array_map(
                static fn (string $service): array => [
                    'service_id' => $service,
                    'mode' => 'governed_internal_shared_service',
                    'external_side_effects' => false,
                    'service_hash' => hash('sha256', 'holding_shared_service|'.$service),
                ],
                $sharedServices,
            ),
            'dependency_map' => array_map(
                static fn (array $manifest): array => [
                    'company_id' => (string) ($manifest['domain_id'] ?? 'unknown'),
                    'depends_on' => array_values((array) data_get($manifest, 'handoff_rules.allowed', [])),
                    'integration_contracts' => array_values((array) ($manifest['integration_contracts'] ?? [])),
                    'dependency_hash' => hash('sha256', 'dependency_map|'.(string) ($manifest['domain_id'] ?? 'unknown').'|'.implode('|', (array) data_get($manifest, 'handoff_rules.allowed', []))),
                ],
                $manifests,
            ),
            'fabric_gates' => [
                'source_company_packet_required',
                'target_company_acceptance_required',
                'evidence_refs_required',
                'policy_checked',
                'receipt_hash_required',
            ],
            'fabric_hash' => hash('sha256', 'atlas|cross_company_fabric|'.count($handoffs).'|'.implode('|', $sharedServices)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $manifests
     * @return array<string,mixed>
     */
    private function portfolioGovernanceStack(array $manifests): array
    {
        $companyIds = array_values(array_map(
            static fn (array $manifest): string => (string) ($manifest['domain_id'] ?? 'unknown'),
            $manifests,
        ));

        $decisionDomains = [
            'capital_allocation',
            'cross_company_priority_conflict',
            'new_external_connector',
            'external_side_effect_mandate',
            'incident_or_policy_exception',
            'company_promotion_or_pause',
        ];

        return [
            'schema' => 'atlas.ai.holding.portfolio_governance_stack.v1',
            'company_count' => count($companyIds),
            'portfolio_board' => [
                'cadence' => 'weekly_portfolio_operating_review',
                'chair' => 'portfolio_governor',
                'members' => array_values(array_map(
                    static fn (string $companyId): string => $companyId.'.company_manager_agent',
                    $companyIds,
                )),
                'operator_role' => 'final_approver_for_external_side_effects_and_real_capital',
                'required_inputs' => ['company_packet', 'operating_packet', 'risk_register', 'dependency_status', 'investment_committee_packet'],
            ],
            'decision_rights_matrix' => array_values(array_map(
                static fn (string $decisionDomain): array => [
                    'decision_domain' => $decisionDomain,
                    'recommendation_owner' => $decisionDomain === 'capital_allocation'
                        ? 'finance.company_manager_agent'
                        : 'portfolio_governor',
                    'consulted_companies' => $companyIds,
                    'approval_authority' => str_contains($decisionDomain, 'external') || $decisionDomain === 'capital_allocation'
                        ? 'operator'
                        : 'portfolio_governor_advisory_with_operator_override',
                    'required_evidence' => ['decision_packet', 'company_receipts', 'risk_review', 'rollback_or_pause_plan'],
                    'external_side_effects_allowed_without_operator' => false,
                    'decision_hash' => hash('sha256', 'portfolio_decision_right|'.$decisionDomain),
                ],
                $decisionDomains,
            )),
            'portfolio_dependency_registry' => array_values(array_map(
                static fn (array $manifest): array => [
                    'company_id' => (string) ($manifest['domain_id'] ?? 'unknown'),
                    'upstream_dependencies' => array_values((array) data_get($manifest, 'handoff_rules.allowed', [])),
                    'integration_contracts' => array_values((array) ($manifest['integration_contracts'] ?? [])),
                    'dependency_review_cadence' => 'weekly_or_before_promotion',
                    'blocked_when' => ['missing_handoff_acceptance', 'missing_policy_profile', 'stale_operating_packet', 'unresolved_sev1_or_sev2'],
                    'dependency_hash' => hash('sha256', 'portfolio_dependency_registry|'.(string) ($manifest['domain_id'] ?? 'unknown')),
                ],
                $manifests,
            )),
            'conflict_resolution_protocol' => [
                'priority_method' => 'portfolio_value_risk_urgency_confidence_and_operator_preference',
                'tie_breaker' => 'operator',
                'mandatory_artifacts' => ['conflict_packet', 'options_considered', 'tradeoff_record', 'decision_receipt'],
                'blocked_actions_until_resolved' => ['external_publish', 'external_spend', 'production_write', 'company_promotion'],
            ],
            'portfolio_resource_allocation' => [
                'allocation_units' => ['agent_capacity', 'review_capacity', 'tool_budget', 'integration_enablement_capacity'],
                'allocation_cadence' => 'weekly',
                'requires_company_capacity_stack' => true,
                'requires_finance_stack' => true,
                'real_spend_enabled' => false,
            ],
            'portfolio_observability' => [
                'required_metrics' => ['cross_company_handoff_acceptance_rate', 'portfolio_wip', 'dependency_blocker_count', 'review_queue_depth', 'policy_exception_count'],
                'dashboard' => 'atlas_portfolio_governance_board',
                'alert_routes' => ['portfolio_governor_queue', 'operator_review_queue'],
            ],
            'governance_hash' => hash('sha256', 'atlas|portfolio_governance|'.implode('|', $companyIds).'|'.implode('|', $decisionDomains)),
        ];
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
        $blueprint = $this->blueprint($domainId);
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
            'flows' => $this->flows($domainId, $blueprint),
            'flow_execution_contracts' => $this->flowExecutionContracts($domainId, $blueprint),
            'flow_playbooks' => $this->flowPlaybooks($domainId, $blueprint),
            'flow_runtime_blueprints' => $this->flowRuntimeBlueprints($domainId, $blueprint),
            'enterprise_flow_orchestration_runbook_stack' => $this->enterpriseFlowOrchestrationRunbookStack($domainId, $blueprint),
            'enterprise_flow_runtime_implementation_stack' => $this->enterpriseFlowRuntimeImplementationStack($domainId, $blueprint),
            'enterprise_flow_fixture_simulation_stack' => $this->enterpriseFlowFixtureSimulationStack($domainId, $blueprint),
            'enterprise_flow_action_runtime_stack' => $this->enterpriseFlowActionRuntimeStack($domainId, $blueprint),
            'agent_collaboration_model' => $this->agentCollaborationModel($domainId, $blueprint),
            'enterprise_agent_registry' => $this->enterpriseAgentRegistry($domainId, $blueprint, $agentRoles),
            'enterprise_domain_agent_toolkit_stack' => $this->enterpriseDomainAgentToolkitStack($domainId, $blueprint, $agentRoles),
            'enterprise_domain_workload_agent_template_stack' => $this->enterpriseDomainWorkloadAgentTemplateStack($domainId, $blueprint, $agentRoles),
            'enterprise_external_research_adoption_stack' => $this->enterpriseExternalResearchAdoptionStack($domainId, $blueprint),
            'enterprise_agent_repository_adoption_pipeline' => $this->enterpriseAgentRepositoryAdoptionPipeline($domainId, $blueprint),
            'enterprise_workforce_capacity_stack' => $this->enterpriseWorkforceCapacityStack($domainId, $blueprint),
            'enterprise_portfolio_dependency_stack' => $this->enterprisePortfolioDependencyStack($domainId, $manifest, $blueprint),
            'domain_data_model' => $this->domainDataModel($domainId, $blueprint),
            'business_process_map' => $this->businessProcessMap($domainId, $blueprint),
            'deliverable_quality_contracts' => $this->deliverableQualityContracts($domainId, $blueprint),
            'go_to_production_pack' => $this->goToProductionPack($domainId, $blueprint),
            'commercial_operating_stack' => $this->commercialOperatingStack($domainId, $blueprint),
            'enterprise_customer_market_operations_stack' => $this->enterpriseCustomerMarketOperationsStack($domainId, $blueprint),
            'enterprise_account_contract_delivery_stack' => $this->enterpriseAccountContractDeliveryStack($domainId, $blueprint, $companyMetrics),
            'enterprise_productized_service_stack' => $this->enterpriseProductizedServiceStack($domainId, $blueprint, $companyMetrics),
            'enterprise_sales_crm_pipeline_stack' => $this->enterpriseSalesCrmPipelineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_customer_support_service_desk_stack' => $this->enterpriseCustomerSupportServiceDeskStack($domainId, $blueprint, $companyMetrics),
            'enterprise_marketing_growth_engine_stack' => $this->enterpriseMarketingGrowthEngineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_finance_treasury_billing_stack' => $this->enterpriseFinanceTreasuryBillingStack($domainId, $blueprint, $companyMetrics),
            'enterprise_vendor_legal_procurement_stack' => $this->enterpriseVendorLegalProcurementStack($domainId, $blueprint),
            'enterprise_resilience_continuity_stack' => $this->enterpriseResilienceContinuityStack($domainId, $blueprint),
            'enterprise_analytics_decision_intelligence_stack' => $this->enterpriseAnalyticsDecisionIntelligenceStack($domainId, $blueprint, $metrics, $products),
            'enterprise_knowledge_memory_learning_stack' => $this->enterpriseKnowledgeMemoryLearningStack($domainId, $blueprint, $metrics, $products),
            'enterprise_identity_access_data_sovereignty_stack' => $this->enterpriseIdentityAccessDataSovereigntyStack($domainId, $blueprint),
            'enterprise_control_tower_run_operations_stack' => $this->enterpriseControlTowerRunOperationsStack($domainId, $blueprint, (array) ($manifest['recurring_cadences'] ?? [])),
            'enterprise_company_command_center_stack' => $this->enterpriseCompanyCommandCenterStack($domainId, $blueprint, $companyMetrics),
            'enterprise_semantic_operating_graph_stack' => $this->enterpriseSemanticOperatingGraphStack($domainId, $blueprint, $functions, $agentRoles, $workProducts, $companyMetrics, $cadences),
            'enterprise_delivery_assurance_stack' => $this->enterpriseDeliveryAssuranceStack($domainId, $blueprint),
            'portfolio_finance_stack' => $this->portfolioFinanceStack($domainId, $blueprint),
            'enterprise_unit_economics_capacity_simulation_stack' => $this->enterpriseUnitEconomicsCapacitySimulationStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'strategic_intelligence_stack' => $this->strategicIntelligenceStack($domainId, $blueprint),
            'enterprise_grc_stack' => $this->enterpriseGrcStack($domainId, $blueprint),
            'enterprise_capability_matrix' => $this->enterpriseCapabilityMatrix($domainId, $blueprint),
            'external_integration_catalog' => $this->externalIntegrationCatalog($domainId, $blueprint),
            'enterprise_connector_certification_stack' => $this->enterpriseConnectorCertificationStack($domainId, $blueprint),
            'enterprise_production_connector_preflight_stack' => $this->enterpriseProductionConnectorPreflightStack($domainId, $blueprint),
            'api_surface' => $this->apiSurface($domainId, $blueprint),
            'evaluation_harness' => $this->evaluationHarness($domainId, $blueprint),
            'enterprise_flow_benchmark_replay_stack' => $this->enterpriseFlowBenchmarkReplayStack($domainId, $blueprint),
            'enterprise_tooling_research_stack' => $this->enterpriseToolingResearchStack($domainId, $blueprint),
            'enterprise_domain_operating_depth_stack' => $this->enterpriseDomainOperatingDepthStack($domainId, $blueprint),
            'enterprise_domain_agent_workforce_stack' => $this->enterpriseDomainAgentWorkforceStack($domainId, $blueprint),
            'enterprise_domain_solution_stack' => $this->enterpriseDomainSolutionStack($domainId, $blueprint),
            'enterprise_vertical_solution_suite_stack' => $this->enterpriseVerticalSolutionSuiteStack($domainId, $blueprint),
            'enterprise_domain_business_execution_mesh_stack' => $this->enterpriseDomainBusinessExecutionMeshStack($domainId, $blueprint, $companyMetrics),
            'enterprise_domain_provider_workbench_stack' => $this->enterpriseDomainProviderWorkbenchStack($domainId, $blueprint),
            'enterprise_industry_solution_ecosystem_stack' => $this->enterpriseIndustrySolutionEcosystemStack($domainId, $blueprint),
            'enterprise_domain_company_execution_suite_stack' => $this->enterpriseDomainCompanyExecutionSuiteStack($domainId, $blueprint, $companyMetrics),
            'enterprise_flow_work_product_delivery_stack' => $this->enterpriseFlowWorkProductDeliveryStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_domain_data_connector_operating_stack' => $this->enterpriseDomainDataConnectorOperatingStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_flow_live_read_connector_probe_stack' => $this->enterpriseFlowLiveReadConnectorProbeStack($domainId, $blueprint, $companyMetrics),
            'premium_enterprise_agent_reference_model' => $this->premiumEnterpriseAgentReferenceModel($domainId, $blueprint),
            'enterprise_flow_operating_packages' => $this->enterpriseFlowOperatingPackages($domainId, $blueprint),
            'enterprise_integration_activation_plan' => $this->enterpriseIntegrationActivationPlan($domainId, $blueprint),
            'enterprise_operational_dress_rehearsal_stack' => $this->enterpriseOperationalDressRehearsalStack($domainId, $blueprint),
            'autonomy_promotion_ladder' => $this->autonomyPromotionLadder($domainId),
            'connectors' => $this->connectors($domainId, $blueprint),
            'toolchain' => $this->toolchain($domainId, $blueprint),
            'enterprise_operating_system' => $this->enterpriseOperatingSystem($domainId, $blueprint),
            'work_products' => $workProducts,
            'metrics' => $companyMetrics,
            'cadences' => $cadences,
            'enterprise_reference_architecture' => $this->enterpriseReferenceArchitecture($domainId, $blueprint),
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
        $company['readiness'] = $this->readiness($company);
        $company['receipt_hash'] = MissionCanonicalHash::sha256($this->companyHashInput($company));

        return $company;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function companyHashInput(array $company): array
    {
        return [
            'schema' => (string) ($company['schema'] ?? ''),
            'company_id' => (string) ($company['company_id'] ?? ''),
            'name' => (string) ($company['name'] ?? ''),
            'ok' => (bool) ($company['ok'] ?? false),
            'readiness' => [
                'ok' => (bool) data_get($company, 'readiness.ok', false),
                'function_count' => (int) data_get($company, 'readiness.function_count', 0),
                'agent_role_count' => (int) data_get($company, 'readiness.agent_role_count', 0),
                'flow_count' => (int) data_get($company, 'readiness.flow_count', 0),
                'connector_count' => (int) data_get($company, 'readiness.connector_count', 0),
                'runtime_command_count' => (int) data_get($company, 'readiness.runtime_command_count', 0),
            ],
            'stack_hashes' => [
                'workload_agent_template_stack_hash' => (string) data_get($company, 'enterprise_domain_workload_agent_template_stack.workload_agent_template_stack_hash', ''),
                'business_backbone_hash' => (string) data_get($company, 'enterprise_business_operating_backbone_stack.backbone_hash', ''),
                'command_center_hash' => (string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', ''),
                'semantic_graph_hash' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', ''),
                'flow_delivery_hash' => (string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', ''),
            ],
            'policy' => [
                'autonomy' => (string) data_get($company, 'policy.autonomy', ''),
                'risk' => (string) data_get($company, 'policy.risk', ''),
                'external_side_effects_allowed' => (bool) data_get($company, 'policy.external_side_effects_allowed', true),
                'operator_approval_required' => (bool) data_get($company, 'policy.operator_approval_required', false),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blueprint(string $domainId): array
    {
        $map = $this->blueprints();

        return $map[$domainId] ?? $map['operations'];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function blueprints(): array
    {
        return [
            'software' => [
                'agent_roles' => ['principal_architect_agent', 'code_intelligence_agent', 'release_captain_agent', 'security_reviewer_agent'],
                'connectors' => ['git_workspace', 'test_runner', 'code_intelligence_index', 'ci_status_read_adapter', 'evidence_ledger'],
                'reference_patterns' => ['execution_grounded_software_agents', 'repo_state_feedback_loop', 'durable_patch_review_repair'],
                'flow_specs' => [
                    'product_spec_to_patch' => ['principal_architect_agent', ['git_workspace', 'code_intelligence_index'], 'spec_pack_to_patch_plan'],
                    'autonomous_repair_loop' => ['code_intelligence_agent', ['test_runner', 'evidence_ledger'], 'failure_triage_patch_repair'],
                    'release_certification' => ['release_captain_agent', ['ci_status_read_adapter', 'evidence_ledger'], 'release_evidence_packet'],
                    'security_review' => ['security_reviewer_agent', ['git_workspace', 'code_intelligence_index'], 'security_review_report'],
                    'dependency_impact_map' => ['code_intelligence_agent', ['git_workspace', 'code_intelligence_index'], 'dependency_impact_map'],
                    'post_release_learning_loop' => ['release_captain_agent', ['ci_status_read_adapter', 'evidence_ledger'], 'post_release_learning_record'],
                ],
                'work_products' => ['architecture_decision_record', 'patch_plan', 'repair_packet', 'release_certification', 'security_review_report'],
                'metrics' => ['test_pass_rate', 'repair_cycle_time', 'release_certification_rate', 'security_findings_closed'],
                'cadences' => ['per_patch_repair_loop', 'daily_ci_certification', 'weekly_security_review'],
                'dashboards' => ['delivery_flow_board', 'quality_gate_board', 'release_risk_board'],
                'review_queue' => 'engineering_review_queue',
            ],
            'research' => [
                'agent_roles' => ['principal_researcher_agent', 'source_intelligence_agent', 'fact_check_agent', 'synthesis_editor_agent'],
                'connectors' => ['web_search', 'paper_pdf_parser', 'source_registry', 'citation_graph', 'evidence_bridge'],
                'reference_patterns' => ['source_linked_claim_verification', 'citation_graph_review', 'contradiction_adjudication'],
                'flow_specs' => [
                    'deep_research_brief' => ['principal_researcher_agent', ['web_search', 'source_registry'], 'source_linked_research_brief'],
                    'citation_graph_review' => ['source_intelligence_agent', ['citation_graph', 'paper_pdf_parser'], 'citation_health_report'],
                    'contradiction_adjudication' => ['fact_check_agent', ['source_registry', 'evidence_bridge'], 'contradiction_decision_packet'],
                    'executive_synthesis' => ['synthesis_editor_agent', ['evidence_bridge'], 'operator_ready_research_memo'],
                    'source_watchlist_monitor' => ['source_intelligence_agent', ['web_search', 'source_registry'], 'source_watchlist_delta'],
                    'primary_source_gap_review' => ['fact_check_agent', ['source_registry', 'citation_graph'], 'primary_source_gap_report'],
                ],
                'work_products' => ['research_brief', 'source_quality_report', 'citation_graph', 'contradiction_packet', 'executive_synthesis'],
                'metrics' => ['primary_source_ratio', 'citation_health', 'claim_support_rate', 'contradiction_resolution_rate'],
                'cadences' => ['daily_source_watch', 'per_claim_fact_check', 'weekly_research_synthesis'],
                'dashboards' => ['source_quality_board', 'claims_and_contradictions_board', 'research_pipeline_board'],
                'review_queue' => 'research_editorial_queue',
            ],
            'strategy' => [
                'agent_roles' => ['venture_partner_agent', 'market_mapper_agent', 'gtm_operator_agent', 'experiment_allocator_agent'],
                'connectors' => ['research_handoff', 'finance_model_handoff', 'market_dataset_read_adapter', 'competitive_intelligence_read_adapter', 'experiment_registry'],
                'reference_patterns' => ['portfolio_thesis_loop', 'experiment_allocator_committee', 'assumption_ledger'],
                'flow_specs' => [
                    'venture_thesis' => ['venture_partner_agent', ['research_handoff', 'market_dataset_read_adapter'], 'venture_thesis_memo'],
                    'market_map' => ['market_mapper_agent', ['research_handoff', 'competitive_intelligence_read_adapter'], 'tam_sam_som_market_map'],
                    'gtm_system_design' => ['gtm_operator_agent', ['experiment_registry'], 'gtm_operating_plan'],
                    'portfolio_experiment_review' => ['experiment_allocator_agent', ['finance_model_handoff', 'experiment_registry'], 'experiment_allocation_packet'],
                    'competitive_wargame' => ['market_mapper_agent', ['research_handoff', 'market_dataset_read_adapter', 'competitive_intelligence_read_adapter'], 'competitive_wargame_memo'],
                    'board_decision_dossier' => ['venture_partner_agent', ['research_handoff', 'finance_model_handoff'], 'board_decision_dossier'],
                ],
                'work_products' => ['venture_thesis', 'market_map', 'gtm_operating_plan', 'experiment_allocation_packet', 'board_strategy_memo'],
                'metrics' => ['assumption_coverage', 'experiment_velocity', 'opportunity_quality_score', 'capital_efficiency_estimate'],
                'cadences' => ['weekly_opportunity_committee', 'per_experiment_review', 'monthly_strategy_board'],
                'dashboards' => ['opportunity_pipeline_board', 'experiment_portfolio_board', 'strategy_decision_log'],
                'review_queue' => 'strategy_committee_queue',
            ],
            'finance' => [
                'agent_roles' => array_column((array) $this->finance->packet('AAPL')['agent_desk'], 'role'),
                'connectors' => array_column((array) $this->finance->packet('AAPL')['connectors'], 'id'),
                'reference_patterns' => ['unified_financial_data_interface', 'source_linked_claim_verification', 'financial_modeling_audit_trail', 'portfolio_monitoring', 'compliance_automation', 'data_room_due_diligence'],
                'flow_specs' => collect((array) $this->finance->packet('AAPL')['flows'])
                    ->mapWithKeys(static fn (array $flow): array => [
                        (string) $flow['id'] => [
                            (string) $flow['agent_role'],
                            (array) $flow['connectors'],
                            (string) $flow['output'],
                        ],
                    ])
                    ->all(),
                'work_products' => (array) $this->finance->packet('AAPL')['work_products'],
                'metrics' => (array) $this->finance->packet('AAPL')['metrics'],
                'cadences' => (array) $this->finance->packet('AAPL')['operating_cadences'],
                'dashboards' => ['portfolio_monitoring_board', 'model_risk_board', 'investment_committee_board'],
                'review_queue' => 'investment_committee_queue',
            ],
            'marketing' => [
                'agent_roles' => ['growth_lead_agent', 'brand_strategy_agent', 'creative_director_agent', 'analytics_agent', 'lifecycle_agent'],
                'connectors' => ['analytics_read_adapter', 'crm_read_adapter', 'content_repository', 'approval_gate', 'experiment_registry'],
                'reference_patterns' => ['crew_planning_reasoning_tools_memory', 'growth_experiment_registry', 'approval_gated_campaign_ops'],
                'flow_specs' => [
                    'growth_strategy' => ['growth_lead_agent', ['analytics_read_adapter', 'crm_read_adapter'], 'growth_strategy_packet'],
                    'brand_positioning_system' => ['brand_strategy_agent', ['research_handoff', 'content_repository'], 'positioning_system'],
                    'creative_production_brief' => ['creative_director_agent', ['content_repository', 'approval_gate'], 'creative_brief_and_copy_pack'],
                    'funnel_experiment_loop' => ['analytics_agent', ['analytics_read_adapter', 'experiment_registry'], 'funnel_experiment_readout'],
                    'lifecycle_campaign_system' => ['lifecycle_agent', ['crm_read_adapter', 'approval_gate'], 'lifecycle_campaign_plan'],
                    'channel_mix_review' => ['growth_lead_agent', ['analytics_read_adapter', 'experiment_registry'], 'channel_mix_reallocation_proposal'],
                    'voice_of_customer_synthesis' => ['brand_strategy_agent', ['crm_read_adapter', 'content_repository'], 'voc_synthesis_packet'],
                ],
                'work_products' => ['growth_strategy_packet', 'positioning_system', 'creative_brief', 'copy_pack', 'funnel_experiment_readout', 'lifecycle_campaign_plan'],
                'metrics' => ['activation_rate', 'conversion_rate', 'creative_throughput', 'experiment_velocity', 'approval_latency'],
                'cadences' => ['weekly_growth_review', 'per_campaign_approval', 'monthly_positioning_review'],
                'dashboards' => ['growth_dashboard', 'creative_pipeline_board', 'campaign_approval_board'],
                'review_queue' => 'marketing_approval_queue',
            ],
            'cyber' => [
                'agent_roles' => ['security_architect_agent', 'appsec_triage_agent', 'grc_lead_agent', 'detection_engineer_agent', 'remediation_manager_agent'],
                'connectors' => ['sbom_read_adapter', 'repo_read_adapter', 'vulnerability_feed_read_adapter', 'evidence_chain', 'ticketing_proposal_adapter'],
                'reference_patterns' => ['stateful_incident_response_graph', 'human_in_loop_remediation_approval', 'scope_and_roe_gate'],
                'flow_specs' => [
                    'security_posture_review' => ['security_architect_agent', ['sbom_read_adapter', 'vulnerability_feed_read_adapter'], 'security_posture_report'],
                    'appsec_triage' => ['appsec_triage_agent', ['repo_read_adapter', 'evidence_chain'], 'finding_triage_packet'],
                    'grc_obligation_map' => ['grc_lead_agent', ['evidence_chain'], 'grc_control_mapping'],
                    'detection_authoring_review' => ['detection_engineer_agent', ['vulnerability_feed_read_adapter'], 'detection_rule_proposal'],
                    'remediation_program' => ['remediation_manager_agent', ['ticketing_proposal_adapter', 'evidence_chain'], 'remediation_program_plan'],
                    'incident_response_readiness' => ['security_architect_agent', ['evidence_chain', 'vulnerability_feed_read_adapter'], 'incident_response_readiness_packet'],
                    'attack_surface_delta_review' => ['appsec_triage_agent', ['repo_read_adapter', 'sbom_read_adapter'], 'attack_surface_delta_report'],
                ],
                'work_products' => ['security_posture_report', 'finding_triage_packet', 'grc_control_mapping', 'detection_rule_proposal', 'remediation_program_plan'],
                'metrics' => ['critical_finding_count', 'mttr', 'control_coverage', 'remediation_sla_risk', 'scope_block_rate'],
                'cadences' => ['daily_security_posture_scan', 'per_finding_triage', 'monthly_grc_review'],
                'dashboards' => ['security_posture_board', 'remediation_board', 'grc_controls_board'],
                'review_queue' => 'security_review_queue',
            ],
            'automation' => [
                'agent_roles' => ['automation_architect_agent', 'tool_scout_agent', 'browser_workflow_agent', 'api_workflow_agent', 'tool_reliability_agent'],
                'connectors' => ['tool_registry', 'browser_planning_adapter', 'api_schema_read_adapter', 'repo_read_adapter', 'execution_receipt_ledger'],
                'reference_patterns' => ['agent_tool_selection_benchmark', 'mcp_tool_surface', 'durable_automation_receipts'],
                'flow_specs' => [
                    'automation_opportunity_intake' => ['automation_architect_agent', ['tool_registry'], 'automation_opportunity_pack'],
                    'tool_selection_benchmark' => ['tool_scout_agent', ['tool_registry', 'repo_read_adapter'], 'tool_selection_scorecard'],
                    'browser_workflow_design' => ['browser_workflow_agent', ['browser_planning_adapter'], 'browser_workflow_runbook'],
                    'api_workflow_design' => ['api_workflow_agent', ['api_schema_read_adapter'], 'api_workflow_runbook'],
                    'tool_reliability_loop' => ['tool_reliability_agent', ['execution_receipt_ledger'], 'tool_reliability_report'],
                    'mcp_adapter_design' => ['api_workflow_agent', ['api_schema_read_adapter', 'tool_registry'], 'mcp_adapter_design_packet'],
                    'automation_replay_review' => ['tool_reliability_agent', ['execution_receipt_ledger'], 'automation_replay_review'],
                ],
                'work_products' => ['automation_opportunity_pack', 'tool_selection_scorecard', 'browser_workflow_runbook', 'api_workflow_runbook', 'tool_reliability_report'],
                'metrics' => ['automation_roi_score', 'blocked_action_rate', 'tool_success_rate', 'manual_time_saved_estimate', 'reliability_regression_count'],
                'cadences' => ['daily_tool_health_review', 'per_automation_receipt_review', 'weekly_automation_portfolio_review'],
                'dashboards' => ['automation_portfolio_board', 'tool_reliability_board', 'risk_block_board'],
                'review_queue' => 'automation_governance_queue',
            ],
            'personal_development' => [
                'agent_roles' => ['executive_coach_agent', 'learning_designer_agent', 'habit_system_agent', 'reflection_analyst_agent', 'privacy_guardian_agent'],
                'connectors' => ['private_memory_surface', 'goal_registry', 'habit_log_read_adapter', 'learning_resource_registry', 'human_review_packet'],
                'reference_patterns' => ['persistent_learner_model', 'privacy_first_memory_review', 'deliberate_practice_loop'],
                'flow_specs' => [
                    'life_operating_review' => ['executive_coach_agent', ['private_memory_surface', 'goal_registry'], 'life_operating_review'],
                    'learning_curriculum_design' => ['learning_designer_agent', ['learning_resource_registry'], 'learning_curriculum'],
                    'habit_system_iteration' => ['habit_system_agent', ['habit_log_read_adapter'], 'habit_system_plan'],
                    'reflection_synthesis' => ['reflection_analyst_agent', ['private_memory_surface'], 'reflection_synthesis'],
                    'privacy_safe_memory_review' => ['privacy_guardian_agent', ['human_review_packet'], 'privacy_review_packet'],
                    'skill_gap_diagnosis' => ['learning_designer_agent', ['goal_registry', 'learning_resource_registry'], 'skill_gap_diagnosis'],
                    'practice_session_review' => ['habit_system_agent', ['habit_log_read_adapter'], 'practice_session_review'],
                ],
                'work_products' => ['life_operating_review', 'learning_curriculum', 'habit_system_plan', 'reflection_synthesis', 'privacy_review_packet'],
                'metrics' => ['goal_progress_rate', 'habit_adherence', 'learning_session_count', 'privacy_review_pass_rate'],
                'cadences' => ['daily_reflection', 'weekly_goal_review', 'monthly_learning_review'],
                'dashboards' => ['goal_progress_board', 'habit_system_board', 'learning_pipeline_board'],
                'review_queue' => 'personal_review_queue',
            ],
            'operations' => [
                'agent_roles' => ['sre_lead_agent', 'incident_commander_agent', 'runbook_engineer_agent', 'capacity_planner_agent', 'postmortem_editor_agent'],
                'connectors' => ['log_read_adapter', 'metrics_read_adapter', 'alert_read_adapter', 'runbook_repository', 'evidence_ledger'],
                'reference_patterns' => ['stateful_incident_response_graph', 'durable_human_approval_checkpoint', 'slo_runbook_feedback_loop'],
                'flow_specs' => [
                    'operational_readiness_review' => ['sre_lead_agent', ['metrics_read_adapter', 'runbook_repository'], 'readiness_review'],
                    'incident_command_packet' => ['incident_commander_agent', ['alert_read_adapter', 'log_read_adapter'], 'incident_command_packet'],
                    'runbook_system_update' => ['runbook_engineer_agent', ['runbook_repository', 'evidence_ledger'], 'runbook_update_packet'],
                    'capacity_and_slo_review' => ['capacity_planner_agent', ['metrics_read_adapter'], 'capacity_slo_review'],
                    'postmortem_action_loop' => ['postmortem_editor_agent', ['evidence_ledger'], 'postmortem_action_plan'],
                    'alert_noise_reduction' => ['sre_lead_agent', ['alert_read_adapter', 'metrics_read_adapter'], 'alert_noise_reduction_plan'],
                    'change_readiness_review' => ['incident_commander_agent', ['runbook_repository', 'evidence_ledger'], 'change_readiness_review'],
                ],
                'work_products' => ['readiness_review', 'incident_command_packet', 'runbook_update_packet', 'capacity_slo_review', 'postmortem_action_plan'],
                'metrics' => ['slo_breach_count', 'mttr', 'runbook_coverage', 'alert_noise_rate', 'postmortem_action_completion'],
                'cadences' => ['daily_readiness_review', 'per_incident_command', 'weekly_slo_review'],
                'dashboards' => ['ops_readiness_board', 'incident_board', 'slo_capacity_board'],
                'review_queue' => 'operations_review_queue',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return list<array<string,mixed>>
     */
    private function enterpriseAgentRegistry(string $domainId, array $blueprint, array $agentRoles): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $agents = array_values(array_unique($agentRoles));

        return array_values(array_map(
            fn (string $agent, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_agent_registry_entry.v1',
                'agent_id' => $domainId.'.'.$agent,
                'company_id' => $domainId,
                'role' => $agent,
                'version' => '2026.05.enterprise',
                'ownership' => [
                    'primary_flows' => array_values(array_filter(
                        array_keys($flowSpecs),
                        static fn (string $flowId): bool => (string) ($flowSpecs[$flowId][0] ?? '') === $agent,
                    )),
                    'backup_role' => $index === 0 ? 'portfolio_governor' : (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                    'escalation_queue' => (string) $blueprint['review_queue'],
                ],
                'capabilities' => [
                    'plan',
                    'retrieve',
                    'analyze',
                    'produce_typed_artifact',
                    'handoff',
                    'explain_with_evidence',
                ],
                'allowed_connector_scope' => $this->enterpriseConnectorsForBlueprint($blueprint),
                'memory_scope' => [
                    'company_scoped_memory' => true,
                    'cross_company_memory_requires_handoff' => true,
                    'private_or_regulated_data_requires_policy_profile' => true,
                ],
                'evaluation_contract' => [
                    'minimum_flow_score' => 0.86,
                    'tool_receipts_required' => true,
                    'policy_findings_allowed' => 0,
                    'reviewer' => 'independent_reviewer_agent',
                ],
                'external_side_effects' => false,
                'registry_hash' => hash('sha256', $domainId.'|agent_registry|'.$agent.'|'.$index),
            ],
            $agents,
            array_keys($agents),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return array<string,mixed>
     */
    private function enterpriseDomainAgentToolkitStack(string $domainId, array $blueprint, array $agentRoles): array
    {
        $agents = array_values(array_unique($agentRoles));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $frameworkSources = $this->agentFrameworkSourceCatalog($domainId);
        $domainSources = $this->domainSolutionSourceCatalog($domainId);
        $frameworkIds = array_column($frameworkSources, 'source_id');
        $domainSourceIds = array_column($domainSources, 'source_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_agent_toolkit_stack.v1',
            'company_id' => $domainId,
            'toolkit_policy' => [
                'mode' => 'domain_specialist_agents_with_certified_toolkits',
                'source_basis' => ['openai_agents_sdk', 'crewai_flows_crews', 'microsoft_agent_framework', 'microsoft_autogen_agent_framework_lineage', 'langgraph_durable_execution', 'model_context_protocol_servers', 'temporal_durable_workflows', 'opentelemetry_collector_tracing', 'anthropic_claude_for_financial_services'],
                'buildout_blocked_by_observed_history_window' => false,
                'runtime_use_before_toolkit_certification_allowed' => false,
                'external_side_effects_default' => false,
                'operator_approval_required_for_toolkit_external_action' => true,
            ],
            'framework_source_catalog' => $frameworkSources,
            'domain_source_catalog_refs' => array_values(array_slice($domainSourceIds, 0, min(6, count($domainSourceIds)))),
            'agent_toolkit_profiles' => array_values(array_map(
                fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'toolkit_id' => $domainId.'.'.$agent.'.toolkit.v1',
                    'primary_framework_patterns' => array_values(array_slice($frameworkIds, 0, min(4, count($frameworkIds)))),
                    'domain_source_refs' => array_values(array_slice($domainSourceIds, $index % max(1, count($domainSourceIds)), min(3, count($domainSourceIds)))),
                    'core_skills' => [
                        'context_pack_loading',
                        'domain_source_selection',
                        'tool_schema_reasoning',
                        'structured_artifact_authoring',
                        'critic_response_repair',
                        'handoff_packet_production',
                    ],
                    'tool_groups' => [
                        'context' => ['open_brain_context_pack', 'domain_memory_read_model', 'source_registry'],
                        'reasoning' => ['scratchpad_state', 'assumption_ledger', 'option_comparison_matrix'],
                        'connector' => $connectors,
                        'verification' => ['policy_gate', 'critic_review', 'evaluation_harness', 'receipt_verifier'],
                    ],
                    'blocked_tool_groups_without_operator' => ['external_write', 'external_publish', 'real_spend', 'live_trade', 'offensive_security', 'secret_export'],
                    'certification_required_before_shadow_mode' => true,
                    'profile_hash' => hash('sha256', $domainId.'|agent_toolkit_profile|'.$agent.'|'.$index),
                ],
                $agents,
                array_keys($agents),
            )),
            'flow_toolkit_assignments' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'assigned_toolkit' => $domainId.'.'.(string) $spec[0].'.toolkit.v1',
                    'required_connector_subset' => array_values((array) $spec[1]),
                    'required_skill_sequence' => ['intake_classification', 'source_grounding', 'tool_plan', 'domain_analysis', 'artifact_generation', 'critic_review', 'policy_gate', 'operator_checkpoint'],
                    'minimum_certification_state' => 'contract_and_fixture_green_before_shadow',
                    'assignment_hash' => hash('sha256', $domainId.'|flow_toolkit_assignment|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'repository_and_agent_watchlist' => [
                'global_agent_frameworks' => [
                    'https://github.com/openai/openai-agents-python',
                    'https://github.com/crewAIInc/crewAI',
                    'https://github.com/microsoft/agent-framework',
                    'https://github.com/microsoft/autogen',
                    'https://github.com/langchain-ai/langgraph',
                    'https://github.com/modelcontextprotocol/servers',
                    'https://github.com/temporalio/sdk-php',
                    'https://github.com/open-telemetry/opentelemetry-collector',
                ],
                'domain_specific_sources' => array_values(array_map(
                    static fn (array $source): string => (string) $source['url'],
                    $domainSources,
                )),
                'watch_review_cadence' => 'weekly_domain_agent_toolkit_review',
                'adoption_requires' => ['license_review', 'security_review', 'local_fixture_eval', 'operator_acceptance', 'rollback_plan'],
                'watchlist_hash' => hash('sha256', $domainId.'|domain_agent_toolkit_watchlist|'.implode('|', $flowIds)),
            ],
            'toolkit_certification_matrix' => array_values(array_map(
                static fn (string $agent): array => [
                    'agent_role' => $agent,
                    'certification_suite' => $agent.'.toolkit_certification.v1',
                    'required_checks' => ['prompt_contract_snapshot', 'tool_schema_contract', 'fixture_replay_green', 'policy_boundary_green', 'receipt_export_green', 'handoff_acceptance_green'],
                    'promotion_blockers' => ['missing_tool_schema', 'missing_receipt', 'policy_finding', 'unsupported_domain_source', 'operator_checkpoint_missing'],
                    'certification_hash' => hash('sha256', 'agent_toolkit_certification|'.$agent),
                ],
                $agents,
            )),
            'toolkit_observability' => [
                'required_metrics' => ['toolkit_profile_coverage', 'flow_toolkit_assignment_coverage', 'tool_schema_contract_coverage', 'fixture_replay_pass_rate', 'policy_boundary_pass_rate', 'handoff_acceptance_rate', 'toolkit_drift_count'],
                'dashboard' => $domainId.'_domain_agent_toolkit_board',
                'alert_on' => ['toolkit_drift', 'missing_schema_contract', 'policy_boundary_failure', 'uncertified_toolkit_used'],
            ],
            'toolkit_stack_hash' => hash('sha256', $domainId.'|enterprise_domain_agent_toolkit|'.implode('|', $agents).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $agentRoles
     * @return array<string,mixed>
     */
    private function enterpriseDomainWorkloadAgentTemplateStack(string $domainId, array $blueprint, array $agentRoles): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $domainSources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($domainSources, 'source_id'));

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_workload_agent_template_stack.v1',
            'company_id' => $domainId,
            'reference_architecture' => [
                'pattern' => 'anthropic_financial_services_agents_generalized_to_every_atlas_company',
                'source_url' => 'https://www.anthropic.com/news/finance-agents',
                'announced_at' => '2026-05-05',
                'templates_package' => ['skills', 'connectors', 'subagents'],
                'enterprise_runtime_features' => ['long_running_sessions', 'per_tool_permissions', 'managed_credential_vault', 'full_audit_log', 'human_in_the_loop_approval'],
                'calendar_wait_blocker_enabled' => false,
                'external_execution_authority_granted' => false,
            ],
            'template_policy' => [
                'mode' => 'ready_to_run_domain_workload_templates_external_effects_blocked',
                'template_required_for_every_flow' => true,
                'skills_are_trigger_loaded_not_always_on_context' => true,
                'connectors_are_governed_read_or_fixture_until_operator_scope' => true,
                'subagents_start_with_minimal_scoped_context' => true,
                'audit_log_required_for_every_tool_call_and_decision' => true,
                'human_review_required_before_client_delivery_filing_payment_trade_publish_deploy_delete_or_security_action' => true,
                'external_write_spend_trade_publish_deploy_delete_or_offensive_security_allowed' => false,
            ],
            'workload_agent_templates' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_workload_agent_template.v1',
                    'template_id' => $domainId.'.'.$flowId.'.workload_agent_template.v1',
                    'flow_id' => $flowId,
                    'display_name' => str_replace('_', ' ', $flowId).' agent',
                    'owner_agent' => (string) ($spec[0] ?? ($agentRoles[0] ?? 'domain_operator_agent')),
                    'workload_family' => $this->domainWorkloadFamily($domainId),
                    'target_artifact' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'skills' => $this->domainWorkloadSkills($domainId, $flowId),
                    'connectors' => [
                        'required' => array_values((array) ($spec[1] ?? [])),
                        'available_company_connectors' => $connectors,
                        'domain_source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                        'governance' => ['least_privilege_scope', 'read_only_or_fixture_default', 'schema_snapshot_required', 'source_lineage_required', 'receipt_export_required'],
                    ],
                    'subagents' => $this->domainWorkloadSubagents($domainId, $flowId),
                    'runtime_contract' => [
                        'session_mode' => 'long_running_supervised_session',
                        'credential_binding' => 'managed_vault_reference_only',
                        'tool_permission_model' => 'per_tool_permission_with_operator_scope',
                        'audit_log' => 'decision_tool_call_artifact_and_handoff_receipts',
                        'context_carryover' => 'company_memory_and_flow_handoff_packet',
                        'failure_mode' => 'pause_emit_repair_packet_and_fallback_to_fixture_or_manual_handoff',
                    ],
                    'approval_contract' => [
                        'operator_review_required' => true,
                        'second_reviewer_required_for' => ['client_delivery', 'filing', 'payment', 'trade', 'public_publish', 'deploy', 'delete', 'security_action'],
                        'auto_send_post_pay_trade_publish_deploy_delete_allowed' => false,
                    ],
                    'distribution_package' => [
                        'schema' => 'atlas.ai.company.domain_workload_agent_distribution_package.v1',
                        'package_id' => $domainId.'.'.$flowId.'.agent_package.v1',
                        'surfaces' => ['atlas_cli', 'atlas_desktop', 'atlas_mcp_readonly', 'managed_agent_cookbook'],
                        'plugin_manifest' => [
                            'manifest_id' => $domainId.'.'.$flowId.'.plugin_manifest.v1',
                            'entrypoint' => 'workload_agent_template',
                            'trigger_skills' => $this->domainWorkloadSkills($domainId, $flowId),
                            'permission_profile' => 'read_fixture_shadow_until_operator_scope',
                            'external_effects_enabled' => false,
                        ],
                        'managed_agent_cookbook' => [
                            'cookbook_id' => $domainId.'.'.$flowId.'.managed_agent_cookbook.v1',
                            'session_model' => 'long_running_supervised_session',
                            'tool_permissions' => 'per_tool_operator_scoped',
                            'credential_vault' => 'managed_vault_reference_only',
                            'audit_log' => 'full_tool_decision_artifact_handoff_log',
                            'deployment_mode' => 'internal_supervised_candidate',
                        ],
                        'skill_bundle' => [
                            'load_mode' => 'trigger_loaded',
                            'always_on_context_allowed' => false,
                            'skill_count' => count($this->domainWorkloadSkills($domainId, $flowId)),
                            'bundle_hash' => hash('sha256', $domainId.'|'.$flowId.'|workload_skill_bundle'),
                        ],
                        'subagent_bundle' => [
                            'context_mode' => 'minimal_scoped_context',
                            'subagent_count' => count($this->domainWorkloadSubagents($domainId, $flowId)),
                            'external_effects_enabled' => false,
                            'bundle_hash' => hash('sha256', $domainId.'|'.$flowId.'|workload_subagent_bundle'),
                        ],
                        'connector_permission_manifest' => [
                            'connector_refs' => array_values((array) ($spec[1] ?? [])),
                            'default_mode' => 'fixture_or_read_only_probe',
                            'write_spend_trade_publish_deploy_delete_allowed' => false,
                            'operator_scope_required_for_live_connector' => true,
                            'tool_permission_matrix' => array_values(array_map(
                                static fn (string $connector): array => [
                                    'connector_ref' => $connector,
                                    'default_permission' => 'fixture_or_read_only_probe',
                                    'live_scope_requires_operator' => true,
                                    'write_spend_trade_publish_deploy_delete_allowed' => false,
                                    'permission_hash' => hash('sha256', $domainId.'|'.$flowId.'|tool_permission|'.$connector),
                                ],
                                array_values((array) ($spec[1] ?? [])),
                            )),
                            'permission_matrix_hash' => hash('sha256', $domainId.'|'.$flowId.'|tool_permission_matrix'),
                            'manifest_hash' => hash('sha256', $domainId.'|'.$flowId.'|connector_permission_manifest'),
                        ],
                        'audit_manifest' => [
                            'required_events' => ['template_loaded', 'skill_loaded', 'connector_called', 'subagent_called', 'artifact_created', 'policy_gate_evaluated', 'operator_review_requested'],
                            'receipt_required' => true,
                            'replay_manifest_required' => true,
                            'manifest_hash' => hash('sha256', $domainId.'|'.$flowId.'|audit_manifest'),
                        ],
                        'rollout_plan' => [
                            'stage' => 'internal_shadow_candidate',
                            'install_steps' => ['register_plugin_manifest', 'register_managed_agent_cookbook', 'bind_skill_bundle', 'bind_subagent_bundle', 'bind_connector_permissions', 'bind_audit_manifest'],
                            'smoke_suite' => ['fixture_context_load', 'skill_trigger_load', 'subagent_handoff', 'connector_permission_check', 'artifact_stub_generation', 'audit_receipt_export'],
                            'promotion_gates' => ['all_smoke_checks_green', 'policy_findings_zero', 'operator_review_packet_ready', 'rollback_plan_bound', 'evidence_sink_bound'],
                            'rollback_plan' => ['disable_plugin_entrypoint', 'fallback_to_manual_handoff', 'restore_previous_template_hash', 'record_rollback_receipt'],
                            'evidence_sink' => 'evidence_ledger.domain_workload_agent_package',
                            'auto_promotion_allowed' => false,
                            'external_execution_enabled_after_rollout' => false,
                            'rollout_hash' => hash('sha256', $domainId.'|'.$flowId.'|agent_rollout_plan'),
                        ],
                        'execution_surface_bindings' => [
                            'cli_action' => 'atlas:ai:autonomous-holding --action=enterprise-flow-run-queue-execute --company='.$domainId.' --flow='.$flowId,
                            'mcp_tool' => 'atlas_holding_'.$domainId.'_'.$flowId.'_readonly_shadow',
                            'desktop_panel' => $domainId.'_company_command_center.'.$flowId,
                            'run_queue_contract' => 'enterprise_flow_run_queue_item.v1',
                            'operator_review_surface' => (string) $blueprint['review_queue'],
                            'external_execution_allowed' => false,
                            'surface_binding_hash' => hash('sha256', $domainId.'|'.$flowId.'|execution_surface_bindings'),
                        ],
                        'fixture_smoke_contract' => [
                            'scenario_id' => $domainId.'.'.$flowId.'.fixture_smoke.v1',
                            'required_fixture_inputs' => ['company_context', 'flow_contract', 'source_snapshot', 'connector_scope', 'expected_artifact_schema'],
                            'replay_steps' => ['load_template', 'load_triggered_skills', 'hydrate_fixture_context', 'validate_connector_scope', 'handoff_to_subagents', 'produce_artifact_stub', 'export_receipt'],
                            'expected_artifacts' => ['tool_plan', 'typed_artifact_stub', 'policy_gate_report', 'handoff_packet'],
                            'pass_criteria' => ['all_required_inputs_present', 'all_tool_permissions_read_or_fixture', 'artifact_schema_valid', 'policy_findings_zero', 'receipt_hash_present'],
                            'policy_boundary_checks' => ['no_external_write', 'no_real_spend', 'no_trade', 'no_public_publish', 'no_deploy_or_delete', 'no_secret_export'],
                            'external_effects_allowed_during_smoke' => false,
                            'fixture_hash' => hash('sha256', $domainId.'|'.$flowId.'|fixture_smoke_contract'),
                        ],
                        'package_hash' => hash('sha256', $domainId.'|'.$flowId.'|agent_distribution_package'),
                    ],
                    'template_hash' => hash('sha256', $domainId.'|workload_agent_template|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_template_coverage_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'template_id' => $domainId.'.'.$flowId.'.workload_agent_template.v1',
                    'required_components' => ['skills', 'connectors', 'subagents', 'runtime_contract', 'approval_contract', 'audit_log'],
                    'coverage_ready' => true,
                    'external_execution_enabled' => false,
                    'coverage_hash' => hash('sha256', $domainId.'|workload_template_coverage|'.$flowId),
                ],
                $flowIds,
            )),
            'template_observability' => [
                'required_metrics' => ['template_coverage_rate', 'skill_load_success_rate', 'connector_scope_pass_rate', 'subagent_handoff_quality', 'audit_log_completeness', 'operator_review_coverage', 'external_effect_block_rate'],
                'dashboard' => $domainId.'_workload_agent_template_board',
                'alert_on' => ['missing_template', 'missing_skill', 'missing_connector_scope', 'subagent_context_overreach', 'audit_gap', 'operator_review_missing', 'external_effect_requested'],
            ],
            'workload_template_stack_hash' => hash('sha256', $domainId.'|domain_workload_agent_templates|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<string>
     */
    private function domainWorkloadSkills(string $domainId, string $flowId): array
    {
        $base = ['scope_intake', 'source_grounding', 'tool_plan', 'typed_artifact_authoring', 'methodology_check', 'policy_review', 'handoff_packet'];
        $domain = match ($domainId) {
            'finance' => ['financial_model_audit', 'valuation_methodology', 'kyc_or_compliance_screening', 'investment_committee_memo'],
            'marketing' => ['audience_segmentation', 'campaign_strategy', 'creative_briefing', 'attribution_analysis'],
            'cyber' => ['defensive_security_triage', 'control_mapping', 'risk_register_update', 'remediation_plan'],
            'software' => ['repo_context_loading', 'code_patch_planning', 'test_repair_loop', 'release_risk_review'],
            'research' => ['citation_graph_building', 'claim_verification', 'contradiction_register', 'synthesis_briefing'],
            'strategy' => ['market_mapping', 'competitive_intelligence', 'experiment_design', 'board_decision_packet'],
            'automation' => ['tool_selection', 'workflow_replay', 'mcp_adapter_design', 'reliability_review'],
            'personal_development' => ['private_context_minimization', 'learning_plan_design', 'habit_review', 'privacy_guard_review'],
            default => ['incident_triage', 'runbook_execution', 'capacity_review', 'postmortem_action_tracking'],
        };

        return array_values(array_unique(array_merge($base, $domain, [$flowId.'_procedure'])));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function domainWorkloadSubagents(string $domainId, string $flowId): array
    {
        $domainReviewer = match ($domainId) {
            'finance' => 'valuation_or_compliance_reviewer_subagent',
            'marketing' => 'growth_and_brand_reviewer_subagent',
            'cyber' => 'defensive_security_reviewer_subagent',
            'software' => 'code_quality_and_test_reviewer_subagent',
            'research' => 'source_faithfulness_reviewer_subagent',
            'strategy' => 'strategy_assumption_reviewer_subagent',
            'automation' => 'tool_reliability_reviewer_subagent',
            'personal_development' => 'privacy_and_learning_reviewer_subagent',
            default => 'operations_reliability_reviewer_subagent',
        };

        return [
            [
                'subagent_id' => $flowId.'.source_grounding_subagent',
                'purpose' => 'select_and_verify_sources_before_artifact_work',
                'context_scope' => ['flow_objective', 'source_catalog_refs', 'connector_schema_snapshots'],
                'tools' => ['source_registry', 'read_only_connector_probe', 'lineage_builder'],
                'external_effects_allowed' => false,
            ],
            [
                'subagent_id' => $flowId.'.'.$domainReviewer,
                'purpose' => 'check_domain_methodology_risk_and_quality',
                'context_scope' => ['draft_artifact', 'methodology_notes', 'risk_policy_profile'],
                'tools' => ['critic_review', 'policy_gate', 'quality_scorecard'],
                'external_effects_allowed' => false,
            ],
            [
                'subagent_id' => $flowId.'.handoff_and_audit_subagent',
                'purpose' => 'assemble_receipts_handoff_packet_and_operator_review_queue',
                'context_scope' => ['artifact_hash', 'tool_receipts', 'decision_receipt', 'acceptance_criteria'],
                'tools' => ['receipt_verifier', 'handoff_packet_builder', 'operator_review_queue'],
                'external_effects_allowed' => false,
            ],
        ];
    }

    private function domainWorkloadFamily(string $domainId): string
    {
        return match ($domainId) {
            'finance' => 'financial_services_research_modeling_compliance_and_close',
            'marketing' => 'growth_campaign_lifecycle_brand_and_attribution',
            'cyber' => 'defensive_security_posture_detection_compliance_and_response',
            'software' => 'software_engineering_code_intelligence_repair_and_release',
            'research' => 'source_grounded_research_synthesis_and_claim_verification',
            'strategy' => 'market_strategy_portfolio_experiment_and_board_decision',
            'automation' => 'tool_automation_mcp_adapter_and_reliability',
            'personal_development' => 'private_learning_habit_reflection_and_goal_operations',
            default => 'enterprise_operations_readiness_incident_and_continuity',
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseExternalResearchAdoptionStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->externalResearchSourceBasis($domainId);
        $sourceIds = array_column($sources, 'source_id');
        $repositoryCatalog = $this->externalResearchRepositoryCatalog($domainId);
        $templates = $this->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_external_research_adoption_stack.v1',
            'company_id' => $domainId,
            'research_policy' => [
                'objective' => 'turn_external_agent_framework_financial_services_and_domain_tooling_research_into_local_enterprise_contracts',
                'calendar_wait_blocker_enabled' => false,
                'external_research_is_architecture_input_only' => true,
                'runtime_ingestion_without_source_review_allowed' => false,
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => false,
                'external_side_effects_default' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_basis' => $sources,
            'repository_and_framework_catalog' => $repositoryCatalog,
            'domain_agent_operating_blueprint' => [
                'pattern' => 'skills_connectors_subagents_receipts_and_policy_gates_per_domain',
                'finance_inspiration' => $domainId === 'finance'
                    ? 'claude_financial_services_style_pitch_model_market_research_valuation_accounting_audit_and_kyc_agents'
                    : 'claude_financial_services_pattern_generalized_to_'.$domainId,
                'required_components' => ['skills', 'connectors', 'subagents', 'tool_receipts', 'source_lineage', 'critic_review', 'operator_checkpoint'],
                'component_hash' => hash('sha256', $domainId.'|external_research_agent_operating_blueprint|'.implode('|', $sourceIds)),
            ],
            'per_flow_adoption_matrix' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'source_refs' => array_values(array_slice($sourceIds, 0, min(8, count($sourceIds)))),
                    'repository_refs' => array_values(array_slice(
                        array_column($repositoryCatalog['official_framework_repositories'], 'repository_url'),
                        0,
                        5,
                    )),
                    'domain_repository_refs' => array_values(array_column($repositoryCatalog['domain_repository_candidates'], 'repository_or_doc_url')),
                    'agent_template_refs' => array_values(array_slice($templateIds, $index % max(1, count($templateIds)), min(3, count($templateIds)))),
                    'connector_candidates' => array_values((array) $spec[1]),
                    'skills' => ['source_review', 'framework_selection', 'domain_tool_mapping', 'fixture_replay_design', 'risk_and_policy_review'],
                    'subagent_roles' => ['domain_methodology_reviewer', 'tool_security_reviewer', 'quality_critic_agent'],
                    'adoption_gates' => ['source_review_green', 'license_review_green', 'security_review_green', 'local_fixture_eval_green', 'operator_acceptance_green'],
                    'blocked_until_gate_green' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_security'],
                    'external_side_effects_enabled' => false,
                    'matrix_hash' => hash('sha256', $domainId.'|external_research_adoption|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'source_to_company_capability_map' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'source_id' => (string) $source['source_id'],
                    'capability' => (string) ($source['capability'] ?? $source['adopted_pattern'] ?? $source['pattern'] ?? 'enterprise_agentic_capability'),
                    'local_contract_target' => 'company_flow_runtime_contract_'.$index,
                    'review_artifacts_required' => ['source_summary', 'allowed_use_review', 'risk_review', 'local_test_result', 'operator_decision'],
                    'adoption_state' => 'contract_candidate_until_local_evidence_green',
                    'external_side_effects_enabled' => false,
                    'capability_hash' => hash('sha256', 'source_capability_map|'.(string) $source['source_id']),
                ],
                $sources,
                array_keys($sources),
            )),
            'connector_and_data_provider_backlog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'activation_model' => 'read_only_probe_fixture_mock_then_operator_mandate_for_any_mutation',
                    'required_artifacts' => ['tool_schema_snapshot', 'permission_scope_report', 'sample_fixture', 'receipt_hash', 'fallback_plan'],
                    'candidate_adapter_forms' => ['mcp_server', 'openapi_adapter', 'read_only_export_import', 'local_fixture_adapter'],
                    'external_side_effects_enabled' => false,
                    'backlog_hash' => hash('sha256', 'external_research_connector_backlog|'.$connector),
                ],
                $connectors,
            )),
            'productionization_gates' => [
                'contract_ready' => ['source_basis_reviewed', 'repository_catalog_reviewed', 'flow_adoption_matrix_complete'],
                'fixture_ready' => ['local_fixture_eval_green', 'tool_receipts_present', 'source_lineage_present'],
                'shadow_ready' => ['read_only_connector_probe_green', 'policy_findings_zero', 'rollback_plan_present'],
                'supervised_ready' => ['operator_signed_mandate', 'second_reviewer_for_risky_external_action', 'incident_route_bound'],
                'autonomy_claim_ready' => ['current_operating_packet_green', 'observed_external_results_reviewed', 'zero_unreviewed_policy_exceptions'],
            ],
            'research_observability' => [
                'required_metrics' => ['source_review_coverage', 'repo_review_coverage', 'flow_adoption_matrix_coverage', 'fixture_eval_pass_rate', 'security_review_pass_rate', 'operator_acceptance_rate'],
                'dashboard' => $domainId.'_external_research_adoption_board',
                'alert_on' => ['unreviewed_source_used', 'repo_license_unknown', 'security_review_missing', 'external_tool_used_without_operator_mandate'],
            ],
            'research_adoption_hash' => hash('sha256', $domainId.'|external_research_adoption|'.implode('|', $sourceIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function externalResearchSourceBasis(string $domainId): array
    {
        $sources = array_values(array_merge(
            $this->flowRuntimeImplementationSourceCatalog(),
            $this->agentFrameworkSourceCatalog($domainId),
            $this->domainSolutionSourceCatalog($domainId),
            $this->premiumReferenceSourceBasis($domainId),
        ));
        $seen = [];

        return array_values(array_filter(array_map(
            static function (array $source) use (&$seen): ?array {
                $sourceId = (string) ($source['source_id'] ?? 'unknown_source');
                if (isset($seen[$sourceId])) {
                    return null;
                }
                $seen[$sourceId] = true;

                return [
                    'source_id' => $sourceId,
                    'url' => (string) ($source['url'] ?? ''),
                    'capability' => (string) ($source['capability'] ?? $source['pattern'] ?? $source['adopted_pattern'] ?? $source['use'] ?? 'enterprise_agentic_pattern'),
                    'review_state' => 'architecture_reviewed_local_runtime_adoption_requires_tests',
                    'source_links_required' => true,
                    'external_side_effects_default' => false,
                    'adoption_boundary' => 'reference_to_contract_only_until_license_security_fixture_and_operator_review',
                    'source_hash' => hash('sha256', 'external_research_source_basis|'.$sourceId),
                ];
            },
            $sources,
        )));
    }

    /**
     * @return array<string,mixed>
     */
    private function externalResearchRepositoryCatalog(string $domainId): array
    {
        $domainRepositories = match ($domainId) {
            'software' => [
                ['source_id' => 'tree_sitter', 'repository_or_doc_url' => 'https://github.com/tree-sitter/tree-sitter', 'use' => 'incremental_ast_parsing_for_code_intelligence'],
                ['source_id' => 'semgrep', 'repository_or_doc_url' => 'https://github.com/semgrep/semgrep', 'use' => 'static_analysis_and_security_rule_benchmarking'],
                ['source_id' => 'opentelemetry_collector', 'repository_or_doc_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'use' => 'runtime_telemetry_pipeline_reference'],
            ],
            'research' => [
                ['source_id' => 'semantic_scholar_api', 'repository_or_doc_url' => 'https://www.semanticscholar.org/product/api', 'use' => 'citation_graph_and_source_quality'],
                ['source_id' => 'openalex_docs', 'repository_or_doc_url' => 'https://docs.openalex.org/', 'use' => 'scholarly_graph_reference'],
                ['source_id' => 'grobid', 'repository_or_doc_url' => 'https://github.com/kermitt2/grobid', 'use' => 'paper_pdf_structure_extraction_candidate'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'repository_or_doc_url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_strategy_inputs'],
                ['source_id' => 'world_bank_api', 'repository_or_doc_url' => 'https://datahelpdesk.worldbank.org/knowledgebase/topics/125589-developer-information', 'use' => 'macro_market_data'],
                ['source_id' => 'fred_api', 'repository_or_doc_url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'economic_series_for_scenario_models'],
            ],
            'finance' => [
                ['source_id' => 'openbb', 'repository_or_doc_url' => 'https://github.com/OpenBB-finance/OpenBB', 'use' => 'financial_research_terminal_reference'],
                ['source_id' => 'sec_edgar_apis', 'repository_or_doc_url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'filings_and_disclosure_data'],
                ['source_id' => 'anthropic_financial_services', 'repository_or_doc_url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'skills_connectors_subagents_financial_agent_pattern'],
            ],
            'marketing' => [
                ['source_id' => 'google_ads_api', 'repository_or_doc_url' => 'https://developers.google.com/google-ads/api/docs/campaigns', 'use' => 'campaign_context_and_reporting'],
                ['source_id' => 'hubspot_crm_api', 'repository_or_doc_url' => 'https://developers.hubspot.com/docs/api/crm/understanding-the-crm', 'use' => 'crm_lifecycle_context'],
                ['source_id' => 'posthog', 'repository_or_doc_url' => 'https://github.com/PostHog/posthog', 'use' => 'product_analytics_and_experiment_reference'],
            ],
            'cyber' => [
                ['source_id' => 'mitre_attack', 'repository_or_doc_url' => 'https://attack.mitre.org/', 'use' => 'threat_modeling_and_detection_mapping'],
                ['source_id' => 'osquery', 'repository_or_doc_url' => 'https://github.com/osquery/osquery', 'use' => 'endpoint_state_query_reference'],
                ['source_id' => 'opencti', 'repository_or_doc_url' => 'https://github.com/OpenCTI-Platform/opencti', 'use' => 'threat_intelligence_graph_reference'],
            ],
            'automation' => [
                ['source_id' => 'model_context_protocol_servers', 'repository_or_doc_url' => 'https://github.com/modelcontextprotocol/servers', 'use' => 'mcp_server_catalog_reference'],
                ['source_id' => 'playwright', 'repository_or_doc_url' => 'https://github.com/microsoft/playwright', 'use' => 'browser_automation_reference'],
                ['source_id' => 'n8n', 'repository_or_doc_url' => 'https://github.com/n8n-io/n8n', 'use' => 'workflow_automation_reference'],
            ],
            'personal_development' => [
                ['source_id' => 'xapi_spec', 'repository_or_doc_url' => 'https://github.com/adlnet/xAPI-Spec', 'use' => 'learning_experience_records'],
                ['source_id' => 'open_badges', 'repository_or_doc_url' => 'https://www.imsglobal.org/spec/ob/v3p0/', 'use' => 'skill_credential_record_reference'],
                ['source_id' => 'caldav', 'repository_or_doc_url' => 'https://www.rfc-editor.org/rfc/rfc4791', 'use' => 'calendar_context_reference'],
            ],
            default => [
                ['source_id' => 'opentelemetry_collector', 'repository_or_doc_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'use' => 'observability_pipeline_reference'],
                ['source_id' => 'prometheus', 'repository_or_doc_url' => 'https://github.com/prometheus/prometheus', 'use' => 'metrics_alerting_reference'],
                ['source_id' => 'grafana', 'repository_or_doc_url' => 'https://github.com/grafana/grafana', 'use' => 'dashboard_operations_reference'],
            ],
        };

        return [
            'official_framework_repositories' => [
                ['source_id' => 'openai_agents_python', 'repository_url' => 'https://github.com/openai/openai-agents-python', 'capability' => 'agents_tools_handoffs_guardrails_tracing'],
                ['source_id' => 'crewai', 'repository_url' => 'https://github.com/crewAIInc/crewAI', 'capability' => 'crew_and_flow_orchestration'],
                ['source_id' => 'microsoft_agent_framework', 'repository_url' => 'https://github.com/microsoft/agent-framework', 'capability' => 'production_grade_agents_multi_agent_workflows_durability_observability_governance_human_in_loop'],
                ['source_id' => 'microsoft_autogen', 'repository_url' => 'https://github.com/microsoft/autogen', 'capability' => 'multi_agent_conversation_and_enterprise_framework_lineage'],
                ['source_id' => 'langgraph', 'repository_url' => 'https://github.com/langchain-ai/langgraph', 'capability' => 'durable_graph_execution_human_interrupts'],
                ['source_id' => 'temporal', 'repository_url' => 'https://github.com/temporalio/sdk-php', 'capability' => 'durable_workflow_replay_and_failure_recovery_candidate'],
                ['source_id' => 'model_context_protocol_servers', 'repository_url' => 'https://github.com/modelcontextprotocol/servers', 'capability' => 'mcp_tool_server_patterns'],
                ['source_id' => 'opentelemetry_collector', 'repository_url' => 'https://github.com/open-telemetry/opentelemetry-collector', 'capability' => 'agent_runtime_trace_metric_log_collection_and_export_reference'],
            ],
            'domain_repository_candidates' => array_values(array_map(
                static fn (array $repo): array => [
                    ...$repo,
                    'adoption_requires' => ['license_review', 'security_review', 'local_fixture_eval', 'operator_acceptance'],
                    'external_side_effects_enabled' => false,
                    'repository_hash' => hash('sha256', 'domain_repository_candidate|'.(string) $repo['source_id']),
                ],
                $domainRepositories,
            )),
            'repository_watch_policy' => [
                'review_cadence' => 'weekly_or_before_adoption',
                'pin_version_before_runtime_use' => true,
                'security_review_required' => true,
                'license_review_required' => true,
                'runtime_adoption_requires_local_contract_tests' => true,
            ],
            'repository_catalog_hash' => hash('sha256', $domainId.'|external_research_repository_catalog|'.implode('|', array_column($domainRepositories, 'source_id'))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseAgentRepositoryAdoptionPipeline(string $domainId, array $blueprint): array
    {
        $repositoryCatalog = $this->externalResearchRepositoryCatalog($domainId);
        $frameworks = array_values((array) ($repositoryCatalog['official_framework_repositories'] ?? []));
        $domainRepositories = array_values((array) ($repositoryCatalog['domain_repository_candidates'] ?? []));
        $repositories = array_values(array_merge($frameworks, $domainRepositories));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_agent_repository_adoption_pipeline.v1',
            'company_id' => $domainId,
            'pipeline_policy' => [
                'purpose' => 'turn_agent_framework_and_domain_repository_research_into_versioned_enterprise_adoption_work',
                'calendar_wait_blocker_enabled' => false,
                'adoption_requires_license_security_sbo_m_fixture_eval_and_operator_acceptance' => true,
                'maintenance_mode_or_deprecation_requires_migration_plan' => true,
                'runtime_use_before_local_contract_tests_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_write_or_procurement' => true,
            ],
            'repository_intake_queue' => array_values(array_map(
                static fn (array $repo, int $index): array => [
                    'schema' => 'atlas.ai.company.repository_intake_item.v1',
                    'repository_id' => (string) ($repo['source_id'] ?? 'repo_'.$index),
                    'url' => (string) ($repo['repository_url'] ?? $repo['repository_or_doc_url'] ?? ''),
                    'capability_or_use' => (string) ($repo['capability'] ?? $repo['use'] ?? 'enterprise_agentic_runtime_candidate'),
                    'source_kind' => isset($repo['repository_url']) ? 'agent_framework_repository' : 'domain_repository_or_api_docs',
                    'intake_status' => 'candidate_requires_review',
                    'required_reviews' => ['license', 'security', 'maintenance_status', 'runtime_boundary', 'data_boundary', 'operator_fit'],
                    'required_artifacts' => ['version_pin', 'sbom_or_dependency_snapshot', 'fixture_eval_result', 'rollback_plan', 'adoption_decision_receipt'],
                    'external_side_effects_enabled' => false,
                    'intake_hash' => hash('sha256', $domainId.'|repository_intake|'.(string) ($repo['source_id'] ?? 'repo_'.$index)),
                ],
                $repositories,
                array_keys($repositories),
            )),
            'framework_adoption_scorecards' => array_values(array_map(
                static fn (array $repo): array => [
                    'schema' => 'atlas.ai.company.framework_adoption_scorecard.v1',
                    'framework_id' => (string) ($repo['source_id'] ?? 'unknown_framework'),
                    'url' => (string) ($repo['repository_url'] ?? ''),
                    'fit_dimensions' => ['handoffs', 'tooling', 'guardrails', 'tracing', 'durable_resume', 'human_checkpoint', 'mcp_or_a2a', 'maintenance_posture'],
                    'risk_findings' => (string) ($repo['source_id'] ?? '') === 'microsoft_autogen'
                        ? ['maintenance_mode_detected_use_microsoft_agent_framework_migration_path_before_new_adoption']
                        : [],
                    'minimum_evidence_before_adoption' => ['sample_flow_trace', 'tool_receipt_export', 'fixture_eval_green', 'security_review_green', 'license_review_green'],
                    'adoption_state' => (string) ($repo['source_id'] ?? '') === 'microsoft_autogen'
                        ? 'migration_reference_only'
                        : 'candidate_for_contract_fixture',
                    'external_side_effects_enabled' => false,
                    'scorecard_hash' => hash('sha256', $domainId.'|framework_scorecard|'.(string) ($repo['source_id'] ?? 'unknown_framework')),
                ],
                $frameworks,
            )),
            'flow_repository_implementation_epics' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_repository_implementation_epic.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_framework_ref' => (string) ($frameworks[$index % max(1, count($frameworks))]['source_id'] ?? 'openai_agents_python'),
                    'domain_repository_refs' => array_values(array_slice(
                        array_column($domainRepositories, 'source_id'),
                        0,
                        min(3, count($domainRepositories)),
                    )),
                    'implementation_steps' => [
                        'pin_repository_versions',
                        'generate_adapter_contract',
                        'build_fixture_dataset',
                        'run_policy_and_security_review',
                        'execute_local_fixture_eval',
                        'wire_receipts_and_trace_export',
                        'document_rollback_or_migration_plan',
                    ],
                    'definition_of_done' => [
                        'version_pin_recorded',
                        'sbom_or_dependency_snapshot_present',
                        'fixture_eval_green',
                        'tool_receipts_complete',
                        'operator_checkpoint_supported',
                        'external_side_effects_false',
                    ],
                    'external_execution_allowed' => false,
                    'epic_hash' => hash('sha256', $domainId.'|flow_repository_epic|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'version_pin_and_supply_chain_plan' => array_values(array_map(
                static fn (array $repo, int $index): array => [
                    'repository_id' => (string) ($repo['source_id'] ?? 'repo_'.$index),
                    'pinning_mode' => 'explicit_version_or_commit_before_runtime',
                    'required_supply_chain_artifacts' => ['license_record', 'dependency_snapshot', 'security_review', 'known_vulnerability_check', 'upgrade_rollback_plan'],
                    'auto_upgrade_allowed' => false,
                    'production_runtime_allowed_before_pin' => false,
                    'pin_hash' => hash('sha256', 'repository_version_pin|'.(string) ($repo['source_id'] ?? 'repo_'.$index)),
                ],
                $repositories,
                array_keys($repositories),
            )),
            'migration_and_deprecation_matrix' => [
                'schema' => 'atlas.ai.company.repository_migration_and_deprecation_matrix.v1',
                'tracked_risks' => ['maintenance_mode', 'license_change', 'security_advisory', 'api_breaking_change', 'provider_lock_in', 'missing_trace_export'],
                'known_migration_paths' => [
                    [
                        'from' => 'microsoft_autogen',
                        'to' => 'microsoft_agent_framework',
                        'reason' => 'autogen_maintenance_mode_successor_framework_for_new_enterprise_adoption',
                        'migration_required_before_new_runtime_adoption' => true,
                    ],
                    [
                        'from' => 'framework_without_durable_resume',
                        'to' => 'langgraph_or_temporal_backed_runtime',
                        'reason' => 'long_running_company_flows_require_checkpoint_resume_and_idempotent_replay',
                        'migration_required_before_supervised_runtime' => true,
                    ],
                ],
                'matrix_hash' => hash('sha256', $domainId.'|repository_migration_deprecation_matrix'),
            ],
            'pipeline_observability' => [
                'required_metrics' => [
                    'repository_review_coverage',
                    'framework_scorecard_coverage',
                    'flow_epic_coverage',
                    'version_pin_coverage',
                    'fixture_eval_green_rate',
                    'security_review_green_rate',
                    'migration_risk_count',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_agent_repository_adoption_pipeline_board',
                'alert_on' => ['unreviewed_repo_used', 'missing_version_pin', 'security_review_missing', 'maintenance_mode_without_migration', 'runtime_used_before_fixture_green'],
            ],
            'pipeline_hash' => hash('sha256', $domainId.'|agent_repository_adoption_pipeline|'.implode('|', array_column($repositories, 'source_id')).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseWorkforceCapacityStack(string $domainId, array $blueprint): array
    {
        $agents = array_values((array) $blueprint['agent_roles']);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_workforce_capacity_stack.v1',
            'company_id' => $domainId,
            'org_model' => [
                'manager_agent' => (string) ($agents[0] ?? 'company_manager_agent'),
                'specialist_count' => max(0, count($agents) - 1),
                'independent_reviewer' => 'independent_reviewer_agent',
                'operator_escalation' => 'operator',
                'coverage_model' => 'manager_specialists_independent_review_operator_checkpoint',
            ],
            'agent_capacity_plan' => array_values(array_map(
                static fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'primary_capacity_units' => $index === 0 ? 4 : 3,
                    'review_capacity_units' => $agent === 'independent_reviewer_agent' ? 6 : 2,
                    'max_parallel_flows' => $index === 0 ? 3 : 2,
                    'requires_backup' => true,
                    'capacity_hash' => hash('sha256', 'agent_capacity|'.$agent),
                ],
                $agents,
                array_keys($agents),
            )),
            'flow_staffing_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'backup_agent' => 'independent_reviewer_agent',
                    'reviewer' => 'independent_reviewer_agent',
                    'operator_checkpoint_required' => true,
                    'minimum_staffing_state' => 'primary_backup_reviewer_defined',
                    'staffing_hash' => hash('sha256', 'flow_staffing|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'training_and_enablement' => array_values(array_map(
                static fn (string $agent): array => [
                    'agent_role' => $agent,
                    'required_training' => [
                        'policy_profile_handling',
                        'source_and_receipt_discipline',
                        'handoff_packet_quality',
                        'incident_and_escalation_protocol',
                    ],
                    'certification_required_before_shadow_mode' => true,
                    'recertification_cadence' => 'monthly_or_after_policy_change',
                    'training_hash' => hash('sha256', 'agent_training|'.$agent),
                ],
                $agents,
            )),
            'succession_and_continuity' => [
                'single_agent_bottleneck_allowed' => false,
                'manual_operator_fallback_required' => true,
                'backup_assignment_required_for_every_flow' => true,
                'continuity_artifacts' => ['runbook', 'handoff_packet', 'last_good_checkpoint', 'decision_log'],
            ],
            'capacity_observability' => [
                'required_metrics' => ['agent_utilization', 'review_queue_depth', 'flow_wip', 'handoff_wait_time', 'blocked_capacity_count'],
                'dashboard' => $domainId.'_workforce_capacity_board',
                'alert_on' => ['review_queue_over_limit', 'missing_backup_agent', 'flow_wip_over_limit'],
            ],
            'workforce_hash' => hash('sha256', $domainId.'|workforce_capacity|'.implode('|', $agents).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterprisePortfolioDependencyStack(string $domainId, array $manifest, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $allowedHandoffs = array_values((array) data_get($manifest, 'handoff_rules.allowed', []));
        $integrationContracts = array_values((array) ($manifest['integration_contracts'] ?? []));

        return [
            'schema' => 'atlas.ai.company.enterprise_portfolio_dependency_stack.v1',
            'company_id' => $domainId,
            'portfolio_role' => [
                'role_id' => $domainId.'.portfolio_role',
                'primary_manager' => (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                'portfolio_governor_relationship' => 'advisory_manager_with_operator_escalation',
                'decision_scope' => ['company_priorities', 'handoff_acceptance', 'resource_request', 'risk_escalation'],
                'blocked_scope' => ['external_spend', 'external_publish', 'production_write', 'trade_or_transfer'],
            ],
            'dependency_intake_contract' => [
                'accepted_channels' => ['portfolio_governor_assignment', 'cross_company_handoff', 'operator_request', 'scheduled_cadence'],
                'required_fields' => ['source_company', 'target_company', 'objective', 'context_hash', 'evidence_refs', 'acceptance_criteria', 'policy_profile'],
                'reject_when_missing' => ['typed_context', 'evidence_refs', 'policy_profile', 'target_acceptance_criteria'],
                'triage_method' => 'value_urgency_risk_dependency_blocker_score',
            ],
            'upstream_dependency_map' => array_values(array_map(
                static fn (string $target): array => [
                    'source_company' => $domainId,
                    'target_company' => $target,
                    'dependency_type' => 'allowed_handoff',
                    'required_packet' => 'atlas.ai.company_cross_handoff_execution.v1',
                    'acceptance_required' => true,
                    'dependency_hash' => hash('sha256', 'company_portfolio_dependency|'.$domainId.'|'.$target),
                ],
                $allowedHandoffs,
            )),
            'integration_dependency_map' => array_values(array_map(
                static fn (string $contract): array => [
                    'integration_contract' => $contract,
                    'owner_company' => $domainId,
                    'enablement_stage' => 'contract_ready',
                    'required_controls' => ['credential_vault', 'least_privilege', 'read_only_probe', 'receipt_capture', 'operator_mandate_before_write'],
                    'dependency_hash' => hash('sha256', 'company_integration_dependency|'.$domainId.'|'.$contract),
                ],
                $integrationContracts,
            )),
            'flow_dependency_routing' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'default_portfolio_priority' => $index % 3 === 0 ? 'strategic' : ($index % 3 === 1 ? 'managed' : 'standard'),
                    'requires_dependency_check_before_execution' => true,
                    'requires_portfolio_review_when' => ['cross_company_blocker', 'resource_conflict', 'policy_exception', 'external_action_request'],
                    'routing_hash' => hash('sha256', 'flow_dependency_routing|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'escalation_and_conflict_model' => [
                'level_1' => (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                'level_2' => 'independent_reviewer_agent',
                'level_3' => 'portfolio_governor',
                'level_4' => 'operator',
                'conflict_packet_required' => true,
                'decision_receipt_required' => true,
                'external_side_effects_blocked_until_resolved' => true,
            ],
            'portfolio_reporting_contract' => [
                'cadence' => 'weekly_portfolio_operating_review',
                'required_sections' => ['okr_delta', 'sla_delta', 'dependency_blockers', 'risk_delta', 'resource_request', 'next_commitments'],
                'required_metrics' => ['handoff_acceptance_rate', 'dependency_blocker_count', 'review_queue_depth', 'flow_wip'],
                'receipt_hash_required' => true,
            ],
            'dependency_hash' => hash('sha256', $domainId.'|portfolio_dependency_stack|'.implode('|', $allowedHandoffs).'|'.implode('|', $integrationContracts).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function domainDataModel(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $workProducts = array_values((array) $blueprint['work_products']);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.domain_data_model.v1',
            'company_id' => $domainId,
            'entities' => [
                [
                    'entity' => 'flow_run',
                    'primary_key' => 'flow_run_id',
                    'required_fields' => ['company_id', 'flow_id', 'input_hash', 'output_hash', 'status', 'receipt_hash'],
                ],
                [
                    'entity' => 'work_product',
                    'primary_key' => 'work_product_id',
                    'required_fields' => ['company_id', 'kind', 'artifact_hash', 'quality_status', 'evidence_refs'],
                ],
                [
                    'entity' => 'handoff_packet',
                    'primary_key' => 'handoff_hash',
                    'required_fields' => ['source_company', 'target_company', 'context_hash', 'expected_output', 'acceptance_status'],
                ],
                [
                    'entity' => 'metric_observation',
                    'primary_key' => 'metric_hash',
                    'required_fields' => ['company_id', 'metric_key', 'value', 'observed_at', 'source_receipt_hash'],
                ],
            ],
            'flow_ids' => $flowIds,
            'work_product_kinds' => $workProducts,
            'metric_keys' => $metrics,
            'retention_and_lineage' => [
                'source_refs_required' => true,
                'state_hash_required' => true,
                'lineage_links' => ['input_packet', 'tool_receipts', 'output_artifact', 'review_packet'],
            ],
            'data_model_hash' => hash('sha256', $domainId.'|domain_data_model|'.implode('|', $flowIds).'|'.implode('|', $workProducts)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function businessProcessMap(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $flowId, array $spec): array => [
                'schema' => 'atlas.ai.company.business_process_map.v1',
                'company_id' => $domainId,
                'process_id' => $domainId.'.process.'.$flowId,
                'flow_id' => $flowId,
                'trigger' => $flowId.'.request.received',
                'swimlanes' => [
                    'operator',
                    (string) $spec[0],
                    'independent_reviewer_agent',
                    'portfolio_governor',
                ],
                'states' => [
                    'intake',
                    'context_loaded',
                    'planned',
                    'analysis_complete',
                    'critic_reviewed',
                    'operator_checkpointed',
                    'published_internal',
                    'handoff_ready',
                ],
                'controls' => [
                    'idempotency_key_required',
                    'checkpoint_before_tool_use',
                    'receipt_after_tool_use',
                    'policy_gate_before_publish',
                    'operator_checkpoint_before_external_action',
                ],
                'outputs' => [(string) $spec[2], $flowId.'.review_packet', $flowId.'.handoff_packet'],
                'process_hash' => hash('sha256', $domainId.'|business_process|'.$flowId.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function deliverableQualityContracts(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $workProduct): array => [
                'schema' => 'atlas.ai.company.deliverable_quality_contract.v1',
                'company_id' => $domainId,
                'work_product' => $workProduct,
                'required_sections' => ['objective', 'method', 'evidence', 'assumptions', 'risks', 'decision_or_recommendation', 'next_actions'],
                'acceptance_criteria' => [
                    'source_refs_or_input_refs_present',
                    'risk_section_non_empty',
                    'policy_profile_attached',
                    'quality_gate_status_green_or_blocked',
                    'receipt_hash_attached',
                ],
                'rejection_criteria' => [
                    'unsupported_claim',
                    'missing_evidence',
                    'external_action_without_operator_mandate',
                    'stale_context_without_disclosure',
                ],
                'quality_score_floor' => 0.86,
                'contract_hash' => hash('sha256', $domainId.'|deliverable_quality|'.$workProduct),
            ],
            array_values((array) $blueprint['work_products']),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function goToProductionPack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.go_to_production_pack.v1',
            'company_id' => $domainId,
            'environment_model' => [
                'stages' => ['contract', 'sandbox', 'shadow', 'supervised_production'],
                'current_stage' => 'contract',
                'promotion_requires' => [
                    'green_eval_harness',
                    'integration_probe_green',
                    'operator_signed_side_effect_mandate',
                    'rollback_plan_present',
                ],
                'external_side_effects_default' => false,
            ],
            'observability' => [
                'required_signals' => ['trace', 'tool_receipt', 'cost', 'latency', 'quality_score', 'policy_findings', 'handoff_status'],
                'dashboards' => array_values((array) $blueprint['dashboards']),
                'alert_routes' => [(string) $blueprint['review_queue'], 'portfolio_governor_queue', 'operator_review_queue'],
                'trace_retention' => 'company_memory_scope_or_longer',
            ],
            'slo_sli_catalog' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'availability_sli' => 'routine_run_success_rate',
                    'quality_sli' => 'critic_score_and_policy_findings',
                    'freshness_sli' => 'latest_context_age',
                    'target' => [
                        'routine_success_rate' => 0.95,
                        'minimum_quality_score' => 0.86,
                        'policy_findings_allowed' => 0,
                    ],
                    'slo_hash' => hash('sha256', 'slo|'.$flowId),
                ],
                $flowIds,
            )),
            'incident_response' => [
                'severity_levels' => ['sev4_quality_warning', 'sev3_flow_blocked', 'sev2_policy_violation', 'sev1_external_side_effect_risk'],
                'first_responder' => (string) ($blueprint['agent_roles'][0] ?? 'company_manager_agent'),
                'escalation_chain' => ['independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'required_artifacts' => ['incident_packet', 'timeline', 'root_cause', 'rollback_or_compensation_plan', 'postmortem_actions'],
            ],
            'capacity_plan' => [
                'named_agents' => array_values((array) $blueprint['agent_roles']),
                'parallel_flow_limit' => min(4, max(2, count($flowIds))),
                'review_wip_limit' => 5,
                'human_checkpoint_capacity_required' => true,
            ],
            'integration_enablement_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'enablement_steps' => [
                        'bind_least_privilege_credentials',
                        'run_read_only_probe',
                        'record_receipt',
                        'attach_policy_profile',
                        'require_operator_mandate_before_write',
                    ],
                    'status' => 'contract_ready',
                    'external_side_effects_enabled' => false,
                    'enablement_hash' => hash('sha256', 'integration_enablement|'.$connector),
                ],
                $connectors,
            )),
            'production_readiness_hash' => hash('sha256', $domainId.'|go_to_production|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function commercialOperatingStack(string $domainId, array $blueprint): array
    {
        $workProducts = array_values((array) $blueprint['work_products']);
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.commercial_operating_stack.v1',
            'company_id' => $domainId,
            'service_catalog' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'service_id' => $workProduct,
                    'tier' => $index % 3 === 0 ? 'strategic' : ($index % 3 === 1 ? 'managed' : 'specialist'),
                    'primary_deliverable' => $workProduct,
                    'entry_flow' => (string) ($flowIds[$index % max(1, count($flowIds))] ?? 'operating_review'),
                    'acceptance_contract' => $workProduct.'.quality_contract',
                    'external_delivery_requires_operator_approval' => true,
                    'service_hash' => hash('sha256', $domainId.'|service_catalog|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'work_intake_model' => [
                'channels' => ['operator_request', 'portfolio_governor_assignment', 'cross_company_handoff', 'scheduled_cadence'],
                'required_intake_fields' => ['objective', 'business_context', 'scope', 'priority', 'evidence_refs', 'policy_profile'],
                'triage_method' => 'impact_urgency_risk_confidence',
                'reject_when_missing_policy_profile' => true,
            ],
            'pricing_and_cost_model' => [
                'mode' => 'internal_chargeback_until_external_commercial_approval',
                'unit_cost_drivers' => ['agent_runtime', 'tool_calls', 'review_time', 'integration_probe_count', 'quality_repair_cycles'],
                'margin_model' => 'notional_margin_for_prioritization_only',
                'external_billing_enabled' => false,
            ],
            'fulfillment_lifecycle' => [
                'stages' => ['intake', 'qualification', 'planning', 'execution', 'review', 'delivery', 'post_delivery_learning'],
                'quality_gate_before_delivery' => true,
                'handoff_packet_required_for_cross_company_delivery' => true,
                'receipt_required_for_delivery' => true,
            ],
            'business_kpis' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric' => $metric,
                    'business_use' => 'portfolio_prioritization_and_service_quality',
                    'target_direction' => str_contains($metric, 'block') || str_contains($metric, 'latency') ? 'decrease' : 'increase',
                    'evidence_source' => 'company_operating_packet.observed_metrics',
                    'kpi_hash' => hash('sha256', 'business_kpi|'.$metric),
                ],
                $metrics,
            )),
            'customer_success_model' => [
                'customer' => 'operator_and_atlas_portfolio',
                'success_review_cadence' => 'weekly_service_review',
                'feedback_artifacts' => ['delivery_acceptance', 'quality_exception', 'follow_up_request', 'learning_record'],
                'escalation_queue' => (string) $blueprint['review_queue'],
            ],
            'commercial_hash' => hash('sha256', $domainId.'|commercial_operating_stack|'.implode('|', $workProducts).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseCustomerMarketOperationsStack(string $domainId, array $blueprint): array
    {
        $workProducts = array_values((array) $blueprint['work_products']);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.enterprise_customer_market_operations_stack.v1',
            'company_id' => $domainId,
            'customer_and_stakeholder_model' => [
                'primary_customer' => $domainId === 'personal_development' ? 'operator_private_self_system' : 'operator_and_atlas_portfolio',
                'secondary_customers' => ['cross_company_consumers', 'portfolio_governor', 'independent_reviewer_agent'],
                'stakeholder_segments' => $this->customerSegments($domainId),
                'sensitive_or_regulated_segment_requires_policy_profile' => true,
                'customer_model_hash' => hash('sha256', $domainId.'|customer_stakeholder_model'),
            ],
            'market_positioning_system' => [
                'positioning_claim' => $this->positioningClaim($domainId),
                'proof_points_required' => ['source_refs', 'accepted_work_products', 'quality_scores', 'policy_findings_zero'],
                'competitive_alternatives' => ['manual_specialist_workflow', 'single_agent_generic_assistant', 'ungoverned_tool_chain'],
                'differentiators' => ['domain_specific_agents', 'typed_handoffs', 'receipt_hashes', 'operator_governed_external_actions'],
                'claim_review_required_before_external_use' => true,
            ],
            'offer_and_packaging_catalog' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'offer_id' => 'offer_'.$workProduct,
                    'work_product' => $workProduct,
                    'package_tier' => $index % 3 === 0 ? 'executive' : ($index % 3 === 1 ? 'managed' : 'specialist'),
                    'buyer_or_consumer' => $index % 2 === 0 ? 'operator' : 'cross_company_consumer',
                    'acceptance_artifacts' => ['delivery_packet', 'evidence_refs', 'quality_review', 'receipt_hash'],
                    'external_offer_publication_allowed' => false,
                    'offer_hash' => hash('sha256', 'customer_market_offer|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'journey_and_lifecycle_map' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'entry_moment' => $flowId.'.need_detected_or_requested',
                    'journey_stages' => ['discover_need', 'qualify_scope', 'produce', 'review', 'accept', 'measure_outcome', 'retain_or_expand'],
                    'owner_agent' => (string) $spec[0],
                    'success_moment' => (string) $spec[2].'.accepted_with_metric_signal',
                    'dropoff_risks' => ['unclear_scope', 'missing_evidence', 'policy_block', 'handoff_rejection'],
                    'journey_hash' => hash('sha256', 'customer_journey|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'voice_of_customer_and_feedback_loop' => [
                'feedback_sources' => ['operator_acceptance', 'target_company_acceptance', 'quality_exception', 'followup_request', 'observed_metric_delta'],
                'capture_contract' => 'atlas.ai.company_customer_feedback.v1',
                'triage_states' => ['new', 'clustered', 'action_selected', 'backlog_linked', 'closed_with_learning'],
                'required_links' => ['work_product_hash', 'flow_id', 'metric_key', 'decision_receipt_hash'],
                'writes_require_review' => true,
            ],
            'growth_and_retention_operating_model' => [
                'growth_mode' => 'internal_portfolio_expansion_until_external_commercial_approval',
                'allowed_actions' => ['recommend_next_service', 'prepare_case_study_draft', 'identify_cross_company_need', 'propose_expansion_packet'],
                'blocked_without_operator_approval' => ['external_publish', 'paid_acquisition', 'email_campaign_send', 'public_claim', 'external_billing'],
                'retention_cadence' => 'weekly_service_value_review',
                'expansion_gate' => ['accepted_delivery', 'source_refs_present', 'quality_score_green', 'operator_reviewed'],
            ],
            'customer_success_scorecard' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric' => $metric,
                    'customer_success_use' => 'value_realization_and_retention_signal',
                    'evidence_source' => 'company_operating_packet.observed_metrics_or_acceptance_records',
                    'target_direction' => str_contains($metric, 'risk') || str_contains($metric, 'block') || str_contains($metric, 'latency') ? 'decrease' : 'increase',
                    'scorecard_hash' => hash('sha256', 'customer_success_metric|'.$metric),
                ],
                $metrics,
            )),
            'market_operations_guardrails' => [
                'external_claims_require_source_refs' => true,
                'customer_visible_delivery_requires_acceptance_packet' => true,
                'regulated_or_sensitive_market_requires_redaction_review' => true,
                'external_side_effects_default' => false,
                'operator_approval_required_for_external_market_action' => true,
            ],
            'customer_market_hash' => hash('sha256', $domainId.'|customer_market_operations|'.implode('|', $workProducts).'|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @return list<string>
     */
    private function customerSegments(string $domainId): array
    {
        return match ($domainId) {
            'software' => ['product_operator', 'engineering_reviewer', 'release_owner', 'security_reviewer'],
            'research' => ['operator_decision_maker', 'strategy_consumer', 'finance_consumer', 'source_auditor'],
            'strategy' => ['portfolio_governor', 'venture_operator', 'finance_committee', 'gtm_owner'],
            'finance' => ['investment_committee', 'portfolio_governor', 'risk_reviewer', 'operator_capital_owner'],
            'marketing' => ['growth_operator', 'brand_reviewer', 'sales_or_crm_consumer', 'creative_approver'],
            'cyber' => ['security_owner', 'grc_reviewer', 'engineering_consumer', 'incident_commander'],
            'automation' => ['operations_owner', 'tooling_consumer', 'workflow_requester', 'risk_reviewer'],
            'personal_development' => ['operator_private_self_system', 'coach_reviewer', 'learning_consumer', 'privacy_guardian'],
            default => ['operator', 'portfolio_governor', 'service_owner', 'incident_reviewer'],
        };
    }

    private function positioningClaim(string $domainId): string
    {
        return match ($domainId) {
            'software' => 'governed_software_delivery_company_with_code_intelligence_repair_release_and_security_receipts',
            'research' => 'source_grounded_research_company_with_claim_attribution_contradiction_review_and_executive_synthesis',
            'strategy' => 'portfolio_strategy_company_for_thesis_market_maps_gtm_systems_and_experiment_allocation',
            'finance' => 'institutional_finance_company_for_research_valuation_risk_portfolio_and_compliance_without_live_trading',
            'marketing' => 'approval_gated_growth_and_brand_company_for_experiments_creative_lifecycle_and_analytics',
            'cyber' => 'defensive_security_company_for_posture_appsec_grc_detection_and_remediation_under_scope_controls',
            'automation' => 'governed_automation_company_for_tool_selection_browser_api_workflows_and_reliability_receipts',
            'personal_development' => 'privacy_first_personal_operating_company_for_goals_learning_habits_reflection_and_memory_review',
            default => 'operations_company_for_readiness_incident_runbooks_capacity_slo_and_postmortem_learning',
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseAccountContractDeliveryStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $workProducts = array_values((array) $blueprint['work_products']);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $metrics = array_values($companyMetrics);

        return [
            'schema' => 'atlas.ai.company.enterprise_account_contract_delivery_stack.v1',
            'company_id' => $domainId,
            'account_operations_policy' => [
                'mode' => 'internal_account_360_and_success_plan_until_external_customer_contract_approval',
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'auto_renewal_allowed' => false,
                'operator_approval_required_for_contract_billing_or_customer_visible_commitment' => true,
            ],
            'source_catalog' => $this->accountContractSourceCatalog(),
            'account_360_model' => [
                'account_record' => $domainId.'.account_360.v1',
                'required_fields' => ['account_id', 'stakeholder_owner', 'active_services', 'success_criteria', 'health_score', 'renewal_or_review_date', 'risk_flags'],
                'health_score_inputs' => ['delivery_acceptance', 'usage_or_consumption_signal', 'support_or_exception_volume', 'quality_score', 'relationship_or_operator_feedback'],
                'single_source_of_truth' => 'company_account_record_with_receipt_links',
                'raw_customer_data_export_allowed' => false,
            ],
            'account_segment_playbooks' => array_values(array_map(
                static fn (string $segment): array => [
                    'segment' => $segment,
                    'playbook_id' => 'account_playbook.'.$segment,
                    'entry_criteria' => ['active_need_or_service', 'named_owner', 'success_criteria_defined'],
                    'standard_actions' => ['confirm_success_plan', 'schedule_value_review', 'review_health_score', 'identify_risk_or_expansion_signal'],
                    'blocked_actions' => ['external_commitment', 'external_invoice', 'auto_renewal', 'public_case_study'],
                    'operator_checkpoint_required' => true,
                    'playbook_hash' => hash('sha256', 'account_segment_playbook|'.$segment),
                ],
                $this->customerSegments($domainId),
            )),
            'contract_and_entitlement_catalog' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'work_product' => $workProduct,
                    'entitlement_id' => 'entitlement.'.$workProduct,
                    'service_tier' => $index % 3 === 0 ? 'strategic' : ($index % 3 === 1 ? 'managed' : 'specialist'),
                    'included_outputs' => [$workProduct, $workProduct.'.review_packet', $workProduct.'.receipt_hash'],
                    'acceptance_criteria' => ['quality_floor_met', 'policy_findings_zero', 'receipt_hash_present', 'operator_or_target_acceptance'],
                    'contract_status' => 'internal_contract_template_ready',
                    'external_contract_signature_allowed' => false,
                    'entitlement_hash' => hash('sha256', 'contract_entitlement|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'onboarding_success_plans' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'success_plan_id' => 'success_plan.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'milestones' => ['scope_confirmed', 'data_or_context_connected', 'first_value_packet_delivered', 'acceptance_recorded', 'recurring_cadence_started'],
                    'required_artifacts' => ['intake_packet', 'success_criteria', 'risk_register_entry', 'delivery_receipt', 'feedback_record'],
                    'exit_criteria' => ['first_value_accepted', 'health_score_initialized', 'next_review_scheduled'],
                    'success_plan_hash' => hash('sha256', 'onboarding_success_plan|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'service_review_and_renewal_calendar' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'review_cadence' => $index % 2 === 0 ? 'weekly_value_review' : 'monthly_service_review',
                    'renewal_or_continuation_signal' => 'internal_service_continuation_review',
                    'required_review_sections' => ['delivered_value', 'quality_score', 'open_risks', 'next_commitments', 'commercial_or_capacity_note'],
                    'renewal_action_allowed' => false,
                    'operator_review_required' => true,
                    'calendar_hash' => hash('sha256', 'service_review_renewal|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'billing_and_revenue_operations_model' => [
                'mode' => 'notional_internal_chargeback_and_invoice_draft_only',
                'subscription_lifecycle_states' => ['draft', 'active_internal', 'paused_internal', 'review_due', 'closed_internal'],
                'billing_artifacts' => ['notional_invoice_draft', 'usage_summary', 'entitlement_snapshot', 'operator_approval_packet'],
                'external_payment_collection_allowed' => false,
                'tax_or_invoice_compliance_review_required_before_external_billing' => true,
            ],
            'account_health_and_risk_register' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric' => $metric,
                    'health_signal' => $metric.'.account_health_signal',
                    'risk_threshold' => str_contains($metric, 'block') || str_contains($metric, 'risk') ? 'above_baseline' : 'below_target',
                    'playbook_trigger' => 'open_account_risk_review',
                    'evidence_source' => 'delivery_acceptance_or_observed_metric_receipt',
                    'health_hash' => hash('sha256', 'account_health_risk|'.$metric),
                ],
                $metrics,
            )),
            'qbr_and_executive_reporting_pack' => [
                'cadence' => 'monthly_or_quarterly_depending_on_service_tier',
                'sections' => ['outcomes_delivered', 'health_score', 'risk_and_blockers', 'usage_or_flow_activity', 'next_value_plan', 'commercial_readiness'],
                'source_links_required' => true,
                'customer_visible_export_requires_operator_approval' => true,
                'report_hash' => hash('sha256', $domainId.'|qbr_executive_reporting_pack'),
            ],
            'account_contract_observability' => [
                'required_metrics' => ['account_health_score', 'success_plan_milestone_completion', 'renewal_review_due_count', 'entitlement_coverage', 'notional_revenue_or_chargeback', 'account_risk_count'],
                'dashboard' => $domainId.'_account_contract_delivery_board',
                'alert_on' => ['health_score_drop', 'renewal_review_due', 'entitlement_gap', 'external_commitment_request'],
            ],
            'account_contract_hash' => hash('sha256', $domainId.'|account_contract_delivery|'.implode('|', $workProducts).'|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseProductizedServiceStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values(array_unique(array_merge(
            array_values((array) $blueprint['work_products']),
            array_values(array_map(static fn (array $spec): string => (string) ($spec[2] ?? 'enterprise_artifact'), $flowSpecs)),
        )));
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $profile = $this->domainOperatingDepthProfile($domainId);
        $valueChain = array_values((array) $profile['value_chain']);
        $dataProducts = array_values((array) $profile['data_products']);

        return [
            'schema' => 'atlas.ai.company.enterprise_productized_service_stack.v1',
            'company_id' => $domainId,
            'product_policy' => [
                'mode' => 'productized_internal_enterprise_services_until_signed_external_scope',
                'calendar_wait_blocker_enabled' => false,
                'service_offer_required_for_every_flow' => true,
                'intake_qualification_sla_success_contract_required_for_every_flow' => true,
                'pricing_is_internal_chargeback_until_external_commercial_mandate' => true,
                'public_gtm_or_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'operator_mandate_required_for_public_offer_customer_commitment_or_billing' => true,
            ],
            'domain_product_lines' => array_values(array_map(
                static fn (string $stage, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_product_line.v1',
                    'product_line_id' => $stage.'_product_line',
                    'value_chain_stage' => $stage,
                    'primary_data_product' => (string) ($dataProducts[$index % max(1, count($dataProducts))] ?? $stage.'_data_product'),
                    'target_consumer' => $index % 2 === 0 ? 'operator_and_portfolio_governor' : 'cross_company_internal_customer',
                    'evidence_required' => ['source_lineage', 'accepted_artifact', 'quality_score', 'policy_gate', 'receipt_hash'],
                    'external_publication_allowed' => false,
                    'product_line_hash' => hash('sha256', 'domain_product_line|'.$stage),
                ],
                $valueChain,
                array_keys($valueChain),
            )),
            'flow_service_offers' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_productized_service_offer.v1',
                    'flow_id' => $flowId,
                    'offer_id' => 'service_offer.'.$flowId,
                    'name' => str_replace('_', ' ', $flowId).' service',
                    'owner_agent' => (string) $spec[0],
                    'primary_work_product' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'service_tier' => $index % 3 === 0 ? 'executive' : ($index % 3 === 1 ? 'managed' : 'specialist'),
                    'included_outputs' => [(string) ($spec[2] ?? 'enterprise_artifact'), $flowId.'.source_lineage_packet', $flowId.'.quality_review', $flowId.'.operator_handoff'],
                    'acceptance_criteria' => ['source_faithfulness_green', 'domain_correctness_green', 'policy_findings_zero', 'operator_acceptance_recorded'],
                    'external_customer_commitment_allowed' => false,
                    'offer_hash' => hash('sha256', $domainId.'|productized_service_offer|'.$flowId.'|'.(string) ($spec[2] ?? 'enterprise_artifact')),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'service_delivery_blueprints' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.productized_service_delivery_blueprint.v1',
                    'flow_id' => $flowId,
                    'delivery_nodes' => ['intake', 'qualification', 'source_pack', 'agent_crew_run', 'artifact_factory', 'quality_review', 'risk_review', 'operator_handoff', 'success_measurement', 'learning_update'],
                    'required_evidence' => ['intake_hash', 'qualification_hash', 'source_pack_hash', 'tool_receipt_hash', 'artifact_hash', 'review_hash', 'handoff_hash', 'success_metric_hash'],
                    'artifact_factory' => (string) ($spec[2] ?? 'enterprise_artifact').'.factory',
                    'escalation_paths' => ['policy_exception', 'missing_source', 'quality_repair', 'operator_rejection', 'external_scope_request'],
                    'delivery_hash' => hash('sha256', 'productized_service_delivery|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'intake_and_qualification_contracts' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.productized_service_intake_contract.v1',
                    'flow_id' => $flowId,
                    'required_fields' => ['objective', 'consumer', 'business_context', 'scope', 'source_refs', 'policy_profile', 'priority', 'acceptance_criteria'],
                    'qualification_rules' => ['scope_known', 'policy_profile_present', 'source_refs_or_manual_context_present', 'operator_or_internal_customer_identified'],
                    'reject_or_hold_reasons' => ['missing_policy_profile', 'ambiguous_scope', 'forbidden_external_action', 'insufficient_evidence_refs'],
                    'default_priority' => $index % 2 === 0 ? 'portfolio_critical' : 'standard_operating_priority',
                    'intake_hash' => hash('sha256', 'productized_service_intake|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'sla_success_contracts' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.productized_service_sla_success_contract.v1',
                    'flow_id' => $flowId,
                    'response_sla' => $index % 2 === 0 ? 'same_business_day_scope_packet' : 'next_business_day_scope_packet',
                    'delivery_sla' => $index % 2 === 0 ? 'same_business_day_internal_artifact' : 'two_business_day_internal_artifact',
                    'quality_floor' => 0.9,
                    'source_faithfulness_floor' => 0.95,
                    'success_metrics' => array_values(array_slice($companyMetrics, $index % max(1, count($companyMetrics)), min(4, count($companyMetrics)))),
                    'service_credit_or_external_commitment_allowed' => false,
                    'sla_hash' => hash('sha256', 'productized_service_sla|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'pricing_packaging_model' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.productized_service_pricing_package.v1',
                    'package_id' => 'package.'.$workProduct,
                    'work_product' => $workProduct,
                    'pricing_mode' => 'internal_chargeback_not_external_invoice',
                    'cost_drivers' => ['agent_runtime', 'tool_calls', 'connector_probe', 'review_time', 'repair_cycles'],
                    'tier' => $index % 3 === 0 ? 'strategic' : ($index % 3 === 1 ? 'managed' : 'specialist'),
                    'external_invoice_allowed' => false,
                    'package_hash' => hash('sha256', 'productized_service_pricing|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'go_to_market_motion_catalog' => [
                ['motion_id' => 'internal_portfolio_pull', 'allowed' => true, 'external_side_effects' => false],
                ['motion_id' => 'cross_company_handoff_expansion', 'allowed' => true, 'external_side_effects' => false],
                ['motion_id' => 'operator_reviewed_case_study_draft', 'allowed' => true, 'external_side_effects' => false],
                ['motion_id' => 'public_offer_launch', 'allowed' => false, 'external_side_effects' => true],
                ['motion_id' => 'paid_campaign_or_sales_sequence', 'allowed' => false, 'external_side_effects' => true],
            ],
            'proof_and_case_study_templates' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'template_id' => 'proof_packet.'.$flowId,
                    'required_sections' => ['problem', 'source_basis', 'delivered_artifact', 'quality_score', 'risk_controls', 'operator_acceptance', 'metric_signal'],
                    'external_publication_allowed' => false,
                    'template_hash' => hash('sha256', 'productized_service_proof_template|'.$flowId),
                ],
                $flowIds,
            )),
            'product_observability' => [
                'required_metrics' => ['service_offer_coverage', 'intake_contract_coverage', 'qualification_pass_rate', 'sla_attainment_rate', 'artifact_acceptance_rate', 'quality_repair_cycle_count', 'operator_handoff_acceptance_rate', 'internal_chargeback_cost_per_artifact', 'proof_packet_coverage', 'external_commitment_block_rate'],
                'dashboard' => $domainId.'_productized_service_board',
                'alert_on' => ['missing_service_offer', 'missing_intake_contract', 'sla_breach', 'quality_repair_loop', 'operator_rejection', 'external_commitment_requested'],
            ],
            'productized_service_hash' => hash('sha256', $domainId.'|productized_service|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseSalesCrmPipelineStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values((array) $blueprint['work_products']);
        $segments = $this->customerSegments($domainId);
        $sourceCatalog = $this->salesCrmSourceCatalog($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_sales_crm_pipeline_stack.v1',
            'company_id' => $domainId,
            'sales_policy' => [
                'mode' => 'internal_revenue_pipeline_and_proposal_ops_until_signed_external_commercial_scope',
                'calendar_wait_blocker_enabled' => false,
                'crm_opportunity_proposal_and_handoff_required_for_every_flow' => true,
                'external_outreach_contract_signature_or_customer_commitment_allowed' => false,
                'public_claim_or_paid_campaign_allowed' => false,
                'operator_mandate_required_for_external_sales_message_contract_or_commitment' => true,
            ],
            'source_catalog' => $sourceCatalog,
            'crm_object_model' => [
                'objects' => ['account', 'contact', 'opportunity', 'service_offer', 'proposal', 'mutual_action_plan', 'renewal_expansion_signal', 'commercial_risk'],
                'required_links' => ['flow_id', 'service_offer_id', 'account_record', 'success_criteria', 'proposal_receipt_hash', 'operator_approval_packet'],
                'single_source_of_truth' => 'atlas_company_crm_record_with_receipt_links',
                'raw_external_contact_export_allowed' => false,
                'crm_model_hash' => hash('sha256', $domainId.'|sales_crm_object_model'),
            ],
            'segment_sales_plays' => array_values(array_map(
                static fn (string $segment): array => [
                    'schema' => 'atlas.ai.company.segment_sales_play.v1',
                    'segment' => $segment,
                    'play_id' => 'sales_play.'.$segment,
                    'entry_criteria' => ['known_need', 'stakeholder_owner', 'service_offer_match', 'success_metric_defined'],
                    'discovery_questions' => ['business_outcome', 'current_workflow', 'evidence_available', 'approval_path', 'risk_or_policy_constraints'],
                    'blocked_actions' => ['unsupervised_external_outreach', 'contract_signature', 'public_claim', 'discount_commitment'],
                    'play_hash' => hash('sha256', 'segment_sales_play|'.$segment),
                ],
                $segments,
            )),
            'flow_opportunity_routes' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_opportunity_route.v1',
                    'flow_id' => $flowId,
                    'opportunity_id' => 'opportunity.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'service_offer_id' => 'service_offer.'.$flowId,
                    'target_segment' => $index % 2 === 0 ? 'operator_and_portfolio_governor' : 'cross_company_internal_customer',
                    'stage_sequence' => ['identified', 'qualified', 'discovery_complete', 'proposal_drafted', 'operator_reviewed', 'internal_acceptance', 'delivery_handoff'],
                    'exit_criteria' => ['accepted_scope', 'success_metrics_defined', 'risk_review_green', 'handoff_packet_created'],
                    'external_commitment_allowed' => false,
                    'route_hash' => hash('sha256', 'flow_opportunity_route|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'proposal_and_scope_packets' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.proposal_scope_packet.v1',
                    'flow_id' => $flowId,
                    'proposal_id' => 'proposal.'.$flowId,
                    'primary_work_product' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'required_sections' => ['problem', 'scope', 'deliverables', 'source_basis', 'success_metrics', 'risks', 'commercial_terms_draft', 'operator_approval'],
                    'commercial_terms_status' => 'internal_draft_only',
                    'signature_allowed' => false,
                    'proposal_hash' => hash('sha256', 'proposal_scope_packet|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'mutual_action_plans' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.mutual_action_plan.v1',
                    'flow_id' => $flowId,
                    'map_id' => 'map.'.$flowId,
                    'milestones' => ['scope_confirmed', 'source_access_ready', 'proposal_reviewed', 'risk_review_complete', 'delivery_window_reserved', 'acceptance_packet_signed_internal'],
                    'owner_split' => ['atlas_internal_owner', 'operator_or_internal_customer_owner', 'risk_reviewer'],
                    'target_cadence' => $index % 2 === 0 ? 'weekly_pipeline_review' : 'biweekly_pipeline_review',
                    'external_customer_binding_allowed' => false,
                    'map_hash' => hash('sha256', 'mutual_action_plan|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'flow_account_research_workbenches' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.sales_account_research_workbench.v1',
                    'flow_id' => $flowId,
                    'workbench_id' => 'sales_research.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'research_inputs' => ['account_record', 'service_offer', 'known_pain', 'stakeholder_map', 'source_refs', 'support_history', 'usage_or_value_signal'],
                    'required_outputs' => ['account_brief', 'fit_score', 'stakeholder_questions', 'risk_notes', 'next_best_action', 'evidence_links'],
                    'external_enrichment_allowed' => false,
                    'workbench_hash' => hash('sha256', 'sales_account_research_workbench|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_deal_room_packets' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.sales_deal_room_packet.v1',
                    'flow_id' => $flowId,
                    'deal_room_id' => 'deal_room.'.$flowId,
                    'required_tabs' => ['opportunity_summary', 'decision_criteria', 'stakeholders', 'proposal_scope', 'risk_register', 'commercial_terms_draft', 'delivery_handoff', 'operator_review'],
                    'approval_gates' => ['source_backed', 'risk_review_green', 'scope_accepted_internal', 'no_external_commitment', 'operator_checkpoint_present'],
                    'external_customer_room_allowed' => false,
                    'deal_room_hash' => hash('sha256', 'sales_deal_room|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_pipeline_forecast_reviews' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.sales_pipeline_forecast_review.v1',
                    'flow_id' => $flowId,
                    'forecast_id' => 'forecast.'.$flowId,
                    'forecast_dimensions' => ['stage', 'deal_quality', 'risk_adjusted_value', 'delivery_capacity', 'decision_timeline', 'operator_review_status'],
                    'cadence' => $index % 2 === 0 ? 'weekly' : 'biweekly',
                    'required_evidence' => ['crm_stage_history', 'proposal_packet', 'mutual_action_plan', 'delivery_capacity_signal', 'risk_review'],
                    'external_revenue_commitment_allowed' => false,
                    'forecast_hash' => hash('sha256', 'sales_pipeline_forecast|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'flow_mutual_action_plan_risk_reviews' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.sales_map_risk_review.v1',
                    'flow_id' => $flowId,
                    'review_id' => 'map_risk.'.$flowId,
                    'risk_checks' => ['scope_creep', 'missing_decision_owner', 'unpriced_work', 'delivery_capacity_gap', 'policy_exception', 'external_commitment_pressure'],
                    'repair_actions' => ['clarify_scope', 'route_operator_review', 'update_proposal', 'split_delivery_phase', 'block_external_commitment'],
                    'operator_review_required' => true,
                    'risk_review_hash' => hash('sha256', 'sales_map_risk_review|'.$flowId),
                ],
                $flowIds,
            )),
            'renewal_and_expansion_signals' => array_values(array_map(
                static fn (string $metric, int $index): array => [
                    'schema' => 'atlas.ai.company.renewal_expansion_signal.v1',
                    'signal_id' => 'renewal_signal.'.$metric,
                    'metric' => $metric,
                    'trigger_condition' => str_contains($metric, 'risk') || str_contains($metric, 'block') ? 'risk_reduced_or_blocker_removed' : 'value_signal_above_baseline',
                    'recommended_action' => $index % 2 === 0 ? 'prepare_expansion_packet' : 'prepare_renewal_review_packet',
                    'requires_operator_review' => true,
                    'external_renewal_commitment_allowed' => false,
                    'signal_hash' => hash('sha256', 'renewal_expansion_signal|'.$metric),
                ],
                $companyMetrics,
                array_keys($companyMetrics),
            )),
            'sales_to_delivery_handoff_contracts' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.sales_delivery_handoff_contract.v1',
                    'flow_id' => $flowId,
                    'required_artifacts' => ['qualified_opportunity', 'proposal_scope_packet', 'mutual_action_plan', 'risk_review', 'acceptance_criteria', 'operator_approval_packet'],
                    'delivery_acceptance_gate' => ['scope_clear', 'source_refs_present', 'policy_profile_present', 'success_metric_defined', 'external_commitment_absent'],
                    'handoff_rejection_reasons' => ['ambiguous_scope', 'missing_success_metric', 'missing_source_basis', 'external_commitment_request'],
                    'handoff_hash' => hash('sha256', 'sales_delivery_handoff|'.$flowId),
                ],
                $flowIds,
            )),
            'pipeline_observability' => [
                'required_metrics' => ['qualified_pipeline_count', 'proposal_draft_coverage', 'map_completion_rate', 'sales_to_delivery_handoff_acceptance_rate', 'commercial_risk_count', 'operator_review_queue_age', 'renewal_expansion_signal_count', 'external_commitment_block_rate', 'crm_record_completeness', 'pipeline_forecast_coverage', 'account_research_completeness', 'deal_room_readiness_rate', 'map_risk_review_completion'],
                'dashboard' => $domainId.'_sales_crm_pipeline_board',
                'alert_on' => ['stale_opportunity', 'missing_proposal', 'handoff_rejection', 'external_commitment_requested', 'public_claim_requested', 'pipeline_forecast_gap'],
            ],
            'sales_crm_pipeline_hash' => hash('sha256', $domainId.'|sales_crm_pipeline|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $companyMetrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function salesCrmSourceCatalog(string $domainId): array
    {
        $sources = [
            ['source_id' => 'salesforce_agentforce_platform', 'url' => 'https://www.salesforce.com/agentforce/', 'pattern' => 'crm_native_agents_connected_to_customer_data_actions_and_approval_flows'],
            ['source_id' => 'hubspot_sales_hub', 'url' => 'https://www.hubspot.com/products/sales', 'pattern' => 'pipeline_deal_management_sequences_proposals_and_sales_automation'],
            ['source_id' => 'gainsight_customer_success_scorecards', 'url' => 'https://www.gainsight.com/customer-success-scorecards/', 'pattern' => 'customer_health_renewal_expansion_and_success_scorecards'],
            ['source_id' => 'stripe_billing_features', 'url' => 'https://stripe.com/billing/features', 'pattern' => 'quote_invoice_entitlement_and_billing_handoff_controls'],
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://openai.github.io/openai-agents-python/agents/', 'pattern' => 'agent_handoffs_guardrails_tools_sessions_and_tracing_for_sales_workflows'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'crm_schema_mapping', 'sandbox_probe', 'operator_mandate_for_external_sales_use'],
                'source_hash' => hash('sha256', 'sales_crm_source|'.$domainId.'|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseCustomerSupportServiceDeskStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values((array) $blueprint['work_products']);
        $segments = $this->customerSegments($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_customer_support_service_desk_stack.v1',
            'company_id' => $domainId,
            'support_policy' => [
                'mode' => 'internal_customer_support_service_desk_until_signed_external_support_scope',
                'calendar_wait_blocker_enabled' => false,
                'ticket_sla_escalation_kb_and_feedback_required_for_every_flow' => true,
                'external_customer_message_or_support_commitment_allowed' => false,
                'regulated_support_advice_allowed_without_review' => false,
                'operator_mandate_required_for_external_customer_support_or_public_kb' => true,
            ],
            'source_catalog' => $this->supportServiceDeskSourceCatalog($domainId),
            'service_desk_object_model' => [
                'objects' => ['ticket', 'incident', 'problem', 'request', 'knowledge_article', 'sla_policy', 'escalation', 'customer_feedback', 'rca_record'],
                'required_links' => ['flow_id', 'account_record', 'service_offer_id', 'support_ticket_id', 'runtime_receipt_hash', 'resolution_artifact_hash', 'feedback_record'],
                'single_source_of_truth' => 'atlas_company_service_desk_record_with_receipt_links',
                'raw_external_customer_message_export_allowed' => false,
                'object_model_hash' => hash('sha256', $domainId.'|support_service_desk_object_model'),
            ],
            'support_segment_playbooks' => array_values(array_map(
                static fn (string $segment): array => [
                    'schema' => 'atlas.ai.company.support_segment_playbook.v1',
                    'segment' => $segment,
                    'playbook_id' => 'support_playbook.'.$segment,
                    'triage_questions' => ['impact', 'urgency', 'affected_service', 'expected_outcome', 'evidence_or_error_context'],
                    'standard_responses' => ['acknowledge_internal_ticket', 'request_missing_context', 'route_to_owner_agent', 'prepare_resolution_packet'],
                    'blocked_without_review' => ['external_customer_advice', 'refund_or_credit_commitment', 'legal_or_regulated_claim', 'public_kb_publish'],
                    'playbook_hash' => hash('sha256', 'support_segment_playbook|'.$segment),
                ],
                $segments,
            )),
            'flow_support_lanes' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_support_lane.v1',
                    'flow_id' => $flowId,
                    'lane_id' => 'support_lane.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'service_offer_id' => 'service_offer.'.$flowId,
                    'ticket_types' => ['how_to_request', 'delivery_exception', 'quality_repair', 'source_dispute', 'policy_or_scope_question', 'integration_issue'],
                    'priority_model' => $index % 2 === 0 ? 'impact_urgency_matrix' : 'standard_service_priority',
                    'external_customer_response_allowed' => false,
                    'lane_hash' => hash('sha256', 'flow_support_lane|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'ticket_triage_and_sla_contracts' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.ticket_triage_sla_contract.v1',
                    'flow_id' => $flowId,
                    'required_fields' => ['requester', 'account_or_internal_customer', 'impact', 'urgency', 'affected_flow', 'evidence_refs', 'desired_resolution', 'policy_profile'],
                    'sla_targets' => [
                        'acknowledgement' => $index % 2 === 0 ? 'same_business_day' : 'next_business_day',
                        'first_resolution_packet' => $index % 2 === 0 ? 'one_business_day' : 'two_business_days',
                        'escalation_review' => 'same_business_day_after_sla_risk',
                    ],
                    'auto_external_response_allowed' => false,
                    'sla_hash' => hash('sha256', 'ticket_triage_sla|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'knowledge_base_article_templates' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.support_kb_article_template.v1',
                    'flow_id' => $flowId,
                    'template_id' => 'kb.'.$flowId,
                    'primary_work_product' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'required_sections' => ['symptom_or_request', 'scope', 'source_basis', 'resolution_steps', 'known_limits', 'policy_notes', 'escalation_path', 'feedback_link'],
                    'public_publish_allowed' => false,
                    'template_hash' => hash('sha256', 'support_kb_template|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'escalation_and_incident_runbooks' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.support_escalation_incident_runbook.v1',
                    'flow_id' => $flowId,
                    'runbook_id' => 'support_incident.'.$flowId,
                    'escalation_levels' => ['owner_agent', 'domain_manager', 'risk_reviewer', 'operator_checkpoint'],
                    'incident_steps' => ['classify', 'contain_internal', 'collect_evidence', 'route_owner', 'prepare_resolution', 'review', 'close_with_learning'],
                    'external_notification_allowed' => false,
                    'runbook_hash' => hash('sha256', 'support_escalation_incident|'.$flowId),
                ],
                $flowIds,
            )),
            'resolution_quality_and_rca_contracts' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.support_resolution_rca_contract.v1',
                    'flow_id' => $flowId,
                    'resolution_evidence' => ['ticket_hash', 'source_refs', 'runtime_receipt', 'artifact_patch_or_answer', 'quality_review', 'acceptance_or_feedback'],
                    'rca_required_for' => ['repeat_ticket', 'sla_breach', 'policy_exception', 'delivery_rejection', 'customer_visible_risk'],
                    'closure_criteria' => ['resolution_packet_present', 'quality_green_or_repair_opened', 'feedback_recorded', 'learning_item_created'],
                    'auto_close_external_ticket_allowed' => false,
                    'rca_hash' => hash('sha256', 'support_resolution_rca|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_case_resolution_workbenches' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.support_case_resolution_workbench.v1',
                    'flow_id' => $flowId,
                    'workbench_id' => 'support_resolution_workbench.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'case_inputs' => ['ticket', 'account_context', 'service_offer', 'runtime_receipts', 'known_errors', 'policy_profile', 'kb_candidates'],
                    'resolution_outputs' => ['answer_or_fix_packet', 'confidence_notes', 'source_refs', 'handoff_or_escalation', 'kb_update_candidate', 'customer_safe_summary'],
                    'external_customer_response_allowed' => false,
                    'workbench_hash' => hash('sha256', 'support_case_resolution_workbench|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_customer_health_escalation_playbooks' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.support_customer_health_escalation_playbook.v1',
                    'flow_id' => $flowId,
                    'playbook_id' => 'support_health_escalation.'.$flowId,
                    'health_signals' => ['repeat_ticket', 'sla_risk', 'blocked_value_realization', 'integration_failure', 'policy_exception', 'negative_feedback'],
                    'escalation_actions' => ['route_account_owner', 'prepare_recovery_packet', 'open_problem_record', 'update_success_plan', 'operator_checkpoint'],
                    'external_service_commitment_allowed' => false,
                    'playbook_hash' => hash('sha256', 'support_health_escalation|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_knowledge_quality_reviews' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.support_knowledge_quality_review.v1',
                    'flow_id' => $flowId,
                    'review_id' => 'kb_quality.'.$flowId,
                    'quality_checks' => ['source_faithfulness', 'resolution_accuracy', 'policy_fit', 'readability', 'known_limits', 'freshness', 'escalation_path'],
                    'minimum_quality_score' => 0.9,
                    'public_publish_allowed' => false,
                    'review_hash' => hash('sha256', 'support_kb_quality|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_support_automation_deflection_tests' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.support_automation_deflection_test.v1',
                    'flow_id' => $flowId,
                    'test_id' => 'support_deflection.'.$flowId,
                    'test_cases' => ['known_question', 'ambiguous_request', 'policy_sensitive_case', 'integration_error', 'repeat_incident', 'handoff_required'],
                    'success_criteria' => ['correct_route', 'source_refs_present', 'no_regulated_advice', 'handoff_when_low_confidence', 'no_external_side_effect'],
                    'auto_deflect_external_ticket_allowed' => false,
                    'test_hash' => hash('sha256', 'support_deflection_test|'.$flowId),
                ],
                $flowIds,
            )),
            'feedback_to_product_learning_loops' => array_values(array_map(
                static fn (string $metric): array => [
                    'schema' => 'atlas.ai.company.support_feedback_learning_loop.v1',
                    'metric' => $metric,
                    'signal_id' => 'support_feedback.'.$metric,
                    'loop_steps' => ['cluster_feedback', 'link_to_flow', 'identify_root_cause', 'create_backlog_item', 'update_kb_or_playbook', 'verify_metric_delta'],
                    'evidence_source' => 'support_ticket_resolution_or_customer_success_feedback',
                    'loop_hash' => hash('sha256', 'support_feedback_learning|'.$metric),
                ],
                $companyMetrics,
            )),
            'support_observability' => [
                'required_metrics' => ['ticket_volume', 'sla_attainment_rate', 'first_resolution_packet_time', 'reopen_rate', 'escalation_rate', 'kb_article_coverage', 'rca_completion_rate', 'feedback_to_backlog_rate', 'external_support_request_block_rate', 'customer_health_risk_ticket_count', 'case_resolution_workbench_coverage', 'kb_quality_review_rate', 'automation_deflection_test_pass_rate'],
                'dashboard' => $domainId.'_customer_support_service_desk_board',
                'alert_on' => ['sla_breach', 'repeat_ticket', 'policy_exception_request', 'external_customer_response_requested', 'public_kb_publish_requested', 'support_backlog_growth'],
            ],
            'support_service_desk_hash' => hash('sha256', $domainId.'|support_service_desk|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $companyMetrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function supportServiceDeskSourceCatalog(string $domainId): array
    {
        $sources = [
            ['source_id' => 'zendesk_ai_agents', 'url' => 'https://www.zendesk.com/service/ai/', 'pattern' => 'support_ai_agents_ticket_triage_knowledge_and_customer_service_workflows'],
            ['source_id' => 'intercom_fin_ai_agent', 'url' => 'https://www.intercom.com/fin', 'pattern' => 'ai_customer_support_resolution_knowledge_and_handoff'],
            ['source_id' => 'servicenow_ai_agents', 'url' => 'https://www.servicenow.com/products/ai-agents.html', 'pattern' => 'enterprise_service_management_ai_agents_workflows_and_incident_operations'],
            ['source_id' => 'atlassian_service_management', 'url' => 'https://www.atlassian.com/software/jira/service-management', 'pattern' => 'service_desk_incident_request_problem_change_and_knowledge_workflows'],
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://openai.github.io/openai-agents-python/agents/', 'pattern' => 'agent_handoffs_guardrails_tools_sessions_and_tracing_for_support_workflows'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'support_schema_mapping', 'sandbox_probe', 'operator_mandate_for_external_support_use'],
                'source_hash' => hash('sha256', 'support_service_desk_source|'.$domainId.'|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseMarketingGrowthEngineStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values((array) $blueprint['work_products']);
        $segments = $this->customerSegments($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_marketing_growth_engine_stack.v1',
            'company_id' => $domainId,
            'marketing_policy' => [
                'mode' => 'internal_growth_engine_and_campaign_ops_until_signed_external_marketing_scope',
                'calendar_wait_blocker_enabled' => false,
                'campaign_content_experiment_channel_and_brand_review_required_for_every_flow' => true,
                'external_publish_paid_campaign_or_outreach_allowed' => false,
                'public_claim_allowed_without_source_and_operator_review' => false,
                'operator_mandate_required_for_external_publish_paid_campaign_or_outreach' => true,
            ],
            'source_catalog' => $this->marketingGrowthSourceCatalog($domainId),
            'growth_operating_model' => [
                'operating_roles' => ['growth_strategist_agent', 'content_agent', 'creative_agent', 'lifecycle_agent', 'analytics_agent', 'brand_compliance_reviewer_agent'],
                'growth_loops' => ['problem_signal_to_content', 'service_offer_to_campaign', 'case_study_to_pipeline', 'support_feedback_to_education', 'product_usage_to_expansion'],
                'required_controls' => ['source_claim_review', 'brand_review', 'policy_review', 'utm_and_attribution', 'crm_handoff', 'external_publish_block'],
                'model_hash' => hash('sha256', $domainId.'|marketing_growth_operating_model'),
            ],
            'audience_segment_map' => array_values(array_map(
                static fn (string $segment): array => [
                    'schema' => 'atlas.ai.company.audience_segment_map.v1',
                    'segment' => $segment,
                    'audience_id' => 'audience.'.$segment,
                    'jobs_to_be_done' => ['understand_problem', 'trust_evidence', 'evaluate_service_fit', 'request_internal_scope'],
                    'message_constraints' => ['no_unverified_claims', 'no_external_commitment', 'source_refs_required', 'sensitive_context_redaction'],
                    'external_targeting_allowed' => false,
                    'audience_hash' => hash('sha256', 'audience_segment|'.$segment),
                ],
                $segments,
            )),
            'flow_campaign_blueprints' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_campaign_blueprint.v1',
                    'flow_id' => $flowId,
                    'campaign_id' => 'campaign.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'service_offer_id' => 'service_offer.'.$flowId,
                    'campaign_goal' => $index % 2 === 0 ? 'internal_demand_generation' : 'customer_education_and_expansion',
                    'channels' => ['internal_portfolio_digest', 'operator_brief', 'case_study_draft', 'crm_handoff_note', 'knowledge_base_article'],
                    'external_launch_allowed' => false,
                    'campaign_hash' => hash('sha256', 'flow_campaign_blueprint|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'content_asset_factories' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.content_asset_factory.v1',
                    'flow_id' => $flowId,
                    'factory_id' => 'content_factory.'.$flowId,
                    'primary_work_product' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'asset_types' => ['executive_brief', 'case_study_draft', 'comparison_memo', 'how_it_works_note', 'sales_enablement_card', 'support_education_article'],
                    'required_evidence' => ['source_refs', 'accepted_artifact', 'quality_score', 'policy_review', 'operator_review'],
                    'public_publish_allowed' => false,
                    'factory_hash' => hash('sha256', 'content_asset_factory|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'experiment_backlog' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.growth_experiment.v1',
                    'flow_id' => $flowId,
                    'experiment_id' => 'growth_experiment.'.$flowId,
                    'hypothesis' => $flowId.'.message_and_offer_fit_improves_internal_pipeline_quality',
                    'variants' => ['problem_first', 'proof_first', 'workflow_first'],
                    'success_metrics' => ['qualified_internal_interest', 'handoff_acceptance_rate', 'content_acceptance', 'support_ticket_deflection'],
                    'minimum_reviewers' => $index % 2 === 0 ? 2 : 1,
                    'external_traffic_allowed' => false,
                    'experiment_hash' => hash('sha256', 'growth_experiment|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'flow_growth_intelligence_workbenches' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.marketing_growth_intelligence_workbench.v1',
                    'flow_id' => $flowId,
                    'workbench_id' => 'growth_intel.'.$flowId,
                    'owner_agent' => (string) $spec[0],
                    'signals' => ['search_intent', 'crm_stage_feedback', 'support_themes', 'competitor_positioning', 'content_performance', 'source_backed_proof'],
                    'outputs' => ['audience_insight', 'message_angle', 'offer_fit_notes', 'claim_evidence_map', 'experiment_recommendation', 'sales_handoff_context'],
                    'external_scrape_or_publish_allowed' => false,
                    'workbench_hash' => hash('sha256', 'growth_intelligence_workbench|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_attribution_experiment_models' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.marketing_attribution_experiment_model.v1',
                    'flow_id' => $flowId,
                    'model_id' => 'attribution.'.$flowId,
                    'touchpoints' => ['internal_digest', 'operator_brief', 'case_study_draft', 'crm_note', 'support_article', 'service_review'],
                    'measurement_plan' => ['utm_contract', 'crm_source_field', 'handoff_acceptance', 'pipeline_influence', 'support_deflection_signal'],
                    'minimum_sample_review_required' => true,
                    'external_tracking_pixel_allowed' => false,
                    'model_hash' => hash('sha256', 'marketing_attribution_experiment|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_channel_budget_guardrails' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.marketing_channel_budget_guardrail.v1',
                    'flow_id' => $flowId,
                    'guardrail_id' => 'channel_budget.'.$flowId,
                    'allowed_spend_modes' => ['zero_spend_internal', 'operator_simulated_budget', 'approved_purchase_order_only'],
                    'blocked_spend_actions' => ['paid_ads_launch', 'influencer_payment', 'sponsored_content', 'external_email_send', 'public_campaign_boost'],
                    'approval_requirements' => ['budget_owner', 'brand_reviewer', 'policy_reviewer', 'operator_checkpoint'],
                    'external_spend_allowed' => false,
                    'guardrail_hash' => hash('sha256', 'marketing_channel_budget_guardrail|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_public_claim_evidence_packets' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.marketing_public_claim_evidence_packet.v1',
                    'flow_id' => $flowId,
                    'packet_id' => 'claim_evidence.'.$flowId,
                    'required_evidence' => ['source_link', 'accepted_artifact', 'metric_definition', 'claim_scope', 'counterexample_check', 'legal_or_brand_review', 'operator_approval'],
                    'blocked_claim_types' => ['guaranteed_roi', 'unverified_benchmark', 'competitor_disparagement', 'regulated_outcome', 'customer_name_without_permission'],
                    'public_claim_allowed' => false,
                    'packet_hash' => hash('sha256', 'marketing_public_claim_evidence|'.$flowId),
                ],
                $flowIds,
            )),
            'channel_and_distribution_plan' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.channel_distribution_plan.v1',
                    'flow_id' => $flowId,
                    'distribution_id' => 'distribution.'.$flowId,
                    'allowed_internal_channels' => ['operator_dashboard', 'portfolio_digest', 'crm_note', 'support_kb_internal', 'board_review_packet'],
                    'blocked_external_channels' => ['public_social_post', 'paid_ads', 'cold_email_sequence', 'public_blog_publish', 'press_release'],
                    'handoff_targets' => ['sales_crm_pipeline', 'customer_support_service_desk', 'productized_service_catalog'],
                    'distribution_hash' => hash('sha256', 'channel_distribution|'.$flowId),
                ],
                $flowIds,
            )),
            'brand_compliance_review_packets' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.brand_compliance_review_packet.v1',
                    'flow_id' => $flowId,
                    'review_id' => 'brand_review.'.$flowId,
                    'required_checks' => ['claim_source_backing', 'tone_and_brand_fit', 'sensitive_context_redaction', 'competitive_claim_review', 'external_commitment_absent', 'policy_profile_match'],
                    'approval_states' => ['draft', 'needs_revision', 'approved_internal_only', 'blocked_external'],
                    'auto_approve_external_publish_allowed' => false,
                    'review_hash' => hash('sha256', 'brand_compliance_review|'.$flowId),
                ],
                $flowIds,
            )),
            'growth_to_crm_handoff_contracts' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.growth_crm_handoff_contract.v1',
                    'flow_id' => $flowId,
                    'required_artifacts' => ['campaign_blueprint', 'content_asset', 'experiment_result_or_hypothesis', 'audience_segment', 'brand_review', 'crm_next_action'],
                    'handoff_gate' => ['source_backed', 'brand_reviewed', 'policy_green', 'no_external_commitment', 'crm_owner_assigned'],
                    'external_lead_handoff_allowed' => false,
                    'handoff_hash' => hash('sha256', 'growth_crm_handoff|'.$flowId),
                ],
                $flowIds,
            )),
            'marketing_observability' => [
                'required_metrics' => ['campaign_blueprint_coverage', 'content_asset_coverage', 'experiment_backlog_coverage', 'brand_review_completion_rate', 'crm_handoff_acceptance_rate', 'source_backed_claim_rate', 'external_publish_block_count', 'content_to_support_deflection_signal', 'case_study_draft_count', 'qualified_pipeline_influence', 'growth_intelligence_workbench_coverage', 'attribution_model_coverage', 'channel_budget_guardrail_coverage', 'public_claim_evidence_packet_coverage'],
                'dashboard' => $domainId.'_marketing_growth_engine_board',
                'alert_on' => ['missing_brand_review', 'unverified_claim', 'external_publish_requested', 'paid_campaign_requested', 'crm_handoff_rejected', 'stale_experiment'],
            ],
            'marketing_growth_engine_hash' => hash('sha256', $domainId.'|marketing_growth_engine|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $companyMetrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function marketingGrowthSourceCatalog(string $domainId): array
    {
        $sources = [
            ['source_id' => 'hubspot_marketing_hub', 'url' => 'https://www.hubspot.com/products/marketing', 'pattern' => 'campaign_content_lifecycle_automation_and_marketing_analytics'],
            ['source_id' => 'salesforce_marketing_cloud', 'url' => 'https://www.salesforce.com/marketing/', 'pattern' => 'enterprise_marketing_customer_journeys_segmentation_and_activation'],
            ['source_id' => 'amplitude_experiment', 'url' => 'https://amplitude.com/experiment', 'pattern' => 'growth_experimentation_feature_flags_and_behavioral_analytics'],
            ['source_id' => 'segment_customer_data_platform', 'url' => 'https://segment.com/', 'pattern' => 'customer_data_activation_audience_segments_and_attribution'],
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://openai.github.io/openai-agents-python/agents/', 'pattern' => 'agent_handoffs_guardrails_tools_sessions_and_tracing_for_marketing_workflows'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'brand_policy_mapping', 'sandbox_probe', 'operator_mandate_for_external_marketing_use'],
                'source_hash' => hash('sha256', 'marketing_growth_source|'.$domainId.'|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseFinanceTreasuryBillingStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values((array) $blueprint['work_products']);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sourceCatalog = $this->financeTreasurySourceCatalog($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_finance_treasury_billing_stack.v1',
            'company_id' => $domainId,
            'finance_policy' => [
                'mode' => 'internal_cfo_operating_system_until_signed_external_finance_mandate',
                'calendar_wait_blocker_enabled' => false,
                'budget_forecast_pnl_and_billing_packet_required_for_every_flow' => true,
                'source_linked_financial_claim_required' => true,
                'model_risk_review_required_for_investment_or_capital_recommendation' => true,
                'external_invoice_payment_collection_capital_transfer_or_trade_allowed' => false,
                'real_revenue_cash_or_aum_claim_allowed' => false,
                'operator_mandate_required_for_external_billing_capital_vendor_spend_or_trade' => true,
            ],
            'source_catalog' => $sourceCatalog,
            'financial_data_interface' => [
                'schema' => 'atlas.ai.company.financial_data_interface.v1',
                'unified_sources' => array_column($sourceCatalog, 'source_id'),
                'provider_connector_classes' => ['market_data', 'fundamentals_and_kpi', 'filings_and_transcripts', 'private_market_intelligence', 'secure_document_room', 'warehouse_and_lakehouse', 'billing_and_erp'],
                'source_verification_contract' => [
                    'direct_source_link_required' => true,
                    'cross_source_reconciliation_required' => true,
                    'claim_without_source_link_allowed' => false,
                    'stale_market_data_warning_required' => true,
                    'client_confidentiality_default' => 'not_used_for_model_training_and_not_exported_without_scope',
                ],
                'data_interface_hash' => hash('sha256', $domainId.'|financial_data_interface|'.implode('|', array_column($sourceCatalog, 'source_id'))),
            ],
            'provider_connector_matrix' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'schema' => 'atlas.ai.company.finance_provider_connector_slot.v1',
                    'connector_slot_id' => 'finance.provider_connector.'.$source['source_id'],
                    'source_id' => (string) $source['source_id'],
                    'capability_class' => ['market_data', 'documents', 'analytics', 'compliance', 'warehouse', 'billing'][$index % 6],
                    'auth_mode' => 'operator_bound_oauth_or_service_account_vault_scope',
                    'sandbox_probe_required' => true,
                    'direct_source_link_required' => true,
                    'write_or_trade_scope_allowed' => false,
                    'connector_hash' => hash('sha256', 'finance_provider_connector|'.$domainId.'|'.$source['source_id']),
                ],
                $sourceCatalog,
                array_keys($sourceCatalog),
            )),
            'cfo_operating_model' => [
                'operating_roles' => ['cfo_agent', 'controller_agent', 'fpna_agent', 'billing_ops_agent', 'treasury_risk_agent', 'audit_reviewer_agent', 'investment_analyst_agent', 'compliance_obligations_agent', 'model_risk_reviewer_agent'],
                'cadences' => ['daily_cash_and_spend_review', 'weekly_margin_capacity_review', 'monthly_close_packet', 'quarterly_budget_reforecast'],
                'required_controls' => ['segregation_of_duties', 'approval_thresholds', 'receipt_linkage', 'budget_variance_explanation', 'source_linked_claim_review', 'model_risk_signoff', 'compliance_obligation_mapping', 'no_external_money_movement_without_mandate'],
                'model_hash' => hash('sha256', $domainId.'|cfo_operating_model'),
            ],
            'flow_financial_research_workbenches' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_financial_research_workbench.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'source_panes' => ['market_feed', 'filings', 'fundamentals_kpi', 'transcripts', 'internal_metrics', 'secure_docs'],
                    'verification_steps' => ['retrieve_source', 'cross_check_source', 'attach_direct_link', 'quote_or_metric_lineage', 'analyst_review'],
                    'output_artifacts' => ['source_linked_research_note', 'comps_table', 'risk_flags', 'assumption_register'],
                    'claim_without_source_allowed' => false,
                    'workbench_hash' => hash('sha256', 'flow_financial_research_workbench|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_budget_envelopes' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_budget_envelope.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'budget_unit' => 'uUSD_internal_notional',
                    'monthly_internal_budget_cap' => 150000 + ($index * 25000),
                    'approval_thresholds' => ['review_over_50_percent', 'operator_checkpoint_over_80_percent', 'block_over_100_percent'],
                    'spend_categories' => ['model_runtime', 'tool_calls', 'connector_probes', 'review_capacity', 'repair_cycles'],
                    'external_spend_allowed' => false,
                    'budget_hash' => hash('sha256', 'flow_budget_envelope|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_forecast_models' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_finance_forecast_model.v1',
                    'flow_id' => $flowId,
                    'forecast_horizon' => '13_week_rolling_and_12_month_plan',
                    'drivers' => ['request_volume', 'agent_minutes', 'connector_calls', 'review_cycles', 'accepted_artifacts', 'internal_chargeback_rate'],
                    'scenario_set' => ['base', 'upside', 'downside', 'stress'],
                    'variance_explainers_required' => ['volume_delta', 'runtime_cost_delta', 'quality_rework_delta', 'capacity_delta'],
                    'default_scenario' => $index % 3 === 0 ? 'upside' : 'base',
                    'forecast_hash' => hash('sha256', 'flow_finance_forecast|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'flow_model_risk_controls' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_finance_model_risk_control.v1',
                    'flow_id' => $flowId,
                    'model_artifacts' => ['assumption_register', 'versioned_workbook_or_notebook', 'sensitivity_table', 'stress_case_set', 'known_limitations'],
                    'review_gates' => ['formula_integrity_check', 'source_lineage_check', 'scenario_reasonableness_review', 'independent_reviewer_signoff', 'rollback_to_prior_model'],
                    'audit_trail_required' => true,
                    'investment_recommendation_allowed_without_review' => false,
                    'model_risk_hash' => hash('sha256', 'flow_finance_model_risk_control|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_investment_committee_packets' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_investment_committee_packet.v1',
                    'flow_id' => $flowId,
                    'packet_sections' => ['executive_summary', 'source_linked_thesis', 'comps_and_valuation', 'scenario_model', 'risk_register', 'compliance_notes', 'decision_options'],
                    'required_approvals' => ['analyst_owner', 'model_risk_reviewer', 'compliance_reviewer', 'operator_or_investment_committee'],
                    'external_pitch_or_trade_allowed' => false,
                    'packet_hash' => hash('sha256', 'flow_investment_committee_packet|'.$flowId),
                ],
                $flowIds,
            )),
            'pnl_line_item_model' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.pnl_line_item_model.v1',
                    'line_item_id' => 'pnl.'.$workProduct,
                    'work_product' => $workProduct,
                    'revenue_basis' => 'internal_chargeback_or_accepted_value_proxy',
                    'cost_basis' => ['agent_runtime', 'provider_usage', 'connector_usage', 'review_labor', 'incident_rework'],
                    'gross_margin_floor' => 0.55 + (($index % 3) * 0.05),
                    'external_revenue_claim_allowed' => false,
                    'pnl_hash' => hash('sha256', 'pnl_line_item|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'billing_ledger_controls' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.billing_ledger_control.v1',
                    'flow_id' => $flowId,
                    'ledger_artifacts' => ['draft_invoice', 'usage_snapshot', 'entitlement_snapshot', 'acceptance_receipt', 'tax_review_placeholder'],
                    'reconciliation_steps' => ['match_usage_to_receipt', 'match_entitlement_to_offer', 'match_acceptance_to_delivery', 'operator_review_before_external_invoice'],
                    'external_invoice_allowed' => false,
                    'payment_collection_allowed' => false,
                    'ledger_hash' => hash('sha256', 'billing_ledger_control|'.$flowId),
                ],
                $flowIds,
            )),
            'treasury_risk_controls' => [
                'cash_policy' => 'internal_cash_position_model_only_until_bank_or_payment_connector_mandate',
                'capital_actions_blocked' => ['wire_transfer', 'card_charge', 'broker_order', 'vendor_payment', 'crypto_transfer', 'loan_or_credit_action'],
                'liquidity_scenarios' => ['base_burn', 'capacity_ramp', 'provider_cost_spike', 'customer_delay', 'incident_rework'],
                'loss_cap_signature_required' => true,
                'real_money_movement_allowed' => false,
                'treasury_hash' => hash('sha256', $domainId.'|treasury_risk_controls'),
            ],
            'finance_close_and_audit_pack' => [
                'close_packet_sections' => ['budget_vs_actual', 'forecast_change', 'pnl_by_service', 'usage_to_billing_reconciliation', 'source_linked_claim_audit', 'model_risk_review_summary', 'compliance_obligation_mapping', 'open_finance_risks', 'operator_exceptions'],
                'evidence_required' => ['runtime_receipts', 'accepted_artifacts', 'cost_center_records', 'ledger_drafts', 'approval_packets', 'variance_notes', 'direct_source_links', 'model_version_hashes', 'compliance_review_notes'],
                'audit_trail_required' => true,
                'external_reporting_allowed' => false,
                'close_hash' => hash('sha256', $domainId.'|finance_close_audit_pack'),
            ],
            'finance_observability' => [
                'required_metrics' => ['budget_burn_rate', 'forecast_accuracy', 'gross_margin_proxy', 'cost_per_accepted_artifact', 'billing_draft_coverage', 'reconciliation_exception_count', 'source_link_coverage', 'cross_source_variance_rate', 'model_review_sla', 'compliance_obligation_coverage', 'unapproved_external_finance_request_count', 'provider_cost_spike_count', 'cash_risk_signal', 'finance_close_packet_coverage'],
                'dashboard' => $domainId.'_finance_treasury_billing_board',
                'alert_on' => ['budget_overrun', 'margin_floor_breach', 'unreconciled_usage', 'external_invoice_requested', 'real_money_movement_requested', 'trade_or_capital_action_requested'],
            ],
            'finance_treasury_billing_hash' => hash('sha256', $domainId.'|finance_treasury_billing|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $connectors).'|'.implode('|', $companyMetrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function financeTreasurySourceCatalog(string $domainId): array
    {
        $sources = [
            ['source_id' => 'anthropic_claude_for_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'financial_research_analysis_compliance_and_workflow_agents_with_enterprise_controls'],
            ['source_id' => 'anthropic_finance_agents', 'url' => 'https://www.anthropic.com/news/finance-agents', 'pattern' => 'agentic_finance_workflows_for_research_modeling_due_diligence_and_risk_review'],
            ['source_id' => 'stripe_billing_features', 'url' => 'https://stripe.com/billing/features', 'pattern' => 'billing_subscriptions_invoices_entitlements_and_revenue_operations'],
            ['source_id' => 'netsuite_financial_management', 'url' => 'https://www.netsuite.com/portal/products/erp/financial-management.shtml', 'pattern' => 'enterprise_financial_close_planning_reporting_and_controls'],
            ['source_id' => 'workday_adaptive_planning', 'url' => 'https://www.workday.com/en-us/products/adaptive-planning/overview.html', 'pattern' => 'fpna_budgeting_forecasting_scenario_planning_and_reporting'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'security_review', 'contract_terms_review', 'sandbox_probe', 'operator_mandate_for_external_financial_action'],
                'source_hash' => hash('sha256', 'finance_treasury_source|'.$domainId.'|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function accountContractSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'salesforce_agentforce_platform', 'url' => 'https://www.salesforce.com/agentforce/', 'pattern' => 'specialized_agents_connected_to_business_data_tools_and_mcp'],
            ['source_id' => 'salesforce_agentforce_developer_guide', 'url' => 'https://developer.salesforce.com/docs/ai/agentforce/guide/get-started-agents.html', 'pattern' => 'agent_lifecycle_testing_api_and_customer_channel_integration'],
            ['source_id' => 'gainsight_customer_success_scorecards', 'url' => 'https://www.gainsight.com/customer-success-scorecards/', 'pattern' => 'customer_360_health_scores_success_plans_playbooks_retention_and_growth'],
            ['source_id' => 'hubspot_customer_success_management', 'url' => 'https://www.hubspot.com/products/service/customer-success-management', 'pattern' => 'customer_health_renewal_pipeline_usage_support_and_sla_management'],
            ['source_id' => 'stripe_billing_features', 'url' => 'https://stripe.com/billing/features', 'pattern' => 'subscription_invoicing_entitlements_quotes_contracting_and_billing_compliance'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'sandbox_or_read_only_probe', 'security_review', 'operator_approval_for_external_use'],
                'source_hash' => hash('sha256', 'account_contract_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseVendorLegalProcurementStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $sources = $this->domainSolutionSourceCatalog($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1',
            'company_id' => $domainId,
            'procurement_policy' => [
                'mode' => 'contract_review_and_internal_procurement_only',
                'purchase_authority' => 'operator_only_for_real_spend',
                'approved_internal_actions' => ['evaluate_vendor', 'draft_purchase_packet', 'compare_contract_terms', 'prepare_security_review'],
                'blocked_without_operator_approval' => ['sign_contract', 'start_paid_plan', 'share_secret', 'grant_write_scope', 'commit_external_spend'],
                'receipt_required' => true,
            ],
            'vendor_due_diligence_register' => array_values(array_map(
                static fn (string $connector): array => [
                    'vendor_or_tool_id' => $connector,
                    'usage_scope' => 'read_or_internal_adapter_until_operator_mandate',
                    'risk_checks' => ['security_posture', 'data_boundary', 'license_or_terms', 'availability_slo', 'exit_plan'],
                    'required_evidence' => ['terms_review', 'least_privilege_scope', 'sandbox_probe_receipt', 'fallback_plan'],
                    'current_status' => 'diligence_required_before_production',
                    'external_side_effects_enabled' => false,
                    'vendor_hash' => hash('sha256', 'vendor_due_diligence|'.$connector),
                ],
                $connectors,
            )),
            'source_terms_review_register' => array_values(array_map(
                static fn (array $source): array => [
                    'source_id' => (string) $source['source_id'],
                    'url' => (string) $source['url'],
                    'allowed_use_status' => 'review_required_before_automated_ingestion',
                    'review_artifacts' => ['terms_snapshot', 'allowed_use_summary', 'rate_limit_or_access_notes', 'attribution_requirements'],
                    'external_ingestion_enabled' => false,
                    'terms_hash' => hash('sha256', 'source_terms_review|'.(string) $source['source_id']),
                ],
                $sources,
            )),
            'contract_lifecycle_model' => [
                'stages' => ['intake', 'diligence', 'security_review', 'legal_review', 'operator_decision', 'activation', 'renewal_or_exit'],
                'required_fields' => ['business_need', 'data_classes', 'tool_permissions', 'cost_model', 'owner', 'exit_plan'],
                'renewal_review_cadence' => 'quarterly_or_before_scope_change',
                'auto_renewal_allowed' => false,
                'contract_receipt_required' => true,
            ],
            'flow_procurement_routing' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'procurement_review_required_when' => ['new_connector', 'paid_api', 'regulated_data', 'write_scope', 'external_publication'],
                    'allowed_preapproval_actions' => ['draft_vendor_comparison', 'run_read_only_probe', 'prepare_operator_packet'],
                    'blocked_actions' => ['purchase', 'credential_share', 'write_scope_enablement', 'public_commitment'],
                    'routing_hash' => hash('sha256', 'flow_procurement_routing|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'legal_and_compliance_review' => [
                'review_owner' => 'independent_reviewer_agent',
                'operator_decision_required_for' => ['regulated_data_processing', 'external_customer_claim', 'paid_vendor_contract', 'live_financial_or_security_action'],
                'required_checks' => ['data_processing_terms', 'ip_and_license', 'confidentiality', 'liability_or_warranty', 'termination_and_export'],
                'exception_policy' => 'fail_closed_until_review_packet_accepted',
            ],
            'vendor_operability_scorecard' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'score_dimensions' => ['reliability', 'cost_transparency', 'security_boundary', 'receipt_export', 'manual_fallback'],
                    'minimum_score_before_shadow_mode' => 0.86,
                    'evidence_source' => 'integration_probe_receipts_and_vendor_review_packet',
                    'scorecard_hash' => hash('sha256', 'vendor_operability_scorecard|'.$connector),
                ],
                $connectors,
            )),
            'procurement_observability' => [
                'required_metrics' => ['vendor_review_backlog', 'contract_exception_count', 'paid_plan_requests_blocked', 'connector_scope_changes', 'renewal_review_due_count'],
                'dashboard' => $domainId.'_vendor_legal_procurement_board',
                'alert_on' => ['unreviewed_paid_vendor', 'terms_review_missing', 'write_scope_requested', 'auto_renewal_detected'],
            ],
            'procurement_hash' => hash('sha256', $domainId.'|vendor_legal_procurement|'.implode('|', $connectors).'|'.implode('|', $flowIds).'|'.implode('|', array_column($sources, 'source_id'))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseResilienceContinuityStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'schema' => 'atlas.ai.company.enterprise_resilience_continuity_stack.v1',
            'company_id' => $domainId,
            'resilience_policy' => [
                'operating_mode' => 'fail_closed_with_manual_operator_fallback',
                'criticality_class' => in_array($domainId, ['finance', 'cyber', 'operations'], true) ? 'tier_1' : 'tier_2',
                'external_side_effects_during_incident_allowed' => false,
                'required_incident_artifacts' => ['incident_packet', 'timeline', 'impact_assessment', 'rollback_or_pause_plan', 'postmortem_actions'],
                'operator_escalation_required_for_sev1' => true,
            ],
            'business_continuity_plan' => [
                'critical_assets' => ['company_packet', 'operating_packet', 'flow_state', 'evidence_ledger_refs', 'connector_contracts'],
                'minimum_viable_operation' => 'read_only_advisory_packets_with_manual_review',
                'manual_fallback_owner' => 'operator',
                'recovery_order' => ['policy_gate', 'evidence_ledger', 'company_packet', 'read_only_connectors', 'flow_runtime_queue'],
                'continuity_hash' => hash('sha256', $domainId.'|business_continuity_plan'),
            ],
            'flow_failure_mode_analysis' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'failure_modes' => ['missing_context', 'connector_unavailable', 'policy_block', 'low_quality_score', 'handoff_rejected'],
                    'detection_signals' => ['state_transition_timeout', 'tool_receipt_missing', 'critic_score_below_floor', 'policy_findings_nonzero'],
                    'fallback_action' => 'emit_blocked_packet_and_open_review_queue',
                    'recovery_evidence_required' => ['last_green_checkpoint', 'blocked_reason', 'review_packet', 'resume_token'],
                    'fmea_hash' => hash('sha256', 'flow_failure_mode_analysis|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'connector_resilience_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'degraded_mode' => 'cached_or_manual_import_read_only',
                    'health_checks' => ['contract_present', 'read_only_probe_receipt', 'latency_budget', 'permission_scope_match'],
                    'fallback_required' => true,
                    'write_modes_remain_blocked' => true,
                    'connector_resilience_hash' => hash('sha256', 'connector_resilience|'.$connector),
                ],
                $connectors,
            )),
            'backup_restore_contract' => [
                'state_artifacts' => ['flow_state', 'decision_receipts', 'work_product_hashes', 'quality_reviews', 'handoff_packets'],
                'restore_test_cadence' => 'monthly_or_before_shadow_mode',
                'restore_success_criteria' => ['receipt_hashes_match', 'latest_green_checkpoint_loads', 'manual_fallback_documented'],
                'restore_without_operator_approval_allowed' => false,
                'backup_contract_hash' => hash('sha256', $domainId.'|backup_restore_contract|'.implode('|', $workProducts)),
            ],
            'incident_exercise_program' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'exercise_id' => 'resilience_drill_'.$flowId,
                    'flow_id' => $flowId,
                    'scenario' => $index % 3 === 0 ? 'connector_outage' : ($index % 3 === 1 ? 'policy_block' : 'quality_regression'),
                    'cadence' => 'quarterly_or_before_supervised_production',
                    'success_criteria' => ['blocked_packet_emitted', 'operator_route_verified', 'postmortem_action_created'],
                    'exercise_hash' => hash('sha256', 'incident_exercise|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'crisis_communication_model' => [
                'audiences' => ['operator', 'portfolio_governor', 'target_company', 'independent_reviewer_agent'],
                'message_templates' => ['sev1_external_risk', 'sev2_policy_block', 'sev3_flow_degraded', 'sev4_quality_warning'],
                'external_notification_allowed_without_operator' => false,
                'required_sections' => ['status', 'impact', 'blocked_actions', 'next_review_time', 'receipt_hash'],
            ],
            'resilience_observability' => [
                'required_metrics' => ['flow_block_rate', 'connector_degraded_count', 'restore_test_age_days', 'incident_exercise_pass_rate', 'manual_fallback_usage'],
                'dashboard' => $domainId.'_resilience_continuity_board',
                'alert_on' => ['sev1_external_side_effect_risk', 'restore_test_overdue', 'connector_degraded_without_fallback', 'missing_incident_packet'],
            ],
            'resilience_hash' => hash('sha256', $domainId.'|resilience_continuity|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $workProducts)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseAnalyticsDecisionIntelligenceStack(string $domainId, array $blueprint, array $manifestMetrics, array $manifestProducts): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $metrics = array_values(array_unique(array_merge($manifestMetrics, (array) $blueprint['metrics'])));
        $workProducts = array_values(array_unique(array_merge($manifestProducts, (array) $blueprint['work_products'])));

        return [
            'schema' => 'atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1',
            'company_id' => $domainId,
            'decision_intelligence_policy' => [
                'mode' => 'evidence_linked_internal_decision_support',
                'synthetic_scores_allowed' => false,
                'decision_without_receipt_allowed' => false,
                'external_action_from_dashboard_allowed' => false,
                'operator_review_required_for_capital_or_external_action' => true,
            ],
            'metric_lineage_catalog' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric_key' => $metric,
                    'definition' => $metric.'.definition.v1',
                    'source_of_record' => 'company_operating_packet.observed_metrics',
                    'required_lineage' => ['input_receipt_hash', 'calculation_method', 'observed_at', 'quality_review'],
                    'staleness_policy' => 'disclose_when_not_recent_or_missing',
                    'lineage_hash' => hash('sha256', 'metric_lineage|'.$metric),
                ],
                $metrics,
            )),
            'executive_dashboard_catalog' => [
                [
                    'dashboard_id' => $domainId.'_executive_operating_dashboard',
                    'audience' => 'operator_and_portfolio_governor',
                    'sections' => ['okr_status', 'sla_status', 'risk_status', 'flow_throughput', 'blocked_actions'],
                    'decision_use' => 'weekly_operating_review',
                    'dashboard_hash' => hash('sha256', $domainId.'|executive_operating_dashboard'),
                ],
                [
                    'dashboard_id' => $domainId.'_quality_and_delivery_dashboard',
                    'audience' => 'company_manager_and_independent_reviewer',
                    'sections' => ['quality_scores', 'repair_cycles', 'acceptance_rate', 'handoff_rejections', 'policy_findings'],
                    'decision_use' => 'delivery_quality_review',
                    'dashboard_hash' => hash('sha256', $domainId.'|quality_delivery_dashboard'),
                ],
                [
                    'dashboard_id' => $domainId.'_integration_and_resilience_dashboard',
                    'audience' => 'company_manager_and_operator',
                    'sections' => ['connector_health', 'probe_receipts', 'fallback_status', 'restore_test_age', 'incident_exercises'],
                    'decision_use' => 'production_promotion_review',
                    'dashboard_hash' => hash('sha256', $domainId.'|integration_resilience_dashboard'),
                ],
            ],
            'flow_decision_register' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'decision_packet' => $flowId.'.decision_packet.v1',
                    'required_decision_fields' => ['decision_context', 'options', 'evidence_refs', 'risk_tradeoffs', 'recommended_action', 'operator_checkpoint_if_external'],
                    'action_register_required' => true,
                    'decision_hash' => hash('sha256', 'flow_decision_register|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'scenario_and_forecast_model' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'scenario_set' => ['base_case', 'upside_case', 'downside_case', 'blocked_case'],
                    'forecast_horizon' => $index % 2 === 0 ? 'next_operating_cycle' : 'next_quarter_internal',
                    'required_inputs' => ['observed_metrics', 'risk_register', 'capacity_plan', 'dependency_status'],
                    'promotion_use' => 'advisory_only_until_observed_results',
                    'scenario_hash' => hash('sha256', 'scenario_forecast|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'work_product_analytics_map' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'work_product' => $workProduct,
                    'primary_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                    'acceptance_signal' => 'operator_or_target_company_acceptance',
                    'learning_signal' => 'quality_exception_or_followup_request',
                    'analytics_hash' => hash('sha256', 'work_product_analytics|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'decision_observability' => [
                'required_metrics' => ['decision_cycle_time', 'decision_receipt_coverage', 'stale_metric_count', 'forecast_error_after_observation', 'action_completion_rate'],
                'dashboard' => $domainId.'_decision_intelligence_board',
                'alert_on' => ['decision_without_receipt', 'stale_metric_used', 'external_action_from_dashboard_request', 'forecast_missing_observation'],
            ],
            'analytics_hash' => hash('sha256', $domainId.'|analytics_decision_intelligence|'.implode('|', $flowIds).'|'.implode('|', $metrics).'|'.implode('|', $workProducts)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseKnowledgeMemoryLearningStack(string $domainId, array $blueprint, array $manifestMetrics, array $manifestProducts): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $metrics = array_values(array_unique(array_merge($manifestMetrics, (array) $blueprint['metrics'])));
        $workProducts = array_values(array_unique(array_merge($manifestProducts, (array) $blueprint['work_products'])));
        $sourceIds = array_column($this->domainSolutionSourceCatalog($domainId), 'source_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1',
            'company_id' => $domainId,
            'memory_governance_policy' => [
                'authoring_source_of_truth' => 'repo_docs_and_domain_contracts',
                'operational_read_models' => ['postgres_kb', 'code_intelligence', 'obsidian_surface', 'evidence_ledger'],
                'write_mode' => 'proposal_only_until_operator_or_owner_doc_review',
                'cross_company_memory_write_requires_handoff' => true,
                'provider_chat_as_source_of_truth_allowed' => false,
                'automatic_canonical_doc_rewrite_allowed' => false,
            ],
            'knowledge_source_registry' => array_values(array_map(
                static fn (string $sourceId): array => [
                    'source_id' => $sourceId,
                    'ingestion_mode' => 'reviewed_reference_or_read_only_connector_probe',
                    'required_evidence' => ['source_url', 'retrieved_at_or_version', 'license_or_terms_review', 'quality_review', 'source_hash'],
                    'allowed_memory_surface' => 'company_read_model_after_review',
                    'canonical_write_allowed' => false,
                    'source_registry_hash' => hash('sha256', 'knowledge_source_registry|'.$sourceId),
                ],
                $sourceIds,
            )),
            'flow_learning_loops' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'learning_inputs' => ['decision_receipts', 'quality_reviews', 'handoff_acceptance', 'policy_findings', 'observed_metrics'],
                    'learning_outputs' => ['playbook_delta', 'evaluation_fixture_delta', 'runbook_update_candidate', 'source_gap_report'],
                    'review_gate' => 'owner_doc_or_company_manager_review_before_canonical_write',
                    'promotion_signal' => 'two_green_cycles_with_no_policy_findings_and_accepted_playbook_delta',
                    'learning_loop_hash' => hash('sha256', 'flow_learning_loop|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'postmortem_and_retrospective_program' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'triggers' => ['sev1_or_sev2_incident', 'quality_rejection', 'handoff_rejection', 'forecast_miss', 'policy_block'],
                    'required_sections' => ['timeline', 'impact', 'root_cause', 'detection_gap', 'corrective_actions', 'playbook_delta', 'evidence_refs'],
                    'action_tracking' => 'backlog_item_with_owner_due_date_and_receipt',
                    'external_disclosure_allowed_without_operator' => false,
                    'postmortem_hash' => hash('sha256', 'postmortem_program|'.$flowId),
                ],
                $flowIds,
            )),
            'playbook_change_control' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'change_packet_schema' => $flowId.'.playbook_change_packet.v1',
                    'required_diff_sections' => ['current_behavior', 'proposed_behavior', 'evidence_refs', 'risk_review', 'test_or_eval_update', 'rollback_plan'],
                    'approvers' => ['company_manager_agent', 'independent_reviewer_agent', 'operator_when_external_action_changes'],
                    'auto_apply_allowed' => false,
                    'change_hash' => hash('sha256', 'playbook_change_control|'.$flowId),
                ],
                $flowIds,
            )),
            'work_product_feedback_memory' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'work_product' => $workProduct,
                    'feedback_packet' => $workProduct.'.feedback_memory_packet.v1',
                    'minimum_signals' => ['acceptance_state', 'quality_score', 'requested_revision', 'evidence_gap', 'followup_outcome'],
                    'linked_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                    'retention_policy' => 'retain_summary_and_receipt_hash_discard_sensitive_payload_unless_policy_allows',
                    'feedback_hash' => hash('sha256', 'work_product_feedback_memory|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'connector_knowledge_sync_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'sync_mode' => 'read_only_probe_to_company_read_model',
                    'sync_preconditions' => ['contract_documented', 'least_privilege_bound', 'sandbox_probe_green', 'terms_review_green'],
                    'writeback_allowed' => false,
                    'staleness_disclosure_required' => true,
                    'sync_hash' => hash('sha256', 'connector_knowledge_sync|'.$connector),
                ],
                $connectors,
            )),
            'learning_observability' => [
                'required_metrics' => ['playbook_delta_acceptance_rate', 'postmortem_action_completion', 'source_gap_count', 'memory_staleness_count', 'regression_reopened_count'],
                'dashboard' => $domainId.'_knowledge_memory_learning_board',
                'alert_on' => ['canonical_write_without_review', 'cross_company_memory_without_handoff', 'stale_source_used_without_disclosure', 'postmortem_action_overdue'],
            ],
            'knowledge_memory_hash' => hash('sha256', $domainId.'|knowledge_memory_learning|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $sourceIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseIdentityAccessDataSovereigntyStack(string $domainId, array $blueprint): array
    {
        $agents = array_values((array) $blueprint['agent_roles']);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $dataClasses = ['public', 'internal', 'confidential', 'regulated_or_sensitive', 'secret_or_credential'];

        return [
            'schema' => 'atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1',
            'company_id' => $domainId,
            'identity_access_policy' => [
                'model' => 'zero_trust_least_privilege_per_agent_flow_and_connector',
                'default_access' => 'deny',
                'credential_storage' => 'vault_reference_only_no_secret_material_in_packet',
                'cross_company_access_requires_handoff' => true,
                'external_write_publish_spend_trade_deploy_requires_operator_mandate' => true,
                'privilege_escalation_auto_allowed' => false,
            ],
            'agent_access_matrix' => array_values(array_map(
                static fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'principal_id' => 'agent:'.$agent,
                    'default_permissions' => ['read_assigned_context', 'produce_typed_artifact', 'request_handoff', 'request_operator_review'],
                    'denied_permissions' => ['read_unscoped_company_memory', 'export_secret', 'external_write_without_mandate', 'approve_own_work'],
                    'session_policy' => [
                        'short_lived_token_required' => true,
                        'scope_bound_to_flow' => true,
                        'step_up_required_for_sensitive_data' => true,
                    ],
                    'access_hash' => hash('sha256', 'agent_access_matrix|'.$agent.'|'.$index),
                ],
                $agents,
                array_keys($agents),
            )),
            'flow_data_boundary_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'allowed_data_classes' => ['public', 'internal', 'confidential_with_redaction'],
                    'blocked_data_classes_without_operator' => ['regulated_or_sensitive', 'secret_or_credential'],
                    'egress_controls' => ['source_ref_required', 'redaction_before_provider_or_external_tool', 'receipt_hash_required', 'staleness_disclosure'],
                    'cross_company_context_rule' => 'typed_handoff_packet_only',
                    'boundary_hash' => hash('sha256', 'flow_data_boundary|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'connector_secret_binding_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'credential_binding' => 'vault_path_reference_only',
                    'minimum_scope' => 'read_only_probe_or_internal_adapter_until_operator_mandate',
                    'rotation_policy' => 'before_shadow_mode_and_after_incident',
                    'revocation_trigger' => ['policy_violation', 'operator_revoke', 'connector_terms_change', 'incident_response'],
                    'secret_material_export_allowed' => false,
                    'binding_hash' => hash('sha256', 'connector_secret_binding|'.$connector),
                ],
                $connectors,
            )),
            'sensitive_data_handling_catalog' => array_values(array_map(
                fn (string $dataClass): array => [
                    'data_class' => $dataClass,
                    'default_action' => $dataClass === 'public' ? 'allow_with_source_ref' : 'minimize_redact_scope_and_log_receipt',
                    'provider_payload_rule' => in_array($dataClass, ['regulated_or_sensitive', 'secret_or_credential'], true)
                        ? 'blocked_until_redacted_or_operator_approved'
                        : 'allowed_when_task_scoped_and_receipted',
                    'retention_rule' => $dataClass === 'secret_or_credential'
                        ? 'never_store_payload_store_vault_reference_only'
                        : 'retain_summary_hash_and_policy_basis',
                    'review_required' => $dataClass !== 'public',
                    'handling_hash' => hash('sha256', $domainId.'|sensitive_data_handling|'.$dataClass),
                ],
                $dataClasses,
            )),
            'purpose_consent_registry' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'purpose_required' => true,
                    'allowed_purposes' => ['internal_analysis', 'operator_requested_delivery', 'cross_company_handoff', 'quality_or_safety_review'],
                    'consent_or_authority_required_when' => ['personal_data', 'regulated_data', 'external_delivery', 'customer_or_vendor_context'],
                    'purpose_drift_action' => 'block_and_request_operator_review',
                    'purpose_hash' => hash('sha256', 'purpose_consent|'.$flowId),
                ],
                $flowIds,
            )),
            'tenant_isolation_model' => [
                'company_scope' => $domainId,
                'isolation_boundary' => 'company_packet_memory_connectors_and_receipts_are_scoped_by_company_id',
                'shared_services_allowed' => ['portfolio_governance', 'cross_company_handoff_router', 'evidence_ledger_read_model'],
                'shared_service_preconditions' => ['typed_handoff_packet', 'target_company_acceptance', 'source_company_receipt', 'policy_check_green'],
                'raw_context_pooling_allowed' => false,
            ],
            'break_glass_and_revocation' => [
                'break_glass_allowed' => false,
                'manual_operator_override_packet_required' => true,
                'revocation_sla' => 'immediate_for_secret_or_policy_violation',
                'required_artifacts' => ['access_decision_receipt', 'revocation_reason', 'affected_flows', 'post_revocation_review'],
            ],
            'sovereignty_observability' => [
                'required_metrics' => ['least_privilege_coverage', 'cross_company_access_attempts', 'redaction_required_count', 'credential_rotation_age', 'purpose_drift_blocks'],
                'dashboard' => $domainId.'_identity_access_data_sovereignty_board',
                'alert_on' => ['secret_material_detected', 'unscoped_memory_access', 'cross_company_access_without_handoff', 'external_write_without_mandate'],
            ],
            'sovereignty_hash' => hash('sha256', $domainId.'|identity_access_data_sovereignty|'.implode('|', $agents).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseControlTowerRunOperationsStack(string $domainId, array $blueprint, array $manifestCadences): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $cadences = array_values(array_unique(array_merge($manifestCadences, (array) $blueprint['cadences'])));
        $dashboards = array_values((array) $blueprint['dashboards']);
        $reviewQueue = (string) $blueprint['review_queue'];

        return [
            'schema' => 'atlas.ai.company.enterprise_control_tower_run_operations_stack.v1',
            'company_id' => $domainId,
            'run_operations_policy' => [
                'mode' => 'supervised_internal_control_tower',
                'runtime_pattern' => 'durable_checkpointed_graph_with_guardrails_handoffs_tracing_and_human_interrupts',
                'external_side_effects_default' => false,
                'run_without_decision_receipt_allowed' => false,
                'operator_interrupt_supported' => true,
                'auto_retry_external_action_allowed' => false,
            ],
            'control_tower_lanes' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'lane_id' => $flowId.'.control_tower_lane',
                    'owner_agent' => (string) $spec[0],
                    'intake_states' => ['queued', 'triaged', 'running', 'blocked', 'review_required', 'accepted', 'closed'],
                    'required_run_artifacts' => ['decision_receipt', 'state_checkpoint', 'tool_receipts', 'trace_id', 'quality_review', 'handoff_packet_if_any'],
                    'blocked_until' => ['policy_check_green', 'identity_scope_green', 'required_sources_attached', 'budget_envelope_available'],
                    'lane_hash' => hash('sha256', 'control_tower_lane|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'run_queue_model' => [
                'queue_id' => $domainId.'_enterprise_run_queue',
                'priority_classes' => ['sev1_operator_blocker', 'sev2_customer_or_company_blocker', 'scheduled_cadence', 'normal_delivery', 'learning_backlog'],
                'admission_controls' => ['scope_present', 'acceptance_criteria_present', 'policy_profile_present', 'identity_scope_bound', 'receipt_budget_allocated'],
                'backpressure_policy' => 'pause_new_runs_when_review_queue_or_policy_findings_exceed_threshold',
                'dead_letter_queue' => $domainId.'_enterprise_run_dlq',
                'queue_hash' => hash('sha256', $domainId.'|enterprise_run_queue'),
            ],
            'cadence_scheduler' => array_values(array_map(
                static fn (string $cadence, int $index): array => [
                    'cadence_id' => $cadence,
                    'schedule_class' => $index % 2 === 0 ? 'operating_review' : 'specialist_review',
                    'required_inputs' => ['open_runs', 'blocked_runs', 'recent_receipts', 'metric_snapshot', 'risk_register_delta'],
                    'outputs' => ['prioritized_run_plan', 'operator_review_packet', 'backlog_update'],
                    'missed_cadence_action' => 'open_control_tower_exception',
                    'cadence_hash' => hash('sha256', 'control_tower_cadence|'.$cadence),
                ],
                $cadences,
                array_keys($cadences),
            )),
            'incident_and_exception_desk' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'exception_types' => ['policy_block', 'tool_failure', 'missing_evidence', 'quality_rejection', 'handoff_rejection', 'identity_scope_violation'],
                    'triage_sla' => 'same_operating_cycle_for_sev2_or_above',
                    'required_packet' => $flowId.'.exception_packet.v1',
                    'resolution_states' => ['accepted_risk', 'repaired', 'requeued', 'operator_escalated', 'closed_as_invalid'],
                    'exception_hash' => hash('sha256', 'control_tower_exception|'.$flowId),
                ],
                $flowIds,
            )),
            'change_window_and_release_calendar' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'change_types' => ['playbook_change', 'connector_scope_change', 'evaluation_fixture_change', 'policy_profile_change', 'production_promotion_request'],
                    'required_approvals' => ['company_manager_agent', 'independent_reviewer_agent', 'operator_for_external_or_sensitive_change'],
                    'freeze_conditions' => ['active_sev1', 'policy_findings_open', 'credential_rotation_overdue', 'observed_regression'],
                    'rollback_required' => true,
                    'change_window_hash' => hash('sha256', 'change_window|'.$flowId),
                ],
                $flowIds,
            )),
            'connector_operations_probe_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'probe_mode' => 'read_only_or_internal_no_mutation',
                    'health_checks' => ['auth_scope_probe', 'latency_probe', 'receipt_export_probe', 'rate_limit_probe', 'fallback_probe'],
                    'promotion_gate' => 'green_probe_before_shadow_mode_and_operator_mandate_before_write',
                    'auto_remediation_allowed' => false,
                    'probe_hash' => hash('sha256', 'connector_operations_probe|'.$connector),
                ],
                $connectors,
            )),
            'dashboard_operations_map' => array_values(array_map(
                static fn (string $dashboard): array => [
                    'dashboard_id' => $dashboard,
                    'control_tower_sections' => ['run_queue', 'blocked_runs', 'sla_risk', 'policy_findings', 'connector_health', 'operator_interrupts'],
                    'decision_scope' => 'internal_prioritization_and_review_only',
                    'external_action_buttons_allowed' => false,
                    'dashboard_ops_hash' => hash('sha256', 'dashboard_operations_map|'.$dashboard),
                ],
                $dashboards,
            )),
            'human_interrupt_and_escalation_model' => [
                'review_queue' => $reviewQueue,
                'interrupt_points' => ['before_external_action', 'before_sensitive_data_use', 'after_policy_block', 'before_production_promotion', 'after_quality_rejection'],
                'handoff_packet_required' => true,
                'resume_requires' => ['operator_or_reviewer_decision', 'updated_state_checkpoint', 'new_receipt_hash'],
                'escalation_hash' => hash('sha256', $domainId.'|human_interrupt|'.$reviewQueue),
            ],
            'run_observability' => [
                'required_metrics' => ['run_cycle_time', 'blocked_run_count', 'dlq_count', 'interrupt_resolution_time', 'connector_probe_health', 'change_failure_rate'],
                'trace_fields' => ['trace_id', 'run_id', 'flow_id', 'agent_role', 'receipt_hash', 'checkpoint_id', 'policy_result', 'handoff_id'],
                'alert_on' => ['run_without_receipt', 'dlq_growth', 'policy_block_spike', 'operator_interrupt_overdue', 'connector_probe_failed'],
            ],
            'control_tower_hash' => hash('sha256', $domainId.'|control_tower_run_operations|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $cadences)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseCompanyCommandCenterStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $agents = array_values((array) $blueprint['agent_roles']);
        $workProducts = array_values((array) $blueprint['work_products']);
        $metrics = array_values($companyMetrics);
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $sourceUrlById = [];
        foreach ($sources as $source) {
            $sourceUrlById[(string) $source['source_id']] = (string) $source['url'];
        }

        $operatingCells = [
            ['cell_id' => 'front_office', 'purpose' => 'intake_prioritization_customer_or_portfolio_value_and_acceptance'],
            ['cell_id' => 'delivery_office', 'purpose' => 'flow_execution_artifact_delivery_quality_and_sla_control'],
            ['cell_id' => 'data_office', 'purpose' => 'source_provider_lineage_freshness_redaction_and_data_products'],
            ['cell_id' => 'risk_office', 'purpose' => 'policy_grc_security_legal_privacy_and_external_action_review'],
            ['cell_id' => 'platform_office', 'purpose' => 'connector_health_runtime_queue_replay_cost_and_observability'],
            ['cell_id' => 'learning_office', 'purpose' => 'postmortem_feedback_playbook_updates_and_benchmark_regression'],
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_company_command_center_stack.v1',
            'company_id' => $domainId,
            'command_center_policy' => [
                'mode' => 'domain_company_operating_command_center',
                'reference_pattern' => 'claude_financial_services_style_unified_data_connectors_specialist_agents_source_linked_artifacts_generalized_per_company',
                'calendar_wait_blocker_enabled' => false,
                'flow_card_required_before_shadow_or_supervised_runtime' => true,
                'operator_interrupt_required_for_external_side_effect' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'secret_material_in_packet_allowed' => false,
            ],
            'operating_cells' => array_values(array_map(
                static fn (array $cell, int $index): array => [
                    'schema' => 'atlas.ai.company.command_center_operating_cell.v1',
                    'cell_id' => (string) $cell['cell_id'],
                    'purpose' => (string) $cell['purpose'],
                    'primary_agent' => (string) ($agents[$index % max(1, count($agents))] ?? 'company_manager_agent'),
                    'backup_agent' => (string) ($agents[($index + 1) % max(1, count($agents))] ?? 'independent_reviewer_agent'),
                    'required_inputs' => ['company_packet', 'open_flow_cards', 'metric_snapshot', 'risk_register', 'source_lineage_delta'],
                    'required_outputs' => ['cell_status', 'blocked_item_list', 'decision_or_escalation_packet', 'receipt_refs'],
                    'blocked_actions_without_operator' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'admin', 'secret_export'],
                    'cell_hash' => hash('sha256', 'company_command_center_cell|'.(string) $cell['cell_id']),
                ],
                $operatingCells,
                array_keys($operatingCells),
            )),
            'flow_command_cards' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_command_card.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'agent_crew' => array_values(array_unique([
                        (string) $spec[0],
                        (string) ($agents[($index + 1) % max(1, count($agents))] ?? 'independent_reviewer_agent'),
                        'independent_reviewer_agent',
                    ])),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'source_links' => array_values(array_map(
                        static fn (string $sourceId): string => (string) ($sourceUrlById[$sourceId] ?? ''),
                        array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    )),
                    'connector_scope' => array_values((array) $spec[1]),
                    'target_artifact' => (string) $spec[2],
                    'run_states' => ['intake', 'context_bound', 'source_verified', 'tool_plan_ready', 'analysis_running', 'critic_review', 'operator_checkpoint', 'handoff_or_delivery_ready'],
                    'quality_gates' => ['source_lineage_complete', 'policy_findings_zero', 'benchmark_or_fixture_green', 'receipt_hash_present', 'reviewer_acceptance_recorded'],
                    'required_receipts' => ['decision_receipt', 'source_lineage_receipt', 'tool_receipts', 'critic_review_receipt', 'operator_checkpoint_receipt'],
                    'sla_profile' => [
                        'standard_response_target' => 'same_operating_cycle',
                        'blocked_flow_triage_target' => 'same_day',
                        'quality_floor' => 0.86,
                        'policy_findings_allowed' => 0,
                    ],
                    'external_execution_allowed' => false,
                    'card_hash' => hash('sha256', $domainId.'|flow_command_card|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_workbench_panels' => array_values(array_map(
                static fn (string $connector, int $index): array => [
                    'schema' => 'atlas.ai.company.command_center_connector_panel.v1',
                    'connector_id' => $connector,
                    'panel_id' => $connector.'.command_center_panel.v1',
                    'mapped_operating_cell' => (string) ($operatingCells[$index % max(1, count($operatingCells))]['cell_id'] ?? 'platform_office'),
                    'displayed_health_signals' => ['auth_scope', 'last_probe_status', 'schema_drift', 'rate_limit_state', 'receipt_export_health', 'fallback_ready'],
                    'allowed_actions' => ['view_contract', 'run_read_only_probe', 'open_fixture', 'request_operator_scope', 'prepare_fallback_packet'],
                    'blocked_actions' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                    'credential_binding' => 'vault_reference_only',
                    'external_side_effects_enabled' => false,
                    'panel_hash' => hash('sha256', 'command_center_connector_panel|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'operator_console_views' => array_values(array_map(
                static fn (string $viewId): array => [
                    'view_id' => $viewId,
                    'sections' => ['run_queue', 'flow_cards', 'source_lineage', 'connector_health', 'risk_and_policy', 'quality_replay', 'operator_interrupts'],
                    'allowed_decisions' => ['prioritize', 'pause', 'request_repair', 'accept_internal_delivery', 'prepare_manual_external_handoff'],
                    'blocked_decisions_without_signed_mandate' => ['external_publish', 'external_write', 'real_spend', 'live_trade', 'production_deploy', 'delete_or_admin_change'],
                    'view_hash' => hash('sha256', 'operator_console_view|'.$viewId),
                ],
                [
                    $domainId.'_executive_command_console',
                    $domainId.'_flow_operations_console',
                    $domainId.'_risk_and_policy_console',
                    $domainId.'_data_and_connector_console',
                ],
            )),
            'work_product_factory_map' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'work_product' => $workProduct,
                    'factory_cell' => (string) ($operatingCells[$index % max(1, count($operatingCells))]['cell_id'] ?? 'delivery_office'),
                    'input_contract' => $workProduct.'.input_packet.v1',
                    'output_contract' => $workProduct.'.source_linked_artifact.v1',
                    'acceptance_checks' => ['schema_valid', 'source_refs_present', 'critic_score_green', 'policy_green', 'receipt_hash_attached'],
                    'customer_visible_export_allowed' => false,
                    'factory_hash' => hash('sha256', 'work_product_factory|'.$workProduct),
                ],
                array_values(array_unique(array_merge($workProducts, array_map(
                    static fn (string $flowId): string => $flowId.'_flow_delivery_artifact',
                    $flowIds,
                )))),
                array_keys(array_values(array_unique(array_merge($workProducts, array_map(
                    static fn (string $flowId): string => $flowId.'_flow_delivery_artifact',
                    $flowIds,
                ))))),
            )),
            'command_center_kpis' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric' => $metric,
                    'command_center_use' => 'operate_prioritize_and_detect_quality_risk_or_value_drift',
                    'minimum_lineage' => ['metric_source', 'calculation_version', 'observed_at', 'receipt_hash'],
                    'dashboard_tile' => $metric.'.command_center_tile',
                    'kpi_hash' => hash('sha256', 'command_center_kpi|'.$metric),
                ],
                $metrics,
            )),
            'escalation_and_pause_protocol' => [
                'pause_triggers' => ['policy_finding', 'source_lineage_gap', 'connector_probe_failed', 'quality_regression', 'operator_interrupt_overdue', 'external_action_requested'],
                'escalation_chain' => [(string) ($agents[0] ?? 'company_manager_agent'), 'independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'resume_requires' => ['blocked_reason_resolved', 'receipt_hash_attached', 'reviewer_acceptance', 'operator_decision_when_external'],
                'auto_resume_external_action_allowed' => false,
                'protocol_hash' => hash('sha256', $domainId.'|command_center_escalation_pause'),
            ],
            'command_center_hash' => hash('sha256', $domainId.'|company_command_center|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $workProducts).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $functions
     * @param list<string> $agentRoles
     * @param list<string> $workProducts
     * @param list<string> $metrics
     * @param list<string> $cadences
     * @return array<string,mixed>
     */
    private function enterpriseSemanticOperatingGraphStack(
        string $domainId,
        array $blueprint,
        array $functions,
        array $agentRoles,
        array $workProducts,
        array $metrics,
        array $cadences,
    ): array {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);

        $nodes = array_merge(
            $this->graphNodes('function', $functions, $domainId),
            $this->graphNodes('agent', $agentRoles, $domainId),
            $this->graphNodes('flow', $flowIds, $domainId),
            $this->graphNodes('connector', $connectors, $domainId),
            $this->graphNodes('metric', $metrics, $domainId),
            $this->graphNodes('work_product', $workProducts, $domainId),
            $this->graphNodes('cadence', $cadences, $domainId),
        );

        return [
            'schema' => 'atlas.ai.company.enterprise_semantic_operating_graph_stack.v1',
            'company_id' => $domainId,
            'graph_policy' => [
                'mode' => 'read_model_digital_twin_with_receipted_edges',
                'canonical_source' => 'company_buildout_packet_and_owner_docs',
                'graph_mutation_mode' => 'proposal_only_until_owner_review',
                'external_side_effects_from_graph_allowed' => false,
                'stale_or_missing_edge_blocks_autonomy_claim' => true,
            ],
            'node_catalog' => $nodes,
            'flow_relationship_edges' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'edge_id' => $flowId.'.flow_relationships',
                    'flow_id' => $flowId,
                    'agent_role' => (string) $spec[0],
                    'connectors' => array_values((array) $spec[1]),
                    'output_work_product' => (string) $spec[2],
                    'edge_types' => ['owned_by_agent', 'uses_connector', 'produces_work_product', 'observed_by_metric', 'guarded_by_policy'],
                    'edge_hash' => hash('sha256', 'flow_relationship_edges|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'operating_views' => [
                [
                    'view_id' => $domainId.'_executive_digital_twin',
                    'scope' => ['functions', 'flows', 'metrics', 'risks', 'blocked_runs'],
                    'primary_use' => 'portfolio_operating_review',
                    'view_hash' => hash('sha256', $domainId.'|executive_digital_twin'),
                ],
                [
                    'view_id' => $domainId.'_agent_work_graph',
                    'scope' => ['agents', 'owned_flows', 'handoffs', 'reviewers', 'capacity'],
                    'primary_use' => 'staffing_and_handoff_review',
                    'view_hash' => hash('sha256', $domainId.'|agent_work_graph'),
                ],
                [
                    'view_id' => $domainId.'_integration_dependency_graph',
                    'scope' => ['connectors', 'secrets', 'probes', 'failure_modes', 'fallbacks'],
                    'primary_use' => 'integration_readiness_review',
                    'view_hash' => hash('sha256', $domainId.'|integration_dependency_graph'),
                ],
                [
                    'view_id' => $domainId.'_knowledge_quality_graph',
                    'scope' => ['sources', 'work_products', 'metric_lineage', 'playbook_deltas', 'postmortems'],
                    'primary_use' => 'learning_and_quality_review',
                    'view_hash' => hash('sha256', $domainId.'|knowledge_quality_graph'),
                ],
            ],
            'drift_detection_rules' => [
                [
                    'rule_id' => 'flow_without_owner_agent',
                    'detects' => 'flow node missing owned_by_agent edge',
                    'action' => 'block_buildout_readiness_for_company',
                ],
                [
                    'rule_id' => 'connector_without_probe_plan',
                    'detects' => 'connector node missing read_only_probe edge',
                    'action' => 'block_shadow_mode_promotion',
                ],
                [
                    'rule_id' => 'metric_without_lineage',
                    'detects' => 'metric node missing source_of_record edge',
                    'action' => 'disclose_metric_as_untrusted',
                ],
                [
                    'rule_id' => 'work_product_without_quality_contract',
                    'detects' => 'work_product node missing acceptance criteria',
                    'action' => 'route_to_delivery_assurance_review',
                ],
                [
                    'rule_id' => 'cross_company_edge_without_handoff',
                    'detects' => 'relationship across company scopes without handoff contract',
                    'action' => 'block_edge_and_open_portfolio_conflict_packet',
                ],
            ],
            'graph_export_contract' => [
                'formats' => ['json_packet', 'graph_projection_table', 'operator_visualization_model'],
                'required_fields' => ['node_id', 'node_type', 'edge_id', 'source_ref', 'receipt_hash', 'last_verified_at_or_stale'],
                'operator_visualization_ready' => true,
                'raw_secret_or_sensitive_payload_export_allowed' => false,
            ],
            'graph_observability' => [
                'required_metrics' => ['node_coverage', 'edge_coverage', 'stale_edge_count', 'drift_rule_findings', 'orphan_flow_count'],
                'alert_on' => ['orphan_flow', 'unowned_connector', 'metric_without_lineage', 'cross_company_edge_without_handoff'],
            ],
            'semantic_graph_hash' => hash('sha256', $domainId.'|semantic_operating_graph|'.implode('|', array_column($nodes, 'node_id')).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function graphNodes(string $type, array $ids, string $domainId): array
    {
        return array_values(array_map(
            static fn (string $id): array => [
                'node_id' => $type.':'.$id,
                'node_type' => $type,
                'label' => $id,
                'company_id' => $domainId,
                'source_ref' => 'company_buildout_packet.'.$type,
                'node_hash' => hash('sha256', $domainId.'|graph_node|'.$type.'|'.$id),
            ],
            $ids,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseDeliveryAssuranceStack(string $domainId, array $blueprint): array
    {
        $workProducts = array_values((array) $blueprint['work_products']);
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.enterprise_delivery_assurance_stack.v1',
            'company_id' => $domainId,
            'delivery_intake_contract' => [
                'channels' => ['operator_request', 'portfolio_assignment', 'cross_company_handoff', 'scheduled_cadence'],
                'required_fields' => ['requester', 'objective', 'business_context', 'scope', 'acceptance_criteria', 'policy_profile', 'due_at_or_cadence'],
                'triage_sla' => 'next_operating_review_or_faster_for_sev2',
                'reject_when_missing_acceptance_criteria' => true,
            ],
            'work_product_delivery_contracts' => array_values(array_map(
                static fn (string $workProduct): array => [
                    'work_product' => $workProduct,
                    'package_required_sections' => ['executive_summary', 'method', 'evidence', 'decision_options', 'risks', 'handoff_notes', 'receipt_hash'],
                    'acceptance_tests' => [
                        'evidence_refs_resolve',
                        'quality_score_floor_met',
                        'policy_findings_zero',
                        'operator_or_target_company_acceptance',
                    ],
                    'support_model' => [
                        'support_window' => 'one_operating_cycle_after_delivery',
                        'revision_policy' => 'one_quality_repair_cycle_before_retriage',
                        'escalation_queue' => 'delivery_exception_queue',
                    ],
                    'delivery_hash' => hash('sha256', 'delivery_contract|'.$workProduct),
                ],
                $workProducts,
            )),
            'flow_delivery_sla' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'sla_class' => $index % 3 === 0 ? 'strategic' : ($index % 3 === 1 ? 'managed' : 'standard'),
                    'response_target' => 'same_day_internal_acknowledgement',
                    'delivery_target' => 'cadence_or_scope_dependent_with_disclosed_eta',
                    'quality_target' => [
                        'minimum_score' => 0.86,
                        'policy_findings_allowed' => 0,
                        'receipt_required' => true,
                    ],
                    'sla_hash' => hash('sha256', 'delivery_sla|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'acceptance_and_feedback_loop' => [
                'acceptance_states' => ['accepted', 'accepted_with_followup', 'needs_repair', 'rejected_with_reason'],
                'feedback_artifacts' => ['acceptance_record', 'repair_request', 'followup_request', 'learning_record'],
                'learning_cadence' => 'weekly_delivery_quality_review',
                'writes_require_review' => true,
            ],
            'delivery_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    ['delivery_cycle_time', 'acceptance_rate', 'repair_rate', 'sla_exception_count', 'handoff_acceptance_rate'],
                    array_slice($metrics, 0, 3),
                ))),
                'dashboard' => $domainId.'_delivery_assurance_board',
                'alert_on' => ['missed_sla', 'repeated_repair', 'policy_exception', 'handoff_rejected'],
            ],
            'delivery_risk_controls' => [
                'external_delivery_requires_operator_approval' => true,
                'customer_visible_claims_require_source_refs' => true,
                'regulated_or_sensitive_output_requires_redaction_review' => true,
                'external_side_effects_default' => false,
            ],
            'delivery_assurance_hash' => hash('sha256', $domainId.'|delivery_assurance|'.implode('|', $workProducts).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function portfolioFinanceStack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $metrics = array_values((array) $blueprint['metrics']);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'schema' => 'atlas.ai.company.portfolio_finance_stack.v1',
            'company_id' => $domainId,
            'budget_envelope' => [
                'mode' => 'notional_internal_budget',
                'external_spend_enabled' => false,
                'budget_drivers' => ['agent_runtime', 'tool_calls', 'review_capacity', 'integration_enablement', 'quality_repair'],
                'approval_required_for' => ['external_spend', 'paid_api_upgrade', 'production_write_access', 'capital_commitment'],
            ],
            'unit_economics_model' => [
                'cost_per_flow_run' => 'estimated_from_runtime_receipts',
                'cost_per_work_product' => 'estimated_from_flow_run_and_review_cycles',
                'value_proxy' => 'operator_accepted_work_products_and_portfolio_outcomes',
                'margin_proxy' => 'notional_value_minus_internal_cost',
                'requires_observed_metrics' => true,
            ],
            'investment_committee_packet' => [
                'required_sections' => ['thesis', 'expected_outcomes', 'resource_request', 'risks', 'dependencies', 'success_metrics', 'exit_or_pause_criteria'],
                'decision_options' => ['fund', 'fund_with_constraints', 'hold', 'pause', 'retire'],
                'operator_approval_required_for_real_capital' => true,
                'receipt_required' => true,
            ],
            'capital_allocation_gates' => [
                'green_operating_packet',
                'quality_score_floor_met',
                'policy_findings_zero',
                'dependency_handoffs_accepted',
                'rollback_or_pause_plan_present',
                'operator_signed_real_spend_mandate',
            ],
            'flow_cost_centers' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'cost_center' => 'cc_'.$flowId,
                    'primary_cost_driver' => $index % 2 === 0 ? 'analysis_runtime' : 'review_and_integration',
                    'tracking_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'flow_quality'),
                    'cost_center_hash' => hash('sha256', 'cost_center|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'resource_allocation_model' => [
                'work_products' => $workProducts,
                'allocation_method' => 'impact_confidence_effort_risk_with_policy_weight',
                'weekly_review_required' => true,
                'portfolio_governor_mode' => 'advisory_until_operator_approval',
            ],
            'finance_hash' => hash('sha256', $domainId.'|portfolio_finance_stack|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $metrics
     * @return array<string,mixed>
     */
    private function enterpriseUnitEconomicsCapacitySimulationStack(string $domainId, array $blueprint, array $workProducts, array $metrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $agents = array_values((array) $blueprint['agent_roles']);

        return [
            'schema' => 'atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1',
            'company_id' => $domainId,
            'economics_policy' => [
                'mode' => 'internal_notional_unit_economics_until_observed_revenue_or_savings',
                'synthetic_financial_claims_allowed' => false,
                'real_pricing_or_capital_commitment_requires_operator_approval' => true,
                'capacity_promotion_requires_observed_runs' => true,
                'external_spend_default' => false,
            ],
            'flow_unit_economics' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'cost_drivers' => ['agent_runtime_minutes', 'tool_calls', 'review_cycles', 'connector_probe_cost', 'repair_cycles'],
                    'value_drivers' => ['accepted_work_product', 'reduced_manual_time', 'risk_reduction', 'decision_quality', 'handoff_reuse'],
                    'primary_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'accepted_work_product_rate'),
                    'notional_unit' => 'one_governed_flow_run',
                    'requires_observed_receipts_before_claim' => true,
                    'unit_hash' => hash('sha256', 'flow_unit_economics|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'capacity_simulation_model' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'flow_id' => $flowId,
                    'simulation_inputs' => ['agent_capacity_units', 'reviewer_capacity_units', 'connector_rate_limits', 'quality_repair_rate', 'handoff_wait_time'],
                    'scenario_set' => ['current_internal', 'two_x_demand', 'review_bottleneck', 'connector_degraded', 'policy_block_spike'],
                    'bottleneck_signal' => $index % 2 === 0 ? 'review_capacity' : 'connector_or_tool_latency',
                    'promotion_gate' => 'shadow_mode_capacity_green_with_operator_review',
                    'simulation_hash' => hash('sha256', 'capacity_simulation|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'work_product_pricing_ladder' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'work_product' => $workProduct,
                    'pricing_basis' => 'notional_internal_transfer_price_until_external_offer_approved',
                    'cost_basis' => ['flow_runs', 'review_cycles', 'source_or_connector_cost', 'quality_repair'],
                    'value_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'acceptance_rate'),
                    'external_price_publication_allowed' => false,
                    'pricing_hash' => hash('sha256', 'work_product_pricing_ladder|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'agent_capacity_cost_model' => array_values(array_map(
                static fn (string $agent, int $index): array => [
                    'agent_role' => $agent,
                    'capacity_unit' => $index === 0 ? 'manager_review_slot' : 'specialist_execution_slot',
                    'cost_inputs' => ['runtime_minutes', 'context_size', 'tool_invocations', 'review_handoff_count'],
                    'utilization_target' => $agent === 'independent_reviewer_agent' ? 0.72 : 0.78,
                    'overload_action' => 'pause_lower_priority_runs_and_open_capacity_review',
                    'capacity_cost_hash' => hash('sha256', 'agent_capacity_cost|'.$agent),
                ],
                $agents,
                array_keys($agents),
            )),
            'connector_cost_and_limit_model' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'cost_controls' => ['rate_limit_budget', 'read_only_probe_budget', 'receipt_export_required', 'fallback_required'],
                    'limit_risks' => ['quota_exhaustion', 'latency_spike', 'terms_change', 'credential_rotation'],
                    'external_paid_upgrade_allowed' => false,
                    'operator_review_required_for_paid_or_write_mode' => true,
                    'connector_cost_hash' => hash('sha256', 'connector_cost_limit|'.$connector),
                ],
                $connectors,
            )),
            'investment_prioritization_model' => [
                'ranking_method' => 'impact_confidence_effort_risk_capacity_and_policy_weighted',
                'required_inputs' => ['unit_economics', 'capacity_simulation', 'quality_score', 'policy_findings', 'dependency_status'],
                'decision_options' => ['scale', 'keep', 'repair', 'pause', 'retire'],
                'real_capital_action_allowed' => false,
            ],
            'economics_observability' => [
                'required_metrics' => ['notional_cost_per_run', 'review_capacity_utilization', 'connector_cost_variance', 'accepted_value_proxy', 'bottleneck_frequency'],
                'dashboard' => $domainId.'_unit_economics_capacity_board',
                'alert_on' => ['cost_spike_without_receipt', 'review_capacity_overload', 'connector_quota_risk', 'pricing_claim_without_observed_basis'],
            ],
            'economics_capacity_hash' => hash('sha256', $domainId.'|unit_economics_capacity|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $workProducts)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function strategicIntelligenceStack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $metrics = array_values((array) $blueprint['metrics']);

        return [
            'schema' => 'atlas.ai.company.strategic_intelligence_stack.v1',
            'company_id' => $domainId,
            'market_signal_system' => [
                'signal_sources' => ['research_handoffs', 'operator_feedback', 'runtime_metrics', 'quality_exceptions', 'external_source_refs'],
                'refresh_cadence' => 'weekly_or_on_material_signal',
                'source_links_required' => true,
                'contradiction_review_required' => true,
            ],
            'competitive_benchmark_model' => [
                'benchmark_subjects' => ['best_in_class_agent_frameworks', 'domain_specific_tools', 'human_operator_baseline', 'atlas_prior_runs'],
                'dimensions' => ['quality', 'latency', 'cost', 'policy_safety', 'handoff_reliability', 'operator_acceptance'],
                'synthetic_scores_allowed' => false,
                'evidence_required' => ['benchmark_trace', 'source_ref', 'critic_review'],
            ],
            'rival_and_alternative_map' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'rivals' => [
                        'human_specialist_workflow',
                        'single_agent_baseline',
                        'manual_tool_chain',
                    ],
                    'differentiators' => ['typed_handoffs', 'receipt_hashes', 'policy_gates', 'cross_company_context'],
                    'map_hash' => hash('sha256', 'rival_map|'.$flowId),
                ],
                $flowIds,
            )),
            'roadmap' => [
                'horizon_1' => ['complete_contracts', 'green_eval_harness', 'operator_ready_packets'],
                'horizon_2' => ['sandbox_integrations', 'shadow_mode_operations', 'cross_company_automation'],
                'horizon_3' => ['supervised_external_actions', 'measured_unit_economics', 'portfolio_scale_review'],
                'roadmap_gates' => ['evidence_green', 'policy_green', 'operator_reviewed', 'rollback_ready'],
            ],
            'learning_loop' => [
                'inputs' => ['flow_evaluations', 'operator_feedback', 'incident_postmortems', 'benchmark_results', 'handoff_acceptance'],
                'outputs' => ['playbook_update', 'quality_contract_update', 'agent_registry_update', 'roadmap_delta'],
                'cadence' => 'weekly_learning_review',
                'writes_require_review' => true,
            ],
            'intelligence_kpis' => array_values(array_map(
                static fn (string $metric): array => [
                    'metric' => $metric,
                    'intelligence_use' => 'roadmap_prioritization_and_benchmark_gap_detection',
                    'minimum_evidence' => 'observed_metric_or_source_ref',
                    'kpi_hash' => hash('sha256', 'intelligence_kpi|'.$metric),
                ],
                $metrics,
            )),
            'intelligence_hash' => hash('sha256', $domainId.'|strategic_intelligence|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseGrcStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowIds = array_keys((array) $blueprint['flow_specs']);

        return [
            'schema' => 'atlas.ai.company.enterprise_grc_stack.v1',
            'company_id' => $domainId,
            'control_framework' => [
                'control_sets' => [
                    'policy_gate',
                    'evidence_ledger',
                    'source_attribution',
                    'operator_approval',
                    'rollback_or_pause_plan',
                    'least_privilege_tooling',
                ],
                'control_owner' => 'independent_reviewer_agent',
                'audit_cadence' => 'weekly_control_review',
                'exceptions_require_operator_review' => true,
            ],
            'data_classification' => [
                'classes' => ['public', 'internal', 'confidential', 'regulated_or_sensitive'],
                'default_class' => $domainId === 'personal_development' ? 'confidential' : 'internal',
                'regulated_or_sensitive_requires_redaction' => true,
                'cross_company_sharing_requires_handoff' => true,
            ],
            'privacy_and_security' => [
                'secret_handling' => 'credential_vault_only',
                'pii_policy' => 'minimize_redact_and_scope',
                'external_tool_policy' => 'read_only_or_operator_mandated',
                'security_review_required_for_new_connector' => true,
            ],
            'vendor_and_tool_risk' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'risk_tier' => str_contains($connector, 'approval') || str_contains($connector, 'gate')
                        ? 'governance'
                        : 'medium',
                    'required_assessments' => [
                        'least_privilege',
                        'data_boundary',
                        'receipt_capture',
                        'failure_mode',
                    ],
                    'status' => 'assessment_required_before_production',
                    'vendor_risk_hash' => hash('sha256', 'vendor_risk|'.$connector),
                ],
                $connectors,
            )),
            'business_continuity' => [
                'manual_fallback_required' => true,
                'degraded_mode' => 'advisory_read_only',
                'recovery_artifacts' => ['last_good_checkpoint', 'receipt_log', 'rollback_plan', 'operator_summary'],
                'rto_class' => 'next_business_day_for_internal_packets',
            ],
            'audit_evidence_requirements' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'required_evidence' => [
                        'input_hash',
                        'tool_receipts',
                        'output_hash',
                        'critic_review',
                        'policy_check',
                        'operator_checkpoint_if_external',
                    ],
                    'retention' => 'company_memory_scope_or_longer',
                    'audit_hash' => hash('sha256', 'audit_evidence|'.$flowId),
                ],
                $flowIds,
            )),
            'grc_hash' => hash('sha256', $domainId.'|enterprise_grc|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function flows(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'id' => $id,
                'domain_id' => $domainId,
                'agent_role' => (string) $spec[0],
                'connectors' => (array) $spec[1],
                'output' => (string) $spec[2],
                'execution_model' => 'durable_graph_with_handoff_and_review_checkpoint',
                'playbook_step_count' => count($this->playbookSteps($domainId, $id, $spec)),
                'required_gates' => [
                    'source_links_required',
                    'evidence_attached',
                    'policy_checked',
                    'durable_state_checkpoint',
                    'operator_review_for_external_action',
                ],
                'external_side_effects' => false,
                'flow_hash' => hash('sha256', $domainId.'|'.$id.'|'.(string) $spec[0]),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function flowPlaybooks(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'flow_id' => $id,
                'domain_id' => $domainId,
                'owner_agent' => (string) $spec[0],
                'state_model' => 'checkpointed_graph_run',
                'steps' => $this->playbookSteps($domainId, $id, $spec),
                'human_review_checkpoint' => [
                    'required_before_external_action' => true,
                    'approval_packet' => $domainId.'.'.$id.'.operator_review_packet',
                    'resume_mode' => 'resume_from_signed_checkpoint',
                ],
                'rollback' => [
                    'mode' => 'proposal_or_internal_state_revert',
                    'evidence_required' => ['before_state_hash', 'after_state_hash', 'review_receipt_hash'],
                ],
                'playbook_hash' => hash('sha256', $domainId.'|'.$id.'|playbook|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function flowExecutionContracts(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'schema' => 'atlas.ai.company.flow_execution_contract.v1',
                'flow_id' => $id,
                'domain_id' => $domainId,
                'owner_agent' => (string) $spec[0],
                'input_contract' => [
                    'required' => ['objective', 'scope', 'evidence_refs', 'policy_profile'],
                    'optional' => ['context_refs', 'constraints', 'operator_preferences'],
                    'reject_when_missing' => true,
                ],
                'output_contract' => [
                    'primary_artifact' => (string) $spec[2],
                    'required_sections' => ['summary', 'evidence', 'assumptions', 'risks', 'decision_options', 'next_actions'],
                    'receipt_required' => true,
                    'source_links_required' => true,
                ],
                'budget_envelope' => [
                    'max_runtime_seconds' => 120,
                    'max_internal_tool_calls' => 12,
                    'external_spend_allowed' => false,
                    'external_side_effects_allowed' => false,
                ],
                'evaluation_rubric' => [
                    'evidence_quality' => 0.25,
                    'domain_specificity' => 0.20,
                    'risk_coverage' => 0.20,
                    'actionability' => 0.20,
                    'policy_compliance' => 0.15,
                ],
                'benchmark_hook' => [
                    'suite' => $domainId.'.'.$id.'.enterprise_flow_benchmark',
                    'mode' => 'internal_fixture_and_rivals_ready',
                    'minimum_score' => 0.86,
                    'benchmark_hash' => hash('sha256', $domainId.'|'.$id.'|benchmark_hook'),
                ],
                'promotion_gate' => [
                    'requires_green_evaluation' => true,
                    'requires_no_policy_findings' => true,
                    'requires_operator_review_for_external_action' => true,
                    'promotion_hash' => hash('sha256', $domainId.'|'.$id.'|promotion_gate'),
                ],
                'contract_hash' => hash('sha256', $domainId.'|'.$id.'|flow_execution_contract|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function flowRuntimeBlueprints(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $id, array $spec): array => [
                'schema' => 'atlas.ai.company.flow_runtime_blueprint.v1',
                'company_id' => $domainId,
                'flow_id' => $id,
                'owner_agent' => (string) $spec[0],
                'runtime_model' => [
                    'engine' => 'durable_checkpointed_graph',
                    'state_store' => 'company_scoped_flow_state',
                    'queue' => $domainId.'.'.$id.'.runtime_queue',
                    'dead_letter_queue' => $domainId.'.'.$id.'.dlq',
                    'idempotency_key_required' => true,
                    'resume_token_required' => true,
                ],
                'state_machine' => [
                    'states' => [
                        'received',
                        'validated',
                        'context_loaded',
                        'planned',
                        'tooling_selected',
                        'executing',
                        'critic_review',
                        'operator_checkpoint',
                        'published_internal',
                        'handoff_or_done',
                        'blocked',
                        'failed',
                    ],
                    'terminal_states' => ['handoff_or_done', 'blocked', 'failed'],
                    'failure_state' => 'failed',
                    'pause_state' => 'operator_checkpoint',
                ],
                'tool_permission_matrix' => array_values(array_map(
                    static fn (string $connector): array => [
                        'connector_id' => $connector,
                        'allowed_modes' => ['read', 'analyze', 'propose'],
                        'blocked_modes' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete'],
                        'requires_receipt' => true,
                        'requires_operator_mandate_for_blocked_mode' => true,
                        'permission_hash' => hash('sha256', 'flow_tool_permission|'.$connector),
                    ],
                    (array) $spec[1],
                )),
                'retry_and_recovery' => [
                    'max_attempts' => 2,
                    'retryable_failures' => ['provider_timeout', 'read_adapter_unavailable', 'transient_parse_error'],
                    'non_retryable_failures' => ['policy_violation', 'missing_required_evidence', 'external_action_without_mandate'],
                    'recovery_path' => 'resume_from_last_green_checkpoint_or_emit_blocked_packet',
                    'manual_fallback' => 'operator_review_packet',
                ],
                'runtime_observability' => [
                    'required_events' => [
                        'flow_received',
                        'state_transition',
                        'tool_call_receipt',
                        'handoff_attempt',
                        'critic_score',
                        'policy_gate_result',
                        'operator_checkpoint_result',
                    ],
                    'required_dimensions' => ['company_id', 'flow_id', 'agent_role', 'connector_id', 'cost_uusd', 'latency_ms', 'quality_score'],
                    'trace_required' => true,
                    'receipt_hash_required' => true,
                ],
                'schema_contracts' => [
                    'input_schema' => $domainId.'.'.$id.'.input.v1',
                    'output_schema' => $domainId.'.'.$id.'.output.v1',
                    'event_schema' => $domainId.'.'.$id.'.runtime_event.v1',
                    'handoff_schema' => $domainId.'.'.$id.'.handoff.v1',
                ],
                'promotion_controls' => [
                    'shadow_mode_requires' => ['green_eval_suite', 'green_read_only_probe', 'observability_complete'],
                    'supervised_production_requires' => ['operator_mandate', 'rollback_plan', 'incident_runbook', 'cost_budget'],
                    'external_side_effects_default' => false,
                ],
                'runtime_hash' => hash('sha256', $domainId.'|flow_runtime_blueprint|'.$id.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowOrchestrationRunbookStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $referencePatterns = array_values((array) $blueprint['reference_patterns']);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_orchestration_runbook_stack.v1',
            'company_id' => $domainId,
            'orchestration_policy' => [
                'mode' => 'supervised_enterprise_agentic_flow_runtime',
                'pattern_basis' => [
                    'source_linked_financial_services_connector_model',
                    'specialist_agents_tools_handoffs_guardrails_tracing',
                    'durable_checkpointed_graph_with_human_interrupt',
                    'crew_or_flow_decomposition_for_complex_domain_work',
                ],
                'external_side_effects_default' => false,
                'operator_checkpoint_required_for_external_action' => true,
                'run_without_decision_receipt_allowed' => false,
                'claim_autonomous_operation_without_observed_runs_allowed' => false,
            ],
            'flow_runbooks' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_orchestration_runbook.v1',
                    'company_id' => $domainId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'reference_patterns' => $referencePatterns,
                    'intake_packet' => [
                        'required_fields' => ['objective', 'scope', 'stakeholder', 'policy_profile', 'evidence_refs', 'acceptance_criteria'],
                        'normalization_steps' => ['deduplicate_context', 'classify_risk', 'bind_company_memory_scope', 'compute_input_hash'],
                        'reject_when' => ['missing_policy_profile', 'missing_evidence_refs', 'ambiguous_acceptance_criteria'],
                    ],
                    'agent_graph' => [
                        'planner' => (string) $spec[0],
                        'specialists' => array_values(array_diff((array) $blueprint['agent_roles'], [(string) $spec[0]])),
                        'critic' => 'independent_reviewer_agent',
                        'operator_gate' => 'operator_approval_gate',
                        'handoff_packet_required' => true,
                    ],
                    'tool_plan' => array_values(array_map(
                        static fn (string $connector): array => [
                            'connector_id' => $connector,
                            'mode' => 'read_analyze_propose',
                            'receipt_required' => true,
                            'credential_scope' => 'least_privilege_vault_reference',
                            'blocked_without_operator_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete'],
                            'tool_hash' => hash('sha256', 'enterprise_flow_tool_plan|'.$connector),
                        ],
                        (array) $spec[1],
                    )),
                    'checkpoint_lattice' => [
                        'checkpoints' => ['intake', 'context_loaded', 'plan_accepted', 'tool_receipts_captured', 'critic_reviewed', 'operator_checkpointed', 'handoff_or_done'],
                        'resume_strategy' => 'resume_from_last_green_checkpoint_with_state_hash',
                        'interrupt_strategy' => 'pause_emit_operator_packet_and_preserve_run_state',
                        'rollback_strategy' => 'revert_internal_state_or_emit_compensation_plan',
                    ],
                    'evaluation_and_acceptance' => [
                        'quality_floor' => 0.86,
                        'policy_findings_allowed' => 0,
                        'required_scores' => ['evidence_quality', 'domain_specificity', 'risk_coverage', 'actionability', 'handoff_quality'],
                        'acceptance_artifacts' => ['output_artifact', 'critic_review', 'policy_gate_result', 'tool_receipts', 'receipt_hash'],
                    ],
                    'handoff_and_delivery' => [
                        'output_artifact' => (string) $spec[2],
                        'handoff_schema' => $domainId.'.'.$flowId.'.enterprise_handoff.v1',
                        'delivery_modes' => ['internal_packet', 'cross_company_handoff', 'operator_review_queue'],
                        'external_delivery_requires_operator_approval' => true,
                    ],
                    'observability_contract' => [
                        'trace_spans' => ['intake', 'plan', 'retrieve', 'tool_call', 'analysis', 'critic', 'operator_checkpoint', 'delivery'],
                        'metrics' => ['run_success_rate', 'quality_score', 'policy_finding_count', 'tool_receipt_coverage', 'checkpoint_resume_rate', 'handoff_acceptance_rate'],
                        'event_schema' => $domainId.'.'.$flowId.'.enterprise_orchestration_event.v1',
                    ],
                    'runbook_hash' => hash('sha256', $domainId.'|enterprise_flow_orchestration_runbook|'.$flowId.'|'.json_encode($spec)),
                ],
                array_keys($flowSpecs),
                $flowSpecs,
            )),
            'shared_connector_backplane' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'backplane_role' => 'shared_company_connector_with_receipts_and_rate_limits',
                    'health_probe_required_before_run' => true,
                    'receipt_export_required' => true,
                    'rate_limit_policy' => 'queue_or_degrade_to_manual_import_before_failure',
                    'backplane_hash' => hash('sha256', 'enterprise_flow_connector_backplane|'.$connector),
                ],
                $connectors,
            )),
            'runbook_observability' => [
                'required_metrics' => ['runbook_coverage', 'tool_receipt_coverage', 'checkpoint_resume_rate', 'operator_interrupt_rate', 'handoff_acceptance_rate', 'policy_findings'],
                'dashboard' => $domainId.'_enterprise_flow_orchestration_board',
                'alert_on' => ['missing_receipt', 'checkpoint_resume_failed', 'policy_finding', 'handoff_rejected'],
            ],
            'orchestration_runbook_hash' => hash('sha256', $domainId.'|enterprise_flow_orchestration_runbook_stack|'.implode('|', array_keys($flowSpecs)).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowRuntimeImplementationStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $agents = array_values((array) $blueprint['agent_roles']);
        $sourceCatalog = $this->flowRuntimeImplementationSourceCatalog();

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_runtime_implementation_stack.v1',
            'company_id' => $domainId,
            'implementation_policy' => [
                'mode' => 'contract_to_shadow_to_supervised_runtime',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_autonomy_claim' => true,
                'ungoverned_external_side_effects_allowed' => false,
                'operator_checkpoint_required_before_write_publish_spend_trade_deploy_delete' => true,
                'runtime_basis' => [
                    'agent_tools_handoffs_guardrails_tracing',
                    'durable_execution_checkpoint_resume',
                    'stateful_flow_orchestration_with_specialist_crews',
                    'financial_services_source_verification_audit_trail_pattern',
                ],
            ],
            'source_catalog' => $sourceCatalog,
            'executable_flow_packets' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.executable_flow_packet.v1',
                    'company_id' => $domainId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'runtime_adapter' => $domainId.'.'.$flowId.'.runtime_adapter',
                    'input_contract' => [
                        'schema' => $domainId.'.'.$flowId.'.input.v1',
                        'required_fields' => ['objective', 'scope', 'risk_profile', 'context_refs', 'acceptance_criteria', 'idempotency_key'],
                        'normalizers' => ['context_pack_binding', 'policy_profile_resolution', 'connector_scope_resolution', 'input_hash'],
                        'reject_when' => ['missing_acceptance_criteria', 'missing_risk_profile', 'connector_scope_uncertified'],
                    ],
                    'execution_graph' => [
                        'nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                        'durability' => 'checkpoint_after_every_node_and_tool_receipt',
                        'resume_token_required' => true,
                        'idempotency_required' => true,
                        'human_interrupt_points' => ['policy_gate', 'operator_checkpoint', 'external_action_request'],
                    ],
                    'output_contract' => [
                        'schema' => $domainId.'.'.$flowId.'.output.v1',
                        'artifact_type' => (string) $spec[2],
                        'required_sections' => ['executive_summary', 'source_lineage', 'analysis', 'risks', 'recommendation', 'next_actions', 'receipt_hash'],
                        'quality_floor' => 0.88,
                        'policy_findings_allowed' => 0,
                    ],
                    'runtime_state_contract' => [
                        'state_objects' => ['intent_packet', 'context_bundle', 'tool_receipts', 'intermediate_artifacts', 'critic_review', 'policy_gate_result', 'delivery_packet'],
                        'state_hash_required' => true,
                        'replayable_without_external_mutation' => true,
                    ],
                    'packet_hash' => hash('sha256', $domainId.'|executable_flow_packet|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'agent_tool_routing_matrix' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'backup_agents' => array_values(array_diff($agents, [(string) $spec[0]])),
                    'connectors' => array_values((array) $spec[1]),
                    'routing_rules' => ['least_privilege_connector_scope', 'specialist_handoff_before_domain_specific_claim', 'critic_reviews_before_delivery'],
                    'blocked_routes' => ['direct_external_write', 'direct_paid_action', 'direct_customer_visible_commitment', 'direct_live_trade_or_offensive_security'],
                    'routing_hash' => hash('sha256', $domainId.'|agent_tool_routing|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_artifact_io_contracts' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'input_schema' => $flowId.'.request.v1',
                    'output_schema' => $flowId.'.'.(string) $spec[2].'.v1',
                    'receipt_schema' => $flowId.'.receipt.v1',
                    'required_lineage' => ['input_hash', 'source_refs', 'connector_receipts', 'transform_hash', 'critic_hash', 'output_hash'],
                    'redaction_before_provider_payload' => true,
                    'contract_hash' => hash('sha256', 'flow_artifact_io_contract|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'supervision_and_shadow_runtime_gates' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'contract_stage_requires' => ['executable_flow_packet_present', 'artifact_io_contract_present', 'connector_certification_present'],
                    'shadow_stage_requires' => ['offline_replay_green', 'sandbox_probe_green', 'policy_scan_green', 'operator_review_queue_bound'],
                    'supervised_stage_requires' => ['operator_signed_mandate', 'rollback_or_manual_fallback', 'observability_dashboard_green', 'incident_route_defined'],
                    'blocked_until_production_acceptance' => ['autonomy_claim', 'ungoverned_external_side_effects', 'self_promotion_to_production'],
                    'gate_hash' => hash('sha256', 'supervision_shadow_runtime_gate|'.$flowId),
                ],
                $flowIds,
            )),
            'connector_runtime_adapters' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_state' => 'contract_defined_probe_required_before_shadow',
                    'supported_operations' => ['read', 'analyze', 'propose', 'export_receipt'],
                    'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                    'adapter_requirements' => ['openapi_or_mcp_schema', 'vault_reference', 'rate_limit_budget', 'mock_fixture', 'receipt_export'],
                    'adapter_hash' => hash('sha256', 'connector_runtime_adapter|'.$connector),
                ],
                $connectors,
            )),
            'runtime_event_and_outbox_contract' => [
                'event_schema' => $domainId.'.enterprise_flow_runtime_event.v1',
                'outbox_required_for' => ['flow_started', 'checkpoint_committed', 'tool_receipt_captured', 'critic_completed', 'policy_gate_completed', 'operator_checkpoint_requested', 'flow_completed'],
                'dead_letter_queue' => $domainId.'_enterprise_flow_runtime_dlq',
                'replay_key_fields' => ['company_id', 'flow_id', 'idempotency_key', 'state_hash', 'receipt_hash'],
                'event_hash' => hash('sha256', $domainId.'|runtime_event_outbox|'.implode('|', $flowIds)),
            ],
            'implementation_observability' => [
                'required_metrics' => ['flow_packet_coverage', 'runtime_adapter_coverage', 'checkpoint_commit_rate', 'tool_receipt_capture_rate', 'critic_review_pass_rate', 'policy_gate_pass_rate', 'operator_checkpoint_latency', 'shadow_replay_pass_rate'],
                'dashboard' => $domainId.'_enterprise_runtime_implementation_board',
                'alert_on' => ['missing_checkpoint', 'missing_receipt', 'policy_gate_failure', 'connector_scope_uncertified', 'shadow_replay_failure'],
            ],
            'implementation_hash' => hash('sha256', $domainId.'|enterprise_flow_runtime_implementation|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function flowRuntimeImplementationSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'source_verified_financial_services_agents_with_audit_trails'],
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://platform.openai.com/docs/guides/agents-sdk/', 'pattern' => 'tools_handoffs_guardrails_tracing_for_agentic_applications'],
            ['source_id' => 'crewai_flows', 'url' => 'https://docs.crewai.com/en/concepts/flows', 'pattern' => 'stateful_multi_step_flow_orchestration_with_crews'],
            ['source_id' => 'langgraph_durable_execution', 'url' => 'https://langchain-5e9cc07a.mintlify.app/oss/python/langgraph/durable-execution', 'pattern' => 'durable_execution_checkpoints_and_human_interrupts'],
            ['source_id' => 'temporal_durable_execution', 'url' => 'https://docs.temporal.io/evaluate/development-production-features/durable-execution', 'pattern' => 'durable_workflow_replay_and_failure_recovery'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'adoption_boundary' => 'pattern_reference_until_local_contract_tests_and_operator_review',
                'evidence_required_before_adoption' => ['source_review', 'license_or_terms_review', 'security_review', 'local_replay_result', 'operator_acceptance'],
                'source_hash' => hash('sha256', 'flow_runtime_implementation_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowFixtureSimulationStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $commandBase = $this->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_fixture_simulation_stack.v1',
            'company_id' => $domainId,
            'simulation_policy' => [
                'mode' => 'offline_contract_fixture_and_shadow_simulation_before_runtime_use',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_external_autonomy_claim' => true,
                'fixtures_required_before_shadow_mode' => true,
                'external_side_effects_allowed_in_simulation' => false,
                'operator_approval_required_to_promote_fixture_to_shadow' => true,
            ],
            'canonical_flow_fixtures' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.canonical_flow_fixture.v1',
                    'fixture_id' => $domainId.'.'.$flowId.'.fixture.'.($index + 1),
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'input_packet' => [
                        'objective' => 'produce_'.$domainId.'_'.$flowId.'_enterprise_artifact',
                        'scope' => 'offline_fixture_no_external_mutation',
                        'risk_profile' => 'r2_internal_enterprise_review',
                        'context_refs' => ['company_packet', 'domain_source_catalog', 'connector_contracts'],
                        'acceptance_criteria' => ['source_lineage_present', 'tool_receipts_present', 'policy_findings_zero', 'quality_score_at_or_above_floor'],
                    ],
                    'expected_output' => [
                        'artifact_type' => (string) $spec[2],
                        'required_sections' => ['executive_summary', 'evidence', 'analysis', 'risk_review', 'recommendation', 'next_actions'],
                        'minimum_quality_score' => 0.88,
                    ],
                    'fixture_hash' => hash('sha256', $domainId.'|canonical_flow_fixture|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_stub_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'stub_id' => $connector.'.offline_stub.v1',
                    'stub_modes' => ['golden_response', 'empty_response', 'permission_denied', 'rate_limited', 'schema_drift'],
                    'must_emit' => ['connector_receipt_hash', 'latency_ms', 'source_ref_or_stub_ref', 'no_external_mutation_attestation'],
                    'external_side_effects' => false,
                    'stub_hash' => hash('sha256', 'connector_offline_stub|'.$connector),
                ],
                $connectors,
            )),
            'expected_trace_trajectories' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'expected_nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                    'expected_tools' => array_values((array) $spec[1]),
                    'required_trace_fields' => ['flow_id', 'agent_role', 'input_hash', 'tool_call_receipts', 'critic_score', 'policy_findings', 'output_hash'],
                    'must_not_include' => ['external_write_call', 'paid_action_call', 'live_trade_call', 'offensive_security_call'],
                    'trajectory_hash' => hash('sha256', 'expected_trace_trajectory|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'quality_assertion_suites' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'assertion_suite' => $flowId.'.quality_assertions.v1',
                    'assertions' => ['output_schema_valid', 'source_lineage_present', 'tool_receipt_coverage_full', 'critic_review_present', 'policy_findings_zero', 'handoff_packet_valid'],
                    'failure_action' => 'block_shadow_mode_open_flow_fixture_review',
                    'assertion_hash' => hash('sha256', 'flow_quality_assertion_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'failure_injection_cases' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'case_suite' => $flowId.'.failure_injection.v1',
                    'cases' => ['missing_context_ref', 'connector_permission_denied', 'connector_schema_drift', 'conflicting_evidence', 'operator_rejects_checkpoint'],
                    'expected_behavior' => 'fail_closed_preserve_checkpoint_and_emit_review_packet',
                    'must_not_do' => ['invent_source', 'skip_policy_gate', 'mutate_external_system', 'self_approve'],
                    'case_hash' => hash('sha256', 'flow_failure_injection|'.$flowId),
                ],
                $flowIds,
            )),
            'dry_run_command_plan' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'command' => 'php artisan '.$commandBase.' --action=smoke --json',
                    'future_flow_specific_command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                    'expected_exit_code' => 0,
                    'required_output_keys' => ['ok', 'schema', 'status'],
                    'dry_run_hash' => hash('sha256', $domainId.'|flow_dry_run_command|'.$flowId),
                ],
                $flowIds,
            )),
            'simulation_promotion_gates' => [
                'contract_ready_requires' => ['canonical_fixture_present', 'connector_stubs_present', 'expected_trace_present'],
                'shadow_ready_requires' => ['quality_assertions_green', 'failure_injection_green', 'dry_run_green', 'operator_review_accepted'],
                'supervised_ready_requires' => ['connector_certification_green', 'runtime_observability_green', 'rollback_or_manual_fallback_present'],
                'autonomy_claim_requires_current_operational_evidence' => true,
            ],
            'simulation_observability' => [
                'required_metrics' => ['fixture_coverage', 'connector_stub_coverage', 'trace_match_rate', 'quality_assertion_pass_rate', 'failure_injection_pass_rate', 'dry_run_pass_rate'],
                'dashboard' => $domainId.'_enterprise_flow_fixture_simulation_board',
                'alert_on' => ['fixture_missing', 'trace_mismatch', 'quality_assertion_failure', 'failure_injection_regression', 'dry_run_failure'],
            ],
            'simulation_stack_hash' => hash('sha256', $domainId.'|enterprise_flow_fixture_simulation|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowActionRuntimeStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $commandBase = $this->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_action_runtime_stack.v1',
            'company_id' => $domainId,
            'action_runtime_policy' => [
                'mode' => 'registered_fixture_to_shadow_action_runtime',
                'buildout_blocked_by_observed_history_window' => false,
                'current_operational_evidence_required_for_external_autonomy_claim' => true,
                'flow_specific_actions_required_before_supervised_runtime' => true,
                'unregistered_action_execution_allowed' => false,
                'external_side_effects_allowed_by_default' => false,
                'operator_checkpoint_required_for_external_mutation' => true,
            ],
            'runtime_action_catalog' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.runtime_action_contract.v1',
                    'flow_id' => $flowId,
                    'action' => $flowId,
                    'command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                    'fallback_command' => 'php artisan '.$commandBase.' --action=smoke --json',
                    'handler_contract' => [
                        'entrypoint' => $commandBase,
                        'input_schema' => $domainId.'.'.$flowId.'.input.v1',
                        'output_schema' => $domainId.'.'.$flowId.'.output.v1',
                        'receipt_schema' => $domainId.'.'.$flowId.'.receipt.v1',
                        'idempotency_key_required' => true,
                        'resume_token_required' => true,
                    ],
                    'runtime_phases' => ['validate_input', 'load_context', 'bind_fixture_or_shadow_connector', 'execute_domain_action', 'critic_review', 'policy_gate', 'operator_checkpoint', 'emit_delivery_packet'],
                    'external_side_effects' => false,
                    'action_hash' => hash('sha256', $domainId.'|runtime_action_contract|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'command_adapter_matrix' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'command_base' => $commandBase,
                    'action_argument' => $flowId,
                    'fixture_mode_argument' => '--fixture',
                    'connector_bindings' => array_values((array) $spec[1]),
                    'required_before_handler_invocation' => ['input_schema_valid', 'connector_scope_certified', 'fixture_or_shadow_mode_declared', 'policy_profile_bound'],
                    'blocked_without_operator' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
                    'adapter_hash' => hash('sha256', $domainId.'|command_adapter_matrix|'.$flowId.'|'.implode('|', (array) $spec[1])),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'handler_state_schemas' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'state_schema' => $flowId.'.runtime_state.v1',
                    'required_state_keys' => ['intent_packet', 'context_refs', 'connector_receipts', 'artifact_draft', 'critic_review', 'policy_gate_result', 'operator_checkpoint', 'delivery_packet'],
                    'output_artifact' => (string) $spec[2],
                    'checkpoint_after' => ['validate_input', 'load_context', 'tool_call', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint'],
                    'state_hash_required' => true,
                    'schema_hash' => hash('sha256', 'handler_state_schema|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'runtime_event_emission_plan' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'event_schema' => $flowId.'.runtime_action_event.v1',
                    'required_events' => ['action_requested', 'input_validated', 'context_loaded', 'connector_bound', 'artifact_produced', 'critic_reviewed', 'policy_checked', 'operator_checkpointed', 'action_completed_or_blocked'],
                    'outbox_topic' => $flowId.'.runtime_action_outbox',
                    'dlq_topic' => $flowId.'.runtime_action_dlq',
                    'event_hash' => hash('sha256', 'runtime_event_emission_plan|'.$flowId),
                ],
                $flowIds,
            )),
            'operator_checkpoint_contracts' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'checkpoint_schema' => $flowId.'.operator_checkpoint.v1',
                    'checkpoint_required_for' => ['external_action_request', 'policy_exception', 'low_confidence_critic_score', 'connector_permission_expansion'],
                    'operator_packet_fields' => ['request_summary', 'risk_profile', 'evidence_refs', 'proposed_action', 'rollback_plan', 'receipt_hash'],
                    'auto_approval_allowed' => false,
                    'checkpoint_hash' => hash('sha256', 'operator_checkpoint_contract|'.$flowId),
                ],
                $flowIds,
            )),
            'action_runtime_promotion_gates' => [
                'contract_ready_requires' => ['runtime_action_catalog_complete', 'handler_state_schema_present', 'command_adapter_bound'],
                'fixture_ready_requires' => ['canonical_fixture_green', 'event_emission_plan_green', 'operator_checkpoint_contract_present'],
                'shadow_ready_requires' => ['dry_run_command_green', 'connector_stub_contract_green', 'quality_assertions_green'],
                'supervised_ready_requires' => ['operator_signed_mandate', 'observability_dashboard_green', 'rollback_plan_present'],
                'autonomy_claim_requires_current_operational_evidence' => true,
            ],
            'action_runtime_observability' => [
                'required_metrics' => ['runtime_action_coverage', 'command_adapter_coverage', 'handler_state_schema_coverage', 'runtime_event_emission_rate', 'checkpoint_packet_coverage', 'dry_run_action_pass_rate', 'operator_checkpoint_latency'],
                'dashboard' => $domainId.'_enterprise_flow_action_runtime_board',
                'alert_on' => ['unregistered_action_request', 'missing_state_hash', 'event_outbox_failure', 'operator_checkpoint_missing', 'dry_run_action_failure'],
            ],
            'action_runtime_hash' => hash('sha256', $domainId.'|enterprise_flow_action_runtime|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<int,mixed> $spec
     * @return list<array<string,mixed>>
     */
    private function playbookSteps(string $domainId, string $flowId, array $spec): array
    {
        $connectors = array_values((array) ($spec[1] ?? []));
        $agent = (string) ($spec[0] ?? 'company_operator');
        $output = (string) ($spec[2] ?? 'review_packet');

        return [
            $this->playbookStep($domainId, $flowId, 1, 'intake_and_scope', $agent, [], 'scope_packet'),
            $this->playbookStep($domainId, $flowId, 2, 'retrieve_context', $agent, $connectors, 'context_bundle'),
            $this->playbookStep($domainId, $flowId, 3, 'plan_and_assign', $agent, ['agent_collaboration_model'], 'task_graph'),
            $this->playbookStep($domainId, $flowId, 4, 'execute_internal_analysis', $agent, $connectors, $output),
            $this->playbookStep($domainId, $flowId, 5, 'critic_review', 'independent_reviewer_agent', ['evidence_ledger'], 'review_findings'),
            $this->playbookStep($domainId, $flowId, 6, 'operator_checkpoint', 'operator_approval_gate', ['decision_receipt'], 'signed_review_packet'),
            $this->playbookStep($domainId, $flowId, 7, 'publish_internal_packet', $agent, ['evidence_ledger'], $output),
        ];
    }

    /**
     * @param list<string> $connectors
     * @return array<string,mixed>
     */
    private function playbookStep(
        string $domainId,
        string $flowId,
        int $sequence,
        string $step,
        string $agent,
        array $connectors,
        string $output,
    ): array {
        return [
            'sequence' => $sequence,
            'step' => $step,
            'agent_role' => $agent,
            'connectors' => $connectors,
            'output' => $output,
            'required_evidence' => ['state_hash', 'source_or_input_refs', 'step_receipt_hash'],
            'external_side_effects' => false,
            'step_hash' => hash('sha256', $domainId.'|'.$flowId.'|'.$sequence.'|'.$step.'|'.$agent),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function agentCollaborationModel(string $domainId, array $blueprint): array
    {
        $agents = array_values((array) $blueprint['agent_roles']);

        return [
            'domain_id' => $domainId,
            'orchestration' => 'manager_with_specialist_handoffs_and_independent_reviewer',
            'patterns' => [
                'planner_assigns_specialists',
                'specialists_emit_typed_work_products',
                'critic_reviews_before_operator_checkpoint',
                'operator_checkpoint_required_for_external_action',
                'run_hooks_capture_agent_tool_handoff_events',
            ],
            'manager_agent' => (string) ($agents[0] ?? 'company_manager_agent'),
            'specialist_agents' => array_slice($agents, 1),
            'review_agent' => 'independent_reviewer_agent',
            'handoff_contract' => [
                'requires_handoff_description' => true,
                'requires_input_schema' => true,
                'requires_output_schema' => true,
                'handoff_hash' => hash('sha256', $domainId.'|agent_collaboration|'.implode('|', $agents)),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function enterpriseCapabilityMatrix(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (string $flowId, array $spec): array => [
                'schema' => 'atlas.ai.company.enterprise_capability_matrix_row.v1',
                'company_id' => $domainId,
                'flow_id' => $flowId,
                'owner_agent' => (string) $spec[0],
                'capability_stages' => [
                    'sense' => [
                        'connectors' => (array) $spec[1],
                        'evidence_required' => ['source_refs', 'input_state_hash'],
                    ],
                    'reason' => [
                        'mode' => 'domain_specialist_with_manager_review',
                        'artifacts' => ['assumption_log', 'option_set', 'risk_register_delta'],
                    ],
                    'produce' => [
                        'primary_output' => (string) $spec[2],
                        'contract' => $domainId.'.'.$flowId.'.output_contract',
                    ],
                    'verify' => [
                        'critic_agent' => 'independent_reviewer_agent',
                        'minimum_score' => 0.86,
                        'policy_findings_allowed' => 0,
                    ],
                    'handoff' => [
                        'typed_handoff_packet' => true,
                        'target_acceptance_required' => true,
                        'receipt_hash_required' => true,
                    ],
                ],
                'enterprise_readiness' => 'structure_complete',
                'external_side_effects' => false,
                'matrix_hash' => hash('sha256', $domainId.'|capability_matrix|'.$flowId.'|'.json_encode($spec)),
            ],
            array_keys((array) $blueprint['flow_specs']),
            (array) $blueprint['flow_specs'],
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function externalIntegrationCatalog(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $connector): array => [
                'schema' => 'atlas.ai.company.external_integration_contract.v1',
                'company_id' => $domainId,
                'connector_id' => $connector,
                'adapter_surface' => str_contains($connector, 'approval') || str_contains($connector, 'gate')
                    ? 'governance_gate'
                    : 'mcp_or_api_adapter',
                'current_mode' => 'contract_ready_read_or_internal_only',
                'production_enablement_requirements' => [
                    'credential_vault_binding',
                    'least_privilege_scope',
                    'sandbox_or_read_only_probe',
                    'receipt_capture',
                    'operator_signed_side_effect_mandate',
                ],
                'blocked_until_requirements_met' => ['write', 'publish', 'spend', 'trade', 'deploy', 'mutate_infrastructure'],
                'integration_hash' => hash('sha256', $domainId.'|external_integration|'.$connector),
            ],
            $this->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseConnectorCertificationStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_connector_certification_stack.v1',
            'company_id' => $domainId,
            'certification_policy' => [
                'mode' => 'contract_probe_certified_before_shadow_or_supervised_use',
                'contract_standard' => 'openapi_or_mcp_tool_schema_with_consumer_driven_contract_tests',
                'write_or_paid_mode_allowed_by_default' => false,
                'production_promotion_without_green_probe_allowed' => false,
                'operator_approval_required_for_write_publish_spend_trade_delete_or_secret_scope_expansion' => true,
            ],
            'source_catalog' => $this->connectorCertificationSourceCatalog(),
            'adapter_contract_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_contract_id' => $connector.'.adapter_contract.v1',
                    'supported_contract_forms' => ['openapi', 'mcp_tool_schema', 'manual_import_schema', 'internal_read_model_schema'],
                    'required_contract_fields' => ['operation_id', 'input_schema', 'output_schema', 'auth_scope', 'rate_limit_policy', 'error_model'],
                    'schema_validation_required' => true,
                    'contract_hash' => hash('sha256', 'connector_adapter_contract|'.$connector),
                ],
                $connectors,
            )),
            'auth_and_secret_boundary' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'credential_binding' => 'vault_reference_only',
                    'minimum_scope' => 'read_or_internal_probe',
                    'token_policy' => ['short_lived_if_supported', 'rotation_record_required', 'least_privilege', 'revocation_path_documented'],
                    'blocked_scope_expansions_without_operator' => ['write', 'publish', 'spend', 'trade', 'delete', 'admin', 'secret_export'],
                    'secret_material_in_packet_allowed' => false,
                    'auth_hash' => hash('sha256', 'connector_auth_secret_boundary|'.$connector),
                ],
                $connectors,
            )),
            'sandbox_probe_matrix' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'probe_id' => $connector.'.sandbox_probe.v1',
                    'probe_modes' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
                    'success_criteria' => ['contract_resolves', 'auth_scope_matches', 'no_external_mutation', 'latency_within_budget', 'receipt_hash_emitted'],
                    'failure_action' => 'block_connector_and_open_integration_review',
                    'probe_hash' => hash('sha256', 'connector_sandbox_probe|'.$connector),
                ],
                $connectors,
            )),
            'consumer_provider_contract_tests' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'test_suite' => $connector.'.consumer_provider_contract_tests.v1',
                    'consumer_assumptions' => ['required_fields_present', 'stable_error_shape', 'pagination_or_batching_disclosed', 'idempotency_semantics_documented'],
                    'provider_verification' => ['response_matches_schema', 'status_code_contract', 'rate_limit_contract', 'permission_denial_contract'],
                    'deployment_gate' => 'cannot_promote_connector_until_contract_verified',
                    'test_hash' => hash('sha256', 'connector_contract_tests|'.$connector),
                ],
                $connectors,
            )),
            'connector_data_mapping_and_lineage' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'mapping_id' => $connector.'.data_mapping.v1',
                    'canonical_entities' => ['source_record', 'normalized_record', 'tool_receipt', 'metric_observation', 'work_product_reference'],
                    'lineage_required' => ['source_uri_or_record_id', 'retrieved_at_or_version', 'transform_hash', 'consumer_flow_id', 'output_hash'],
                    'redaction_required_before_provider_payload' => true,
                    'mapping_hash' => hash('sha256', 'connector_data_mapping|'.$connector),
                ],
                $connectors,
            )),
            'flow_connector_usage_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'connectors' => array_values((array) $spec[1]),
                    'allowed_modes' => ['read', 'analyze', 'propose'],
                    'blocked_modes' => ['write', 'publish', 'spend', 'trade', 'delete'],
                    'pre_run_requirements' => ['adapter_contract_green', 'auth_boundary_green', 'sandbox_probe_green', 'rate_limit_budget_available'],
                    'usage_hash' => hash('sha256', 'flow_connector_usage|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'replay_fixture_and_mock_server_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'fixture_id' => $connector.'.replay_fixture.v1',
                    'fixture_requirements' => ['golden_response', 'error_response', 'rate_limit_response', 'permission_denied_response', 'stale_data_response'],
                    'mock_or_stub_modes' => ['contract_fixture', 'sandbox_fixture', 'manual_import_fixture'],
                    'required_for_offline_eval' => true,
                    'fixture_hash' => hash('sha256', 'connector_replay_fixture|'.$connector),
                ],
                $connectors,
            )),
            'connector_slo_and_failure_mode_catalog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'slo' => ['availability_probe_success_rate' => 0.95, 'receipt_export_rate' => 1.0, 'schema_match_rate' => 1.0],
                    'failure_modes' => ['auth_expired', 'schema_drift', 'rate_limit', 'permission_denied', 'provider_outage', 'terms_change'],
                    'fallback' => 'degrade_to_manual_import_or_cached_read_model_with_staleness_disclosure',
                    'slo_hash' => hash('sha256', 'connector_slo_failure|'.$connector),
                ],
                $connectors,
            )),
            'certification_promotion_gates' => [
                'contract_ready_requires' => ['adapter_contract_catalogued', 'source_terms_reviewed', 'auth_boundary_defined'],
                'sandbox_requires' => ['sandbox_probe_green', 'fixture_pack_present', 'contract_tests_green'],
                'shadow_requires' => ['flow_usage_matrix_bound', 'replay_eval_green', 'observability_green'],
                'supervised_production_requires' => ['operator_mandate', 'rollback_or_manual_fallback', 'incident_route', 'cost_budget'],
                'external_write_or_paid_mode_requires_operator_approval' => true,
            ],
            'connector_certification_observability' => [
                'required_metrics' => ['adapter_contract_coverage', 'sandbox_probe_success_rate', 'contract_test_pass_rate', 'schema_drift_count', 'auth_scope_exception_count', 'receipt_export_rate'],
                'dashboard' => $domainId.'_connector_certification_board',
                'alert_on' => ['schema_drift', 'auth_scope_exception', 'probe_failure', 'contract_test_failure', 'write_mode_request'],
            ],
            'connector_certification_hash' => hash('sha256', $domainId.'|enterprise_connector_certification|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseProductionConnectorPreflightStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_production_connector_preflight_stack.v1',
            'company_id' => $domainId,
            'preflight_policy' => [
                'mode' => 'production_connector_cutover_preflight_without_auto_execution',
                'calendar_wait_blocker_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'real_credential_material_in_packet_allowed' => false,
                'external_side_effects_default' => false,
                'manual_execution_handoff_only_after_signed_mandate' => true,
            ],
            'connector_preflight_contracts' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_production_preflight_contract.v1',
                    'connector_id' => $connector,
                    'credential_vault_binding' => [
                        'binding_mode' => 'vault_reference_required_no_secret_material',
                        'attestation_required' => true,
                        'rotation_policy_required' => true,
                        'revocation_path_required' => true,
                        'credential_material_in_packet_allowed' => false,
                    ],
                    'scope_contract' => [
                        'minimum_scope' => 'read_only_or_fixture',
                        'production_scope_requires_operator_and_second_reviewer' => true,
                        'blocked_scope_without_signed_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                    ],
                    'live_data_readiness' => [
                        'schema_snapshot_required' => true,
                        'sample_record_fixture_required' => true,
                        'freshness_slo_required' => true,
                        'source_lineage_required' => true,
                        'live_mutation_allowed' => false,
                    ],
                    'non_production_dress_rehearsal' => [
                        'required' => true,
                        'must_emit' => ['probe_receipt_hash', 'latency_ms', 'schema_match', 'permission_scope_match', 'no_external_mutation_attestation'],
                        'promotion_requires_green_rehearsal' => true,
                    ],
                    'cost_and_rate_limit_envelope' => [
                        'budget_cap_required_for_paid_api' => true,
                        'rate_limit_policy_required' => true,
                        'burst_behavior_required' => true,
                        'spend_without_cap_allowed' => false,
                    ],
                    'rollback_and_fallback' => [
                        'manual_fallback_required' => true,
                        'disable_switch_required' => true,
                        'last_good_fixture_required' => true,
                        'rollback_drill_required_before_external_mutation' => true,
                    ],
                    'preflight_hash' => hash('sha256', 'production_connector_preflight|'.$connector),
                ],
                $connectors,
            )),
            'flow_connector_cutover_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'required_cutover_evidence' => [
                        'connector_certification_green',
                        'production_preflight_contract_green',
                        'vault_binding_attested',
                        'read_only_or_sandbox_probe_green',
                        'operator_signed_scope',
                        'rollback_drill_green',
                    ],
                    'manual_handoff_packet_required' => true,
                    'auto_execute_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'cutover_hash' => hash('sha256', 'flow_connector_cutover|'.$flowId.'|'.implode('|', (array) $spec[1])),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'production_readiness_evidence_register' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'required_evidence' => [
                        'adapter_contract_hash',
                        'auth_boundary_hash',
                        'sandbox_probe_receipt_hash',
                        'consumer_provider_contract_hash',
                        'lineage_attestation_hash',
                        'slo_failure_mode_hash',
                        'vault_scope_attestation_hash',
                        'operator_mandate_hash',
                    ],
                    'current_state' => 'preflight_contract_ready_external_execution_blocked',
                    'missing_before_real_execution' => ['real_vault_binding', 'signed_production_scope', 'manual_execution_owner'],
                    'external_side_effects_enabled' => false,
                    'evidence_register_hash' => hash('sha256', 'production_readiness_evidence|'.$connector),
                ],
                $connectors,
            )),
            'cutover_observability' => [
                'required_metrics' => ['preflight_contract_coverage', 'vault_binding_attestation_rate', 'dress_rehearsal_pass_rate', 'rollback_drill_pass_rate', 'signed_scope_coverage', 'manual_handoff_readiness'],
                'dashboard' => $domainId.'_production_connector_preflight_board',
                'alert_on' => ['credential_scope_missing', 'signed_scope_missing', 'paid_api_without_budget_cap', 'write_scope_requested_without_mandate', 'rollback_drill_missing'],
            ],
            'production_connector_preflight_hash' => hash('sha256', $domainId.'|production_connector_preflight|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<string>
     */
    private function enterpriseConnectorsForBlueprint(array $blueprint): array
    {
        $connectors = array_values(array_filter((array) ($blueprint['connectors'] ?? []), 'is_string'));

        foreach ((array) ($blueprint['flow_specs'] ?? []) as $spec) {
            foreach ((array) ($spec[1] ?? []) as $connector) {
                if (is_string($connector) && $connector !== '') {
                    $connectors[] = $connector;
                }
            }
        }

        return array_values(array_unique($connectors));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function connectorCertificationSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'openapi_initiative', 'url' => 'https://www.openapis.org/', 'pattern' => 'portable_vendor_neutral_api_contract_metadata'],
            ['source_id' => 'model_context_protocol_tools', 'url' => 'https://modelcontextprotocol.info/specification/2024-11-05/server/tools', 'pattern' => 'tool_schema_for_language_model_invocable_connectors'],
            ['source_id' => 'pact_contract_testing', 'url' => 'https://docs.pact.io/', 'pattern' => 'consumer_provider_contract_tests_for_http_and_message_integrations'],
            ['source_id' => 'postman_api_contract_testing', 'url' => 'https://www.postman.com/postman/postman-intergalactic/documentation/o4masc1/postman-api-contract-testing', 'pattern' => 'schema_validation_payload_assertions_and_repeatable_contract_tests'],
            ['source_id' => 'anthropic_financial_services_connectors', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'domain_data_connectors_with_source_verification_and_audit_trails'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'terms_review', 'security_review', 'sandbox_probe_receipt', 'contract_test_result'],
                'source_hash' => hash('sha256', 'connector_certification_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function apiSurface(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $commandBase = $this->commandBase($domainId);

        return [
            'schema' => 'atlas.ai.company.api_surface.v1',
            'company_id' => $domainId,
            'commands' => [
                'readiness' => 'php artisan '.$commandBase.' readiness --json',
                'smoke' => 'php artisan '.$commandBase.' smoke --json',
                'enterprise_analysis' => $domainId === 'software'
                    ? 'php artisan atlas:ai:engineering-company enterprise-analysis --json'
                    : 'php artisan '.$commandBase.' --action=enterprise-analysis --json',
            ],
            'packet_endpoints' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'input_packet' => $flowId.'.request.v1',
                    'output_packet' => $flowId.'.response.v1',
                    'receipt_packet' => $flowId.'.receipt.v1',
                ],
                $flowIds,
            )),
            'state_contract' => [
                'checkpoint_required' => true,
                'resume_token_required' => true,
                'idempotency_key_required' => true,
            ],
            'api_hash' => hash('sha256', $domainId.'|api_surface|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function evaluationHarness(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);

        return [
            'schema' => 'atlas.ai.company.evaluation_harness.v1',
            'company_id' => $domainId,
            'evaluation_modes' => [
                'contract_fixture',
                'source_link_audit',
                'policy_violation_scan',
                'handoff_acceptance_test',
                'budget_and_latency_probe',
                'operator_review_sampling',
            ],
            'required_trace_fields' => [
                'flow_id',
                'agent_role',
                'input_hash',
                'tool_call_receipts',
                'output_hash',
                'critic_score',
                'policy_findings',
                'handoff_acceptance_status',
            ],
            'suite_per_flow' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'suite' => $domainId.'.'.$flowId.'.enterprise_eval',
                    'minimum_score' => 0.86,
                    'failure_action' => 'block_promotion_and_open_review_queue',
                    'suite_hash' => hash('sha256', $domainId.'|eval_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'harness_hash' => hash('sha256', $domainId.'|evaluation_harness|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowBenchmarkReplayStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $metrics = array_values((array) $blueprint['metrics']);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1',
            'company_id' => $domainId,
            'benchmark_policy' => [
                'mode' => 'offline_eval_before_shadow_online_eval_after_observed_runs',
                'basis' => [
                    'trace_grading_for_tool_handoff_guardrail_and_routing_regressions',
                    'curated_datasets_for_repeatable_offline_evals',
                    'production_trace_monitoring_for_online_quality_without_reference_outputs',
                    'isolated_benchmark_runs_with_logs_telemetry_and_custom_metrics',
                    'stateful_task_assertions_for_procedural_enterprise_work',
                ],
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
            ],
            'source_catalog' => $this->benchmarkSourceCatalog(),
            'offline_dataset_contracts' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'dataset_id' => $domainId.'.'.$flowId.'.golden_dataset.v1',
                    'minimum_examples' => 10,
                    'example_types' => ['happy_path', 'edge_case', 'ambiguous_request', 'missing_evidence', 'policy_boundary'],
                    'required_fields' => ['input', 'reference_output_or_assertions', 'metadata', 'risk_profile', 'expected_tool_trajectory'],
                    'reference_output_required' => true,
                    'owner_agent' => (string) $spec[0],
                    'dataset_hash' => hash('sha256', $domainId.'|offline_dataset|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'trace_grading_rubrics' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'rubric_id' => $flowId.'.trace_grading_rubric.v1',
                    'graded_trace_components' => ['tool_selection', 'tool_arguments', 'handoff_timing', 'guardrail_result', 'critic_review', 'operator_checkpoint'],
                    'score_keys' => ['task_success', 'trajectory_correctness', 'evidence_quality', 'policy_compliance', 'handoff_quality', 'cost_latency'],
                    'failure_modes' => ['wrong_tool', 'missing_handoff', 'policy_violation', 'unsupported_claim', 'stale_context', 'budget_overrun'],
                    'minimum_score' => 0.86,
                    'rubric_hash' => hash('sha256', 'trace_grading_rubric|'.$flowId),
                ],
                $flowIds,
            )),
            'adversarial_regression_cases' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'case_suite' => $flowId.'.adversarial_regression.v1',
                    'case_types' => ['prompt_injection', 'missing_source', 'conflicting_evidence', 'forbidden_external_action', 'connector_outage', 'handoff_rejection'],
                    'expected_behavior' => 'fail_closed_emit_review_packet_and_preserve_checkpoint',
                    'must_not_do' => ['invent_source', 'skip_policy_gate', 'perform_external_side_effect', 'approve_own_work'],
                    'case_hash' => hash('sha256', 'adversarial_regression|'.$flowId),
                ],
                $flowIds,
            )),
            'deterministic_state_assertions' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'assertion_suite' => $flowId.'.state_assertions.v1',
                    'state_objects' => ['flow_run', 'work_product', 'tool_receipt', 'handoff_packet', 'metric_observation'],
                    'assertions' => [
                        'no_terminal_success_without_receipt_hash',
                        'no_delivery_without_quality_score',
                        'no_cross_company_handoff_without_acceptance_state',
                        'no_external_action_without_operator_mandate',
                    ],
                    'primary_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                    'primary_work_product' => (string) ($workProducts[$index % max(1, count($workProducts))] ?? (string) $spec[2]),
                    'assertion_hash' => hash('sha256', $domainId.'|state_assertions|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'replay_and_comparison_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'replay_modes' => ['baseline_contract_fixture', 'latest_runbook', 'latest_tooling_pattern', 'memory_enabled_variant'],
                    'comparison_dimensions' => ['task_success', 'quality_score', 'policy_findings', 'tool_receipt_coverage', 'latency_ms', 'cost_uusd', 'handoff_acceptance'],
                    'required_artifacts' => ['input_fixture', 'agent_trace', 'tool_receipts', 'grader_feedback', 'diff_report', 'replay_receipt_hash'],
                    'regression_action' => 'block_promotion_open_flow_quality_review_and_attach_replay_diff',
                    'replay_hash' => hash('sha256', 'benchmark_replay|'.$flowId),
                ],
                $flowIds,
            )),
            'promotion_quality_gates' => [
                'minimum_offline_eval_score' => 0.86,
                'minimum_trace_grade_score' => 0.86,
                'policy_findings_allowed' => 0,
                'required_green_replays' => ['baseline_contract_fixture', 'adversarial_regression', 'state_assertions'],
                'operator_review_required_before_shadow_mode' => true,
                'observed_online_runs_required_before_autonomy_claim' => true,
            ],
            'benchmark_observability' => [
                'required_metrics' => ['offline_eval_score', 'trace_grade_score', 'adversarial_pass_rate', 'state_assertion_pass_rate', 'replay_regression_count', 'online_quality_drift'],
                'dashboard' => $domainId.'_enterprise_flow_benchmark_replay_board',
                'alert_on' => ['score_below_floor', 'policy_finding', 'state_assertion_failed', 'replay_regression'],
            ],
            'benchmark_replay_hash' => hash('sha256', $domainId.'|enterprise_flow_benchmark_replay|'.implode('|', $flowIds).'|'.implode('|', $metrics)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function benchmarkSourceCatalog(): array
    {
        $sources = [
            ['source_id' => 'openai_agent_evals', 'url' => 'https://developers.openai.com/api/docs/guides/agent-evals', 'pattern' => 'trace_grading_datasets_eval_runs_for_agent_workflows'],
            ['source_id' => 'langsmith_evaluation_concepts', 'url' => 'https://docs.langchain.com/langsmith/evaluation-concepts', 'pattern' => 'offline_online_evaluation_datasets_runs_evaluators_and_feedback'],
            ['source_id' => 'microsoft_autogenbench', 'url' => 'https://microsoft.github.io/autogen/0.2/blog/2024/01/25/AutoGenBench/', 'pattern' => 'isolated_benchmark_runs_logs_telemetry_and_custom_metrics'],
            ['source_id' => 'microsoft_state_bench', 'url' => 'https://opensource.microsoft.com/blog/2026/05/19/introducing-state-bench-a-benchmark-for-ai-agent-memory/', 'pattern' => 'stateful_procedural_enterprise_tasks_with_deterministic_assertions'],
            ['source_id' => 'anthropic_financial_services_benchmark_pattern', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'pattern' => 'domain_specific_agent_benchmarks_with_verified_data_connectors_and_audit_trails'],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'dataset_contract', 'trace_sample', 'benchmark_run_receipt', 'operator_review'],
                'source_hash' => hash('sha256', 'enterprise_flow_benchmark_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseToolingResearchStack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $referencePatterns = array_values((array) $blueprint['reference_patterns']);

        return [
            'schema' => 'atlas.ai.company.enterprise_tooling_research_stack.v1',
            'company_id' => $domainId,
            'source_catalog' => $this->agentFrameworkSourceCatalog($domainId),
            'domain_adoption_strategy' => [
                'reference_patterns' => $referencePatterns,
                'default_orchestration' => 'typed_flow_with_specialist_crews_handoffs_guardrails_tracing_and_durable_resume',
                'tool_selection_method' => 'evidence_weighted_fit_security_maturity_and_operational_cost',
                'production_rule' => 'adopt_as_internal_or_read_only_until_eval_integration_probe_and_operator_mandate_are_green',
                'reject_when' => [
                    'no_license_or_security_posture',
                    'no_state_or_trace_model_for_long_running_work',
                    'no_human_review_checkpoint_for_external_side_effects',
                    'no_receipt_or_evidence_export',
                ],
            ],
            'per_flow_tooling_benchmark' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'candidate_patterns' => $this->candidateToolingPatterns($domainId),
                    'minimum_benchmark_dimensions' => [
                        'task_success',
                        'evidence_quality',
                        'handoff_reliability',
                        'trace_completeness',
                        'durable_resume',
                        'security_boundary',
                        'cost_latency',
                    ],
                    'required_artifacts' => [
                        'baseline_prompt_or_fixture',
                        'agent_trace',
                        'tool_receipts',
                        'critic_review',
                        'operator_checkpoint',
                        'benchmark_hash',
                    ],
                    'promotion_gate' => 'flow_cannot_enter_shadow_mode_until_best_fit_pattern_has_green_eval_and_integration_probe',
                    'benchmark_hash' => hash('sha256', $domainId.'|tooling_benchmark|'.$flowId),
                ],
                $flowIds,
            )),
            'connector_integration_backlog' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'adapter_options' => ['mcp_server', 'api_adapter', 'read_model_projection', 'manual_import_bridge'],
                    'enablement_sequence' => [
                        'document_contract',
                        'bind_least_privilege_secret',
                        'run_read_only_probe',
                        'capture_receipt',
                        'add_fixture_to_evaluation_harness',
                        'request_operator_write_mandate_if_needed',
                    ],
                    'current_mode' => 'research_ready_contract_ready_read_or_internal_only',
                    'external_side_effects_enabled' => false,
                    'backlog_hash' => hash('sha256', 'tooling_integration_backlog|'.$connector),
                ],
                $connectors,
            )),
            'agent_repository_watchlist' => [
                'frameworks' => ['openai_agents_sdk', 'crewai_flows_crews', 'microsoft_agent_framework', 'microsoft_agent_framework_autogen_lineage', 'langgraph_durable_execution', 'model_context_protocol_servers', 'temporal_durable_workflows', 'opentelemetry_collector_tracing'],
                'domain_specific_watch' => $domainId === 'finance'
                    ? ['financial_data_mcp_connectors', 'excel_financial_modeling', 'market_data_research_sources', 'compliance_automation_agents']
                    : ['domain_mcp_connectors', 'workflow_specific_agent_templates', 'evaluation_datasets', 'observability_adapters'],
                'review_cadence' => 'weekly_tooling_research_review',
                'watchlist_hash' => hash('sha256', $domainId.'|agent_repository_watchlist|'.implode('|', $referencePatterns)),
            ],
            'enterprise_adoption_gates' => [
                'license_and_security_review',
                'sandbox_probe_green',
                'evaluation_harness_green',
                'receipts_exported',
                'operator_checkpoint_supported',
                'rollback_or_manual_fallback_documented',
            ],
            'research_stack_hash' => hash('sha256', $domainId.'|enterprise_tooling_research|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function agentFrameworkSourceCatalog(string $domainId): array
    {
        $catalog = [
            [
                'source_id' => 'anthropic_claude_for_financial_services',
                'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'adopted_pattern' => 'unified_interface_over_internal_external_data_mcp_connectors_and_domain_expert_support',
                'applies_to' => $domainId === 'finance' ? 'primary_domain_blueprint' : 'regulated_domain_connector_blueprint',
            ],
            [
                'source_id' => 'openai_agents_sdk',
                'url' => 'https://developers.openai.com/api/docs/guides/agents',
                'adopted_pattern' => 'specialist_agents_tools_handoffs_guardrails_state_and_tracing',
                'applies_to' => 'all_company_flows',
            ],
            [
                'source_id' => 'crewai_flows_crews',
                'url' => 'https://docs.crewai.com/en/introduction',
                'adopted_pattern' => 'stateful_flows_with_specialist_crews_for_complex_work',
                'applies_to' => 'multi_agent_work_decomposition',
            ],
            [
                'source_id' => 'microsoft_agent_framework',
                'url' => 'https://github.com/microsoft/agent-framework',
                'adopted_pattern' => 'production_grade_agents_multi_agent_workflows_durability_observability_governance_and_human_in_loop',
                'applies_to' => 'production_agent_runtime_benchmark',
            ],
            [
                'source_id' => 'microsoft_autogen_agent_framework_lineage',
                'url' => 'https://github.com/microsoft/autogen',
                'adopted_pattern' => 'enterprise_grade_multi_agent_orchestration_mcp_and_a2a_migration_path',
                'applies_to' => 'agent_framework_benchmark',
            ],
            [
                'source_id' => 'langgraph_durable_execution',
                'url' => 'https://docs.langchain.com/oss/javascript/langgraph/durable-execution',
                'adopted_pattern' => 'checkpointed_durable_execution_resume_after_interrupt_or_failure',
                'applies_to' => 'long_running_flows_and_recovery',
            ],
            [
                'source_id' => 'model_context_protocol_servers',
                'url' => 'https://github.com/modelcontextprotocol/servers',
                'adopted_pattern' => 'standardized_tool_and_context_server_catalog_for_connector_backplanes',
                'applies_to' => 'connector_tool_surface_design',
            ],
            [
                'source_id' => 'temporal_durable_workflows',
                'url' => 'https://github.com/temporalio/sdk-php',
                'adopted_pattern' => 'durable_workflow_replay_retry_and_long_running_business_process_orchestration',
                'applies_to' => 'durable_company_flow_runtime',
            ],
            [
                'source_id' => 'opentelemetry_collector_tracing',
                'url' => 'https://github.com/open-telemetry/opentelemetry-collector',
                'adopted_pattern' => 'trace_metric_log_collection_for_agent_runtime_observability',
                'applies_to' => 'runtime_observability_and_audit_export',
            ],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'evidence_required_before_adoption' => ['source_review', 'license_review', 'security_review', 'sandbox_probe', 'benchmark_result'],
                'source_hash' => hash('sha256', 'agent_framework_source|'.$source['source_id']),
            ],
            $catalog,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function candidateToolingPatterns(string $domainId): array
    {
        $patterns = [
            [
                'pattern_id' => 'typed_agents_sdk_runtime',
                'best_for' => 'code_owned_orchestration_with_tools_handoffs_guardrails_and_tracing',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'flow_plus_crew_runtime',
                'best_for' => 'structured_process_with_specialist_agent_teams',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'durable_graph_runtime',
                'best_for' => 'long_running_checkpointed_flows_with_resume',
                'fit' => 'high',
            ],
            [
                'pattern_id' => 'enterprise_agent_framework_runtime',
                'best_for' => 'multi_provider_enterprise_orchestration_with_mcp_a2a_and_long_term_support',
                'fit' => 'medium',
            ],
        ];

        if ($domainId === 'finance') {
            $patterns[] = [
                'pattern_id' => 'financial_services_connector_runtime',
                'best_for' => 'financial_research_modeling_due_diligence_risk_and_compliance_with_trusted_data_connectors',
                'fit' => 'high',
            ];
        }

        return array_values(array_map(
            static fn (array $pattern): array => [
                ...$pattern,
                'must_support' => ['source_refs', 'tool_receipts', 'policy_gate', 'human_checkpoint', 'evaluation_export'],
                'external_side_effects_default' => false,
                'pattern_hash' => hash('sha256', 'candidate_tooling_pattern|'.$pattern['pattern_id']),
            ],
            $patterns,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseDomainSolutionStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_column($sources, 'source_id');
        $flowSpecs = (array) $blueprint['flow_specs'];
        $workProducts = array_values((array) $blueprint['work_products']);
        $agents = array_values((array) $blueprint['agent_roles']);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_solution_stack.v1',
            'company_id' => $domainId,
            'domain_source_catalog' => $sources,
            'solution_modules' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'module_id' => $domainId.'.solution.'.$flowId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'domain_sources' => array_values(array_slice($sourceIds, 0, min(4, count($sourceIds)))),
                    'capability_bundle' => [
                        'sense' => ['source_ingestion', 'context_normalization', 'freshness_check'],
                        'reason' => ['domain_model', 'risk_review', 'scenario_or_option_analysis'],
                        'act' => ['draft_or_propose_only', 'operator_checkpoint_before_external_action'],
                        'learn' => ['quality_feedback', 'metric_update', 'playbook_delta'],
                    ],
                    'required_data_products' => array_values(array_slice($workProducts, 0, min(3, count($workProducts)))),
                    'service_level' => [
                        'mode' => 'internal_enterprise_managed_service',
                        'quality_floor' => 0.86,
                        'policy_findings_allowed' => 0,
                        'receipt_required' => true,
                    ],
                    'module_hash' => hash('sha256', $domainId.'|solution_module|'.$flowId.'|'.json_encode($spec)),
                ],
                array_keys($flowSpecs),
                $flowSpecs,
            )),
            'managed_agent_templates' => array_values(array_map(
                fn (string $agent, int $index): array => [
                    'agent_template_id' => $domainId.'.template.'.$agent,
                    'role' => $agent,
                    'skills' => [
                        'domain_source_selection',
                        'structured_reasoning',
                        'tool_receipt_interpretation',
                        'risk_and_policy_review',
                        'typed_artifact_production',
                    ],
                    'default_sources' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(3, count($sourceIds)))),
                    'collaboration_contract' => [
                        'manager' => (string) ($agents[0] ?? $agent),
                        'reviewer' => 'independent_reviewer_agent',
                        'handoff_packet_required' => true,
                        'operator_checkpoint_for_external_action' => true,
                    ],
                    'agent_template_hash' => hash('sha256', $domainId.'|managed_agent_template|'.$agent),
                ],
                $agents,
                array_keys($agents),
            )),
            'data_product_catalog' => array_values(array_map(
                static fn (string $workProduct): array => [
                    'data_product_id' => $workProduct,
                    'contract' => $workProduct.'.enterprise_data_product.v1',
                    'required_lineage' => ['source_ids', 'input_hash', 'transform_steps', 'critic_review', 'output_hash'],
                    'freshness_policy' => 'source_specific_or_disclose_stale_context',
                    'consumer' => 'operator_or_cross_company_handoff',
                    'data_product_hash' => hash('sha256', 'domain_solution_data_product|'.$workProduct),
                ],
                $workProducts,
            )),
            'enterprise_solution_playbooks' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_domain_solution_playbook.v1',
                    'playbook_id' => $domainId.'.solution_playbook.'.$flowId,
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'source_pack' => [
                        'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                        'direct_hyperlinks_required' => true,
                        'minimum_independent_sources' => min(3, max(1, count($sourceIds))),
                        'freshness_check_required' => true,
                        'source_disagreement_register_required' => true,
                    ],
                    'data_plane' => [
                        'input_contract' => $flowId.'.input_context.v1',
                        'normalized_context_contract' => $flowId.'.normalized_context.v1',
                        'lineage_fields' => ['source_id', 'connector_id', 'retrieved_at', 'input_hash', 'transform_hash', 'redaction_status'],
                        'warehouse_or_vector_index_allowed' => 'internal_or_read_only_connector_until_operator_scope',
                        'raw_secret_or_sensitive_payload_export_allowed' => false,
                    ],
                    'execution_path' => [
                        'nodes' => ['intake', 'source_pack', 'tool_plan', 'analysis_or_model', 'artifact_build', 'critic_review', 'policy_gate', 'handoff'],
                        'durable_state_required' => true,
                        'resume_token_required' => true,
                        'idempotency_key_required' => true,
                        'operator_interrupt_supported' => true,
                    ],
                    'tooling_contract' => [
                        'connector_refs' => array_values((array) ($spec[1] ?? [])),
                        'mcp_or_api_adapter_required' => true,
                        'sandbox_or_fixture_mode_required_before_live_read' => true,
                        'write_spend_trade_publish_deploy_delete_blocked_without_signed_scope' => true,
                        'tool_receipt_required' => true,
                    ],
                    'domain_review_contract' => [
                        'reviewer' => 'independent_reviewer_agent',
                        'domain_correctness_score_required' => 0.9,
                        'source_faithfulness_score_required' => 0.95,
                        'policy_findings_allowed' => 0,
                        'customer_visible_claims_require_source_refs' => true,
                    ],
                    'benchmark_contract' => [
                        'fixture_cases_required' => 25,
                        'shadow_replays_required' => 5,
                        'adversarial_cases_required' => 5,
                        'regression_pack_required' => true,
                        'synthetic_score_claims_allowed' => false,
                    ],
                    'handoff_contract' => [
                        'artifact_type' => (string) ($spec[2] ?? 'enterprise_artifact'),
                        'required_evidence' => ['source_pack_hash', 'tool_receipt_hash', 'artifact_hash', 'critic_review_hash', 'policy_gate_hash', 'receipt_hash'],
                        'target_acceptance_required' => true,
                        'external_delivery_requires_operator_mandate' => true,
                    ],
                    'playbook_hash' => hash('sha256', $domainId.'|enterprise_solution_playbook|'.$flowId.'|'.json_encode($spec)),
                ],
                array_keys($flowSpecs),
                $flowSpecs,
                array_keys(array_keys($flowSpecs)),
            )),
            'domain_data_plane' => [
                'schema' => 'atlas.ai.company.enterprise_domain_data_plane.v1',
                'source_refs' => $sourceIds,
                'layers' => ['source_catalog', 'connector_snapshot', 'normalized_context', 'domain_model', 'artifact_lineage', 'audit_export'],
                'direct_source_hyperlinks_required' => true,
                'cross_source_verification_required' => true,
                'claim_to_source_traceability_required' => true,
                'private_or_regulated_data_requires_redaction' => true,
                'external_data_mutation_allowed' => false,
                'data_plane_hash' => hash('sha256', $domainId.'|enterprise_domain_data_plane|'.implode('|', $sourceIds)),
            ],
            'domain_expert_review_board' => [
                'schema' => 'atlas.ai.company.enterprise_domain_expert_review_board.v1',
                'roles' => array_values(array_unique(array_merge($agents, ['independent_reviewer_agent', 'portfolio_governor']))),
                'review_modes' => ['domain_correctness', 'source_faithfulness', 'policy_and_risk', 'artifact_acceptance', 'production_scope'],
                'second_reviewer_required_for_external_action' => true,
                'operator_acceptance_required_for_customer_visible_output' => true,
                'board_hash' => hash('sha256', $domainId.'|enterprise_domain_expert_review_board|'.implode('|', $agents)),
            ],
            'solution_operating_model' => [
                'intake' => 'objective_scope_policy_profile_and_evidence_refs',
                'execution' => 'durable_flow_runtime_blueprint_with_domain_solution_module',
                'delivery' => 'typed_artifact_with_source_lineage_receipt_and_quality_review',
                'continuous_improvement' => 'weekly_solution_review_updates_sources_modules_and_agent_templates',
                'external_side_effects_default' => false,
            ],
            'domain_solution_hash' => hash('sha256', $domainId.'|enterprise_domain_solution|'.implode('|', $sourceIds).'|'.implode('|', array_keys($flowSpecs))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseDomainOperatingDepthStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values(array_unique(array_merge(
            array_values((array) $blueprint['work_products']),
            array_values(array_map(static fn (array $spec): string => (string) ($spec[2] ?? 'enterprise_artifact'), $flowSpecs)),
        )));
        $metrics = array_values((array) $blueprint['metrics']);
        $profile = $this->domainOperatingDepthProfile($domainId);
        $domainSkills = array_values((array) $profile['skills']);
        $domainSystems = array_values((array) $profile['systems']);
        $domainDataProducts = array_values((array) $profile['data_products']);
        $domainControls = array_values((array) $profile['controls']);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_operating_depth_stack.v1',
            'company_id' => $domainId,
            'source_inspiration' => [
                'anthropic_financial_agents_pattern' => 'skills_connectors_subagents_per_vertical_workflow',
                'anthropic_financial_agents_url' => 'https://www.anthropic.com/news/finance-agents',
                'openai_agents_sdk_pattern' => 'tools_handoffs_guardrails_tracing_state_owned_by_application',
                'openai_agents_sdk_url' => 'https://developers.openai.com/api/docs/guides/agents',
                'mcp_connector_pattern' => 'api_or_mcp_adapter_per_enterprise_system_with_receipts',
                'stainless_mcp_sdk_pattern_url' => 'https://www.anthropic.com/news/anthropic-acquires-stainless',
            ],
            'depth_policy' => [
                'mode' => 'domain_specific_enterprise_depth_without_ungoverned_external_effects',
                'calendar_wait_blocker_enabled' => false,
                'flow_depth_packet_required_for_every_flow' => true,
                'skills_connectors_subagents_required_for_every_flow' => true,
                'domain_data_product_required_for_every_flow' => true,
                'enterprise_system_map_required_for_every_connector' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
            ],
            'domain_value_chain' => array_values((array) $profile['value_chain']),
            'domain_data_product_spine' => array_values(array_map(
                static fn (string $dataProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_depth_data_product.v1',
                    'data_product_id' => $dataProduct,
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'lineage_required' => ['source_ref', 'connector_receipt', 'normalization_hash', 'critic_review_hash', 'artifact_hash'],
                    'freshness_policy' => 'source_specific_with_staleness_disclosure',
                    'external_mutation_allowed' => false,
                    'data_product_hash' => hash('sha256', 'domain_depth_data_product|'.$dataProduct),
                ],
                $domainDataProducts,
                array_keys($domainDataProducts),
            )),
            'enterprise_system_map' => array_values(array_map(
                static fn (string $system, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_depth_enterprise_system.v1',
                    'system_id' => $system,
                    'connector_ref' => (string) ($connectors[$index % max(1, count($connectors))] ?? 'manual_import_adapter'),
                    'integration_mode' => 'fixture_manual_import_read_only_probe_then_supervised_handoff',
                    'required_controls' => ['rbac_scope', 'credential_vault_ref', 'schema_snapshot', 'rate_limit', 'audit_log', 'rollback_or_reconciliation_plan'],
                    'credential_material_in_packet_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'system_hash' => hash('sha256', 'domain_depth_enterprise_system|'.$system),
                ],
                $domainSystems,
                array_keys($domainSystems),
            )),
            'flow_depth_packets' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_domain_flow_depth_packet.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'reference_pattern' => $domainId === 'finance'
                        ? 'anthropic_financial_services_ready_to_run_agent_template'
                        : 'anthropic_financial_services_style_vertical_agent_template_generalized',
                    'skills' => array_values(array_unique(array_merge(
                        ['scope_intake', 'domain_source_selection', 'tool_plan', 'artifact_build', 'critic_review', 'operator_handoff'],
                        array_slice($domainSkills, $index % max(1, count($domainSkills)), min(5, count($domainSkills))),
                    ))),
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'subagents' => [
                        $domainId.'.'.$flowId.'.source_lineage_subagent',
                        $domainId.'.'.$flowId.'.methodology_check_subagent',
                        $domainId.'.'.$flowId.'.artifact_quality_subagent',
                        $domainId.'.'.$flowId.'.risk_policy_subagent',
                    ],
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'enterprise_system_refs' => array_values(array_slice($domainSystems, $index % max(1, count($domainSystems)), min(4, count($domainSystems)))),
                    'data_product_refs' => array_values(array_slice($domainDataProducts, $index % max(1, count($domainDataProducts)), min(4, count($domainDataProducts)))),
                    'work_product' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'artifact_sections' => ['objective', 'source_lineage', 'domain_analysis', 'model_or_plan', 'risk_controls', 'decision_recommendation', 'operator_handoff', 'receipt_hash'],
                    'quality_contract' => [
                        'minimum_fixture_cases' => 25,
                        'minimum_shadow_replays' => 5,
                        'source_faithfulness_floor' => 0.95,
                        'domain_correctness_floor' => 0.9,
                        'policy_findings_allowed' => 0,
                    ],
                    'domain_controls' => $domainControls,
                    'operating_controls' => [
                        'tool_receipts_required' => true,
                        'second_reviewer_required_for_external_action' => true,
                        'customer_visible_claims_require_source_refs' => true,
                        'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                        'offensive_security_allowed' => false,
                    ],
                    'metric_refs' => array_values(array_slice($metrics, $index % max(1, count($metrics)), min(4, count($metrics)))),
                    'packet_hash' => hash('sha256', $domainId.'|domain_flow_depth_packet|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'mcp_api_connector_backlog' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.domain_depth_connector_backlog_item.v1',
                    'connector_id' => $connector,
                    'adapter_target' => $connector.'.mcp_or_api_adapter',
                    'contract_tests_required' => ['schema_snapshot', 'auth_scope', 'read_only_probe', 'fixture_replay', 'rate_limit', 'receipt_export'],
                    'live_write_mode_allowed' => false,
                    'credential_material_in_packet_allowed' => false,
                    'backlog_hash' => hash('sha256', 'domain_depth_connector_backlog|'.$connector),
                ],
                $connectors,
            )),
            'delivery_offer_model' => array_values(array_map(
                static fn (string $workProduct): array => [
                    'schema' => 'atlas.ai.company.domain_depth_delivery_offer.v1',
                    'work_product' => $workProduct,
                    'service_model' => 'internal_enterprise_service_until_signed_external_scope',
                    'acceptance' => ['typed_artifact', 'source_lineage', 'quality_scores', 'risk_review', 'operator_acceptance'],
                    'external_customer_commitment_allowed' => false,
                    'offer_hash' => hash('sha256', 'domain_depth_delivery_offer|'.$workProduct),
                ],
                $workProducts,
            )),
            'depth_observability' => [
                'required_metrics' => [
                    'flow_depth_packet_coverage',
                    'skill_coverage',
                    'subagent_coverage',
                    'connector_depth_coverage',
                    'data_product_lineage_coverage',
                    'enterprise_system_probe_pass_rate',
                    'quality_contract_pass_rate',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_domain_operating_depth_board',
                'alert_on' => ['missing_flow_depth_packet', 'missing_subagent', 'missing_data_product', 'missing_connector_adapter', 'quality_contract_failed', 'external_effect_requested'],
            ],
            'domain_operating_depth_hash' => hash('sha256', $domainId.'|domain_operating_depth|'.implode('|', $flowIds).'|'.implode('|', $domainSystems).'|'.implode('|', $domainDataProducts)),
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    private function domainOperatingDepthProfile(string $domainId): array
    {
        return match ($domainId) {
            'software' => [
                'value_chain' => ['intake', 'architecture', 'implementation', 'test_repair', 'security_review', 'release', 'learning'],
                'skills' => ['ast_reasoning', 'dependency_impact_analysis', 'test_failure_triage', 'patch_review', 'release_risk_assessment'],
                'systems' => ['git_provider', 'ci_system', 'issue_tracker', 'artifact_registry', 'observability_stack'],
                'data_products' => ['repo_graph', 'test_failure_corpus', 'release_evidence_pack', 'security_findings_register', 'dependency_impact_map'],
                'controls' => ['no_unreviewed_destructive_git_operation', 'tests_before_release', 'security_findings_closed_or_waived'],
            ],
            'research' => [
                'value_chain' => ['question_scope', 'source_discovery', 'citation_graph', 'claim_extraction', 'contradiction_review', 'synthesis', 'knowledge_update'],
                'skills' => ['primary_source_retrieval', 'citation_quality_scoring', 'claim_support_mapping', 'contradiction_adjudication', 'evidence_synthesis'],
                'systems' => ['web_search', 'paper_index', 'citation_graph', 'source_registry', 'knowledge_base'],
                'data_products' => ['source_pack', 'claim_table', 'citation_graph', 'contradiction_register', 'synthesis_memo'],
                'controls' => ['primary_sources_required', 'unsupported_claims_blocked', 'source_disagreement_disclosed'],
            ],
            'strategy' => [
                'value_chain' => ['opportunity_scan', 'market_map', 'business_model', 'experiment_design', 'capital_option', 'board_dossier'],
                'skills' => ['market_mapping', 'assumption_modeling', 'scenario_analysis', 'experiment_portfolio_design', 'board_memo_writing'],
                'systems' => ['market_dataset', 'research_handoff', 'finance_model', 'experiment_registry', 'board_decision_log'],
                'data_products' => ['opportunity_map', 'tam_model', 'assumption_ledger', 'experiment_scorecard', 'board_decision_packet'],
                'controls' => ['assumptions_must_be_explicit', 'capital_commitment_operator_only', 'forecast_uncertainty_disclosed'],
            ],
            'finance' => [
                'value_chain' => ['market_research', 'filing_ingestion', 'model_build', 'valuation_review', 'risk_compliance', 'committee_pack'],
                'skills' => ['pitchbook_building', 'earnings_review', 'model_building', 'valuation_methodology_check', 'kyc_screening'],
                'systems' => ['market_data_terminal', 'sec_filings', 'spreadsheet_model', 'portfolio_analytics', 'compliance_system'],
                'data_products' => ['comps_model', 'earnings_update', 'valuation_packet', 'kyc_file', 'investment_committee_memo'],
                'controls' => ['source_attribution_required', 'model_audit_trail_required', 'live_trade_blocked', 'compliance_escalation_required'],
            ],
            'marketing' => [
                'value_chain' => ['market_insight', 'positioning', 'creative_brief', 'campaign_plan', 'experiment_readout', 'lifecycle_iteration'],
                'skills' => ['audience_segmentation', 'positioning_strategy', 'creative_briefing', 'funnel_analysis', 'lifecycle_orchestration'],
                'systems' => ['crm', 'analytics', 'content_repository', 'ads_platform', 'approval_system'],
                'data_products' => ['audience_segments', 'positioning_system', 'creative_pack', 'experiment_readout', 'campaign_plan'],
                'controls' => ['claims_review_required', 'campaign_publish_operator_only', 'regulated_targeting_review_required'],
            ],
            'cyber' => [
                'value_chain' => ['scope_and_roe', 'asset_inventory', 'finding_triage', 'control_mapping', 'detection_proposal', 'remediation_plan'],
                'skills' => ['threat_modeling', 'vulnerability_enrichment', 'appsec_review', 'detection_engineering', 'grc_mapping'],
                'systems' => ['sbom', 'repo_read_adapter', 'vulnerability_feed', 'ticketing_system', 'evidence_chain'],
                'data_products' => ['attack_surface_delta', 'finding_triage_packet', 'control_map', 'detection_rule_proposal', 'remediation_plan'],
                'controls' => ['authorized_scope_required', 'offensive_execution_blocked', 'remediation_requires_owner_acceptance'],
            ],
            'automation' => [
                'value_chain' => ['opportunity_intake', 'tool_selection', 'workflow_design', 'fixture_replay', 'reliability_review', 'supervised_handoff'],
                'skills' => ['api_schema_analysis', 'browser_workflow_design', 'mcp_adapter_design', 'tool_benchmarking', 'replay_debugging'],
                'systems' => ['tool_registry', 'browser_adapter', 'api_schema_registry', 'workflow_engine', 'receipt_ledger'],
                'data_products' => ['automation_opportunity_pack', 'tool_scorecard', 'workflow_runbook', 'mcp_adapter_packet', 'reliability_report'],
                'controls' => ['destructive_action_blocked', 'credential_export_blocked', 'idempotency_required'],
            ],
            'personal_development' => [
                'value_chain' => ['goal_scope', 'baseline_review', 'curriculum_design', 'habit_iteration', 'reflection', 'privacy_review'],
                'skills' => ['coaching_intake', 'curriculum_design', 'habit_system_design', 'reflection_synthesis', 'privacy_filtering'],
                'systems' => ['goal_registry', 'private_memory', 'habit_log', 'learning_resource_registry', 'review_packet'],
                'data_products' => ['life_operating_review', 'learning_curriculum', 'habit_plan', 'reflection_synthesis', 'privacy_review_packet'],
                'controls' => ['private_memory_review_required', 'sensitive_export_blocked', 'operator_controls_all_external_sharing'],
            ],
            default => [
                'value_chain' => ['readiness', 'incident_intake', 'triage', 'runbook_execution', 'capacity_review', 'postmortem_learning'],
                'skills' => ['slo_analysis', 'incident_command', 'log_metric_correlation', 'runbook_authoring', 'postmortem_editing'],
                'systems' => ['metrics_stack', 'logs_stack', 'alerting_system', 'runbook_repository', 'incident_tracker'],
                'data_products' => ['readiness_review', 'incident_packet', 'capacity_review', 'runbook_update', 'postmortem_action_plan'],
                'controls' => ['production_mutation_operator_only', 'incident_receipts_required', 'rollback_plan_required'],
            ],
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseDomainAgentWorkforceStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $profile = $this->domainOperatingDepthProfile($domainId);
        $domainSkills = array_values((array) $profile['skills']);
        $domainSystems = array_values((array) $profile['systems']);
        $domainDataProducts = array_values((array) $profile['data_products']);
        $agentRoles = array_values((array) $blueprint['agent_roles']);

        $commonWorkforceSkills = [
            'objective_intake',
            'source_linked_retrieval',
            'tool_permission_planning',
            'domain_artifact_authoring',
            'methodology_or_policy_review',
            'operator_handoff_packaging',
            'learning_update',
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_agent_workforce_stack.v1',
            'company_id' => $domainId,
            'reference_architecture' => [
                'anthropic_finance_agents' => 'skills_connectors_subagents_packaged_per_workflow',
                'anthropic_finance_agents_url' => 'https://www.anthropic.com/news/finance-agents',
                'anthropic_financial_services_solution' => 'unified_data_sources_direct_source_links_enterprise_connectors_implementation_support',
                'anthropic_financial_services_solution_url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'openai_agents_sdk' => 'tools_handoffs_guardrails_sessions_and_tracing',
                'openai_agents_sdk_url' => 'https://openai.github.io/openai-agents-python/agents/',
                'langgraph_pattern' => 'durable_state_checkpointing_and_human_interrupts_for_sensitive_workflows',
                'langgraph_url' => 'https://www.langchain.com/langgraph',
                'microsoft_autogen_pattern' => 'multi_agent_collaboration_and_enterprise_agent_framework_research_input',
                'microsoft_autogen_url' => 'https://github.com/microsoft/autogen',
            ],
            'workforce_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'managed_agent_crew_required_for_every_flow' => true,
                'skills_connectors_subagents_required_for_every_flow' => true,
                'per_tool_permission_manifest_required' => true,
                'credential_vault_reference_required' => true,
                'full_audit_log_required' => true,
                'long_running_session_resume_required' => true,
                'human_review_required_before_customer_filing_or_external_action' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
            ],
            'managed_agent_catalog' => array_values(array_map(
                static fn (string $role, int $index): array => [
                    'schema' => 'atlas.ai.company.managed_domain_agent.v1',
                    'agent_id' => $domainId.'.'.$role.'.managed_agent',
                    'role' => $role,
                    'skill_refs' => array_values(array_unique(array_merge(
                        ['source_grounding', 'tool_receipt_capture', 'artifact_quality_review', 'operator_handoff'],
                        array_slice($domainSkills, $index % max(1, count($domainSkills)), min(4, count($domainSkills))),
                    ))),
                    'default_connector_refs' => array_values(array_slice($connectors, $index % max(1, count($connectors)), min(3, count($connectors)))),
                    'default_system_refs' => array_values(array_slice($domainSystems, $index % max(1, count($domainSystems)), min(3, count($domainSystems)))),
                    'permissions' => ['read_fixture', 'read_manual_import', 'draft_artifact', 'run_critic', 'prepare_handoff'],
                    'blocked_permissions' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                    'agent_hash' => hash('sha256', 'managed_domain_agent|'.$role),
                ],
                $agentRoles,
                array_keys($agentRoles),
            )),
            'flow_agent_crews' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_agent_crew.v1',
                    'crew_id' => $domainId.'.'.$flowId.'.agent_crew.v1',
                    'flow_id' => $flowId,
                    'primary_agent' => (string) $spec[0],
                    'agent_template_pattern' => $domainId === 'finance'
                        ? 'claude_financial_services_ready_to_run_agent_template'
                        : 'claude_financial_services_style_agent_template_generalized_to_'.$domainId,
                    'skills' => array_values(array_unique(array_merge(
                        $commonWorkforceSkills,
                        array_slice($domainSkills, $index % max(1, count($domainSkills)), min(5, count($domainSkills))),
                    ))),
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'subagents' => [
                        $domainId.'.'.$flowId.'.research_or_source_subagent',
                        $domainId.'.'.$flowId.'.model_or_methodology_subagent',
                        $domainId.'.'.$flowId.'.artifact_factory_subagent',
                        $domainId.'.'.$flowId.'.risk_compliance_subagent',
                        $domainId.'.'.$flowId.'.operator_handoff_subagent',
                    ],
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'enterprise_system_refs' => array_values(array_slice($domainSystems, $index % max(1, count($domainSystems)), min(4, count($domainSystems)))),
                    'data_product_refs' => array_values(array_slice($domainDataProducts, $index % max(1, count($domainDataProducts)), min(4, count($domainDataProducts)))),
                    'work_surface_adapters' => ['spreadsheet', 'document', 'presentation', 'email_draft', 'case_queue', 'dashboard'],
                    'managed_runtime_controls' => [
                        'long_running_session' => true,
                        'resume_token_required' => true,
                        'per_tool_permissions' => true,
                        'credential_vault_ref_only' => true,
                        'full_audit_log' => true,
                        'tool_call_receipts_required' => true,
                        'human_interrupt_before_sensitive_tool' => true,
                        'external_side_effects_enabled' => false,
                    ],
                    'work_queue_contract' => [
                        'queue_id' => $domainId.'.'.$flowId.'.work_queue',
                        'states' => ['queued', 'scoped', 'retrieving', 'analyzing', 'artifact_draft', 'critic_review', 'operator_handoff', 'accepted', 'learning_update'],
                        'dead_letter_required' => true,
                        'idempotency_key_required' => true,
                    ],
                    'acceptance_contract' => [
                        'minimum_fixture_cases' => 25,
                        'minimum_shadow_replays' => 5,
                        'source_faithfulness_floor' => 0.95,
                        'domain_correctness_floor' => 0.9,
                        'policy_findings_allowed' => 0,
                        'operator_acceptance_required' => true,
                    ],
                    'crew_hash' => hash('sha256', $domainId.'|enterprise_flow_agent_crew|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'workforce_control_plane' => [
                'session_store' => $domainId.'_managed_agent_sessions',
                'audit_log' => $domainId.'_managed_agent_audit_log',
                'permission_manifest' => $domainId.'_tool_permission_manifest',
                'credential_vault_binding' => 'vault_reference_only_no_secret_material_in_packets',
                'operator_review_queue' => $domainId.'_agent_workforce_operator_review',
                'external_effect_worker_enabled' => false,
            ],
            'workforce_observability' => [
                'required_metrics' => [
                    'crew_coverage',
                    'skill_coverage',
                    'connector_coverage',
                    'subagent_coverage',
                    'tool_permission_manifest_coverage',
                    'audit_log_coverage',
                    'session_resume_success_rate',
                    'human_interrupt_rate_for_sensitive_tools',
                    'operator_acceptance_rate',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_agent_workforce_board',
                'alert_on' => ['missing_crew', 'missing_subagent', 'missing_permission_manifest', 'missing_audit_log', 'sensitive_tool_without_interrupt', 'external_effect_requested'],
            ],
            'workforce_hash' => hash('sha256', $domainId.'|enterprise_domain_agent_workforce|'.implode('|', $flowIds).'|'.implode('|', $agentRoles)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function domainSolutionSourceCatalog(string $domainId): array
    {
        $catalog = match ($domainId) {
            'software' => [
                ['source_id' => 'github_rest_api', 'url' => 'https://docs.github.com/en/rest', 'use' => 'repository_pull_request_issue_and_ci_context'],
                ['source_id' => 'opentelemetry_docs', 'url' => 'https://opentelemetry.io/docs/', 'use' => 'trace_metric_log_instrumentation'],
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'secure_software_verification_controls'],
                ['source_id' => 'openai_agents_sdk', 'url' => 'https://developers.openai.com/api/docs/guides/agents', 'use' => 'agent_tools_handoffs_guardrails_tracing'],
                ['source_id' => 'microsoft_autogen', 'url' => 'https://github.com/microsoft/autogen', 'use' => 'multi_agent_orchestration_benchmark'],
            ],
            'research' => [
                ['source_id' => 'semantic_scholar_api', 'url' => 'https://www.semanticscholar.org/product/api', 'use' => 'paper_citation_author_graph'],
                ['source_id' => 'arxiv_api', 'url' => 'https://info.arxiv.org/help/api/index.html', 'use' => 'preprint_discovery'],
                ['source_id' => 'crossref_api', 'url' => 'https://www.crossref.org/documentation/retrieve-metadata/rest-api/', 'use' => 'doi_metadata_and_references'],
                ['source_id' => 'pubmed_eutilities', 'url' => 'https://www.ncbi.nlm.nih.gov/books/NBK25501/', 'use' => 'biomedical_literature'],
                ['source_id' => 'openalex_api', 'url' => 'https://docs.openalex.org/', 'use' => 'open_scholarly_graph'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_filings'],
                ['source_id' => 'world_bank_api', 'url' => 'https://datahelpdesk.worldbank.org/knowledgebase/topics/125589-developer-information', 'use' => 'macro_market_indicators'],
                ['source_id' => 'fred_api', 'url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'economic_time_series'],
                ['source_id' => 'oecd_data_api', 'url' => 'https://data-explorer.oecd.org/', 'use' => 'country_and_sector_indicators'],
                ['source_id' => 'google_trends', 'url' => 'https://trends.google.com/trends/', 'use' => 'demand_signal_research'],
            ],
            'finance' => [
                ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'financial_services_agent_solution_pattern'],
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'filings_fundamentals_disclosure'],
                ['source_id' => 'fred_api', 'url' => 'https://fred.stlouisfed.org/docs/api/fred/', 'use' => 'macro_rates_and_economic_series'],
                ['source_id' => 'alpha_vantage_docs', 'url' => 'https://www.alphavantage.co/documentation/', 'use' => 'market_data_sandbox'],
                ['source_id' => 'openbb_docs', 'url' => 'https://docs.openbb.co/', 'use' => 'financial_research_terminal_pattern'],
            ],
            'marketing' => [
                ['source_id' => 'google_ads_api', 'url' => 'https://developers.google.com/google-ads/api/docs/campaigns', 'use' => 'campaign_reporting_and_management'],
                ['source_id' => 'google_analytics_data_api', 'url' => 'https://developers.google.com/analytics/devguides/reporting/data/v1', 'use' => 'web_and_product_analytics'],
                ['source_id' => 'hubspot_crm_api', 'url' => 'https://developers.hubspot.com/docs/api/crm/understanding-the-crm', 'use' => 'crm_lifecycle_context'],
                ['source_id' => 'meta_marketing_api', 'url' => 'https://developers.facebook.com/docs/marketing-apis/', 'use' => 'paid_social_campaign_context'],
                ['source_id' => 'linkedin_marketing_api', 'url' => 'https://learn.microsoft.com/en-us/linkedin/marketing/', 'use' => 'b2b_campaign_context'],
            ],
            'cyber' => [
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'application_security_control_verification'],
                ['source_id' => 'mitre_attack', 'url' => 'https://attack.mitre.org/', 'use' => 'adversary_tactics_techniques'],
                ['source_id' => 'nvd_api', 'url' => 'https://nvd.nist.gov/developers/vulnerabilities', 'use' => 'vulnerability_enrichment'],
                ['source_id' => 'cisa_kev', 'url' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog', 'use' => 'known_exploited_vulnerability_priority'],
                ['source_id' => 'opencti_docs', 'url' => 'https://docs.opencti.io/latest/', 'use' => 'threat_intelligence_graph'],
            ],
            'automation' => [
                ['source_id' => 'model_context_protocol', 'url' => 'https://modelcontextprotocol.io/docs/getting-started/intro', 'use' => 'tool_and_context_integration_standard'],
                ['source_id' => 'playwright_docs', 'url' => 'https://playwright.dev/docs/intro', 'use' => 'browser_automation'],
                ['source_id' => 'n8n_docs', 'url' => 'https://docs.n8n.io/', 'use' => 'workflow_automation_patterns'],
                ['source_id' => 'zapier_platform_docs', 'url' => 'https://platform.zapier.com/docs', 'use' => 'saas_connector_patterns'],
                ['source_id' => 'openapi_spec', 'url' => 'https://spec.openapis.org/oas/latest.html', 'use' => 'api_adapter_contracts'],
            ],
            'personal_development' => [
                ['source_id' => 'xapi_spec', 'url' => 'https://github.com/adlnet/xAPI-Spec', 'use' => 'learning_experience_records'],
                ['source_id' => 'open_badges', 'url' => 'https://www.imsglobal.org/spec/ob/v3p0/', 'use' => 'skill_and_credential_records'],
                ['source_id' => 'caldav_rfc', 'url' => 'https://www.rfc-editor.org/rfc/rfc4791', 'use' => 'calendar_and_schedule_context'],
                ['source_id' => 'apple_healthkit_docs', 'url' => 'https://developer.apple.com/documentation/healthkit', 'use' => 'health_context_with_privacy_review'],
                ['source_id' => 'schema_org', 'url' => 'https://schema.org/', 'use' => 'structured_goal_learning_event_metadata'],
            ],
            default => [
                ['source_id' => 'opentelemetry_docs', 'url' => 'https://opentelemetry.io/docs/', 'use' => 'trace_metric_log_instrumentation'],
                ['source_id' => 'prometheus_docs', 'url' => 'https://prometheus.io/docs/introduction/overview/', 'use' => 'metrics_and_alerting'],
                ['source_id' => 'kubernetes_api', 'url' => 'https://kubernetes.io/docs/reference/kubernetes-api/', 'use' => 'platform_state_and_workload_context'],
                ['source_id' => 'grafana_docs', 'url' => 'https://grafana.com/docs/', 'use' => 'dashboard_and_observability_context'],
                ['source_id' => 'pagerduty_api', 'url' => 'https://developer.pagerduty.com/api-reference/', 'use' => 'incident_response_context'],
            ],
        };

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'mode' => 'source_catalog_reference_until_connector_probe_green',
                'source_links_required' => true,
                'external_side_effects_default' => false,
                'source_hash' => hash('sha256', 'domain_solution_source|'.$source['source_id']),
            ],
            $catalog,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseVerticalSolutionSuiteStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values(array_unique(array_merge(
            array_values((array) $blueprint['work_products']),
            array_values(array_map(static fn (array $spec): string => (string) ($spec[2] ?? 'enterprise_artifact'), $flowSpecs)),
        )));
        $suiteBlueprints = $this->verticalSolutionSuites($domainId);
        $suiteIds = array_values(array_map(static fn (array $suite): string => (string) $suite['suite_id'], $suiteBlueprints));

        return [
            'schema' => 'atlas.ai.company.enterprise_vertical_solution_suite_stack.v1',
            'company_id' => $domainId,
            'suite_policy' => [
                'reference_pattern' => 'claude_financial_services_unified_domain_solution_generalized_to_every_company',
                'reference_url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'calendar_wait_blocker_enabled' => false,
                'suite_required_for_every_company' => true,
                'flow_kit_required_for_every_flow' => true,
                'connector_workbench_required_for_every_connector' => true,
                'artifact_factory_required_for_core_work_products' => true,
                'external_execution_allowed_by_suite' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_basis' => $sources,
            'solution_suites' => array_values(array_map(
                static fn (array $suite): array => [
                    'schema' => 'atlas.ai.company.vertical_solution_suite.v1',
                    'suite_id' => (string) $suite['suite_id'],
                    'name' => (string) $suite['name'],
                    'purpose' => (string) $suite['purpose'],
                    'source_refs' => array_values(array_slice($sourceIds, 0, min(5, count($sourceIds)))),
                    'operating_capabilities' => ['intake', 'retrieve', 'analyze', 'model_or_plan', 'produce_artifact', 'verify', 'handoff', 'learn'],
                    'required_controls' => ['source_lineage', 'tool_receipts', 'risk_review', 'quality_replay', 'operator_checkpoint', 'audit_export'],
                    'external_side_effects_enabled' => false,
                    'suite_hash' => hash('sha256', 'vertical_solution_suite|'.(string) $suite['suite_id']),
                ],
                $suiteBlueprints,
            )),
            'flow_solution_kits' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.vertical_flow_solution_kit.v1',
                    'kit_id' => $domainId.'.'.$flowId.'.vertical_solution_kit.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'suite_refs' => array_values(array_slice($suiteIds, $index % max(1, count($suiteIds)), min(3, count($suiteIds)))),
                    'connector_refs' => array_values((array) $spec[1]),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'work_product' => (string) $spec[2],
                    'workflow_nodes' => ['scope', 'source_pack', 'tool_plan', 'domain_model', 'artifact_factory', 'critic_review', 'policy_gate', 'operator_handoff'],
                    'required_evidence' => ['source_pack_hash', 'tool_receipt_hash', 'artifact_hash', 'critic_review_hash', 'policy_gate_hash', 'handoff_hash'],
                    'quality_floor' => 0.9,
                    'external_execution_allowed' => false,
                    'kit_hash' => hash('sha256', $domainId.'|vertical_solution_kit|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_solution_workbenches' => array_values(array_map(
                static fn (string $connector, int $index): array => [
                    'schema' => 'atlas.ai.company.vertical_connector_solution_workbench.v1',
                    'connector_id' => $connector,
                    'workbench_id' => $connector.'.vertical_solution_workbench.v1',
                    'primary_source_id' => (string) ($sourceIds[$index % max(1, count($sourceIds))] ?? 'internal_fixture_source'),
                    'modes' => ['fixture', 'manual_import', 'read_only_probe', 'shadow_read'],
                    'required_outputs' => ['schema_snapshot', 'sample_payload', 'permission_scope_report', 'lineage_map', 'receipt_hash'],
                    'blocked_without_operator_mandate' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                    'credential_binding' => 'vault_reference_only',
                    'external_side_effects_enabled' => false,
                    'workbench_hash' => hash('sha256', 'vertical_connector_solution_workbench|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'artifact_factory_catalog' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.vertical_artifact_factory.v1',
                    'factory_id' => $workProduct.'.vertical_artifact_factory.v1',
                    'work_product' => $workProduct,
                    'suite_ref' => (string) ($suiteIds[$index % max(1, count($suiteIds))] ?? 'domain_operating_suite'),
                    'required_sections' => ['objective', 'source_lineage', 'analysis', 'recommendation', 'risks', 'next_actions', 'receipt_hash'],
                    'quality_controls' => ['schema_validation', 'source_link_check', 'critic_review', 'policy_gate', 'operator_acceptance_marker'],
                    'delivery_mode' => 'internal_or_manual_handoff_only_until_signed_external_scope',
                    'external_delivery_allowed' => false,
                    'factory_hash' => hash('sha256', 'vertical_artifact_factory|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'suite_evaluation_recipes' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.vertical_solution_evaluation_recipe.v1',
                    'flow_id' => $flowId,
                    'minimum_fixture_cases' => 25,
                    'minimum_shadow_replays' => 5,
                    'required_scores' => ['task_success', 'source_faithfulness', 'domain_correctness', 'risk_control', 'artifact_quality', 'handoff_quality'],
                    'policy_findings_allowed' => 0,
                    'promotion_without_green_recipe_allowed' => false,
                    'recipe_hash' => hash('sha256', 'vertical_solution_evaluation_recipe|'.$flowId),
                ],
                $flowIds,
            )),
            'suite_observability' => [
                'required_metrics' => [
                    'suite_coverage',
                    'flow_kit_coverage',
                    'connector_workbench_coverage',
                    'artifact_factory_coverage',
                    'source_lineage_coverage',
                    'evaluation_recipe_pass_rate',
                    'operator_handoff_acceptance_rate',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_vertical_solution_suite_board',
                'alert_on' => ['missing_suite', 'missing_flow_kit', 'missing_connector_workbench', 'missing_artifact_factory', 'lineage_gap', 'policy_finding', 'external_effect_requested'],
            ],
            'suite_stack_hash' => hash('sha256', $domainId.'|vertical_solution_suite|'.implode('|', $suiteIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array{suite_id:string,name:string,purpose:string}>
     */
    private function verticalSolutionSuites(string $domainId): array
    {
        $domainSuites = match ($domainId) {
            'software' => [
                ['suite_id' => 'software_architecture_delivery_suite', 'name' => 'Software Architecture Delivery Suite', 'purpose' => 'convert specs into governed patches releases and learning loops'],
                ['suite_id' => 'repo_intelligence_quality_suite', 'name' => 'Repo Intelligence Quality Suite', 'purpose' => 'map code dependencies tests ownership and repair risk'],
            ],
            'research' => [
                ['suite_id' => 'primary_source_research_suite', 'name' => 'Primary Source Research Suite', 'purpose' => 'produce claim linked research with citation and contradiction controls'],
                ['suite_id' => 'evidence_synthesis_suite', 'name' => 'Evidence Synthesis Suite', 'purpose' => 'turn verified sources into executive synthesis and reusable knowledge'],
            ],
            'strategy' => [
                ['suite_id' => 'venture_strategy_suite', 'name' => 'Venture Strategy Suite', 'purpose' => 'build thesis market maps and board decision dossiers'],
                ['suite_id' => 'experiment_capital_allocator_suite', 'name' => 'Experiment Capital Allocator Suite', 'purpose' => 'score opportunities experiments and capital options'],
            ],
            'finance' => [
                ['suite_id' => 'financial_research_terminal_suite', 'name' => 'Financial Research Terminal Suite', 'purpose' => 'unify market filings macro and internal context for analyst workflows'],
                ['suite_id' => 'investment_committee_modeling_suite', 'name' => 'Investment Committee Modeling Suite', 'purpose' => 'produce valuation diligence risk accounting audit and KYC packets'],
            ],
            'marketing' => [
                ['suite_id' => 'growth_command_suite', 'name' => 'Growth Command Suite', 'purpose' => 'operate growth strategy channel mix lifecycle and experiment review'],
                ['suite_id' => 'brand_creative_factory_suite', 'name' => 'Brand Creative Factory Suite', 'purpose' => 'produce positioning creative briefs copy packs and voice of customer synthesis'],
            ],
            'cyber' => [
                ['suite_id' => 'security_posture_suite', 'name' => 'Security Posture Suite', 'purpose' => 'manage appsec posture vulnerability triage and attack surface deltas'],
                ['suite_id' => 'grc_detection_response_suite', 'name' => 'GRC Detection Response Suite', 'purpose' => 'map controls propose detections and prepare incident readiness'],
            ],
            'automation' => [
                ['suite_id' => 'automation_design_suite', 'name' => 'Automation Design Suite', 'purpose' => 'select tools design browser and API workflows and MCP adapters'],
                ['suite_id' => 'automation_reliability_suite', 'name' => 'Automation Reliability Suite', 'purpose' => 'replay automations measure reliability and manage blocked external actions'],
            ],
            'personal_development' => [
                ['suite_id' => 'executive_growth_suite', 'name' => 'Executive Growth Suite', 'purpose' => 'operate goals reflection habits learning and skill gap diagnosis'],
                ['suite_id' => 'privacy_learning_memory_suite', 'name' => 'Privacy Learning Memory Suite', 'purpose' => 'protect private memory while improving curriculum and practice loops'],
            ],
            default => [
                ['suite_id' => 'operations_reliability_suite', 'name' => 'Operations Reliability Suite', 'purpose' => 'run readiness incident command capacity and SLO review'],
                ['suite_id' => 'runbook_learning_suite', 'name' => 'Runbook Learning Suite', 'purpose' => 'improve runbooks postmortems alert quality and change readiness'],
            ],
        };

        $commonSuites = [
            ['suite_id' => 'domain_data_connector_suite', 'name' => 'Domain Data Connector Suite', 'purpose' => 'normalize domain sources connectors MCP or API workbenches and lineage'],
            ['suite_id' => 'agent_workforce_suite', 'name' => 'Agent Workforce Suite', 'purpose' => 'coordinate specialist agents handoffs guardrails tracing and human checkpoints'],
            ['suite_id' => 'delivery_factory_suite', 'name' => 'Delivery Factory Suite', 'purpose' => 'produce typed artifacts with quality gates receipts and handoff packets'],
            ['suite_id' => 'risk_compliance_assurance_suite', 'name' => 'Risk Compliance Assurance Suite', 'purpose' => 'enforce policy risk legal privacy GRC audit and external action blocks'],
            ['suite_id' => 'learning_optimization_suite', 'name' => 'Learning Optimization Suite', 'purpose' => 'convert replays metrics feedback and incidents into improved playbooks'],
        ];

        return array_values(array_merge($domainSuites, $commonSuites));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseDomainBusinessExecutionMeshStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $executionModes = $this->domainExecutionModes($domainId);
        $workProducts = array_values(array_unique(array_merge(
            array_values((array) $blueprint['work_products']),
            array_values(array_map(static fn (array $spec): string => (string) ($spec[2] ?? 'enterprise_artifact'), $flowSpecs)),
        )));

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_business_execution_mesh_stack.v1',
            'company_id' => $domainId,
            'execution_policy' => [
                'mode' => 'domain_business_execution_mesh_with_internal_runtime_and_manual_external_handoff',
                'calendar_wait_blocker_enabled' => false,
                'domain_specific_execution_required_for_every_flow' => true,
                'service_lane_required_for_every_flow' => true,
                'kpi_contract_required_for_every_flow' => true,
                'external_side_effects_default' => false,
                'autonomous_external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
            ],
            'execution_mode_catalog' => array_values(array_map(
                static fn (string $mode): array => [
                    'mode_id' => $mode,
                    'allowed_runtime_modes' => ['fixture', 'internal_runtime', 'shadow_read', 'supervised_manual_handoff'],
                    'blocked_without_operator_mandate' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                    'mode_hash' => hash('sha256', 'domain_execution_mode|'.$mode),
                ],
                $executionModes,
            )),
            'flow_execution_cells' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_business_execution_cell.v1',
                    'cell_id' => $domainId.'.'.$flowId.'.business_execution_cell.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'execution_mode' => (string) ($executionModes[$index % max(1, count($executionModes))] ?? 'advisory_delivery'),
                    'connector_refs' => array_values((array) $spec[1]),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'primary_work_product' => (string) $spec[2],
                    'operating_steps' => ['intake', 'source_refresh', 'tool_read', 'domain_analysis', 'artifact_build', 'critic_review', 'business_decision_packet', 'operator_handoff', 'learning_update'],
                    'required_business_evidence' => ['objective_hash', 'source_lineage_hash', 'tool_receipt_hash', 'artifact_hash', 'decision_packet_hash', 'handoff_hash', 'learning_delta_hash'],
                    'cell_hash' => hash('sha256', $domainId.'|business_execution_cell|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_tool_kpi_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_tool_kpi_binding.v1',
                    'flow_id' => $flowId,
                    'tool_refs' => array_values((array) $spec[1]),
                    'kpi_refs' => array_values(array_slice($companyMetrics, $index % max(1, count($companyMetrics)), min(3, count($companyMetrics)))),
                    'decision_cadence' => $index % 2 === 0 ? 'weekly_operating_board' : 'per_run_quality_review',
                    'success_evidence' => ['accepted_work_product', 'source_faithfulness_score', 'policy_gate_green', 'operator_handoff_acceptance'],
                    'binding_hash' => hash('sha256', $domainId.'|flow_tool_kpi|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'domain_service_lanes' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_service_lane.v1',
                    'lane_id' => $domainId.'.'.$flowId.'.service_lane.v1',
                    'flow_id' => $flowId,
                    'queue' => $domainId.'.'.$flowId.'.execution_queue',
                    'wip_limit' => 2 + ($index % 3),
                    'sla' => $index % 2 === 0 ? 'same_business_day_internal_packet' : 'next_business_day_internal_packet',
                    'review_roles' => ['owner_agent', 'independent_reviewer_agent', 'policy_gate_agent', 'operator_for_external_effect'],
                    'lane_hash' => hash('sha256', $domainId.'|domain_service_lane|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'business_artifact_delivery_contracts' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.business_artifact_delivery_contract.v1',
                    'work_product' => $workProduct,
                    'acceptance_sections' => ['objective', 'business_context', 'source_lineage', 'analysis', 'recommendation', 'risk_controls', 'decision_or_handoff', 'receipt_hash'],
                    'quality_bar' => 0.9,
                    'review_cadence' => $index % 2 === 0 ? 'per_artifact' : 'weekly_sampling_plus_exception_review',
                    'external_delivery_allowed' => false,
                    'delivery_contract_hash' => hash('sha256', $domainId.'|business_artifact_delivery|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'execution_observability' => [
                'required_metrics' => [
                    'business_execution_cell_coverage',
                    'flow_tool_kpi_binding_coverage',
                    'service_lane_coverage',
                    'business_artifact_acceptance_rate',
                    'source_lineage_completeness',
                    'policy_gate_pass_rate',
                    'operator_handoff_acceptance_rate',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_business_execution_mesh_board',
                'alert_on' => ['missing_execution_cell', 'missing_kpi_binding', 'service_lane_sla_breach', 'artifact_quality_failure', 'policy_gate_failure', 'external_effect_requested'],
            ],
            'execution_mesh_hash' => hash('sha256', $domainId.'|business_execution_mesh|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $companyMetrics)),
        ];
    }

    /**
     * @return list<string>
     */
    private function domainExecutionModes(string $domainId): array
    {
        return match ($domainId) {
            'software' => ['spec_to_patch_delivery', 'repo_quality_repair', 'release_certification', 'security_review_handoff'],
            'research' => ['primary_source_research', 'citation_graph_synthesis', 'contradiction_resolution', 'executive_brief_delivery'],
            'strategy' => ['market_map_analysis', 'venture_thesis_diligence', 'capital_experiment_design', 'board_memo_delivery'],
            'finance' => ['financial_research_terminal', 'valuation_modeling', 'risk_diligence', 'investment_committee_packet'],
            'marketing' => ['growth_strategy_operations', 'creative_brief_factory', 'campaign_readout', 'lifecycle_experiment_design'],
            'cyber' => ['security_posture_review', 'vulnerability_triage', 'grc_control_mapping', 'detection_response_planning'],
            'automation' => ['automation_opportunity_analysis', 'browser_workflow_design', 'api_workflow_design', 'tool_reliability_review'],
            'personal_development' => ['goal_review', 'learning_curriculum_planning', 'habit_system_design', 'privacy_review'],
            default => ['readiness_review', 'incident_command', 'runbook_update', 'capacity_slo_review'],
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseDomainProviderWorkbenchStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_provider_workbench_stack.v1',
            'company_id' => $domainId,
            'workbench_policy' => [
                'mode' => 'domain_provider_workbenches_with_read_only_probe_first',
                'calendar_wait_blocker_enabled' => false,
                'provider_write_or_paid_action_default' => false,
                'real_provider_terms_review_required' => true,
                'credential_material_in_packet_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'source_claims_require_provider_lineage' => true,
            ],
            'provider_contracts' => array_values(array_map(
                static fn (array $source): array => [
                    'schema' => 'atlas.ai.company.domain_provider_contract.v1',
                    'provider_id' => (string) $source['source_id'],
                    'url' => (string) $source['url'],
                    'use' => (string) $source['use'],
                    'integration_modes' => ['documentation_reference', 'manual_import_fixture', 'read_only_api_probe', 'mcp_or_adapter_candidate'],
                    'required_reviews' => ['terms', 'security', 'privacy', 'rate_limits', 'data_retention', 'fallback'],
                    'minimum_evidence_before_runtime' => ['source_review_hash', 'terms_review_hash', 'adapter_contract_hash', 'fixture_hash', 'read_probe_receipt_hash'],
                    'external_side_effects_enabled' => false,
                    'contract_hash' => hash('sha256', 'domain_provider_contract|'.(string) $source['source_id']),
                ],
                $sources,
            )),
            'connector_workbenches' => array_values(array_map(
                static fn (string $connector, int $index): array => [
                    'connector_id' => $connector,
                    'workbench_id' => $connector.'.domain_provider_workbench.v1',
                    'primary_provider_id' => (string) ($sourceIds[$index % max(1, count($sourceIds))] ?? 'internal_fixture_provider'),
                    'supported_modes' => ['fixture', 'manual_import', 'read_only_probe', 'shadow_read'],
                    'required_capabilities' => ['schema_snapshot', 'sample_payload', 'lineage_capture', 'receipt_export', 'rate_limit_envelope', 'fallback_fixture'],
                    'blocked_capabilities_without_signed_scope' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                    'mcp_or_api_adapter_contract_required' => true,
                    'credential_binding' => 'vault_reference_only',
                    'external_side_effects_enabled' => false,
                    'workbench_hash' => hash('sha256', 'domain_provider_workbench|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'flow_provider_routes' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'required_connectors' => array_values((array) $spec[1]),
                    'primary_provider_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(3, count($sourceIds)))),
                    'route_stages' => ['select_provider', 'load_fixture_or_read_probe', 'normalize_context', 'produce_artifact', 'verify_lineage', 'operator_review'],
                    'required_receipts' => ['provider_selection_hash', 'input_payload_hash', 'tool_receipt_hash', 'lineage_hash', 'artifact_hash', 'review_hash'],
                    'external_execution_allowed' => false,
                    'route_hash' => hash('sha256', 'flow_provider_route|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'provider_evaluation_cases' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'case_pack_id' => $flowId.'.provider_eval_cases.v1',
                    'minimum_cases_before_shadow' => 15,
                    'case_types' => ['happy_path', 'stale_data', 'permission_denied', 'schema_drift', 'rate_limited', 'conflicting_sources', 'missing_lineage'],
                    'required_scores' => ['schema_match', 'lineage_completeness', 'source_faithfulness', 'fallback_quality', 'policy_compliance'],
                    'promotion_requires_green_provider_eval' => true,
                    'case_hash' => hash('sha256', 'provider_eval_cases|'.$flowId),
                ],
                $flowIds,
            )),
            'provider_data_product_lineage' => array_values(array_map(
                static fn (string $sourceId): array => [
                    'provider_id' => $sourceId,
                    'lineage_contract' => $sourceId.'.provider_lineage.v1',
                    'required_fields' => ['source_uri', 'retrieved_at_or_version', 'adapter_hash', 'normalization_hash', 'consumer_flow_id', 'output_hash'],
                    'staleness_disclosure_required' => true,
                    'redaction_before_model_or_external_tool_required' => true,
                    'lineage_hash' => hash('sha256', 'provider_data_product_lineage|'.$sourceId),
                ],
                $sourceIds,
            )),
            'provider_workbench_observability' => [
                'required_metrics' => [
                    'provider_contract_coverage',
                    'workbench_probe_pass_rate',
                    'provider_eval_pass_rate',
                    'lineage_completeness_rate',
                    'schema_drift_count',
                    'rate_limit_exception_count',
                    'fallback_usage_rate',
                    'operator_review_coverage',
                ],
                'dashboard' => $domainId.'_domain_provider_workbench_board',
                'alert_on' => ['terms_review_missing', 'credential_scope_missing', 'schema_drift', 'lineage_missing', 'provider_eval_failed', 'external_effect_requested'],
            ],
            'provider_workbench_hash' => hash('sha256', $domainId.'|domain_provider_workbench|'.implode('|', $sourceIds).'|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseIntegrationActivationPlan(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowIds = array_keys((array) $blueprint['flow_specs']);

        return [
            'schema' => 'atlas.ai.company.enterprise_integration_activation_plan.v1',
            'company_id' => $domainId,
            'activation_policy' => [
                'buildout_blocked_by_observed_history_window' => false,
                'external_write_blocked_until_operator_mandate' => true,
                'default_mode' => 'contract_ready_read_only_probe',
                'promotion_sequence' => ['contract', 'sandbox_probe', 'shadow_mode', 'supervised_production'],
                'kill_switch_required' => true,
            ],
            'source_activation_tracks' => array_values(array_map(
                static fn (array $source): array => [
                    'source_id' => (string) $source['source_id'],
                    'url' => (string) $source['url'],
                    'activation_steps' => [
                        'confirm_terms_and_allowed_use',
                        'define_read_only_adapter_contract',
                        'create_fixture_payload',
                        'run_sandbox_or_documentation_probe',
                        'capture_probe_receipt',
                        'attach_to_evaluation_harness',
                    ],
                    'required_evidence' => ['terms_review', 'adapter_contract', 'fixture_hash', 'probe_receipt', 'eval_result'],
                    'current_mode' => 'reference_catalog_ready',
                    'external_side_effects_enabled' => false,
                    'track_hash' => hash('sha256', 'source_activation_track|'.(string) $source['source_id']),
                ],
                $sources,
            )),
            'connector_activation_tracks' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'activation_steps' => [
                        'bind_secret_placeholder_or_internal_adapter',
                        'verify_least_privilege_scope',
                        'execute_read_only_health_check',
                        'record_tool_receipt',
                        'wire_metric_and_alert',
                        'document_manual_fallback',
                    ],
                    'health_check_contract' => [
                        'timeout_seconds' => 10,
                        'must_return_receipt' => true,
                        'must_not_mutate_external_state' => true,
                        'failure_mode' => 'block_flow_and_emit_review_packet',
                    ],
                    'shadow_mode_ready_when' => ['health_check_green', 'fixture_eval_green', 'fallback_documented', 'operator_reviewed'],
                    'external_side_effects_enabled' => false,
                    'track_hash' => hash('sha256', 'connector_activation_track|'.$connector),
                ],
                $connectors,
            )),
            'flow_activation_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'minimum_stage' => 'sandbox_probe',
                    'required_activation_evidence' => [
                        'input_fixture',
                        'runtime_blueprint',
                        'source_track_receipt',
                        'connector_health_receipt',
                        'critic_review',
                        'operator_checkpoint_if_external',
                    ],
                    'shadow_mode_entry_criteria' => ['all_required_connectors_green', 'eval_score_at_or_above_floor', 'policy_findings_zero'],
                    'supervised_production_entry_criteria' => ['operator_signed_mandate', 'rollback_plan_present', 'incident_route_configured'],
                    'matrix_hash' => hash('sha256', 'flow_activation_matrix|'.$flowId),
                ],
                $flowIds,
            )),
            'activation_observability' => [
                'required_signals' => ['activation_stage', 'probe_status', 'receipt_hash', 'policy_status', 'fixture_eval_score', 'fallback_status'],
                'dashboard' => $domainId.'_integration_activation_board',
                'alert_route' => (string) $blueprint['review_queue'],
                'weekly_review_required' => true,
            ],
            'activation_hash' => hash('sha256', $domainId.'|integration_activation|'.implode('|', array_column($sources, 'source_id')).'|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseIndustrySolutionEcosystemStack(string $domainId, array $blueprint): array
    {
        $sources = $this->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $partnerTracks = $this->enterpriseImplementationPartnerTracks($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_industry_solution_ecosystem_stack.v1',
            'company_id' => $domainId,
            'ecosystem_policy' => [
                'reference_pattern' => 'claude_financial_services_style_industry_solution_adapted_per_company',
                'source_url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'unified_data_interface_required' => true,
                'direct_source_hyperlinks_required' => true,
                'mcp_or_api_connector_workbench_required' => true,
                'implementation_partner_playbook_required' => true,
                'expanded_workload_capacity_model_required' => true,
                'audit_trail_required_for_every_claim_and_artifact' => true,
                'confidential_data_not_used_for_training_assumption_required' => true,
                'external_write_spend_trade_publish_deploy_delete_blocked_without_operator_mandate' => true,
                'external_side_effects_enabled' => false,
            ],
            'industry_data_interface' => [
                'schema' => 'atlas.ai.company.industry_data_interface.v1',
                'interface_id' => $domainId.'.industry_data_interface.v1',
                'source_ids' => $sourceIds,
                'connector_ids' => $connectors,
                'normalization_layers' => ['source_adapter', 'lineage_capture', 'redaction', 'domain_schema', 'claim_linker', 'artifact_export'],
                'verification_controls' => ['cross_source_check', 'source_hyperlink', 'staleness_disclosure', 'confidence_note', 'human_review_on_conflict'],
                'data_protection_controls' => ['vault_reference_only', 'least_privilege_scope', 'tenant_isolation', 'redaction_before_model_context', 'retention_review'],
                'interface_hash' => hash('sha256', $domainId.'|industry_data_interface|'.implode('|', $sourceIds).'|'.implode('|', $connectors)),
            ],
            'ecosystem_provider_catalog' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'schema' => 'atlas.ai.company.industry_ecosystem_provider.v1',
                    'provider_id' => (string) $source['source_id'],
                    'url' => (string) $source['url'],
                    'use' => (string) $source['use'],
                    'mapped_connector_id' => (string) ($connectors[$index % max(1, count($connectors))] ?? 'internal_fixture_connector'),
                    'adoption_mode' => 'reference_manual_import_read_only_probe_then_supervised_connector',
                    'required_before_live_use' => ['terms_review', 'security_review', 'privacy_review', 'adapter_contract', 'sandbox_probe', 'fallback_fixture'],
                    'claim_verification_required' => true,
                    'external_side_effects_enabled' => false,
                    'provider_hash' => hash('sha256', 'industry_ecosystem_provider|'.(string) $source['source_id']),
                ],
                $sources,
                array_keys($sources),
            )),
            'implementation_partner_tracks' => $partnerTracks,
            'flow_solution_workload_packs' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_solution_workload_pack.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'target_artifact' => (string) $spec[2],
                    'primary_source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'required_connectors' => array_values((array) $spec[1]),
                    'workload_patterns' => [
                        'research_or_context_intake',
                        'multi_source_analysis',
                        'domain_model_or_artifact_generation',
                        'compliance_or_policy_check',
                        'audit_trail_export',
                        'operator_review_packet',
                    ],
                    'capacity_profile' => [
                        'supports_deadline_or_event_spike' => true,
                        'requires_queue_and_dlq' => true,
                        'requires_replay_dataset' => true,
                        'requires_cost_and_latency_metering' => true,
                    ],
                    'source_verification' => [
                        'every_material_claim_links_source' => true,
                        'conflicting_sources_create_adjudication_packet' => true,
                        'missing_source_blocks_external_delivery' => true,
                    ],
                    'external_execution_allowed' => false,
                    'workload_hash' => hash('sha256', 'flow_solution_workload_pack|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'enterprise_adoption_program' => [
                'phases' => ['source_and_terms_review', 'fixture_implementation', 'read_only_probe', 'workflow_training', 'shadow_run', 'supervised_internal_run', 'manual_external_handoff'],
                'enablement_artifacts' => ['domain_playbook', 'review_rubric', 'operator_training_packet', 'fallback_runbook', 'incident_route'],
                'procurement_and_billing_mode' => 'prepared_packet_only_no_auto_procurement',
                'expert_implementation_support_required' => true,
                'external_contracting_allowed_by_stack' => false,
            ],
            'audit_and_confidentiality_controls' => [
                'claim_to_source_map_required' => true,
                'artifact_hash_required' => true,
                'tool_receipt_hash_required' => true,
                'operator_review_hash_required' => true,
                'client_or_private_data_training_exclusion_attestation_required' => true,
                'data_room_or_private_context_requires_vault_scope' => true,
                'secret_material_in_packet_allowed' => false,
            ],
            'ecosystem_observability' => [
                'required_metrics' => [
                    'source_link_coverage',
                    'provider_contract_coverage',
                    'connector_probe_green_rate',
                    'implementation_partner_track_coverage',
                    'workload_pack_coverage',
                    'audit_trail_completeness',
                    'confidentiality_attestation_coverage',
                    'external_effect_block_rate',
                ],
                'dashboard' => $domainId.'_industry_solution_ecosystem_board',
                'alert_on' => ['missing_source_link', 'provider_terms_missing', 'partner_track_missing', 'audit_gap', 'external_effect_requested'],
            ],
            'ecosystem_hash' => hash('sha256', $domainId.'|industry_solution_ecosystem|'.implode('|', $sourceIds).'|'.implode('|', $connectors).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function enterpriseImplementationPartnerTracks(string $domainId): array
    {
        $tracks = [
            ['track_id' => 'accenture_scale_adoption', 'focus' => 'front_middle_back_office_scaleout_and_operating_model'],
            ['track_id' => 'deloitte_research_productivity', 'focus' => 'research_workflow_productivity_and_analyst_augmentation'],
            ['track_id' => 'kpmg_agent_deployment', 'focus' => 'developer_and_domain_agent_deployment_governance'],
            ['track_id' => 'pwc_regulatory_pathfinder', 'focus' => 'obligation_mapping_gap_analysis_policy_updates'],
            ['track_id' => 'slalom_modernization_and_operations', 'focus' => 'legacy_modernization_and_end_to_end_operations_transformation'],
            ['track_id' => 'tribeai_deal_material_review', 'focus' => 'document_intelligence_entity_resolution_and_due_diligence'],
            ['track_id' => 'turing_compliance_benchmarking', 'focus' => 'compliance_requirements_generation_and_benchmarking'],
        ];

        return array_values(array_map(
            static fn (array $track): array => [
                ...$track,
                'schema' => 'atlas.ai.company.implementation_partner_track.v1',
                'source_basis' => 'anthropic_claude_for_financial_services_partner_ecosystem',
                'company_adaptation' => $domainId,
                'deliverables' => ['implementation_plan', 'training_plan', 'governance_mapping', 'measurement_model', 'handoff_runbook'],
                'commercial_status' => 'reference_track_no_auto_procurement',
                'operator_procurement_required' => true,
                'external_side_effects_enabled' => false,
                'track_hash' => hash('sha256', 'implementation_partner_track|'.$domainId.'|'.$track['track_id']),
            ],
            $tracks,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseDomainCompanyExecutionSuiteStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->domainCompanyExecutionSuiteSources($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->domainCompanyExecutionSuiteProfile($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_company_execution_suite_stack.v1',
            'company_id' => $domainId,
            'suite_policy' => [
                'mode' => 'ultra_premium_enterprise_domain_company_execution_suite',
                'calendar_wait_blocker_enabled' => false,
                'domain_specific_flow_suite_required' => true,
                'connector_read_only_probe_required_before_external_effect' => true,
                'source_linked_artifact_required' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'external_side_effects_enabled' => false,
            ],
            'source_catalog' => $sources,
            'domain_operating_model' => [
                'schema' => 'atlas.ai.company.domain_company_execution_operating_model.v1',
                'domain_category' => (string) $profile['category'],
                'operating_roles' => (array) $profile['roles'],
                'workbenches' => (array) $profile['workbenches'],
                'decision_cadences' => (array) $profile['cadences'],
                'required_controls' => ['source_lineage', 'receipt_export', 'critic_review', 'operator_checkpoint', 'replay_harness', 'rollback_plan', 'external_effect_block'],
                'blocked_external_actions' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export', 'contract_signature'],
                'operating_model_hash' => hash('sha256', $domainId.'|domain_company_execution_operating_model'),
            ],
            'connector_execution_workbenches' => array_values(array_map(
                static fn (string $connector, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_connector_execution_workbench.v1',
                    'connector_id' => $connector,
                    'workbench_id' => $connector.'.execution_workbench.v1',
                    'primary_source_id' => (string) ($sourceIds[$index % max(1, count($sourceIds))] ?? 'internal_fixture_source'),
                    'supported_modes' => ['fixture', 'manual_import', 'read_only_probe', 'shadow_read', 'supervised_handoff'],
                    'required_receipts' => ['schema_snapshot', 'sample_payload_hash', 'lineage_hash', 'tool_receipt_hash', 'fallback_receipt_hash'],
                    'blocked_operations_without_operator' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'least_privilege_scope_required' => true,
                    'external_side_effects_enabled' => false,
                    'workbench_hash' => hash('sha256', 'domain_connector_execution_workbench|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'flow_domain_execution_packets' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_domain_execution_packet.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'target_artifact' => (string) $spec[2],
                    'execution_workbench' => (string) (((array) $profile['workbenches'])[$index % max(1, count((array) $profile['workbenches']))] ?? 'domain_execution_workbench'),
                    'source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'connector_scope' => array_values((array) $spec[1]),
                    'execution_stages' => ['intake', 'source_bind', 'tool_plan', 'analysis_or_action_simulation', 'critic_review', 'risk_review', 'operator_packet', 'delivery_or_handoff'],
                    'required_artifacts' => ['input_dossier', 'source_lineage_map', 'tool_receipt_bundle', 'domain_analysis_artifact', 'risk_review_packet', 'operator_handoff_packet'],
                    'quality_gates' => ['source_lineage_complete', 'no_policy_findings', 'reviewer_acceptance', 'replay_or_fixture_green', 'rollback_path_present'],
                    'external_execution_allowed' => false,
                    'packet_hash' => hash('sha256', 'flow_domain_execution_packet|'.$domainId.'|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_domain_risk_control_packets' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_domain_risk_control_packet.v1',
                    'flow_id' => $flowId,
                    'control_frameworks' => (array) $profile['control_frameworks'],
                    'risk_checks' => (array) $profile['risk_checks'],
                    'required_evidence' => ['policy_profile', 'source_lineage', 'receipt_hashes', 'control_mapping', 'exception_register', 'operator_review'],
                    'exception_handling' => ['block_external_effect', 'open_review_item', 'attach_remediation_plan', 'record_acceptance_or_rejection'],
                    'external_exception_acceptance_allowed' => false,
                    'control_hash' => hash('sha256', 'flow_domain_risk_control|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_domain_decision_room_packets' => array_values(array_map(
                fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_domain_decision_room_packet.v1',
                    'flow_id' => $flowId,
                    'decision_room_id' => 'decision_room.'.$domainId.'.'.$flowId,
                    'decision_types' => (array) $profile['decision_types'],
                    'required_sections' => ['executive_summary', 'evidence_table', 'options_considered', 'risk_register', 'recommended_next_action', 'operator_decision_log', 'rollback_or_exit_path'],
                    'cadence' => (string) (((array) $profile['cadences'])[$index % max(1, count((array) $profile['cadences']))] ?? 'per_flow_review'),
                    'auto_decision_allowed' => false,
                    'decision_room_hash' => hash('sha256', 'flow_domain_decision_room|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                array_keys($flowIds),
            )),
            'flow_domain_replay_and_eval_packs' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_domain_replay_eval_pack.v1',
                    'flow_id' => $flowId,
                    'case_types' => ['happy_path', 'missing_source', 'conflicting_sources', 'policy_sensitive_request', 'connector_failure', 'stale_data', 'operator_rejection'],
                    'minimum_cases' => 25,
                    'required_scores' => ['source_faithfulness', 'risk_precision', 'handoff_quality', 'artifact_completeness', 'policy_compliance', 'fallback_quality'],
                    'promotion_requires_green_replay' => true,
                    'eval_hash' => hash('sha256', 'flow_domain_replay_eval|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'domain_execution_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    (array) $companyMetrics,
                    ['source_lineage_coverage', 'connector_probe_green_rate', 'risk_review_completion', 'decision_room_readiness', 'replay_eval_pass_rate', 'external_effect_block_rate']
                ))),
                'dashboard' => $domainId.'_domain_company_execution_suite_board',
                'alert_on' => ['missing_source_lineage', 'connector_probe_failed', 'risk_review_missing', 'decision_room_stale', 'external_effect_requested', 'replay_eval_failed'],
            ],
            'domain_execution_suite_hash' => hash('sha256', $domainId.'|domain_company_execution_suite|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $sourceIds)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainCompanyExecutionSuiteProfile(string $domainId): array
    {
        return match ($domainId) {
            'cyber' => [
                'category' => 'security_operations_appsec_grc_detection_response',
                'roles' => ['security_architect', 'appsec_triage_lead', 'grc_control_owner', 'detection_engineer', 'incident_commander', 'remediation_manager'],
                'workbenches' => ['security_posture_workbench', 'vulnerability_triage_queue', 'control_mapping_lab', 'detection_engineering_lab', 'incident_readiness_room'],
                'cadences' => ['daily_posture_review', 'per_finding_triage', 'weekly_detection_review', 'monthly_grc_attestation'],
                'control_frameworks' => ['nist_csf_2', 'mitre_attack_enterprise', 'owasp_asvs', 'cisa_kev', 'soc2_style_control_evidence'],
                'risk_checks' => ['known_exploited_vulnerability', 'attack_path_exposure', 'auth_boundary_gap', 'data_exfiltration_risk', 'unreviewed_offensive_action', 'missing_remediation_owner'],
                'decision_types' => ['prioritize_remediation', 'approve_detection_rule', 'accept_or_reject_control_gap', 'escalate_incident_readiness'],
            ],
            'strategy' => [
                'category' => 'market_strategy_portfolio_intelligence_and_board_decisions',
                'roles' => ['market_intelligence_lead', 'venture_thesis_owner', 'portfolio_strategy_partner', 'experiment_allocator', 'board_memo_editor', 'competitive_analyst'],
                'workbenches' => ['market_map_room', 'rival_intelligence_table', 'venture_thesis_lab', 'experiment_allocation_board', 'board_memo_factory'],
                'cadences' => ['weekly_opportunity_committee', 'per_experiment_review', 'monthly_strategy_board', 'quarterly_portfolio_reset'],
                'control_frameworks' => ['source_reliability_matrix', 'assumption_register', 'decision_log', 'counterfactual_review', 'portfolio_risk_register'],
                'risk_checks' => ['weak_source_basis', 'unclear_customer_segment', 'unpriced_execution_risk', 'strategy_without_metric', 'overfit_to_single_source', 'capital_allocation_without_review'],
                'decision_types' => ['approve_thesis', 'rank_opportunity', 'allocate_experiment_budget', 'revise_go_to_market_motion'],
            ],
            'finance' => [
                'category' => 'financial_research_treasury_billing_risk_and_investment_committee',
                'roles' => ['financial_research_lead', 'valuation_model_owner', 'treasury_controller', 'billing_ops_owner', 'model_risk_reviewer', 'investment_committee_secretary'],
                'workbenches' => ['financial_research_terminal', 'valuation_model_room', 'treasury_risk_board', 'billing_reconciliation_desk', 'investment_committee_room'],
                'cadences' => ['daily_cash_risk_review', 'weekly_forecast_review', 'monthly_close_review', 'per_investment_committee'],
                'control_frameworks' => ['source_linked_financial_claims', 'model_risk_management', 'cash_movement_segregation', 'close_and_audit_evidence', 'billing_reconciliation'],
                'risk_checks' => ['unlinked_financial_claim', 'model_assumption_drift', 'cash_movement_request', 'billing_exception', 'capital_commitment_without_committee', 'unreviewed_market_data_staleness'],
                'decision_types' => ['approve_forecast', 'escalate_model_risk', 'prepare_investment_packet', 'block_cash_or_trade_action'],
            ],
            'marketing' => [
                'category' => 'growth_marketing_brand_lifecycle_and_attribution',
                'roles' => ['growth_strategy_lead', 'brand_steward', 'creative_director', 'lifecycle_operator', 'attribution_analyst', 'claim_review_owner'],
                'workbenches' => ['growth_intelligence_room', 'campaign_factory', 'claim_evidence_lab', 'attribution_model_room', 'brand_review_queue'],
                'cadences' => ['weekly_growth_review', 'per_campaign_review', 'monthly_brand_claim_audit', 'quarterly_channel_mix_review'],
                'control_frameworks' => ['brand_policy', 'source_backed_claims', 'privacy_review', 'budget_guardrails', 'crm_handoff_quality'],
                'risk_checks' => ['unverified_claim', 'public_publish_request', 'paid_spend_request', 'privacy_sensitive_audience', 'brand_mismatch', 'unsupported_roi_claim'],
                'decision_types' => ['approve_internal_campaign', 'route_brand_revision', 'rank_experiment', 'block_public_claim'],
            ],
            'software' => [
                'category' => 'software_engineering_delivery_release_security_and_repair',
                'roles' => ['principal_architect', 'code_intelligence_owner', 'release_captain', 'security_reviewer', 'test_repair_owner', 'developer_experience_lead'],
                'workbenches' => ['architecture_decision_room', 'patch_repair_queue', 'test_failure_lab', 'release_certification_room', 'security_review_queue'],
                'cadences' => ['per_patch_repair_loop', 'daily_ci_certification', 'weekly_security_review', 'per_release_go_no_go'],
                'control_frameworks' => ['test_matrix', 'code_review_receipts', 'release_slo', 'security_findings', 'rollback_plan'],
                'risk_checks' => ['untested_patch', 'breaking_contract_change', 'security_regression', 'missing_rollback', 'unreviewed_dependency', 'release_without_receipt'],
                'decision_types' => ['approve_patch_plan', 'merge_or_repair', 'promote_release', 'block_security_regression'],
            ],
            'research' => [
                'category' => 'primary_research_source_graph_synthesis_and_evidence_delivery',
                'roles' => ['primary_source_researcher', 'citation_graph_curator', 'contradiction_reviewer', 'methodology_critic', 'executive_brief_editor', 'source_auditor'],
                'workbenches' => ['source_discovery_room', 'citation_graph_lab', 'contradiction_resolution_table', 'methodology_review_queue', 'brief_delivery_factory'],
                'cadences' => ['per_research_question', 'weekly_source_quality_review', 'monthly_methodology_audit', 'per_executive_brief'],
                'control_frameworks' => ['source_quality_rubric', 'citation_lineage', 'contradiction_register', 'methodology_notes', 'confidence_calibration'],
                'risk_checks' => ['unsupported_claim', 'stale_source', 'citation_gap', 'contradictory_evidence', 'low_confidence_without_disclosure', 'private_data_leak'],
                'decision_types' => ['accept_source', 'resolve_contradiction', 'publish_internal_brief', 'route_methodology_review'],
            ],
            default => [
                'category' => 'operations_automation_delivery_assurance_and_control_tower',
                'roles' => ['operations_controller', 'automation_architect', 'process_owner', 'slo_manager', 'incident_coordinator', 'continuous_improvement_lead'],
                'workbenches' => ['process_command_center', 'automation_design_lab', 'runbook_drill_room', 'capacity_slo_board', 'incident_exception_desk'],
                'cadences' => ['daily_operations_review', 'weekly_capacity_review', 'per_incident_postmortem', 'monthly_process_improvement'],
                'control_frameworks' => ['runbook_evidence', 'slo_sli_catalog', 'incident_postmortem', 'change_control', 'automation_risk_register'],
                'risk_checks' => ['runbook_gap', 'capacity_overrun', 'automation_without_fallback', 'incident_route_missing', 'change_without_window', 'external_action_without_mandate'],
                'decision_types' => ['prioritize_run', 'approve_internal_automation', 'escalate_incident', 'schedule_change_window'],
            ],
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseFlowWorkProductDeliveryStack(string $domainId, array $blueprint, array $workProducts, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $profile = $this->domainCompanyExecutionSuiteProfile($domainId);

        $catalog = array_values(array_map(
            static fn (string $workProduct, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_work_product_catalog_item.v1',
                'work_product_id' => $workProduct,
                'artifact_schema_id' => $workProduct.'.enterprise_artifact.v1',
                'versioning_policy' => 'semantic_artifact_version_with_receipt_hash_and_source_lineage',
                'required_sections' => ['executive_summary', 'inputs_and_scope', 'source_lineage', 'analysis_or_execution_trace', 'risk_and_controls', 'recommended_next_action', 'handoff_and_rollback'],
                'acceptance_floor' => [
                    'source_faithfulness' => 0.95,
                    'domain_correctness' => 0.9,
                    'operator_readability' => 0.9,
                    'policy_findings_allowed' => 0,
                ],
                'catalog_order' => $index + 1,
                'external_delivery_allowed' => false,
                'catalog_hash' => hash('sha256', 'enterprise_work_product_catalog|'.$domainId.'|'.$workProduct),
            ],
            $workProducts,
            array_keys($workProducts),
        ));

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1',
            'company_id' => $domainId,
            'delivery_policy' => [
                'mode' => 'source_linked_operator_ready_business_artifact_delivery',
                'calendar_wait_blocker_enabled' => false,
                'external_delivery_allowed' => false,
                'customer_visible_claim_allowed_without_source_and_operator_review' => false,
                'real_customer_send_allowed' => false,
                'operator_acceptance_required_before_external_handoff' => true,
                'artifact_receipt_hash_required' => true,
                'external_side_effects_enabled' => false,
            ],
            'work_product_catalog' => $catalog,
            'flow_delivery_blueprints' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_delivery_blueprint.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'work_product_id' => (string) $spec[2],
                    'artifact_schema_id' => (string) $spec[2].'.enterprise_artifact.v1',
                    'delivery_lane' => (string) (((array) $profile['workbenches'])[$index % max(1, count((array) $profile['workbenches']))] ?? 'enterprise_delivery_lane'),
                    'input_contract' => [
                        'required_inputs' => ['operator_intent', 'company_context', 'source_refs', 'connector_scope', 'policy_profile', 'prior_receipts'],
                        'connector_scope' => array_values((array) $spec[1]),
                        'missing_input_behavior' => 'block_delivery_and_emit_review_packet',
                    ],
                    'artifact_sections' => ['executive_summary', 'decision_or_action_context', 'source_lineage_table', 'domain_analysis', 'risk_controls', 'quality_eval', 'operator_handoff', 'rollback_or_followup'],
                    'production_grade_requirements' => ['typed_schema', 'source_refs', 'receipt_hashes', 'redaction_review', 'domain_reviewer', 'operator_acceptance', 'replay_case_link'],
                    'external_delivery_allowed' => false,
                    'blueprint_hash' => hash('sha256', 'flow_work_product_delivery_blueprint|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_acceptance_contracts' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_acceptance_contract.v1',
                    'flow_id' => $flowId,
                    'acceptance_tests' => ['schema_valid', 'required_sections_present', 'source_lineage_complete', 'domain_reviewer_passed', 'policy_findings_zero', 'replay_reference_passed', 'operator_packet_ready'],
                    'quality_floor' => ['source_faithfulness' => 0.95, 'domain_correctness' => 0.9, 'handoff_clarity' => 0.9, 'risk_control_completeness' => 0.95],
                    'required_reviewers' => ['domain_reviewer', 'risk_or_policy_reviewer', 'operator_acceptance'],
                    'failure_modes' => ['missing_source', 'stale_data', 'connector_unavailable', 'policy_sensitive_output', 'unsupported_claim', 'handoff_rejected'],
                    'auto_accept_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_work_product_acceptance_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'flow_handoff_packets' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_handoff_packet.v1',
                    'flow_id' => $flowId,
                    'work_product_id' => (string) $spec[2],
                    'required_evidence' => ['artifact_hash', 'source_lineage_hash', 'tool_receipt_hashes', 'quality_gate_hash', 'risk_review_hash', 'operator_acceptance_hash', 'rollback_or_followup_plan'],
                    'handoff_targets' => ['company_command_center', 'operator_review_queue', 'portfolio_governance_if_cross_company_dependency'],
                    'external_handoff_mode' => 'manual_operator_supplied_channel_only',
                    'atlas_external_send_allowed' => false,
                    'packet_hash' => hash('sha256', 'flow_work_product_handoff_packet|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_replay_artifact_checks' => array_values(array_map(
                fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_work_product_replay_artifact_check.v1',
                    'flow_id' => $flowId,
                    'minimum_replay_cases' => 25,
                    'artifact_regression_checks' => ['section_stability', 'source_link_integrity', 'claim_support_consistency', 'risk_language_consistency', 'operator_handoff_completeness', 'redaction_boundary'],
                    'adversarial_cases' => ['unsupported_claim_request', 'conflicting_source_request', 'external_action_request', 'private_data_request', 'stale_market_or_policy_data'],
                    'promotion_requires_green_replay' => true,
                    'check_hash' => hash('sha256', 'flow_work_product_replay_artifact_check|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'delivery_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['artifact_schema_pass_rate', 'required_section_coverage', 'source_lineage_coverage', 'operator_acceptance_rate', 'handoff_rejection_rate', 'external_delivery_block_rate']
                ))),
                'dashboard' => $domainId.'_flow_work_product_delivery_board',
                'alert_on' => ['missing_required_section', 'source_lineage_gap', 'quality_floor_failed', 'operator_rejection', 'external_delivery_requested'],
            ],
            'delivery_stack_hash' => hash('sha256', $domainId.'|flow_work_product_delivery|'.implode('|', $flowIds).'|'.implode('|', $workProducts).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseDomainDataConnectorOperatingStack(string $domainId, array $blueprint, array $workProducts, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->domainCompanyExecutionSuiteSources($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->domainCompanyExecutionSuiteProfile($domainId);

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_data_connector_operating_stack.v1',
            'company_id' => $domainId,
            'data_connector_policy' => [
                'mode' => 'governed_domain_data_room_and_connector_operations',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'source_lineage_permission_profile_fixture_eval_connector_probe_and_operator_acceptance',
                'read_only_probe_required_before_live_use' => true,
                'write_tools_enabled' => false,
                'external_data_mutation_allowed' => false,
                'secret_export_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_data_room_catalog' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'schema' => 'atlas.ai.company.source_data_room_entry.v1',
                    'source_id' => (string) ($source['source_id'] ?? 'unknown_source'),
                    'source_url' => (string) ($source['url'] ?? ''),
                    'source_use' => (string) ($source['use'] ?? 'domain_reference'),
                    'data_room_id' => 'data_room.source.'.(string) ($source['source_id'] ?? 'unknown_source'),
                    'minimum_evidence' => ['terms_review', 'security_review', 'schema_snapshot', 'freshness_policy', 'lineage_capture', 'fallback_source'],
                    'default_access_mode' => $index === 0 ? 'canonical_reference_read' : 'reference_or_fixture_read',
                    'external_side_effects_enabled' => false,
                    'entry_hash' => hash('sha256', 'source_data_room_entry|'.$domainId.'|'.(string) ($source['source_id'] ?? 'unknown_source')),
                ],
                $sources,
                array_keys($sources),
            )),
            'domain_data_products' => array_values(array_map(
                static fn (string $workProduct, int $index): array => [
                    'schema' => 'atlas.ai.company.domain_data_product_contract.v1',
                    'data_product_id' => $workProduct.'.data_product.v1',
                    'work_product_id' => $workProduct,
                    'owning_lane' => 'domain_data_room',
                    'source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'contract_sections' => ['schema', 'source_lineage', 'freshness', 'quality_rules', 'privacy_boundary', 'consumer_flows', 'receipt_requirements'],
                    'quality_rules' => ['schema_valid', 'source_linked', 'freshness_disclosed', 'duplicates_handled', 'redaction_checked', 'operator_readable'],
                    'mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'domain_data_product_contract|'.$domainId.'|'.$workProduct),
                ],
                $workProducts,
                array_keys($workProducts),
            )),
            'connector_permission_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_permission_profile.v1',
                    'connector_id' => $connector,
                    'profile_id' => $connector.'.permission_profile.v1',
                    'allowed_modes' => ['fixture', 'manual_import', 'schema_snapshot', 'read_only_probe', 'receipt_export'],
                    'blocked_modes_without_operator_mandate' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'required_controls' => ['least_privilege_scope', 'credential_vault_reference', 'permission_report', 'rate_limit_plan', 'audit_log_export', 'disable_plan'],
                    'write_tools_enabled' => false,
                    'permission_hash' => hash('sha256', 'connector_permission_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_data_connector_contracts' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_data_connector_contract.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'work_product_id' => (string) $spec[2],
                    'required_connectors' => array_values((array) $spec[1]),
                    'required_source_ids' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(4, count($sourceIds)))),
                    'required_data_products' => [(string) $spec[2].'.data_product.v1', 'source_lineage_bundle', 'tool_receipt_bundle'],
                    'data_contract_gates' => ['schema_snapshot_present', 'permission_profile_present', 'source_lineage_complete', 'freshness_policy_applied', 'privacy_boundary_passed', 'operator_handoff_ready'],
                    'minimum_fixture_cases' => 25,
                    'live_connector_mode' => 'read_only_probe_until_operator_mandate',
                    'external_mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_data_connector_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'connector_fixture_eval_suites' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.connector_fixture_eval_suite.v1',
                    'flow_id' => $flowId,
                    'case_mix' => ['happy_path', 'missing_source', 'stale_source', 'conflicting_source', 'permission_denied', 'connector_timeout', 'private_data_boundary', 'external_mutation_request'],
                    'minimum_case_count' => 25,
                    'required_scores' => ['schema_validity', 'lineage_completeness', 'freshness_disclosure', 'permission_boundary', 'fallback_quality', 'receipt_completeness'],
                    'promotion_requires_green_eval' => true,
                    'eval_hash' => hash('sha256', 'connector_fixture_eval_suite|'.$flowId),
                ],
                $flowIds,
            )),
            'domain_data_room_operating_model' => [
                'schema' => 'atlas.ai.company.domain_data_room_operating_model.v1',
                'operating_roles' => (array) $profile['roles'],
                'workbenches' => (array) $profile['workbenches'],
                'decision_cadences' => (array) $profile['cadences'],
                'required_controls' => ['source_lineage', 'permission_profiles', 'fixture_eval', 'privacy_boundary', 'receipt_export', 'fallback_source', 'operator_acceptance'],
                'blocked_external_actions' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                'operating_model_hash' => hash('sha256', $domainId.'|domain_data_room_operating_model'),
            ],
            'data_connector_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['source_lineage_coverage', 'permission_profile_coverage', 'schema_snapshot_coverage', 'fixture_eval_pass_rate', 'read_only_probe_pass_rate', 'freshness_disclosure_rate', 'external_mutation_block_rate']
                ))),
                'dashboard' => $domainId.'_domain_data_connector_operating_board',
                'alert_on' => ['missing_permission_profile', 'schema_snapshot_missing', 'lineage_gap', 'fixture_eval_failed', 'external_mutation_requested', 'secret_export_requested'],
            ],
            'data_connector_stack_hash' => hash('sha256', $domainId.'|domain_data_connector_operating|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $sourceIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseFlowLiveReadConnectorProbeStack(string $domainId, array $blueprint, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1',
            'company_id' => $domainId,
            'probe_policy' => [
                'mode' => 'flow_scoped_live_read_or_sandbox_probe_before_external_effect',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'signed_scope_schema_snapshot_sample_payload_permission_report_lineage_and_probe_receipt',
                'live_read_allowed' => true,
                'write_tools_enabled' => false,
                'external_mutation_allowed' => false,
                'credential_material_in_packet_allowed' => false,
                'operator_scope_required_before_live_connector_probe' => true,
                'operator_mandate_required_for_any_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'connector_probe_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.live_read_connector_probe_profile.v1',
                    'connector_id' => $connector,
                    'profile_id' => $connector.'.live_read_probe_profile.v1',
                    'allowed_probe_modes' => ['fixture_probe', 'sandbox_read', 'live_read_only'],
                    'required_scope_artifacts' => ['vault_scope_reference', 'permission_report', 'schema_snapshot', 'sample_payload_hash', 'rate_limit_budget', 'disable_plan'],
                    'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
                    'credential_material_in_packet_allowed' => false,
                    'external_mutation_allowed' => false,
                    'profile_hash' => hash('sha256', 'live_read_connector_probe_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_live_read_probe_contracts' => array_values(array_map(
                fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_live_read_probe_contract.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'work_product_id' => (string) $spec[2],
                    'required_pre_probe_evidence' => ['operator_scope', 'connector_permission_profile', 'schema_snapshot_plan', 'privacy_boundary', 'fallback_fixture', 'rollback_or_disable_plan'],
                    'required_probe_outputs' => ['probe_receipt_hash', 'schema_snapshot_hash', 'sample_payload_hash', 'source_lineage_hash', 'permission_scope_hash', 'redaction_review_hash', 'latency_and_error_budget'],
                    'failure_handling' => ['block_flow_promotion', 'open_review_item', 'fallback_to_fixture', 'record_connector_exception', 'notify_operator_queue'],
                    'minimum_probe_cases' => 12,
                    'external_mutation_allowed' => false,
                    'contract_hash' => hash('sha256', 'flow_live_read_probe_contract|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'flow_probe_evidence_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'schema' => 'atlas.ai.company.flow_live_read_probe_evidence_matrix.v1',
                    'flow_id' => $flowId,
                    'required_green_evidence' => ['scope_bound', 'permission_profile_bound', 'schema_snapshot_bound', 'sample_payload_bound', 'lineage_bound', 'redaction_review_bound', 'fallback_fixture_bound', 'probe_receipt_bound'],
                    'promotion_stage_unlocked' => 'shadow_readiness_not_external_write_authority',
                    'promotion_requires_operator_acceptance' => true,
                    'external_execution_authority_granted' => false,
                    'matrix_hash' => hash('sha256', 'flow_live_read_probe_evidence_matrix|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
            )),
            'probe_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    $companyMetrics,
                    ['live_read_probe_coverage', 'schema_snapshot_coverage', 'sample_payload_hash_coverage', 'permission_scope_match_rate', 'probe_failure_rate', 'fallback_fixture_use_rate', 'external_mutation_block_rate']
                ))),
                'dashboard' => $domainId.'_flow_live_read_connector_probe_board',
                'alert_on' => ['missing_operator_scope', 'permission_scope_mismatch', 'schema_snapshot_missing', 'probe_failed', 'external_mutation_requested', 'credential_material_detected'],
            ],
            'probe_stack_hash' => hash('sha256', $domainId.'|flow_live_read_connector_probe|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function domainCompanyExecutionSuiteSources(string $domainId): array
    {
        $common = [
            ['source_id' => 'openai_agents_sdk', 'url' => 'https://openai.github.io/openai-agents-python/', 'use' => 'agents_handoffs_guardrails_sessions_tracing'],
            ['source_id' => 'model_context_protocol', 'url' => 'https://modelcontextprotocol.io/', 'use' => 'tool_and_data_connector_context_protocol'],
        ];

        $domain = match ($domainId) {
            'cyber' => [
                ['source_id' => 'nist_csf_2', 'url' => 'https://www.nist.gov/cyberframework', 'use' => 'cybersecurity_governance_risk_and_control_outcomes'],
                ['source_id' => 'mitre_attack_enterprise', 'url' => 'https://attack.mitre.org/matrices/enterprise/', 'use' => 'threat_modeling_detection_and_response_mapping'],
                ['source_id' => 'owasp_asvs', 'url' => 'https://owasp.org/www-project-application-security-verification-standard/', 'use' => 'application_security_verification_requirements'],
                ['source_id' => 'cisa_kev', 'url' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog', 'use' => 'exploited_vulnerability_prioritization'],
                ['source_id' => 'semgrep', 'url' => 'https://github.com/semgrep/semgrep', 'use' => 'static_analysis_rule_reference'],
            ],
            'strategy' => [
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'public_company_filings_and_market_diligence'],
                ['source_id' => 'world_bank_data', 'url' => 'https://data.worldbank.org/', 'use' => 'macro_market_and_country_data'],
                ['source_id' => 'fred', 'url' => 'https://fred.stlouisfed.org/', 'use' => 'economic_time_series_for_strategy'],
                ['source_id' => 'google_trends', 'url' => 'https://trends.google.com/trends/', 'use' => 'market_interest_signal_reference'],
                ['source_id' => 'crunchbase', 'url' => 'https://www.crunchbase.com/', 'use' => 'company_and_funding_landscape_reference'],
            ],
            'finance' => [
                ['source_id' => 'anthropic_financial_services', 'url' => 'https://www.anthropic.com/news/claude-for-financial-services', 'use' => 'financial_services_reference_architecture'],
                ['source_id' => 'sec_edgar_apis', 'url' => 'https://www.sec.gov/search-filings/edgar-application-programming-interfaces', 'use' => 'financial_filings'],
                ['source_id' => 'stripe_billing', 'url' => 'https://stripe.com/billing/features', 'use' => 'billing_revenue_operations'],
                ['source_id' => 'fred', 'url' => 'https://fred.stlouisfed.org/', 'use' => 'macro_financial_data'],
                ['source_id' => 'openbb', 'url' => 'https://github.com/OpenBB-finance/OpenBB', 'use' => 'financial_research_terminal_reference'],
            ],
            'marketing' => [
                ['source_id' => 'hubspot_marketing_hub', 'url' => 'https://www.hubspot.com/products/marketing', 'use' => 'marketing_campaign_and_lifecycle_reference'],
                ['source_id' => 'salesforce_marketing_cloud', 'url' => 'https://www.salesforce.com/marketing/', 'use' => 'enterprise_marketing_cloud_reference'],
                ['source_id' => 'amplitude_experiment', 'url' => 'https://amplitude.com/experiment', 'use' => 'experimentation_reference'],
                ['source_id' => 'segment_cdp', 'url' => 'https://segment.com/', 'use' => 'customer_data_platform_reference'],
                ['source_id' => 'google_analytics', 'url' => 'https://analytics.google.com/', 'use' => 'attribution_and_analytics_reference'],
            ],
            default => [
                ['source_id' => 'servicenow_ai_agents', 'url' => 'https://www.servicenow.com/products/ai-agents.html', 'use' => 'enterprise_operations_ai_agents_reference'],
                ['source_id' => 'temporal', 'url' => 'https://github.com/temporalio/temporal', 'use' => 'durable_workflow_orchestration_reference'],
                ['source_id' => 'opentelemetry', 'url' => 'https://opentelemetry.io/', 'use' => 'tracing_metrics_logs_observability_reference'],
                ['source_id' => 'grafana', 'url' => 'https://github.com/grafana/grafana', 'use' => 'operations_dashboard_reference'],
                ['source_id' => 'linear', 'url' => 'https://linear.app/', 'use' => 'issue_and_workflow_tracking_reference'],
            ],
        };

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'schema' => 'atlas.ai.company.domain_company_execution_source.v1',
                'domain_id' => $domainId,
                'evidence_required_before_adoption' => ['source_review', 'terms_or_license_review', 'security_review', 'fixture_eval', 'operator_acceptance'],
                'external_side_effects_enabled' => false,
                'source_hash' => hash('sha256', 'domain_company_execution_source|'.$domainId.'|'.$source['source_id']),
            ],
            array_values(array_merge($domain, $common)),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseOperationalDressRehearsalStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);

        return [
            'schema' => 'atlas.ai.company.enterprise_operational_dress_rehearsal_stack.v1',
            'company_id' => $domainId,
            'rehearsal_policy' => [
                'mode' => 'staging_and_read_only_live_rehearsal_before_supervised_production',
                'calendar_wait_blocker_enabled' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'production_cutover_allowed_without_signed_acceptance' => false,
                'operator_and_domain_owner_acceptance_required' => true,
                'second_reviewer_required_for_spend_trade_publish_security_or_delete_scope' => true,
            ],
            'flow_rehearsal_runbooks' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_operational_dress_rehearsal_runbook.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_scope' => array_values((array) $spec[1]),
                    'target_artifact' => (string) $spec[2],
                    'staging_sequence' => [
                        'load_latest_company_packet',
                        'resolve_connector_preflight_contracts',
                        'bind_read_only_or_fixture_credentials',
                        'execute_shadow_run_with_live_read_if_available',
                        'compare_against_fixture_baseline',
                        'emit_rehearsal_receipt',
                        'open_operator_acceptance_packet',
                    ],
                    'acceptance_criteria' => [
                        'policy_findings_zero',
                        'source_lineage_complete',
                        'tool_receipts_complete',
                        'artifact_quality_score_green',
                        'rollback_drill_green',
                        'operator_acceptance_recorded',
                    ],
                    'blocked_during_rehearsal' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security'],
                    'runbook_hash' => hash('sha256', 'operational_dress_rehearsal_runbook|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'live_read_probe_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'probe_mode' => 'live_read_only_or_sandbox_fixture',
                    'required_before_supervised_production' => [
                        'vault_reference_attested',
                        'scope_matches_contract',
                        'sample_payload_redacted',
                        'lineage_ref_present',
                        'receipt_hash_emitted',
                        'no_external_mutation_attested',
                    ],
                    'failure_action' => 'fall_back_to_fixture_and_block_cutover',
                    'mutation_allowed' => false,
                    'probe_hash' => hash('sha256', 'operational_live_read_probe|'.$connector),
                ],
                $connectors,
            )),
            'operator_acceptance_packets' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'acceptance_packet_id' => $domainId.'.'.$flowId.'.operator_acceptance.v1',
                    'required_signatures' => ['operator', 'domain_owner'],
                    'second_reviewer_required_when' => ['spend_scope', 'trade_scope', 'publish_scope', 'security_scope', 'delete_scope'],
                    'required_artifacts' => [
                        'rehearsal_receipt_hash',
                        'artifact_hash',
                        'source_lineage_summary',
                        'policy_gate_result',
                        'rollback_drill_receipt',
                        'customer_or_stakeholder_acceptance_note',
                    ],
                    'auto_accept_allowed' => false,
                    'external_execution_enabled_by_packet' => false,
                    'acceptance_hash' => hash('sha256', 'operator_acceptance_packet|'.$domainId.'|'.$flowId.'|'.(string) $spec[2]),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'rollback_drill_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'drill_steps' => [
                        'snapshot_before_rehearsal',
                        'simulate_connector_failure',
                        'degrade_to_fixture_or_manual_import',
                        'emit_compensation_packet_if_external_intent_exists',
                        'verify_dashboard_and_alert_state',
                    ],
                    'success_criteria' => ['no_external_state_changed', 'fallback_artifact_available', 'incident_route_ready', 'operator_interrupt_verified'],
                    'required_before_any_external_mutation' => true,
                    'rollback_hash' => hash('sha256', 'operational_rollback_drill|'.$flowId),
                ],
                $flowIds,
            )),
            'promotion_evidence_matrix' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'promotion_stage' => 'supervised_production_candidate_external_blocked_until_signed_scope',
                    'must_have_green' => [
                        'fixture_suite',
                        'shadow_runtime',
                        'connector_preflight',
                        'live_read_probe',
                        'rollback_drill',
                        'operator_acceptance',
                    ],
                    'calendar_wait_days_required' => 0,
                    'external_side_effects_enabled' => false,
                    'promotion_hash' => hash('sha256', 'operational_promotion_evidence|'.$flowId),
                ],
                $flowIds,
            )),
            'dress_rehearsal_observability' => [
                'required_metrics' => [
                    'rehearsal_pass_rate',
                    'live_read_probe_pass_rate',
                    'rollback_drill_pass_rate',
                    'operator_acceptance_coverage',
                    'artifact_quality_green_rate',
                    'policy_findings_zero_rate',
                    'cutover_blocker_count',
                ],
                'dashboard' => $domainId.'_operational_dress_rehearsal_board',
                'alert_on' => ['live_probe_failed', 'rollback_drill_failed', 'operator_acceptance_missing', 'policy_finding_present', 'external_mutation_requested'],
            ],
            'dress_rehearsal_hash' => hash('sha256', $domainId.'|operational_dress_rehearsal|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function premiumEnterpriseAgentReferenceModel(string $domainId, array $blueprint): array
    {
        $templates = $this->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $sourceIds = array_column($this->domainSolutionSourceCatalog($domainId), 'source_id');

        return [
            'schema' => 'atlas.ai.company.premium_enterprise_agent_reference_model.v1',
            'company_id' => $domainId,
            'model_policy' => [
                'target_tier' => 'ultra_premium_enterprise_company',
                'calendar_wait_blocker_enabled' => false,
                'calendar_history_replaced_by' => 'fixture_shadow_connector_probe_replay_and_current_operating_packet_evidence',
                'external_side_effects_default' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_or_security_action' => true,
                'completion_claim_requires' => [
                    'all_flow_templates_mapped',
                    'all_connectors_have_read_only_probe_plan',
                    'all_templates_have_replay_harness',
                    'current_operating_packet_green',
                    'zero_policy_findings',
                ],
            ],
            'reference_source_basis' => $this->premiumReferenceSourceBasis($domainId),
            'managed_agent_templates' => $templates,
            'enterprise_agentic_architecture_basis' => [
                'schema' => 'atlas.ai.company.enterprise_agentic_architecture_basis.v1',
                'agent_runtime_patterns' => [
                    'openai_agents_sdk' => 'handoffs_guardrails_tools_sessions_and_full_trace_export',
                    'crewai_flows' => 'stateful_flow_orchestration_with_crews_persistence_and_resume',
                    'microsoft_agent_framework' => 'durable_workflows_human_in_loop_parallel_branching_and_observability',
                    'model_context_protocol' => 'standard_connector_protocol_for_secure_tool_and_data_access',
                    'temporal_workflows' => 'replayable_business_process_state_retries_and_idempotency',
                    'opentelemetry' => 'trace_metric_log_export_for_agent_runtime_audit',
                ],
                'required_runtime_properties' => ['typed_inputs_outputs', 'tool_permission_scope', 'handoff_contracts', 'guardrails', 'durable_state', 'human_interrupts', 'trace_export', 'replay_dataset', 'policy_receipts'],
                'architecture_hash' => hash('sha256', $domainId.'|enterprise_agentic_architecture_basis|'.implode('|', $templateIds)),
            ],
            'template_runtime_contracts' => array_values(array_map(
                static fn (array $template): array => [
                    'schema' => 'atlas.ai.company.premium_template_runtime_contract.v1',
                    'template_id' => (string) $template['template_id'],
                    'required_capabilities' => ['domain_context_loading', 'tool_use_policy', 'handoff_emit_and_accept', 'guardrail_check', 'trace_export', 'critic_review', 'receipt_export'],
                    'required_runtime_events' => ['template_selected', 'context_loaded', 'tool_scope_checked', 'handoff_emitted', 'guardrail_evaluated', 'artifact_reviewed', 'receipt_exported'],
                    'forbidden_without_operator' => ['external_write', 'spend', 'trade', 'publish', 'deploy', 'delete', 'offensive_security', 'secret_export'],
                    'contract_hash' => hash('sha256', 'premium_template_runtime_contract|'.$template['template_id']),
                ],
                $templates,
            )),
            'flow_template_map' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_template_id' => (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator'),
                    'supporting_template_ids' => array_values(array_slice($templateIds, 0, min(3, count($templateIds)))),
                    'required_workbench' => $flowId.'.premium_workbench.v1',
                    'required_artifacts' => [(string) $spec[2], 'source_lineage', 'tool_receipts', 'critic_review', 'operator_checkpoint'],
                    'external_side_effects' => false,
                    'map_hash' => hash('sha256', 'premium_flow_template_map|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_managed_agent_workflows' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_managed_agent_workflow.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'primary_template_id' => (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator'),
                    'workflow_nodes' => ['intake', 'classify', 'retrieve', 'plan', 'tool_scope_check', 'execute_internal_or_read_only', 'critic_review', 'policy_gate', 'operator_checkpoint', 'artifact_handoff'],
                    'handoff_contracts' => ['owner_to_researcher', 'researcher_to_builder', 'builder_to_critic', 'critic_to_policy_gate', 'policy_gate_to_operator'],
                    'guardrails' => ['prompt_injection_check', 'pii_and_secret_boundary', 'regulated_claim_check', 'external_side_effect_block', 'budget_and_rate_limit_check'],
                    'durable_state' => ['state_hash_required' => true, 'resume_token_required' => true, 'idempotency_key_required' => true],
                    'trace_export_required' => true,
                    'workflow_hash' => hash('sha256', 'flow_managed_agent_workflow|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'data_and_tool_workbenches' => array_values(array_map(
                static fn (string $connector): array => [
                    'connector_id' => $connector,
                    'workbench_id' => $connector.'.premium_workbench.v1',
                    'access_mode' => 'read_only_or_internal_fixture_until_operator_mandate',
                    'required_capabilities' => ['schema_snapshot', 'sample_fixture', 'lineage_capture', 'tool_receipt_export', 'permission_scope_report'],
                    'blocked_capabilities_without_operator' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'offensive_operation', 'secret_export'],
                    'probe_required_before_shadow' => true,
                    'workbench_hash' => hash('sha256', 'premium_workbench|'.$connector),
                ],
                $connectors,
            )),
            'connector_mcp_server_plan' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.connector_mcp_server_plan.v1',
                    'connector_id' => $connector,
                    'server_mode' => 'reference_or_internal_adapter_until_security_review',
                    'required_tools' => ['schema_snapshot', 'read_sample', 'search_or_query', 'export_receipt', 'permission_report'],
                    'required_security_reviews' => ['auth_scope', 'rate_limit', 'data_boundary', 'audit_log', 'rollback_or_disable_plan'],
                    'write_tools_enabled' => false,
                    'plan_hash' => hash('sha256', 'connector_mcp_server_plan|'.$connector),
                ],
                $connectors,
            )),
            'domain_source_alignment' => array_values(array_map(
                static fn (string $sourceId): array => [
                    'source_id' => $sourceId,
                    'alignment_use' => 'domain_specific_reference_or_connector_candidate',
                    'adoption_state' => 'reference_ready_probe_required_before_runtime',
                    'review_required' => ['terms', 'security', 'data_boundary', 'rate_limits', 'fallback_plan'],
                    'alignment_hash' => hash('sha256', 'premium_source_alignment|'.$sourceId),
                ],
                $sourceIds,
            )),
            'replay_and_audit_harness' => [
                'schema' => 'atlas.ai.company.premium_replay_audit_harness.v1',
                'benchmarks_per_flow' => array_values(array_map(
                    static fn (string $flowId): array => [
                        'flow_id' => $flowId,
                        'dataset_contract' => $flowId.'.offline_cases.v1',
                        'minimum_case_count_before_shadow' => 25,
                        'required_scores' => ['task_success', 'evidence_faithfulness', 'decision_determinism', 'trace_completeness', 'policy_boundary', 'handoff_quality', 'guardrail_precision'],
                        'replay_required_before_promotion' => true,
                        'benchmark_hash' => hash('sha256', 'premium_replay_benchmark|'.$flowId),
                    ],
                    $flowIds,
                )),
                'audit_artifacts' => ['input_hash', 'tool_call_trace', 'source_refs', 'output_hash', 'critic_score', 'decision_receipt_hash'],
                'determinism_and_faithfulness_measured_separately' => true,
                'telemetry_first_governance' => true,
                'harness_hash' => hash('sha256', $domainId.'|premium_replay_audit_harness|'.implode('|', $flowIds)),
            ],
            'accelerated_activation_contract' => [
                'buildout_wait_days_required' => 0,
                'why_no_calendar_wait' => 'maturity_is_proven_by_green_evidence_not_time_elapsed',
                'activation_sequence' => [
                    'contract_packet_green',
                    'fixture_suite_green',
                    'connector_read_only_probe_green',
                    'runbook_drill_green',
                    'shadow_readiness_green',
                    'operator_mandate_before_external_effect',
                ],
                'promotion_blockers' => ['missing_current_evidence', 'policy_finding', 'connector_probe_missing', 'runbook_drill_missing', 'operator_mandate_missing_for_external_action'],
            ],
            'premium_model_hash' => hash('sha256', $domainId.'|premium_enterprise_agent_reference_model|'.implode('|', $templateIds).'|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function premiumReferenceSourceBasis(string $domainId): array
    {
        $sources = [
            [
                'source_id' => 'anthropic_agents_for_financial_services_2026',
                'url' => 'https://www.anthropic.com/news/finance-agents',
                'adopted_pattern' => 'skills_connectors_subagents_managed_agents_per_tool_permissions_credential_vault_and_audit_log',
                'applies_to' => $domainId === 'finance' ? 'primary_finance_template_catalog' : 'enterprise_template_packaging_pattern',
            ],
            [
                'source_id' => 'anthropic_claude_for_financial_services_2025',
                'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                'adopted_pattern' => 'financial_analysis_solution_custom_apps_compliance_underwriting_customer_and_back_office_transformation',
                'applies_to' => $domainId === 'finance' ? 'finance_solution_reference' : 'regulated_workflow_reference',
            ],
            [
                'source_id' => 'kpmg_enterprise_ai_agents_strategy_2026',
                'url' => 'https://kpmg.com/us/en/articles/2026/enterprise-ai-agents-strategy.html',
                'adopted_pattern' => 'enterprise_readiness_governance_operating_model_modern_architecture_and_measurement',
                'applies_to' => 'all_companies',
            ],
            [
                'source_id' => 'replayable_financial_agents_dfah_2026',
                'url' => 'https://arxiv.org/abs/2601.15322',
                'adopted_pattern' => 'measure_decision_determinism_and_evidence_faithfulness_independently_for_audit_replay',
                'applies_to' => 'regulated_and_high_risk_flow_replay',
            ],
            [
                'source_id' => 'ai_trust_os_2026',
                'url' => 'https://arxiv.org/abs/2604.04749',
                'adopted_pattern' => 'telemetry_first_continuous_governance_zero_trust_observability_and_trust_artifacts',
                'applies_to' => 'portfolio_control_tower_and_company_observability',
            ],
        ];

        return array_values(array_map(
            static fn (array $source): array => [
                ...$source,
                'review_state' => 'reference_reviewed_for_architecture_not_runtime_ingested',
                'source_hash' => hash('sha256', 'premium_reference_source|'.$source['source_id']),
            ],
            $sources,
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseFlowOperatingPackages(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $templates = $this->premiumAgentTemplates($domainId);
        $templateIds = array_column($templates, 'template_id');
        $connectors = $this->enterpriseConnectorsForBlueprint($blueprint);
        $metrics = array_values((array) $blueprint['metrics']);
        $cadences = array_values((array) $blueprint['cadences']);
        $commandBase = $this->commandBase($domainId);
        $sourceBasis = array_values(array_merge(
            $this->flowRuntimeImplementationSourceCatalog(),
            $this->agentFrameworkSourceCatalog($domainId),
            $this->premiumReferenceSourceBasis($domainId),
        ));

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_operating_package_stack.v1',
            'company_id' => $domainId,
            'operating_policy' => [
                'target' => 'target_9_enterprise_company_flow_operability',
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'green_contract_fixture_replay_connector_probe_runbook_drill_and_current_operating_packet',
                'package_required_for_every_flow' => true,
                'external_execution_allowed_by_package' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'source_basis' => array_values(array_map(
                static fn (array $source): array => [
                    'source_id' => (string) ($source['source_id'] ?? 'unknown_source'),
                    'url' => (string) ($source['url'] ?? ''),
                    'pattern' => (string) ($source['pattern'] ?? $source['adopted_pattern'] ?? 'enterprise_agentic_operating_pattern'),
                ],
                $sourceBasis,
            )),
            'flow_packages' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => $this->enterpriseFlowOperatingPackage(
                    $domainId,
                    $flowId,
                    $spec,
                    $index,
                    $templateIds,
                    $connectors,
                    $metrics,
                    $cadences,
                    $commandBase,
                ),
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'portfolio_operations_layer' => [
                'cross_flow_queue' => $domainId.'_enterprise_flow_operations_queue',
                'priority_method' => 'risk_adjusted_value_sla_and_evidence_gap',
                'daily_dispatch_required' => true,
                'weekly_operating_board_required' => true,
                'operator_exception_queue' => (string) $blueprint['review_queue'],
                'blocked_work_policy' => 'blocked_items_keep_receipt_gap_and_next_evidence_action',
            ],
            'package_observability' => [
                'required_metrics' => [
                    'flow_package_coverage',
                    'source_basis_coverage',
                    'workbench_binding_coverage',
                    'replay_case_coverage',
                    'runbook_drill_coverage',
                    'operator_exception_latency',
                    'external_action_block_rate',
                ],
                'dashboard' => $domainId.'_enterprise_flow_operating_package_board',
                'alert_on' => ['missing_package', 'stale_source_basis', 'missing_workbench', 'replay_below_floor', 'runbook_drill_missing'],
            ],
            'package_stack_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_packages|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
    }

    /**
     * @param array<int,mixed> $spec
     * @param list<string> $templateIds
     * @param list<string> $connectors
     * @param list<string> $metrics
     * @param list<string> $cadences
     * @return array<string,mixed>
     */
    private function enterpriseFlowOperatingPackage(
        string $domainId,
        string $flowId,
        array $spec,
        int $index,
        array $templateIds,
        array $connectors,
        array $metrics,
        array $cadences,
        string $commandBase,
    ): array {
        $flowConnectors = array_values((array) ($spec[1] ?? []));
        $ownerAgent = (string) ($spec[0] ?? 'company_manager_agent');
        $artifact = (string) ($spec[2] ?? 'enterprise_packet');
        $templateId = (string) ($templateIds[$index % max(1, count($templateIds))] ?? 'enterprise_operator');

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_operating_package.v1',
            'package_id' => $domainId.'.'.$flowId.'.enterprise_operating_package.v1',
            'company_id' => $domainId,
            'flow_id' => $flowId,
            'owner_agent' => $ownerAgent,
            'premium_template_id' => $templateId,
            'operating_cell' => [
                'primary_agent' => $ownerAgent,
                'support_agents' => ['independent_reviewer_agent', 'policy_gate_agent', 'operations_coordinator_agent'],
                'cadence' => (string) ($cadences[$index % max(1, count($cadences))] ?? 'weekly_operating_board'),
                'review_queue' => $domainId.'_operator_review_queue',
                'decision_rights' => [
                    'plan_and_analyze' => $ownerAgent,
                    'quality_acceptance' => 'independent_reviewer_agent',
                    'external_action' => 'operator_signed_mandate_only',
                ],
            ],
            'tool_and_data_cell' => [
                'required_connectors' => $flowConnectors,
                'connector_workbenches' => array_values(array_map(
                    static fn (string $connector): array => [
                        'connector_id' => $connector,
                        'workbench_id' => $connector.'.premium_workbench.v1',
                        'access_mode' => 'read_only_or_internal_fixture_until_operator_mandate',
                        'must_emit' => ['schema_snapshot', 'sample_fixture', 'permission_scope_report', 'tool_receipt_hash'],
                        'blocked_capabilities' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_operation'],
                    ],
                    $flowConnectors,
                )),
                'shared_backplane_connectors' => $connectors,
                'data_boundary' => 'company_scoped_redacted_context_with_source_lineage',
            ],
            'runtime_cell' => [
                'command' => 'php artisan '.$commandBase.' --action='.$flowId.' --fixture --json',
                'fallback_command' => 'php artisan '.$commandBase.' --action=smoke --json',
                'runtime_nodes' => ['intake', 'plan', 'retrieve', 'tool_probe', 'domain_reasoning', 'artifact_draft', 'critic_review', 'policy_gate', 'operator_checkpoint', 'delivery_or_handoff'],
                'checkpoint_after_every_node' => true,
                'idempotency_key_required' => true,
                'state_hash_required' => true,
                'dead_letter_queue' => $domainId.'.'.$flowId.'.dlq',
                'resume_strategy' => 'resume_from_last_green_checkpoint_with_tool_receipts',
            ],
            'quality_replay_cell' => [
                'dataset_id' => $domainId.'.'.$flowId.'.enterprise_replay_dataset.v1',
                'minimum_cases_before_shadow' => 25,
                'case_mix' => ['happy_path', 'edge_case', 'ambiguous_request', 'missing_evidence', 'conflicting_evidence', 'forbidden_external_action', 'connector_outage'],
                'trace_rubric' => ['task_success', 'trajectory_correctness', 'source_faithfulness', 'policy_compliance', 'handoff_quality', 'cost_latency'],
                'minimum_scores' => [
                    'offline_eval' => 0.9,
                    'trace_grade' => 0.88,
                    'source_faithfulness' => 0.94,
                    'policy_compliance' => 1.0,
                ],
                'replay_modes' => ['contract_fixture', 'adversarial_regression', 'state_assertions', 'latest_runbook', 'memory_enabled_variant'],
                'promotion_without_green_replay_allowed' => false,
            ],
            'delivery_cell' => [
                'artifact_type' => $artifact,
                'required_sections' => ['executive_summary', 'source_lineage', 'analysis', 'risks', 'recommendation', 'next_actions', 'receipt_hash'],
                'quality_gate' => 'critic_score_policy_gate_and_schema_validation_green',
                'external_delivery_requires_operator_mandate' => true,
            ],
            'operations_cell' => [
                'sla_metric' => (string) ($metrics[$index % max(1, count($metrics))] ?? 'quality_score'),
                'response_sla' => $index % 3 === 0 ? 'same_business_day' : 'next_business_day',
                'rollback_or_compensation_plan_required' => true,
                'incident_route' => ['company_manager_agent', 'independent_reviewer_agent', 'portfolio_governor', 'operator'],
                'runbook_drill_required_before_supervised_mode' => true,
                'post_run_reconciliation_required' => true,
            ],
            'promotion_gates' => [
                'contract_ready' => ['package_present', 'input_output_schema_bound', 'workbench_scope_bound'],
                'fixture_ready' => ['fixture_dataset_25_cases', 'tool_receipts_green', 'policy_boundary_green'],
                'shadow_ready' => ['connector_probe_green', 'replay_score_green', 'runbook_drill_green'],
                'supervised_ready' => ['operator_mandate_present', 'rollback_plan_present', 'observability_dashboard_green'],
                'autonomy_claim_ready' => ['current_operating_packet_green', 'observed_runs_green', 'zero_policy_findings'],
            ],
            'external_execution_allowed' => false,
            'buildout_wait_days_required' => 0,
            'package_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_package|'.$flowId.'|'.$templateId.'|'.implode('|', $flowConnectors)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function premiumAgentTemplates(string $domainId): array
    {
        $templates = match ($domainId) {
            'software' => [
                ['template_id' => 'product_spec_architect', 'purpose' => 'turn_operator_intent_into_spec_architecture_and_acceptance_contract'],
                ['template_id' => 'repo_cartographer', 'purpose' => 'map_code_ownership_dependencies_tests_and_runtime_boundaries'],
                ['template_id' => 'patch_builder', 'purpose' => 'produce_minimal_patch_with_receipts_and_rollback_plan'],
                ['template_id' => 'test_repair_operator', 'purpose' => 'run_tests_triage_failures_and_repair_with_evidence'],
                ['template_id' => 'security_release_reviewer', 'purpose' => 'review_security_release_risk_and_certification_packet'],
                ['template_id' => 'post_release_learning_agent', 'purpose' => 'convert_results_into_playbook_and_fixture_improvements'],
                ['template_id' => 'dependency_modernization_agent', 'purpose' => 'plan_dependency_upgrades_version_pins_and_contract_test_rollout'],
                ['template_id' => 'observability_instrumentation_agent', 'purpose' => 'bind_logs_metrics_traces_and_runtime_slos_to_delivery_flows'],
                ['template_id' => 'incident_repair_commander', 'purpose' => 'triage_runtime_incidents_patch_candidates_and_rollback_decisions'],
                ['template_id' => 'developer_platform_operator', 'purpose' => 'operate_ci_harness_tooling_and_internal_developer_experience_backlog'],
            ],
            'research' => [
                ['template_id' => 'source_scout', 'purpose' => 'discover_primary_sources_and_rank_source_quality'],
                ['template_id' => 'claim_attributor', 'purpose' => 'bind_claims_to_source_refs_and_quote_boundaries'],
                ['template_id' => 'contradiction_judge', 'purpose' => 'surface_conflicts_and_decide_resolution_or_disclosure'],
                ['template_id' => 'citation_graph_builder', 'purpose' => 'build_source_and_citation_lineage'],
                ['template_id' => 'executive_synthesizer', 'purpose' => 'produce_decision_ready_research_briefs'],
                ['template_id' => 'watchlist_monitor', 'purpose' => 'track_source_deltas_and_refresh_staleness'],
                ['template_id' => 'methodology_reviewer', 'purpose' => 'review_study_design_sampling_bias_and_confidence_limits'],
                ['template_id' => 'data_room_librarian', 'purpose' => 'organize_private_context_source_sets_and_access_boundaries'],
                ['template_id' => 'expert_interview_analyst', 'purpose' => 'extract_claims_from_interviews_and_bind_them_to_verbatim_evidence'],
                ['template_id' => 'research_delivery_editor', 'purpose' => 'package_findings_into_operator_ready_briefs_with_disclosures'],
            ],
            'strategy' => [
                ['template_id' => 'venture_thesis_builder', 'purpose' => 'build_company_thesis_assumptions_and_market_logic'],
                ['template_id' => 'market_mapper', 'purpose' => 'map_competitors_segments_tam_sam_som_and_demand_signals'],
                ['template_id' => 'gtm_system_designer', 'purpose' => 'design_channel_offer_pricing_and_sales_motion'],
                ['template_id' => 'experiment_allocator', 'purpose' => 'rank_experiments_by_value_risk_speed_and_learning'],
                ['template_id' => 'competitive_wargamer', 'purpose' => 'simulate_rival_moves_and_defensive_options'],
                ['template_id' => 'board_dossier_writer', 'purpose' => 'package_decisions_for_operator_or_portfolio_review'],
                ['template_id' => 'pricing_packaging_strategist', 'purpose' => 'model_offer_packaging_pricing_power_and_willingness_to_pay_evidence'],
                ['template_id' => 'partnership_scout', 'purpose' => 'identify_distribution_technology_and_capital_partnership_options'],
                ['template_id' => 'portfolio_capital_allocator', 'purpose' => 'rank_company_investment_options_by_risk_capacity_and_expected_learning'],
                ['template_id' => 'moat_and_risk_reviewer', 'purpose' => 'stress_test_strategy_against_competition_regulation_and_execution_risk'],
            ],
            'finance' => [
                ['template_id' => 'pitch_builder', 'purpose' => 'create_target_lists_comparables_and_pitchbook_materials'],
                ['template_id' => 'meeting_preparer', 'purpose' => 'assemble_client_counterparty_and_asset_briefs'],
                ['template_id' => 'earnings_reviewer', 'purpose' => 'read_transcripts_filings_update_models_and_flag_thesis_changes'],
                ['template_id' => 'model_builder', 'purpose' => 'build_and_maintain_financial_models_from_filings_data_feeds_and_inputs'],
                ['template_id' => 'market_researcher', 'purpose' => 'track_sector_issuer_news_filings_research_and_risk_items'],
                ['template_id' => 'valuation_reviewer', 'purpose' => 'check_valuation_against_comparables_methodology_and_review_standards'],
                ['template_id' => 'general_ledger_reconciler', 'purpose' => 'reconcile_accounts_and_nav_style_book_records'],
                ['template_id' => 'month_end_closer', 'purpose' => 'run_close_checklists_prepare_journal_entries_and_close_reports'],
                ['template_id' => 'statement_auditor', 'purpose' => 'review_statements_for_consistency_completeness_and_audit_readiness'],
                ['template_id' => 'kyc_screener', 'purpose' => 'assemble_entity_files_review_documents_and_package_compliance_escalations'],
            ],
            'marketing' => [
                ['template_id' => 'growth_strategist', 'purpose' => 'build_growth_strategy_from_analytics_crm_and_market_research'],
                ['template_id' => 'icp_positioning_builder', 'purpose' => 'define_icp_pain_positioning_claims_and_proof_points'],
                ['template_id' => 'campaign_planner', 'purpose' => 'plan_campaign_objectives_channels_assets_and_approval_gates'],
                ['template_id' => 'creative_director', 'purpose' => 'produce_copy_briefs_creative_variants_and_brand_consistency_reviews'],
                ['template_id' => 'funnel_analyst', 'purpose' => 'diagnose_conversion_dropoffs_and_experiment_opportunities'],
                ['template_id' => 'lifecycle_operator', 'purpose' => 'design_lifecycle_and_retention_campaigns_without_auto_send'],
                ['template_id' => 'seo_content_operator', 'purpose' => 'plan_search_content_clusters_claim_evidence_and_refresh_cadence'],
                ['template_id' => 'paid_media_controller', 'purpose' => 'prepare_budget_pacing_audience_controls_and_spend_approval_packets'],
                ['template_id' => 'brand_claim_reviewer', 'purpose' => 'audit_public_claims_proof_points_compliance_and_tone_consistency'],
                ['template_id' => 'crm_revenue_ops_handoff', 'purpose' => 'convert_growth_signals_into_sales_crm_and_success_handoff_packets'],
            ],
            'cyber' => [
                ['template_id' => 'scope_and_roe_gatekeeper', 'purpose' => 'validate_authorized_scope_rules_of_engagement_and_allowed_actions'],
                ['template_id' => 'appsec_triager', 'purpose' => 'review_findings_reproducibility_severity_and_remediation'],
                ['template_id' => 'security_posture_analyst', 'purpose' => 'map_attack_surface_dependencies_vulnerabilities_and_exposure'],
                ['template_id' => 'grc_mapper', 'purpose' => 'map_controls_obligations_evidence_and_audit_gaps'],
                ['template_id' => 'detection_engineer', 'purpose' => 'draft_detection_rules_and_validation_plans'],
                ['template_id' => 'remediation_program_manager', 'purpose' => 'prioritize_fix_programs_tickets_and_sla_risk_without_unauthorized_mutation'],
                ['template_id' => 'threat_intel_correlator', 'purpose' => 'correlate_cves_advisories_assets_and_exploitability_signals'],
                ['template_id' => 'incident_response_scribe', 'purpose' => 'assemble_timeline_decision_log_evidence_and_post_incident_actions'],
                ['template_id' => 'identity_access_reviewer', 'purpose' => 'review_access_paths_privilege_risk_and_segregation_of_duties'],
                ['template_id' => 'secure_change_reviewer', 'purpose' => 'gate_security_sensitive_changes_with_rollback_and_monitoring_requirements'],
            ],
            'automation' => [
                ['template_id' => 'automation_architect', 'purpose' => 'select_high_roi_safe_automation_opportunities'],
                ['template_id' => 'tool_scout', 'purpose' => 'evaluate_repositories_tools_mcp_servers_and_api_surfaces'],
                ['template_id' => 'browser_workflow_designer', 'purpose' => 'design_browser_runbooks_with_screenshots_and_replay_fixtures'],
                ['template_id' => 'api_workflow_designer', 'purpose' => 'design_openapi_mcp_and_connector_workflows'],
                ['template_id' => 'terminal_workflow_designer', 'purpose' => 'draft_terminal_automation_with_dry_run_and_rollback'],
                ['template_id' => 'tool_reliability_operator', 'purpose' => 'monitor_receipts_replay_failures_and_regressions'],
                ['template_id' => 'integration_test_builder', 'purpose' => 'turn_automation_flows_into_fixtures_assertions_and_regression_suites'],
                ['template_id' => 'approval_policy_designer', 'purpose' => 'map_tool_permissions_approval_steps_and_forbidden_side_effects'],
                ['template_id' => 'workflow_queue_operator', 'purpose' => 'operate_prioritized_runs_retries_dead_letters_and_sla_backlogs'],
                ['template_id' => 'automation_roi_auditor', 'purpose' => 'measure_saved_time_quality_risk_and_maintenance_cost_per_automation'],
            ],
            'personal_development' => [
                ['template_id' => 'life_operating_reviewer', 'purpose' => 'review_goals_energy_focus_and_weekly_commitments'],
                ['template_id' => 'learning_designer', 'purpose' => 'build_curricula_practice_loops_and_skill_gap_maps'],
                ['template_id' => 'habit_system_designer', 'purpose' => 'design_habit_protocols_review_cadence_and_friction_removal'],
                ['template_id' => 'reflection_analyst', 'purpose' => 'synthesize_private_reflections_into_safe_learning_records'],
                ['template_id' => 'focus_planner', 'purpose' => 'plan_deep_work_blocks_priorities_and_review_checkpoints'],
                ['template_id' => 'privacy_guardian', 'purpose' => 'protect_private_memory_and_block_sensitive_externalization'],
                ['template_id' => 'performance_review_coach', 'purpose' => 'convert_weekly_evidence_into_skill_growth_and_behavior_adjustments'],
                ['template_id' => 'project_commitment_operator', 'purpose' => 'manage_personal_projects_next_actions_blockers_and_review_packets'],
                ['template_id' => 'decision_journal_analyst', 'purpose' => 'track_decisions_assumptions_outcomes_and_calibration_learning'],
                ['template_id' => 'wellbeing_boundary_reviewer', 'purpose' => 'surface_overload_risk_recovery_needs_and_private_boundary_controls'],
            ],
            default => [
                ['template_id' => 'readiness_reviewer', 'purpose' => 'review_slo_runbooks_capacity_and_operational_risk'],
                ['template_id' => 'incident_commander', 'purpose' => 'assemble_incident_timeline_diagnostics_and_decision_packet'],
                ['template_id' => 'runbook_engineer', 'purpose' => 'create_and_update_runbooks_from_operational_evidence'],
                ['template_id' => 'capacity_planner', 'purpose' => 'forecast_capacity_slo_risk_and_resource_needs'],
                ['template_id' => 'postmortem_editor', 'purpose' => 'convert_incidents_into_actions_and_learning_records'],
                ['template_id' => 'alert_noise_reducer', 'purpose' => 'diagnose_alert_quality_and_propose_noise_reduction'],
                ['template_id' => 'process_mining_analyst', 'purpose' => 'find_operational_bottlenecks_variance_and_automation_candidates'],
                ['template_id' => 'vendor_sla_controller', 'purpose' => 'track_vendor_service_levels_escalations_and_procurement_handoffs'],
                ['template_id' => 'change_management_operator', 'purpose' => 'prepare_change_windows_risk_reviews_and_communication_packets'],
                ['template_id' => 'continuous_improvement_allocator', 'purpose' => 'rank_process_improvement_backlog_by_value_risk_and_capacity'],
            ],
        };

        return array_values(array_map(
            static fn (array $template): array => [
                ...$template,
                'skills' => ['domain_context_loading', 'connector_reasoning', 'structured_artifact_authoring', 'risk_review', 'receipt_export', 'handoff_packet'],
                'connectors_required' => 'mapped_per_flow_or_workbench',
                'subagent_pattern' => 'specialist_subagent_for_research_methodology_or_critic_review',
                'tool_permissions' => 'least_privilege_read_or_fixture_until_operator_mandate',
                'audit_log_required' => true,
                'human_in_loop_required_before_external_action' => true,
                'template_hash' => hash('sha256', 'premium_agent_template|'.$template['template_id']),
            ],
            $templates,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function autonomyPromotionLadder(string $domainId): array
    {
        return [
            [
                'stage' => 'stage_1_internal_advisory',
                'allowed' => ['read_context', 'draft_internal_packet', 'open_review_item'],
                'blocked' => ['external_write', 'spend', 'trade', 'publish', 'deploy'],
                'promotion_evidence' => ['enterprise_buildout_ready', 'flow_contracts_green'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_1'),
            ],
            [
                'stage' => 'stage_2_supervised_internal_execution',
                'allowed' => ['execute_internal_flow', 'create_receipted_artifact', 'prepare_handoff_packet'],
                'blocked' => ['ungoverned_external_side_effect'],
                'promotion_evidence' => ['operating_cycle_observed', 'critic_score_green', 'policy_findings_zero'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_2'),
            ],
            [
                'stage' => 'stage_3_limited_external_preparation',
                'allowed' => ['prepare_external_action_packet', 'run_read_only_external_probe'],
                'blocked' => ['external_mutation_without_signed_operator_mandate'],
                'promotion_evidence' => ['integration_contract_ready', 'rollback_or_compensation_plan', 'operator_signed_checkpoint'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_3'),
            ],
            [
                'stage' => 'stage_4_real_external_autonomy_claim',
                'allowed' => ['bounded_external_action_after_signed_mandate'],
                'blocked' => ['unbounded_action', 'missing_audit_receipt', 'policy_exception'],
                'promotion_evidence' => ['current_operational_evidence_green', 'external_integration_proven', 'incident_free_or_reviewed_operation'],
                'stage_hash' => hash('sha256', $domainId.'|autonomy|stage_4'),
            ],
        ];
    }

    private function commandBase(string $domainId): string
    {
        return match ($domainId) {
            'software' => 'atlas:ai:engineering-company',
            'personal_development' => 'atlas:ai:personal-development-domain',
            default => 'atlas:ai:'.str_replace('_', '-', $domainId).'-domain',
        };
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function toolchain(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            fn (array $connector): array => [
                'connector_id' => (string) $connector['id'],
                'domain_id' => $domainId,
                'adapter_kind' => (string) $connector['kind'],
                'permission_model' => (string) $connector['side_effect_profile'],
                'mcp_or_adapter_ready' => true,
                'requires_source_links' => (bool) $connector['source_links_required'],
                'requires_receipt' => true,
                'external_side_effects' => false,
                'tool_contract_hash' => hash('sha256', $domainId.'|toolchain|'.(string) $connector['contract_hash']),
            ],
            $this->connectors($domainId, $blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseReferenceArchitecture(string $domainId, array $blueprint): array
    {
        return [
            'domain_id' => $domainId,
            'source_references' => [
                [
                    'id' => 'anthropic_claude_financial_services',
                    'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                    'adopted_for' => ['finance', 'research', 'strategy'],
                    'patterns' => ['unified_data_interface', 'source_linked_verification', 'model_audit_trail', 'compliance_automation'],
                ],
                [
                    'id' => 'microsoft_agent_framework_autogen_successor',
                    'url' => 'https://github.com/microsoft/autogen',
                    'adopted_for' => ['software', 'automation', 'operations'],
                    'patterns' => ['multi_agent_orchestration', 'mcp_interop', 'benchmarks', 'long_term_support_bias'],
                ],
                [
                    'id' => 'crewai_open_source_orchestration',
                    'url' => 'https://crewai.com/open-source',
                    'adopted_for' => ['marketing', 'research', 'automation'],
                    'patterns' => ['planning', 'reasoning', 'tools', 'memory', 'knowledge', 'collaboration'],
                ],
                [
                    'id' => 'openai_agents_sdk',
                    'url' => 'https://openai.github.io/openai-agents-python/agents/',
                    'adopted_for' => ['all'],
                    'patterns' => ['tools', 'guardrails', 'handoffs', 'sessions', 'run_hooks'],
                ],
                [
                    'id' => 'langgraph_durable_execution',
                    'url' => 'https://docs.langchain.com/oss/javascript/langgraph/durable-execution',
                    'adopted_for' => ['cyber', 'operations', 'automation', 'personal_development'],
                    'patterns' => ['durable_execution', 'checkpoint_resume', 'human_in_loop_interrupts'],
                ],
            ],
            'adopted_patterns' => array_values(array_unique(array_merge(
                ['durable_graph_state', 'human_in_loop_checkpoint', 'guardrailed_tool_use', 'source_linked_outputs', 'receipt_hashes'],
                (array) ($blueprint['reference_patterns'] ?? []),
            ))),
            'runtime_contract' => [
                'stateful_runs_required' => true,
                'tool_calls_must_be_receipted' => true,
                'handoffs_must_be_typed' => true,
                'guardrails_required' => true,
                'external_side_effects_default' => false,
            ],
            'architecture_hash' => hash('sha256', $domainId.'|enterprise_reference_architecture|'.implode('|', (array) ($blueprint['reference_patterns'] ?? []))),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseOperatingSystem(string $domainId, array $blueprint): array
    {
        $metrics = array_values((array) $blueprint['metrics']);
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $workProducts = array_values((array) $blueprint['work_products']);

        return [
            'domain_id' => $domainId,
            'schema' => 'atlas.ai.company.enterprise_operating_system.v1',
            'governance_board' => [
                'cadence' => 'weekly_operating_board',
                'standing_agenda' => [
                    'scorecard_review',
                    'risk_register_review',
                    'flow_throughput_review',
                    'quality_gate_exceptions',
                    'next_commitments',
                ],
                'decision_rights' => [
                    'internal_prioritization' => 'company_manager_agent',
                    'cross_domain_handoff' => 'portfolio_governor_advisory',
                    'external_action' => 'operator_approval_required',
                ],
            ],
            'okr_scorecard' => $this->okrScorecard($domainId, $metrics),
            'sla_catalog' => $this->slaCatalog($domainId, $flowIds),
            'risk_register' => $this->riskRegister($domainId),
            'backlog_system' => [
                'intake_queue' => $domainId.'_enterprise_intake_queue',
                'prioritization_method' => 'impact_confidence_effort_with_policy_risk',
                'lanes' => ['intake', 'triage', 'planned', 'in_progress', 'review', 'blocked', 'done'],
                'wip_limits' => [
                    'in_progress' => 3,
                    'review' => 5,
                ],
                'backlog_hash' => hash('sha256', $domainId.'|backlog_system|'.implode('|', $flowIds)),
            ],
            'runbooks' => $this->runbooks($domainId, $flowIds, $workProducts),
            'escalation_policy' => [
                'level_1' => 'company_manager_agent',
                'level_2' => 'independent_reviewer_agent',
                'level_3' => 'portfolio_governor',
                'level_4' => 'operator',
                'escalate_on' => ['policy_block', 'quality_gate_failure', 'external_action_request', 'repeated_flow_failure'],
            ],
            'audit' => [
                'decision_log_required' => true,
                'evidence_ledger_required' => true,
                'receipt_hash_required' => true,
                'retention_policy' => 'company_memory_scope_or_longer',
            ],
            'operating_system_hash' => hash('sha256', $domainId.'|enterprise_operating_system|'.implode('|', $metrics).'|'.implode('|', $flowIds)),
        ];
    }

    /**
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    private function okrScorecard(string $domainId, array $metrics): array
    {
        return array_values(array_map(
            static fn (string $metric, int $index): array => [
                'objective' => $domainId.'.objective.'.($index + 1),
                'key_result' => $metric,
                'target' => $index % 2 === 0 ? 'improve' : 'maintain_green',
                'measurement_source' => 'company_operating_packet.observed_metrics',
                'review_cadence' => 'weekly_operating_board',
                'score_hash' => hash('sha256', $domainId.'|okr|'.$metric),
            ],
            $metrics,
            array_keys($metrics),
        ));
    }

    /**
     * @param list<string> $flowIds
     * @return list<array<string,mixed>>
     */
    private function slaCatalog(string $domainId, array $flowIds): array
    {
        return array_values(array_map(
            static fn (string $flowId, int $index): array => [
                'flow_id' => $flowId,
                'response_sla' => $index % 3 === 0 ? 'same_business_day' : 'next_business_day',
                'quality_sla' => 'all_required_gates_green_or_blocked_with_reason',
                'escalation_after' => 'one_failed_review_cycle',
                'sla_hash' => hash('sha256', $domainId.'|sla|'.$flowId),
            ],
            $flowIds,
            array_keys($flowIds),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function riskRegister(string $domainId): array
    {
        return array_map(
            static fn (string $risk): array => [
                'risk_id' => $domainId.'.risk.'.$risk,
                'risk' => $risk,
                'mitigation' => match ($risk) {
                    'policy_violation' => 'fail_closed_policy_gate',
                    'source_quality_failure' => 'source_link_and_evidence_review',
                    'tool_failure' => 'adapter_health_probe_and_manual_fallback',
                    'external_side_effect_request' => 'operator_checkpoint_required',
                    default => 'portfolio_review',
                },
                'owner' => 'company_manager_agent',
                'status' => 'monitored',
                'risk_hash' => hash('sha256', $domainId.'|risk|'.$risk),
            ],
            ['policy_violation', 'source_quality_failure', 'tool_failure', 'external_side_effect_request'],
        );
    }

    /**
     * @param list<string> $flowIds
     * @param list<string> $workProducts
     * @return list<array<string,mixed>>
     */
    private function runbooks(string $domainId, array $flowIds, array $workProducts): array
    {
        return array_values(array_map(
            static fn (string $flowId, int $index): array => [
                'runbook_id' => $domainId.'.runbook.'.$flowId,
                'flow_id' => $flowId,
                'primary_output' => (string) ($workProducts[$index % max(1, count($workProducts))] ?? 'review_packet'),
                'entry_conditions' => ['scoped_request_present', 'policy_profile_loaded', 'evidence_contract_loaded'],
                'exit_conditions' => ['work_product_recorded', 'quality_gates_checked', 'receipt_hash_attached'],
                'fallback' => 'manual_operator_review',
                'runbook_hash' => hash('sha256', $domainId.'|runbook|'.$flowId),
            ],
            $flowIds,
            array_keys($flowIds),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,mixed>>
     */
    private function connectors(string $domainId, array $blueprint): array
    {
        return array_values(array_map(
            static fn (string $connector): array => [
                'id' => $connector,
                'domain_id' => $domainId,
                'kind' => str_contains($connector, 'approval') || str_contains($connector, 'gate')
                    ? 'governance_gate'
                    : 'read_or_internal_adapter',
                'side_effect_profile' => 'read_only_or_governed_internal',
                'external_side_effects' => false,
                'source_links_required' => true,
                'contract_hash' => hash('sha256', $domainId.'|'.$connector),
            ],
            $this->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function readiness(array $company): array
    {
        return [
            'ok' => count((array) $company['functions']) >= 4
                && count((array) $company['agent_roles']) >= 5
                && count((array) $company['flows']) >= 4
                && count((array) $company['flow_execution_contracts']) >= count((array) $company['flows'])
                && count((array) $company['flow_playbooks']) >= count((array) $company['flows'])
                && count((array) $company['flow_runtime_blueprints']) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.flow_runbooks', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.shared_connector_backplane', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.runbook_observability.required_metrics', [])) >= 5
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.executable_flow_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.agent_tool_routing_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.flow_artifact_io_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.supervision_and_shadow_runtime_gates', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.connector_runtime_adapters', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_observability.required_metrics', [])) >= 6
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.canonical_flow_fixtures', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.connector_stub_catalog', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.expected_trace_trajectories', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.quality_assertion_suites', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.failure_injection_cases', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.dry_run_command_plan', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_observability.required_metrics', [])) >= 6
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.command_adapter_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.handler_state_schemas', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_event_emission_plan', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.operator_checkpoint_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_action_runtime_stack.action_runtime_observability.required_metrics', [])) >= 6
                && count((array) $company['enterprise_agent_registry']) >= count((array) $company['agent_roles'])
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.framework_source_catalog', [])) >= 9
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.agent_toolkit_profiles', [])) >= count((array) $company['agent_roles'])
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.flow_toolkit_assignments', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.repository_and_agent_watchlist.global_agent_frameworks', [])) >= 8
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.toolkit_certification_matrix', [])) >= count((array) $company['agent_roles'])
                && count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.toolkit_observability.required_metrics', [])) >= 6
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.source_basis', [])) >= 12
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.official_framework_repositories', [])) >= 8
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.domain_repository_candidates', [])) >= 3
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.per_flow_adoption_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.source_to_company_capability_map', [])) >= 12
                && count((array) data_get($company, 'enterprise_external_research_adoption_stack.connector_and_data_provider_backlog', [])) >= count((array) $company['connectors'])
                && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.calendar_wait_blocker_enabled', true) === false
                && count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.repository_intake_queue', [])) >= 11
                && count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.framework_adoption_scorecards', [])) >= 8
                && count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.flow_repository_implementation_epics', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.version_pin_and_supply_chain_plan', [])) >= 11
                && count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_policy.runtime_use_before_local_contract_tests_allowed', true) === false
                && (bool) data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_policy.external_side_effects_enabled', true) === false
                && count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])) >= 4
                && count((array) data_get($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])) >= 4
                && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.flow_dependency_routing', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_metrics', [])) >= 4
                && count((array) data_get($company, 'domain_data_model.entities', [])) >= 4
                && count((array) $company['business_process_map']) >= count((array) $company['flows'])
                && count((array) $company['deliverable_quality_contracts']) >= 5
                && count((array) data_get($company, 'go_to_production_pack.slo_sli_catalog', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5
                && count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) $company['metrics'])
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])) >= 5
                && count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])) >= 5
                && count((array) data_get($company, 'enterprise_productized_service_stack.flow_service_offers', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_productized_service_stack.service_delivery_blueprints', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_productized_service_stack.sla_success_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])) >= 5
                && count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_productized_service_stack.proof_and_case_study_templates', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])) >= 10
                && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true) === false
                && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true) === false
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.objects', [])) >= 8
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.segment_sales_plays', [])) >= 4
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.mutual_action_plans', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_deal_room_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_pipeline_forecast_reviews', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_mutual_action_plan_risk_reviews', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])) >= count((array) $company['metrics'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_to_delivery_handoff_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])) >= 13
                && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true) === false
                && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.public_claim_or_paid_campaign_allowed', true) === false
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.objects', [])) >= 9
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_segment_playbooks', [])) >= 4
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.knowledge_base_article_templates', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.escalation_and_incident_runbooks', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.resolution_quality_and_rca_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_customer_health_escalation_playbooks', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_knowledge_quality_reviews', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_automation_deflection_tests', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])) >= count((array) $company['metrics'])
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])) >= 13
                && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true) === false
                && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.regulated_support_advice_allowed_without_review', true) === false
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.operating_roles', [])) >= 6
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.audience_segment_map', [])) >= 4
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.content_asset_factories', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.experiment_backlog', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_attribution_experiment_models', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_channel_budget_guardrails', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_public_claim_evidence_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.channel_and_distribution_plan', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.brand_compliance_review_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_to_crm_handoff_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])) >= 14
                && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.external_publish_paid_campaign_or_outreach_allowed', true) === false
                && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.public_claim_allowed_without_source_and_operator_review', true) === false
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])) >= 7
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.direct_source_link_required', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.claim_without_source_link_allowed', true) === false
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', []))
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.operating_roles', [])) >= 9
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_forecast_models', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_model_risk_controls', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_investment_committee_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])) >= 5
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.capital_actions_blocked', [])) >= 6
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.close_packet_sections', [])) >= 9
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])) >= 14
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.source_linked_financial_claim_required', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.model_risk_review_required_for_investment_or_capital_recommendation', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true) === false
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true) === false
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.resilience_observability.required_metrics', [])) >= 5
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= count((array) $company['metrics'])
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.scenario_and_forecast_model', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.work_product_analytics_map', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.postmortem_and_retrospective_program', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.playbook_change_control', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.work_product_feedback_memory', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sensitive_data_handling_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.purpose_consent_registry', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= count((array) $company['cadences'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.incident_and_exception_desk', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.change_window_and_release_calendar', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.dashboard_operations_map', [])) >= 3
                && count((array) data_get($company, 'enterprise_company_command_center_stack.operating_cells', [])) >= 6
                && count((array) data_get($company, 'enterprise_company_command_center_stack.flow_command_cards', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_company_command_center_stack.operator_console_views', [])) >= 4
                && count((array) data_get($company, 'enterprise_company_command_center_stack.work_product_factory_map', [])) >= 5
                && count((array) data_get($company, 'enterprise_company_command_center_stack.command_center_kpis', [])) >= count((array) $company['metrics'])
                && (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= (
                    count((array) $company['functions'])
                    + count((array) $company['agent_roles'])
                    + count((array) $company['flows'])
                    + count((array) $company['connectors'])
                    + count((array) $company['metrics'])
                )
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])) >= 5
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'portfolio_finance_stack.flow_cost_centers', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])) >= 5
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])) >= 4
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'strategic_intelligence_stack.rival_and_alternative_map', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'strategic_intelligence_stack.intelligence_kpis', [])) >= 4
                && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])) >= count((array) $company['flows'])
                && count((array) $company['enterprise_capability_matrix']) >= count((array) $company['flows'])
                && count((array) $company['external_integration_catalog']) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.adapter_contract_catalog', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.auth_and_secret_boundary', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.sandbox_probe_matrix', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.consumer_provider_contract_tests', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.connector_data_mapping_and_lineage', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.flow_connector_usage_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.replay_fixture_and_mock_server_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.connector_slo_and_failure_mode_catalog', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])) >= 5
                && count((array) data_get($company, 'enterprise_production_connector_preflight_stack.connector_preflight_contracts', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_production_connector_preflight_stack.flow_connector_cutover_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_production_connector_preflight_stack.production_readiness_evidence_register', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])) >= 6
                && (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.production_cutover_without_operator_signed_scope_allowed', true) === false
                && count((array) data_get($company, 'evaluation_harness.suite_per_flow', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])) >= 5
                && count((array) data_get($company, 'enterprise_tooling_research_stack.source_catalog', [])) >= 9
                && count((array) data_get($company, 'enterprise_tooling_research_stack.per_flow_tooling_benchmark', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_tooling_research_stack.connector_integration_backlog', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.domain_value_chain', [])) >= 6
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.domain_data_product_spine', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.enterprise_system_map', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.flow_depth_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.mcp_api_connector_backlog', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.delivery_offer_model', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.depth_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_domain_operating_depth_stack.depth_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_operating_depth_stack.depth_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                && count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.managed_agent_catalog', [])) >= 4
                && count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_observability.required_metrics', [])) >= 10
                && (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                && (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_control_plane.external_effect_worker_enabled', true) === false
                && count((array) data_get($company, 'enterprise_domain_solution_stack.domain_source_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_solution_stack.solution_modules', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_solution_stack.managed_agent_templates', [])) >= 4
                && count((array) data_get($company, 'enterprise_domain_solution_stack.data_product_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', [])) >= count((array) $company['flows'])
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.direct_source_hyperlinks_required', false)
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false)
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.external_data_mutation_allowed', true) === false
                && count((array) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.review_modes', [])) >= 5
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.solution_suites', [])) >= 6
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.artifact_factory_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_evaluation_recipes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_policy.external_execution_allowed_by_suite', true) === false
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_mode_catalog', [])) >= 4
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.domain_service_lanes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.business_artifact_delivery_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_policy.autonomous_external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_evaluation_cases', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])) >= 5
                && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.provider_write_or_paid_action_default', true) === false
                && count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_provider_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.implementation_partner_tracks', [])) >= 7
                && count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.flow_solution_workload_packs', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_observability.required_metrics', [])) >= 8
                && (bool) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_policy.external_side_effects_enabled', true) === false
                && (bool) data_get($company, 'enterprise_industry_solution_ecosystem_stack.enterprise_adoption_program.external_contracting_allowed_by_stack', true) === false
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])) >= 7
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.operating_roles', [])) >= 6
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_execution_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_decision_room_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_observability.required_metrics', [])) >= (count((array) $company['metrics']) + 6)
                && (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.external_side_effects_enabled', true) === false
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= count((array) $company['work_products'])
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])) >= (count((array) $company['metrics']) + 6)
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true) === false
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])) >= 7
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])) >= count((array) $company['work_products'])
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.required_controls', [])) >= 7
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_observability.required_metrics', [])) >= (count((array) $company['metrics']) + 7)
                && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true) === false
                && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true) === false
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= (count((array) $company['metrics']) + 7)
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.reference_source_basis', [])) >= 5
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.enterprise_agentic_architecture_basis.agent_runtime_patterns', [])) >= 6
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.enterprise_agentic_architecture_basis.required_runtime_properties', [])) >= 9
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.managed_agent_templates', [])) >= 10
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.template_runtime_contracts', [])) >= count((array) data_get($company, 'premium_enterprise_agent_reference_model.managed_agent_templates', []))
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.flow_template_map', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.flow_managed_agent_workflows', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.data_and_tool_workbenches', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.connector_mcp_server_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.domain_source_alignment', [])) >= 5
                && count((array) data_get($company, 'premium_enterprise_agent_reference_model.replay_and_audit_harness.benchmarks_per_flow', [])) >= count((array) $company['flows'])
                && (int) data_get($company, 'premium_enterprise_agent_reference_model.accelerated_activation_contract.buildout_wait_days_required', 30) === 0
                && count((array) data_get($company, 'enterprise_flow_operating_packages.source_basis', [])) >= 10
                && count((array) data_get($company, 'enterprise_flow_operating_packages.flow_packages', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_flow_operating_packages.package_observability.required_metrics', [])) >= 6
                && (bool) data_get($company, 'enterprise_flow_operating_packages.operating_policy.calendar_wait_blocker_enabled', true) === false
                && count((array) data_get($company, 'enterprise_integration_activation_plan.source_activation_tracks', [])) >= 5
                && count((array) data_get($company, 'enterprise_integration_activation_plan.connector_activation_tracks', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_integration_activation_plan.flow_activation_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.live_read_probe_plan', [])) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.operator_acceptance_packets', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rollback_drill_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.promotion_evidence_matrix', [])) >= count((array) $company['flows'])
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7
                && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.external_mutation_allowed_during_rehearsal', true) === false
                && count((array) $company['autonomy_promotion_ladder']) >= 4
                && count((array) $company['connectors']) >= 3
                && count((array) $company['toolchain']) >= count((array) $company['connectors'])
                && count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])) >= 4
                && count((array) data_get($company, 'enterprise_operating_system.risk_register', [])) >= 4
                && count((array) data_get($company, 'enterprise_operating_system.runbooks', [])) >= count((array) $company['flows'])
                && count((array) $company['work_products']) >= 5
                && count((array) $company['metrics']) >= 4
                && count((array) $company['cadences']) >= 3,
            'function_count' => count((array) $company['functions']),
            'agent_count' => count((array) $company['agent_roles']),
            'flow_count' => count((array) $company['flows']),
            'flow_execution_contract_count' => count((array) $company['flow_execution_contracts']),
            'flow_playbook_count' => count((array) $company['flow_playbooks']),
            'flow_runtime_blueprint_count' => count((array) $company['flow_runtime_blueprints']),
            'enterprise_flow_runbook_count' => count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.flow_runbooks', [])),
            'enterprise_flow_connector_backplane_count' => count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.shared_connector_backplane', [])),
            'enterprise_flow_runbook_metric_count' => count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.runbook_observability.required_metrics', [])),
            'flow_runtime_implementation_source_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.source_catalog', [])),
            'executable_flow_packet_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.executable_flow_packets', [])),
            'agent_tool_routing_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.agent_tool_routing_matrix', [])),
            'flow_artifact_io_contract_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.flow_artifact_io_contracts', [])),
            'supervision_shadow_gate_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.supervision_and_shadow_runtime_gates', [])),
            'connector_runtime_adapter_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.connector_runtime_adapters', [])),
            'runtime_implementation_metric_count' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_observability.required_metrics', [])),
            'flow_fixture_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.canonical_flow_fixtures', [])),
            'connector_stub_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.connector_stub_catalog', [])),
            'expected_trace_trajectory_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.expected_trace_trajectories', [])),
            'quality_assertion_suite_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.quality_assertion_suites', [])),
            'failure_injection_case_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.failure_injection_cases', [])),
            'dry_run_command_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.dry_run_command_plan', [])),
            'flow_fixture_simulation_metric_count' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_observability.required_metrics', [])),
            'runtime_action_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', [])),
            'command_adapter_matrix_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.command_adapter_matrix', [])),
            'handler_state_schema_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.handler_state_schemas', [])),
            'runtime_event_emission_plan_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_event_emission_plan', [])),
            'operator_checkpoint_contract_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.operator_checkpoint_contracts', [])),
            'action_runtime_metric_count' => count((array) data_get($company, 'enterprise_flow_action_runtime_stack.action_runtime_observability.required_metrics', [])),
            'enterprise_agent_registry_count' => count((array) $company['enterprise_agent_registry']),
            'agent_toolkit_framework_source_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.framework_source_catalog', [])),
            'agent_toolkit_profile_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.agent_toolkit_profiles', [])),
            'flow_toolkit_assignment_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.flow_toolkit_assignments', [])),
            'agent_repository_watch_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.repository_and_agent_watchlist.global_agent_frameworks', [])),
            'agent_toolkit_certification_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.toolkit_certification_matrix', [])),
            'agent_toolkit_metric_count' => count((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.toolkit_observability.required_metrics', [])),
            'external_research_source_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.source_basis', [])),
            'external_research_framework_repo_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.official_framework_repositories', [])),
            'external_research_domain_repo_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.domain_repository_candidates', [])),
            'external_research_flow_adoption_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.per_flow_adoption_matrix', [])),
            'external_research_capability_map_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.source_to_company_capability_map', [])),
            'external_research_connector_backlog_count' => count((array) data_get($company, 'enterprise_external_research_adoption_stack.connector_and_data_provider_backlog', [])),
            'external_research_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.calendar_wait_blocker_enabled', true),
            'agent_repository_intake_count' => count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.repository_intake_queue', [])),
            'agent_repository_framework_scorecard_count' => count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.framework_adoption_scorecards', [])),
            'agent_repository_flow_epic_count' => count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.flow_repository_implementation_epics', [])),
            'agent_repository_version_pin_count' => count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.version_pin_and_supply_chain_plan', [])),
            'agent_repository_metric_count' => count((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_observability.required_metrics', [])),
            'agent_repository_external_side_effects_enabled' => (bool) data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_policy.external_side_effects_enabled', true),
            'workforce_agent_capacity_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])),
            'workforce_flow_staffing_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', [])),
            'workforce_training_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])),
            'portfolio_dependency_handoff_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])),
            'portfolio_integration_dependency_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])),
            'portfolio_flow_dependency_routing_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.flow_dependency_routing', [])),
            'portfolio_reporting_metric_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_metrics', [])),
            'domain_data_entity_count' => count((array) data_get($company, 'domain_data_model.entities', [])),
            'business_process_count' => count((array) $company['business_process_map']),
            'deliverable_quality_contract_count' => count((array) $company['deliverable_quality_contracts']),
            'production_slo_count' => count((array) data_get($company, 'go_to_production_pack.slo_sli_catalog', [])),
            'production_integration_enablement_count' => count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])),
            'service_catalog_count' => count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])),
            'business_kpi_count' => count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])),
            'customer_offer_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
            'customer_journey_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])),
            'customer_success_metric_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])),
            'account_playbook_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])),
            'account_entitlement_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
            'account_onboarding_success_plan_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])),
            'account_service_review_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])),
            'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
            'account_contract_metric_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])),
            'productized_service_product_line_count' => count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])),
            'productized_service_offer_count' => count((array) data_get($company, 'enterprise_productized_service_stack.flow_service_offers', [])),
            'productized_service_delivery_blueprint_count' => count((array) data_get($company, 'enterprise_productized_service_stack.service_delivery_blueprints', [])),
            'productized_service_intake_contract_count' => count((array) data_get($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', [])),
            'productized_service_sla_contract_count' => count((array) data_get($company, 'enterprise_productized_service_stack.sla_success_contracts', [])),
            'productized_service_pricing_package_count' => count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])),
            'productized_service_gtm_motion_count' => count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])),
            'productized_service_proof_template_count' => count((array) data_get($company, 'enterprise_productized_service_stack.proof_and_case_study_templates', [])),
            'productized_service_metric_count' => count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])),
            'productized_service_external_commitment_enabled' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true),
            'productized_service_external_billing_enabled' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true),
            'sales_crm_source_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])),
            'sales_crm_object_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.objects', [])),
            'sales_crm_segment_play_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.segment_sales_plays', [])),
            'sales_crm_opportunity_route_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', [])),
            'sales_crm_proposal_packet_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', [])),
            'sales_crm_mutual_action_plan_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.mutual_action_plans', [])),
            'sales_crm_account_research_workbench_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', [])),
            'sales_crm_deal_room_packet_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_deal_room_packets', [])),
            'sales_crm_pipeline_forecast_review_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_pipeline_forecast_reviews', [])),
            'sales_crm_map_risk_review_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_mutual_action_plan_risk_reviews', [])),
            'sales_crm_renewal_expansion_signal_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])),
            'sales_crm_handoff_contract_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_to_delivery_handoff_contracts', [])),
            'sales_crm_metric_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])),
            'sales_crm_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.calendar_wait_blocker_enabled', true),
            'sales_crm_external_commitment_enabled' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true),
            'sales_crm_public_claim_paid_campaign_enabled' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.public_claim_or_paid_campaign_allowed', true),
            'support_service_desk_source_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])),
            'support_service_desk_object_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.objects', [])),
            'support_segment_playbook_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_segment_playbooks', [])),
            'support_flow_lane_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])),
            'support_ticket_sla_contract_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', [])),
            'support_kb_template_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.knowledge_base_article_templates', [])),
            'support_escalation_runbook_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.escalation_and_incident_runbooks', [])),
            'support_resolution_rca_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.resolution_quality_and_rca_contracts', [])),
            'support_case_resolution_workbench_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', [])),
            'support_customer_health_escalation_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_customer_health_escalation_playbooks', [])),
            'support_knowledge_quality_review_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_knowledge_quality_reviews', [])),
            'support_automation_deflection_test_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_automation_deflection_tests', [])),
            'support_feedback_learning_loop_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])),
            'support_service_desk_metric_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])),
            'support_service_desk_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.calendar_wait_blocker_enabled', true),
            'support_service_desk_external_message_enabled' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true),
            'support_service_desk_unreviewed_regulated_advice_enabled' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.regulated_support_advice_allowed_without_review', true),
            'marketing_growth_source_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])),
            'marketing_growth_role_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.operating_roles', [])),
            'marketing_audience_segment_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.audience_segment_map', [])),
            'marketing_campaign_blueprint_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', [])),
            'marketing_content_factory_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.content_asset_factories', [])),
            'marketing_experiment_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.experiment_backlog', [])),
            'marketing_growth_intelligence_workbench_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', [])),
            'marketing_attribution_experiment_model_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_attribution_experiment_models', [])),
            'marketing_channel_budget_guardrail_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_channel_budget_guardrails', [])),
            'marketing_public_claim_evidence_packet_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_public_claim_evidence_packets', [])),
            'marketing_channel_distribution_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.channel_and_distribution_plan', [])),
            'marketing_brand_review_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.brand_compliance_review_packets', [])),
            'marketing_crm_handoff_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_to_crm_handoff_contracts', [])),
            'marketing_growth_metric_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])),
            'marketing_growth_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.calendar_wait_blocker_enabled', true),
            'marketing_growth_external_publish_enabled' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.external_publish_paid_campaign_or_outreach_allowed', true),
            'marketing_growth_unreviewed_public_claim_enabled' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.public_claim_allowed_without_source_and_operator_review', true),
            'finance_treasury_source_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
            'finance_treasury_data_interface_connector_class_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])),
            'finance_treasury_provider_connector_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])),
            'finance_treasury_cfo_role_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.operating_roles', [])),
            'finance_treasury_research_workbench_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', [])),
            'finance_treasury_budget_envelope_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', [])),
            'finance_treasury_forecast_model_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_forecast_models', [])),
            'finance_treasury_model_risk_control_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_model_risk_controls', [])),
            'finance_treasury_investment_committee_packet_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_investment_committee_packets', [])),
            'finance_treasury_pnl_line_item_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])),
            'finance_treasury_billing_ledger_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', [])),
            'finance_treasury_blocked_capital_action_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.capital_actions_blocked', [])),
            'finance_treasury_close_section_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.close_packet_sections', [])),
            'finance_treasury_metric_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])),
            'finance_treasury_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.calendar_wait_blocker_enabled', true),
            'finance_treasury_external_financial_action_enabled' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true),
            'finance_treasury_real_money_movement_enabled' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true),
            'vendor_due_diligence_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
            'source_terms_review_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])),
            'flow_procurement_routing_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', [])),
            'vendor_operability_scorecard_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])),
            'flow_failure_mode_analysis_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', [])),
            'connector_resilience_plan_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])),
            'incident_exercise_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])),
            'resilience_metric_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.resilience_observability.required_metrics', [])),
            'analytics_metric_lineage_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])),
            'executive_dashboard_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])),
            'flow_decision_register_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])),
            'scenario_forecast_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.scenario_and_forecast_model', [])),
            'work_product_analytics_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.work_product_analytics_map', [])),
            'knowledge_source_registry_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])),
            'flow_learning_loop_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])),
            'postmortem_program_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.postmortem_and_retrospective_program', [])),
            'playbook_change_control_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.playbook_change_control', [])),
            'work_product_feedback_memory_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.work_product_feedback_memory', [])),
            'connector_knowledge_sync_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])),
            'agent_access_matrix_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])),
            'flow_data_boundary_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])),
            'connector_secret_binding_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])),
            'sensitive_data_handling_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sensitive_data_handling_catalog', [])),
            'purpose_consent_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.purpose_consent_registry', [])),
            'control_tower_lane_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])),
            'control_tower_cadence_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])),
            'exception_desk_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.incident_and_exception_desk', [])),
            'change_window_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.change_window_and_release_calendar', [])),
            'connector_probe_plan_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])),
            'dashboard_operations_map_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.dashboard_operations_map', [])),
            'command_center_operating_cell_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.operating_cells', [])),
            'command_center_flow_card_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.flow_command_cards', [])),
            'command_center_connector_panel_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])),
            'command_center_console_view_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.operator_console_views', [])),
            'command_center_work_product_factory_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.work_product_factory_map', [])),
            'command_center_kpi_count' => count((array) data_get($company, 'enterprise_company_command_center_stack.command_center_kpis', [])),
            'command_center_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.calendar_wait_blocker_enabled', true),
            'command_center_external_execution_enabled' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true),
            'semantic_graph_node_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])),
            'semantic_graph_edge_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', [])),
            'semantic_graph_view_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])),
            'semantic_graph_drift_rule_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])),
            'delivery_contract_count' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])),
            'delivery_sla_count' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])),
            'finance_cost_center_count' => count((array) data_get($company, 'portfolio_finance_stack.flow_cost_centers', [])),
            'flow_unit_economics_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', [])),
            'capacity_simulation_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', [])),
            'work_product_pricing_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])),
            'agent_capacity_cost_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])),
            'connector_cost_limit_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])),
            'intelligence_rival_map_count' => count((array) data_get($company, 'strategic_intelligence_stack.rival_and_alternative_map', [])),
            'intelligence_kpi_count' => count((array) data_get($company, 'strategic_intelligence_stack.intelligence_kpis', [])),
            'grc_vendor_risk_count' => count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])),
            'grc_audit_evidence_count' => count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])),
            'capability_matrix_count' => count((array) $company['enterprise_capability_matrix']),
            'external_integration_contract_count' => count((array) $company['external_integration_catalog']),
            'connector_adapter_contract_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.adapter_contract_catalog', [])),
            'connector_auth_boundary_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.auth_and_secret_boundary', [])),
            'connector_sandbox_probe_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.sandbox_probe_matrix', [])),
            'connector_contract_test_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.consumer_provider_contract_tests', [])),
            'connector_data_mapping_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_data_mapping_and_lineage', [])),
            'flow_connector_usage_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.flow_connector_usage_matrix', [])),
            'connector_replay_fixture_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.replay_fixture_and_mock_server_plan', [])),
            'connector_slo_failure_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_slo_and_failure_mode_catalog', [])),
            'connector_certification_metric_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])),
            'production_connector_preflight_contract_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.connector_preflight_contracts', [])),
            'production_connector_cutover_flow_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.flow_connector_cutover_matrix', [])),
            'production_connector_evidence_register_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.production_readiness_evidence_register', [])),
            'production_connector_cutover_metric_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])),
            'production_connector_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.calendar_wait_blocker_enabled', true),
            'evaluation_suite_count' => count((array) data_get($company, 'evaluation_harness.suite_per_flow', [])),
            'benchmark_offline_dataset_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', [])),
            'benchmark_trace_rubric_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', [])),
            'benchmark_adversarial_case_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', [])),
            'benchmark_state_assertion_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', [])),
            'benchmark_replay_matrix_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', [])),
            'benchmark_observability_metric_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])),
            'tooling_source_count' => count((array) data_get($company, 'enterprise_tooling_research_stack.source_catalog', [])),
            'tooling_benchmark_count' => count((array) data_get($company, 'enterprise_tooling_research_stack.per_flow_tooling_benchmark', [])),
            'tooling_integration_backlog_count' => count((array) data_get($company, 'enterprise_tooling_research_stack.connector_integration_backlog', [])),
            'domain_operating_value_chain_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.domain_value_chain', [])),
            'domain_operating_data_product_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.domain_data_product_spine', [])),
            'domain_operating_system_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.enterprise_system_map', [])),
            'domain_operating_flow_depth_packet_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.flow_depth_packets', [])),
            'domain_operating_connector_backlog_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.mcp_api_connector_backlog', [])),
            'domain_operating_delivery_offer_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.delivery_offer_model', [])),
            'domain_operating_depth_metric_count' => count((array) data_get($company, 'enterprise_domain_operating_depth_stack.depth_observability.required_metrics', [])),
            'domain_operating_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_operating_depth_stack.depth_policy.calendar_wait_blocker_enabled', true),
            'domain_operating_external_execution_enabled' => (bool) data_get($company, 'enterprise_domain_operating_depth_stack.depth_policy.external_write_spend_trade_publish_deploy_delete_allowed', true),
            'domain_agent_workforce_managed_agent_count' => count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.managed_agent_catalog', [])),
            'domain_agent_workforce_crew_count' => count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', [])),
            'domain_agent_workforce_metric_count' => count((array) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_observability.required_metrics', [])),
            'domain_agent_workforce_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_policy.calendar_wait_blocker_enabled', true),
            'domain_agent_workforce_external_execution_enabled' => (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_policy.external_write_spend_trade_publish_deploy_delete_allowed', true),
            'domain_agent_workforce_external_worker_enabled' => (bool) data_get($company, 'enterprise_domain_agent_workforce_stack.workforce_control_plane.external_effect_worker_enabled', true),
            'domain_solution_source_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_source_catalog', [])),
            'domain_solution_module_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.solution_modules', [])),
            'domain_solution_agent_template_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.managed_agent_templates', [])),
            'domain_solution_data_product_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.data_product_catalog', [])),
            'domain_solution_playbook_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', [])),
            'domain_solution_data_plane_source_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.source_refs', [])),
            'domain_solution_review_mode_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.review_modes', [])),
            'vertical_solution_suite_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.solution_suites', [])),
            'vertical_solution_flow_kit_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', [])),
            'vertical_solution_connector_workbench_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', [])),
            'vertical_solution_artifact_factory_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.artifact_factory_catalog', [])),
            'vertical_solution_evaluation_recipe_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_evaluation_recipes', [])),
            'vertical_solution_metric_count' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_observability.required_metrics', [])),
            'vertical_solution_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true),
            'vertical_solution_external_execution_enabled' => (bool) data_get($company, 'enterprise_vertical_solution_suite_stack.suite_policy.external_execution_allowed_by_suite', true),
            'business_execution_mode_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_mode_catalog', [])),
            'business_execution_cell_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', [])),
            'business_execution_kpi_binding_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', [])),
            'business_execution_service_lane_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.domain_service_lanes', [])),
            'business_execution_artifact_contract_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.business_artifact_delivery_contracts', [])),
            'business_execution_metric_count' => count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_observability.required_metrics', [])),
            'business_execution_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_policy.calendar_wait_blocker_enabled', true),
            'business_execution_external_execution_enabled' => (bool) data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_policy.autonomous_external_write_spend_trade_publish_deploy_delete_allowed', true),
            'domain_provider_contract_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])),
            'domain_provider_connector_workbench_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])),
            'domain_provider_flow_route_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', [])),
            'domain_provider_eval_case_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_evaluation_cases', [])),
            'domain_provider_lineage_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])),
            'domain_provider_metric_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])),
            'domain_provider_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.calendar_wait_blocker_enabled', true),
            'industry_solution_provider_count' => count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_provider_catalog', [])),
            'industry_solution_partner_track_count' => count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.implementation_partner_tracks', [])),
            'industry_solution_workload_pack_count' => count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.flow_solution_workload_packs', [])),
            'industry_solution_metric_count' => count((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_observability.required_metrics', [])),
            'industry_solution_external_side_effects_enabled' => (bool) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_policy.external_side_effects_enabled', true),
            'domain_execution_suite_source_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])),
            'domain_execution_suite_role_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.operating_roles', [])),
            'domain_execution_suite_connector_workbench_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])),
            'domain_execution_suite_flow_packet_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_execution_packets', [])),
            'domain_execution_suite_risk_control_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', [])),
            'domain_execution_suite_decision_room_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_decision_room_packets', [])),
            'domain_execution_suite_replay_eval_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', [])),
            'domain_execution_suite_metric_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_observability.required_metrics', [])),
            'domain_execution_suite_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true),
            'domain_execution_suite_external_side_effects_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.external_side_effects_enabled', true),
            'flow_work_product_catalog_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])),
            'flow_work_product_delivery_blueprint_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])),
            'flow_work_product_acceptance_contract_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])),
            'flow_work_product_handoff_packet_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])),
            'flow_work_product_replay_check_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])),
            'flow_work_product_metric_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])),
            'flow_work_product_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true),
            'flow_work_product_external_delivery_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true),
            'domain_data_room_source_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])),
            'domain_data_product_contract_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])),
            'domain_connector_permission_profile_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])),
            'domain_flow_data_connector_contract_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', [])),
            'domain_connector_fixture_eval_suite_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', [])),
            'domain_data_room_required_control_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.required_controls', [])),
            'domain_data_connector_metric_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_observability.required_metrics', [])),
            'domain_data_connector_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.calendar_wait_blocker_enabled', true),
            'domain_data_connector_write_tools_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true),
            'domain_data_connector_external_mutation_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true),
            'flow_live_read_connector_profile_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])),
            'flow_live_read_probe_contract_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])),
            'flow_live_read_probe_evidence_matrix_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])),
            'flow_live_read_probe_metric_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])),
            'flow_live_read_probe_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true),
            'flow_live_read_probe_write_tools_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true),
            'flow_live_read_probe_external_mutation_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true),
            'premium_reference_source_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.reference_source_basis', [])),
            'premium_agentic_runtime_pattern_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.enterprise_agentic_architecture_basis.agent_runtime_patterns', [])),
            'premium_required_runtime_property_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.enterprise_agentic_architecture_basis.required_runtime_properties', [])),
            'premium_managed_agent_template_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.managed_agent_templates', [])),
            'premium_template_runtime_contract_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.template_runtime_contracts', [])),
            'premium_flow_template_map_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.flow_template_map', [])),
            'premium_flow_managed_agent_workflow_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.flow_managed_agent_workflows', [])),
            'premium_workbench_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.data_and_tool_workbenches', [])),
            'premium_connector_mcp_server_plan_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.connector_mcp_server_plan', [])),
            'premium_domain_source_alignment_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.domain_source_alignment', [])),
            'premium_replay_benchmark_count' => count((array) data_get($company, 'premium_enterprise_agent_reference_model.replay_and_audit_harness.benchmarks_per_flow', [])),
            'premium_buildout_wait_days_required' => (int) data_get($company, 'premium_enterprise_agent_reference_model.accelerated_activation_contract.buildout_wait_days_required', 30),
            'enterprise_flow_operating_package_source_count' => count((array) data_get($company, 'enterprise_flow_operating_packages.source_basis', [])),
            'enterprise_flow_operating_package_count' => count((array) data_get($company, 'enterprise_flow_operating_packages.flow_packages', [])),
            'enterprise_flow_operating_package_metric_count' => count((array) data_get($company, 'enterprise_flow_operating_packages.package_observability.required_metrics', [])),
            'enterprise_flow_operating_package_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_operating_packages.operating_policy.calendar_wait_blocker_enabled', true),
            'activation_source_track_count' => count((array) data_get($company, 'enterprise_integration_activation_plan.source_activation_tracks', [])),
            'activation_connector_track_count' => count((array) data_get($company, 'enterprise_integration_activation_plan.connector_activation_tracks', [])),
            'activation_flow_matrix_count' => count((array) data_get($company, 'enterprise_integration_activation_plan.flow_activation_matrix', [])),
            'operational_dress_rehearsal_flow_runbook_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', [])),
            'operational_dress_rehearsal_live_probe_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.live_read_probe_plan', [])),
            'operational_dress_rehearsal_acceptance_packet_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.operator_acceptance_packets', [])),
            'operational_dress_rehearsal_rollback_drill_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rollback_drill_matrix', [])),
            'operational_dress_rehearsal_promotion_evidence_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.promotion_evidence_matrix', [])),
            'operational_dress_rehearsal_metric_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])),
            'operational_dress_rehearsal_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.calendar_wait_blocker_enabled', true),
            'autonomy_promotion_stage_count' => count((array) $company['autonomy_promotion_ladder']),
            'connector_count' => count((array) $company['connectors']),
            'toolchain_count' => count((array) $company['toolchain']),
            'okr_count' => count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])),
            'risk_count' => count((array) data_get($company, 'enterprise_operating_system.risk_register', [])),
            'runbook_count' => count((array) data_get($company, 'enterprise_operating_system.runbooks', [])),
            'work_product_count' => count((array) $company['work_products']),
            'metric_count' => count((array) $company['metrics']),
            'cadence_count' => count((array) $company['cadences']),
        ];
    }
}
