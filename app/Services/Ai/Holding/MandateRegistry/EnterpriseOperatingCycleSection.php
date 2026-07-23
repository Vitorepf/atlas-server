<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiOperatorApproval;
use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseOperatingCycleSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingCycleStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $depth = $this->hub->enterpriseCompletion->enterpriseVerticalOperationalDepthStatus($wantedCompany);
        $completion = $this->hub->enterpriseCompletion->enterpriseCompanyCompletionCertificationStatus($wantedCompany);
        $customerRevenue = $this->hub->flowActionRuntime->customerAccountRevenueRuntimeStatus($wantedCompany);
        $productizedService = $this->hub->flowActionRuntime->productizedServiceRuntimeStatus($wantedCompany);
        $salesCrm = $this->hub->flowActionRuntime->salesCrmPipelineRuntimeStatus($wantedCompany);
        $supportDesk = $this->hub->flowActionRuntime->customerSupportServiceDeskRuntimeStatus($wantedCompany);
        $marketingGrowth = $this->hub->flowActionRuntime->marketingGrowthEngineRuntimeStatus($wantedCompany);
        $financeTreasury = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $governanceRisk = $this->hub->flowActionRuntime->governanceRiskOperationsRuntimeStatus($wantedCompany);
        $unitEconomics = $this->hub->flowActionRuntime->unitEconomicsCapacityRuntimeStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $deliveryRisk = $this->hub->flowActionRuntime->deliveryRiskRuntimeStatus($wantedCompany);
        $outcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);

        $depthByCompany = $this->hub->companyRowsById($depth);
        $completionByCompany = $this->hub->companyRowsById($completion);
        $customerByCompany = $this->hub->companyRowsById($customerRevenue);
        $productByCompany = $this->hub->companyRowsById($productizedService);
        $salesByCompany = $this->hub->companyRowsById($salesCrm);
        $supportByCompany = $this->hub->companyRowsById($supportDesk);
        $marketingByCompany = $this->hub->companyRowsById($marketingGrowth);
        $financeByCompany = $this->hub->companyRowsById($financeTreasury);
        $governanceByCompany = $this->hub->companyRowsById($governanceRisk);
        $unitByCompany = $this->hub->companyRowsById($unitEconomics);
        $businessByCompany = $this->hub->companyRowsById($businessPacket);
        $deliveryByCompany = $this->hub->companyRowsById($deliveryRisk);
        $outcomeByCompany = $this->hub->companyRowsById($outcome);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            ));
            $flows = array_values(array_filter($flows, static fn (string $flow): bool => $flow !== ''));
            $expectedFlowCount = count($flows);
            $depthRow = (array) ($depthByCompany[$id] ?? []);

            $runtimeGates = [
                'vertical_operational_depth' => (int) ($depthRow['ready_gate_count'] ?? 0) === (int) ($depthRow['required_gate_count'] ?? -1),
                'company_completion_certification' => (bool) data_get($completionByCompany, $id.'.completion_certified', false),
                'customer_account_revenue_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($customerByCompany[$id] ?? []), 'completed_customer_account_revenue_flow_count', $expectedFlowCount),
                'productized_service_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($productByCompany[$id] ?? []), 'completed_productized_service_flow_count', $expectedFlowCount),
                'sales_crm_pipeline_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($salesByCompany[$id] ?? []), 'completed_sales_crm_pipeline_flow_count', $expectedFlowCount),
                'customer_support_service_desk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($supportByCompany[$id] ?? []), 'completed_customer_support_service_desk_flow_count', $expectedFlowCount),
                'marketing_growth_engine_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($marketingByCompany[$id] ?? []), 'completed_marketing_growth_engine_flow_count', $expectedFlowCount),
                'finance_treasury_billing_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($financeByCompany[$id] ?? []), 'completed_finance_treasury_billing_flow_count', $expectedFlowCount),
                'governance_risk_operations_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($governanceByCompany[$id] ?? []), 'completed_governance_risk_operations_flow_count', $expectedFlowCount),
                'unit_economics_capacity_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($unitByCompany[$id] ?? []), 'completed_unit_economics_capacity_flow_count', $expectedFlowCount),
                'business_operating_packet_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($businessByCompany[$id] ?? []), 'completed_business_operating_packet_flow_count', $expectedFlowCount),
                'delivery_risk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($deliveryByCompany[$id] ?? []), 'completed_delivery_risk_flow_count', $expectedFlowCount),
                'operational_outcome_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($outcomeByCompany[$id] ?? []), 'completed_operational_outcome_flow_count', $expectedFlowCount),
            ];

            $flowCycles = [];
            foreach ($flows as $flowId) {
                $flowId = (string) $flowId;
                $lanes = [
                    'product_offer' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId) !== [],
                    'delivery_blueprint' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.service_delivery_blueprints', $flowId) !== [],
                    'sales_route' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId) !== [],
                    'proposal_packet' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId) !== [],
                    'support_lane' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId) !== [],
                    'marketing_campaign' => $this->hub->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', $flowId) !== [],
                    'finance_budget' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', $flowId) !== [],
                    'finance_billing_control' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId) !== [],
                    'unit_economics' => $this->hub->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', $flowId) !== [],
                    'business_execution_cell' => $this->hub->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', $flowId) !== [],
                    'command_center_card' => $this->hub->findByFlow($company, 'enterprise_company_command_center_stack.flow_command_cards', $flowId) !== [],
                    'operating_package' => $this->hub->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId) !== [],
                    'runbook_drill' => $this->hub->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', $flowId) !== [],
                ];

                $readyLaneCount = count(array_filter($lanes));
                $flowCycle = [
                    'schema' => 'atlas.ai.company.enterprise_flow_operating_cycle.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready_lane_count' => $readyLaneCount,
                    'required_lane_count' => count($lanes),
                    'cycle_score' => count($lanes) > 0 ? round($readyLaneCount / count($lanes), 4) : 0.0,
                    'cycle_ready' => $readyLaneCount === count($lanes),
                    'lanes' => $lanes,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $flowCycle['flow_operating_cycle_hash'] = MissionCanonicalHash::sha256($flowCycle);
                $flowCycles[] = $flowCycle;
            }

            $readyRuntimeGateCount = count(array_filter($runtimeGates));
            $readyFlowCycleCount = count(array_filter($flowCycles, static fn (array $flowCycle): bool => (bool) ($flowCycle['cycle_ready'] ?? false)));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_cycle_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'ready_flow_cycle_count' => $readyFlowCycleCount,
                'flow_cycle_count' => count($flowCycles),
                'ready_runtime_gate_count' => $readyRuntimeGateCount,
                'required_runtime_gate_count' => count($runtimeGates),
                'ready_lane_count' => array_sum(array_map(static fn (array $flowCycle): int => (int) ($flowCycle['ready_lane_count'] ?? 0), $flowCycles)),
                'required_lane_count' => array_sum(array_map(static fn (array $flowCycle): int => (int) ($flowCycle['required_lane_count'] ?? 0), $flowCycles)),
                'operating_cycle_ready' => $expectedFlowCount > 0
                    && $readyFlowCycleCount === count($flowCycles)
                    && $readyRuntimeGateCount === count($runtimeGates),
                'runtime_gates' => $runtimeGates,
                'missing_runtime_gates' => array_values(array_keys(array_filter($runtimeGates, static fn (bool $ready): bool => ! $ready))),
                'flow_cycles' => $flowCycles,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['operating_cycle_score'] = $row['required_lane_count'] > 0
                ? round(((int) $row['ready_lane_count']) / ((int) $row['required_lane_count']), 4)
                : 0.0;
            $row['company_operating_cycle_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['operating_cycle_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_CYCLE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operating_cycles_ready_external_autonomy_still_blocked'
                : 'enterprise_company_operating_cycles_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'operating_cycle_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'flow_cycle_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_cycle_count'] ?? 0), $companies)),
                'ready_flow_cycle_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_cycle_count'] ?? 0), $companies)),
                'required_runtime_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_runtime_gate_count'] ?? 0), $companies)),
                'ready_runtime_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_runtime_gate_count'] ?? 0), $companies)),
                'required_lane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_lane_count'] ?? 0), $companies)),
                'ready_lane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_lane_count'] ?? 0), $companies)),
                'average_operating_cycle_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['operating_cycle_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_vertical_operational_depth_status_hash' => $depth['enterprise_vertical_operational_depth_status_hash'] ?? null,
                'enterprise_company_completion_certification_status_hash' => $completion['enterprise_company_completion_certification_status_hash'] ?? null,
                'customer_account_revenue_runtime_status_hash' => $customerRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                'productized_service_runtime_status_hash' => $productizedService['productized_service_runtime_status_hash'] ?? null,
                'sales_crm_pipeline_runtime_status_hash' => $salesCrm['sales_crm_pipeline_runtime_status_hash'] ?? null,
                'customer_support_service_desk_runtime_status_hash' => $supportDesk['customer_support_service_desk_runtime_status_hash'] ?? null,
                'marketing_growth_engine_runtime_status_hash' => $marketingGrowth['marketing_growth_engine_runtime_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasury['finance_treasury_billing_runtime_status_hash'] ?? null,
                'governance_risk_operations_runtime_status_hash' => $governanceRisk['governance_risk_operations_runtime_status_hash'] ?? null,
                'unit_economics_capacity_runtime_status_hash' => $unitEconomics['unit_economics_capacity_runtime_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'delivery_risk_runtime_status_hash' => $deliveryRisk['delivery_risk_runtime_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $outcome['operational_outcome_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'company_operating_cycle_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['external_customer_commitment', 'external_billing', 'real_money_movement', 'paid_campaign', 'public_publish', 'vendor_purchase', 'unattended_cutover'],
            ],
        ];
        $payload['enterprise_company_operating_cycle_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingCadenceStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cycle = $this->enterpriseCompanyOperatingCycleStatus($wantedCompany);
        $workforce = $this->hub->flowActionRuntime->workforceCapacityRuntimeStatus($wantedCompany);
        $dependencies = $this->hub->flowActionRuntime->portfolioDependencyRuntimeStatus($wantedCompany);
        $commandCenter = $this->hub->flowActionRuntime->commandCenterControlTowerRuntimeStatus($wantedCompany);
        $unitEconomics = $this->hub->flowActionRuntime->unitEconomicsCapacityRuntimeStatus($wantedCompany);

        $cycleByCompany = $this->hub->companyRowsById($cycle);
        $workforceByCompany = $this->hub->companyRowsById($workforce);
        $dependenciesByCompany = $this->hub->companyRowsById($dependencies);
        $commandCenterByCompany = $this->hub->companyRowsById($commandCenter);
        $unitEconomicsByCompany = $this->hub->companyRowsById($unitEconomics);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $cadences = array_values((array) ($company['cadences'] ?? []));
            $expectedFlowCount = count($flows);
            $expectedCadenceCount = count($cadences);
            $okrCount = count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', []));
            $riskCount = count((array) data_get($company, 'enterprise_operating_system.risk_register', []));
            $backlogLanes = (array) data_get($company, 'enterprise_operating_system.backlog_system.lanes', []);
            $runbooks = (array) data_get($company, 'enterprise_operating_system.runbooks', []);
            $cadenceScheduler = (array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', []);
            $backlogWipLimits = (array) data_get($company, 'enterprise_operating_system.backlog_system.wip_limits', []);

            $cycleRow = (array) ($cycleByCompany[$id] ?? []);
            $workforceRow = (array) ($workforceByCompany[$id] ?? []);
            $dependenciesRow = (array) ($dependenciesByCompany[$id] ?? []);
            $commandCenterRow = (array) ($commandCenterByCompany[$id] ?? []);
            $unitEconomicsRow = (array) ($unitEconomicsByCompany[$id] ?? []);

            $cadenceRecords = [];
            foreach ($cadences as $cadence) {
                $cadenceId = (string) $cadence;
                $scheduler = $this->hub->findByKey($cadenceScheduler, 'cadence_id', $cadenceId);
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_operating_cadence_record.v1',
                    'company_id' => $id,
                    'cadence_id' => $cadenceId,
                    'horizon' => str_contains($cadenceId, 'weekly')
                        ? 'weekly'
                        : (str_contains($cadenceId, 'daily') ? 'daily' : 'per_run_or_event'),
                    'scheduler_bound' => $scheduler !== [],
                    'required_input_count' => count((array) ($scheduler['required_inputs'] ?? [])),
                    'output_count' => count((array) ($scheduler['outputs'] ?? [])),
                    'missed_cadence_action_bound' => (string) ($scheduler['missed_cadence_action'] ?? '') !== '',
                    'operator_review_packet_required' => in_array('operator_review_packet', (array) ($scheduler['outputs'] ?? []), true),
                    'backlog_update_required' => in_array('backlog_update', (array) ($scheduler['outputs'] ?? []), true),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $record['cadence_ready'] = (bool) $record['scheduler_bound']
                    && (int) $record['required_input_count'] >= 5
                    && (int) $record['output_count'] >= 3
                    && (bool) $record['missed_cadence_action_bound']
                    && (bool) $record['operator_review_packet_required']
                    && (bool) $record['backlog_update_required'];
                $record['operating_cadence_record_hash'] = MissionCanonicalHash::sha256($record);
                $cadenceRecords[] = $record;
            }

            $dailyCadenceCount = count(array_filter($cadenceRecords, static fn (array $record): bool => ($record['horizon'] ?? null) === 'daily'));
            $weeklyCadenceCount = count(array_filter($cadenceRecords, static fn (array $record): bool => ($record['horizon'] ?? null) === 'weekly'));
            $eventCadenceCount = count(array_filter($cadenceRecords, static fn (array $record): bool => ($record['horizon'] ?? null) === 'per_run_or_event'));
            $continuousImprovementSystem = [
                'daily_control_count' => $dailyCadenceCount,
                'weekly_board_count' => $weeklyCadenceCount,
                'per_run_or_event_control_count' => $eventCadenceCount,
                'monthly_process_improvement_bound' => $okrCount >= 4 && $riskCount >= 4 && count($backlogLanes) >= 7,
                'incident_or_missed_cadence_postmortem_bound' => count(array_filter(
                    $cadenceRecords,
                    static fn (array $record): bool => (bool) ($record['missed_cadence_action_bound'] ?? false)
                )) >= $expectedCadenceCount,
                'backlog_wip_limit_count' => count(array_filter($backlogWipLimits, static fn (mixed $limit): bool => (int) $limit > 0)),
                'risk_remediation_source_count' => $riskCount,
                'external_commitments_allowed' => false,
            ];

            $gates = [
                'company_operating_cycle_ready' => (bool) ($cycleRow['operating_cycle_ready'] ?? false),
                'command_center_runtime_ready' => $this->hub->companyRuntimeCoverageReady($commandCenterRow, 'completed_command_center_control_tower_flow_count', $expectedFlowCount),
                'workforce_capacity_runtime_ready' => $this->hub->companyRuntimeCoverageReady($workforceRow, 'completed_workforce_capacity_flow_count', $expectedFlowCount),
                'portfolio_dependency_runtime_ready' => $this->hub->companyRuntimeCoverageReady($dependenciesRow, 'completed_portfolio_dependency_flow_count', $expectedFlowCount),
                'unit_economics_capacity_runtime_ready' => $this->hub->companyRuntimeCoverageReady($unitEconomicsRow, 'completed_unit_economics_capacity_flow_count', $expectedFlowCount),
                'governance_board_ready' => data_get($company, 'enterprise_operating_system.governance_board.cadence') === 'weekly_operating_board'
                    && (string) data_get($company, 'enterprise_operating_system.governance_board.decision_rights.external_action') === 'operator_approval_required',
                'okr_scorecard_ready' => $okrCount >= 4,
                'risk_register_ready' => $riskCount >= 4,
                'backlog_system_ready' => count($backlogLanes) >= 7
                    && (int) data_get($company, 'enterprise_operating_system.backlog_system.wip_limits.in_progress', 0) > 0
                    && (int) data_get($company, 'enterprise_operating_system.backlog_system.wip_limits.review', 0) > 0,
                'runbooks_ready' => count($runbooks) >= $expectedFlowCount,
                'cadence_scheduler_ready' => count($cadenceRecords) >= $expectedCadenceCount
                    && count(array_filter($cadenceRecords, static fn (array $record): bool => (bool) ($record['cadence_ready'] ?? false))) === $expectedCadenceCount,
                'daily_weekly_event_rhythm_bound' => $weeklyCadenceCount > 0 && ($dailyCadenceCount + $eventCadenceCount) >= 2,
                'monthly_process_improvement_bound' => (bool) $continuousImprovementSystem['monthly_process_improvement_bound'],
                'incident_or_missed_cadence_postmortem_bound' => (bool) $continuousImprovementSystem['incident_or_missed_cadence_postmortem_bound'],
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_cadence_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'cadence_count' => $expectedCadenceCount,
                'ready_cadence_count' => count(array_filter($cadenceRecords, static fn (array $record): bool => (bool) ($record['cadence_ready'] ?? false))),
                'okr_count' => $okrCount,
                'risk_count' => $riskCount,
                'backlog_lane_count' => count($backlogLanes),
                'backlog_wip_limit_count' => (int) $continuousImprovementSystem['backlog_wip_limit_count'],
                'runbook_count' => count($runbooks),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'cadence_ready' => $expectedFlowCount > 0
                    && $expectedCadenceCount > 0
                    && $readyGateCount === count($gates),
                'cadence_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'operating_calendar' => [
                    'quarterly_strategy_review' => [
                        'bound' => $okrCount >= 4 && $riskCount >= 4,
                        'inputs' => ['okr_scorecard', 'risk_register', 'unit_economics_capacity', 'portfolio_dependency_report'],
                        'outputs' => ['portfolio_priority_reset', 'capacity_budget_review', 'operator_exception_packet'],
                        'external_commitments_allowed' => false,
                    ],
                    'weekly_operating_board' => [
                        'bound' => (bool) $gates['governance_board_ready'],
                        'inputs' => ['scorecard_review', 'flow_throughput_review', 'quality_gate_exceptions', 'next_commitments'],
                        'outputs' => ['prioritized_run_plan', 'blocked_work_review', 'cadence_backlog_update'],
                        'external_commitments_allowed' => false,
                    ],
                    'daily_or_per_run_control' => [
                        'bound' => count($cadenceRecords) >= $expectedCadenceCount,
                        'inputs' => ['open_runs', 'blocked_runs', 'recent_receipts', 'metric_snapshot', 'risk_register_delta'],
                        'outputs' => ['operator_review_packet', 'backlog_update', 'control_tower_exception'],
                        'external_commitments_allowed' => false,
                    ],
                    'monthly_process_improvement' => [
                        'bound' => (bool) $continuousImprovementSystem['monthly_process_improvement_bound'],
                        'inputs' => ['okr_scorecard', 'risk_register', 'backlog_health', 'runbook_postmortems', 'unit_economics_capacity'],
                        'outputs' => ['process_improvement_backlog', 'risk_remediation_plan', 'automation_candidate_review', 'operator_exception_packet'],
                        'external_commitments_allowed' => false,
                    ],
                ],
                'continuous_improvement_system' => $continuousImprovementSystem,
                'cadence_records' => $cadenceRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_operating_cadence_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['cadence_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_CADENCE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operating_cadences_ready_external_autonomy_still_blocked'
                : 'enterprise_company_operating_cadences_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'cadence_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'cadence_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['cadence_count'] ?? 0), $companies)),
                'ready_cadence_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_cadence_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'okr_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['okr_count'] ?? 0), $companies)),
                'risk_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['risk_count'] ?? 0), $companies)),
                'backlog_wip_limit_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['backlog_wip_limit_count'] ?? 0), $companies)),
                'runbook_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['runbook_count'] ?? 0), $companies)),
                'monthly_process_improvement_bound_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'continuous_improvement_system.monthly_process_improvement_bound', false))),
                'incident_postmortem_bound_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'continuous_improvement_system.incident_or_missed_cadence_postmortem_bound', false))),
                'average_cadence_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['cadence_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_operating_cycle_status_hash' => $cycle['enterprise_company_operating_cycle_status_hash'] ?? null,
                'workforce_capacity_runtime_status_hash' => $workforce['workforce_capacity_runtime_status_hash'] ?? null,
                'portfolio_dependency_runtime_status_hash' => $dependencies['portfolio_dependency_runtime_status_hash'] ?? null,
                'command_center_control_tower_runtime_status_hash' => $commandCenter['command_center_control_tower_runtime_status_hash'] ?? null,
                'unit_economics_capacity_runtime_status_hash' => $unitEconomics['unit_economics_capacity_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'company_operating_cadence_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['unattended_external_execution', 'external_customer_commitment', 'external_billing', 'real_money_movement', 'paid_campaign', 'vendor_purchase', 'public_publish'],
            ],
        ];
        $payload['enterprise_company_operating_cadence_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingScorecardStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cadence = $this->enterpriseCompanyOperatingCadenceStatus($wantedCompany);
        $holdingScorecard = $this->hub->flowActionRuntime->holdingOutcomeScorecardStatus($wantedCompany);
        $portfolioDecision = $this->hub->flowActionRuntime->portfolioDecisionPacketStatus($wantedCompany);
        $financeTreasury = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $unitEconomics = $this->hub->flowActionRuntime->unitEconomicsCapacityRuntimeStatus($wantedCompany);
        $customerRevenue = $this->hub->flowActionRuntime->customerAccountRevenueRuntimeStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $boardReview = $this->hub->flowActionRuntime->companyBoardOperatingReviewStatus($wantedCompany);

        $cadenceByCompany = $this->hub->companyRowsById($cadence);
        $holdingByCompany = $this->hub->companyRowsById($holdingScorecard);
        $portfolioByCompany = $this->hub->companyRowsById($portfolioDecision);
        $financeByCompany = $this->hub->companyRowsById($financeTreasury);
        $unitByCompany = $this->hub->companyRowsById($unitEconomics);
        $customerByCompany = $this->hub->companyRowsById($customerRevenue);
        $businessByCompany = $this->hub->companyRowsById($businessPacket);
        $boardReviewByCompany = $this->hub->companyRowsById($boardReview);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values((array) ($company['flows'] ?? []));
            $expectedFlowCount = count($flows);
            $workProducts = (array) ($company['work_products'] ?? []);

            $cadenceRow = (array) ($cadenceByCompany[$id] ?? []);
            $holdingRow = (array) ($holdingByCompany[$id] ?? []);
            $portfolioRow = (array) ($portfolioByCompany[$id] ?? []);
            $financeRow = (array) ($financeByCompany[$id] ?? []);
            $unitRow = (array) ($unitByCompany[$id] ?? []);
            $customerRow = (array) ($customerByCompany[$id] ?? []);
            $businessRow = (array) ($businessByCompany[$id] ?? []);
            $boardReviewRow = (array) ($boardReviewByCompany[$id] ?? []);

            $costCenterCount = count((array) data_get($company, 'portfolio_finance_stack.flow_cost_centers', []));
            $pricingLadderCount = count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', []));
            $billingLedgerCount = count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', []));
            $pnlLineItemCount = count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', []));
            $budgetEnvelopeCount = count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', []));
            $capacitySimulationCount = count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', []));
            $acceptedValueProxyCount = (int) ($holdingRow['measured_kpi_count'] ?? 0);
            $internalScore = (float) ($holdingRow['score'] ?? 0.0);
            $riskRemediationCount = (int) data_get($boardReviewRow, 'continuous_improvement_backlog.risk_remediation_count', 0);
            $nextCycleActionCount = (int) data_get($boardReviewRow, 'continuous_improvement_backlog.next_cycle_action_count', 0);
            $boardDecisionActionCount = (int) ($boardReviewRow['required_operator_decision_count'] ?? 0);
            $validScorecardSourceHashCount = count(array_filter([
                $cadenceRow['company_operating_cadence_record_hash'] ?? null,
                $holdingRow['scorecard_hash'] ?? null,
                $portfolioRow['decision_packet_hash'] ?? null,
                $boardReviewRow['board_operating_review_hash'] ?? null,
            ], static fn (mixed $hash): bool => is_string($hash) && strlen($hash) === 64));

            $gates = [
                'operating_cadence_ready' => (bool) ($cadenceRow['cadence_ready'] ?? false),
                'holding_outcome_scorecard_ready' => (bool) ($holdingRow['ready'] ?? false) && $internalScore >= 9.0,
                'portfolio_decision_packet_ready' => (bool) ($portfolioRow['ready'] ?? false),
                'board_operating_review_ready' => (bool) ($boardReviewRow['ready'] ?? false),
                'finance_treasury_billing_runtime_ready' => $this->hub->companyRuntimeCoverageReady($financeRow, 'completed_finance_treasury_billing_flow_count', $expectedFlowCount),
                'unit_economics_capacity_runtime_ready' => $this->hub->companyRuntimeCoverageReady($unitRow, 'completed_unit_economics_capacity_flow_count', $expectedFlowCount),
                'customer_account_revenue_runtime_ready' => $this->hub->companyRuntimeCoverageReady($customerRow, 'completed_customer_account_revenue_flow_count', $expectedFlowCount),
                'business_operating_packet_runtime_ready' => $this->hub->companyRuntimeCoverageReady($businessRow, 'completed_business_operating_packet_flow_count', $expectedFlowCount),
                'flow_cost_centers_ready' => $costCenterCount >= $expectedFlowCount,
                'pricing_ladder_ready' => $pricingLadderCount >= max(5, count($workProducts)),
                'billing_ledger_controls_ready' => $billingLedgerCount >= $expectedFlowCount,
                'pnl_model_ready' => $pnlLineItemCount >= 5,
                'budget_envelopes_ready' => $budgetEnvelopeCount >= $expectedFlowCount,
                'capacity_simulation_ready' => $capacitySimulationCount >= $expectedFlowCount,
                'continuous_improvement_backlog_bound' => $nextCycleActionCount >= $expectedFlowCount
                    && (bool) data_get($boardReviewRow, 'continuous_improvement_backlog.bound_to_outcome_scorecard', false),
                'risk_remediation_backlog_bound' => $riskRemediationCount >= $expectedFlowCount,
                'board_decision_actions_bound' => $boardDecisionActionCount >= 3,
                'scorecard_source_hash_lineage_bound' => $validScorecardSourceHashCount === 4,
                'external_financial_actions_blocked' => (int) ($financeRow['external_financial_actions_blocked_count'] ?? 0) >= $expectedFlowCount
                    && (bool) data_get($company, 'portfolio_finance_stack.budget_envelope.external_spend_enabled', true) === false
                    && (bool) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.investment_prioritization_model.real_capital_action_allowed', true) === false,
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_scorecard_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'internal_outcome_score' => $internalScore,
                'completed_outcome_flow_count' => (int) ($holdingRow['completed_outcome_flow_count'] ?? 0),
                'measured_kpi_count' => $acceptedValueProxyCount,
                'portfolio_decision_recommendation' => (string) ($portfolioRow['decision_recommendation'] ?? 'repair_before_scale'),
                'allocation_class' => (string) ($portfolioRow['allocation_class'] ?? 'hold_and_repair'),
                'board_operating_review_hash' => (string) ($boardReviewRow['board_operating_review_hash'] ?? ''),
                'notional_internal_pnl' => [
                    'mode' => 'internal_notional_pnl_until_external_evidence_and_operator_acceptance',
                    'notional_revenue_or_value_proxy_units' => $acceptedValueProxyCount,
                    'notional_cost_center_count' => $costCenterCount,
                    'pnl_line_item_count' => $pnlLineItemCount,
                    'billing_ledger_control_count' => $billingLedgerCount,
                    'pricing_ladder_count' => $pricingLadderCount,
                    'external_revenue_claim_allowed' => false,
                    'real_money_movement_allowed' => false,
                ],
                'capacity_and_investment' => [
                    'capacity_simulation_count' => $capacitySimulationCount,
                    'budget_envelope_count' => $budgetEnvelopeCount,
                    'decision_options' => (array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.investment_prioritization_model.decision_options', []),
                    'real_capital_action_allowed' => false,
                ],
                'governance_and_improvement' => [
                    'board_review_ready' => (bool) ($boardReviewRow['ready'] ?? false),
                    'risk_remediation_count' => $riskRemediationCount,
                    'next_cycle_action_count' => $nextCycleActionCount,
                    'board_decision_action_count' => $boardDecisionActionCount,
                    'scorecard_source_hash_lineage_count' => $validScorecardSourceHashCount,
                    'monthly_process_improvement_bound' => (bool) data_get($cadenceRow, 'continuous_improvement_system.monthly_process_improvement_bound', false),
                    'incident_postmortem_bound' => (bool) data_get($cadenceRow, 'continuous_improvement_system.incident_or_missed_cadence_postmortem_bound', false),
                    'external_commitments_allowed' => false,
                ],
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'scorecard_ready' => $expectedFlowCount > 0
                    && $readyGateCount === count($gates),
                'scorecard_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_internal_enterprise_operating_scorecard_external_value_claim_blocked'
                    : 'operating_scorecard_attention_required',
                'scorecard_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_operating_scorecard_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['scorecard_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_SCORECARD_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operating_scorecards_ready_external_value_claims_blocked'
                : 'enterprise_company_operating_scorecards_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'scorecard_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'completed_outcome_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['completed_outcome_flow_count'] ?? 0), $companies)),
                'measured_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['measured_kpi_count'] ?? 0), $companies)),
                'board_review_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'governance_and_improvement.board_review_ready', false))),
                'risk_remediation_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'governance_and_improvement.risk_remediation_count', 0), $companies)),
                'next_cycle_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'governance_and_improvement.next_cycle_action_count', 0), $companies)),
                'board_decision_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'governance_and_improvement.board_decision_action_count', 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_internal_outcome_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['internal_outcome_score'] ?? 0.0), $companies)) / count($companies), 2) : 0.0,
                'average_scorecard_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['scorecard_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'scale_internal_supervised_capacity_count' => count(array_filter($companies, static fn (array $company): bool => ($company['portfolio_decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity')),
                'external_revenue_claim_allowed_count' => 0,
                'real_money_movement_allowed_count' => 0,
                'external_execution_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_operating_cadence_status_hash' => $cadence['enterprise_company_operating_cadence_status_hash'] ?? null,
                'holding_outcome_scorecard_status_hash' => $holdingScorecard['holding_outcome_scorecard_status_hash'] ?? null,
                'portfolio_decision_packet_status_hash' => $portfolioDecision['portfolio_decision_packet_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasury['finance_treasury_billing_runtime_status_hash'] ?? null,
                'unit_economics_capacity_runtime_status_hash' => $unitEconomics['unit_economics_capacity_runtime_status_hash'] ?? null,
                'customer_account_revenue_runtime_status_hash' => $customerRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'company_board_operating_review_status_hash' => $boardReview['company_board_operating_review_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'company_operating_scorecard_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_revenue_claim_allowed' => false,
                'real_money_movement_allowed' => false,
                'operator_signed_scope_required_for_external_value_claim_or_capital_action' => true,
                'blocked_operations' => ['external_revenue_claim', 'invoice_send', 'payment_collection', 'capital_transfer', 'trade', 'public_financial_claim', 'vendor_purchase'],
            ],
        ];
        $payload['enterprise_company_operating_scorecard_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyActiveOperatingSystemStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $completion = $this->hub->enterpriseCompletion->enterpriseCompanyCompletionCertificationStatus($wantedCompany);
        $depth = $this->hub->enterpriseCompletion->enterpriseVerticalOperationalDepthStatus($wantedCompany);
        $cycle = $this->enterpriseCompanyOperatingCycleStatus($wantedCompany);
        $cadence = $this->enterpriseCompanyOperatingCadenceStatus($wantedCompany);
        $scorecard = $this->enterpriseCompanyOperatingScorecardStatus($wantedCompany);
        $cutover = $this->hub->cutoverCloseout->externalSupervisedCutoverPortfolioReadinessStatus($wantedCompany);
        $supervisedPacket = $this->hub->realExecutionChain->supervisedExternalExecutionPacketStatus($wantedCompany);

        $completionByCompany = $this->hub->companyRowsById($completion);
        $depthByCompany = $this->hub->companyRowsById($depth);
        $cycleByCompany = $this->hub->companyRowsById($cycle);
        $cadenceByCompany = $this->hub->companyRowsById($cadence);
        $scorecardByCompany = $this->hub->companyRowsById($scorecard);
        $cutoverByCompany = $this->hub->companyRowsById($cutover);
        $packetByCompany = $this->hub->companyRowsById($supervisedPacket);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));

            $completionRow = (array) ($completionByCompany[$id] ?? []);
            $depthRow = (array) ($depthByCompany[$id] ?? []);
            $cycleRow = (array) ($cycleByCompany[$id] ?? []);
            $cadenceRow = (array) ($cadenceByCompany[$id] ?? []);
            $scorecardRow = (array) ($scorecardByCompany[$id] ?? []);
            $cutoverRow = (array) ($cutoverByCompany[$id] ?? []);
            $packetRow = (array) ($packetByCompany[$id] ?? []);
            $structuralActiveOperatingSystemReady = (bool) data_get($company, 'readiness.ok', false)
                && data_get($company, 'enterprise_operating_system.schema') === 'atlas.ai.company.enterprise_operating_system.v1'
                && (string) data_get($company, 'enterprise_operating_system.operating_system_hash', '') !== ''
                && count((array) data_get($company, 'enterprise_operating_system.governance_board.standing_agenda', [])) >= 5
                && count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])) >= 4
                && count((array) data_get($company, 'enterprise_operating_system.risk_register', [])) >= 4
                && count((array) data_get($company, 'enterprise_operating_system.sla_catalog', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_operating_system.runbooks', [])) >= $expectedFlowCount
                && (bool) data_get($company, 'enterprise_operating_system.audit.decision_log_required', false)
                && (bool) data_get($company, 'enterprise_operating_system.audit.evidence_ledger_required', false)
                && (bool) data_get($company, 'enterprise_operating_system.audit.receipt_hash_required', false)
                && count((array) data_get($company, 'control_plane.command_surfaces', [])) >= 3
                && count((array) data_get($company, 'control_plane.dashboards', [])) >= 3;
            $externalCutoverReceiptChainComplete = (bool) ($completionRow['supervised_cutover_receipt_chain_complete'] ?? false)
                || (bool) ($cutoverRow['cutover_chain_complete'] ?? false);

            $gates = [
                'completion_certified_or_structural_buildout_ready' => (bool) ($completionRow['completion_certified'] ?? false)
                    || $structuralActiveOperatingSystemReady,
                'vertical_operational_depth_ready_or_structural_depth_ready' => (int) ($depthRow['ready_gate_count'] ?? 0) === (int) ($depthRow['required_gate_count'] ?? -1)
                    || (
                        $structuralActiveOperatingSystemReady
                        && count((array) data_get($company, 'enterprise_domain_operating_depth_stack.flow_depth_packets', [])) >= $expectedFlowCount
                        && (bool) data_get($company, 'enterprise_domain_operating_depth_stack.depth_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    ),
                'operating_cycle_ready_or_structural_runbooks_ready' => (bool) ($cycleRow['operating_cycle_ready'] ?? false)
                    || (
                        $structuralActiveOperatingSystemReady
                        && count((array) data_get($company, 'enterprise_operating_system.runbooks', [])) >= $expectedFlowCount
                    ),
                'operating_cadence_ready_or_structural_cadence_ready' => (bool) ($cadenceRow['cadence_ready'] ?? false)
                    || (
                        $structuralActiveOperatingSystemReady
                        && (
                            count((array) ($company['cadences'] ?? [])) >= min(4, $expectedFlowCount)
                            || count((array) data_get($company, 'enterprise_operating_system.sla_catalog', [])) >= $expectedFlowCount
                        )
                    ),
                'operating_scorecard_ready_or_structural_scorecard_ready' => (bool) ($scorecardRow['scorecard_ready'] ?? false)
                    || (
                        $structuralActiveOperatingSystemReady
                        && count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])) >= 4
                    ),
                'supervised_cutover_receipt_chain_or_internal_handoff_model_ready' => $externalCutoverReceiptChainComplete
                    || $structuralActiveOperatingSystemReady,
                'real_external_execution_dossier_or_internal_dossier_model_ready' => (bool) ($completionRow['real_external_execution_dossier_ready'] ?? false)
                    || $structuralActiveOperatingSystemReady,
                'manual_handoff_pack_or_internal_handoff_model_ready' => (bool) ($completionRow['manual_handoff_pack_ready'] ?? false)
                    || $structuralActiveOperatingSystemReady,
                'supervised_external_execution_packet_ready' => $expectedFlowCount > 0
                    && (int) ($packetRow['flow_count'] ?? 0) === $expectedFlowCount
                    && (int) ($packetRow['packet_ready_count'] ?? 0) >= $expectedFlowCount,
                'external_value_claims_blocked' => (bool) data_get($scorecardRow, 'notional_internal_pnl.external_revenue_claim_allowed', true) === false
                    && (bool) data_get($scorecardRow, 'notional_internal_pnl.real_money_movement_allowed', true) === false,
                'external_execution_blocked' => (bool) ($completionRow['external_execution_allowed'] ?? true) === false
                    && (bool) ($depthRow['external_execution_allowed'] ?? true) === false
                    && (bool) ($cycleRow['external_execution_allowed'] ?? true) === false
                    && (bool) ($cadenceRow['external_execution_allowed'] ?? true) === false
                    && (bool) ($scorecardRow['external_execution_allowed'] ?? true) === false,
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_active_operating_system_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'active_operating_system_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'active_operating_system_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_internal_active_operating_system_external_autonomy_blocked'
                    : 'active_operating_system_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'active_operating_system_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'layer_scores' => [
                    'vertical_operational_depth_score' => (float) ($depthRow['depth_score'] ?? 0.0),
                    'operating_cycle_score' => (float) ($cycleRow['operating_cycle_score'] ?? 0.0),
                    'operating_cadence_score' => (float) ($cadenceRow['cadence_score'] ?? 0.0),
                    'operating_scorecard_score' => (float) ($scorecardRow['scorecard_score'] ?? 0.0),
                    'internal_outcome_score' => (float) ($scorecardRow['internal_outcome_score'] ?? 0.0),
                ],
                'governance_chain' => [
                    'completion_certification_record_hash' => $completionRow['completion_certification_record_hash'] ?? null,
                    'vertical_operational_depth_record_hash' => $depthRow['vertical_operational_depth_record_hash'] ?? null,
                    'company_operating_cycle_record_hash' => $cycleRow['company_operating_cycle_record_hash'] ?? null,
                    'company_operating_cadence_record_hash' => $cadenceRow['company_operating_cadence_record_hash'] ?? null,
                    'company_operating_scorecard_record_hash' => $scorecardRow['company_operating_scorecard_record_hash'] ?? null,
                    'portfolio_readiness_record_hash' => $cutoverRow['portfolio_readiness_record_hash'] ?? null,
                    'supervised_execution_packet_hash' => $packetRow['company_packet_status_hash'] ?? null,
                ],
                'structural_active_operating_system' => [
                    'ready' => $structuralActiveOperatingSystemReady,
                    'operating_system_hash' => data_get($company, 'enterprise_operating_system.operating_system_hash'),
                    'governance_agenda_count' => count((array) data_get($company, 'enterprise_operating_system.governance_board.standing_agenda', [])),
                    'okr_count' => count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])),
                    'risk_count' => count((array) data_get($company, 'enterprise_operating_system.risk_register', [])),
                    'sla_count' => count((array) data_get($company, 'enterprise_operating_system.sla_catalog', [])),
                    'runbook_count' => count((array) data_get($company, 'enterprise_operating_system.runbooks', [])),
                    'external_cutover_receipt_chain_complete' => $externalCutoverReceiptChainComplete,
                    'external_autonomy_still_blocked' => true,
                ],
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_autonomy_allowed' => false,
            ];
            $row['active_operating_system_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['active_operating_system_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_ACTIVE_OPERATING_SYSTEM_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_active_operating_systems_ready_external_autonomy_still_blocked'
                : 'enterprise_company_active_operating_systems_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'active_operating_system_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_active_operating_system_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['active_operating_system_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'average_internal_outcome_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) data_get($company, 'layer_scores.internal_outcome_score', 0.0), $companies)) / count($companies), 2) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'external_autonomy_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_completion_certification_status_hash' => $completion['enterprise_company_completion_certification_status_hash'] ?? null,
                'enterprise_vertical_operational_depth_status_hash' => $depth['enterprise_vertical_operational_depth_status_hash'] ?? null,
                'enterprise_company_operating_cycle_status_hash' => $cycle['enterprise_company_operating_cycle_status_hash'] ?? null,
                'enterprise_company_operating_cadence_status_hash' => $cadence['enterprise_company_operating_cadence_status_hash'] ?? null,
                'enterprise_company_operating_scorecard_status_hash' => $scorecard['enterprise_company_operating_scorecard_status_hash'] ?? null,
                'external_supervised_cutover_portfolio_readiness_status_hash' => $cutover['external_supervised_cutover_portfolio_readiness_status_hash'] ?? null,
                'supervised_external_execution_packet_status_hash' => $supervisedPacket['supervised_external_execution_packet_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'active_operating_system_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_autonomy_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['unattended_external_execution', 'external_customer_commitment', 'external_revenue_claim', 'invoice_send', 'payment_collection', 'capital_transfer', 'trade', 'vendor_purchase', 'public_publish'],
            ],
        ];
        $payload['enterprise_company_active_operating_system_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCapabilityCatalogStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $activeOperatingSystem = $this->enterpriseCompanyActiveOperatingSystemStatus($wantedCompany);
        $activeByCompany = $this->hub->companyRowsById($activeOperatingSystem);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values((array) ($company['flows'] ?? []));
            $expectedFlowCount = count($flows);
            $connectorCount = count((array) ($company['connectors'] ?? []));
            $metricCount = count((array) ($company['metrics'] ?? []));
            $workProductCount = count((array) ($company['work_products'] ?? []));
            $activeRow = (array) ($activeByCompany[$id] ?? []);
            $backbone = $this->hub->companyOperatingStatus->businessOperatingBackboneCompany($company);

            $families = [
                'active_operating_system' => [
                    'ready' => (bool) ($activeRow['active_operating_system_ready'] ?? false),
                    'artifact_count' => (int) ($activeRow['ready_gate_count'] ?? 0),
                    'minimum_artifact_count' => (int) ($activeRow['required_gate_count'] ?? 1),
                    'authority' => 'internal_governed_operations_only',
                ],
                'financial_services_data_modeling' => [
                    'ready' => data_get($company, 'enterprise_finance_treasury_billing_stack.schema') === 'atlas.ai.company.enterprise_finance_treasury_billing_stack.v1'
                        && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])) >= 5
                        && (bool) data_get($company, 'portfolio_finance_stack.budget_envelope.external_spend_enabled', true) === false,
                    'artifact_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', []))
                        + count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', []))
                        + count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])),
                    'minimum_artifact_count' => ($expectedFlowCount * 2) + 5,
                    'authority' => 'notional_financial_modeling_no_external_money_movement',
                ],
                'customer_market_operations' => [
                    'ready' => (bool) data_get($backbone, 'checks.customer_market_operations_green', false),
                    'artifact_count' => (int) ($backbone['customer_offer_count'] ?? 0),
                    'minimum_artifact_count' => 5,
                    'authority' => 'internal_market_planning_only',
                ],
                'account_contract_delivery' => [
                    'ready' => (bool) data_get($backbone, 'checks.account_contract_delivery_green', false),
                    'artifact_count' => (int) ($backbone['account_contract_count'] ?? 0),
                    'minimum_artifact_count' => 5,
                    'authority' => 'draft_contract_and_entitlement_model_only',
                ],
                'productized_service_delivery' => [
                    'ready' => count((array) data_get($company, 'enterprise_productized_service_stack.flow_service_offers', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_productized_service_stack.service_delivery_blueprints', [])) >= $expectedFlowCount,
                    'artifact_count' => count((array) data_get($company, 'enterprise_productized_service_stack.flow_service_offers', []))
                        + count((array) data_get($company, 'enterprise_productized_service_stack.service_delivery_blueprints', [])),
                    'minimum_artifact_count' => $expectedFlowCount * 2,
                    'authority' => 'internal_service_catalog_only',
                ],
                'sales_crm_pipeline' => [
                    'ready' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', [])) >= $expectedFlowCount,
                    'artifact_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', []))
                        + count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', [])),
                    'minimum_artifact_count' => $expectedFlowCount * 2,
                    'authority' => 'draft_pipeline_no_external_customer_commitment',
                ],
                'support_service_desk' => [
                    'ready' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])) >= $expectedFlowCount,
                    'artifact_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])),
                    'minimum_artifact_count' => $expectedFlowCount,
                    'authority' => 'internal_support_lane_model_only',
                ],
                'marketing_growth_engine' => [
                    'ready' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', [])) >= $expectedFlowCount,
                    'artifact_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', [])),
                    'minimum_artifact_count' => $expectedFlowCount,
                    'authority' => 'campaign_blueprints_no_paid_publish',
                ],
                'vendor_legal_procurement' => [
                    'ready' => (bool) data_get($backbone, 'checks.vendor_legal_procurement_green', false),
                    'artifact_count' => (int) ($backbone['vendor_due_diligence_count'] ?? 0),
                    'minimum_artifact_count' => $connectorCount,
                    'authority' => 'operator_only_for_real_spend',
                ],
                'resilience_continuity' => [
                    'ready' => (bool) data_get($backbone, 'checks.resilience_continuity_green', false),
                    'artifact_count' => (int) ($backbone['resilience_exercise_count'] ?? 0),
                    'minimum_artifact_count' => $expectedFlowCount,
                    'authority' => 'incident_drill_no_external_mutation',
                ],
                'analytics_decision_intelligence' => [
                    'ready' => (bool) data_get($backbone, 'checks.analytics_decision_intelligence_green', false),
                    'artifact_count' => (int) ($backbone['analytics_dashboard_count'] ?? 0),
                    'minimum_artifact_count' => 3,
                    'authority' => 'lineaged_metrics_no_synthetic_claims',
                ],
                'knowledge_memory_learning' => [
                    'ready' => (bool) data_get($backbone, 'checks.knowledge_memory_learning_green', false),
                    'artifact_count' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])),
                    'minimum_artifact_count' => 5,
                    'authority' => 'learning_loops_no_auto_canonical_rewrite',
                ],
                'identity_access_data_sovereignty' => [
                    'ready' => (bool) data_get($backbone, 'checks.identity_access_data_sovereignty_green', false),
                    'artifact_count' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])),
                    'minimum_artifact_count' => 4,
                    'authority' => 'deny_by_default_secretless_catalog',
                ],
                'control_tower_run_operations' => [
                    'ready' => (bool) data_get($backbone, 'checks.control_tower_run_operations_green', false),
                    'artifact_count' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])),
                    'minimum_artifact_count' => $expectedFlowCount,
                    'authority' => 'operator_visible_run_control',
                ],
                'semantic_operating_graph' => [
                    'ready' => (bool) data_get($backbone, 'checks.semantic_operating_graph_green', false),
                    'artifact_count' => (int) ($backbone['semantic_graph_node_count'] ?? 0),
                    'minimum_artifact_count' => $expectedFlowCount + $connectorCount + $metricCount,
                    'authority' => 'read_model_no_execution_authority',
                ],
                'delivery_assurance' => [
                    'ready' => (bool) data_get($backbone, 'checks.delivery_assurance_green', false),
                    'artifact_count' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])),
                    'minimum_artifact_count' => max(5, $workProductCount),
                    'authority' => 'acceptance_criteria_required_before_delivery_claim',
                ],
                'flow_work_product_delivery' => [
                    'ready' => data_get($company, 'enterprise_flow_work_product_delivery_stack.schema') === 'atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1'
                        && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= $workProductCount
                        && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])) >= $expectedFlowCount
                        && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true) === false,
                    'artifact_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', []))
                        + count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', []))
                        + count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', []))
                        + count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])),
                    'minimum_artifact_count' => $expectedFlowCount * 4,
                    'authority' => 'operator_ready_artifact_delivery_no_external_send',
                ],
                'domain_data_connector_operations' => [
                    'ready' => data_get($company, 'enterprise_domain_data_connector_operating_stack.schema') === 'atlas.ai.company.enterprise_domain_data_connector_operating_stack.v1'
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])) >= 7
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])) >= $workProductCount
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= $connectorCount
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', [])) >= $expectedFlowCount
                        && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true) === false
                        && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true) === false,
                    'artifact_count' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', []))
                        + count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', []))
                        + count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', []))
                        + count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', []))
                        + count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', [])),
                    'minimum_artifact_count' => 7 + $workProductCount + $connectorCount + ($expectedFlowCount * 2),
                    'authority' => 'governed_data_room_and_read_only_connector_operations',
                ],
                'flow_live_read_connector_probe_operations' => [
                    'ready' => data_get($company, 'enterprise_flow_live_read_connector_probe_stack.schema') === 'atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1'
                        && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= $connectorCount
                        && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= ($metricCount + 7)
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true) === false
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.live_read_allowed', false)
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true) === false
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.credential_material_in_packet_allowed', true) === false,
                    'artifact_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', []))
                        + count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', []))
                        + count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])),
                    'minimum_artifact_count' => $connectorCount + ($expectedFlowCount * 2),
                    'authority' => 'flow_scoped_live_read_or_sandbox_probe_no_external_mutation',
                ],
                'grc_audit_controls' => [
                    'ready' => (bool) data_get($backbone, 'checks.grc_green', false),
                    'artifact_count' => (int) ($backbone['grc_audit_evidence_count'] ?? 0),
                    'minimum_artifact_count' => $expectedFlowCount,
                    'authority' => 'audit_evidence_required_for_exception',
                ],
            ];

            $flowCapabilities = [];
            foreach ($flows as $flow) {
                $flowId = is_array($flow) ? (string) ($flow['id'] ?? $flow['flow_id'] ?? '') : (string) $flow;
                if ($flowId === '') {
                    continue;
                }
                $flowGates = [
                    'product_offer' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId) !== [],
                    'delivery_blueprint' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.service_delivery_blueprints', $flowId) !== [],
                    'sales_route' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId) !== [],
                    'proposal_packet' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId) !== [],
                    'support_lane' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId) !== [],
                    'marketing_campaign' => $this->hub->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', $flowId) !== [],
                    'finance_budget' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', $flowId) !== [],
                    'finance_billing_control' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId) !== [],
                    'unit_economics' => $this->hub->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', $flowId) !== [],
                    'execution_cell' => $this->hub->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', $flowId) !== [],
                    'command_center_card' => $this->hub->findByFlow($company, 'enterprise_company_command_center_stack.flow_command_cards', $flowId) !== [],
                    'operating_package' => $this->hub->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId) !== [],
                    'work_product_delivery_blueprint' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', $flowId) !== [],
                    'work_product_acceptance_contract' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId) !== [],
                    'work_product_handoff_packet' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId) !== [],
                    'work_product_replay_check' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', $flowId) !== [],
                    'flow_data_connector_contract' => $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId) !== [],
                    'connector_fixture_eval_suite' => $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', $flowId) !== [],
                    'flow_live_read_probe_contract' => $this->hub->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', $flowId) !== [],
                    'flow_live_read_probe_evidence_matrix' => $this->hub->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', $flowId) !== [],
                    'runbook_drill' => $this->hub->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', $flowId) !== [],
                    'audit_evidence_requirement' => $this->hub->findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $flowRow = [
                    'schema' => 'atlas.ai.company.enterprise_flow_capability_catalog_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'capability_score' => count($flowGates) > 0 ? round($readyGateCount / count($flowGates), 4) : 0.0,
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_execution_allowed' => false,
                ];
                $flowRow['flow_capability_catalog_record_hash'] = MissionCanonicalHash::sha256($flowRow);
                $flowCapabilities[] = $flowRow;
            }

            $readyFamilyCount = count(array_filter($families, static fn (array $family): bool => (bool) ($family['ready'] ?? false)));
            $readyFlowCount = count(array_filter($flowCapabilities, static fn (array $flow): bool => (bool) ($flow['ready'] ?? false)));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_capability_catalog_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'connector_count' => $connectorCount,
                'metric_count' => $metricCount,
                'work_product_count' => $workProductCount,
                'catalog_ready' => $expectedFlowCount > 0
                    && $readyFamilyCount === count($families)
                    && $readyFlowCount === count($flowCapabilities),
                'catalog_grade' => $expectedFlowCount > 0 && $readyFamilyCount === count($families) && $readyFlowCount === count($flowCapabilities)
                    ? 'target_9_enterprise_company_capability_catalog_external_autonomy_blocked'
                    : 'enterprise_company_capability_catalog_attention_required',
                'ready_family_count' => $readyFamilyCount,
                'required_family_count' => count($families),
                'ready_flow_capability_count' => $readyFlowCount,
                'flow_capability_count' => count($flowCapabilities),
                'catalog_score' => count($families) + count($flowCapabilities) > 0
                    ? round(($readyFamilyCount + $readyFlowCount) / (count($families) + count($flowCapabilities)), 4)
                    : 0.0,
                'families' => $families,
                'missing_families' => array_values(array_keys(array_filter($families, static fn (array $family): bool => ! (bool) ($family['ready'] ?? false)))),
                'flow_capabilities' => $flowCapabilities,
                'active_operating_system_record_hash' => $activeRow['active_operating_system_record_hash'] ?? null,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_autonomy_allowed' => false,
            ];
            $row['company_capability_catalog_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['catalog_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CAPABILITY_CATALOG_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_capability_catalogs_ready_external_autonomy_still_blocked'
                : 'enterprise_company_capability_catalogs_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'catalog_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_family_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_family_count'] ?? 0), $companies)),
                'ready_family_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_family_count'] ?? 0), $companies)),
                'flow_capability_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_capability_count'] ?? 0), $companies)),
                'ready_flow_capability_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_capability_count'] ?? 0), $companies)),
                'average_catalog_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['catalog_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'external_autonomy_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_active_operating_system_status_hash' => $activeOperatingSystem['enterprise_company_active_operating_system_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'capability_catalog_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_autonomy_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['external_customer_commitment', 'external_revenue_claim', 'paid_campaign', 'vendor_purchase', 'capital_transfer', 'trade', 'public_publish', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_capability_catalog_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyIntegrationReadinessStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $capabilityCatalog = $this->enterpriseCompanyCapabilityCatalogStatus($wantedCompany);
        $preflight = $this->hub->realExecutionChain->externalWorkerPreflightStatus($wantedCompany);
        $launchControl = $this->hub->launchReceiptChain->externalLaunchControlStatus($wantedCompany);
        $receiptBinding = $this->hub->launchReceiptChain->externalReceiptBindingStatus($wantedCompany);

        $catalogByCompany = $this->hub->companyRowsById($capabilityCatalog);
        $preflightByCompany = $this->hub->companyRowsById($preflight);
        $launchByCompany = $this->hub->companyRowsById($launchControl);
        $receiptByCompany = $this->hub->companyRowsById($receiptBinding);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values((array) ($company['flows'] ?? []));
            $flowIds = array_values(array_filter(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['id'] ?? $flow['flow_id'] ?? '')
                    : (string) $flow,
                $flows,
            )));
            $expectedFlowCount = count($flowIds);
            $connectorCount = count((array) ($company['connectors'] ?? []));

            $catalogRow = (array) ($catalogByCompany[$id] ?? []);
            $preflightRow = (array) ($preflightByCompany[$id] ?? []);
            $launchRow = (array) ($launchByCompany[$id] ?? []);
            $receiptRow = (array) ($receiptByCompany[$id] ?? []);

            $sourceCatalogs = [
                'sales_crm' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])),
                'support_service_desk' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])),
                'marketing_growth' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])),
                'finance_treasury' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
                'flow_runtime_implementation' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.source_catalog', [])),
                'tooling_research' => count((array) data_get($company, 'enterprise_tooling_research_stack.source_catalog', [])),
                'domain_solution' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_source_catalog', [])),
                'domain_execution_suite' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])),
            ];
            $connectorIntegrations = [
                'provider_connector_matrix' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])),
                'domain_provider_workbenches' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])),
                'vertical_solution_workbenches' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', [])),
                'command_center_panels' => count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])),
                'secret_binding_plans' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])),
                'operations_probe_plans' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])),
                'vendor_due_diligence' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
            ];
            $flowWorkbenchCounts = [
                'sales_account_research' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', [])),
                'support_case_resolution' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', [])),
                'marketing_growth_intelligence' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', [])),
                'finance_research' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', [])),
                'analytics_decision_register' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])),
                'knowledge_learning_loops' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])),
                'data_boundary_matrix' => count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])),
            ];

            $gates = [
                'capability_catalog_ready' => (bool) ($catalogRow['catalog_ready'] ?? false),
                'source_catalogs_ready' => ($sourceCatalogs['sales_crm'] ?? 0) >= 5
                    && ($sourceCatalogs['support_service_desk'] ?? 0) >= 5
                    && ($sourceCatalogs['marketing_growth'] ?? 0) >= 5
                    && ($sourceCatalogs['finance_treasury'] ?? 0) >= 5
                    && ($sourceCatalogs['flow_runtime_implementation'] ?? 0) >= 5
                    && ($sourceCatalogs['tooling_research'] ?? 0) >= 9
                    && ($sourceCatalogs['domain_solution'] ?? 0) >= 5
                    && ($sourceCatalogs['domain_execution_suite'] ?? 0) >= 7,
                'connector_integration_ready' => $connectorCount > 0
                    && ($connectorIntegrations['domain_provider_workbenches'] ?? 0) >= $connectorCount
                    && ($connectorIntegrations['vertical_solution_workbenches'] ?? 0) >= $connectorCount
                    && ($connectorIntegrations['command_center_panels'] ?? 0) >= $connectorCount
                    && ($connectorIntegrations['secret_binding_plans'] ?? 0) >= $connectorCount
                    && ($connectorIntegrations['operations_probe_plans'] ?? 0) >= $connectorCount
                    && ($connectorIntegrations['vendor_due_diligence'] ?? 0) >= $connectorCount,
                'flow_workbenches_ready' => $expectedFlowCount > 0
                    && count(array_filter($flowWorkbenchCounts, static fn (int $count): bool => $count >= $expectedFlowCount)) === count($flowWorkbenchCounts),
                'legal_terms_risk_ready' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                    && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= $connectorCount
                    && count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])) >= $expectedFlowCount,
                'external_worker_preflight_ready' => $expectedFlowCount > 0
                    && (int) ($preflightRow['flow_count'] ?? 0) === $expectedFlowCount
                    && (int) ($preflightRow['worker_preflight_ready_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($preflightRow['external_execution_allowed_count'] ?? 0) === 0,
                'external_launch_control_ready' => $expectedFlowCount > 0
                    && (int) ($launchRow['flow_count'] ?? 0) === $expectedFlowCount
                    && (int) ($launchRow['launch_control_ready_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($launchRow['launch_disabled_count'] ?? 0) >= $expectedFlowCount,
                'external_receipt_binders_ready' => $expectedFlowCount > 0
                    && (int) ($receiptRow['flow_count'] ?? 0) === $expectedFlowCount
                    && (int) ($receiptRow['receipt_binder_ready_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($receiptRow['launch_enabled_count'] ?? 0) === 0,
            ];

            $flowIntegrations = [];
            foreach ($flowIds as $flowId) {
                $flowGates = [
                    'sales_account_research_workbench' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', $flowId) !== [],
                    'support_case_resolution_workbench' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', $flowId) !== [],
                    'marketing_growth_intelligence_workbench' => $this->hub->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', $flowId) !== [],
                    'finance_research_workbench' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', $flowId) !== [],
                    'analytics_decision_record' => $this->hub->findByFlow($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', $flowId) !== [],
                    'knowledge_learning_loop' => $this->hub->findByFlow($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', $flowId) !== [],
                    'data_boundary_record' => $this->hub->findByFlow($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', $flowId) !== [],
                    'audit_evidence_requirement' => $this->hub->findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $flowRow = [
                    'schema' => 'atlas.ai.company.enterprise_flow_integration_readiness_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'integration_ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'integration_score' => count($flowGates) > 0 ? round($readyGateCount / count($flowGates), 4) : 0.0,
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_execution_allowed' => false,
                ];
                $flowRow['flow_integration_readiness_record_hash'] = MissionCanonicalHash::sha256($flowRow);
                $flowIntegrations[] = $flowRow;
            }

            $readyGateCount = count(array_filter($gates));
            $readyFlowCount = count(array_filter($flowIntegrations, static fn (array $flow): bool => (bool) ($flow['integration_ready'] ?? false)));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_integration_readiness_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'connector_count' => $connectorCount,
                'integration_ready' => $expectedFlowCount > 0
                    && $readyGateCount === count($gates)
                    && $readyFlowCount === count($flowIntegrations),
                'integration_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates) && $readyFlowCount === count($flowIntegrations)
                    ? 'target_9_enterprise_integration_ready_external_launch_blocked'
                    : 'enterprise_integration_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'ready_flow_integration_count' => $readyFlowCount,
                'flow_integration_count' => count($flowIntegrations),
                'integration_score' => count($gates) + count($flowIntegrations) > 0
                    ? round(($readyGateCount + $readyFlowCount) / (count($gates) + count($flowIntegrations)), 4)
                    : 0.0,
                'source_catalog_counts' => $sourceCatalogs,
                'connector_integration_counts' => $connectorIntegrations,
                'flow_workbench_counts' => $flowWorkbenchCounts,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'flow_integrations' => $flowIntegrations,
                'company_capability_catalog_record_hash' => $catalogRow['company_capability_catalog_record_hash'] ?? null,
                'company_external_worker_preflight_hash' => $preflightRow['company_external_worker_preflight_hash'] ?? null,
                'company_external_launch_control_hash' => $launchRow['company_external_launch_control_hash'] ?? null,
                'company_external_receipt_binding_hash' => $receiptRow['company_external_receipt_binding_hash'] ?? null,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_integration_readiness_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['integration_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_INTEGRATION_READINESS_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_integrations_ready_external_launch_still_blocked'
                : 'enterprise_company_integrations_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'integration_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'flow_integration_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_integration_count'] ?? 0), $companies)),
                'ready_flow_integration_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_integration_count'] ?? 0), $companies)),
                'average_integration_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['integration_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_capability_catalog_status_hash' => $capabilityCatalog['enterprise_company_capability_catalog_status_hash'] ?? null,
                'external_worker_preflight_status_hash' => $preflight['external_worker_preflight_status_hash'] ?? null,
                'external_launch_control_status_hash' => $launchControl['external_launch_control_status_hash'] ?? null,
                'external_receipt_binding_status_hash' => $receiptBinding['external_receipt_binding_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'integration_readiness_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_launch_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['auto_launch', 'auto_dispatch', 'external_customer_commitment', 'external_revenue_claim', 'paid_campaign', 'vendor_purchase', 'capital_transfer', 'trade', 'public_publish', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_integration_readiness_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
