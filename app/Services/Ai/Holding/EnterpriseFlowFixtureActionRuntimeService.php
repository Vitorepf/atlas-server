<?php

namespace App\Services\Ai\Holding;

use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureAttestations;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureBuilders;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureRuntimeRecords;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureSupport;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureRuntimeStatusReaders;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureBusinessStatusReaders;
use Illuminate\Support\Str;

class EnterpriseFlowFixtureActionRuntimeService
{
    public const SCHEMA = 'atlas.ai.company.enterprise_flow_fixture_action_run.v1';

    public const INTERNAL_SCHEMA = 'atlas.ai.company.enterprise_flow_action_run.v1';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $buildoutReport = null;

    private readonly EnterpriseFlowFixtureBuilders $builders;

    private readonly EnterpriseFlowFixtureRuntimeRecords $records;

    private readonly EnterpriseFlowFixtureAttestations $attestations;

    private readonly EnterpriseFlowFixtureRuntimeStatusReaders $readersRuntime;

    private readonly EnterpriseFlowFixtureBusinessStatusReaders $readersBusiness;

    public function __construct(
        private readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
    ) {
        $this->builders = new EnterpriseFlowFixtureBuilders();
        $this->records = new EnterpriseFlowFixtureRuntimeRecords($this);
        $this->attestations = new EnterpriseFlowFixtureAttestations();
        $this->readersRuntime = new EnterpriseFlowFixtureRuntimeStatusReaders($this, $this->records);
        $this->readersBusiness = new EnterpriseFlowFixtureBusinessStatusReaders($this, $this->records);
    }

    public function supports(string $companyId, string $action): bool
    {
        return $this->actionContract($companyId, $action) !== null;
    }

