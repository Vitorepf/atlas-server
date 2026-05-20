<?php

namespace App\Console\Commands;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Holding\AutonomousHoldingReadinessService;
use App\Services\Ai\Holding\AutonomousHoldingOperatingCycleService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureSuiteService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiAutonomousHoldingCommand extends Command
{
    protected $signature = 'atlas:ai:autonomous-holding
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, observe-cycle, enterprise-operating-packet-status, enterprise-buildout, enterprise-consolidation-run, enterprise-fixture-suite, enterprise-flow-action-runtime-run, enterprise-flow-action-runtime-status, enterprise-vertical-solution-runtime-status, enterprise-domain-solution-playbook-runtime-status, enterprise-domain-operating-depth-runtime-status, enterprise-domain-agent-workforce-runtime-status, enterprise-operational-dossier-runtime-status, enterprise-autonomy-promotion-runtime-status, enterprise-domain-business-execution-runtime-status, enterprise-company-operating-spine-runtime-status, enterprise-commercial-operations-runtime-status, enterprise-domain-provider-workbench-runtime-status, enterprise-external-research-adoption-runtime-status, enterprise-flow-benchmark-replay-runtime-status, enterprise-connector-certification-preflight-runtime-status, enterprise-command-center-control-tower-runtime-status, enterprise-operational-dress-rehearsal-runtime-status, enterprise-semantic-operating-graph-runtime-status, enterprise-agent-toolchain-runtime-status, enterprise-cross-company-handoff-runtime-status, enterprise-customer-account-revenue-runtime-status, enterprise-unit-economics-capacity-runtime-status, enterprise-business-operating-packet-runtime-status, enterprise-delivery-risk-runtime-status, enterprise-operational-outcome-runtime-status, enterprise-holding-outcome-scorecard-status, enterprise-portfolio-decision-packet-status, enterprise-company-board-operating-review-status, enterprise-shadow-readiness, enterprise-supervised-activation-plan, enterprise-supervised-runtime, enterprise-connector-certification, enterprise-external-action-mandates, enterprise-external-action-register, enterprise-external-action-preflight, enterprise-external-action-request-approval, enterprise-external-action-approve, enterprise-external-action-reject, enterprise-external-action-approval-status, enterprise-control-tower, enterprise-activation-cockpit, enterprise-premium-activation-status, enterprise-provider-workbench-status, enterprise-agent-repository-adoption-status, enterprise-industry-solution-ecosystem-status, enterprise-business-operating-backbone-status, enterprise-production-connector-preflight-status, enterprise-flow-quality-research-status, enterprise-vertical-solution-suite-status, enterprise-domain-business-execution-mesh-status, enterprise-flow-operating-package-status, enterprise-company-command-center-status, enterprise-operational-dress-rehearsal-status, enterprise-real-external-execution-readiness-dossier, enterprise-real-external-execution-handoff-pack, enterprise-supervised-external-execution-packet-status, enterprise-external-worker-preflight-status, enterprise-activation-backlog-register, enterprise-activation-backlog-status, enterprise-activation-backlog-run, enterprise-connector-activation-register, enterprise-connector-activation-probe, enterprise-connector-activation-status, enterprise-live-read-connector-readiness-status, enterprise-flow-run-queue-register, enterprise-flow-run-queue-execute, enterprise-flow-run-queue-replay, enterprise-flow-run-queue-status, enterprise-flow-operations-runbook-register, enterprise-flow-operations-runbook-drill, enterprise-flow-operations-runbook-status}
        {--company= : Optional company id for enterprise fixture/shadow actions}
        {--flow= : Optional flow id for mandate registration}
        {--work-package= : Optional activation backlog work package id}
        {--connector= : Optional enterprise connector id}
        {--mandate-hash= : Mandate packet hash for preflight}
        {--approval-uuid= : Operator approval uuid for approve/reject}
        {--operator=atlas_operator : Operator/reviewer identifier for approval decisions}
        {--note= : Optional approval decision note}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Autonomous Holding: read-only registry, portfolio governor and target-9 readiness for multi-domain companies.';

    public function handle(
        AutonomousHoldingReadinessService $readiness,
        AutonomousHoldingOperatingCycleService $operatingCycle,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
        EnterpriseFlowFixtureSuiteService $fixtureSuite,
        EnterpriseFlowFixtureActionRuntimeService $flowActionRuntime,
        ExternalActionMandateRegistryService $mandateRegistry,
    ): int
    {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'observe-cycle' => $this->renderObserveCycle($operatingCycle),
                'enterprise-operating-packet-status' => $this->renderEnterpriseOperatingPacketStatus($operatingCycle),
                'enterprise-buildout' => $this->renderEnterpriseBuildout($enterpriseBuildout),
                'enterprise-consolidation-run' => $this->renderEnterpriseConsolidationRun($mandateRegistry, $flowActionRuntime, $operatingCycle, $readiness),
                'enterprise-fixture-suite' => $this->renderEnterpriseFixtureSuite($fixtureSuite),
                'enterprise-flow-action-runtime-run' => $this->renderEnterpriseFlowActionRuntimeRun($flowActionRuntime),
                'enterprise-flow-action-runtime-status' => $this->renderEnterpriseFlowActionRuntimeStatus($flowActionRuntime),
                'enterprise-vertical-solution-runtime-status' => $this->renderEnterpriseVerticalSolutionRuntimeStatus($flowActionRuntime),
                'enterprise-domain-solution-playbook-runtime-status' => $this->renderEnterpriseDomainSolutionPlaybookRuntimeStatus($flowActionRuntime),
                'enterprise-domain-operating-depth-runtime-status' => $this->renderEnterpriseDomainOperatingDepthRuntimeStatus($flowActionRuntime),
                'enterprise-domain-agent-workforce-runtime-status' => $this->renderEnterpriseDomainAgentWorkforceRuntimeStatus($flowActionRuntime),
                'enterprise-operational-dossier-runtime-status' => $this->renderEnterpriseOperationalDossierRuntimeStatus($flowActionRuntime),
                'enterprise-autonomy-promotion-runtime-status' => $this->renderEnterpriseAutonomyPromotionRuntimeStatus($flowActionRuntime),
                'enterprise-domain-business-execution-runtime-status' => $this->renderEnterpriseDomainBusinessExecutionRuntimeStatus($flowActionRuntime),
                'enterprise-company-operating-spine-runtime-status' => $this->renderEnterpriseCompanyOperatingSpineRuntimeStatus($flowActionRuntime),
                'enterprise-commercial-operations-runtime-status' => $this->renderEnterpriseCommercialOperationsRuntimeStatus($flowActionRuntime),
                'enterprise-domain-provider-workbench-runtime-status' => $this->renderEnterpriseDomainProviderWorkbenchRuntimeStatus($flowActionRuntime),
                'enterprise-external-research-adoption-runtime-status' => $this->renderEnterpriseExternalResearchAdoptionRuntimeStatus($flowActionRuntime),
                'enterprise-flow-benchmark-replay-runtime-status' => $this->renderEnterpriseFlowBenchmarkReplayRuntimeStatus($flowActionRuntime),
                'enterprise-connector-certification-preflight-runtime-status' => $this->renderEnterpriseConnectorCertificationPreflightRuntimeStatus($flowActionRuntime),
                'enterprise-command-center-control-tower-runtime-status' => $this->renderEnterpriseCommandCenterControlTowerRuntimeStatus($flowActionRuntime),
                'enterprise-operational-dress-rehearsal-runtime-status' => $this->renderEnterpriseOperationalDressRehearsalRuntimeStatus($flowActionRuntime),
                'enterprise-semantic-operating-graph-runtime-status' => $this->renderEnterpriseSemanticOperatingGraphRuntimeStatus($flowActionRuntime),
                'enterprise-agent-toolchain-runtime-status' => $this->renderEnterpriseAgentToolchainRuntimeStatus($flowActionRuntime),
                'enterprise-cross-company-handoff-runtime-status' => $this->renderEnterpriseCrossCompanyHandoffRuntimeStatus($flowActionRuntime),
                'enterprise-customer-account-revenue-runtime-status' => $this->renderEnterpriseCustomerAccountRevenueRuntimeStatus($flowActionRuntime),
                'enterprise-unit-economics-capacity-runtime-status' => $this->renderEnterpriseUnitEconomicsCapacityRuntimeStatus($flowActionRuntime),
                'enterprise-business-operating-packet-runtime-status' => $this->renderEnterpriseBusinessOperatingPacketRuntimeStatus($flowActionRuntime),
                'enterprise-delivery-risk-runtime-status' => $this->renderEnterpriseDeliveryRiskRuntimeStatus($flowActionRuntime),
                'enterprise-operational-outcome-runtime-status' => $this->renderEnterpriseOperationalOutcomeRuntimeStatus($flowActionRuntime),
                'enterprise-holding-outcome-scorecard-status' => $this->renderEnterpriseHoldingOutcomeScorecardStatus($flowActionRuntime),
                'enterprise-portfolio-decision-packet-status' => $this->renderEnterprisePortfolioDecisionPacketStatus($flowActionRuntime),
                'enterprise-company-board-operating-review-status' => $this->renderEnterpriseCompanyBoardOperatingReviewStatus($flowActionRuntime),
                'enterprise-shadow-readiness' => $this->renderEnterpriseShadowReadiness($fixtureSuite),
                'enterprise-supervised-activation-plan' => $this->renderEnterpriseSupervisedActivationPlan($fixtureSuite),
                'enterprise-supervised-runtime' => $this->renderEnterpriseSupervisedRuntime($fixtureSuite),
                'enterprise-connector-certification' => $this->renderEnterpriseConnectorCertification($fixtureSuite),
                'enterprise-external-action-mandates' => $this->renderEnterpriseExternalActionMandates($fixtureSuite),
                'enterprise-external-action-register' => $this->renderEnterpriseExternalActionRegister($mandateRegistry),
                'enterprise-external-action-preflight' => $this->renderEnterpriseExternalActionPreflight($mandateRegistry),
                'enterprise-external-action-request-approval' => $this->renderEnterpriseExternalActionRequestApproval($mandateRegistry),
                'enterprise-external-action-approve' => $this->renderEnterpriseExternalActionDecision($mandateRegistry, 'approved'),
                'enterprise-external-action-reject' => $this->renderEnterpriseExternalActionDecision($mandateRegistry, 'rejected'),
                'enterprise-external-action-approval-status' => $this->renderEnterpriseExternalActionApprovalStatus($mandateRegistry),
                'enterprise-control-tower' => $this->renderEnterpriseControlTower($mandateRegistry),
                'enterprise-activation-cockpit' => $this->renderEnterpriseActivationCockpit($mandateRegistry),
                'enterprise-premium-activation-status' => $this->renderEnterprisePremiumActivationStatus($mandateRegistry),
                'enterprise-provider-workbench-status' => $this->renderEnterpriseProviderWorkbenchStatus($mandateRegistry),
                'enterprise-agent-repository-adoption-status' => $this->renderEnterpriseAgentRepositoryAdoptionStatus($mandateRegistry),
                'enterprise-industry-solution-ecosystem-status' => $this->renderEnterpriseIndustrySolutionEcosystemStatus($mandateRegistry),
                'enterprise-business-operating-backbone-status' => $this->renderEnterpriseBusinessOperatingBackboneStatus($mandateRegistry),
                'enterprise-production-connector-preflight-status' => $this->renderEnterpriseProductionConnectorPreflightStatus($mandateRegistry),
                'enterprise-flow-quality-research-status' => $this->renderEnterpriseFlowQualityResearchStatus($mandateRegistry),
                'enterprise-vertical-solution-suite-status' => $this->renderEnterpriseVerticalSolutionSuiteStatus($mandateRegistry),
                'enterprise-domain-business-execution-mesh-status' => $this->renderEnterpriseDomainBusinessExecutionMeshStatus($mandateRegistry),
                'enterprise-flow-operating-package-status' => $this->renderEnterpriseFlowOperatingPackageStatus($mandateRegistry),
                'enterprise-company-command-center-status' => $this->renderEnterpriseCompanyCommandCenterStatus($mandateRegistry),
                'enterprise-operational-dress-rehearsal-status' => $this->renderEnterpriseOperationalDressRehearsalStatus($mandateRegistry),
                'enterprise-real-external-execution-readiness-dossier' => $this->renderEnterpriseRealExternalExecutionReadinessDossier($mandateRegistry),
                'enterprise-real-external-execution-handoff-pack' => $this->renderEnterpriseRealExternalExecutionHandoffPack($mandateRegistry),
                'enterprise-supervised-external-execution-packet-status' => $this->renderEnterpriseSupervisedExternalExecutionPacketStatus($mandateRegistry),
                'enterprise-external-worker-preflight-status' => $this->renderEnterpriseExternalWorkerPreflightStatus($mandateRegistry),
                'enterprise-activation-backlog-register' => $this->renderEnterpriseActivationBacklogRegister($mandateRegistry),
                'enterprise-activation-backlog-status' => $this->renderEnterpriseActivationBacklogStatus($mandateRegistry),
                'enterprise-activation-backlog-run' => $this->renderEnterpriseActivationBacklogRun($mandateRegistry),
                'enterprise-connector-activation-register' => $this->renderEnterpriseConnectorActivationRegister($mandateRegistry),
                'enterprise-connector-activation-probe' => $this->renderEnterpriseConnectorActivationProbe($mandateRegistry),
                'enterprise-connector-activation-status' => $this->renderEnterpriseConnectorActivationStatus($mandateRegistry),
                'enterprise-live-read-connector-readiness-status' => $this->renderEnterpriseLiveReadConnectorReadinessStatus($mandateRegistry),
                'enterprise-flow-run-queue-register' => $this->renderEnterpriseFlowRunQueueRegister($mandateRegistry),
                'enterprise-flow-run-queue-execute' => $this->renderEnterpriseFlowRunQueueExecute($mandateRegistry),
                'enterprise-flow-run-queue-replay' => $this->renderEnterpriseFlowRunQueueReplay($mandateRegistry),
                'enterprise-flow-run-queue-status' => $this->renderEnterpriseFlowRunQueueStatus($mandateRegistry),
                'enterprise-flow-operations-runbook-register' => $this->renderEnterpriseFlowOperationsRunbookRegister($mandateRegistry),
                'enterprise-flow-operations-runbook-drill' => $this->renderEnterpriseFlowOperationsRunbookDrill($mandateRegistry),
                'enterprise-flow-operations-runbook-status' => $this->renderEnterpriseFlowOperationsRunbookStatus($mandateRegistry),
                default => $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:autonomous-holding"),
            };
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderObserveCycle(AutonomousHoldingOperatingCycleService $operatingCycle): int
    {
        $payload = $operatingCycle->observeToday();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('date', (string) $payload['date']);
            $this->components->twoColumnDetail('created', (string) $payload['summary']['created']);
            $this->components->twoColumnDetail('skipped', (string) $payload['summary']['skipped']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseOperatingPacketStatus(AutonomousHoldingOperatingCycleService $operatingCycle): int
    {
        $company = $this->option('company');
        $payload = $operatingCycle->operatingPacketStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('operating_packets', (string) $payload['summary']['operating_packet_count']);
            $this->components->twoColumnDetail('runbook_evidence', (string) $payload['summary']['flow_operations_runbook_evidence_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderReadiness(AutonomousHoldingReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('current_score', (string) $payload['current_score']);
            $this->components->twoColumnDetail('target_score', (string) $payload['target_score']);
            $this->components->twoColumnDetail('active_companies', (string) $payload['summary']['active_company_count']);
            $this->components->twoColumnDetail(
                'supervised_execution',
                (string) $payload['summary']['supervised_execution_company_count'],
            );
            $this->components->twoColumnDetail(
                'limited_autonomy',
                (string) $payload['summary']['limited_autonomy_company_count'],
            );
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseBuildout(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('company_count', (string) $payload['company_count']);
            $this->components->twoColumnDetail('enterprise_company_count', (string) $payload['enterprise_company_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConsolidationRun(
        ExternalActionMandateRegistryService $mandateRegistry,
        EnterpriseFlowFixtureActionRuntimeService $flowActionRuntime,
        AutonomousHoldingOperatingCycleService $operatingCycle,
        AutonomousHoldingReadinessService $readiness,
    ): int {
        $company = $this->option('company');
        $companyId = is_string($company) && trim($company) !== '' ? trim($company) : null;

        $steps = [
            'activation_backlog_register' => $mandateRegistry->registerActivationBacklog($companyId),
            'activation_backlog_run' => $mandateRegistry->runActivationBacklog($companyId, null, null),
            'connector_activation_register' => $mandateRegistry->registerConnectorActivations($companyId),
            'connector_activation_probe' => $mandateRegistry->probeConnectorActivations($companyId, null, null),
            'live_read_connector_readiness_status' => $mandateRegistry->liveReadConnectorReadinessStatus($companyId),
            'provider_workbench_status' => $mandateRegistry->providerWorkbenchStatus($companyId),
            'agent_repository_adoption_status' => $mandateRegistry->agentRepositoryAdoptionStatus($companyId),
            'industry_solution_ecosystem_status' => $mandateRegistry->industrySolutionEcosystemStatus($companyId),
            'business_operating_backbone_status' => $mandateRegistry->businessOperatingBackboneStatus($companyId),
            'production_connector_preflight_status' => $mandateRegistry->productionConnectorPreflightStatus($companyId),
            'flow_quality_research_status' => $mandateRegistry->flowQualityResearchStatus($companyId),
            'vertical_solution_suite_status' => $mandateRegistry->verticalSolutionSuiteStatus($companyId),
            'domain_business_execution_mesh_status' => $mandateRegistry->domainBusinessExecutionMeshStatus($companyId),
            'flow_operating_package_status' => $mandateRegistry->flowOperatingPackageStatus($companyId),
            'company_command_center_status' => $mandateRegistry->companyCommandCenterStatus($companyId),
            'operational_dress_rehearsal_status' => $mandateRegistry->operationalDressRehearsalStatus($companyId),
            'flow_run_queue_register' => $mandateRegistry->registerFlowRunQueue($companyId),
            'flow_operations_runbook_register' => $mandateRegistry->registerFlowOperationsRunbooks($companyId),
            'flow_operations_runbook_drill' => $mandateRegistry->drillFlowOperationsRunbooks($companyId, null),
            'real_external_execution_readiness_dossier' => $mandateRegistry->realExternalExecutionReadinessDossier($companyId),
            'real_external_execution_handoff_pack' => $mandateRegistry->realExternalExecutionHandoffPack($companyId),
            'flow_action_runtime_run' => $flowActionRuntime->runPortfolioInternal($companyId),
            'observe_cycle' => $operatingCycle->observeToday(),
        ];
        $steps['supervised_external_execution_packet_status'] = $mandateRegistry->supervisedExternalExecutionPacketStatusFromHandoff($steps['real_external_execution_handoff_pack']);
        $steps['external_worker_preflight_status'] = $mandateRegistry->externalWorkerPreflightStatusFromPackets($steps['supervised_external_execution_packet_status']);
        $steps['real_external_execution_readiness_dossier'] = $this->compactConsolidationStep($steps['real_external_execution_readiness_dossier']);
        $steps['real_external_execution_handoff_pack'] = $this->compactConsolidationStep($steps['real_external_execution_handoff_pack']);
        $steps['supervised_external_execution_packet_status'] = $this->compactConsolidationStep($steps['supervised_external_execution_packet_status']);
        $steps['external_worker_preflight_status'] = $this->compactConsolidationStep($steps['external_worker_preflight_status']);

        $steps['flow_action_runtime_status'] = $flowActionRuntime->runtimeStatus($companyId);
        $steps['vertical_solution_runtime_status'] = $flowActionRuntime->verticalSolutionRuntimeStatus($companyId);
        $steps['domain_solution_playbook_runtime_status'] = $flowActionRuntime->domainSolutionPlaybookRuntimeStatus($companyId);
        $steps['domain_operating_depth_runtime_status'] = $flowActionRuntime->domainOperatingDepthRuntimeStatus($companyId);
        $steps['domain_agent_workforce_runtime_status'] = $flowActionRuntime->domainAgentWorkforceRuntimeStatus($companyId);
        $steps['operational_dossier_runtime_status'] = $flowActionRuntime->operationalDossierRuntimeStatus($companyId);
        $steps['autonomy_promotion_runtime_status'] = $flowActionRuntime->autonomyPromotionRuntimeStatus($companyId);
        $steps['domain_business_execution_runtime_status'] = $flowActionRuntime->domainBusinessExecutionRuntimeStatus($companyId);
        $steps['company_operating_spine_runtime_status'] = $flowActionRuntime->companyOperatingSpineRuntimeStatus($companyId);
        $steps['commercial_operations_runtime_status'] = $flowActionRuntime->commercialOperationsRuntimeStatus($companyId);
        $steps['domain_provider_workbench_runtime_status'] = $flowActionRuntime->domainProviderWorkbenchRuntimeStatus($companyId);
        $steps['external_research_adoption_runtime_status'] = $flowActionRuntime->externalResearchAdoptionRuntimeStatus($companyId);
        $steps['flow_benchmark_replay_runtime_status'] = $flowActionRuntime->flowBenchmarkReplayRuntimeStatus($companyId);
        $steps['connector_certification_preflight_runtime_status'] = $flowActionRuntime->connectorCertificationPreflightRuntimeStatus($companyId);
        $steps['command_center_control_tower_runtime_status'] = $flowActionRuntime->commandCenterControlTowerRuntimeStatus($companyId);
        $steps['operational_dress_rehearsal_runtime_status'] = $flowActionRuntime->operationalDressRehearsalRuntimeStatus($companyId);
        $steps['semantic_operating_graph_runtime_status'] = $flowActionRuntime->semanticOperatingGraphRuntimeStatus($companyId);
        $steps['agent_toolchain_runtime_status'] = $flowActionRuntime->agentToolchainRuntimeStatus($companyId);
        $steps['cross_company_handoff_runtime_status'] = $flowActionRuntime->crossCompanyHandoffRuntimeStatus($companyId);
        $steps['customer_account_revenue_runtime_status'] = $flowActionRuntime->customerAccountRevenueRuntimeStatus($companyId);
        $steps['unit_economics_capacity_runtime_status'] = $flowActionRuntime->unitEconomicsCapacityRuntimeStatus($companyId);
        $steps['business_operating_packet_runtime_status'] = $flowActionRuntime->businessOperatingPacketRuntimeStatus($companyId);
        $steps['delivery_risk_runtime_status'] = $flowActionRuntime->deliveryRiskRuntimeStatus($companyId);
        $steps['operational_outcome_runtime_status'] = $flowActionRuntime->operationalOutcomeRuntimeStatus($companyId);
        $steps['holding_outcome_scorecard_status'] = $flowActionRuntime->holdingOutcomeScorecardStatus($companyId);
        $steps['portfolio_decision_packet_status'] = $flowActionRuntime->portfolioDecisionPacketStatus($companyId);
        $steps['company_board_operating_review_status'] = $flowActionRuntime->companyBoardOperatingReviewStatus($companyId);
        $steps['operating_packet_status'] = $operatingCycle->operatingPacketStatus($companyId);
        $steps['readiness'] = $readiness->report();

        $steps = array_map(fn (array $step): array => $this->compactConsolidationStep($step), $steps);

        $payload = [
            'ok' => collect($steps)->every(static fn (array $step): bool => (bool) ($step['ok'] ?? false))
                && (bool) ($steps['readiness']['ok'] ?? false),
            'schema' => 'atlas.ai.holding.enterprise_consolidation_run.v1',
            'status' => (bool) ($steps['readiness']['ok'] ?? false)
                ? 'consolidated_target_ready_external_blocked'
                : 'consolidated_attention_external_blocked',
            'generated_at' => now()->toJSON(),
            'company_scope' => $companyId,
            'summary' => [
                'step_count' => count($steps),
                'green_step_count' => count(array_filter($steps, static fn (array $step): bool => (bool) ($step['ok'] ?? false))),
                'flow_action_runtime_coverage_rate' => (float) data_get($steps, 'flow_action_runtime_status.summary.coverage_rate', 0.0),
                'vertical_solution_runtime_coverage_rate' => (float) data_get($steps, 'vertical_solution_runtime_status.summary.coverage_rate', 0.0),
                'domain_solution_playbook_runtime_coverage_rate' => (float) data_get($steps, 'domain_solution_playbook_runtime_status.summary.coverage_rate', 0.0),
                'domain_operating_depth_runtime_coverage_rate' => (float) data_get($steps, 'domain_operating_depth_runtime_status.summary.coverage_rate', 0.0),
                'domain_agent_workforce_runtime_coverage_rate' => (float) data_get($steps, 'domain_agent_workforce_runtime_status.summary.coverage_rate', 0.0),
                'operational_dossier_runtime_coverage_rate' => (float) data_get($steps, 'operational_dossier_runtime_status.summary.coverage_rate', 0.0),
                'autonomy_promotion_runtime_coverage_rate' => (float) data_get($steps, 'autonomy_promotion_runtime_status.summary.coverage_rate', 0.0),
                'domain_business_execution_runtime_coverage_rate' => (float) data_get($steps, 'domain_business_execution_runtime_status.summary.coverage_rate', 0.0),
                'company_operating_spine_runtime_coverage_rate' => (float) data_get($steps, 'company_operating_spine_runtime_status.summary.coverage_rate', 0.0),
                'commercial_operations_runtime_coverage_rate' => (float) data_get($steps, 'commercial_operations_runtime_status.summary.coverage_rate', 0.0),
                'domain_provider_workbench_runtime_coverage_rate' => (float) data_get($steps, 'domain_provider_workbench_runtime_status.summary.coverage_rate', 0.0),
                'external_research_adoption_runtime_coverage_rate' => (float) data_get($steps, 'external_research_adoption_runtime_status.summary.coverage_rate', 0.0),
                'flow_benchmark_replay_runtime_coverage_rate' => (float) data_get($steps, 'flow_benchmark_replay_runtime_status.summary.coverage_rate', 0.0),
                'connector_certification_preflight_runtime_coverage_rate' => (float) data_get($steps, 'connector_certification_preflight_runtime_status.summary.coverage_rate', 0.0),
                'command_center_control_tower_runtime_coverage_rate' => (float) data_get($steps, 'command_center_control_tower_runtime_status.summary.coverage_rate', 0.0),
                'operational_dress_rehearsal_runtime_coverage_rate' => (float) data_get($steps, 'operational_dress_rehearsal_runtime_status.summary.coverage_rate', 0.0),
                'semantic_operating_graph_runtime_coverage_rate' => (float) data_get($steps, 'semantic_operating_graph_runtime_status.summary.coverage_rate', 0.0),
                'agent_toolchain_runtime_coverage_rate' => (float) data_get($steps, 'agent_toolchain_runtime_status.summary.coverage_rate', 0.0),
                'cross_company_handoff_runtime_coverage_rate' => (float) data_get($steps, 'cross_company_handoff_runtime_status.summary.coverage_rate', 0.0),
                'customer_account_revenue_runtime_coverage_rate' => (float) data_get($steps, 'customer_account_revenue_runtime_status.summary.coverage_rate', 0.0),
                'unit_economics_capacity_runtime_coverage_rate' => (float) data_get($steps, 'unit_economics_capacity_runtime_status.summary.coverage_rate', 0.0),
                'business_operating_packet_runtime_coverage_rate' => (float) data_get($steps, 'business_operating_packet_runtime_status.summary.coverage_rate', 0.0),
                'delivery_risk_runtime_coverage_rate' => (float) data_get($steps, 'delivery_risk_runtime_status.summary.coverage_rate', 0.0),
                'operational_outcome_runtime_coverage_rate' => (float) data_get($steps, 'operational_outcome_runtime_status.summary.coverage_rate', 0.0),
                'holding_outcome_scorecard_average_score' => (float) data_get($steps, 'holding_outcome_scorecard_status.summary.average_score', 0.0),
                'portfolio_decision_packet_ready_count' => (int) data_get($steps, 'portfolio_decision_packet_status.summary.ready_decision_packet_count', 0),
                'company_board_operating_review_ready_count' => (int) data_get($steps, 'company_board_operating_review_status.summary.ready_company_count', 0),
                'external_worker_preflight_ready_count' => (int) data_get($steps, 'external_worker_preflight_status.summary.worker_preflight_ready_count', 0),
                'operating_packet_count' => (int) data_get($steps, 'operating_packet_status.summary.operating_packet_count', 0),
                'readiness_score' => (float) data_get($steps, 'readiness.current_score', 0.0),
                'target_score' => (float) data_get($steps, 'readiness.target_score', 0.0),
                'target_ready_companies' => (int) data_get($steps, 'readiness.summary.target_9_claim_ready_company_count', 0),
            ],
            'steps' => $steps,
            'policy' => [
                'mode' => 'internal_enterprise_consolidation',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
            ],
        ];
        $payload['consolidation_hash'] = \App\Services\Ai\Mission\MissionCanonicalHash::sha256($payload);

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('steps', (string) $payload['summary']['step_count']);
            $this->components->twoColumnDetail('green_steps', (string) $payload['summary']['green_step_count']);
            $this->components->twoColumnDetail('readiness_score', (string) $payload['summary']['readiness_score']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFixtureSuite(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->run(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('pass_rate', (string) $payload['summary']['pass_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function compactConsolidationStep(array $step): array
    {
        $compact = [
            'ok' => (bool) ($step['ok'] ?? false),
            'schema' => (string) ($step['schema'] ?? ''),
            'status' => (string) ($step['status'] ?? ''),
            'generated_at' => (string) ($step['generated_at'] ?? now()->toJSON()),
            'summary' => (array) ($step['summary'] ?? []),
            'policy' => (array) ($step['policy'] ?? []),
        ];

        foreach ([
            'source_hashes',
            'source_dossier_hash',
            'source_handoff_pack_hash',
            'current_score',
            'target_score',
            'real_external_execution_readiness_dossier_hash',
            'real_external_execution_handoff_pack_hash',
            'supervised_external_execution_packet_status_hash',
            'external_worker_preflight_status_hash',
            'runtime_run_hash',
            'runtime_status_hash',
            'vertical_solution_runtime_status_hash',
            'domain_solution_playbook_runtime_status_hash',
            'domain_operating_depth_runtime_status_hash',
            'domain_agent_workforce_runtime_status_hash',
            'operational_dossier_runtime_status_hash',
            'autonomy_promotion_runtime_status_hash',
            'domain_business_execution_runtime_status_hash',
            'company_operating_spine_runtime_status_hash',
            'commercial_operations_runtime_status_hash',
            'domain_provider_workbench_runtime_status_hash',
            'external_research_adoption_runtime_status_hash',
            'semantic_operating_graph_runtime_status_hash',
            'agent_toolchain_runtime_status_hash',
            'cross_company_handoff_runtime_status_hash',
            'customer_account_revenue_runtime_status_hash',
            'unit_economics_capacity_runtime_status_hash',
            'business_operating_packet_runtime_status_hash',
            'delivery_risk_runtime_status_hash',
            'operational_outcome_runtime_status_hash',
            'holding_outcome_scorecard_status_hash',
            'portfolio_decision_packet_status_hash',
            'company_board_operating_review_status_hash',
            'operating_packet_status_hash',
        ] as $field) {
            if (array_key_exists($field, $step)) {
                $compact[$field] = $step[$field];
            }
        }

        return $compact;
    }

    private function renderEnterpriseFlowActionRuntimeRun(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->runPortfolioInternal(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('runtime_records', (string) $payload['summary']['runtime_record_bound_count']);
            $this->components->twoColumnDetail('external_side_effects', (string) $payload['summary']['external_side_effect_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowActionRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->runtimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_flows', (string) $payload['summary']['completed_runtime_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseVerticalSolutionRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->verticalSolutionRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_vertical_flows', (string) $payload['summary']['completed_vertical_runtime_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
            $this->components->twoColumnDetail('external_side_effects', (string) $payload['summary']['external_side_effect_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainSolutionPlaybookRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainSolutionPlaybookRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_solution_playbook_flows', (string) $payload['summary']['completed_domain_solution_playbook_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainOperatingDepthRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainOperatingDepthRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_domain_depth_flows', (string) $payload['summary']['completed_domain_operating_depth_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainAgentWorkforceRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainAgentWorkforceRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_agent_workforce_flows', (string) $payload['summary']['completed_domain_agent_workforce_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseOperationalDossierRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->operationalDossierRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_dossier_flows', (string) $payload['summary']['completed_operational_dossier_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseAutonomyPromotionRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->autonomyPromotionRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_autonomy_flows', (string) $payload['summary']['completed_autonomy_promotion_flow_count']);
            $this->components->twoColumnDetail('limited_autonomy_blocked', (string) $payload['summary']['limited_external_autonomy_blocked_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainBusinessExecutionRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainBusinessExecutionRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_business_flows', (string) $payload['summary']['completed_business_execution_runtime_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
            $this->components->twoColumnDetail('external_side_effects', (string) $payload['summary']['external_side_effect_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingSpineRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->companyOperatingSpineRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_operating_spine_flows', (string) $payload['summary']['completed_operating_spine_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCommercialOperationsRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->commercialOperationsRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_commercial_flows', (string) $payload['summary']['completed_commercial_operations_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainProviderWorkbenchRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainProviderWorkbenchRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_provider_workbench_flows', (string) $payload['summary']['completed_provider_workbench_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalResearchAdoptionRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->externalResearchAdoptionRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_research_adoption_flows', (string) $payload['summary']['completed_external_research_adoption_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowBenchmarkReplayRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->flowBenchmarkReplayRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_benchmark_flows', (string) $payload['summary']['completed_benchmark_replay_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConnectorCertificationPreflightRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->connectorCertificationPreflightRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_connector_flows', (string) $payload['summary']['completed_connector_certification_preflight_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCommandCenterControlTowerRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->commandCenterControlTowerRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_command_center_flows', (string) $payload['summary']['completed_command_center_control_tower_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseOperationalDressRehearsalRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->operationalDressRehearsalRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_rehearsal_flows', (string) $payload['summary']['completed_operational_dress_rehearsal_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseSemanticOperatingGraphRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->semanticOperatingGraphRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_graph_flows', (string) $payload['summary']['completed_semantic_graph_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseAgentToolchainRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->agentToolchainRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_toolchain_flows', (string) $payload['summary']['completed_agent_toolchain_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCrossCompanyHandoffRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->crossCompanyHandoffRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('handoff_contracts', (string) $payload['summary']['handoff_contract_count']);
            $this->components->twoColumnDetail('ready_packets', (string) $payload['summary']['ready_handoff_runtime_packet_count']);
            $this->components->twoColumnDetail('target_acceptance', (string) $payload['summary']['target_acceptance_bound_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCustomerAccountRevenueRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->customerAccountRevenueRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_customer_account_revenue_flows', (string) $payload['summary']['completed_customer_account_revenue_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseUnitEconomicsCapacityRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->unitEconomicsCapacityRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_economic_flows', (string) $payload['summary']['completed_unit_economics_capacity_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseBusinessOperatingPacketRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->businessOperatingPacketRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_business_packets', (string) $payload['summary']['completed_business_operating_packet_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
            $this->components->twoColumnDetail('external_commitments_blocked', (string) $payload['summary']['external_commitments_blocked_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDeliveryRiskRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->deliveryRiskRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_delivery_risk_flows', (string) $payload['summary']['completed_delivery_risk_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseOperationalOutcomeRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->operationalOutcomeRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_outcome_flows', (string) $payload['summary']['completed_operational_outcome_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
            $this->components->twoColumnDetail('external_side_effects', (string) $payload['summary']['external_side_effect_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseHoldingOutcomeScorecardStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->holdingOutcomeScorecardStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready_companies', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('completed_outcome_flows', (string) $payload['summary']['completed_outcome_flow_count']);
            $this->components->twoColumnDetail('average_score', (string) $payload['summary']['average_score']);
            $this->components->twoColumnDetail('external_value_claims', (string) $payload['summary']['external_value_claim_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterprisePortfolioDecisionPacketStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->portfolioDecisionPacketStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready_packets', (string) $payload['summary']['ready_decision_packet_count']);
            $this->components->twoColumnDetail('scale_internal', (string) $payload['summary']['scale_internal_supervised_capacity_count']);
            $this->components->twoColumnDetail('real_capital_blocked', (string) $payload['summary']['blocked_real_capital_action_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyBoardOperatingReviewStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->companyBoardOperatingReviewStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready_companies', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('business_packets', (string) $payload['summary']['business_operating_packet_flow_count']);
            $this->components->twoColumnDetail('command_center_flows', (string) $payload['summary']['command_center_flow_count']);
            $this->components->twoColumnDetail('external_commitments_allowed', (string) $payload['summary']['external_commitment_allowed_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseShadowReadiness(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->shadowReadiness(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('shadow_ready', (string) $payload['summary']['shadow_ready_company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseSupervisedActivationPlan(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->supervisedActivationPlan(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready_for_mandate', (string) $payload['summary']['ready_for_operator_mandate_company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseSupervisedRuntime(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->supervisedRuntime(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('completed_flows', (string) $payload['summary']['completed_flow_count']);
            $this->components->twoColumnDetail('completion_rate', (string) $payload['summary']['completion_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConnectorCertification(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->connectorCertificationSuite(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('certified_connectors', (string) $payload['summary']['certified_connector_count']);
            $this->components->twoColumnDetail('certification_rate', (string) $payload['summary']['certification_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionMandates(EnterpriseFlowFixtureSuiteService $fixtureSuite): int
    {
        $company = $this->option('company');
        $payload = $fixtureSuite->externalActionMandateSuite(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('prepared_packets', (string) $payload['summary']['prepared_packet_count']);
            $this->components->twoColumnDetail('auto_execute_allowed', $payload['summary']['auto_execute_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $payload = $mandateRegistry->register(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('registered', (string) $payload['summary']['registered_count']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('auto_execute_allowed_count', (string) $payload['summary']['auto_execute_allowed_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionPreflight(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $hash = $this->option('mandate-hash');
        $payload = $mandateRegistry->preflight(is_string($hash) ? trim($hash) : '');
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['external_execution_allowed'] ? 'true' : 'false');
            $this->components->twoColumnDetail('mandate_packet_hash', (string) $payload['mandate_packet_hash']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionRequestApproval(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $hash = $this->option('mandate-hash');
        $payload = $mandateRegistry->requestApproval(is_string($hash) ? trim($hash) : '');
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('approval_count', (string) ($payload['approval_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', $payload['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionDecision(
        ExternalActionMandateRegistryService $mandateRegistry,
        string $decision,
    ): int {
        $uuid = $this->option('approval-uuid');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->decideApproval(
            is_string($uuid) ? trim($uuid) : '',
            $decision,
            is_string($operator) ? trim($operator) : 'atlas_operator',
            is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('approval_uuid', (string) ($payload['approval_uuid'] ?? ''));
            $this->components->twoColumnDetail('mandate_status', (string) ($payload['mandate_status'] ?? ''));
            $this->components->twoColumnDetail('external_execution_allowed', $payload['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalActionApprovalStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $hash = $this->option('mandate-hash');
        $payload = $mandateRegistry->approvalStatus(is_string($hash) ? trim($hash) : '');
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('approval_count', (string) ($payload['approval_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', $payload['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseControlTower(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->controlTower(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('registered_mandates', (string) $payload['summary']['registered_mandate_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseActivationCockpit(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->activationCockpit(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('blocked_flows', (string) $payload['summary']['blocked_flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['activation_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterprisePremiumActivationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->premiumActivationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('premium_ready', (string) $payload['summary']['premium_ready_company_count']);
            $this->components->twoColumnDetail('wait_days_required_max', (string) $payload['summary']['wait_days_required_max']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseProviderWorkbenchStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->providerWorkbenchStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('provider_contracts', (string) $payload['summary']['provider_contract_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseAgentRepositoryAdoptionStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->agentRepositoryAdoptionStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('repository_intake', (string) $payload['summary']['repository_intake_count']);
            $this->components->twoColumnDetail('flow_epics', (string) $payload['summary']['flow_epic_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseIndustrySolutionEcosystemStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->industrySolutionEcosystemStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('providers', (string) $payload['summary']['ecosystem_provider_count']);
            $this->components->twoColumnDetail('workload_packs', (string) $payload['summary']['flow_workload_pack_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseBusinessOperatingBackboneStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->businessOperatingBackboneStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('ready_components', (string) $payload['summary']['ready_component_count']);
            $this->components->twoColumnDetail('required_components', (string) $payload['summary']['required_component_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseProductionConnectorPreflightStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->productionConnectorPreflightStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('connector_preflight_contracts', (string) $payload['summary']['connector_preflight_contract_count']);
            $this->components->twoColumnDetail('flow_cutovers', (string) $payload['summary']['flow_cutover_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowQualityResearchStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->flowQualityResearchStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('offline_datasets', (string) $payload['summary']['offline_dataset_count']);
            $this->components->twoColumnDetail('tooling_benchmarks', (string) $payload['summary']['tooling_benchmark_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyCommandCenterStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->companyCommandCenterStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('flow_cards', (string) $payload['summary']['flow_command_card_count']);
            $this->components->twoColumnDetail('connector_panels', (string) $payload['summary']['connector_workbench_panel_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowOperatingPackageStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->flowOperatingPackageStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('flow_packages', (string) $payload['summary']['flow_package_count']);
            $this->components->twoColumnDetail('replay_contracts', (string) $payload['summary']['replay_contract_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseVerticalSolutionSuiteStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->verticalSolutionSuiteStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('solution_suites', (string) $payload['summary']['solution_suite_count']);
            $this->components->twoColumnDetail('flow_kits', (string) $payload['summary']['flow_solution_kit_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainBusinessExecutionMeshStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->domainBusinessExecutionMeshStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('execution_cells', (string) $payload['summary']['execution_cell_count']);
            $this->components->twoColumnDetail('service_lanes', (string) $payload['summary']['service_lane_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseOperationalDressRehearsalStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->operationalDressRehearsalStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('rehearsal_runbooks', (string) $payload['summary']['flow_rehearsal_runbook_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseRealExternalExecutionReadinessDossier(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->realExternalExecutionReadinessDossier(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('manual_candidates', (string) $payload['summary']['manual_handoff_candidate_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseRealExternalExecutionHandoffPack(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->realExternalExecutionHandoffPack(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('handoff_packs', (string) $payload['summary']['handoff_pack_ready_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseSupervisedExternalExecutionPacketStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->supervisedExternalExecutionPacketStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('ready_packets', (string) $payload['summary']['packet_ready_count']);
            $this->components->twoColumnDetail('external_worker_enabled', $payload['policy']['external_worker_enabled'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalWorkerPreflightStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalWorkerPreflightStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('worker_preflights', (string) $payload['summary']['worker_preflight_ready_count']);
            $this->components->twoColumnDetail('dispatch_enabled', $payload['policy']['external_worker_dispatch_enabled'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseActivationBacklogRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->registerActivationBacklog(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('work_packages', (string) $payload['summary']['work_package_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['registry_policy']['external_execution_enabled_by_registry'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseActivationBacklogStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->activationBacklogStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('work_packages', (string) $payload['summary']['work_package_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseActivationBacklogRun(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $workPackage = $this->option('work-package');
        $payload = $mandateRegistry->runActivationBacklog(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
            is_string($workPackage) && trim($workPackage) !== '' ? trim($workPackage) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('work_packages', (string) $payload['summary']['work_package_count']);
            $this->components->twoColumnDetail('completed', (string) $payload['summary']['completed_count']);
            $this->components->twoColumnDetail('blocked', (string) $payload['summary']['blocked_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['run_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConnectorActivationRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->registerConnectorActivations(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('connector_activations', (string) $payload['summary']['connector_activation_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['registry_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConnectorActivationProbe(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $connector = $this->option('connector');
        $payload = $mandateRegistry->probeConnectorActivations(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
            is_string($connector) && trim($connector) !== '' ? trim($connector) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('connector_activations', (string) $payload['summary']['connector_activation_count']);
            $this->components->twoColumnDetail('probe_green', (string) $payload['summary']['probe_green_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['probe_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseConnectorActivationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->connectorActivationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('connector_activations', (string) $payload['summary']['connector_activation_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseLiveReadConnectorReadinessStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->liveReadConnectorReadinessStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('connectors', (string) $payload['summary']['connector_readiness_count']);
            $this->components->twoColumnDetail('live_read_ready', (string) $payload['summary']['live_read_ready_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowRunQueueRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->registerFlowRunQueue(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['queue_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowRunQueueExecute(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $payload = $mandateRegistry->executeFlowRunQueue(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('completed', (string) $payload['summary']['completed_count']);
            $this->components->twoColumnDetail('dlq', (string) $payload['summary']['dlq_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['execution_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowRunQueueStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->flowRunQueueStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowRunQueueReplay(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $payload = $mandateRegistry->replayFlowRunQueue(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('completed', (string) $payload['summary']['completed_count']);
            $this->components->twoColumnDetail('dlq', (string) $payload['summary']['dlq_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['replay_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowOperationsRunbookRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->registerFlowOperationsRunbooks(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['registry_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowOperationsRunbookDrill(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $flow = $this->option('flow');
        $payload = $mandateRegistry->drillFlowOperationsRunbooks(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($flow) && trim($flow) !== '' ? trim($flow) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('operations_green', (string) $payload['summary']['operations_green_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['drill_policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowOperationsRunbookStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->flowOperationsRunbookStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }

        $human();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
