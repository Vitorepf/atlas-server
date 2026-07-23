<?php

namespace App\Services\Ai\Holding;

use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService;
use App\Services\Ai\Holding\EnterpriseBuildout\EnterpriseBuildoutSupport;
use App\Services\Ai\Holding\EnterpriseBuildout\PortfolioReportSection;
use App\Services\Ai\Holding\EnterpriseBuildout\FlowRuntimeSection;
use App\Services\Ai\Holding\EnterpriseBuildout\AgentWorkforceSection;
use App\Services\Ai\Holding\EnterpriseBuildout\CommercialCustomerSection;
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

    public function __construct(
        private readonly FinanceEnterpriseAnalysisService $finance,
    ) {
        $this->support = new EnterpriseBuildoutSupport($this->finance);
        $this->portfolioReport = new PortfolioReportSection();
        $this->flowRuntime = new FlowRuntimeSection($this->support);
        $this->agentWorkforce = new AgentWorkforceSection($this->support);
        $this->commercialCustomer = new CommercialCustomerSection($this->support);
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
            'enterprise_company_operating_blueprint_stack' => $this->enterpriseCompanyOperatingBlueprintStack($domainId, $blueprint),
            'enterprise_external_research_adoption_stack' => $this->agentWorkforce->enterpriseExternalResearchAdoptionStack($domainId, $blueprint),
            'enterprise_agent_repository_adoption_pipeline' => $this->agentWorkforce->enterpriseAgentRepositoryAdoptionPipeline($domainId, $blueprint),
            'enterprise_agent_repository_operating_catalog' => $this->agentWorkforce->enterpriseAgentRepositoryOperatingCatalog($domainId, $blueprint),
            'enterprise_workforce_capacity_stack' => $this->agentWorkforce->enterpriseWorkforceCapacityStack($domainId, $blueprint),
            'enterprise_portfolio_dependency_stack' => $this->enterprisePortfolioDependencyStack($domainId, $manifest, $blueprint),
            'domain_data_model' => $this->domainDataModel($domainId, $blueprint),
            'business_process_map' => $this->businessProcessMap($domainId, $blueprint),
            'deliverable_quality_contracts' => $this->deliverableQualityContracts($domainId, $blueprint),
            'go_to_production_pack' => $this->goToProductionPack($domainId, $blueprint),
            'commercial_operating_stack' => $this->commercialCustomer->commercialOperatingStack($domainId, $blueprint),
            'enterprise_customer_market_operations_stack' => $this->commercialCustomer->enterpriseCustomerMarketOperationsStack($domainId, $blueprint),
            'enterprise_account_contract_delivery_stack' => $this->commercialCustomer->enterpriseAccountContractDeliveryStack($domainId, $blueprint, $companyMetrics),
            'enterprise_productized_service_stack' => $this->commercialCustomer->enterpriseProductizedServiceStack($domainId, $blueprint, $companyMetrics),
            'enterprise_sales_crm_pipeline_stack' => $this->commercialCustomer->enterpriseSalesCrmPipelineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_customer_support_service_desk_stack' => $this->commercialCustomer->enterpriseCustomerSupportServiceDeskStack($domainId, $blueprint, $companyMetrics),
            'enterprise_marketing_growth_engine_stack' => $this->commercialCustomer->enterpriseMarketingGrowthEngineStack($domainId, $blueprint, $companyMetrics),
            'enterprise_finance_treasury_billing_stack' => $this->commercialCustomer->enterpriseFinanceTreasuryBillingStack($domainId, $blueprint, $companyMetrics),
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
            'enterprise_flow_benchmark_replay_stack' => $this->flowRuntime->enterpriseFlowBenchmarkReplayStack($domainId, $blueprint),
            'enterprise_tooling_research_stack' => $this->enterpriseToolingResearchStack($domainId, $blueprint),
            'enterprise_domain_operating_depth_stack' => $this->enterpriseDomainOperatingDepthStack($domainId, $blueprint),
            'enterprise_domain_agent_workforce_stack' => $this->agentWorkforce->enterpriseDomainAgentWorkforceStack($domainId, $blueprint),
            'enterprise_domain_solution_stack' => $this->enterpriseDomainSolutionStack($domainId, $blueprint),
            'enterprise_vertical_solution_suite_stack' => $this->enterpriseVerticalSolutionSuiteStack($domainId, $blueprint),
            'enterprise_domain_business_execution_mesh_stack' => $this->enterpriseDomainBusinessExecutionMeshStack($domainId, $blueprint, $companyMetrics),
            'enterprise_domain_provider_workbench_stack' => $this->enterpriseDomainProviderWorkbenchStack($domainId, $blueprint),
            'enterprise_industry_solution_ecosystem_stack' => $this->enterpriseIndustrySolutionEcosystemStack($domainId, $blueprint),
            'enterprise_domain_company_execution_suite_stack' => $this->enterpriseDomainCompanyExecutionSuiteStack($domainId, $blueprint, $companyMetrics),
            'enterprise_flow_work_product_delivery_stack' => $this->flowRuntime->enterpriseFlowWorkProductDeliveryStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_domain_data_connector_operating_stack' => $this->enterpriseDomainDataConnectorOperatingStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_flow_live_read_connector_probe_stack' => $this->flowRuntime->enterpriseFlowLiveReadConnectorProbeStack($domainId, $blueprint, $companyMetrics),
            'enterprise_domain_data_fabric_stack' => $this->enterpriseDomainDataFabricStack($domainId, $blueprint, $workProducts, $companyMetrics),
            'enterprise_company_revenue_delivery_operating_mesh' => $this->enterpriseCompanyRevenueDeliveryOperatingMesh($domainId, $blueprint, $workProducts, $companyMetrics),
            'premium_enterprise_agent_reference_model' => $this->agentWorkforce->premiumEnterpriseAgentReferenceModel($domainId, $blueprint),
            'enterprise_flow_operating_packages' => $this->flowRuntime->enterpriseFlowOperatingPackages($domainId, $blueprint),
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
        $company['readiness'] = $this->portfolioReport->readiness($company);
        $company['receipt_hash'] = MissionCanonicalHash::sha256($this->portfolioReport->companyHashInput($company));

        return $company;
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseCompanyOperatingBlueprintStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->externalResearchSourceBasis($domainId);
        $sourceIds = array_column($sources, 'source_id');
        $archetypes = $this->enterpriseWorkloadArchetypes($domainId);
        $archetypeIds = array_column($archetypes, 'archetype_id');

        return [
            'schema' => 'atlas.ai.company.enterprise_company_operating_blueprint_stack.v1',
            'company_id' => $domainId,
            'reference_model' => [
                'source_pattern' => 'claude_financial_services_style_skills_connectors_subagents_generalized_to_every_company',
                'source_urls' => [
                    'https://www.anthropic.com/news/claude-for-financial-services',
                    'https://www.anthropic.com/news/finance-agents',
                ],
                'adopted_enterprise_capabilities' => [
                    'task_specific_workload_templates',
                    'least_privilege_connector_permissions',
                    'managed_credential_vault_boundary',
                    'long_running_durable_agent_sessions',
                    'full_decision_tool_artifact_audit_log',
                    'human_in_the_loop_before_external_effects',
                    'source_grounded_artifact_generation',
                    'trace_replay_eval_and_acceptance_packet',
                ],
                'calendar_wait_blocker_enabled' => false,
            ],
            'workload_archetype_catalog' => $archetypes,
            'data_provider_contracts' => $this->enterpriseDataProviderContracts($domainId, $connectors),
            'connector_permission_profiles' => array_values(array_map(
                static fn (string $connector): array => [
                    'schema' => 'atlas.ai.company.enterprise_connector_permission_profile.v1',
                    'connector_id' => $connector,
                    'default_mode' => 'read_only_probe_or_fixture',
                    'live_scope_requires_operator_mandate' => true,
                    'credential_binding' => 'vault_reference_only_no_secret_material_export',
                    'source_lineage_required' => true,
                    'tool_call_receipt_required' => true,
                    'write_spend_trade_publish_deploy_delete_or_security_action_allowed' => false,
                    'profile_hash' => hash('sha256', 'connector_permission_profile|'.$connector),
                ],
                $connectors,
            )),
            'flow_operating_blueprints' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.enterprise_flow_operating_blueprint.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) ($spec[0] ?? 'company_manager_agent'),
                    'primary_archetype' => (string) ($archetypeIds[$index % max(1, count($archetypeIds))] ?? 'enterprise_operator_workload'),
                    'required_skills' => $this->support->domainWorkloadSkills($domainId, $flowId),
                    'required_connectors' => array_values((array) ($spec[1] ?? [])),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(6, count($sourceIds)))),
                    'subagent_lanes' => [
                        'source_grounding_lane' => $flowId.'.source_grounding_subagent',
                        'domain_methodology_lane' => $flowId.'.'.(string) data_get($this->support->domainWorkloadSubagents($domainId, $flowId), '1.subagent_id', 'domain_reviewer_subagent'),
                        'risk_policy_lane' => $flowId.'.risk_policy_reviewer_subagent',
                        'artifact_quality_lane' => $flowId.'.artifact_quality_reviewer_subagent',
                        'handoff_audit_lane' => $flowId.'.handoff_and_audit_subagent',
                    ],
                    'artifact_assembly_contract' => [
                        'target_artifact' => (string) ($spec[2] ?? 'enterprise_artifact'),
                        'sections_required' => ['source_snapshot', 'methodology', 'analysis_or_plan', 'risk_register', 'decision_packet', 'operator_handoff'],
                        'source_link_required_per_claim' => true,
                        'cross_source_reconciliation_required' => true,
                        'customer_visible_claims_require_operator_acceptance' => true,
                        'contract_hash' => hash('sha256', $domainId.'|'.$flowId.'|artifact_assembly_contract'),
                    ],
                    'runtime_handoff_contract' => [
                        'durable_session_required' => true,
                        'checkpoint_resume_required' => true,
                        'tool_receipts_required' => true,
                        'trace_export_required' => true,
                        'eval_replay_required' => true,
                        'rollback_or_manual_fallback_required' => true,
                        'external_effects_allowed' => false,
                        'handoff_hash' => hash('sha256', $domainId.'|'.$flowId.'|runtime_handoff_contract'),
                    ],
                    'quality_and_eval_gates' => [
                        'domain_correctness_floor' => 0.92,
                        'source_faithfulness_floor' => 0.97,
                        'policy_findings_allowed' => 0,
                        'fixture_replay_cases_required' => 25,
                        'adversarial_cases_required' => 5,
                        'second_reviewer_required_for_external_action' => true,
                    ],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'flow_operating_blueprint_hash' => hash('sha256', $domainId.'|enterprise_flow_operating_blueprint|'.$flowId.'|'.json_encode($spec)),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'artifact_assembly_lines' => array_values(array_map(
                static fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'pipeline' => ['source_snapshot', 'connector_read', 'analysis_workspace', 'artifact_draft', 'critic_review', 'policy_gate', 'acceptance_packet', 'operator_handoff'],
                    'all_claims_source_linked' => true,
                    'receipt_export_required' => true,
                    'customer_delivery_allowed_without_operator_acceptance' => false,
                    'assembly_hash' => hash('sha256', 'artifact_assembly_line|'.$flowId),
                ],
                $flowIds,
            )),
            'control_room_handoffs' => array_values(array_map(
                fn (string $flowId): array => [
                    'flow_id' => $flowId,
                    'review_queue' => (string) $blueprint['review_queue'],
                    'required_packet' => ['scope', 'sources', 'artifact_hash', 'tool_receipts', 'risk_register', 'rollback_plan', 'decision_options'],
                    'operator_acceptance_required' => true,
                    'second_reviewer_required_for_risky_external_effect' => true,
                    'auto_delivery_or_external_action_allowed' => false,
                    'handoff_hash' => hash('sha256', 'control_room_handoff|'.$flowId),
                ],
                $flowIds,
            )),
            'operating_blueprint_observability' => [
                'required_metrics' => [
                    'flow_blueprint_coverage',
                    'archetype_coverage',
                    'connector_permission_coverage',
                    'source_lineage_coverage',
                    'artifact_acceptance_rate',
                    'tool_receipt_completeness',
                    'eval_replay_pass_rate',
                    'operator_handoff_latency',
                    'external_effect_block_rate',
                    'policy_finding_zero_rate',
                ],
                'dashboard' => $domainId.'_enterprise_company_operating_blueprint_board',
                'alert_on' => ['missing_flow_blueprint', 'unscoped_connector', 'claim_without_source_link', 'missing_tool_receipt', 'operator_handoff_missing', 'external_effect_requested'],
            ],
            'blueprint_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'blueprint_is_internal_execution_contract' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
            'operating_blueprint_hash' => hash('sha256', $domainId.'|enterprise_company_operating_blueprint|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $archetypeIds)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function enterpriseWorkloadArchetypes(string $domainId): array
    {
        $map = [
            'finance' => [
                ['pitch_builder', 'build_source_linked_investment_or_customer_pitch', ['filings', 'market_data', 'company_data_room']],
                ['meeting_preparer', 'prepare_ic_board_or_customer_finance_meeting', ['crm_context', 'filings', 'notes']],
                ['earnings_reviewer', 'review_earnings_filings_and_call_materials', ['sec_filings', 'transcripts', 'market_data']],
                ['model_builder', 'build_and_audit_financial_model', ['financial_statements', 'assumptions', 'scenario_data']],
                ['market_researcher', 'research_market_and_comparable_companies', ['market_data', 'news', 'filings']],
                ['valuation_reviewer', 'review_dcf_comps_and_sensitivity', ['model_outputs', 'comps', 'risk_factors']],
                ['general_ledger_reconciler', 'reconcile_ledger_and_billing_artifacts', ['billing_ledger', 'bank_export_fixture', 'invoice_register']],
                ['month_end_closer', 'assemble_month_end_close_packet', ['ledger', 'accruals', 'variance_analysis']],
                ['statement_auditor', 'audit_financial_statement_claims', ['statements', 'source_docs', 'audit_trail']],
                ['kyc_screener', 'screen_counterparty_kyc_aml_risk', ['counterparty_profile', 'sanctions_fixture', 'risk_register']],
            ],
            'marketing' => [
                ['audience_researcher', 'build_source_linked_segment_and_persona_packet', ['analytics', 'crm', 'research']],
                ['campaign_strategist', 'design_campaign_strategy_with_budget_guardrails', ['analytics', 'experiment_registry', 'content_repository']],
                ['creative_brief_builder', 'produce_reviewable_creative_and_copy_brief', ['brand_system', 'content_repository', 'approval_gate']],
                ['attribution_analyst', 'analyze_channel_and_funnel_performance', ['analytics', 'experiment_registry', 'crm']],
                ['lifecycle_operator', 'prepare_lifecycle_journey_and_message_plan', ['crm', 'approval_gate', 'customer_segments']],
                ['brand_compliance_reviewer', 'verify_claims_tone_and_public_evidence', ['content_repository', 'source_registry', 'approval_gate']],
                ['experiment_allocator', 'rank_growth_tests_and_capacity', ['experiment_registry', 'analytics', 'budget_fixture']],
                ['voc_synthesizer', 'synthesize_customer_voice_into_positioning', ['crm', 'support_exports', 'research_handoff']],
            ],
            'cyber' => [
                ['posture_reviewer', 'assemble_defensive_security_posture_report', ['sbom', 'vulnerability_feeds', 'repo_state']],
                ['finding_triager', 'triage_appsec_findings_with_evidence', ['repo_read', 'evidence_chain', 'vulnerability_feeds']],
                ['control_mapper', 'map_obligations_controls_and_evidence', ['evidence_chain', 'policy_catalog', 'asset_inventory']],
                ['detection_reviewer', 'draft_detection_rule_proposal_without_deploying', ['threat_intel', 'logs_fixture', 'detection_catalog']],
                ['remediation_planner', 'prepare_remediation_program_and_ticket_proposals', ['ticketing_proposal_adapter', 'evidence_chain', 'repo_read']],
                ['incident_readiness_lead', 'rehearse_incident_response_without_external_action', ['runbooks', 'alert_fixture', 'evidence_chain']],
                ['attack_surface_reviewer', 'review_attack_surface_delta_defensively', ['repo_read', 'sbom', 'asset_fixture']],
            ],
        ];
        $fallback = [
            ['source_grounded_operator', 'produce_source_grounded_domain_artifact', ['source_registry', 'read_only_connector', 'evidence_ledger']],
            ['workflow_planner', 'design_durable_flow_execution_plan', ['runbook_repository', 'tool_registry', 'policy_gate']],
            ['artifact_reviewer', 'review_artifact_quality_risk_and_policy', ['critic_review', 'quality_scorecard', 'receipt_verifier']],
            ['handoff_coordinator', 'assemble_operator_handoff_packet', ['operator_review_queue', 'receipt_verifier', 'rollback_plan']],
            ['outcome_analyst', 'measure_internal_outcome_and_feedback', ['metrics_read_adapter', 'evidence_ledger', 'learning_loop']],
        ];

        return array_values(array_map(
            static fn (array $item): array => [
                'schema' => 'atlas.ai.company.enterprise_workload_archetype.v1',
                'archetype_id' => (string) $item[0],
                'purpose' => (string) $item[1],
                'data_inputs' => array_values((array) $item[2]),
                'required_controls' => ['source_lineage', 'least_privilege_connector_scope', 'tool_receipts', 'critic_review', 'operator_handoff'],
                'external_effects_allowed' => false,
                'archetype_hash' => hash('sha256', 'enterprise_workload_archetype|'.(string) $item[0]),
            ],
            $map[$domainId] ?? $fallback,
        ));
    }

    /**
     * @param list<string> $connectors
     * @return list<array<string,mixed>>
     */
    private function enterpriseDataProviderContracts(string $domainId, array $connectors): array
    {
        return array_values(array_map(
            static fn (string $connector, int $index): array => [
                'schema' => 'atlas.ai.company.enterprise_data_provider_contract.v1',
                'provider_id' => $domainId.'.'.$connector.'.provider_contract.v1',
                'connector_id' => $connector,
                'contract_mode' => 'read_only_or_fixture_until_operator_scope',
                'schema_snapshot_required' => true,
                'sample_fixture_required' => true,
                'source_lineage_required' => true,
                'cross_source_reconciliation_required' => true,
                'rate_limit_and_cost_guardrail_required' => true,
                'credential_secret_material_export_allowed' => false,
                'external_mutation_allowed' => false,
                'contract_hash' => hash('sha256', $domainId.'|enterprise_data_provider_contract|'.$connector.'|'.$index),
            ],
            $connectors,
            array_keys($connectors),
        ));
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

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
    private function enterpriseVendorLegalProcurementStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $sources = $this->support->domainSolutionSourceCatalog($domainId);

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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $metrics = array_values(array_unique(array_merge($manifestMetrics, (array) $blueprint['metrics'])));
        $workProducts = array_values(array_unique(array_merge($manifestProducts, (array) $blueprint['work_products'])));
        $sourceIds = array_column($this->support->domainSolutionSourceCatalog($domainId), 'source_id');

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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $agents = array_values((array) $blueprint['agent_roles']);
        $workProducts = array_values((array) $blueprint['work_products']);
        $metrics = array_values($companyMetrics);
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
            $this->support->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseConnectorCertificationStack(string $domainId, array $blueprint): array
    {
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $commandBase = $this->support->commandBase($domainId);

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
    private function enterpriseToolingResearchStack(string $domainId, array $blueprint): array
    {
        $flowIds = array_keys((array) $blueprint['flow_specs']);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $referencePatterns = array_values((array) $blueprint['reference_patterns']);

        return [
            'schema' => 'atlas.ai.company.enterprise_tooling_research_stack.v1',
            'company_id' => $domainId,
            'source_catalog' => $this->support->agentFrameworkSourceCatalog($domainId),
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
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
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
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $workProducts = array_values(array_unique(array_merge(
            array_values((array) $blueprint['work_products']),
            array_values(array_map(static fn (array $spec): string => (string) ($spec[2] ?? 'enterprise_artifact'), $flowSpecs)),
        )));
        $metrics = array_values((array) $blueprint['metrics']);
        $profile = $this->support->domainOperatingDepthProfile($domainId);
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
     * @param array<string,mixed> $blueprint
     * @return array<string,mixed>
     */
    private function enterpriseVerticalSolutionSuiteStack(string $domainId, array $blueprint): array
    {
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
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
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
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
                    'primary_source_ids' => array_values($sourceIds),
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
            'flow_source_verification_matrix' => array_values(array_map(
                static fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_source_verification_matrix.v1',
                    'flow_id' => $flowId,
                    'primary_source_ids' => array_values($sourceIds),
                    'required_connector_ids' => array_values((array) $spec[1]),
                    'verification_steps' => [
                        'source_hyperlinks_attached',
                        'cross_source_reconciliation',
                        'staleness_and_scope_disclosure',
                        'artifact_claim_map_export',
                        'operator_review_on_conflict',
                    ],
                    'minimum_source_count' => min(3, max(1, count($sourceIds))),
                    'cross_source_check_required' => true,
                    'claim_to_source_map_required' => true,
                    'missing_source_blocks_external_delivery' => true,
                    'external_execution_allowed' => false,
                    'verification_hash' => hash('sha256', 'flow_source_verification_matrix|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_compliance_workload_controls' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'schema' => 'atlas.ai.company.flow_compliance_workload_control.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'required_controls' => [
                        'policy_obligation_mapping',
                        'privacy_redaction_review',
                        'terms_and_vendor_risk_review',
                        'deterministic_replay_eval',
                        'audit_export_packet',
                        'operator_acceptance_gate',
                    ],
                    'capacity_model' => [
                        'expanded_workload_capacity_required' => true,
                        'queue_and_dlq_required' => true,
                        'cost_latency_metering_required' => true,
                        'event_spike_replay_required' => true,
                    ],
                    'operator_acceptance_required' => true,
                    'external_claim_or_delivery_allowed' => false,
                    'control_hash' => hash('sha256', 'flow_compliance_workload_control|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'implementation_partner_handoff_matrix' => array_values(array_map(
                static fn (string $flowId, int $index): array => [
                    'schema' => 'atlas.ai.company.implementation_partner_handoff.v1',
                    'flow_id' => $flowId,
                    'partner_track_id' => (string) ($partnerTracks[$index % max(1, count($partnerTracks))]['track_id'] ?? 'internal_enterprise_enablement'),
                    'handoff_artifacts' => ['implementation_plan', 'training_plan', 'governance_mapping', 'measurement_model', 'rollback_runbook', 'operator_acceptance_packet'],
                    'expert_implementation_support_required' => true,
                    'procurement_or_external_contracting_allowed' => false,
                    'handoff_hash' => hash('sha256', 'implementation_partner_handoff|'.$domainId.'|'.$flowId),
                ],
                $flowIds,
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
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->domainCompanyExecutionSuiteSources($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->support->domainCompanyExecutionSuiteProfile($domainId);

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
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $companyMetrics
     * @return array<string,mixed>
     */
    private function enterpriseDomainDataConnectorOperatingStack(string $domainId, array $blueprint, array $workProducts, array $companyMetrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->domainCompanyExecutionSuiteSources($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->support->domainCompanyExecutionSuiteProfile($domainId);

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
     * @return array<string,mixed>
     */
    private function enterpriseOperationalDressRehearsalStack(string $domainId, array $blueprint): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);

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
     * @param list<string> $workProducts
     * @param list<string> $metrics
     * @return array<string,mixed>
     */
    private function enterpriseDomainDataFabricStack(string $domainId, array $blueprint, array $workProducts, array $metrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $sources = $this->support->domainSolutionSourceCatalog($domainId);
        $sourceIds = array_values(array_column($sources, 'source_id'));
        $profile = $this->support->domainOperatingDepthProfile($domainId);
        $dataProducts = array_values(array_unique(array_merge(
            array_values((array) $profile['data_products']),
            $workProducts,
        )));
        $systems = array_values((array) $profile['systems']);
        $fabricCapabilities = [
            'unified_domain_data_interface',
            'direct_source_link_verification',
            'cross_source_claim_check',
            'internal_private_data_room',
            'domain_model_and_scenario_workbench',
            'compliance_policy_obligation_mapping',
            'portfolio_or_customer_decision_packet_factory',
            'implementation_partner_enablement_track',
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_domain_data_fabric_stack.v1',
            'company_id' => $domainId,
            'reference_basis' => [
                'anthropic_claude_for_financial_services' => [
                    'url' => 'https://www.anthropic.com/news/claude-for-financial-services',
                    'adopted_patterns' => [
                        'unified_interface_over_market_internal_and_enterprise_platform_data',
                        'direct_hyperlinks_to_source_materials_for_verification',
                        'prebuilt_connector_ecosystem_for_critical_data_sources',
                        'domain_workloads_such_as_due_diligence_modeling_compliance_and_customer_operations',
                        'expert_implementation_support_for_enterprise_value_realization',
                    ],
                ],
                'generalization_rule' => 'apply_financial_services_grade_data_fabric_to_every_atlas_company_domain',
            ],
            'fabric_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'maturity_evidence_replaces_fixed_day_wait' => true,
                'direct_source_link_required_for_every_claim' => true,
                'cross_source_verification_required' => true,
                'private_or_regulated_data_requires_redaction' => true,
                'connector_probe_required_before_live_read' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'real_money_trade_or_offensive_security_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
            ],
            'domain_data_source_catalog' => array_values(array_map(
                static fn (array $source, int $index): array => [
                    'source_id' => (string) ($source['source_id'] ?? 'unknown_source'),
                    'url' => (string) ($source['url'] ?? ''),
                    'source_class' => $index < 3 ? 'primary_domain_reference' : 'enterprise_connector_or_methodology_reference',
                    'verification_contract' => [
                        'direct_link_required' => true,
                        'freshness_check_required' => true,
                        'claim_support_mapping_required' => true,
                        'contradiction_register_required' => true,
                    ],
                    'adoption_state' => 'reference_ready_read_only_or_manual_import_until_connector_probe',
                    'source_hash' => hash('sha256', 'domain_data_fabric_source|'.(string) ($source['source_id'] ?? 'unknown_source')),
                ],
                $sources,
                array_keys($sources),
            )),
            'connector_data_provider_matrix' => array_values(array_map(
                fn (string $connector, int $index): array => [
                    'connector_id' => $connector,
                    'provider_class' => (string) ($systems[$index % max(1, count($systems))] ?? 'domain_system'),
                    'mapped_data_products' => array_values(array_slice($dataProducts, $index % max(1, count($dataProducts)), min(4, count($dataProducts)))),
                    'interface_modes' => ['manual_import_fixture', 'read_only_probe', 'mcp_or_api_adapter', 'supervised_external_handoff'],
                    'required_controls' => ['rbac_scope', 'credential_vault_ref', 'schema_snapshot', 'rate_limit', 'audit_log', 'source_lineage_export', 'disable_plan'],
                    'write_tools_enabled' => false,
                    'external_mutation_allowed' => false,
                    'provider_hash' => hash('sha256', $domainId.'|data_fabric_provider|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'domain_data_products' => array_values(array_map(
                static fn (string $dataProduct, int $index): array => [
                    'data_product_id' => $dataProduct,
                    'canonical_contract' => $dataProduct.'.domain_data_product.v1',
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'lineage_fields' => ['source_ref', 'connector_id', 'retrieved_at', 'input_hash', 'normalization_hash', 'artifact_hash', 'critic_review_hash'],
                    'quality_gates' => ['freshness_disclosed', 'source_faithfulness_score', 'schema_validation', 'policy_boundary_check', 'operator_handoff_readiness'],
                    'consumer_surfaces' => ['company_command_center', 'flow_artifact_factory', 'portfolio_board_packet', 'operator_review_queue'],
                    'external_export_allowed_without_operator' => false,
                    'data_product_hash' => hash('sha256', 'domain_data_fabric_product|'.$dataProduct),
                ],
                $dataProducts,
                array_keys($dataProducts),
            )),
            'flow_data_workbenches' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_domain_data_workbench.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'source_refs' => array_values(array_slice($sourceIds, $index % max(1, count($sourceIds)), min(5, count($sourceIds)))),
                    'data_product_refs' => array_values(array_slice($dataProducts, $index % max(1, count($dataProducts)), min(5, count($dataProducts)))),
                    'workbench_capabilities' => $fabricCapabilities,
                    'required_artifacts' => [(string) ($spec[2] ?? 'enterprise_artifact'), 'source_pack', 'model_or_analysis_trace', 'risk_and_policy_review', 'operator_handoff_packet'],
                    'quality_floor' => [
                        'source_faithfulness' => 0.95,
                        'domain_correctness' => 0.9,
                        'trace_completeness' => 0.95,
                        'policy_findings_allowed' => 0,
                    ],
                    'external_side_effects_enabled' => false,
                    'workbench_hash' => hash('sha256', $domainId.'|flow_data_workbench|'.$flowId.'|'.(string) $spec[0]),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'flow_decision_packet_factories' => array_values(array_map(
                static fn (string $flowId, array $spec): array => [
                    'flow_id' => $flowId,
                    'packet_id' => $flowId.'.decision_packet_factory.v1',
                    'artifact_type' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'sections' => ['objective', 'source_links', 'data_context', 'analysis_or_model', 'options', 'risk_controls', 'recommendation', 'receipts', 'operator_next_step'],
                    'must_include' => ['direct_source_links', 'assumption_register', 'methodology_notes', 'policy_gate_result', 'handoff_acceptance_contract'],
                    'customer_visible_or_external_action_requires_operator' => true,
                    'factory_hash' => hash('sha256', 'flow_decision_packet_factory|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
            )),
            'compliance_and_obligation_automation' => [
                'schema' => 'atlas.ai.company.domain_data_fabric_compliance.v1',
                'control_sets' => ['data_provenance', 'privacy_redaction', 'model_risk_or_methodology_review', 'customer_visible_claim_review', 'external_action_authority', 'audit_export'],
                'obligation_map_required_for_regulated_or_customer_visible_output' => true,
                'policy_gap_packet_required_before_promotion' => true,
                'compliance_hash' => hash('sha256', $domainId.'|domain_data_fabric_compliance'),
            ],
            'implementation_enablement_tracks' => array_values(array_map(
                static fn (string $capability): array => [
                    'capability_id' => $capability,
                    'enablement_sequence' => ['contract', 'fixture', 'read_only_probe', 'trace_export', 'replay_eval', 'operator_acceptance', 'supervised_cutover_packet'],
                    'done_evidence' => ['contract_hash', 'fixture_hash', 'probe_receipt_hash', 'eval_hash', 'operator_acceptance_hash'],
                    'external_cutover_allowed_without_operator' => false,
                    'track_hash' => hash('sha256', 'domain_data_fabric_enablement|'.$capability),
                ],
                $fabricCapabilities,
            )),
            'fabric_observability' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    ['source_link_coverage', 'cross_source_verification_rate', 'connector_probe_pass_rate', 'data_product_lineage_coverage', 'decision_packet_acceptance_rate', 'policy_gap_count', 'external_effect_block_rate'],
                    array_slice($metrics, 0, min(6, count($metrics))),
                ))),
                'dashboard' => $domainId.'_enterprise_domain_data_fabric_board',
                'alert_on' => ['missing_source_link', 'stale_source', 'connector_probe_failed', 'unsupported_claim', 'policy_gap', 'external_effect_requested'],
            ],
            'domain_data_fabric_hash' => hash('sha256', $domainId.'|enterprise_domain_data_fabric|'.implode('|', $flowIds).'|'.implode('|', $connectors).'|'.implode('|', $sourceIds)),
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<string> $workProducts
     * @param list<string> $metrics
     * @return array<string,mixed>
     */
    private function enterpriseCompanyRevenueDeliveryOperatingMesh(string $domainId, array $blueprint, array $workProducts, array $metrics): array
    {
        $flowSpecs = (array) $blueprint['flow_specs'];
        $flowIds = array_keys($flowSpecs);
        $connectors = $this->support->enterpriseConnectorsForBlueprint($blueprint);
        $commercialStages = ['package', 'qualify', 'scope', 'deliver', 'support', 'bill', 'renew', 'learn'];
        $operatingSystems = [
            'product_catalog',
            'sales_crm',
            'delivery_lane',
            'support_desk',
            'billing_ledger',
            'risk_register',
            'evidence_room',
            'operator_board',
        ];

        return [
            'schema' => 'atlas.ai.company.enterprise_revenue_delivery_operating_mesh.v1',
            'company_id' => $domainId,
            'mesh_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'maturity_evidence_replaces_fixed_day_wait' => true,
                'customer_commitment_allowed_without_operator' => false,
                'invoice_payment_or_capital_action_allowed_without_operator' => false,
                'public_claim_or_campaign_publish_allowed_without_operator' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
            ],
            'operating_systems' => array_values(array_map(
                static fn (string $system, int $index): array => [
                    'system_id' => $system,
                    'system_class' => $index < 4 ? 'front_office_and_delivery' : 'finance_risk_and_governance',
                    'required_records' => ['source_lineage', 'decision_receipt', 'operator_handoff', 'rollback_plan', 'audit_export'],
                    'write_mode' => 'internal_packet_only_until_signed_scope',
                    'system_hash' => hash('sha256', 'revenue_delivery_system|'.$system),
                ],
                $operatingSystems,
                array_keys($operatingSystems),
            )),
            'flow_commercial_operating_threads' => array_values(array_map(
                fn (string $flowId, array $spec, int $index): array => [
                    'schema' => 'atlas.ai.company.flow_revenue_delivery_thread.v1',
                    'flow_id' => $flowId,
                    'owner_agent' => (string) $spec[0],
                    'artifact_type' => (string) ($spec[2] ?? 'enterprise_artifact'),
                    'work_product_refs' => array_values(array_slice($workProducts, $index % max(1, count($workProducts)), min(5, count($workProducts)))),
                    'connector_refs' => array_values((array) ($spec[1] ?? [])),
                    'commercial_stage_contracts' => array_values(array_map(
                        static fn (string $stage): array => [
                            'stage_id' => $stage,
                            'required_packet' => $stage.'.'.$flowId.'.packet.v1',
                            'required_evidence' => ['source_links', 'acceptance_criteria', 'risk_review', 'cost_or_capacity_note', 'operator_next_step'],
                            'external_effect_allowed' => false,
                            'stage_hash' => hash('sha256', 'flow_revenue_delivery_stage|'.$flowId.'|'.$stage),
                        ],
                        $commercialStages,
                    )),
                    'handoff_chain' => [
                        'product_to_sales',
                        'sales_to_delivery',
                        'delivery_to_support',
                        'support_to_success',
                        'success_to_billing',
                        'billing_to_board_review',
                    ],
                    'thread_hash' => hash('sha256', $domainId.'|revenue_delivery_thread|'.$flowId),
                ],
                $flowIds,
                $flowSpecs,
                array_keys($flowIds),
            )),
            'company_board_value_scorecard' => [
                'required_metrics' => array_values(array_unique(array_merge(
                    ['qualified_pipeline_value', 'delivery_sla_hit_rate', 'support_resolution_quality', 'gross_margin_guardrail', 'billing_readiness', 'renewal_expansion_signal', 'evidence_acceptance_rate', 'external_effect_block_rate'],
                    array_slice($metrics, 0, min(6, count($metrics))),
                ))),
                'review_cadence' => 'weekly_operator_board_review_until_external_mandates_exist',
                'score_floor_for_target_9' => 0.9,
            ],
            'connector_to_commercial_system_map' => array_values(array_map(
                static fn (string $connector, int $index): array => [
                    'connector_id' => $connector,
                    'mapped_system' => $operatingSystems[$index % count($operatingSystems)],
                    'allowed_mode' => 'read_only_probe_or_internal_fixture',
                    'required_preflight' => ['credential_scope', 'schema_snapshot', 'contract_test', 'rate_limit', 'audit_log', 'disable_plan'],
                    'external_write_enabled' => false,
                    'connector_map_hash' => hash('sha256', 'revenue_delivery_connector|'.$connector),
                ],
                $connectors,
                array_keys($connectors),
            )),
            'mesh_observability' => [
                'required_metrics' => ['thread_packet_coverage', 'stage_evidence_coverage', 'handoff_latency', 'sla_risk_count', 'billing_exception_count', 'operator_acceptance_rate', 'source_lineage_coverage', 'external_effect_block_rate'],
                'dashboard' => $domainId.'_revenue_delivery_operating_mesh',
                'alert_on' => ['missing_stage_packet', 'stale_customer_context', 'billing_without_scope', 'public_claim_without_evidence', 'external_effect_requested'],
            ],
            'revenue_delivery_mesh_hash' => hash('sha256', $domainId.'|revenue_delivery_operating_mesh|'.implode('|', $flowIds).'|'.implode('|', $connectors)),
        ];
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
            $this->support->enterpriseConnectorsForBlueprint($blueprint),
        ));
    }

}