    public function clearRuntimeRecordCache(?string $companyId = null): void
    {
        $this->records->clearRuntimeRecordCache($companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildoutReport(): array
    {
        if ($this->buildoutReport === null) {
            $this->buildoutReport = $this->buildout->report();
        }

        return $this->buildoutReport;
    }

    /**
     * Table-driven engine behind the uniform *RuntimeStatus readers (Obra #8 R-16).
     *
     * @param array{
     *     schema:string,status_complete:string,status_missing:string,hash_key:string,
     *     completed_key:string,missing_key:string,
     *     flows:\Closure,gate:\Closure|list<string|array{0:string,1:string}>,
     *     counts:array<string,string|array{0:string,1:string}|\Closure>,
     *     policy:array<string,bool>
     * } $spec
     * @return array<string,mixed>
     */
    public function runtimeStatusFor(array $spec, ?string $companyId): array
    {
        $gate = $spec['gate'];
        if (! $gate instanceof \Closure) {
            $gatePredicates = array_map(EnterpriseFlowFixtureSupport::recordPredicate(...), $gate);
            $gate = static function (array $record) use ($gatePredicates): bool {
                foreach ($gatePredicates as $predicate) {
                    if (! $predicate($record)) {
                        return false;
                    }
                }

                return true;
            };
        }

        $countPredicates = [];
        foreach ($spec['counts'] as $summaryKey => $definition) {
            $countPredicates[$summaryKey] = $definition instanceof \Closure
                ? $definition
                : EnterpriseFlowFixtureSupport::recordPredicate($definition);
        }

        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = ($spec['flows'])($company);
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $matchingRecords = array_values(array_filter($companyRecords, $gate));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $matchingRecords,
            ))));

            $companySummary = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                $spec['completed_key'] => count(array_intersect($flows, $completedFlows)),
                $spec['missing_key'] => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            foreach ($countPredicates as $summaryKey => $predicate) {
                $companySummary[$summaryKey] = count(array_filter($companyRecords, $predicate));
            }

            $companies[] = $companySummary;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company[$spec['completed_key']], $companies));
        $summary = [
            'company_count' => count($companies),
            'expected_flow_count' => $expectedFlowCount,
            $spec['completed_key'] => $completedFlowCount,
            'runtime_record_count' => count($records),
        ];
        foreach ($countPredicates as $summaryKey => $_predicate) {
            $summary[$summaryKey] = array_sum(array_map(static fn (array $company): int => (int) $company[$summaryKey], $companies));
        }
        $summary['external_side_effect_count'] = count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true)));
        $summary['coverage_rate'] = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

        return AtlasEnvelope::seal([
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => $spec['schema'],
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? $spec['status_complete']
                : $spec['status_missing'],
            'generated_at' => now()->toJSON(),
            'summary' => $summary,
            'companies' => $companies,
            'records' => $records,
            'policy' => $spec['policy'],
        ], $spec['hash_key']);
    }


    public function runPortfolioInternal(?string $companyId = null): array
    {
        return $this->readersRuntime->runPortfolioInternal($companyId);
    }

    public function companySystemModelRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->companySystemModelRuntimeStatus($companyId);
    }

    public function internalOperationsBackboneRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->internalOperationsBackboneRuntimeStatus($companyId);
    }

    public function activationRunOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->activationRunOperationsRuntimeStatus($companyId);
    }

    public function runtimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->runtimeStatus($companyId);
    }

    public function verticalSolutionRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->verticalSolutionRuntimeStatus($companyId);
    }

    public function domainBusinessExecutionRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainBusinessExecutionRuntimeStatus($companyId);
    }

    public function companyOperatingSpineRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->companyOperatingSpineRuntimeStatus($companyId);
    }

    public function commercialOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->commercialOperationsRuntimeStatus($companyId);
    }

    public function domainProviderWorkbenchRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainProviderWorkbenchRuntimeStatus($companyId);
    }

    public function domainCompanyExecutionSuiteRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainCompanyExecutionSuiteRuntimeStatus($companyId);
    }

    public function flowWorkProductDeliveryRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->flowWorkProductDeliveryRuntimeStatus($companyId);
    }

    public function domainDataConnectorOperatingRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainDataConnectorOperatingRuntimeStatus($companyId);
    }

    public function flowLiveReadConnectorProbeRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->flowLiveReadConnectorProbeRuntimeStatus($companyId);
    }

    public function externalResearchAdoptionRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->externalResearchAdoptionRuntimeStatus($companyId);
    }

    public function flowBenchmarkReplayRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->flowBenchmarkReplayRuntimeStatus($companyId);
    }

    public function connectorCertificationPreflightRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->connectorCertificationPreflightRuntimeStatus($companyId);
    }

    public function commandCenterControlTowerRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->commandCenterControlTowerRuntimeStatus($companyId);
    }

    public function operationalDressRehearsalRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->operationalDressRehearsalRuntimeStatus($companyId);
    }

    public function semanticOperatingGraphRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->semanticOperatingGraphRuntimeStatus($companyId);
    }

    public function domainSolutionPlaybookRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainSolutionPlaybookRuntimeStatus($companyId);
    }

    public function domainOperatingDepthRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainOperatingDepthRuntimeStatus($companyId);
    }

    public function domainAgentWorkforceRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->domainAgentWorkforceRuntimeStatus($companyId);
    }

    public function agentToolchainRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->agentToolchainRuntimeStatus($companyId);
    }

    public function flowExecutionFoundationRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersRuntime->flowExecutionFoundationRuntimeStatus($companyId);
    }

    public function workforceCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->workforceCapacityRuntimeStatus($companyId);
    }

    public function portfolioDependencyRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->portfolioDependencyRuntimeStatus($companyId);
    }

    public function operationalDossierRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->operationalDossierRuntimeStatus($companyId);
    }

    public function operationalOutcomeRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->operationalOutcomeRuntimeStatus($companyId);
    }

    public function autonomyPromotionRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->autonomyPromotionRuntimeStatus($companyId);
    }

    public function holdingOutcomeScorecardStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->holdingOutcomeScorecardStatus($companyId);
    }

    public function portfolioDecisionPacketStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->portfolioDecisionPacketStatus($companyId);
    }

    public function companyBoardOperatingReviewStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->companyBoardOperatingReviewStatus($companyId);
    }

    public function crossCompanyHandoffRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->crossCompanyHandoffRuntimeStatus($companyId);
    }

    public function customerAccountRevenueRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->customerAccountRevenueRuntimeStatus($companyId);
    }

    public function productizedServiceRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->productizedServiceRuntimeStatus($companyId);
    }

    public function salesCrmPipelineRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->salesCrmPipelineRuntimeStatus($companyId);
    }

    public function customerSupportServiceDeskRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->customerSupportServiceDeskRuntimeStatus($companyId);
    }

    public function marketingGrowthEngineRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->marketingGrowthEngineRuntimeStatus($companyId);
    }

    public function financeTreasuryBillingRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->financeTreasuryBillingRuntimeStatus($companyId);
    }

    public function governanceRiskOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->governanceRiskOperationsRuntimeStatus($companyId);
    }

    public function unitEconomicsCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->unitEconomicsCapacityRuntimeStatus($companyId);
    }

    public function businessOperatingPacketRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->businessOperatingPacketRuntimeStatus($companyId);
    }

    public function deliveryRiskRuntimeStatus(?string $companyId = null): array
    {
        return $this->readersBusiness->deliveryRiskRuntimeStatus($companyId);
    }


    /**
     * Entangled by design: one payload assembled from ~200 interdependent per-flow locals.
     * GOD-DEBULK kept it whole on the facade (shared mutable local state, not splittable
     * byte-identically); its leaf helpers live in EnterpriseFlowFixture\{Support,Builders,RuntimeRecords}.
     *
     * @return array<string,mixed>
     */
    public function run(string $companyId, string $action, bool $fixtureMode = true): array
    {
        $company = $this->buildout->companyPacket($companyId);
        $contract = EnterpriseFlowFixtureSupport::actionContractFromCompany($company, $action);

        if ($contract === null) {
            throw new \InvalidArgumentException("Unknown enterprise fixture action [{$action}] for company [{$companyId}].");
        }

        $flowId = (string) $contract['flow_id'];
        $flowSpec = EnterpriseFlowFixtureSupport::findByFlow($company, 'flow_specs', $flowId);
        $flowRunbook = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_orchestration_runbook_stack.flow_runbooks', $flowId);
        $executableFlowPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_runtime_implementation_stack.executable_flow_packets', $flowId);
        $agentToolRouting = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_runtime_implementation_stack.agent_tool_routing_matrix', $flowId);
        $flowArtifactIoContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_runtime_implementation_stack.flow_artifact_io_contracts', $flowId);
        $supervisionShadowGate = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_runtime_implementation_stack.supervision_and_shadow_runtime_gates', $flowId);
        $canonicalFixture = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_fixture_simulation_stack.canonical_flow_fixtures', $flowId);
        $trajectory = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_fixture_simulation_stack.expected_trace_trajectories', $flowId);
        $assertionSuite = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_fixture_simulation_stack.quality_assertion_suites', $flowId);
        $failureCases = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_fixture_simulation_stack.failure_injection_cases', $flowId);
        $dryRun = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_fixture_simulation_stack.dry_run_command_plan', $flowId);
        $stateSchema = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_action_runtime_stack.handler_state_schemas', $flowId);
        $eventPlan = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_action_runtime_stack.runtime_event_emission_plan', $flowId);
        $checkpoint = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_action_runtime_stack.operator_checkpoint_contracts', $flowId);
        $flowToolkitAssignment = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_agent_toolkit_stack.flow_toolkit_assignments', $flowId);
        $agentRepositoryEpic = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_agent_repository_adoption_pipeline.flow_repository_implementation_epics', $flowId);
        $workforceStaffing = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', $flowId);
        $portfolioDependencyRouting = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_portfolio_dependency_stack.flow_dependency_routing', $flowId);
        $externalResearchFlowMatrix = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_external_research_adoption_stack.per_flow_adoption_matrix', $flowId);
        $externalResearchSourceBasis = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.source_basis', []));
        $externalResearchOfficialRepositories = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.official_framework_repositories', []));
        $externalResearchDomainRepositories = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.domain_repository_candidates', []));
        $externalResearchCapabilityMap = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.source_to_company_capability_map', []));
        $externalResearchConnectorBacklog = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.connector_and_data_provider_backlog', []));
        $externalResearchProductionGates = (array) data_get($company, 'enterprise_external_research_adoption_stack.productionization_gates', []);
        $operatingPackage = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId);
        $verticalSolutionKit = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', $flowId);
        $verticalConnectorWorkbenches = EnterpriseFlowFixtureSupport::verticalConnectorWorkbenches($company, array_values((array) data_get($verticalSolutionKit, 'connector_refs', [])));
        $artifactFactory = EnterpriseFlowFixtureSupport::artifactFactoryForWorkProduct($company, (string) data_get($verticalSolutionKit, 'work_product', ''));
        $businessExecutionCell = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', $flowId);
        $businessKpiBinding = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', $flowId);
        $businessServiceLane = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.domain_service_lanes', $flowId);
        $domainSolutionPlaybook = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', $flowId);
        $domainOperatingDepthPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_operating_depth_stack.flow_depth_packets', $flowId);
        $domainAgentCrew = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', $flowId);
        $flowProviderRoute = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', $flowId);
        $providerEvaluationCase = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_provider_workbench_stack.provider_evaluation_cases', $flowId);
        $offlineDatasetContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', $flowId);
        $traceGradingRubric = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', $flowId);
        $adversarialRegressionCase = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', $flowId);
        $deterministicStateAssertion = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', $flowId);
        $replayComparisonMatrix = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', $flowId);
        $semanticFlowEdge = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', $flowId);
        $customerJourney = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', $flowId);
        $productizedServiceOffer = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId);
        $productizedDeliveryBlueprint = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_productized_service_stack.service_delivery_blueprints', $flowId);
        $productizedIntakeContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', $flowId);
        $productizedSlaContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_productized_service_stack.sla_success_contracts', $flowId);
        $productizedProofTemplate = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_productized_service_stack.proof_and_case_study_templates', $flowId);
        $salesOpportunityRoute = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId);
        $salesProposalPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId);
        $salesMutualActionPlan = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.mutual_action_plans', $flowId);
        $salesAccountResearchWorkbench = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', $flowId);
        $salesDealRoomPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_deal_room_packets', $flowId);
        $salesPipelineForecastReview = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_pipeline_forecast_reviews', $flowId);
        $salesMapRiskReview = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_mutual_action_plan_risk_reviews', $flowId);
        $salesDeliveryHandoff = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_sales_crm_pipeline_stack.sales_to_delivery_handoff_contracts', $flowId);
        $supportLane = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId);
        $supportTicketSla = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', $flowId);
        $supportKbTemplate = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.knowledge_base_article_templates', $flowId);
        $supportEscalationRunbook = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.escalation_and_incident_runbooks', $flowId);
        $supportResolutionRca = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.resolution_quality_and_rca_contracts', $flowId);
        $supportCaseResolutionWorkbench = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', $flowId);
        $supportHealthEscalationPlaybook = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_customer_health_escalation_playbooks', $flowId);
        $supportKnowledgeQualityReview = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_knowledge_quality_reviews', $flowId);
        $supportAutomationDeflectionTest = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_automation_deflection_tests', $flowId);
        $marketingCampaignBlueprint = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', $flowId);
        $marketingContentFactory = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.content_asset_factories', $flowId);
        $marketingExperiment = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.experiment_backlog', $flowId);
        $marketingGrowthIntelligenceWorkbench = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', $flowId);
        $marketingAttributionExperimentModel = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_attribution_experiment_models', $flowId);
        $marketingChannelBudgetGuardrail = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_channel_budget_guardrails', $flowId);
        $marketingPublicClaimEvidencePacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_public_claim_evidence_packets', $flowId);
        $marketingChannelPlan = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.channel_and_distribution_plan', $flowId);
        $marketingBrandReview = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.brand_compliance_review_packets', $flowId);
        $marketingCrmHandoff = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_marketing_growth_engine_stack.growth_to_crm_handoff_contracts', $flowId);
        $financeResearchWorkbench = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', $flowId);
        $financeBudgetEnvelope = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', $flowId);
        $financeForecastModel = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_forecast_models', $flowId);
        $financeModelRiskControl = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_model_risk_controls', $flowId);
        $financeInvestmentCommitteePacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_investment_committee_packets', $flowId);
        $financeBillingLedger = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId);
        $accountOnboardingPlan = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', $flowId);
        $accountServiceReview = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', $flowId);
        $vendorProcurementRouting = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', $flowId);
        $resilienceFailureMode = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', $flowId);
        $resilienceExercise = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', $flowId);
        $analyticsDecisionRegister = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', $flowId);
        $scenarioForecast = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_analytics_decision_intelligence_stack.scenario_and_forecast_model', $flowId);
        $knowledgeLearningLoop = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', $flowId);
        $playbookChangeControl = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_knowledge_memory_learning_stack.playbook_change_control', $flowId);
        $identityDataBoundary = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', $flowId);
        $purposeConsent = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_identity_access_data_sovereignty_stack.purpose_consent_registry', $flowId);
        $deliverySla = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', $flowId);
        $flowCostCenter = EnterpriseFlowFixtureSupport::findByFlow($company, 'portfolio_finance_stack.flow_cost_centers', $flowId);
        $flowUnitEconomics = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', $flowId);
        $capacitySimulation = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', $flowId);
        $strategicRivalMap = EnterpriseFlowFixtureSupport::findByFlow($company, 'strategic_intelligence_stack.rival_and_alternative_map', $flowId);
        $grcEvidence = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId);
        $domainExecutionPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_execution_packets', $flowId);
        $domainRiskControlPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', $flowId);
        $domainDecisionRoomPacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_decision_room_packets', $flowId);
        $domainReplayEvalPack = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', $flowId);
        $flowWorkProductDeliveryBlueprint = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', $flowId);
        $flowWorkProductAcceptance = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId);
        $flowWorkProductHandoff = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId);
        $flowWorkProductReplayCheck = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', $flowId);
        $domainDataConnectorContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
        $domainConnectorFixtureEval = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', $flowId);
        $flowLiveReadProbeContract = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', $flowId);
        $flowLiveReadProbeEvidence = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', $flowId);
        $controlTowerLane = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', $flowId);
        $incidentExceptionDesk = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_control_tower_run_operations_stack.incident_and_exception_desk', $flowId);
        $changeWindowRelease = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_control_tower_run_operations_stack.change_window_and_release_calendar', $flowId);
        $flowCommandCard = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_company_command_center_stack.flow_command_cards', $flowId);
        $rehearsalRunbook = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', $flowId);
        $operatorAcceptancePacket = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.operator_acceptance_packets', $flowId);
        $rollbackDrill = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.rollback_drill_matrix', $flowId);
        $promotionEvidence = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.promotion_evidence_matrix', $flowId);
        $businessProcess = EnterpriseFlowFixtureSupport::findByFlow($company, 'business_process_map', $flowId);
        $sloSli = EnterpriseFlowFixtureSupport::findByFlow($company, 'go_to_production_pack.slo_sli_catalog', $flowId);
        $flowActivationMatrix = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_integration_activation_plan.flow_activation_matrix', $flowId);
        $sourceActivationTracks = array_values((array) data_get($company, 'enterprise_integration_activation_plan.source_activation_tracks', []));
        $connectorActivationTracks = array_values((array) data_get($company, 'enterprise_integration_activation_plan.connector_activation_tracks', []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $connectorBackplane = array_values((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.shared_connector_backplane', []));
        $connectorRuntimeAdapters = array_values((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.connector_runtime_adapters', []));
        $frameworkSourceCatalog = array_values((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.framework_source_catalog', []));
        $agentFrameworkWatchlist = array_values((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.repository_and_agent_watchlist.global_agent_frameworks', []));
        $frameworkScorecards = array_values((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.framework_adoption_scorecards', []));
        $versionPinPlan = array_values((array) data_get($company, 'enterprise_agent_repository_adoption_pipeline.version_pin_and_supply_chain_plan', []));
        $premiumAgenticArchitecture = (array) data_get($company, 'premium_enterprise_agent_reference_model.enterprise_agentic_architecture_basis', []);
        $premiumTemplates = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.managed_agent_templates', []));
        $premiumTemplateRuntimeContracts = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.template_runtime_contracts', []));
        $premiumFlowTemplateMap = EnterpriseFlowFixtureSupport::findByFlow($company, 'premium_enterprise_agent_reference_model.flow_template_map', $flowId);
        $premiumManagedAgentWorkflow = EnterpriseFlowFixtureSupport::findByFlow($company, 'premium_enterprise_agent_reference_model.flow_managed_agent_workflows', $flowId);
        $premiumWorkbenches = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.data_and_tool_workbenches', []));
        $premiumMcpServerPlan = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.connector_mcp_server_plan', []));
        $premiumReplayBenchmark = EnterpriseFlowFixtureSupport::findByFlow($company, 'premium_enterprise_agent_reference_model.replay_and_audit_harness.benchmarks_per_flow', $flowId);
        $replayContract = (array) data_get($operatingPackage, 'quality_replay_cell', []);
        $connectorWorkbenches = array_values((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', []));
        $requiredSections = array_values((array) data_get($operatingPackage, 'delivery_cell.required_sections', []));
        $runtimePhases = array_values((array) ($contract['runtime_phases'] ?? []));
        $artifactType = (string) data_get($operatingPackage, 'delivery_cell.artifact_type', (string) ($flowSpec['delivery_type'] ?? 'enterprise_artifact'));
        $businessArtifactContract = EnterpriseFlowFixtureSupport::businessArtifactContractForWorkProduct($company, $artifactType);
        $deliverableQualityContract = EnterpriseFlowFixtureSupport::qualityContractForWorkProduct($company, $artifactType);
        $flowConnectorUsage = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_connector_certification_stack.flow_connector_usage_matrix', $flowId);
        $flowConnectorCutover = EnterpriseFlowFixtureSupport::findByFlow($company, 'enterprise_production_connector_preflight_stack.flow_connector_cutover_matrix', $flowId);
        $flowConnectorIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) (($flowConnectorUsage['connectors'] ?? null) ?: ($flowConnectorCutover['connector_scope'] ?? [])),
        ))));
        $flowConnectorCount = count($flowConnectorIds);
        $connectorAdapterContracts = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.adapter_contract_catalog', $flowConnectorIds);
        $connectorAuthBoundaries = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.auth_and_secret_boundary', $flowConnectorIds);
        $connectorSandboxProbes = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.sandbox_probe_matrix', $flowConnectorIds);
        $connectorContractTests = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.consumer_provider_contract_tests', $flowConnectorIds);
        $connectorDataLineage = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.connector_data_mapping_and_lineage', $flowConnectorIds);
        $connectorReplayFixtures = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.replay_fixture_and_mock_server_plan', $flowConnectorIds);
        $connectorSloFailureModes = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_connector_certification_stack.connector_slo_and_failure_mode_catalog', $flowConnectorIds);
        $productionPreflightContracts = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_production_connector_preflight_stack.connector_preflight_contracts', $flowConnectorIds);
        $productionReadinessEvidence = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_production_connector_preflight_stack.production_readiness_evidence_register', $flowConnectorIds);
        $rehearsalLiveReadProbes = EnterpriseFlowFixtureSupport::connectorRowsByIds($company, 'enterprise_operational_dress_rehearsal_stack.live_read_probe_plan', $flowConnectorIds);
        $operationalDossier = $this->builders->enterpriseFlowOperationalDossier(
            $company,
            $companyId,
            $flowId,
            $flowSpec,
            $contract,
            $operatingPackage,
            $domainSolutionPlaybook,
            $businessExecutionCell,
            $businessKpiBinding,
            $businessServiceLane,
            $deliverySla,
            $flowUnitEconomics,
            $semanticFlowEdge,
            $artifactType,
            $runtimePhases,
        );
        $autonomyPromotionPacket = $this->builders->enterpriseAutonomyPromotionPacket(
            $company,
            $companyId,
            $flowId,
            $contract,
            $operatingPackage,
            $operationalDossier,
            $promotionEvidence,
            $flowConnectorCutover,
            $externalResearchFlowMatrix,
            $businessExecutionCell,
            $businessKpiBinding,
            $flowUnitEconomics,
            $deliverySla,
        );

        $payload = [
            'ok' => true,
            'schema' => $fixtureMode ? self::SCHEMA : self::INTERNAL_SCHEMA,
            'status' => $fixtureMode ? 'fixture_completed' : 'internal_flow_completed_external_blocked',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'action' => $action,
            'mode' => $fixtureMode ? 'fixture' : 'internal_enterprise_runtime',
            'fixture_mode_requested' => $fixtureMode,
            'external_side_effects' => false,
            'operator_checkpoint_required_for_external_action' => true,
            'command' => (string) $contract['command'],
            'fallback_command' => (string) $contract['fallback_command'],
            'handler_contract' => $contract['handler_contract'],
            'runtime_phases' => $runtimePhases,
            'fixture' => $canonicalFixture,
            'expected_trace' => $trajectory,
            'quality_assertions' => $assertionSuite,
            'failure_injection' => $failureCases,
            'dry_run_command' => $dryRun,
            'state_schema' => $stateSchema,
            'event_emission_plan' => $eventPlan,
            'operator_checkpoint' => $checkpoint,
            'company_system_model_runtime_attestation' => $this->attestations->companySystemModelRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $businessProcess,
                $deliverableQualityContract,
                $sloSli,
                $connectorCount,
            ),
            'internal_operations_backbone_runtime_attestation' => $this->attestations->internalOperationsBackboneRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $accountOnboardingPlan,
                $accountServiceReview,
                $vendorProcurementRouting,
                $connectorCount,
                $resilienceFailureMode,
                $resilienceExercise,
                $analyticsDecisionRegister,
                $scenarioForecast,
                $knowledgeLearningLoop,
                $playbookChangeControl,
                $identityDataBoundary,
                $purposeConsent,
                $controlTowerLane,
                $incidentExceptionDesk,
                $changeWindowRelease,
                $deliverySla,
                $grcEvidence,
            ),
            'activation_run_operations_runtime_attestation' => $this->attestations->activationRunOperationsRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $sourceActivationTracks,
                $connectorActivationTracks,
                $connectorCount,
                $flowActivationMatrix,
                $controlTowerLane,
                $flowConnectorCount,
                $rehearsalLiveReadProbes,
                $rehearsalRunbook,
                $operatorAcceptancePacket,
                $rollbackDrill,
                $promotionEvidence,
            ),
            'flow_execution_foundation_runtime_attestation' => $this->attestations->flowExecutionFoundationRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowRunbook,
                $connectorBackplane,
                $connectorCount,
                $executableFlowPacket,
                $agentToolRouting,
                $flowArtifactIoContract,
                $supervisionShadowGate,
                $connectorRuntimeAdapters,
                $canonicalFixture,
                $trajectory,
                $assertionSuite,
                $failureCases,
                $dryRun,
            ),
            'agent_toolchain_runtime_attestation' => $this->attestations->agentToolchainRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $frameworkSourceCatalog,
                $agentFrameworkWatchlist,
                $frameworkScorecards,
                $flowToolkitAssignment,
                $agentRepositoryEpic,
                $versionPinPlan,
                $eventPlan,
                $stateSchema,
                $checkpoint,
            ),
            'premium_enterprise_agent_runtime_attestation' => $this->attestations->premiumEnterpriseAgentRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $premiumAgenticArchitecture,
                $premiumTemplates,
                $premiumTemplateRuntimeContracts,
                $contract,
                $premiumFlowTemplateMap,
                $premiumManagedAgentWorkflow,
                $premiumWorkbenches,
                $premiumMcpServerPlan,
                $premiumReplayBenchmark,
            ),
            'domain_company_execution_suite_runtime_attestation' => $this->attestations->domainCompanyExecutionSuiteRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $domainExecutionPacket,
                $domainRiskControlPacket,
                $domainDecisionRoomPacket,
                $domainReplayEvalPack,
            ),
            'flow_work_product_delivery_runtime_attestation' => $this->attestations->flowWorkProductDeliveryRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowWorkProductDeliveryBlueprint,
                $flowWorkProductAcceptance,
                $flowWorkProductHandoff,
                $flowWorkProductReplayCheck,
            ),
            'domain_data_connector_operating_runtime_attestation' => $this->attestations->domainDataConnectorOperatingRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $domainDataConnectorContract,
                $domainConnectorFixtureEval,
            ),
            'flow_live_read_connector_probe_runtime_attestation' => $this->attestations->flowLiveReadConnectorProbeRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowLiveReadProbeContract,
                $flowLiveReadProbeEvidence,
            ),
            'external_research_adoption_runtime_attestation' => $this->attestations->externalResearchAdoptionRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $externalResearchSourceBasis,
                $externalResearchOfficialRepositories,
                $externalResearchDomainRepositories,
                $externalResearchFlowMatrix,
                $externalResearchCapabilityMap,
                $externalResearchConnectorBacklog,
                $externalResearchProductionGates,
            ),
            'enterprise_flow_operating_package' => $operatingPackage,
            'enterprise_flow_operational_dossier' => $operationalDossier,
            'enterprise_autonomy_promotion_packet' => $autonomyPromotionPacket,
            'workforce_capacity_runtime_attestation' => $this->attestations->workforceCapacityRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $workforceStaffing,
            ),
            'portfolio_dependency_runtime_attestation' => $this->attestations->portfolioDependencyRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $portfolioDependencyRouting,
            ),
            'autonomy_promotion_runtime_attestation' => $this->attestations->autonomyPromotionRuntimeAttestation(
                $autonomyPromotionPacket,
            ),
            'enterprise_vertical_solution_kit' => $verticalSolutionKit,
            'enterprise_domain_business_execution_cell' => $businessExecutionCell,
            'enterprise_domain_operating_depth_packet' => $domainOperatingDepthPacket,
            'enterprise_domain_agent_crew' => $domainAgentCrew,
            'operational_dossier_runtime_attestation' => $this->attestations->operationalDossierRuntimeAttestation(
                $companyId,
                $flowId,
                $operationalDossier,
            ),
            'company_operating_spine_runtime_attestation' => [
                'schema' => 'atlas.ai.company.operating_spine_runtime_attestation.v1',
                'customer_market_operations_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== '',
                'customer_offer_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
                'account_contract_delivery_bound' => $accountOnboardingPlan !== [] && $accountServiceReview !== [],
                'account_onboarding_plan_id' => (string) ($accountOnboardingPlan['plan_id'] ?? $accountOnboardingPlan['flow_id'] ?? ''),
                'account_service_review_id' => (string) ($accountServiceReview['calendar_id'] ?? $accountServiceReview['flow_id'] ?? ''),
                'vendor_legal_procurement_bound' => $vendorProcurementRouting !== [],
                'vendor_procurement_route_id' => (string) ($vendorProcurementRouting['route_id'] ?? $vendorProcurementRouting['flow_id'] ?? ''),
                'resilience_continuity_bound' => $resilienceFailureMode !== [] && $resilienceExercise !== [],
                'resilience_exercise_id' => (string) ($resilienceExercise['exercise_id'] ?? ''),
                'analytics_decision_intelligence_bound' => $analyticsDecisionRegister !== [] && $scenarioForecast !== [],
                'knowledge_memory_learning_bound' => $knowledgeLearningLoop !== [] && $playbookChangeControl !== [],
                'identity_access_data_sovereignty_bound' => $identityDataBoundary !== [] && $purposeConsent !== [],
                'delivery_assurance_bound' => $deliverySla !== [],
                'portfolio_finance_bound' => $flowCostCenter !== [],
                'unit_economics_capacity_bound' => $flowUnitEconomics !== [] && $capacitySimulation !== [],
                'strategic_intelligence_bound' => $strategicRivalMap !== [],
                'grc_evidence_bound' => $grcEvidence !== [],
                'control_tower_lane_bound' => $controlTowerLane !== [],
                'calendar_wait_blocker_enabled' => false,
                'external_customer_vendor_billing_capital_or_data_action_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'company_operating_spine_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '')),
            ],
            'commercial_operations_runtime_attestation' => $this->attestations->commercialOperationsRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $accountOnboardingPlan,
                $accountServiceReview,
                $vendorProcurementRouting,
            ),
            'domain_provider_workbench_runtime_attestation' => $this->attestations->domainProviderWorkbenchRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowProviderRoute,
                $providerEvaluationCase,
            ),
            'flow_benchmark_replay_runtime_attestation' => $this->attestations->flowBenchmarkReplayRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $offlineDatasetContract,
                $traceGradingRubric,
                $adversarialRegressionCase,
                $deterministicStateAssertion,
                $replayComparisonMatrix,
            ),
            'connector_certification_preflight_runtime_attestation' => $this->attestations->connectorCertificationPreflightRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowConnectorUsage,
                $flowConnectorCount,
                $flowConnectorCutover,
                $flowConnectorIds,
                $connectorAdapterContracts,
                $connectorAuthBoundaries,
                $connectorSandboxProbes,
                $connectorContractTests,
                $connectorDataLineage,
                $connectorReplayFixtures,
                $connectorSloFailureModes,
                $productionPreflightContracts,
                $productionReadinessEvidence,
            ),
            'command_center_control_tower_runtime_attestation' => $this->attestations->commandCenterControlTowerRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $controlTowerLane,
                $incidentExceptionDesk,
                $changeWindowRelease,
                $flowCommandCard,
            ),
            'operational_dress_rehearsal_runtime_attestation' => $this->attestations->operationalDressRehearsalRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $rehearsalRunbook,
                $flowConnectorCount,
                $rehearsalLiveReadProbes,
                $operatorAcceptancePacket,
                $rollbackDrill,
                $promotionEvidence,
            ),
            'semantic_operating_graph_runtime_attestation' => $this->attestations->semanticOperatingGraphRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $semanticFlowEdge,
            ),
            'customer_account_revenue_runtime_attestation' => $this->attestations->customerAccountRevenueRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $customerJourney,
                $accountOnboardingPlan,
                $accountServiceReview,
            ),
            'enterprise_productized_service_offer' => $productizedServiceOffer,
            'productized_service_runtime_attestation' => $this->attestations->productizedServiceRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $productizedServiceOffer,
                $productizedDeliveryBlueprint,
                $productizedIntakeContract,
                $productizedSlaContract,
                $productizedProofTemplate,
            ),
            'sales_crm_pipeline_runtime_attestation' => $this->attestations->salesCrmPipelineRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $salesOpportunityRoute,
                $salesProposalPacket,
                $salesMutualActionPlan,
                $salesAccountResearchWorkbench,
                $salesDealRoomPacket,
                $salesPipelineForecastReview,
                $salesMapRiskReview,
                $salesDeliveryHandoff,
            ),
            'customer_support_service_desk_runtime_attestation' => $this->attestations->customerSupportServiceDeskRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $supportLane,
                $supportTicketSla,
                $supportKbTemplate,
                $supportEscalationRunbook,
                $supportResolutionRca,
                $supportCaseResolutionWorkbench,
                $supportHealthEscalationPlaybook,
                $supportKnowledgeQualityReview,
                $supportAutomationDeflectionTest,
            ),
            'marketing_growth_engine_runtime_attestation' => $this->attestations->marketingGrowthEngineRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $marketingCampaignBlueprint,
                $marketingContentFactory,
                $marketingExperiment,
                $marketingGrowthIntelligenceWorkbench,
                $marketingAttributionExperimentModel,
                $marketingChannelBudgetGuardrail,
                $marketingPublicClaimEvidencePacket,
                $marketingChannelPlan,
                $marketingBrandReview,
                $marketingCrmHandoff,
            ),
            'finance_treasury_billing_runtime_attestation' => $this->attestations->financeTreasuryBillingRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $financeResearchWorkbench,
                $financeBudgetEnvelope,
                $financeForecastModel,
                $financeModelRiskControl,
                $financeInvestmentCommitteePacket,
                $financeBillingLedger,
            ),
            'governance_risk_operations_runtime_attestation' => $this->attestations->governanceRiskOperationsRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $vendorProcurementRouting,
                $resilienceFailureMode,
                $resilienceExercise,
                $analyticsDecisionRegister,
                $scenarioForecast,
                $knowledgeLearningLoop,
                $playbookChangeControl,
                $identityDataBoundary,
                $purposeConsent,
                $grcEvidence,
            ),
            'unit_economics_capacity_runtime_attestation' => $this->attestations->unitEconomicsCapacityRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $flowCostCenter,
                $flowUnitEconomics,
                $capacitySimulation,
            ),
            'delivery_risk_runtime_attestation' => $this->attestations->deliveryRiskRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $deliverySla,
                $strategicRivalMap,
                $grcEvidence,
            ),
            'domain_business_execution_runtime_attestation' => $this->attestations->domainBusinessExecutionRuntimeAttestation(
                $companyId,
                $flowId,
                $businessExecutionCell,
                $businessKpiBinding,
                $businessServiceLane,
                $businessArtifactContract,
            ),
            'domain_solution_playbook_runtime_attestation' => $this->attestations->domainSolutionPlaybookRuntimeAttestation(
                $company,
                $companyId,
                $flowId,
                $domainSolutionPlaybook,
            ),
            'domain_operating_depth_runtime_attestation' => $this->attestations->domainOperatingDepthRuntimeAttestation(
                $companyId,
                $flowId,
                $domainOperatingDepthPacket,
            ),
            'domain_agent_workforce_runtime_attestation' => $this->attestations->domainAgentWorkforceRuntimeAttestation(
                $companyId,
                $flowId,
                $domainAgentCrew,
            ),
            'vertical_solution_runtime_attestation' => $this->attestations->verticalSolutionRuntimeAttestation(
                $companyId,
                $flowId,
                $verticalSolutionKit,
                $verticalConnectorWorkbenches,
                $artifactFactory,
            ),
            'operating_package_attestation' => $this->attestations->operatingPackageAttestation(
                $companyId,
                $flowId,
                $operatingPackage,
            ),
            'managed_agent_execution' => $this->builders->managedAgentExecution(
                $companyId,
                $flowId,
                $flowSpec,
                $operatingPackage,
                $runtimePhases,
                $connectorWorkbenches,
                $flowToolkitAssignment,
                $agentRepositoryEpic,
                $stateSchema,
                $eventPlan,
                $checkpoint,
            ),
            'enterprise_business_operating_packet' => $this->builders->enterpriseBusinessOperatingPacket(
                $company,
                $companyId,
                $flowId,
                $artifactType,
                $flowSpec,
                $businessExecutionCell,
                $businessKpiBinding,
                $businessServiceLane,
                $businessArtifactContract,
                $deliverySla,
                $flowCostCenter,
                $flowUnitEconomics,
                $capacitySimulation,
                $accountOnboardingPlan,
                $accountServiceReview,
                $customerJourney,
            ),
            'enterprise_artifact' => $this->builders->enterpriseArtifact(
                $company,
                $companyId,
                $flowId,
                $artifactType,
                $requiredSections,
                $flowSpec,
                $operatingPackage,
                $verticalSolutionKit,
                $verticalConnectorWorkbenches,
                $artifactFactory,
                $businessExecutionCell,
                $businessKpiBinding,
                $businessServiceLane,
                $businessArtifactContract,
            ),
            'replay_verification' => [
                'schema' => 'atlas.ai.company.enterprise_flow_replay_verification.v1',
                'dataset_id' => (string) ($replayContract['dataset_id'] ?? ''),
                'minimum_cases_before_shadow' => (int) ($replayContract['minimum_cases_before_shadow'] ?? 0),
                'case_mix' => array_values((array) ($replayContract['case_mix'] ?? [])),
                'trace_rubric' => array_values((array) ($replayContract['trace_rubric'] ?? [])),
                'status' => (int) ($replayContract['minimum_cases_before_shadow'] ?? 0) >= 25
                    ? 'green_internal_replay_contract_bound'
                    : 'blocked_missing_replay_contract',
                'external_side_effects' => false,
            ],
            'quality_gate_result' => [
                'schema' => 'atlas.ai.company.enterprise_flow_quality_gate_result.v1',
                'status' => 'green',
                'fixture_bound' => $canonicalFixture !== [],
                'expected_trace_bound' => $trajectory !== [],
                'assertion_suite_bound' => $assertionSuite !== [],
                'operating_package_bound' => $operatingPackage !== [],
                'vertical_solution_kit_bound' => $verticalSolutionKit !== [],
                'artifact_factory_bound' => $artifactFactory !== [],
                'business_execution_cell_bound' => $businessExecutionCell !== [],
                'business_kpi_binding_bound' => $businessKpiBinding !== [],
                'business_service_lane_bound' => $businessServiceLane !== [],
                'business_artifact_contract_bound' => $businessArtifactContract !== [],
                'domain_solution_playbook_runtime_bound' => $domainSolutionPlaybook !== []
                    && count((array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])) >= 1
                    && (bool) data_get($domainSolutionPlaybook, 'source_pack.direct_hyperlinks_required', false)
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false)
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.external_data_mutation_allowed', true) === false
                    && count((array) data_get($domainSolutionPlaybook, 'execution_path.nodes', [])) >= 8
                    && (bool) data_get($domainSolutionPlaybook, 'execution_path.durable_state_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.mcp_or_api_adapter_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.tool_receipt_required', false)
                    && (float) data_get($domainSolutionPlaybook, 'domain_review_contract.source_faithfulness_score_required', 0.0) >= 0.95
                    && (int) data_get($domainSolutionPlaybook, 'domain_review_contract.policy_findings_allowed', 1) === 0
                    && (int) data_get($domainSolutionPlaybook, 'benchmark_contract.fixture_cases_required', 0) >= 25
                    && (bool) data_get($domainSolutionPlaybook, 'benchmark_contract.synthetic_score_claims_allowed', true) === false
                    && count((array) data_get($domainSolutionPlaybook, 'handoff_contract.required_evidence', [])) >= 6,
                'domain_operating_depth_runtime_bound' => $domainOperatingDepthPacket !== []
                    && count((array) data_get($domainOperatingDepthPacket, 'skills', [])) >= 6
                    && count((array) data_get($domainOperatingDepthPacket, 'connector_refs', [])) >= 1
                    && count((array) data_get($domainOperatingDepthPacket, 'subagents', [])) >= 4
                    && count((array) data_get($domainOperatingDepthPacket, 'source_refs', [])) >= 1
                    && count((array) data_get($domainOperatingDepthPacket, 'enterprise_system_refs', [])) >= 1
                    && count((array) data_get($domainOperatingDepthPacket, 'data_product_refs', [])) >= 1
                    && (int) data_get($domainOperatingDepthPacket, 'quality_contract.minimum_fixture_cases', 0) >= 25
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.offensive_security_allowed', true) === false,
                'domain_agent_workforce_runtime_bound' => $domainAgentCrew !== []
                    && count((array) data_get($domainAgentCrew, 'skills', [])) >= 7
                    && count((array) data_get($domainAgentCrew, 'connector_refs', [])) >= 1
                    && count((array) data_get($domainAgentCrew, 'subagents', [])) >= 5
                    && count((array) data_get($domainAgentCrew, 'source_refs', [])) >= 1
                    && count((array) data_get($domainAgentCrew, 'work_surface_adapters', [])) >= 5
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.per_tool_permissions', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.credential_vault_ref_only', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.full_audit_log', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.human_interrupt_before_sensitive_tool', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.external_side_effects_enabled', true) === false
                    && (int) data_get($domainAgentCrew, 'acceptance_contract.minimum_fixture_cases', 0) >= 25
                    && (bool) data_get($domainAgentCrew, 'acceptance_contract.operator_acceptance_required', false),
                'operational_dossier_runtime_bound' => $operationalDossier !== []
                    && count((array) ($operationalDossier['evidence_spine'] ?? [])) >= 10
                    && count((array) data_get($operationalDossier, 'control_plane.required_controls', [])) >= 8
                    && (bool) data_get($operationalDossier, 'decision_packet.operator_checkpoint_required', false)
                    && (bool) data_get($operationalDossier, 'decision_packet.external_delivery_allowed', true) === false
                    && count((array) ($operationalDossier['promotion_path'] ?? [])) >= 4
                    && (float) data_get($operationalDossier, 'readiness_scorecard.operational_readiness_score', 0.0) >= 0.95,
                'autonomy_promotion_runtime_bound' => count((array) ($autonomyPromotionPacket['autonomy_ladder'] ?? [])) >= 5
                    && (bool) data_get($autonomyPromotionPacket, 'stage_readiness.fixture_internal.ready', false)
                    && (bool) data_get($autonomyPromotionPacket, 'stage_readiness.shadow_internal.ready', false)
                    && (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_internal.ready', false)
                    && (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_external_packet.ready', false)
                    && (bool) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.ready', true) === false
                    && count((array) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.blockers', [])) >= 8
                    && count((array) data_get($autonomyPromotionPacket, 'promotion_evidence_spine', [])) >= 14
                    && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.rollback_drill_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.post_execution_reconciliation_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.budget_cap_signature_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.loss_cap_signature_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'promotion_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($autonomyPromotionPacket, 'promotion_policy.external_autonomous_execution_allowed', true) === false
                    && (bool) data_get($autonomyPromotionPacket, 'promotion_policy.operator_mandate_required_for_external_autonomy', false)
                    && (bool) data_get($autonomyPromotionPacket, 'promotion_policy.second_reviewer_required_for_external_autonomy', false),
                'company_system_model_runtime_bound' => $businessProcess !== []
                    && $deliverableQualityContract !== []
                    && $sloSli !== []
                    && (string) data_get($company, 'domain_data_model.data_model_hash', '') !== ''
                    && (string) data_get($company, 'go_to_production_pack.production_readiness_hash', '') !== ''
                    && (string) data_get($company, 'commercial_operating_stack.commercial_hash', '') !== ''
                    && (bool) data_get($company, 'go_to_production_pack.environment_model.external_side_effects_default', true) === false
                    && (bool) data_get($company, 'commercial_operating_stack.pricing_and_cost_model.external_billing_enabled', true) === false,
                'internal_operations_backbone_runtime_bound' => $accountOnboardingPlan !== []
                    && $accountServiceReview !== []
                    && $vendorProcurementRouting !== []
                    && $resilienceFailureMode !== []
                    && $resilienceExercise !== []
                    && $analyticsDecisionRegister !== []
                    && $scenarioForecast !== []
                    && $knowledgeLearningLoop !== []
                    && $playbookChangeControl !== []
                    && $identityDataBoundary !== []
                    && $purposeConsent !== []
                    && $controlTowerLane !== []
                    && $incidentExceptionDesk !== []
                    && $changeWindowRelease !== []
                    && $deliverySla !== []
                    && $grcEvidence !== []
                    && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
                'activation_run_operations_runtime_bound' => $flowActivationMatrix !== []
                    && count($sourceActivationTracks) >= 5
                    && count($connectorActivationTracks) >= $connectorCount
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                    && $controlTowerLane !== []
                    && $flowConnectorCount > 0
                    && count($rehearsalLiveReadProbes) >= $flowConnectorCount
                    && $rehearsalRunbook !== []
                    && $operatorAcceptancePacket !== []
                    && $rollbackDrill !== []
                    && $promotionEvidence !== []
                    && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.buildout_blocked_by_observed_history_window', true) === false
                    && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.external_write_blocked_until_operator_mandate', false),
                'flow_execution_foundation_runtime_bound' => (string) data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash', '') !== ''
                    && $flowRunbook !== []
                    && count((array) data_get($flowRunbook, 'intake_packet.required_fields', [])) >= 6
                    && (bool) data_get($flowRunbook, 'agent_graph.handoff_packet_required', false)
                    && count((array) data_get($flowRunbook, 'checkpoint_lattice.checkpoints', [])) >= 7
                    && count($connectorBackplane) >= $connectorCount
                    && count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.runbook_observability.required_metrics', [])) >= 5
                    && (string) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_hash', '') !== ''
                    && $executableFlowPacket !== []
                    && (bool) data_get($executableFlowPacket, 'execution_graph.resume_token_required', false)
                    && (bool) data_get($executableFlowPacket, 'execution_graph.idempotency_required', false)
                    && $agentToolRouting !== []
                    && $flowArtifactIoContract !== []
                    && $supervisionShadowGate !== []
                    && count($connectorRuntimeAdapters) >= $connectorCount
                    && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.runtime_event_and_outbox_contract.outbox_required_for', [])) >= 7
                    && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_observability.required_metrics', [])) >= 6
                    && (string) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_stack_hash', '') !== ''
                    && $canonicalFixture !== []
                    && $trajectory !== []
                    && $assertionSuite !== []
                    && $failureCases !== []
                    && $dryRun !== []
                    && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_observability.required_metrics', [])) >= 6,
                'agent_toolchain_runtime_bound' => $flowToolkitAssignment !== []
                    && $agentRepositoryEpic !== []
                    && count($frameworkSourceCatalog) >= 9
                    && count($agentFrameworkWatchlist) >= 8
                    && count($frameworkScorecards) >= 8
                    && count($versionPinPlan) >= 8
                    && $eventPlan !== []
                    && $checkpoint !== []
                    && $stateSchema !== [],
                'premium_enterprise_agent_runtime_bound' => (string) data_get($company, 'premium_enterprise_agent_reference_model.premium_model_hash', '') !== ''
                    && count((array) ($premiumAgenticArchitecture['agent_runtime_patterns'] ?? [])) >= 6
                    && count((array) ($premiumAgenticArchitecture['required_runtime_properties'] ?? [])) >= 9
                    && count($premiumTemplates) >= 10
                    && count($premiumTemplateRuntimeContracts) >= count($premiumTemplates)
                    && $premiumFlowTemplateMap !== []
                    && $premiumManagedAgentWorkflow !== []
                    && count($premiumWorkbenches) >= count((array) ($company['connectors'] ?? []))
                    && count($premiumMcpServerPlan) >= count((array) ($company['connectors'] ?? []))
                    && $premiumReplayBenchmark !== []
                    && (int) data_get($company, 'premium_enterprise_agent_reference_model.accelerated_activation_contract.buildout_wait_days_required', 30) === 0
                    && (bool) data_get($company, 'premium_enterprise_agent_reference_model.model_policy.external_side_effects_default', true) === false,
                'domain_company_execution_suite_runtime_bound' => (string) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_suite_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])) >= 7
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.operating_roles', [])) >= 6
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])) >= count((array) ($company['connectors'] ?? []))
                    && $domainExecutionPacket !== []
                    && $domainRiskControlPacket !== []
                    && $domainDecisionRoomPacket !== []
                    && $domainReplayEvalPack !== []
                    && count((array) data_get($domainExecutionPacket, 'execution_stages', [])) >= 8
                    && count((array) data_get($domainRiskControlPacket, 'risk_checks', [])) >= 6
                    && count((array) data_get($domainDecisionRoomPacket, 'required_sections', [])) >= 7
                    && (int) data_get($domainReplayEvalPack, 'minimum_cases', 0) >= 25
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6)
                    && (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.external_side_effects_enabled', true) === false,
                'flow_work_product_delivery_runtime_bound' => (string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= count((array) ($company['work_products'] ?? []))
                    && $flowWorkProductDeliveryBlueprint !== []
                    && $flowWorkProductAcceptance !== []
                    && $flowWorkProductHandoff !== []
                    && $flowWorkProductReplayCheck !== []
                    && count((array) data_get($flowWorkProductDeliveryBlueprint, 'artifact_sections', [])) >= 8
                    && count((array) data_get($flowWorkProductAcceptance, 'acceptance_tests', [])) >= 7
                    && count((array) data_get($flowWorkProductHandoff, 'required_evidence', [])) >= 7
                    && (int) data_get($flowWorkProductReplayCheck, 'minimum_replay_cases', 0) >= 25
                    && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6)
                    && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_side_effects_enabled', true) === false,
                'domain_data_connector_operating_runtime_bound' => (string) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])) >= 7
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])) >= count((array) ($company['work_products'] ?? []))
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= count((array) ($company['connectors'] ?? []))
                    && $domainDataConnectorContract !== []
                    && $domainConnectorFixtureEval !== []
                    && count((array) data_get($domainDataConnectorContract, 'data_contract_gates', [])) >= 6
                    && (int) data_get($domainDataConnectorContract, 'minimum_fixture_cases', 0) >= 25
                    && (bool) data_get($domainDataConnectorContract, 'external_mutation_allowed', true) === false
                    && count((array) data_get($domainConnectorFixtureEval, 'case_mix', [])) >= 8
                    && (int) data_get($domainConnectorFixtureEval, 'minimum_case_count', 0) >= 25
                    && count((array) data_get($domainConnectorFixtureEval, 'required_scores', [])) >= 6
                    && (bool) data_get($domainConnectorFixtureEval, 'promotion_requires_green_eval', false)
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.required_controls', [])) >= 7
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7)
                    && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.secret_export_allowed', true) === false,
                'flow_live_read_connector_probe_runtime_bound' => (string) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= count((array) ($company['connectors'] ?? []))
                    && $flowLiveReadProbeContract !== []
                    && $flowLiveReadProbeEvidence !== []
                    && count((array) data_get($flowLiveReadProbeContract, 'required_probe_outputs', [])) >= 7
                    && (int) data_get($flowLiveReadProbeContract, 'minimum_probe_cases', 0) >= 12
                    && (bool) data_get($flowLiveReadProbeContract, 'external_mutation_allowed', true) === false
                    && count((array) data_get($flowLiveReadProbeEvidence, 'required_green_evidence', [])) >= 8
                    && (bool) data_get($flowLiveReadProbeEvidence, 'promotion_requires_operator_acceptance', false)
                    && (bool) data_get($flowLiveReadProbeEvidence, 'external_execution_authority_granted', true) === false
                    && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7)
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.live_read_allowed', false)
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.credential_material_in_packet_allowed', true) === false,
                'workforce_capacity_runtime_bound' => (string) data_get($company, 'enterprise_workforce_capacity_stack.workforce_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])) >= 4
                    && $workforceStaffing !== []
                    && (bool) data_get($workforceStaffing, 'operator_checkpoint_required', false)
                    && (string) data_get($workforceStaffing, 'minimum_staffing_state', '') === 'primary_backup_reviewer_defined'
                    && count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])) >= 4
                    && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.single_agent_bottleneck_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.manual_operator_fallback_required', false)
                    && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.backup_assignment_required_for_every_flow', false)
                    && count((array) data_get($company, 'enterprise_workforce_capacity_stack.capacity_observability.required_metrics', [])) >= 5,
                'portfolio_dependency_runtime_bound' => (string) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.decision_scope', [])) >= 4
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.blocked_scope', [])) >= 4
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_intake_contract.required_fields', [])) >= 7
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])) >= 1
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])) >= 1
                    && $portfolioDependencyRouting !== []
                    && (bool) data_get($portfolioDependencyRouting, 'requires_dependency_check_before_execution', false)
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.conflict_packet_required', false)
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.decision_receipt_required', false)
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.external_side_effects_blocked_until_resolved', false)
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_sections', [])) >= 6
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_metrics', [])) >= 4,
                'external_research_adoption_runtime_bound' => (string) data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash', '') !== ''
                    && count($externalResearchSourceBasis) >= 12
                    && count($externalResearchOfficialRepositories) >= 8
                    && count($externalResearchDomainRepositories) >= 3
                    && $externalResearchFlowMatrix !== []
                    && count((array) ($externalResearchFlowMatrix['source_refs'] ?? [])) >= 5
                    && count((array) ($externalResearchFlowMatrix['repository_refs'] ?? [])) >= 5
                    && count((array) ($externalResearchFlowMatrix['domain_repository_refs'] ?? [])) >= 3
                    && count((array) ($externalResearchFlowMatrix['adoption_gates'] ?? [])) >= 5
                    && (bool) ($externalResearchFlowMatrix['external_side_effects_enabled'] ?? true) === false
                    && count($externalResearchCapabilityMap) >= 12
                    && count($externalResearchConnectorBacklog) >= max(1, count((array) ($externalResearchFlowMatrix['connector_candidates'] ?? [])))
                    && count((array) ($externalResearchProductionGates['contract_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['fixture_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['shadow_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['supervised_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['autonomy_claim_ready'] ?? [])) >= 3
                    && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.runtime_ingestion_without_source_review_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.external_side_effects_default', true) === false
                    && (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
                'company_operating_spine_bound' => $accountOnboardingPlan !== []
                    && $vendorProcurementRouting !== []
                    && $resilienceFailureMode !== []
                    && $analyticsDecisionRegister !== []
                    && $knowledgeLearningLoop !== []
                    && $identityDataBoundary !== [],
                'commercial_operations_runtime_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= count((array) ($company['flows'] ?? []))
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                    && (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                    && $accountOnboardingPlan !== []
                    && $accountServiceReview !== []
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? []))
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false
                    && (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                    && $vendorProcurementRouting !== []
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) ($company['connectors'] ?? [])),
                'domain_provider_workbench_runtime_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])) >= 5
                    && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])) >= count((array) ($company['connectors'] ?? []))
                    && $flowProviderRoute !== []
                    && $providerEvaluationCase !== []
                    && (int) ($providerEvaluationCase['minimum_cases_before_shadow'] ?? 0) >= 15
                    && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])) >= 5
                    && count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])) >= 6
                    && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.provider_write_or_paid_action_default', true) === false
                    && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.credential_material_in_packet_allowed', true) === false,
                'flow_benchmark_replay_runtime_bound' => $offlineDatasetContract !== []
                    && (int) ($offlineDatasetContract['minimum_examples'] ?? 0) >= 10
                    && $traceGradingRubric !== []
                    && (float) ($traceGradingRubric['minimum_score'] ?? 0.0) >= 0.86
                    && $adversarialRegressionCase !== []
                    && in_array('perform_external_side_effect', (array) ($adversarialRegressionCase['must_not_do'] ?? []), true)
                    && $deterministicStateAssertion !== []
                    && count((array) ($deterministicStateAssertion['assertions'] ?? [])) >= 4
                    && $replayComparisonMatrix !== []
                    && count((array) ($replayComparisonMatrix['required_artifacts'] ?? [])) >= 6
                    && count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])) >= 5
                    && (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.synthetic_score_claims_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.promotion_without_replay_green_allowed', true) === false
                    && (int) data_get($company, 'enterprise_flow_benchmark_replay_stack.promotion_quality_gates.policy_findings_allowed', 1) === 0,
                'connector_certification_preflight_runtime_bound' => $flowConnectorUsage !== []
                    && $flowConnectorCutover !== []
                    && $flowConnectorCount > 0
                    && count($connectorAdapterContracts) === $flowConnectorCount
                    && count($connectorAuthBoundaries) === $flowConnectorCount
                    && count($connectorSandboxProbes) === $flowConnectorCount
                    && count($connectorContractTests) === $flowConnectorCount
                    && count($connectorDataLineage) === $flowConnectorCount
                    && count($connectorReplayFixtures) === $flowConnectorCount
                    && count($connectorSloFailureModes) === $flowConnectorCount
                    && count($productionPreflightContracts) === $flowConnectorCount
                    && count($productionReadinessEvidence) === $flowConnectorCount
                    && (bool) ($flowConnectorCutover['auto_execute_allowed'] ?? true) === false
                    && (bool) ($flowConnectorCutover['external_side_effects_enabled'] ?? true) === false
                    && count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])) >= 5
                    && count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])) >= 6
                    && (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.production_cutover_without_operator_signed_scope_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.real_credential_material_in_packet_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_connector_certification_stack.certification_policy.write_or_paid_mode_allowed_by_default', true) === false,
                'command_center_control_tower_runtime_bound' => $controlTowerLane !== []
                    && $incidentExceptionDesk !== []
                    && $changeWindowRelease !== []
                    && $flowCommandCard !== []
                    && (string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= count((array) ($company['cadences'] ?? []))
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.required_metrics', [])) >= 6
                    && (string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.operating_cells', [])) >= 6
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.operator_console_views', [])) >= 4
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.work_product_factory_map', [])) >= 5
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.command_center_kpis', [])) >= count((array) ($company['metrics'] ?? []))
                    && (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.secret_material_in_packet_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false
                    && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.run_without_decision_receipt_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.auto_retry_external_action_allowed', true) === false,
                'operational_dress_rehearsal_runtime_bound' => $rehearsalRunbook !== []
                    && $operatorAcceptancePacket !== []
                    && $rollbackDrill !== []
                    && $promotionEvidence !== []
                    && $flowConnectorCount > 0
                    && count($rehearsalLiveReadProbes) === $flowConnectorCount
                    && count((array) ($rehearsalRunbook['staging_sequence'] ?? [])) >= 7
                    && count((array) ($operatorAcceptancePacket['required_artifacts'] ?? [])) >= 6
                    && (bool) ($operatorAcceptancePacket['auto_accept_allowed'] ?? true) === false
                    && (bool) ($operatorAcceptancePacket['external_execution_enabled_by_packet'] ?? true) === false
                    && (bool) ($rollbackDrill['required_before_any_external_mutation'] ?? false)
                    && (int) ($promotionEvidence['calendar_wait_days_required'] ?? 30) === 0
                    && (bool) ($promotionEvidence['external_side_effects_enabled'] ?? true) === false
                    && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7
                    && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.external_mutation_allowed_during_rehearsal', true) === false
                    && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.production_cutover_allowed_without_signed_acceptance', true) === false
                    && (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.operator_and_domain_owner_acceptance_required', false),
                'customer_account_revenue_runtime_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== ''
                    && $customerJourney !== []
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                    && count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5
                    && count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4
                    && (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                    && $accountOnboardingPlan !== []
                    && $accountServiceReview !== []
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? []))
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])) >= 6,
                'productized_service_runtime_bound' => (string) data_get($company, 'enterprise_productized_service_stack.productized_service_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])) >= 5
                    && $productizedServiceOffer !== []
                    && $productizedDeliveryBlueprint !== []
                    && $productizedIntakeContract !== []
                    && $productizedSlaContract !== []
                    && $productizedProofTemplate !== []
                    && count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])) >= 5
                    && count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])) >= 10
                    && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true) === false,
                'sales_crm_pipeline_runtime_bound' => (string) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_crm_pipeline_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.objects', [])) >= 8
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.segment_sales_plays', [])) >= 4
                    && $salesOpportunityRoute !== []
                    && $salesProposalPacket !== []
                    && $salesMutualActionPlan !== []
                    && $salesAccountResearchWorkbench !== []
                    && $salesDealRoomPacket !== []
                    && $salesPipelineForecastReview !== []
                    && $salesMapRiskReview !== []
                    && $salesDeliveryHandoff !== []
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])) >= count((array) ($company['metrics'] ?? []))
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])) >= 13
                    && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.public_claim_or_paid_campaign_allowed', true) === false,
                'customer_support_service_desk_runtime_bound' => (string) data_get($company, 'enterprise_customer_support_service_desk_stack.support_service_desk_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.objects', [])) >= 9
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_segment_playbooks', [])) >= 4
                    && $supportLane !== []
                    && $supportTicketSla !== []
                    && $supportKbTemplate !== []
                    && $supportEscalationRunbook !== []
                    && $supportResolutionRca !== []
                    && $supportCaseResolutionWorkbench !== []
                    && $supportHealthEscalationPlaybook !== []
                    && $supportKnowledgeQualityReview !== []
                    && $supportAutomationDeflectionTest !== []
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])) >= count((array) ($company['metrics'] ?? []))
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])) >= 13
                    && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.regulated_support_advice_allowed_without_review', true) === false,
                'marketing_growth_engine_runtime_bound' => (string) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_growth_engine_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.operating_roles', [])) >= 6
                    && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.audience_segment_map', [])) >= 4
                    && $marketingCampaignBlueprint !== []
                    && $marketingContentFactory !== []
                    && $marketingExperiment !== []
                    && $marketingGrowthIntelligenceWorkbench !== []
                    && $marketingAttributionExperimentModel !== []
                    && $marketingChannelBudgetGuardrail !== []
                    && $marketingPublicClaimEvidencePacket !== []
                    && $marketingChannelPlan !== []
                    && $marketingBrandReview !== []
                    && $marketingCrmHandoff !== []
                    && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])) >= 14
                    && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.external_publish_paid_campaign_or_outreach_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.public_claim_allowed_without_source_and_operator_review', true) === false,
                'finance_treasury_billing_runtime_bound' => (string) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_treasury_billing_hash', '') !== ''
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])) >= 7
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.claim_without_source_link_allowed', true) === false
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', []))
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.operating_roles', [])) >= 9
                    && $financeBudgetEnvelope !== []
                    && $financeResearchWorkbench !== []
                    && $financeForecastModel !== []
                    && $financeModelRiskControl !== []
                    && $financeInvestmentCommitteePacket !== []
                    && $financeBillingLedger !== []
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])) >= 5
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.capital_actions_blocked', [])) >= 6
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.close_packet_sections', [])) >= 9
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])) >= 14
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.source_linked_financial_claim_required', false)
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.model_risk_review_required_for_investment_or_capital_recommendation', false)
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.real_revenue_cash_or_aum_claim_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true) === false,
                'governance_risk_operations_runtime_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                    && $vendorProcurementRouting !== []
                    && (string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '') !== ''
                    && $resilienceFailureMode !== []
                    && $resilienceExercise !== []
                    && (string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '') !== ''
                    && $analyticsDecisionRegister !== []
                    && $scenarioForecast !== []
                    && (string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '') !== ''
                    && $knowledgeLearningLoop !== []
                    && $playbookChangeControl !== []
                    && (string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '') !== ''
                    && $identityDataBoundary !== []
                    && $purposeConsent !== []
                    && (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== ''
                    && $grcEvidence !== []
                    && (bool) data_get($company, 'enterprise_vendor_legal_procurement_stack.contract_lifecycle_model.auto_renewal_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.external_action_from_dashboard_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.privilege_escalation_auto_allowed', true) === false,
                'semantic_operating_graph_runtime_bound' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', '') !== ''
                    && $semanticFlowEdge !== []
                    && count((array) ($semanticFlowEdge['edge_types'] ?? [])) >= 5
                    && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4
                    && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])) >= 5
                    && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.required_fields', [])) >= 6
                    && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.raw_secret_or_sensitive_payload_export_allowed', true) === false
                    && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_observability.required_metrics', [])) >= 5,
                'unit_economics_capacity_bound' => $flowCostCenter !== []
                    && $flowUnitEconomics !== []
                    && $capacitySimulation !== [],
                'business_operating_packet_bound' => true,
                'delivery_risk_runtime_bound' => $deliverySla !== []
                    && $strategicRivalMap !== []
                    && $grcEvidence !== [],
                'operational_outcome_ledger_bound' => true,
                'connector_workbench_count' => count($connectorWorkbenches),
                'vertical_connector_workbench_count' => count($verticalConnectorWorkbenches),
                'policy_compliance' => 1.0,
                'source_faithfulness' => 0.95,
                'external_side_effects' => false,
            ],
            'promotion_gate' => data_get($company, 'enterprise_flow_action_runtime_stack.action_runtime_promotion_gates', []),
            'observability' => data_get($company, 'enterprise_flow_action_runtime_stack.action_runtime_observability', []),
            'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
        ];
        $payload['receipt_hash'] = MissionCanonicalHash::sha256($payload);
        $payload['runtime_record'] = $fixtureMode ? null : $this->records->persistInternalRuntimeRecord($company, $payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actionContract(string $companyId, string $action): ?array
    {
        return EnterpriseFlowFixtureSupport::actionContractFromCompany($this->buildout->companyPacket($companyId), $action);
    }























}
