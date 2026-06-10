<?php

namespace App\Services\Ai\Holding;

use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class AutonomousHoldingReadinessService
{
    public const SCHEMA = 'atlas.ai.autonomous_holding_readiness.v1';

    public const TARGET_SCORE = 9.0;

    public const OBSERVED_HISTORY_REQUIRED_DAYS = 1;

    private const REQUIRED_COMPANIES = 9;

    private const MIN_FUNCTIONS_PER_COMPANY = 4;

    private const MIN_AGENT_ROLES_PER_COMPANY = 5;

    private const MIN_FLOWS_PER_COMPANY = 4;

    private const MIN_DELIVERY_TYPES_PER_COMPANY = 3;

    private const MIN_OBSERVED_WORK_PRODUCTS_PER_COMPANY = self::MIN_DELIVERY_TYPES_PER_COMPANY;

    private const MIN_INTEGRATIONS_PER_COMPANY = 3;

    private const MIN_RECURRING_CADENCES_PER_COMPANY = 3;

    private const MIN_OBSERVED_RECURRING_JOBS_PER_COMPANY = self::MIN_RECURRING_CADENCES_PER_COMPANY;

    private const MIN_METRICS_PER_COMPANY = 4;

    private const MIN_OBSERVED_METRICS_PER_COMPANY = 5;

    private const MIN_OBSERVED_FUNCTION_EXECUTIONS_PER_COMPANY = self::MIN_FUNCTIONS_PER_COMPANY;

    private const MIN_OBSERVED_AGENT_ASSIGNMENTS_PER_COMPANY = self::MIN_AGENT_ROLES_PER_COMPANY;

    private const MIN_OBSERVED_AGENT_SCORECARDS_PER_COMPANY = self::MIN_AGENT_ROLES_PER_COMPANY;

    private const MIN_OBSERVED_FLOW_EXECUTIONS_PER_COMPANY = self::MIN_FLOWS_PER_COMPANY;

    private const MIN_OBSERVED_INTEGRATION_PROBES_PER_COMPANY = 3;

    private const MIN_PROVIDER_CONTRACTS_PER_COMPANY = 5;

    private const MIN_PROVIDER_WORKBENCH_METRICS_PER_COMPANY = 8;

    private const MIN_INDUSTRY_SOLUTION_PROVIDER_CONTRACTS_PER_COMPANY = 5;

    private const MIN_INDUSTRY_SOLUTION_PARTNER_TRACKS_PER_COMPANY = 7;

    private const MIN_INDUSTRY_SOLUTION_METRICS_PER_COMPANY = 8;

    private const MIN_AGENT_REPOSITORY_INTAKE_ITEMS_PER_COMPANY = 9;

    private const MIN_AGENT_REPOSITORY_FRAMEWORK_SCORECARDS_PER_COMPANY = 6;

    private const MIN_AGENT_REPOSITORY_PIPELINE_METRICS_PER_COMPANY = 8;

    private const MIN_DRESS_REHEARSAL_METRICS_PER_COMPANY = 7;

    private const MIN_BUSINESS_BACKBONE_COMPONENTS_PER_COMPANY = 12;

    private const MIN_REPLAY_CASES_PER_FLOW = 25;

    private const MIN_HISTORY_SOURCES_PER_COMPANY = 3;

    private const MIN_RUNTIME_COMMANDS_PER_COMPANY = 3;

    private const MIN_OBSERVED_OPERATING_DAYS = self::OBSERVED_HISTORY_REQUIRED_DAYS;

    /**
     * @var array<string,AiDomainRuntimeRecord|null>
     */
    private array $latestHoldingCycleRecordCache = [];

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $companies = array_map(
            fn (array $manifest): array => $this->companySnapshot($manifest),
            DomainSeedManifests::all(),
        );

        $summary = $this->summary($companies);
        $dimensions = $this->dimensions($summary);
        $score = $this->score($dimensions);
        $companyBuildoutScore = $this->companyBuildoutScore($summary);
        $blockers = $this->blockers($summary, $dimensions);

        return [
            'ok' => $score >= self::TARGET_SCORE && $blockers === [],
            'schema' => self::SCHEMA,
            'generated_at' => Carbon::now()->toIso8601String(),
            'target_score' => self::TARGET_SCORE,
            'current_score' => $score,
            'company_buildout_score' => $companyBuildoutScore,
            'operational_history_score' => $dimensions['observed_history_window']['score'] * 10,
            'status' => $score >= self::TARGET_SCORE && $blockers === [] ? 'target_met' : 'attention',
            'summary' => $summary,
            'dimensions' => $dimensions,
            'companies' => $companies,
            'portfolio_governor' => $this->portfolioGovernor($summary, $blockers),
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($blockers),
            'invariants' => [
                'read_model_only' => true,
                'no_external_provider_call' => true,
                'no_external_tool_execution' => true,
                'no_auto_spend' => true,
                'no_auto_publish' => true,
                'no_live_trading' => true,
                'no_offensive_cyber_execution' => true,
                'decision_receipts_required_before_runtime' => true,
                'evidence_ledger_required_for_execution_claims' => true,
                'target_9_requires_every_company_to_have_real_operating_model' => true,
                'target_9_requires_functions_agents_flows_integrations_recurrence_metrics_history' => true,
                'company_buildout_can_complete_before_calendar_history' => true,
                'calendar_history_blocks_neither_buildout_nor_target_9' => true,
                'target_9_uses_accelerated_operational_evidence' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function companySnapshot(array $manifest): array
    {
        $stage = (int) ($manifest['maturity_stage'] ?? 0);
        $capabilities = (array) ($manifest['capabilities'] ?? []);
        $policy = (array) ($manifest['policy_profile'] ?? []);
        $handoff = (array) ($manifest['handoff_rules'] ?? []);

        $qualityGates = array_values(array_filter((array) ($manifest['quality_gates'] ?? []), 'is_string'));
        $evidenceSchema = array_values(array_filter((array) ($manifest['evidence_schema'] ?? []), 'is_string'));
        $forbidden = array_values(array_filter((array) ($manifest['forbidden_actions'] ?? []), 'is_string'));
        $allowedHandoffs = array_values(array_filter((array) ($handoff['allowed'] ?? []), 'is_string'));
        $functions = array_values(array_filter((array) ($manifest['enterprise_functions'] ?? $manifest['departments'] ?? []), 'is_string'));
        $agents = array_values(array_filter((array) ($manifest['agent_roles'] ?? []), 'is_string'));
        $integrations = array_values(array_filter((array) ($manifest['integration_contracts'] ?? []), 'is_string'));
        $cadences = array_values(array_filter((array) ($manifest['recurring_cadences'] ?? []), 'is_string'));
        $history = array_values(array_filter((array) ($manifest['operational_history'] ?? []), 'is_string'));
        $commands = array_values(array_filter((array) ($manifest['runtime_commands'] ?? []), 'is_string'));
        $registeredCommands = $this->registeredRuntimeCommands($commands);
        $deliveryTypes = array_values(array_filter((array) ($manifest['delivery_types'] ?? []), 'is_string'));
        $routineRuns = $this->latestRoutineRuns((string) ($manifest['domain_id'] ?? 'unknown'));
        $observedMetrics = $this->latestObservedMetrics((string) ($manifest['domain_id'] ?? 'unknown'));
        $observedPacketFlows = $this->latestOperatingPacketFlowProfiles((string) ($manifest['domain_id'] ?? 'unknown'));
        $functionExecutions = $this->latestFunctionExecutions((string) ($manifest['domain_id'] ?? 'unknown'));
        $agentAssignments = $this->latestAgentAssignments((string) ($manifest['domain_id'] ?? 'unknown'));
        $agentOperationalScorecards = $this->latestAgentOperationalScorecards((string) ($manifest['domain_id'] ?? 'unknown'));
        $flowExecutions = $this->latestFlowExecutions((string) ($manifest['domain_id'] ?? 'unknown'));
        $workProducts = $this->latestWorkProducts((string) ($manifest['domain_id'] ?? 'unknown'));
        $recurringJobs = $this->latestRecurringJobs((string) ($manifest['domain_id'] ?? 'unknown'));
        $integrationProbes = $this->latestIntegrationProbes((string) ($manifest['domain_id'] ?? 'unknown'));
        $flowOperationsRunbooks = $this->latestFlowOperationsRunbooks((string) ($manifest['domain_id'] ?? 'unknown'));
        $flowActionRuntimeRecords = $this->latestEnterpriseFlowActionRuntimeRecords((string) ($manifest['domain_id'] ?? 'unknown'));
        $buildoutPacket = $this->enterpriseBuildoutCompany((string) ($manifest['domain_id'] ?? 'unknown'));

        $hasFunctions = count($functions) >= self::MIN_FUNCTIONS_PER_COMPANY;
        $hasObservedFunctionCoverage = count($functionExecutions) >= self::MIN_OBSERVED_FUNCTION_EXECUTIONS_PER_COMPANY
            && count(array_intersect($functions, array_map(
                static fn (array $execution): string => (string) ($execution['function'] ?? ''),
                $functionExecutions,
            ))) === count($functions)
            && collect($functionExecutions)->every(static fn (array $execution): bool => ($execution['schema'] ?? null) === 'atlas.ai.company_function_execution.v1'
                && ($execution['status'] ?? null) === 'observed'
                && ($execution['external_side_effects'] ?? true) === false
                && is_string($execution['work_product_id'] ?? null)
                && is_string($execution['metric_key'] ?? null));
        $hasAgents = count($agents) >= self::MIN_AGENT_ROLES_PER_COMPANY;
        $hasObservedAgentCoverage = count($agentAssignments) >= self::MIN_OBSERVED_AGENT_ASSIGNMENTS_PER_COMPANY
            && count(array_intersect($agents, array_map(
                static fn (array $assignment): string => (string) ($assignment['agent_role'] ?? ''),
                $agentAssignments,
            ))) === count($agents)
            && collect($agentAssignments)->every(static fn (array $assignment): bool => ($assignment['schema'] ?? null) === 'atlas.ai.company_agent_assignment.v1'
                && ($assignment['status'] ?? null) === 'observed'
                && ($assignment['external_side_effects'] ?? true) === false
                && is_string($assignment['owned_function'] ?? null)
                && is_string($assignment['flow_profile'] ?? null)
                && is_string($assignment['metric_key'] ?? null)
                && is_string($assignment['assignment_hash'] ?? null));
        $hasObservedAgentOperationalScorecards = count($agentOperationalScorecards) >= self::MIN_OBSERVED_AGENT_SCORECARDS_PER_COMPANY
            && count(array_intersect($agents, array_map(
                static fn (array $scorecard): string => (string) ($scorecard['agent_role'] ?? ''),
                $agentOperationalScorecards,
            ))) === count($agents)
            && collect($agentOperationalScorecards)->every(static fn (array $scorecard): bool => ($scorecard['schema'] ?? null) === 'atlas.ai.company_agent_operational_scorecard.v1'
                && (bool) ($scorecard['utilization_green'] ?? false)
                && (bool) ($scorecard['review_capacity_green'] ?? false)
                && (bool) ($scorecard['backup_bound'] ?? false)
                && (int) ($scorecard['policy_findings'] ?? 1) === 0
                && ($scorecard['external_side_effects'] ?? true) === false
                && is_string($scorecard['scorecard_hash'] ?? null));
        $manifestFlows = array_values(array_filter((array) ($manifest['flow_profiles'] ?? []), 'is_string'));
        $flows = $observedPacketFlows !== [] ? $observedPacketFlows : $manifestFlows;
        $hasFlows = count($flows) >= self::MIN_FLOWS_PER_COMPANY;
        $hasObservedFlowCoverage = count($flowExecutions) >= self::MIN_OBSERVED_FLOW_EXECUTIONS_PER_COMPANY
            && count(array_intersect($flows, array_map(
                static fn (array $execution): string => (string) ($execution['flow_profile'] ?? ''),
                $flowExecutions,
            ))) === count($flows)
            && collect($flowExecutions)->every(static fn (array $execution): bool => ($execution['schema'] ?? null) === 'atlas.ai.company_flow_execution.v1'
                && ($execution['status'] ?? null) === 'observed'
                && ($execution['external_side_effects'] ?? true) === false
                && is_string($execution['owned_function'] ?? null)
                && is_string($execution['agent_role'] ?? null)
                && is_string($execution['metric_key'] ?? null)
                && is_string($execution['flow_execution_hash'] ?? null));
        $hasDeliveryTypes = count($deliveryTypes) >= self::MIN_DELIVERY_TYPES_PER_COMPANY;
        $hasObservedWorkProductCoverage = count($workProducts) >= self::MIN_OBSERVED_WORK_PRODUCTS_PER_COMPANY
            && count(array_intersect($deliveryTypes, array_map(
                static fn (array $product): string => (string) ($product['kind'] ?? ''),
                $workProducts,
            ))) === count($deliveryTypes)
            && collect($workProducts)->every(static fn (array $product): bool => ($product['schema'] ?? null) === 'atlas.ai.company_work_product.v1'
                && ($product['status'] ?? null) === 'observed'
                && ($product['external_side_effects'] ?? true) === false
                && is_string($product['artifact_id'] ?? null)
                && is_string($product['flow_profile'] ?? null)
                && is_string($product['metric_key'] ?? null)
                && is_string($product['work_product_hash'] ?? null));
        $hasIntegrations = count($integrations) >= self::MIN_INTEGRATIONS_PER_COMPANY;
        $hasRecurringExecution = count($cadences) >= self::MIN_RECURRING_CADENCES_PER_COMPANY;
        $hasObservedRecurringExecution = count($recurringJobs) >= self::MIN_OBSERVED_RECURRING_JOBS_PER_COMPANY
            && count(array_intersect($cadences, array_map(
                static fn (array $job): string => (string) ($job['cadence'] ?? ''),
                $recurringJobs,
            ))) === count($cadences)
            && collect($recurringJobs)->every(static fn (array $job): bool => ($job['schema'] ?? null) === 'atlas.ai.company_recurring_job.v1'
                && ($job['status'] ?? null) === 'observed'
                && ($job['external_side_effects'] ?? true) === false
                && is_string($job['flow_profile'] ?? null)
                && is_string($job['metric_key'] ?? null)
                && is_string($job['recurring_job_hash'] ?? null));
        $hasMetrics = count((array) ($manifest['metrics'] ?? [])) >= self::MIN_METRICS_PER_COMPANY;
        $hasObservedMetrics = count($observedMetrics) >= self::MIN_OBSERVED_METRICS_PER_COMPANY;
        $hasObservedIntegrationProbes = count($integrationProbes) >= self::MIN_OBSERVED_INTEGRATION_PROBES_PER_COMPANY
            && collect($integrationProbes)->every(static fn (array $probe): bool => ($probe['governed'] ?? false) === true
                && ($probe['external_side_effects'] ?? true) === false
                && is_string($probe['contract_hash'] ?? null));
        $greenOperationsRunbooks = array_values(array_filter(
            $flowOperationsRunbooks,
            static fn (array $runbook): bool => ($runbook['operations_green'] ?? false) === true
                || ($runbook['status'] ?? null) === 'observed_green'
                || ($runbook['runbook_status'] ?? null) === 'operations_runbook_green_external_blocked',
        ));
        $operatingPackageBoundRunbooks = array_values(array_filter(
            $flowOperationsRunbooks,
            fn (array $runbook): bool => is_string($runbook['operating_package_hash'] ?? null)
                && strlen((string) $runbook['operating_package_hash']) === 64
                && (int) ($runbook['operating_package_attestation_count'] ?? 0) > 0
                && $this->externalSideEffectsBlocked($runbook),
        ));
        $replayContractBoundRunbooks = array_values(array_filter(
            $flowOperationsRunbooks,
            fn (array $runbook): bool => ($runbook['replay_contract_bound'] ?? false) === true
                && (int) ($runbook['minimum_replay_cases_before_shadow'] ?? 0) >= self::MIN_REPLAY_CASES_PER_FLOW
                && ($runbook['calendar_wait_blocker_enabled'] ?? true) === false
                && $this->externalSideEffectsBlocked($runbook),
        ));
        $hasObservedOperationsRunbookCoverage = count($flows) > 0
            && count($greenOperationsRunbooks) >= count($flows);
        $hasObservedOperatingPackageEvidence = count($flows) > 0
            && count($operatingPackageBoundRunbooks) >= count($flows);
        $hasObservedReplayContractEvidence = count($flows) > 0
            && count($replayContractBoundRunbooks) >= count($flows);
        $validFlowActionRuntimeRecords = array_values(array_filter(
            $flowActionRuntimeRecords,
            static fn (array $record): bool => ($record['runtime_status'] ?? null) === DomainRuntimeRecordService::STATUS_COMPLETED
                && ($record['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_action_run.v1'
                && ($record['status'] ?? null) === 'internal_flow_completed_external_blocked'
                && ($record['mode'] ?? null) === 'internal_enterprise_runtime'
                && ($record['external_side_effects'] ?? true) === false
                && is_string($record['receipt_hash'] ?? null)
                && strlen((string) $record['receipt_hash']) === 64,
        ));
        $hasObservedFlowActionRuntimeCoverage = count($flows) > 0
            && count(array_intersect($flows, array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $validFlowActionRuntimeRecords,
            ))) === count($flows);
        $providerWorkbench = $this->providerWorkbenchReadiness($buildoutPacket);
        $agentRepositoryPipeline = $this->agentRepositoryPipelineReadiness($buildoutPacket);
        $industrySolutionEcosystem = $this->industrySolutionEcosystemReadiness($buildoutPacket);
        $dressRehearsal = $this->dressRehearsalReadiness($buildoutPacket);
        $businessBackbone = $this->enterpriseBusinessBackboneReadiness($buildoutPacket);
        $hasEnterpriseProviderWorkbench = (bool) ($providerWorkbench['ready'] ?? false);
        $hasEnterpriseAgentRepositoryPipeline = (bool) ($agentRepositoryPipeline['ready'] ?? false);
        $hasEnterpriseIndustrySolutionEcosystem = (bool) ($industrySolutionEcosystem['ready'] ?? false);
        $hasEnterpriseOperationalDressRehearsal = (bool) ($dressRehearsal['ready'] ?? false);
        $hasEnterpriseBusinessBackbone = (bool) ($businessBackbone['ready'] ?? false);
        $hasHistory = count($history) >= self::MIN_HISTORY_SOURCES_PER_COMPANY;
        $hasRuntimeCommands = count($registeredCommands) >= self::MIN_RUNTIME_COMMANDS_PER_COMPANY
            && count($registeredCommands) === count($commands);
        $hasRoutineExecution = count($routineRuns) >= 2
            && collect($routineRuns)->every(static fn (array $run): bool => (bool) ($run['ok'] ?? false));
        $observedDays = $this->observedOperatingDays((string) ($manifest['domain_id'] ?? 'unknown'));
        $hasObservedHistoryWindow = $observedDays >= self::MIN_OBSERVED_OPERATING_DAYS;
        $enterpriseStructuralModelComplete = $hasFunctions
            && $hasObservedFunctionCoverage
            && $hasAgents
            && $hasObservedAgentCoverage
            && $hasObservedAgentOperationalScorecards
            && $hasFlows
            && $hasObservedFlowCoverage
            && $hasDeliveryTypes
            && $hasObservedWorkProductCoverage
            && $hasIntegrations
            && $hasObservedIntegrationProbes
            && $hasRecurringExecution
            && $hasObservedRecurringExecution
            && $hasMetrics
            && $hasObservedMetrics
            && $hasEnterpriseProviderWorkbench
            && $hasEnterpriseAgentRepositoryPipeline
            && $hasEnterpriseIndustrySolutionEcosystem
            && $hasEnterpriseBusinessBackbone
            && $hasHistory
            && $hasRuntimeCommands
            && $hasRoutineExecution;
        $enterpriseOperatingModelComplete = $enterpriseStructuralModelComplete
            && $hasObservedOperationsRunbookCoverage
            && $hasObservedOperatingPackageEvidence
            && $hasObservedReplayContractEvidence
            && $hasObservedFlowActionRuntimeCoverage
            && $hasEnterpriseOperationalDressRehearsal;

        return [
            'company_id' => (string) ($manifest['domain_id'] ?? 'unknown'),
            'name' => (string) ($manifest['name'] ?? 'Unknown Company Runtime'),
            'owner' => (string) ($manifest['owner'] ?? 'unknown'),
            'status' => (string) ($manifest['status'] ?? 'unknown'),
            'maturity_stage' => $stage,
            'maturity_label' => $this->stageLabel($stage),
            'autonomy' => (string) ($policy['autonomy'] ?? 'unknown'),
            'risk' => (string) ($policy['risk'] ?? 'unknown'),
            'capability_count' => count($capabilities),
            'department_count' => count((array) ($manifest['departments'] ?? [])),
            'flow_profile_count' => count($flows),
            'manifest_flow_profile_count' => count($manifestFlows),
            'observed_packet_flow_profile_count' => count($observedPacketFlows),
            'observed_flow_execution_count' => count($flowExecutions),
            'observed_flow_action_runtime_record_count' => count($flowActionRuntimeRecords),
            'observed_valid_flow_action_runtime_record_count' => count($validFlowActionRuntimeRecords),
            'observed_operations_runbook_count' => count($flowOperationsRunbooks),
            'observed_operations_runbook_green_count' => count($greenOperationsRunbooks),
            'observed_operating_package_bound_count' => count($operatingPackageBoundRunbooks),
            'observed_replay_contract_bound_count' => count($replayContractBoundRunbooks),
            'provider_contract_count' => (int) ($providerWorkbench['provider_contract_count'] ?? 0),
            'provider_connector_workbench_count' => (int) ($providerWorkbench['connector_workbench_count'] ?? 0),
            'provider_flow_route_count' => (int) ($providerWorkbench['flow_provider_route_count'] ?? 0),
            'provider_eval_case_count' => (int) ($providerWorkbench['provider_eval_case_count'] ?? 0),
            'provider_lineage_count' => (int) ($providerWorkbench['provider_lineage_count'] ?? 0),
            'provider_metric_count' => (int) ($providerWorkbench['provider_metric_count'] ?? 0),
            'agent_repository_intake_count' => (int) ($agentRepositoryPipeline['intake_count'] ?? 0),
            'agent_repository_framework_scorecard_count' => (int) ($agentRepositoryPipeline['framework_scorecard_count'] ?? 0),
            'agent_repository_flow_epic_count' => (int) ($agentRepositoryPipeline['flow_epic_count'] ?? 0),
            'agent_repository_version_pin_count' => (int) ($agentRepositoryPipeline['version_pin_count'] ?? 0),
            'agent_repository_metric_count' => (int) ($agentRepositoryPipeline['metric_count'] ?? 0),
            'industry_solution_provider_count' => (int) ($industrySolutionEcosystem['provider_count'] ?? 0),
            'industry_solution_partner_track_count' => (int) ($industrySolutionEcosystem['partner_track_count'] ?? 0),
            'industry_solution_workload_pack_count' => (int) ($industrySolutionEcosystem['workload_pack_count'] ?? 0),
            'industry_solution_metric_count' => (int) ($industrySolutionEcosystem['metric_count'] ?? 0),
            'dress_rehearsal_flow_runbook_count' => (int) ($dressRehearsal['flow_rehearsal_runbook_count'] ?? 0),
            'dress_rehearsal_live_read_probe_count' => (int) ($dressRehearsal['live_read_probe_count'] ?? 0),
            'dress_rehearsal_operator_acceptance_packet_count' => (int) ($dressRehearsal['operator_acceptance_packet_count'] ?? 0),
            'dress_rehearsal_rollback_drill_count' => (int) ($dressRehearsal['rollback_drill_count'] ?? 0),
            'dress_rehearsal_promotion_evidence_count' => (int) ($dressRehearsal['promotion_evidence_count'] ?? 0),
            'dress_rehearsal_metric_count' => (int) ($dressRehearsal['dress_rehearsal_metric_count'] ?? 0),
            'business_backbone_ready_component_count' => (int) ($businessBackbone['ready_component_count'] ?? 0),
            'business_backbone_required_component_count' => (int) ($businessBackbone['required_component_count'] ?? self::MIN_BUSINESS_BACKBONE_COMPONENTS_PER_COMPANY),
            'business_backbone_check_count' => count((array) ($businessBackbone['checks'] ?? [])),
            'delivery_type_count' => count($deliveryTypes),
            'observed_work_product_count' => count($workProducts),
            'quality_gate_count' => count($qualityGates),
            'evidence_schema_count' => count($evidenceSchema),
            'forbidden_action_count' => count($forbidden),
            'handoff_count' => count($allowedHandoffs),
            'enterprise_function_count' => count($functions),
            'observed_function_execution_count' => count($functionExecutions),
            'agent_role_count' => count($agents),
            'observed_agent_assignment_count' => count($agentAssignments),
            'observed_agent_operational_scorecard_count' => count($agentOperationalScorecards),
            'integration_contract_count' => count($integrations),
            'observed_integration_probe_count' => count($integrationProbes),
            'recurring_cadence_count' => count($cadences),
            'observed_recurring_job_count' => count($recurringJobs),
            'metric_count' => count((array) ($manifest['metrics'] ?? [])),
            'observed_metric_count' => count($observedMetrics),
            'operational_history_source_count' => count($history),
            'runtime_command_count' => count($commands),
            'registered_runtime_command_count' => count($registeredCommands),
            'unregistered_runtime_commands' => array_values(array_diff($commands, $registeredCommands)),
            'routine_run_count' => count($routineRuns),
            'routine_run_success_count' => count(array_filter(
                $routineRuns,
                static fn (array $run): bool => (bool) ($run['ok'] ?? false),
            )),
            'operating_review_days' => $observedDays,
            'has_policy_profile' => $policy !== [],
            'has_safety_boundary' => $forbidden !== [] && $qualityGates !== [],
            'has_evidence_contract' => $evidenceSchema !== [],
            'has_cross_domain_handoff' => $allowedHandoffs !== [],
            'has_enterprise_functions' => $hasFunctions,
            'has_observed_function_coverage' => $hasObservedFunctionCoverage,
            'has_specialized_agents' => $hasAgents,
            'has_observed_agent_coverage' => $hasObservedAgentCoverage,
            'has_observed_agent_operational_scorecards' => $hasObservedAgentOperationalScorecards,
            'has_company_flows' => $hasFlows,
            'has_observed_flow_coverage' => $hasObservedFlowCoverage,
            'has_observed_flow_action_runtime_coverage' => $hasObservedFlowActionRuntimeCoverage,
            'has_observed_operations_runbook_coverage' => $hasObservedOperationsRunbookCoverage,
            'has_observed_operating_package_evidence' => $hasObservedOperatingPackageEvidence,
            'has_observed_replay_contract_evidence' => $hasObservedReplayContractEvidence,
            'has_enterprise_provider_workbench' => $hasEnterpriseProviderWorkbench,
            'has_enterprise_agent_repository_pipeline' => $hasEnterpriseAgentRepositoryPipeline,
            'has_enterprise_industry_solution_ecosystem' => $hasEnterpriseIndustrySolutionEcosystem,
            'has_enterprise_operational_dress_rehearsal' => $hasEnterpriseOperationalDressRehearsal,
            'has_enterprise_business_operating_backbone' => $hasEnterpriseBusinessBackbone,
            'has_delivery_work_products' => $hasDeliveryTypes,
            'has_observed_work_product_coverage' => $hasObservedWorkProductCoverage,
            'has_integration_contracts' => $hasIntegrations,
            'has_observed_integration_probes' => $hasObservedIntegrationProbes,
            'has_recurring_execution' => $hasRecurringExecution,
            'has_observed_recurring_execution' => $hasObservedRecurringExecution,
            'has_metric_system' => $hasMetrics,
            'has_observed_metric_system' => $hasObservedMetrics,
            'has_operational_history' => $hasHistory,
            'has_observed_history_window' => $hasObservedHistoryWindow,
            'has_runtime_command_surface' => $hasRuntimeCommands,
            'has_routine_execution' => $hasRoutineExecution,
            'enterprise_structural_model_complete' => $enterpriseStructuralModelComplete,
            'enterprise_operating_model_complete' => $enterpriseOperatingModelComplete,
            'target_9_claim_ready' => $enterpriseOperatingModelComplete && $hasObservedHistoryWindow,
            'execution_claim' => $stage >= DomainSeedManifests::STAGE_OPERATING_UNIT
                ? 'supervised_execution_candidate'
                : 'proposal_or_read_model',
            'target_9_gap' => $this->targetGap($stage, $hasObservedHistoryWindow),
        ];
    }

    /**
     * @param list<array<string,mixed>> $companies
     * @return array<string,mixed>
     */
    private function summary(array $companies): array
    {
        $active = array_values(array_filter(
            $companies,
            static fn (array $company): bool => ($company['status'] ?? null) === 'active',
        ));

        return [
            'company_count' => count($companies),
            'active_company_count' => count($active),
            'specialist_or_better_count' => $this->countByMinimumStage(
                $companies,
                DomainSeedManifests::STAGE_SPECIALIST,
            ),
            'department_or_better_count' => $this->countByMinimumStage(
                $companies,
                DomainSeedManifests::STAGE_DEPARTMENT,
            ),
            'supervised_execution_company_count' => $this->countByMinimumStage(
                $companies,
                DomainSeedManifests::STAGE_OPERATING_UNIT,
            ),
            'limited_autonomy_company_count' => $this->countByMinimumStage(
                $companies,
                DomainSeedManifests::STAGE_AUTONOMOUS_ENTERPRISE_UNIT,
            ),
            'enterprise_operating_model_complete_count' => $this->countByFlag(
                $companies,
                'enterprise_operating_model_complete',
            ),
            'enterprise_structural_model_complete_count' => $this->countByFlag(
                $companies,
                'enterprise_structural_model_complete',
            ),
            'target_9_claim_ready_company_count' => $this->countByFlag(
                $companies,
                'target_9_claim_ready',
            ),
            'company_with_enterprise_functions_count' => $this->countByFlag($companies, 'has_enterprise_functions'),
            'company_with_observed_function_coverage_count' => $this->countByFlag($companies, 'has_observed_function_coverage'),
            'company_with_specialized_agents_count' => $this->countByFlag($companies, 'has_specialized_agents'),
            'company_with_observed_agent_coverage_count' => $this->countByFlag($companies, 'has_observed_agent_coverage'),
            'company_with_observed_agent_operational_scorecards_count' => $this->countByFlag($companies, 'has_observed_agent_operational_scorecards'),
            'company_with_company_flows_count' => $this->countByFlag($companies, 'has_company_flows'),
            'company_with_observed_flow_coverage_count' => $this->countByFlag($companies, 'has_observed_flow_coverage'),
            'company_with_observed_flow_action_runtime_coverage_count' => $this->countByFlag($companies, 'has_observed_flow_action_runtime_coverage'),
            'company_with_observed_operations_runbook_coverage_count' => $this->countByFlag($companies, 'has_observed_operations_runbook_coverage'),
            'company_with_observed_operating_package_evidence_count' => $this->countByFlag($companies, 'has_observed_operating_package_evidence'),
            'company_with_observed_replay_contract_evidence_count' => $this->countByFlag($companies, 'has_observed_replay_contract_evidence'),
            'company_with_enterprise_provider_workbench_count' => $this->countByFlag($companies, 'has_enterprise_provider_workbench'),
            'company_with_enterprise_agent_repository_pipeline_count' => $this->countByFlag($companies, 'has_enterprise_agent_repository_pipeline'),
            'company_with_enterprise_industry_solution_ecosystem_count' => $this->countByFlag($companies, 'has_enterprise_industry_solution_ecosystem'),
            'company_with_enterprise_operational_dress_rehearsal_count' => $this->countByFlag($companies, 'has_enterprise_operational_dress_rehearsal'),
            'company_with_enterprise_business_operating_backbone_count' => $this->countByFlag($companies, 'has_enterprise_business_operating_backbone'),
            'company_with_delivery_work_products_count' => $this->countByFlag($companies, 'has_delivery_work_products'),
            'company_with_observed_work_product_coverage_count' => $this->countByFlag($companies, 'has_observed_work_product_coverage'),
            'company_with_integration_contracts_count' => $this->countByFlag($companies, 'has_integration_contracts'),
            'company_with_observed_integration_probe_count' => $this->countByFlag($companies, 'has_observed_integration_probes'),
            'company_with_recurring_execution_count' => $this->countByFlag($companies, 'has_recurring_execution'),
            'company_with_observed_recurring_execution_count' => $this->countByFlag($companies, 'has_observed_recurring_execution'),
            'company_with_metric_system_count' => $this->countByFlag($companies, 'has_metric_system'),
            'company_with_observed_metric_system_count' => $this->countByFlag($companies, 'has_observed_metric_system'),
            'company_with_operational_history_count' => $this->countByFlag($companies, 'has_operational_history'),
            'company_with_observed_history_window_count' => $this->countByFlag($companies, 'has_observed_history_window'),
            'company_with_runtime_command_surface_count' => $this->countByFlag($companies, 'has_runtime_command_surface'),
            'company_with_routine_execution_count' => $this->countByFlag($companies, 'has_routine_execution'),
            'company_with_capabilities_count' => count(array_filter(
                $companies,
                static fn (array $company): bool => (int) ($company['capability_count'] ?? 0) > 0,
            )),
            'company_with_safety_boundary_count' => count(array_filter(
                $companies,
                static fn (array $company): bool => (bool) ($company['has_safety_boundary'] ?? false),
            )),
            'company_with_evidence_contract_count' => count(array_filter(
                $companies,
                static fn (array $company): bool => (bool) ($company['has_evidence_contract'] ?? false),
            )),
            'company_with_cross_domain_handoff_count' => count(array_filter(
                $companies,
                static fn (array $company): bool => (bool) ($company['has_cross_domain_handoff'] ?? false),
            )),
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function dimensions(array $summary): array
    {
        $companyCount = max(1, (int) $summary['company_count']);

        return [
            'company_registry' => [
                'score' => $this->ratioScore((int) $summary['active_company_count'], self::REQUIRED_COMPANIES),
                'evidence' => 'all nine company runtimes active in DomainSeedManifests',
            ],
            'capability_contracts' => [
                'score' => $this->ratioScore((int) $summary['company_with_capabilities_count'], $companyCount),
                'evidence' => 'each company declares at least one capability contract',
            ],
            'safety_boundaries' => [
                'score' => $this->ratioScore((int) $summary['company_with_safety_boundary_count'], $companyCount),
                'evidence' => 'quality gates and forbidden actions declared per company',
            ],
            'evidence_contracts' => [
                'score' => $this->ratioScore((int) $summary['company_with_evidence_contract_count'], $companyCount),
                'evidence' => 'evidence_schema declared per company',
            ],
            'cross_domain_handoffs' => [
                'score' => $this->ratioScore((int) $summary['company_with_cross_domain_handoff_count'], $companyCount),
                'evidence' => 'handoff_rules.allowed declared per company',
            ],
            'enterprise_functions' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_functions_count'], $companyCount),
                'evidence' => 'each company declares at least four business functions/departments it owns',
            ],
            'observed_function_coverage' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_function_coverage_count'], $companyCount),
                'evidence' => 'each company has observed execution coverage for every declared enterprise function in the latest holding packet',
            ],
            'specialized_agents' => [
                'score' => $this->ratioScore((int) $summary['company_with_specialized_agents_count'], $companyCount),
                'evidence' => 'each company declares at least five specialized agent roles',
            ],
            'observed_agent_coverage' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_agent_coverage_count'], $companyCount),
                'evidence' => 'each company has observed assignment coverage for every specialized agent in the latest holding packet',
            ],
            'observed_agent_operational_scorecards' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_agent_operational_scorecards_count'], $companyCount),
                'evidence' => 'each company has green capacity, backup, review and policy scorecards for every specialized agent in the latest holding packet',
            ],
            'company_flows' => [
                'score' => $this->ratioScore((int) $summary['company_with_company_flows_count'], $companyCount),
                'evidence' => 'each company exposes at least four own operating flows',
            ],
            'observed_flow_coverage' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_flow_coverage_count'], $companyCount),
                'evidence' => 'each company has observed execution coverage for every declared flow profile in the latest holding packet',
            ],
            'observed_flow_action_runtime' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_flow_action_runtime_coverage_count'], $companyCount),
                'evidence' => 'each company has completed persisted enterprise_flow_action runtime records for every declared flow',
            ],
            'observed_operations_runbooks' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_operations_runbook_coverage_count'], $companyCount),
                'evidence' => 'each company has green flow operations runbook evidence for every declared flow in the latest holding packet',
            ],
            'observed_operating_package_evidence' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_operating_package_evidence_count'], $companyCount),
                'evidence' => 'each company has package-bound operating evidence and attestations for every declared flow',
            ],
            'observed_replay_contract_evidence' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_replay_contract_evidence_count'], $companyCount),
                'evidence' => 'each company has replay-bound flow evidence with at least 25 cases and no calendar wait blocker',
            ],
            'enterprise_provider_workbenches' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_provider_workbench_count'], $companyCount),
                'evidence' => 'each company has provider contracts, connector workbenches, flow routes, eval cases, lineage and no calendar wait blocker',
            ],
            'enterprise_agent_repository_pipelines' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_agent_repository_pipeline_count'], $companyCount),
                'evidence' => 'each company has reviewed framework/domain repositories translated into intake items, scorecards, flow epics, version pins, migration plans and observability',
            ],
            'enterprise_industry_solution_ecosystems' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_industry_solution_ecosystem_count'], $companyCount),
                'evidence' => 'each company has a Claude Financial Services style solution ecosystem with data interface, providers, partner tracks, workload packs, audit and confidentiality controls',
            ],
            'enterprise_operational_dress_rehearsal' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_operational_dress_rehearsal_count'], $companyCount),
                'evidence' => 'each company has flow rehearsal runbooks, live-read probes, operator acceptance packets, rollback drills and promotion evidence',
            ],
            'enterprise_business_operating_backbone' => [
                'score' => $this->ratioScore((int) $summary['company_with_enterprise_business_operating_backbone_count'], $companyCount),
                'evidence' => 'each company has customer, account, vendor/legal/procurement, resilience, analytics, knowledge, identity, control tower, semantic graph, delivery, unit economics and GRC backbone gates',
            ],
            'delivery_work_products' => [
                'score' => $this->ratioScore((int) $summary['company_with_delivery_work_products_count'], $companyCount),
                'evidence' => 'each company exposes at least three company-specific work products it can produce',
            ],
            'observed_work_product_coverage' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_work_product_coverage_count'], $companyCount),
                'evidence' => 'each company has observed work product coverage for every declared delivery type in the latest holding packet',
            ],
            'integration_contracts' => [
                'score' => $this->ratioScore((int) $summary['company_with_integration_contracts_count'], $companyCount),
                'evidence' => 'each company declares at least three governed integrations or read adapters',
            ],
            'observed_integration_probes' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_integration_probe_count'], $companyCount),
                'evidence' => 'each company has at least three recent governed integration probes in the latest holding packet',
            ],
            'recurring_execution' => [
                'score' => $this->ratioScore((int) $summary['company_with_recurring_execution_count'], $companyCount),
                'evidence' => 'each company declares at least three recurring execution cadences',
            ],
            'observed_recurring_execution' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_recurring_execution_count'], $companyCount),
                'evidence' => 'each company has observed recurring job coverage for every declared cadence in the latest holding packet',
            ],
            'metric_system' => [
                'score' => $this->ratioScore((int) $summary['company_with_metric_system_count'], $companyCount),
                'evidence' => 'each company declares at least four operating metrics',
            ],
            'observed_metric_system' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_metric_system_count'], $companyCount),
                'evidence' => 'each company has at least five observed operating metrics in the latest holding packet',
            ],
            'operational_history' => [
                'score' => $this->ratioScore((int) $summary['company_with_operational_history_count'], $companyCount),
                'evidence' => 'each company declares at least three history sources or control-plane ledgers',
            ],
            'observed_history_window' => [
                'score' => $this->ratioScore((int) $summary['company_with_observed_history_window_count'], $companyCount),
                'evidence' => 'each company must have at least one current observed operating cycle with complete governed execution evidence before target 9',
            ],
            'runtime_command_surfaces' => [
                'score' => $this->ratioScore((int) $summary['company_with_runtime_command_surface_count'], $companyCount),
                'evidence' => 'each company exposes readiness, smoke/control-plane and operating command surfaces',
            ],
            'routine_execution' => [
                'score' => $this->ratioScore((int) $summary['company_with_routine_execution_count'], $companyCount),
                'evidence' => 'each company has at least two successful routine runs captured in the holding operating packet',
            ],
            'supervised_execution' => [
                'score' => $this->ratioScore((int) $summary['supervised_execution_company_count'], self::REQUIRED_COMPANIES),
                'evidence' => 'target 9 real requires every company to be at supervised operating unit or better',
            ],
            'limited_autonomy' => [
                'score' => $this->ratioScore((int) $summary['limited_autonomy_company_count'], 1),
                'evidence' => 'target 9 real requires at least one bounded autonomous enterprise unit',
            ],
            'complete_company_operating_models' => [
                'score' => $this->ratioScore((int) $summary['enterprise_operating_model_complete_count'], $companyCount),
                'evidence' => 'every company must satisfy functions, agents, flows, persisted flow action runtime, runbooks, operating packages, replay contracts, work products, integrations, recurrence, observed metrics and command surfaces',
            ],
            'complete_company_structural_models' => [
                'score' => $this->ratioScore((int) $summary['enterprise_structural_model_complete_count'], $companyCount),
                'evidence' => 'every company has complete buildout evidence before the accelerated maturity claim gate',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function companyBuildoutScore(array $summary): float
    {
        return round($this->ratioScore(
            (int) $summary['enterprise_structural_model_complete_count'],
            max(1, (int) $summary['company_count']),
        ) * 10.0, 1);
    }

    /**
     * @param array<string,array<string,mixed>> $dimensions
     */
    private function score(array $dimensions): float
    {
        $weights = [
            'company_registry' => 1.0,
            'capability_contracts' => 0.5,
            'safety_boundaries' => 1.0,
            'evidence_contracts' => 0.75,
            'cross_domain_handoffs' => 0.75,
            'enterprise_functions' => 1.0,
            'observed_function_coverage' => 1.5,
            'specialized_agents' => 1.5,
            'observed_agent_coverage' => 1.5,
            'observed_agent_operational_scorecards' => 1.5,
            'company_flows' => 1.0,
            'observed_flow_coverage' => 1.5,
            'observed_flow_action_runtime' => 1.5,
            'observed_operations_runbooks' => 1.5,
            'observed_operating_package_evidence' => 1.5,
            'observed_replay_contract_evidence' => 1.5,
            'enterprise_provider_workbenches' => 1.5,
            'enterprise_agent_repository_pipelines' => 1.25,
            'enterprise_industry_solution_ecosystems' => 1.5,
            'enterprise_operational_dress_rehearsal' => 1.5,
            'enterprise_business_operating_backbone' => 1.75,
            'delivery_work_products' => 1.0,
            'observed_work_product_coverage' => 1.5,
            'integration_contracts' => 1.25,
            'observed_integration_probes' => 1.25,
            'recurring_execution' => 1.25,
            'observed_recurring_execution' => 1.5,
            'metric_system' => 1.0,
            'observed_metric_system' => 1.25,
            'operational_history' => 1.5,
            'observed_history_window' => 2.0,
            'runtime_command_surfaces' => 1.25,
            'routine_execution' => 1.25,
            'supervised_execution' => 2.0,
            'limited_autonomy' => 0.75,
            'complete_company_operating_models' => 2.5,
        ];

        $weighted = 0.0;
        $total = 0.0;
        foreach ($weights as $key => $weight) {
            $weighted += (float) ($dimensions[$key]['score'] ?? 0.0) * $weight;
            $total += $weight;
        }

        return round($weighted / max(1.0, $total) * 10.0, 1);
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,array<string,mixed>> $dimensions
     * @return list<array<string,mixed>>
     */
    private function blockers(array $summary, array $dimensions): array
    {
        $blockers = [];

        if ((int) $summary['supervised_execution_company_count'] < self::REQUIRED_COMPANIES) {
            $blockers[] = [
                'id' => 'promote_every_company_to_operating_unit',
                'severity' => 'critical',
                'current' => (int) $summary['supervised_execution_company_count'],
                'required' => self::REQUIRED_COMPANIES,
                'repair' => 'Promote every company through receipts, evidence, benchmarks, operating commands and supervised execution gates.',
            ];
        }

        if ((int) $summary['limited_autonomy_company_count'] < 1) {
            $blockers[] = [
                'id' => 'promote_one_company_to_limited_autonomy',
                'severity' => 'critical',
                'current' => (int) $summary['limited_autonomy_company_count'],
                'required' => 1,
                'repair' => 'Promote one company to bounded autonomy with budget, stop conditions, rollback and review packet.',
            ];
        }

        if ((int) $summary['enterprise_operating_model_complete_count'] < (int) $summary['company_count']) {
            $blockers[] = [
                'id' => 'complete_every_company_operating_model',
                'severity' => 'critical',
                'current' => (int) $summary['enterprise_operating_model_complete_count'],
                'required' => (int) $summary['company_count'],
                'repair' => 'Each company must have functions, specialized agents, own flows, work products, integrations, recurrence, metrics, history and command surfaces.',
            ];
        }

        foreach ($dimensions as $id => $dimension) {
            if ((float) ($dimension['score'] ?? 0.0) < 1.0) {
                $blockers[] = [
                    'id' => 'dimension_incomplete:'.$id,
                    'severity' => in_array($id, ['supervised_execution', 'limited_autonomy'], true) ? 'critical' : 'high',
                    'current' => (float) ($dimension['score'] ?? 0.0),
                    'required' => 1.0,
                    'repair' => 'Complete dimension evidence: '.(string) ($dimension['evidence'] ?? $id),
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $blockers
     * @return array<string,mixed>
     */
    private function portfolioGovernor(array $summary, array $blockers): array
    {
        return [
            'mode' => 'read_model_advisory',
            'promotion_allowed' => $blockers === [],
            'capital_allocation_allowed' => false,
            'autonomous_external_execution_allowed' => false,
            'recommended_first_promotions' => [
                'marketing',
                'research',
                'finance',
            ],
            'minimum_target_9_shape' => [
                'active_companies' => self::REQUIRED_COMPANIES,
                'supervised_execution_companies' => self::REQUIRED_COMPANIES,
                'limited_autonomy_companies' => 1,
                'complete_company_operating_models' => self::REQUIRED_COMPANIES,
                'current_active_companies' => (int) $summary['active_company_count'],
                'current_supervised_execution_companies' => (int) $summary['supervised_execution_company_count'],
                'current_limited_autonomy_companies' => (int) $summary['limited_autonomy_company_count'],
                'current_complete_company_operating_models' => (int) $summary['enterprise_operating_model_complete_count'],
                'current_complete_company_structural_models' => (int) $summary['enterprise_structural_model_complete_count'],
                'current_target_9_claim_ready_companies' => (int) $summary['target_9_claim_ready_company_count'],
                'current_companies_with_persisted_flow_action_runtime' => (int) $summary['company_with_observed_flow_action_runtime_coverage_count'],
                'current_companies_with_operations_runbook_evidence' => (int) $summary['company_with_observed_operations_runbook_coverage_count'],
                'current_companies_with_operating_package_evidence' => (int) $summary['company_with_observed_operating_package_evidence_count'],
                'current_companies_with_replay_contract_evidence' => (int) $summary['company_with_observed_replay_contract_evidence_count'],
                'current_companies_with_provider_workbenches' => (int) $summary['company_with_enterprise_provider_workbench_count'],
                'current_companies_with_operational_dress_rehearsal' => (int) $summary['company_with_enterprise_operational_dress_rehearsal_count'],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $blockers
     * @return list<string>
     */
    private function nextActions(array $blockers): array
    {
        if ($blockers === []) {
            return ['run_external_rivals_and_supervised_operating_review_before_claiming_10'];
        }

        return array_values(array_unique(array_map(
            static fn (array $blocker): string => (string) ($blocker['repair'] ?? $blocker['id']),
            $blockers,
        )));
    }

    private function ratioScore(int $current, int $required): float
    {
        return round(min(1.0, $current / max(1, $required)), 2);
    }

    /**
     * @param list<array<string,mixed>> $companies
     */
    private function countByMinimumStage(array $companies, int $minimumStage): int
    {
        return count(array_filter(
            $companies,
            static fn (array $company): bool => (int) ($company['maturity_stage'] ?? 0) >= $minimumStage,
        ));
    }

    /**
     * @param list<array<string,mixed>> $companies
     */
    private function countByFlag(array $companies, string $flag): int
    {
        return count(array_filter(
            $companies,
            static fn (array $company): bool => (bool) ($company[$flag] ?? false),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function enterpriseBuildoutCompany(string $domainId): array
    {
        try {
            return app(AutonomousHoldingEnterpriseBuildoutService::class)->companyPacket($domainId);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function providerWorkbenchReadiness(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_domain_provider_workbench_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $providerContracts = (array) data_get($stack, 'provider_contracts', []);
        $connectorWorkbenches = (array) data_get($stack, 'connector_workbenches', []);
        $flowRoutes = (array) data_get($stack, 'flow_provider_routes', []);
        $evalCases = (array) data_get($stack, 'provider_evaluation_cases', []);
        $lineage = (array) data_get($stack, 'provider_data_product_lineage', []);
        $metrics = (array) data_get($stack, 'provider_workbench_observability.required_metrics', []);

        $checks = [
            'provider_contracts' => count($providerContracts) >= self::MIN_PROVIDER_CONTRACTS_PER_COMPANY,
            'connector_workbenches' => count($connectorWorkbenches) >= $connectorCount && $connectorCount > 0,
            'flow_provider_routes' => count($flowRoutes) >= $flowCount && $flowCount > 0,
            'provider_evaluation_cases' => count($evalCases) >= $flowCount && $flowCount > 0,
            'provider_lineage' => count($lineage) >= self::MIN_PROVIDER_CONTRACTS_PER_COMPANY,
            'observability' => count($metrics) >= self::MIN_PROVIDER_WORKBENCH_METRICS_PER_COMPANY,
            'calendar_wait_removed' => (bool) data_get($stack, 'workbench_policy.calendar_wait_blocker_enabled', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'workbench_policy.provider_write_or_paid_action_default', true) === false,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'provider_contract_count' => count($providerContracts),
            'connector_workbench_count' => count($connectorWorkbenches),
            'flow_provider_route_count' => count($flowRoutes),
            'provider_eval_case_count' => count($evalCases),
            'provider_lineage_count' => count($lineage),
            'provider_metric_count' => count($metrics),
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function agentRepositoryPipelineReadiness(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_agent_repository_adoption_pipeline', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $intake = (array) data_get($stack, 'repository_intake_queue', []);
        $scorecards = (array) data_get($stack, 'framework_adoption_scorecards', []);
        $flowEpics = (array) data_get($stack, 'flow_repository_implementation_epics', []);
        $versionPins = (array) data_get($stack, 'version_pin_and_supply_chain_plan', []);
        $metrics = (array) data_get($stack, 'pipeline_observability.required_metrics', []);

        $checks = [
            'repository_intake' => count($intake) >= self::MIN_AGENT_REPOSITORY_INTAKE_ITEMS_PER_COMPANY,
            'framework_scorecards' => count($scorecards) >= self::MIN_AGENT_REPOSITORY_FRAMEWORK_SCORECARDS_PER_COMPANY,
            'flow_epics' => count($flowEpics) >= $flowCount && $flowCount > 0,
            'version_pins' => count($versionPins) >= self::MIN_AGENT_REPOSITORY_INTAKE_ITEMS_PER_COMPANY,
            'observability' => count($metrics) >= self::MIN_AGENT_REPOSITORY_PIPELINE_METRICS_PER_COMPANY,
            'review_gate' => (bool) data_get($stack, 'pipeline_policy.adoption_requires_license_security_sbo_m_fixture_eval_and_operator_acceptance', false),
            'migration_gate' => (bool) data_get($stack, 'pipeline_policy.maintenance_mode_or_deprecation_requires_migration_plan', false),
            'runtime_before_tests_blocked' => (bool) data_get($stack, 'pipeline_policy.runtime_use_before_local_contract_tests_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'pipeline_policy.external_side_effects_enabled', true) === false,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'intake_count' => count($intake),
            'framework_scorecard_count' => count($scorecards),
            'flow_epic_count' => count($flowEpics),
            'version_pin_count' => count($versionPins),
            'metric_count' => count($metrics),
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function industrySolutionEcosystemReadiness(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_industry_solution_ecosystem_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectors = (array) ($company['connectors'] ?? []);
        $providers = (array) data_get($stack, 'ecosystem_provider_catalog', []);
        $partnerTracks = (array) data_get($stack, 'implementation_partner_tracks', []);
        $workloadPacks = (array) data_get($stack, 'flow_solution_workload_packs', []);
        $sourceVerificationMatrix = (array) data_get($stack, 'flow_source_verification_matrix', []);
        $complianceWorkloadControls = (array) data_get($stack, 'flow_compliance_workload_controls', []);
        $partnerHandoffs = (array) data_get($stack, 'implementation_partner_handoff_matrix', []);
        $metrics = (array) data_get($stack, 'ecosystem_observability.required_metrics', []);

        $checks = [
            'unified_data_interface' => data_get($stack, 'industry_data_interface.schema') === 'atlas.ai.company.industry_data_interface.v1'
                && count((array) data_get($stack, 'industry_data_interface.connector_ids', [])) >= count($connectors)
                && count($connectors) > 0,
            'provider_catalog' => count($providers) >= self::MIN_INDUSTRY_SOLUTION_PROVIDER_CONTRACTS_PER_COMPANY,
            'implementation_partner_tracks' => count($partnerTracks) >= self::MIN_INDUSTRY_SOLUTION_PARTNER_TRACKS_PER_COMPANY,
            'flow_solution_workload_packs' => count($workloadPacks) >= $flowCount && $flowCount > 0,
            'flow_source_verification_matrix' => count($sourceVerificationMatrix) >= $flowCount && $flowCount > 0,
            'flow_compliance_workload_controls' => count($complianceWorkloadControls) >= $flowCount && $flowCount > 0,
            'implementation_partner_handoffs' => count($partnerHandoffs) >= $flowCount && $flowCount > 0,
            'observability' => count($metrics) >= self::MIN_INDUSTRY_SOLUTION_METRICS_PER_COMPANY,
            'source_links_required' => (bool) data_get($stack, 'ecosystem_policy.direct_source_hyperlinks_required', false),
            'mcp_or_api_workbench_required' => (bool) data_get($stack, 'ecosystem_policy.mcp_or_api_connector_workbench_required', false),
            'audit_trail_required' => (bool) data_get($stack, 'ecosystem_policy.audit_trail_required_for_every_claim_and_artifact', false),
            'confidentiality_controls' => (bool) data_get($stack, 'audit_and_confidentiality_controls.client_or_private_data_training_exclusion_attestation_required', false),
            'external_effects_blocked' => (bool) data_get($stack, 'ecosystem_policy.external_side_effects_enabled', true) === false
                && (bool) data_get($stack, 'enterprise_adoption_program.external_contracting_allowed_by_stack', true) === false,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'provider_count' => count($providers),
            'partner_track_count' => count($partnerTracks),
            'workload_pack_count' => count($workloadPacks),
            'source_verification_matrix_count' => count($sourceVerificationMatrix),
            'compliance_workload_control_count' => count($complianceWorkloadControls),
            'partner_handoff_count' => count($partnerHandoffs),
            'metric_count' => count($metrics),
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function dressRehearsalReadiness(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_operational_dress_rehearsal_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $runbooks = (array) data_get($stack, 'flow_rehearsal_runbooks', []);
        $liveProbes = (array) data_get($stack, 'live_read_probe_plan', []);
        $acceptance = (array) data_get($stack, 'operator_acceptance_packets', []);
        $rollback = (array) data_get($stack, 'rollback_drill_matrix', []);
        $promotion = (array) data_get($stack, 'promotion_evidence_matrix', []);
        $metrics = (array) data_get($stack, 'dress_rehearsal_observability.required_metrics', []);

        $checks = [
            'flow_rehearsal_runbooks' => count($runbooks) >= $flowCount && $flowCount > 0,
            'live_read_probes' => count($liveProbes) >= $connectorCount && $connectorCount > 0,
            'operator_acceptance_packets' => count($acceptance) >= $flowCount && $flowCount > 0,
            'rollback_drills' => count($rollback) >= $flowCount && $flowCount > 0,
            'promotion_evidence' => count($promotion) >= $flowCount && $flowCount > 0,
            'observability' => count($metrics) >= self::MIN_DRESS_REHEARSAL_METRICS_PER_COMPANY,
            'calendar_wait_removed' => (bool) data_get($stack, 'rehearsal_policy.calendar_wait_blocker_enabled', true) === false,
            'external_mutation_blocked' => (bool) data_get($stack, 'rehearsal_policy.external_mutation_allowed_during_rehearsal', true) === false,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_rehearsal_runbook_count' => count($runbooks),
            'live_read_probe_count' => count($liveProbes),
            'operator_acceptance_packet_count' => count($acceptance),
            'rollback_drill_count' => count($rollback),
            'promotion_evidence_count' => count($promotion),
            'dress_rehearsal_metric_count' => count($metrics),
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function enterpriseBusinessBackboneReadiness(array $company): array
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
            'customer_market_operations' => data_get($company, 'enterprise_customer_market_operations_stack.schema') === 'atlas.ai.company.enterprise_customer_market_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                && (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_operations_guardrails.external_side_effects_default', true) === false,
            'account_contract_delivery' => data_get($company, 'enterprise_account_contract_delivery_stack.schema') === 'atlas.ai.company.enterprise_account_contract_delivery_stack.v1'
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])) >= 5
                && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.account_operations_policy.external_billing_allowed', true) === false,
            'vendor_legal_procurement' => data_get($company, 'enterprise_vendor_legal_procurement_stack.schema') === 'atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1'
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= $connectorCount
                && data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_policy.purchase_authority') === 'operator_only_for_real_spend',
            'resilience_continuity' => data_get($company, 'enterprise_resilience_continuity_stack.schema') === 'atlas.ai.company.enterprise_resilience_continuity_stack.v1'
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.resilience_observability.required_metrics', [])) >= 5
                && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false,
            'analytics_decision_intelligence' => data_get($company, 'enterprise_analytics_decision_intelligence_stack.schema') === 'atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1'
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= $metricCount
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.scenario_and_forecast_model', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.synthetic_scores_allowed', true) === false,
            'knowledge_memory_learning' => data_get($company, 'enterprise_knowledge_memory_learning_stack.schema') === 'atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1'
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.postmortem_and_retrospective_program', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.playbook_change_control', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false,
            'identity_access_data_sovereignty' => data_get($company, 'enterprise_identity_access_data_sovereignty_stack.schema') === 'atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1'
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sensitive_data_handling_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.purpose_consent_registry', [])) >= $flowCount
                && data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.default_access') === 'deny',
            'control_tower_run_operations' => data_get($company, 'enterprise_control_tower_run_operations_stack.schema') === 'atlas.ai.company.enterprise_control_tower_run_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= $cadenceCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.incident_and_exception_desk', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.change_window_and_release_calendar', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
            'semantic_operating_graph' => data_get($company, 'enterprise_semantic_operating_graph_stack.schema') === 'atlas.ai.company.enterprise_semantic_operating_graph_stack.v1'
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= $nodeFloor
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])) >= 5
                && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.external_side_effects_from_graph_allowed', true) === false,
            'delivery_assurance' => data_get($company, 'enterprise_delivery_assurance_stack.schema') === 'atlas.ai.company.enterprise_delivery_assurance_stack.v1'
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_intake_contract.reject_when_missing_acceptance_criteria', false)
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
            'unit_economics_capacity_simulation' => data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.schema') === 'atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1'
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])) >= 5
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])) >= 4
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_policy.synthetic_financial_claims_allowed', true) === false,
            'grc' => data_get($company, 'enterprise_grc_stack.schema') === 'atlas.ai.company.enterprise_grc_stack.v1'
                && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 4
                && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
        ];

        $readyComponentCount = count(array_filter($checks));

        return [
            'ready' => $readyComponentCount >= self::MIN_BUSINESS_BACKBONE_COMPONENTS_PER_COMPANY
                && ! in_array(false, $checks, true),
            'checks' => $checks,
            'ready_component_count' => $readyComponentCount,
            'required_component_count' => self::MIN_BUSINESS_BACKBONE_COMPONENTS_PER_COMPANY,
        ];
    }

    private function observedOperatingDays(string $domainId): int
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return 0;
        }

        return AiDomainRuntimeRecord::query()
            ->where('domain_id', $domainId)
            ->where('runtime_status', DomainRuntimeRecordService::STATUS_COMPLETED)
            ->get(['created_at'])
            ->map(static fn (AiDomainRuntimeRecord $record): string => optional($record->created_at)->toDateString() ?? '')
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestRoutineRuns(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.routine_runs', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestObservedMetrics(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.observed_metrics', []),
            'is_array',
        ));
    }

    /**
     * @return list<string>
     */
    private function latestOperatingPacketFlowProfiles(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.flow_profiles', []),
            'is_string',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestFunctionExecutions(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.function_executions', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestAgentAssignments(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.agent_assignments', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestAgentOperationalScorecards(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.agent_operational_scorecards', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestFlowExecutions(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.flow_executions', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestWorkProducts(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.work_products', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestRecurringJobs(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.recurring_jobs', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestIntegrationProbes(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.integration_checks', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestFlowOperationsRunbooks(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        $record = $this->latestHoldingCycleRecord($domainId);

        if (! $record instanceof AiDomainRuntimeRecord) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($record->execution_plan, 'operating_packet.flow_operations_runbooks', []),
            'is_array',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function latestEnterpriseFlowActionRuntimeRecords(string $domainId): array
    {
        if (! $this->domainRuntimeRecordsTableAvailable()) {
            return [];
        }

        return AiDomainRuntimeRecord::query()
            ->where('domain_id', $domainId)
            ->where('runtime_status', DomainRuntimeRecordService::STATUS_COMPLETED)
            ->where('execution_plan->runtime_kind', 'enterprise_flow_action')
            ->latest('id')
            ->cursor()
            ->map(static fn (AiDomainRuntimeRecord $record): array => [
                'record_id' => (string) $record->id,
                'uuid' => (string) $record->uuid,
                'runtime_status' => (string) $record->runtime_status,
                'flow_id' => (string) data_get($record->execution_plan, 'flow_id', ''),
                'schema' => (string) data_get($record->execution_plan, 'runtime_packet.schema', ''),
                'status' => (string) data_get($record->execution_plan, 'runtime_packet.status', ''),
                'mode' => (string) data_get($record->execution_plan, 'runtime_packet.mode', ''),
                'external_side_effects' => (bool) data_get($record->execution_plan, 'runtime_packet.external_side_effects', true),
                'receipt_hash' => (string) $record->receipt_hash,
            ])
            ->unique('flow_id')
            ->values()
            ->all();
    }

    private function latestHoldingCycleRecord(string $domainId): ?AiDomainRuntimeRecord
    {
        if (array_key_exists($domainId, $this->latestHoldingCycleRecordCache)) {
            return $this->latestHoldingCycleRecordCache[$domainId];
        }

        $query = AiDomainRuntimeRecord::query()
            ->where('domain_id', $domainId)
            ->where('runtime_status', DomainRuntimeRecordService::STATUS_COMPLETED)
            ->latest('id');

        $todayRef = 'autonomous_holding_operating_cycle:'.Carbon::now()->toDateString();

        $todayRecord = (clone $query)
            ->whereJsonContains('evidence_refs', $todayRef)
            ->first();

        if ($todayRecord instanceof AiDomainRuntimeRecord) {
            return $this->latestHoldingCycleRecordCache[$domainId] = $todayRecord;
        }

        foreach ($query->cursor() as $record) {
            if (collect((array) $record->evidence_refs)
                ->contains(static fn (mixed $ref): bool => is_string($ref) && str_starts_with($ref, 'autonomous_holding_operating_cycle:'))) {
                return $this->latestHoldingCycleRecordCache[$domainId] = $record;
            }
        }

        return $this->latestHoldingCycleRecordCache[$domainId] = null;
    }

    /**
     * @param array<string,mixed> $packet
     */
    private function externalSideEffectsBlocked(array $packet): bool
    {
        if (array_key_exists('external_side_effects', $packet)) {
            return ($packet['external_side_effects'] ?? true) === false;
        }

        if (array_key_exists('external_side_effects_enabled', $packet)) {
            return ($packet['external_side_effects_enabled'] ?? true) === false;
        }

        return false;
    }

    /**
     * @param list<string> $commands
     * @return list<string>
     */
    private function registeredRuntimeCommands(array $commands): array
    {
        $available = array_keys(Artisan::all());

        return array_values(array_filter(
            $commands,
            static fn (string $command): bool => in_array(self::artisanCommandName($command), $available, true),
        ));
    }

    private static function artisanCommandName(string $command): string
    {
        $command = trim($command);
        $command = preg_replace('/^php\s+artisan\s+/', '', $command) ?? $command;
        $parts = preg_split('/\s+/', trim($command));

        return (string) ($parts[0] ?? '');
    }

    private function stageLabel(int $stage): string
    {
        return match ($stage) {
            DomainSeedManifests::STAGE_ASSISTANT => 'assistant',
            DomainSeedManifests::STAGE_SPECIALIST => 'specialist',
            DomainSeedManifests::STAGE_DEPARTMENT => 'department',
            DomainSeedManifests::STAGE_OPERATING_UNIT => 'supervised_operating_unit',
            DomainSeedManifests::STAGE_AUTONOMOUS_ENTERPRISE_UNIT => 'limited_autonomy_enterprise_unit',
            default => 'unknown',
        };
    }

    private function domainRuntimeRecordsTableAvailable(): bool
    {
        return DatabaseTableAvailability::has('ai_domain_runtime_records');
    }

    private function targetGap(int $stage, bool $hasObservedHistoryWindow): string
    {
        if (! $hasObservedHistoryWindow) {
            return 'needs_current_observed_operating_cycle';
        }

        if ($stage >= DomainSeedManifests::STAGE_AUTONOMOUS_ENTERPRISE_UNIT) {
            return 'none_for_target_9_shape';
        }

        if ($stage >= DomainSeedManifests::STAGE_OPERATING_UNIT) {
            return 'needs_one_company_promoted_to_limited_autonomy';
        }

        return 'needs_receipts_evidence_benchmarks_and_supervised_execution_promotion';
    }
}
