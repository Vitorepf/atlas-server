<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
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
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:autonomous-holding
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, observe-cycle, enterprise-operating-packet-status, enterprise-buildout, enterprise-consolidation-run, enterprise-fixture-suite, enterprise-flow-action-runtime-run, enterprise-flow-action-runtime-status, enterprise-vertical-solution-runtime-status, enterprise-domain-solution-playbook-runtime-status, enterprise-domain-operating-depth-runtime-status, enterprise-domain-agent-workforce-runtime-status, enterprise-operational-dossier-runtime-status, enterprise-autonomy-promotion-runtime-status, enterprise-domain-business-execution-runtime-status, enterprise-company-operating-spine-runtime-status, enterprise-commercial-operations-runtime-status, enterprise-domain-provider-workbench-runtime-status, enterprise-domain-company-execution-suite-runtime-status, enterprise-flow-work-product-delivery-runtime-status, enterprise-domain-data-connector-operating-runtime-status, enterprise-flow-live-read-connector-probe-runtime-status, enterprise-external-research-adoption-runtime-status, enterprise-flow-benchmark-replay-runtime-status, enterprise-connector-certification-preflight-runtime-status, enterprise-command-center-control-tower-runtime-status, enterprise-operational-dress-rehearsal-runtime-status, enterprise-semantic-operating-graph-runtime-status, enterprise-agent-toolchain-runtime-status, enterprise-workforce-capacity-runtime-status, enterprise-cross-company-handoff-runtime-status, enterprise-customer-account-revenue-runtime-status, enterprise-productized-service-runtime-status, enterprise-sales-crm-pipeline-runtime-status, enterprise-customer-support-service-desk-runtime-status, enterprise-marketing-growth-engine-runtime-status, enterprise-finance-treasury-billing-runtime-status, enterprise-governance-risk-operations-runtime-status, enterprise-unit-economics-capacity-runtime-status, enterprise-business-operating-packet-runtime-status, enterprise-delivery-risk-runtime-status, enterprise-operational-outcome-runtime-status, enterprise-holding-outcome-scorecard-status, enterprise-portfolio-decision-packet-status, enterprise-company-board-operating-review-status, enterprise-company-completion-certification-status, enterprise-holding-completion-audit-status, enterprise-vertical-operational-depth-status, enterprise-company-operating-cycle-status, enterprise-company-operating-cadence-status, enterprise-company-operating-scorecard-status, enterprise-company-active-operating-system-status, enterprise-company-capability-catalog-status, enterprise-company-integration-readiness-status, enterprise-domain-workload-agent-template-status, enterprise-company-domain-solution-pack-status, enterprise-company-agent-operations-pack-status, enterprise-company-domain-operating-model-certification-status, enterprise-company-domain-tool-execution-readiness-status, enterprise-company-flow-tool-execution-ledger-status, enterprise-company-flow-tool-execution-runtime-register, enterprise-company-flow-tool-execution-runtime-status, enterprise-company-domain-adapter-execution-envelope-register, enterprise-company-domain-adapter-execution-envelope-status, enterprise-company-operational-execution-loop-status, enterprise-company-work-product-acceptance-evidence-status, enterprise-company-work-product-runtime-register, enterprise-company-work-product-runtime-status, enterprise-company-operating-blueprint-runtime-register, enterprise-company-operating-blueprint-runtime-status, enterprise-company-business-runtime-persistence-register, enterprise-company-business-runtime-persistence-status, enterprise-company-capability-runtime-mesh-register, enterprise-company-capability-runtime-mesh-status, enterprise-company-supervised-connector-execution-register, enterprise-company-supervised-connector-execution-status, enterprise-company-external-tool-activation-work-order-register, enterprise-company-external-tool-activation-work-order-status, enterprise-company-external-tool-activation-packet-register, enterprise-company-external-tool-activation-packet-status, enterprise-company-commercial-service-catalog-status, enterprise-company-revenue-delivery-operating-mesh-status, enterprise-company-org-operating-model-status, enterprise-company-customer-delivery-lifecycle-status, enterprise-company-quality-compliance-lifecycle-status, enterprise-company-production-readiness-certification-status, enterprise-company-operating-evidence-bundle-status, enterprise-shadow-readiness, enterprise-supervised-activation-plan, enterprise-supervised-runtime, enterprise-connector-certification, enterprise-external-action-mandates, enterprise-external-action-register, enterprise-external-action-preflight, enterprise-external-action-request-approval, enterprise-external-action-approve, enterprise-external-action-reject, enterprise-external-action-approval-status, enterprise-control-tower, enterprise-activation-cockpit, enterprise-premium-activation-status, enterprise-provider-workbench-status, enterprise-agent-repository-adoption-status, enterprise-agent-repository-operating-catalog-status, enterprise-domain-data-fabric-status, enterprise-domain-data-connector-operating-status, enterprise-flow-live-read-connector-probe-status, enterprise-external-research-adoption-status, enterprise-flow-benchmark-replay-status, enterprise-connector-certification-preflight-status, enterprise-domain-agent-toolchain-certification-status, enterprise-industry-solution-ecosystem-status, enterprise-business-operating-backbone-status, enterprise-production-connector-preflight-status, enterprise-flow-quality-research-status, enterprise-vertical-solution-suite-status, enterprise-domain-business-execution-mesh-status, enterprise-flow-operating-package-status, enterprise-company-command-center-status, enterprise-operational-dress-rehearsal-status, enterprise-real-external-execution-readiness-dossier, enterprise-real-external-execution-handoff-pack, enterprise-supervised-external-execution-packet-status, enterprise-external-worker-preflight-status, enterprise-external-worker-dispatch-plan-status, enterprise-external-launch-control-status, enterprise-external-receipt-binding-status, enterprise-external-supervised-cutover-dossier-status, enterprise-external-supervised-cutover-work-order-status, enterprise-external-supervised-cutover-work-order-register, enterprise-external-supervised-cutover-work-order-persisted-status, enterprise-external-supervised-cutover-work-item-bind-receipt, enterprise-external-supervised-cutover-promotion-status, enterprise-external-supervised-cutover-final-authority-bind-receipt, enterprise-external-supervised-cutover-runtime-invocation-register, enterprise-external-supervised-cutover-runtime-invocation-status, enterprise-external-supervised-cutover-runtime-rehearsal-execute, enterprise-external-supervised-cutover-rehearsal-promotion-status, enterprise-external-supervised-cutover-manual-handoff-register, enterprise-external-supervised-cutover-manual-handoff-status, enterprise-external-supervised-cutover-manual-closeout-bind-receipt, enterprise-external-supervised-cutover-manual-closeout-status, enterprise-external-supervised-cutover-portfolio-readiness-status, enterprise-external-supervised-cutover-company-evidence-bundle-apply, enterprise-external-supervised-cutover-portfolio-evidence-bundle-apply, enterprise-activation-backlog-register, enterprise-activation-backlog-status, enterprise-activation-backlog-run, enterprise-connector-activation-register, enterprise-connector-activation-probe, enterprise-connector-activation-status, enterprise-live-read-connector-readiness-status, enterprise-flow-run-queue-register, enterprise-flow-run-queue-execute, enterprise-flow-run-queue-replay, enterprise-flow-run-queue-status, enterprise-flow-operations-runbook-register, enterprise-flow-operations-runbook-drill, enterprise-flow-operations-runbook-status}
        {--company= : Optional company id for enterprise fixture/shadow actions}
        {--flow= : Optional flow id for mandate registration}
        {--work-package= : Optional activation backlog work package id}
        {--connector= : Optional enterprise connector id}
        {--work-order= : Optional external cutover work order id}
        {--work-item= : Optional external cutover work item id}
        {--invocation= : Optional external cutover runtime invocation id}
        {--authority= : Optional external cutover final authority id}
        {--receipt-hash= : Receipt hash for external cutover work item binding}
        {--receipt-source= : Receipt source for external cutover work item binding}
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
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        if ((int) ini_get('memory_limit') > 0) {
            ini_set('memory_limit', '512M');
        }

        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            $spec = self::RENDER_TABLE[$action] ?? null;
            if ($spec !== null) {
                $services = [
                    'operatingCycle' => $operatingCycle,
                    'enterpriseBuildout' => $enterpriseBuildout,
                    'fixtureSuite' => $fixtureSuite,
                    'flowActionRuntime' => $flowActionRuntime,
                    'mandateRegistry' => $mandateRegistry,
                ];

                return $this->renderPayload($spec, $services[$spec[0]]);
            }

            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'enterprise-consolidation-run' => $this->renderEnterpriseConsolidationRun($mandateRegistry, $flowActionRuntime, $operatingCycle, $readiness),
                'enterprise-fixture-suite' => $this->renderEnterpriseFixtureSuite($fixtureSuite),
                'enterprise-external-action-approve' => $this->renderEnterpriseExternalActionDecision($mandateRegistry, 'approved'),
                'enterprise-external-action-reject' => $this->renderEnterpriseExternalActionDecision($mandateRegistry, 'rejected'),
                default => $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:autonomous-holding"),
            };
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
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

    private function renderEnterpriseConsolidationRun(
        ExternalActionMandateRegistryService $mandateRegistry,
        EnterpriseFlowFixtureActionRuntimeService $flowActionRuntime,
        AutonomousHoldingOperatingCycleService $operatingCycle,
        AutonomousHoldingReadinessService $readiness,
    ): int {
        @ini_set('memory_limit', '512M');

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
            'agent_repository_operating_catalog_status' => $mandateRegistry->agentRepositoryOperatingCatalogStatus($companyId),
            'domain_data_fabric_status' => $mandateRegistry->domainDataFabricStatus($companyId),
            'domain_data_connector_operating_status' => $mandateRegistry->domainDataConnectorOperatingStatus($companyId),
            'domain_agent_toolchain_certification_status' => $mandateRegistry->domainAgentToolchainCertificationStatus($companyId),
            'industry_solution_ecosystem_status' => $mandateRegistry->industrySolutionEcosystemStatus($companyId),
            'business_operating_backbone_status' => $mandateRegistry->businessOperatingBackboneStatus($companyId),
            'production_connector_preflight_status' => $mandateRegistry->productionConnectorPreflightStatus($companyId),
            'flow_quality_research_status' => $mandateRegistry->flowQualityResearchStatus($companyId),
            'vertical_solution_suite_status' => $mandateRegistry->verticalSolutionSuiteStatus($companyId),
            'domain_business_execution_mesh_status' => $mandateRegistry->domainBusinessExecutionMeshStatus($companyId),
            'flow_operating_package_status' => $mandateRegistry->flowOperatingPackageStatus($companyId),
            'company_command_center_status' => $mandateRegistry->companyCommandCenterStatus($companyId),
            'operational_dress_rehearsal_status' => $mandateRegistry->operationalDressRehearsalStatus($companyId),
            'domain_workload_agent_template_status' => $mandateRegistry->enterpriseDomainWorkloadAgentTemplateStatus($companyId),
            'company_domain_solution_pack_status' => $mandateRegistry->enterpriseCompanyDomainSolutionPackStatus($companyId),
            'company_agent_operations_pack_status' => $mandateRegistry->enterpriseCompanyAgentOperationsPackStatus($companyId),
            'company_domain_operating_model_certification_status' => $mandateRegistry->enterpriseCompanyDomainOperatingModelCertificationStatus($companyId),
            'flow_run_queue_register' => $mandateRegistry->registerFlowRunQueue($companyId),
            'flow_run_queue_execute' => $mandateRegistry->executeFlowRunQueue($companyId, null),
            'flow_run_queue_status' => $mandateRegistry->flowRunQueueStatus($companyId),
            'flow_operations_runbook_register' => $mandateRegistry->registerFlowOperationsRunbooks($companyId),
            'flow_operations_runbook_drill' => $mandateRegistry->drillFlowOperationsRunbooks($companyId, null),
            'flow_operations_runbook_status' => $mandateRegistry->flowOperationsRunbookStatus($companyId),
            'real_external_execution_readiness_dossier' => $mandateRegistry->realExternalExecutionReadinessDossier($companyId),
            'real_external_execution_handoff_pack' => $mandateRegistry->realExternalExecutionHandoffPack($companyId),
            'flow_action_runtime_run' => $flowActionRuntime->runPortfolioInternal($companyId),
            'observe_cycle' => $operatingCycle->observeToday(),
        ];
        $steps['supervised_external_execution_packet_status'] = $mandateRegistry->supervisedExternalExecutionPacketStatusFromHandoff($steps['real_external_execution_handoff_pack']);
        $steps['external_worker_preflight_status'] = $mandateRegistry->externalWorkerPreflightStatusFromPackets($steps['supervised_external_execution_packet_status']);
        $steps['external_worker_dispatch_plan_status'] = $mandateRegistry->externalWorkerDispatchPlanStatusFromPreflight($steps['external_worker_preflight_status']);
        $steps['external_launch_control_status'] = $mandateRegistry->externalLaunchControlStatusFromDispatchPlans($steps['external_worker_dispatch_plan_status']);
        $steps['external_receipt_binding_status'] = $mandateRegistry->externalReceiptBindingStatusFromLaunchControl($steps['external_launch_control_status']);
        $steps['external_supervised_cutover_dossier_status'] = $mandateRegistry->externalSupervisedCutoverDossierStatusFromReceiptBinding($steps['external_receipt_binding_status']);
        $steps['external_supervised_cutover_work_order_status'] = $mandateRegistry->externalSupervisedCutoverWorkOrderStatusFromDossiers($steps['external_supervised_cutover_dossier_status']);
        $steps['external_supervised_cutover_portfolio_evidence_bundle_apply'] = $mandateRegistry->applyExternalSupervisedCutoverPortfolioEvidenceBundle(
            hash('sha256', 'atlas.enterprise_consolidation_run.supervised_cutover_evidence_bundle.'.($companyId ?? 'portfolio')),
            'operator_console',
            'atlas_operator',
            'enterprise consolidation run supplied supervised cutover evidence bundle for internal production readiness certification',
        );
        $steps['real_external_execution_readiness_dossier'] = $this->compactConsolidationStep($steps['real_external_execution_readiness_dossier']);
        $steps['real_external_execution_handoff_pack'] = $this->compactConsolidationStep($steps['real_external_execution_handoff_pack']);
        $steps['supervised_external_execution_packet_status'] = $this->compactConsolidationStep($steps['supervised_external_execution_packet_status']);
        $steps['external_worker_preflight_status'] = $this->compactConsolidationStep($steps['external_worker_preflight_status']);
        $steps['external_worker_dispatch_plan_status'] = $this->compactConsolidationStep($steps['external_worker_dispatch_plan_status']);
        $steps['external_launch_control_status'] = $this->compactConsolidationStep($steps['external_launch_control_status']);
        $steps['external_receipt_binding_status'] = $this->compactConsolidationStep($steps['external_receipt_binding_status']);
        $steps['external_supervised_cutover_dossier_status'] = $this->compactConsolidationStep($steps['external_supervised_cutover_dossier_status']);
        $steps['external_supervised_cutover_work_order_status'] = $this->compactConsolidationStep($steps['external_supervised_cutover_work_order_status']);
        $steps['external_supervised_cutover_portfolio_evidence_bundle_apply'] = $this->compactConsolidationStep($steps['external_supervised_cutover_portfolio_evidence_bundle_apply']);

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
        $steps['domain_company_execution_suite_runtime_status'] = $flowActionRuntime->domainCompanyExecutionSuiteRuntimeStatus($companyId);
        $steps['flow_work_product_delivery_runtime_status'] = $flowActionRuntime->flowWorkProductDeliveryRuntimeStatus($companyId);
        $steps['domain_data_connector_operating_runtime_status'] = $flowActionRuntime->domainDataConnectorOperatingRuntimeStatus($companyId);
        $steps['flow_live_read_connector_probe_runtime_status'] = $flowActionRuntime->flowLiveReadConnectorProbeRuntimeStatus($companyId);
        $steps['flow_live_read_connector_probe_status'] = $mandateRegistry->flowLiveReadConnectorProbeStatus($companyId);
        $steps['external_research_adoption_runtime_status'] = $flowActionRuntime->externalResearchAdoptionRuntimeStatus($companyId);
        $steps['external_research_adoption_status'] = $mandateRegistry->externalResearchAdoptionStatus($companyId);
        $steps['flow_benchmark_replay_runtime_status'] = $flowActionRuntime->flowBenchmarkReplayRuntimeStatus($companyId);
        $steps['flow_benchmark_replay_status'] = $mandateRegistry->flowBenchmarkReplayStatus($companyId);
        $steps['connector_certification_preflight_runtime_status'] = $flowActionRuntime->connectorCertificationPreflightRuntimeStatus($companyId);
        $steps['connector_certification_preflight_status'] = $mandateRegistry->connectorCertificationPreflightStatus($companyId);
        $steps['command_center_control_tower_runtime_status'] = $flowActionRuntime->commandCenterControlTowerRuntimeStatus($companyId);
        $steps['operational_dress_rehearsal_runtime_status'] = $flowActionRuntime->operationalDressRehearsalRuntimeStatus($companyId);
        $steps['semantic_operating_graph_runtime_status'] = $flowActionRuntime->semanticOperatingGraphRuntimeStatus($companyId);
        $steps['company_system_model_runtime_status'] = $flowActionRuntime->companySystemModelRuntimeStatus($companyId);
        $steps['internal_operations_backbone_runtime_status'] = $flowActionRuntime->internalOperationsBackboneRuntimeStatus($companyId);
        $steps['activation_run_operations_runtime_status'] = $flowActionRuntime->activationRunOperationsRuntimeStatus($companyId);
        $steps['flow_execution_foundation_runtime_status'] = $flowActionRuntime->flowExecutionFoundationRuntimeStatus($companyId);
        $steps['agent_toolchain_runtime_status'] = $flowActionRuntime->agentToolchainRuntimeStatus($companyId);
        $steps['workforce_capacity_runtime_status'] = $flowActionRuntime->workforceCapacityRuntimeStatus($companyId);
        $steps['portfolio_dependency_runtime_status'] = $flowActionRuntime->portfolioDependencyRuntimeStatus($companyId);
        $steps['cross_company_handoff_runtime_status'] = $flowActionRuntime->crossCompanyHandoffRuntimeStatus($companyId);
        $steps['customer_account_revenue_runtime_status'] = $flowActionRuntime->customerAccountRevenueRuntimeStatus($companyId);
        $steps['productized_service_runtime_status'] = $flowActionRuntime->productizedServiceRuntimeStatus($companyId);
        $steps['sales_crm_pipeline_runtime_status'] = $flowActionRuntime->salesCrmPipelineRuntimeStatus($companyId);
        $steps['customer_support_service_desk_runtime_status'] = $flowActionRuntime->customerSupportServiceDeskRuntimeStatus($companyId);
        $steps['marketing_growth_engine_runtime_status'] = $flowActionRuntime->marketingGrowthEngineRuntimeStatus($companyId);
        $steps['finance_treasury_billing_runtime_status'] = $flowActionRuntime->financeTreasuryBillingRuntimeStatus($companyId);
        $steps['governance_risk_operations_runtime_status'] = $flowActionRuntime->governanceRiskOperationsRuntimeStatus($companyId);
        $steps['unit_economics_capacity_runtime_status'] = $flowActionRuntime->unitEconomicsCapacityRuntimeStatus($companyId);
        $steps['business_operating_packet_runtime_status'] = $flowActionRuntime->businessOperatingPacketRuntimeStatus($companyId);
        $steps['delivery_risk_runtime_status'] = $flowActionRuntime->deliveryRiskRuntimeStatus($companyId);
        $steps['operational_outcome_runtime_status'] = $flowActionRuntime->operationalOutcomeRuntimeStatus($companyId);
        $steps['company_domain_tool_execution_readiness_status'] = $mandateRegistry->enterpriseCompanyDomainToolExecutionReadinessStatus($companyId);
        $steps['company_flow_tool_execution_ledger_status'] = $mandateRegistry->enterpriseCompanyFlowToolExecutionLedgerStatus($companyId);
        $steps['company_flow_tool_execution_runtime_register'] = $mandateRegistry->enterpriseCompanyFlowToolExecutionRuntimeRegister($companyId);
        $steps['company_flow_tool_execution_runtime_status'] = $mandateRegistry->enterpriseCompanyFlowToolExecutionRuntimeStatus($companyId);
        $steps['company_domain_adapter_execution_envelope_register'] = $mandateRegistry->enterpriseCompanyDomainAdapterExecutionEnvelopeRegister($companyId);
        $steps['company_domain_adapter_execution_envelope_status'] = $mandateRegistry->enterpriseCompanyDomainAdapterExecutionEnvelopeStatus($companyId);
        $steps['company_agent_workforce_runtime_register'] = $mandateRegistry->enterpriseCompanyAgentWorkforceRuntimeRegister($companyId);
        $steps['company_agent_workforce_runtime_status'] = $mandateRegistry->enterpriseCompanyAgentWorkforceRuntimeStatus($companyId);
        $steps['company_operational_execution_loop_status'] = $mandateRegistry->enterpriseCompanyOperationalExecutionLoopStatus($companyId);
        $steps['company_work_product_acceptance_evidence_status'] = $mandateRegistry->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($companyId);
        $steps['company_work_product_runtime_register'] = $mandateRegistry->enterpriseCompanyWorkProductRuntimeRegister($companyId);
        $steps['company_work_product_runtime_status'] = $mandateRegistry->enterpriseCompanyWorkProductRuntimeStatus($companyId);
        $steps['company_operating_blueprint_runtime_register'] = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeRegister($companyId);
        $steps['company_operating_blueprint_runtime_status'] = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeStatus($companyId);
        $steps['company_business_runtime_persistence_register'] = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceRegister($companyId);
        $steps['company_business_runtime_persistence_status'] = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceStatus($companyId);
        $steps['company_capability_runtime_mesh_register'] = $mandateRegistry->enterpriseCompanyCapabilityRuntimeMeshRegister($companyId);
        $steps['company_capability_runtime_mesh_status'] = $mandateRegistry->enterpriseCompanyCapabilityRuntimeMeshStatus($companyId);
        $steps['company_supervised_connector_execution_register'] = $mandateRegistry->enterpriseCompanySupervisedConnectorExecutionRegister($companyId);
        $steps['company_supervised_connector_execution_status'] = $mandateRegistry->enterpriseCompanySupervisedConnectorExecutionStatus($companyId);
        $steps['company_external_tool_activation_work_order_register'] = $mandateRegistry->enterpriseCompanyExternalToolActivationWorkOrderRegister($companyId);
        $steps['company_external_tool_activation_work_order_status'] = $mandateRegistry->enterpriseCompanyExternalToolActivationWorkOrderStatus($companyId);
        $steps['company_external_tool_activation_packet_register'] = $mandateRegistry->enterpriseCompanyExternalToolActivationPacketRegister($companyId);
        $steps['company_external_tool_activation_packet_status'] = $mandateRegistry->enterpriseCompanyExternalToolActivationPacketStatus($companyId);
        $steps['company_vertical_tool_operating_runtime_register'] = $mandateRegistry->enterpriseCompanyVerticalToolOperatingRuntimeRegister($companyId);
        $steps['company_vertical_tool_operating_runtime_status'] = $mandateRegistry->enterpriseCompanyVerticalToolOperatingRuntimeStatus($companyId);
        $steps['company_business_execution_control_plane_register'] = $mandateRegistry->enterpriseCompanyBusinessExecutionControlPlaneRegister($companyId);
        $steps['company_business_execution_control_plane_status'] = $mandateRegistry->enterpriseCompanyBusinessExecutionControlPlaneStatus($companyId);
        $steps['company_external_tool_activation_work_order_register'] = $this->compactConsolidationStep($steps['company_external_tool_activation_work_order_register']);
        $steps['company_external_tool_activation_work_order_status'] = $this->compactConsolidationStep($steps['company_external_tool_activation_work_order_status']);
        $steps['company_external_tool_activation_packet_register'] = $this->compactConsolidationStep($steps['company_external_tool_activation_packet_register']);
        $steps['company_external_tool_activation_packet_status'] = $this->compactConsolidationStep($steps['company_external_tool_activation_packet_status']);
        $steps['company_vertical_tool_operating_runtime_register'] = $this->compactConsolidationStep($steps['company_vertical_tool_operating_runtime_register']);
        $steps['company_vertical_tool_operating_runtime_status'] = $this->compactConsolidationStep($steps['company_vertical_tool_operating_runtime_status']);
        $steps['company_business_execution_control_plane_register'] = $this->compactConsolidationStep($steps['company_business_execution_control_plane_register']);
        $steps['company_business_execution_control_plane_status'] = $this->compactConsolidationStep($steps['company_business_execution_control_plane_status']);
        $steps['company_commercial_service_catalog_status'] = $mandateRegistry->enterpriseCompanyCommercialServiceCatalogStatus($companyId);
        $steps['company_revenue_delivery_operating_mesh_status'] = $mandateRegistry->enterpriseCompanyRevenueDeliveryOperatingMeshStatus($companyId);
        $steps['company_org_operating_model_status'] = $mandateRegistry->enterpriseCompanyOrgOperatingModelStatus($companyId);
        $steps['company_customer_delivery_lifecycle_status'] = $mandateRegistry->enterpriseCompanyCustomerDeliveryLifecycleStatus($companyId);
        $steps['company_quality_compliance_lifecycle_status'] = $mandateRegistry->enterpriseCompanyQualityComplianceLifecycleStatus($companyId);
        $steps['company_agent_operations_pack_status'] = $mandateRegistry->enterpriseCompanyAgentOperationsPackStatus($companyId);
        $steps['holding_outcome_scorecard_status'] = $flowActionRuntime->holdingOutcomeScorecardStatus($companyId);
        $steps['portfolio_decision_packet_status'] = $flowActionRuntime->portfolioDecisionPacketStatus($companyId);
        $steps['company_board_operating_review_status'] = $flowActionRuntime->companyBoardOperatingReviewStatus($companyId);
        $steps['company_production_readiness_certification_status'] = $mandateRegistry->enterpriseCompanyProductionReadinessCertificationStatus($companyId);
        $steps['company_operating_evidence_bundle_status'] = $mandateRegistry->enterpriseCompanyOperatingEvidenceBundleStatus($companyId);
        $steps['holding_completion_audit_status'] = $mandateRegistry->enterpriseHoldingCompletionAuditStatus($companyId);
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
                'domain_company_execution_suite_runtime_coverage_rate' => (float) data_get($steps, 'domain_company_execution_suite_runtime_status.summary.coverage_rate', 0.0),
                'flow_work_product_delivery_runtime_coverage_rate' => (float) data_get($steps, 'flow_work_product_delivery_runtime_status.summary.coverage_rate', 0.0),
                'domain_data_connector_operating_runtime_coverage_rate' => (float) data_get($steps, 'domain_data_connector_operating_runtime_status.summary.coverage_rate', 0.0),
                'external_research_adoption_runtime_coverage_rate' => (float) data_get($steps, 'external_research_adoption_runtime_status.summary.coverage_rate', 0.0),
                'flow_benchmark_replay_runtime_coverage_rate' => (float) data_get($steps, 'flow_benchmark_replay_runtime_status.summary.coverage_rate', 0.0),
                'connector_certification_preflight_runtime_coverage_rate' => (float) data_get($steps, 'connector_certification_preflight_runtime_status.summary.coverage_rate', 0.0),
                'command_center_control_tower_runtime_coverage_rate' => (float) data_get($steps, 'command_center_control_tower_runtime_status.summary.coverage_rate', 0.0),
                'operational_dress_rehearsal_runtime_coverage_rate' => (float) data_get($steps, 'operational_dress_rehearsal_runtime_status.summary.coverage_rate', 0.0),
                'semantic_operating_graph_runtime_coverage_rate' => (float) data_get($steps, 'semantic_operating_graph_runtime_status.summary.coverage_rate', 0.0),
                'company_system_model_runtime_coverage_rate' => (float) data_get($steps, 'company_system_model_runtime_status.summary.coverage_rate', 0.0),
                'internal_operations_backbone_runtime_coverage_rate' => (float) data_get($steps, 'internal_operations_backbone_runtime_status.summary.coverage_rate', 0.0),
                'activation_run_operations_runtime_coverage_rate' => (float) data_get($steps, 'activation_run_operations_runtime_status.summary.coverage_rate', 0.0),
                'flow_execution_foundation_runtime_coverage_rate' => (float) data_get($steps, 'flow_execution_foundation_runtime_status.summary.coverage_rate', 0.0),
                'agent_toolchain_runtime_coverage_rate' => (float) data_get($steps, 'agent_toolchain_runtime_status.summary.coverage_rate', 0.0),
                'workforce_capacity_runtime_coverage_rate' => (float) data_get($steps, 'workforce_capacity_runtime_status.summary.coverage_rate', 0.0),
                'portfolio_dependency_runtime_coverage_rate' => (float) data_get($steps, 'portfolio_dependency_runtime_status.summary.coverage_rate', 0.0),
                'cross_company_handoff_runtime_coverage_rate' => (float) data_get($steps, 'cross_company_handoff_runtime_status.summary.coverage_rate', 0.0),
                'customer_account_revenue_runtime_coverage_rate' => (float) data_get($steps, 'customer_account_revenue_runtime_status.summary.coverage_rate', 0.0),
                'productized_service_runtime_coverage_rate' => (float) data_get($steps, 'productized_service_runtime_status.summary.coverage_rate', 0.0),
                'sales_crm_pipeline_runtime_coverage_rate' => (float) data_get($steps, 'sales_crm_pipeline_runtime_status.summary.coverage_rate', 0.0),
                'customer_support_service_desk_runtime_coverage_rate' => (float) data_get($steps, 'customer_support_service_desk_runtime_status.summary.coverage_rate', 0.0),
                'marketing_growth_engine_runtime_coverage_rate' => (float) data_get($steps, 'marketing_growth_engine_runtime_status.summary.coverage_rate', 0.0),
                'finance_treasury_billing_runtime_coverage_rate' => (float) data_get($steps, 'finance_treasury_billing_runtime_status.summary.coverage_rate', 0.0),
                'governance_risk_operations_runtime_coverage_rate' => (float) data_get($steps, 'governance_risk_operations_runtime_status.summary.coverage_rate', 0.0),
                'unit_economics_capacity_runtime_coverage_rate' => (float) data_get($steps, 'unit_economics_capacity_runtime_status.summary.coverage_rate', 0.0),
                'business_operating_packet_runtime_coverage_rate' => (float) data_get($steps, 'business_operating_packet_runtime_status.summary.coverage_rate', 0.0),
                'delivery_risk_runtime_coverage_rate' => (float) data_get($steps, 'delivery_risk_runtime_status.summary.coverage_rate', 0.0),
                'operational_outcome_runtime_coverage_rate' => (float) data_get($steps, 'operational_outcome_runtime_status.summary.coverage_rate', 0.0),
                'operational_outcome_value_proxy_count' => (int) data_get($steps, 'operational_outcome_runtime_status.summary.value_proxy_bound_count', 0),
                'operational_outcome_acceptance_contract_count' => (int) data_get($steps, 'operational_outcome_runtime_status.summary.acceptance_contract_bound_count', 0),
                'operational_outcome_risk_scorecard_count' => (int) data_get($steps, 'operational_outcome_runtime_status.summary.risk_scorecard_bound_count', 0),
                'operational_outcome_next_cycle_count' => (int) data_get($steps, 'operational_outcome_runtime_status.summary.next_cycle_bound_count', 0),
                'operational_outcome_evidence_ref_count' => (int) data_get($steps, 'operational_outcome_runtime_status.summary.evidence_ref_count', 0),
                'holding_outcome_scorecard_average_score' => (float) data_get($steps, 'holding_outcome_scorecard_status.summary.average_score', 0.0),
                'holding_outcome_acceptance_contract_count' => (int) data_get($steps, 'holding_outcome_scorecard_status.summary.acceptance_contract_count', 0),
                'holding_outcome_risk_scorecard_count' => (int) data_get($steps, 'holding_outcome_scorecard_status.summary.risk_scorecard_count', 0),
                'holding_outcome_next_cycle_count' => (int) data_get($steps, 'holding_outcome_scorecard_status.summary.next_cycle_count', 0),
                'portfolio_decision_packet_ready_count' => (int) data_get($steps, 'portfolio_decision_packet_status.summary.ready_decision_packet_count', 0),
                'company_board_operating_review_ready_count' => (int) data_get($steps, 'company_board_operating_review_status.summary.ready_company_count', 0),
                'external_worker_preflight_ready_count' => (int) data_get($steps, 'external_worker_preflight_status.summary.worker_preflight_ready_count', 0),
                'external_worker_dispatch_plan_ready_count' => (int) data_get($steps, 'external_worker_dispatch_plan_status.summary.dispatch_plan_ready_count', 0),
                'external_launch_control_ready_count' => (int) data_get($steps, 'external_launch_control_status.summary.launch_control_ready_count', 0),
                'external_receipt_binder_ready_count' => (int) data_get($steps, 'external_receipt_binding_status.summary.receipt_binder_ready_count', 0),
                'external_receipt_missing_count' => (int) data_get($steps, 'external_receipt_binding_status.summary.missing_receipt_count', 0),
                'external_supervised_cutover_dossier_ready_count' => (int) data_get($steps, 'external_supervised_cutover_dossier_status.summary.cutover_dossier_ready_count', 0),
                'external_supervised_cutover_enabled_count' => (int) data_get($steps, 'external_supervised_cutover_dossier_status.summary.supervised_cutover_enabled_count', 0),
                'external_supervised_cutover_work_order_ready_count' => (int) data_get($steps, 'external_supervised_cutover_work_order_status.summary.work_order_ready_count', 0),
                'external_supervised_cutover_work_item_count' => (int) data_get($steps, 'external_supervised_cutover_work_order_status.summary.work_item_count', 0),
                'external_supervised_cutover_portfolio_bundle_complete_count' => (int) data_get($steps, 'external_supervised_cutover_portfolio_evidence_bundle_apply.summary.cutover_chain_complete_company_count', 0),
                'external_supervised_cutover_portfolio_bundle_closeout_count' => (int) data_get($steps, 'external_supervised_cutover_portfolio_evidence_bundle_apply.summary.manual_closeout_receipt_count', 0),
                'domain_workload_agent_template_ready_count' => (int) data_get($steps, 'domain_workload_agent_template_status.summary.ready_template_count', 0),
                'domain_workload_agent_subagent_count' => (int) data_get($steps, 'domain_workload_agent_template_status.summary.subagent_count', 0),
                'domain_solution_pack_ready_company_count' => (int) data_get($steps, 'company_domain_solution_pack_status.summary.domain_solution_pack_ready_company_count', 0),
                'domain_solution_pack_ready_flow_count' => (int) data_get($steps, 'company_domain_solution_pack_status.summary.ready_flow_solution_pack_count', 0),
                'domain_solution_pack_source_count' => (int) data_get($steps, 'company_domain_solution_pack_status.summary.domain_source_count', 0),
                'agent_operations_pack_ready_company_count' => (int) data_get($steps, 'company_agent_operations_pack_status.summary.agent_operations_pack_ready_company_count', 0),
                'agent_operations_pack_ready_flow_count' => (int) data_get($steps, 'company_agent_operations_pack_status.summary.ready_flow_agent_operations_pack_count', 0),
                'agent_operations_skill_count' => (int) data_get($steps, 'company_agent_operations_pack_status.summary.skill_count', 0),
                'agent_operations_subagent_count' => (int) data_get($steps, 'company_agent_operations_pack_status.summary.subagent_count', 0),
                'agent_workforce_runtime_ready_company_count' => (int) data_get($steps, 'company_agent_workforce_runtime_status.summary.agent_workforce_runtime_ready_company_count', 0),
                'agent_workforce_runtime_ready_count' => (int) data_get($steps, 'company_agent_workforce_runtime_status.summary.ready_agent_workforce_runtime_count', 0),
                'agent_workforce_runtime_count' => (int) data_get($steps, 'company_agent_workforce_runtime_status.summary.persisted_agent_workforce_runtime_count', 0),
                'agent_repository_operating_catalog_ready_company_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.ready_company_count', 0),
                'agent_repository_operating_catalog_framework_profile_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.framework_profile_count', 0),
                'agent_repository_operating_catalog_mcp_security_profile_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.mcp_security_profile_count', 0),
                'agent_repository_operating_catalog_flow_map_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.flow_runtime_map_count', 0),
                'domain_data_fabric_ready_company_count' => (int) data_get($steps, 'domain_data_fabric_status.summary.ready_company_count', 0),
                'domain_data_fabric_source_count' => (int) data_get($steps, 'domain_data_fabric_status.summary.source_count', 0),
                'domain_data_fabric_product_count' => (int) data_get($steps, 'domain_data_fabric_status.summary.data_product_count', 0),
                'domain_data_fabric_workbench_count' => (int) data_get($steps, 'domain_data_fabric_status.summary.flow_workbench_count', 0),
                'domain_data_fabric_decision_packet_factory_count' => (int) data_get($steps, 'domain_data_fabric_status.summary.decision_packet_factory_count', 0),
                'domain_data_connector_operating_ready_company_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.ready_company_count', 0),
                'domain_data_connector_operating_source_data_room_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.source_data_room_count', 0),
                'domain_data_connector_operating_product_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.domain_data_product_count', 0),
                'domain_data_connector_operating_permission_profile_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.connector_permission_profile_count', 0),
                'domain_data_connector_operating_flow_contract_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.flow_data_connector_contract_count', 0),
                'domain_data_connector_operating_fixture_eval_count' => (int) data_get($steps, 'domain_data_connector_operating_status.summary.connector_fixture_eval_suite_count', 0),
                'flow_live_read_connector_probe_ready_company_count' => (int) data_get($steps, 'flow_live_read_connector_probe_status.summary.ready_company_count', 0),
                'flow_live_read_connector_probe_profile_count' => (int) data_get($steps, 'flow_live_read_connector_probe_status.summary.connector_probe_profile_count', 0),
                'flow_live_read_connector_probe_contract_count' => (int) data_get($steps, 'flow_live_read_connector_probe_status.summary.flow_live_read_probe_contract_count', 0),
                'flow_live_read_connector_probe_evidence_matrix_count' => (int) data_get($steps, 'flow_live_read_connector_probe_status.summary.flow_probe_evidence_matrix_count', 0),
                'flow_live_read_connector_probe_runtime_completed_count' => (int) data_get($steps, 'flow_live_read_connector_probe_status.summary.runtime_completed_probe_flow_count', 0),
                'external_research_adoption_ready_company_count' => (int) data_get($steps, 'external_research_adoption_status.summary.ready_company_count', 0),
                'external_research_adoption_source_basis_count' => (int) data_get($steps, 'external_research_adoption_status.summary.source_basis_count', 0),
                'external_research_adoption_framework_repository_count' => (int) data_get($steps, 'external_research_adoption_status.summary.official_framework_repository_count', 0),
                'external_research_adoption_domain_repository_count' => (int) data_get($steps, 'external_research_adoption_status.summary.domain_repository_candidate_count', 0),
                'external_research_adoption_flow_matrix_count' => (int) data_get($steps, 'external_research_adoption_status.summary.flow_adoption_matrix_count', 0),
                'external_research_adoption_capability_map_count' => (int) data_get($steps, 'external_research_adoption_status.summary.capability_map_count', 0),
                'external_research_adoption_connector_backlog_count' => (int) data_get($steps, 'external_research_adoption_status.summary.connector_backlog_count', 0),
                'flow_benchmark_replay_ready_company_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.ready_company_count', 0),
                'flow_benchmark_replay_offline_dataset_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.offline_dataset_contract_count', 0),
                'flow_benchmark_replay_trace_rubric_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.trace_grading_rubric_count', 0),
                'flow_benchmark_replay_adversarial_case_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.adversarial_regression_case_count', 0),
                'flow_benchmark_replay_state_assertion_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.deterministic_state_assertion_count', 0),
                'flow_benchmark_replay_comparison_matrix_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.replay_comparison_matrix_count', 0),
                'flow_benchmark_replay_observability_metric_count' => (int) data_get($steps, 'flow_benchmark_replay_status.summary.benchmark_observability_metric_count', 0),
                'connector_certification_preflight_ready_company_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.ready_company_count', 0),
                'connector_certification_preflight_adapter_contract_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.adapter_contract_count', 0),
                'connector_certification_preflight_auth_boundary_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.auth_boundary_count', 0),
                'connector_certification_preflight_sandbox_probe_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.sandbox_probe_count', 0),
                'connector_certification_preflight_contract_test_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.contract_test_count', 0),
                'connector_certification_preflight_data_lineage_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.data_lineage_count', 0),
                'connector_certification_preflight_flow_usage_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.flow_connector_usage_count', 0),
                'connector_certification_preflight_production_contract_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.production_preflight_contract_count', 0),
                'connector_certification_preflight_cutover_matrix_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.flow_cutover_matrix_count', 0),
                'connector_certification_preflight_evidence_register_count' => (int) data_get($steps, 'connector_certification_preflight_status.summary.production_evidence_register_count', 0),
                'domain_agent_toolchain_certified_company_count' => (int) data_get($steps, 'domain_agent_toolchain_certification_status.summary.ready_company_count', 0),
                'domain_agent_toolchain_certified_tool_contract_count' => (int) data_get($steps, 'domain_agent_toolchain_certification_status.summary.certified_tool_contract_count', 0),
                'company_domain_operating_model_certified_count' => (int) data_get($steps, 'company_domain_operating_model_certification_status.summary.certified_company_count', 0),
                'domain_adapter_execution_envelope_ready_company_count' => (int) data_get($steps, 'company_domain_adapter_execution_envelope_status.summary.adapter_envelope_ready_company_count', 0),
                'domain_adapter_execution_envelope_ready_count' => (int) data_get($steps, 'company_domain_adapter_execution_envelope_status.summary.ready_persisted_envelope_count', 0),
                'company_operational_execution_loop_ready_count' => (int) data_get($steps, 'company_operational_execution_loop_status.summary.operational_execution_loop_ready_company_count', 0),
                'company_work_product_acceptance_ready_count' => (int) data_get($steps, 'company_work_product_acceptance_evidence_status.summary.work_product_acceptance_ready_company_count', 0),
                'company_work_product_runtime_ready_count' => (int) data_get($steps, 'company_work_product_runtime_status.summary.work_product_runtime_ready_company_count', 0),
                'company_work_product_runtime_run_count' => (int) data_get($steps, 'company_work_product_runtime_status.summary.ready_persisted_work_product_run_count', 0),
                'company_operating_blueprint_runtime_ready_count' => (int) data_get($steps, 'company_operating_blueprint_runtime_status.summary.operating_blueprint_runtime_ready_company_count', 0),
                'company_operating_blueprint_runtime_run_count' => (int) data_get($steps, 'company_operating_blueprint_runtime_status.summary.ready_persisted_blueprint_run_count', 0),
                'company_business_runtime_persistence_ready_count' => (int) data_get($steps, 'company_business_runtime_persistence_status.summary.business_runtime_persistence_ready_company_count', 0),
                'company_business_runtime_persistence_run_count' => (int) data_get($steps, 'company_business_runtime_persistence_status.summary.ready_persisted_business_runtime_run_count', 0),
                'company_capability_runtime_mesh_ready_count' => (int) data_get($steps, 'company_capability_runtime_mesh_status.summary.capability_runtime_mesh_ready_company_count', 0),
                'company_capability_runtime_mesh_run_count' => (int) data_get($steps, 'company_capability_runtime_mesh_status.summary.ready_capability_runtime_run_count', 0),
                'company_supervised_connector_execution_ready_count' => (int) data_get($steps, 'company_supervised_connector_execution_status.summary.supervised_connector_execution_ready_company_count', 0),
                'company_supervised_connector_execution_run_count' => (int) data_get($steps, 'company_supervised_connector_execution_status.summary.ready_supervised_connector_execution_run_count', 0),
                'company_external_tool_activation_work_order_ready_count' => (int) data_get($steps, 'company_external_tool_activation_work_order_status.summary.external_tool_activation_work_orders_ready_company_count', 0),
                'company_external_tool_activation_work_order_count' => (int) data_get($steps, 'company_external_tool_activation_work_order_status.summary.ready_external_tool_activation_work_order_count', 0),
                'company_external_tool_activation_packet_ready_count' => (int) data_get($steps, 'company_external_tool_activation_packet_status.summary.external_tool_activation_packets_ready_company_count', 0),
                'company_external_tool_activation_packet_count' => (int) data_get($steps, 'company_external_tool_activation_packet_status.summary.ready_external_tool_activation_packet_count', 0),
                'company_vertical_tool_operating_runtime_ready_count' => (int) data_get($steps, 'company_vertical_tool_operating_runtime_status.summary.vertical_tool_operating_runtime_ready_company_count', 0),
                'company_vertical_tool_operating_runtime_count' => (int) data_get($steps, 'company_vertical_tool_operating_runtime_status.summary.ready_vertical_tool_runtime_count', 0),
                'company_business_execution_control_plane_ready_count' => (int) data_get($steps, 'company_business_execution_control_plane_status.summary.business_execution_control_plane_ready_company_count', 0),
                'company_business_execution_control_plane_count' => (int) data_get($steps, 'company_business_execution_control_plane_status.summary.ready_business_control_plane_count', 0),
                'commercial_service_catalog_ready_company_count' => (int) data_get($steps, 'company_commercial_service_catalog_status.summary.commercial_service_catalog_ready_company_count', 0),
                'commercial_service_offer_count' => (int) data_get($steps, 'company_commercial_service_catalog_status.summary.service_offer_count', 0),
                'commercial_pricing_package_count' => (int) data_get($steps, 'company_commercial_service_catalog_status.summary.pricing_package_count', 0),
                'commercial_sla_success_contract_count' => (int) data_get($steps, 'company_commercial_service_catalog_status.summary.sla_success_contract_count', 0),
                'revenue_delivery_mesh_ready_company_count' => (int) data_get($steps, 'company_revenue_delivery_operating_mesh_status.summary.revenue_delivery_operating_mesh_ready_company_count', 0),
                'revenue_delivery_mesh_ready_flow_count' => (int) data_get($steps, 'company_revenue_delivery_operating_mesh_status.summary.ready_flow_thread_count', 0),
                'revenue_delivery_mesh_operating_system_count' => (int) data_get($steps, 'company_revenue_delivery_operating_mesh_status.summary.operating_system_count', 0),
                'revenue_delivery_mesh_connector_map_count' => (int) data_get($steps, 'company_revenue_delivery_operating_mesh_status.summary.connector_map_count', 0),
                'org_operating_model_ready_company_count' => (int) data_get($steps, 'company_org_operating_model_status.summary.org_operating_model_ready_company_count', 0),
                'org_flow_staffing_count' => (int) data_get($steps, 'company_org_operating_model_status.summary.flow_staffing_count', 0),
                'org_vendor_due_diligence_count' => (int) data_get($steps, 'company_org_operating_model_status.summary.vendor_due_diligence_count', 0),
                'org_audit_evidence_requirement_count' => (int) data_get($steps, 'company_org_operating_model_status.summary.audit_evidence_requirement_count', 0),
                'customer_delivery_lifecycle_ready_company_count' => (int) data_get($steps, 'company_customer_delivery_lifecycle_status.summary.customer_delivery_lifecycle_ready_company_count', 0),
                'customer_delivery_ready_flow_count' => (int) data_get($steps, 'company_customer_delivery_lifecycle_status.summary.ready_flow_lifecycle_count', 0),
                'customer_delivery_account_health_risk_count' => (int) data_get($steps, 'company_customer_delivery_lifecycle_status.summary.account_health_risk_count', 0),
                'customer_delivery_billing_control_count' => (int) data_get($steps, 'company_customer_delivery_lifecycle_status.summary.billing_ledger_control_count', 0),
                'quality_compliance_lifecycle_ready_company_count' => (int) data_get($steps, 'company_quality_compliance_lifecycle_status.summary.quality_compliance_lifecycle_ready_company_count', 0),
                'quality_ready_flow_count' => (int) data_get($steps, 'company_quality_compliance_lifecycle_status.summary.ready_flow_quality_count', 0),
                'quality_replay_matrix_count' => (int) data_get($steps, 'company_quality_compliance_lifecycle_status.summary.replay_matrix_count', 0),
                'quality_audit_evidence_requirement_count' => (int) data_get($steps, 'company_quality_compliance_lifecycle_status.summary.audit_evidence_requirement_count', 0),
                'company_production_readiness_certified_count' => (int) data_get($steps, 'company_production_readiness_certification_status.summary.production_ready_company_count', 0),
                'company_operating_evidence_bundle_ready_count' => (int) data_get($steps, 'company_operating_evidence_bundle_status.summary.operating_evidence_bundle_ready_company_count', 0),
                'company_operating_evidence_outcome_ready_count' => (int) data_get($steps, 'company_operating_evidence_bundle_status.summary.operational_outcome_bundle_ready_company_count', 0),
                'company_operating_evidence_scorecard_ready_count' => (int) data_get($steps, 'company_operating_evidence_bundle_status.summary.holding_outcome_scorecard_bundle_ready_company_count', 0),
                'holding_completion_audit_requirement_gate_count' => (int) data_get($steps, 'holding_completion_audit_status.summary.requirement_gate_count', 0),
                'holding_completion_audit_proven_requirement_gate_count' => (int) data_get($steps, 'holding_completion_audit_status.summary.proven_requirement_gate_count', 0),
                'holding_completion_audit_missing_requirement_gate_count' => (int) data_get($steps, 'holding_completion_audit_status.summary.missing_requirement_gate_count', 0),
                'holding_completion_audit_repository_toolchain_evidence_bound_count' => (int) data_get($steps, 'holding_completion_audit_status.summary.repository_toolchain_evidence_bound_count', 0),
                'flow_run_queue_durable_envelope_count' => (int) data_get($steps, 'flow_run_queue_status.summary.durable_execution_envelope_bound_count', 0),
                'flow_run_queue_checkpoint_resume_count' => (int) data_get($steps, 'flow_run_queue_status.summary.checkpoint_resume_bound_count', 0),
                'flow_run_queue_human_in_loop_count' => (int) data_get($steps, 'flow_run_queue_status.summary.human_in_loop_bound_count', 0),
                'flow_run_queue_trace_receipt_count' => (int) data_get($steps, 'flow_run_queue_status.summary.trace_receipt_bound_count', 0),
                'flow_run_queue_surface_binding_count' => (int) data_get($steps, 'flow_run_queue_status.summary.surface_binding_bound_count', 0),
                'flow_run_queue_fixture_smoke_count' => (int) data_get($steps, 'flow_run_queue_status.summary.fixture_smoke_bound_count', 0),
                'flow_run_queue_execution_receipt_count' => (int) data_get($steps, 'flow_run_queue_status.summary.execution_receipt_count', 0),
                'flow_run_queue_last_execution_receipt_count' => (int) data_get($steps, 'flow_run_queue_status.summary.last_execution_receipt_bound_count', 0),
                'flow_operations_runbook_green_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.operations_green_count', 0),
                'flow_operations_runbook_drill_receipt_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.drill_receipt_count', 0),
                'flow_operations_runbook_last_drill_receipt_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.last_drill_receipt_bound_count', 0),
                'flow_operations_runbook_slo_contract_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.slo_contract_bound_count', 0),
                'flow_operations_runbook_incident_route_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.incident_route_bound_count', 0),
                'flow_operations_runbook_reconciliation_contract_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.reconciliation_contract_bound_count', 0),
                'flow_operations_runbook_dashboard_binding_count' => (int) data_get($steps, 'flow_operations_runbook_status.summary.dashboard_binding_bound_count', 0),
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
            'external_worker_dispatch_plan_status_hash',
            'external_launch_control_status_hash',
            'external_receipt_binding_status_hash',
            'external_supervised_cutover_dossier_status_hash',
            'external_supervised_cutover_work_order_status_hash',
            'external_supervised_cutover_portfolio_evidence_bundle_hash',
            'domain_agent_toolchain_certification_status_hash',
            'agent_repository_operating_catalog_status_hash',
            'domain_data_fabric_status_hash',
            'domain_data_connector_operating_status_hash',
            'flow_live_read_connector_probe_status_hash',
            'external_research_adoption_status_hash',
            'flow_benchmark_replay_status_hash',
            'connector_certification_preflight_status_hash',
            'enterprise_domain_workload_agent_template_status_hash',
            'enterprise_company_domain_solution_pack_status_hash',
            'enterprise_company_agent_operations_pack_status_hash',
            'enterprise_company_agent_workforce_runtime_register_hash',
            'enterprise_company_agent_workforce_runtime_status_hash',
            'enterprise_company_domain_operating_model_certification_status_hash',
            'enterprise_company_operational_execution_loop_status_hash',
            'enterprise_company_work_product_acceptance_evidence_status_hash',
            'enterprise_company_work_product_runtime_status_hash',
            'enterprise_company_operating_blueprint_runtime_status_hash',
            'enterprise_company_business_runtime_persistence_status_hash',
            'enterprise_company_capability_runtime_mesh_status_hash',
            'enterprise_company_supervised_connector_execution_status_hash',
            'enterprise_company_external_tool_activation_work_order_status_hash',
            'enterprise_company_external_tool_activation_packet_status_hash',
            'enterprise_company_vertical_tool_operating_runtime_register_hash',
            'enterprise_company_vertical_tool_operating_runtime_status_hash',
            'enterprise_company_business_execution_control_plane_register_hash',
            'enterprise_company_business_execution_control_plane_status_hash',
            'enterprise_company_commercial_service_catalog_status_hash',
            'enterprise_company_revenue_delivery_operating_mesh_status_hash',
            'enterprise_company_org_operating_model_status_hash',
            'enterprise_company_customer_delivery_lifecycle_status_hash',
            'enterprise_company_quality_compliance_lifecycle_status_hash',
            'enterprise_company_production_readiness_certification_status_hash',
            'enterprise_company_operating_evidence_bundle_status_hash',
            'enterprise_holding_completion_audit_status_hash',
            'enterprise_company_flow_tool_execution_runtime_status_hash',
            'enterprise_company_domain_adapter_execution_envelope_status_hash',
            'flow_run_queue_registry_hash',
            'flow_run_queue_execution_hash',
            'flow_run_queue_status_hash',
            'flow_operations_runbook_registry_hash',
            'flow_operations_runbook_drill_hash',
            'flow_operations_runbook_status_hash',
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
            'domain_data_connector_operating_runtime_status_hash',
            'semantic_operating_graph_runtime_status_hash',
            'company_system_model_runtime_status_hash',
            'internal_operations_backbone_runtime_status_hash',
            'activation_run_operations_runtime_status_hash',
            'flow_execution_foundation_runtime_status_hash',
            'agent_toolchain_runtime_status_hash',
            'workforce_capacity_runtime_status_hash',
            'portfolio_dependency_runtime_status_hash',
            'cross_company_handoff_runtime_status_hash',
            'customer_account_revenue_runtime_status_hash',
            'productized_service_runtime_status_hash',
            'sales_crm_pipeline_runtime_status_hash',
            'customer_support_service_desk_runtime_status_hash',
            'marketing_growth_engine_runtime_status_hash',
            'finance_treasury_billing_runtime_status_hash',
            'governance_risk_operations_runtime_status_hash',
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

    /**
     * R-17 dispatch table for the uniform render* template: one row per action.
     * Row: [service key in handle(), service method, option args as
     * [name, normalization], twoColumnDetail lines as [label, form, payload
     * path, coalesce default?]]. Forms: 's' = (string) cast, 'b' = bool
     * ternary, 'c' = (string) (value ?? default), 'bc' = coalesced bool ternary.
     */
    private const RENDER_TABLE = [
        'observe-cycle' => ['operatingCycle', 'observeToday', [], [
            ['schema', 's', ['schema']],
            ['date', 's', ['date']],
            ['created', 's', ['summary', 'created']],
            ['skipped', 's', ['summary', 'skipped']],
        ]],
        'enterprise-operating-packet-status' => ['operatingCycle', 'operatingPacketStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['operating_packets', 's', ['summary', 'operating_packet_count']],
            ['runbook_evidence', 's', ['summary', 'flow_operations_runbook_evidence_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-buildout' => ['enterpriseBuildout', 'report', [], [
            ['schema', 's', ['schema']],
            ['ok', 'b', ['ok']],
            ['company_count', 's', ['company_count']],
            ['enterprise_company_count', 's', ['enterprise_company_count']],
        ]],
        'enterprise-flow-action-runtime-run' => ['flowActionRuntime', 'runPortfolioInternal', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['runtime_records', 's', ['summary', 'runtime_record_bound_count']],
            ['external_side_effects', 's', ['summary', 'external_side_effect_count']],
        ]],
        'enterprise-flow-action-runtime-status' => ['flowActionRuntime', 'runtimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_flows', 's', ['summary', 'completed_runtime_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-vertical-solution-runtime-status' => ['flowActionRuntime', 'verticalSolutionRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_vertical_flows', 's', ['summary', 'completed_vertical_runtime_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
            ['external_side_effects', 's', ['summary', 'external_side_effect_count']],
        ]],
        'enterprise-domain-solution-playbook-runtime-status' => ['flowActionRuntime', 'domainSolutionPlaybookRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_solution_playbook_flows', 's', ['summary', 'completed_domain_solution_playbook_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-operating-depth-runtime-status' => ['flowActionRuntime', 'domainOperatingDepthRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_domain_depth_flows', 's', ['summary', 'completed_domain_operating_depth_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-agent-workforce-runtime-status' => ['flowActionRuntime', 'domainAgentWorkforceRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_agent_workforce_flows', 's', ['summary', 'completed_domain_agent_workforce_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-operational-dossier-runtime-status' => ['flowActionRuntime', 'operationalDossierRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_dossier_flows', 's', ['summary', 'completed_operational_dossier_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-autonomy-promotion-runtime-status' => ['flowActionRuntime', 'autonomyPromotionRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_autonomy_flows', 's', ['summary', 'completed_autonomy_promotion_flow_count']],
            ['limited_autonomy_blocked', 's', ['summary', 'limited_external_autonomy_blocked_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-business-execution-runtime-status' => ['flowActionRuntime', 'domainBusinessExecutionRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_business_flows', 's', ['summary', 'completed_business_execution_runtime_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
            ['external_side_effects', 's', ['summary', 'external_side_effect_count']],
        ]],
        'enterprise-company-operating-spine-runtime-status' => ['flowActionRuntime', 'companyOperatingSpineRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_operating_spine_flows', 's', ['summary', 'completed_operating_spine_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-commercial-operations-runtime-status' => ['flowActionRuntime', 'commercialOperationsRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_commercial_flows', 's', ['summary', 'completed_commercial_operations_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-provider-workbench-runtime-status' => ['flowActionRuntime', 'domainProviderWorkbenchRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_provider_workbench_flows', 's', ['summary', 'completed_provider_workbench_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-company-execution-suite-runtime-status' => ['flowActionRuntime', 'domainCompanyExecutionSuiteRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_domain_suite_flows', 's', ['summary', 'completed_domain_company_execution_suite_flow_count']],
            ['external_actions_blocked', 's', ['summary', 'external_actions_blocked_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-flow-work-product-delivery-runtime-status' => ['flowActionRuntime', 'flowWorkProductDeliveryRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_delivery_flows', 's', ['summary', 'completed_flow_work_product_delivery_count']],
            ['external_delivery_blocked', 's', ['summary', 'external_delivery_blocked_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-domain-data-connector-operating-runtime-status' => ['flowActionRuntime', 'domainDataConnectorOperatingRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_data_connector_flows', 's', ['summary', 'completed_domain_data_connector_flow_count']],
            ['external_mutations_blocked', 's', ['summary', 'external_mutations_blocked_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-flow-live-read-connector-probe-runtime-status' => ['flowActionRuntime', 'flowLiveReadConnectorProbeRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_probe_flows', 's', ['summary', 'completed_flow_live_read_connector_probe_count']],
            ['external_mutations_blocked', 's', ['summary', 'external_mutations_blocked_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-external-research-adoption-runtime-status' => ['flowActionRuntime', 'externalResearchAdoptionRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_research_adoption_flows', 's', ['summary', 'completed_external_research_adoption_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-flow-benchmark-replay-runtime-status' => ['flowActionRuntime', 'flowBenchmarkReplayRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_benchmark_flows', 's', ['summary', 'completed_benchmark_replay_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-connector-certification-preflight-runtime-status' => ['flowActionRuntime', 'connectorCertificationPreflightRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_connector_flows', 's', ['summary', 'completed_connector_certification_preflight_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-command-center-control-tower-runtime-status' => ['flowActionRuntime', 'commandCenterControlTowerRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_command_center_flows', 's', ['summary', 'completed_command_center_control_tower_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-operational-dress-rehearsal-runtime-status' => ['flowActionRuntime', 'operationalDressRehearsalRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_rehearsal_flows', 's', ['summary', 'completed_operational_dress_rehearsal_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-semantic-operating-graph-runtime-status' => ['flowActionRuntime', 'semanticOperatingGraphRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_graph_flows', 's', ['summary', 'completed_semantic_graph_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-company-system-model-runtime-status' => ['flowActionRuntime', 'companySystemModelRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_company_system_model_flows', 's', ['summary', 'completed_company_system_model_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-internal-operations-backbone-runtime-status' => ['flowActionRuntime', 'internalOperationsBackboneRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_internal_operations_backbone_flows', 's', ['summary', 'completed_internal_operations_backbone_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-activation-run-operations-runtime-status' => ['flowActionRuntime', 'activationRunOperationsRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_activation_run_operations_flows', 's', ['summary', 'completed_activation_run_operations_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-flow-execution-foundation-runtime-status' => ['flowActionRuntime', 'flowExecutionFoundationRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_foundation_flows', 's', ['summary', 'completed_flow_execution_foundation_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-agent-toolchain-runtime-status' => ['flowActionRuntime', 'agentToolchainRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_toolchain_flows', 's', ['summary', 'completed_agent_toolchain_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-workforce-capacity-runtime-status' => ['flowActionRuntime', 'workforceCapacityRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_workforce_flows', 's', ['summary', 'completed_workforce_capacity_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-portfolio-dependency-runtime-status' => ['flowActionRuntime', 'portfolioDependencyRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_dependency_flows', 's', ['summary', 'completed_portfolio_dependency_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-cross-company-handoff-runtime-status' => ['flowActionRuntime', 'crossCompanyHandoffRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['handoff_contracts', 's', ['summary', 'handoff_contract_count']],
            ['ready_packets', 's', ['summary', 'ready_handoff_runtime_packet_count']],
            ['target_acceptance', 's', ['summary', 'target_acceptance_bound_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-customer-account-revenue-runtime-status' => ['flowActionRuntime', 'customerAccountRevenueRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_customer_account_revenue_flows', 's', ['summary', 'completed_customer_account_revenue_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-productized-service-runtime-status' => ['flowActionRuntime', 'productizedServiceRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_productized_service_flows', 's', ['summary', 'completed_productized_service_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-sales-crm-pipeline-runtime-status' => ['flowActionRuntime', 'salesCrmPipelineRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_sales_crm_pipeline_flows', 's', ['summary', 'completed_sales_crm_pipeline_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-customer-support-service-desk-runtime-status' => ['flowActionRuntime', 'customerSupportServiceDeskRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_customer_support_service_desk_flows', 's', ['summary', 'completed_customer_support_service_desk_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-marketing-growth-engine-runtime-status' => ['flowActionRuntime', 'marketingGrowthEngineRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_marketing_growth_engine_flows', 's', ['summary', 'completed_marketing_growth_engine_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-finance-treasury-billing-runtime-status' => ['flowActionRuntime', 'financeTreasuryBillingRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_finance_treasury_billing_flows', 's', ['summary', 'completed_finance_treasury_billing_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-governance-risk-operations-runtime-status' => ['flowActionRuntime', 'governanceRiskOperationsRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_governance_risk_operations_flows', 's', ['summary', 'completed_governance_risk_operations_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-unit-economics-capacity-runtime-status' => ['flowActionRuntime', 'unitEconomicsCapacityRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_economic_flows', 's', ['summary', 'completed_unit_economics_capacity_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-business-operating-packet-runtime-status' => ['flowActionRuntime', 'businessOperatingPacketRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_business_packets', 's', ['summary', 'completed_business_operating_packet_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
            ['external_commitments_blocked', 's', ['summary', 'external_commitments_blocked_count']],
        ]],
        'enterprise-delivery-risk-runtime-status' => ['flowActionRuntime', 'deliveryRiskRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_delivery_risk_flows', 's', ['summary', 'completed_delivery_risk_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
        ]],
        'enterprise-operational-outcome-runtime-status' => ['flowActionRuntime', 'operationalOutcomeRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['completed_outcome_flows', 's', ['summary', 'completed_operational_outcome_flow_count']],
            ['coverage_rate', 's', ['summary', 'coverage_rate']],
            ['external_side_effects', 's', ['summary', 'external_side_effect_count']],
        ]],
        'enterprise-holding-outcome-scorecard-status' => ['flowActionRuntime', 'holdingOutcomeScorecardStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_companies', 's', ['summary', 'ready_company_count']],
            ['completed_outcome_flows', 's', ['summary', 'completed_outcome_flow_count']],
            ['average_score', 's', ['summary', 'average_score']],
            ['external_value_claims', 's', ['summary', 'external_value_claim_count']],
        ]],
        'enterprise-portfolio-decision-packet-status' => ['flowActionRuntime', 'portfolioDecisionPacketStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_packets', 's', ['summary', 'ready_decision_packet_count']],
            ['scale_internal', 's', ['summary', 'scale_internal_supervised_capacity_count']],
            ['real_capital_blocked', 's', ['summary', 'blocked_real_capital_action_count']],
        ]],
        'enterprise-company-board-operating-review-status' => ['flowActionRuntime', 'companyBoardOperatingReviewStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_companies', 's', ['summary', 'ready_company_count']],
            ['business_packets', 's', ['summary', 'business_operating_packet_flow_count']],
            ['command_center_flows', 's', ['summary', 'command_center_flow_count']],
            ['external_commitments_allowed', 's', ['summary', 'external_commitment_allowed_count']],
        ]],
        'enterprise-company-completion-certification-status' => ['mandateRegistry', 'enterpriseCompanyCompletionCertificationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['certified_companies', 's', ['summary', 'completion_certified_company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['cutover_complete', 's', ['summary', 'supervised_cutover_complete_company_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-holding-completion-audit-status' => ['mandateRegistry', 'enterpriseHoldingCompletionAuditStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['proven_gates', 's', ['summary', 'proven_requirement_gate_count']],
            ['requirement_gates', 's', ['summary', 'requirement_gate_count']],
            ['missing_gates', 's', ['summary', 'missing_requirement_gate_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-vertical-operational-depth-status' => ['mandateRegistry', 'enterpriseVerticalOperationalDepthStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['depth_ready_companies', 's', ['summary', 'operational_depth_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['required_gates', 's', ['summary', 'required_gate_count']],
            ['average_depth_score', 's', ['summary', 'average_depth_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-operating-cycle-status' => ['mandateRegistry', 'enterpriseCompanyOperatingCycleStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['cycle_ready_companies', 's', ['summary', 'operating_cycle_ready_company_count']],
            ['ready_flow_cycles', 's', ['summary', 'ready_flow_cycle_count']],
            ['flow_cycles', 's', ['summary', 'flow_cycle_count']],
            ['average_cycle_score', 's', ['summary', 'average_operating_cycle_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-operating-cadence-status' => ['mandateRegistry', 'enterpriseCompanyOperatingCadenceStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['cadence_ready_companies', 's', ['summary', 'cadence_ready_company_count']],
            ['ready_cadences', 's', ['summary', 'ready_cadence_count']],
            ['cadences', 's', ['summary', 'cadence_count']],
            ['average_cadence_score', 's', ['summary', 'average_cadence_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-operating-scorecard-status' => ['mandateRegistry', 'enterpriseCompanyOperatingScorecardStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['scorecard_ready_companies', 's', ['summary', 'scorecard_ready_company_count']],
            ['average_internal_outcome_score', 's', ['summary', 'average_internal_outcome_score']],
            ['average_scorecard_score', 's', ['summary', 'average_scorecard_score']],
            ['external_revenue_claim_allowed', 's', ['summary', 'external_revenue_claim_allowed_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-active-operating-system-status' => ['mandateRegistry', 'enterpriseCompanyActiveOperatingSystemStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['active_operating_system_ready_companies', 's', ['summary', 'active_operating_system_ready_company_count']],
            ['average_active_operating_system_score', 's', ['summary', 'average_active_operating_system_score']],
            ['average_internal_outcome_score', 's', ['summary', 'average_internal_outcome_score']],
            ['external_autonomy_allowed', 's', ['summary', 'external_autonomy_allowed_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-capability-catalog-status' => ['mandateRegistry', 'enterpriseCompanyCapabilityCatalogStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['catalog_ready_companies', 's', ['summary', 'catalog_ready_company_count']],
            ['ready_families', 's', ['summary', 'ready_family_count']],
            ['families', 's', ['summary', 'required_family_count']],
            ['ready_flow_capabilities', 's', ['summary', 'ready_flow_capability_count']],
            ['flow_capabilities', 's', ['summary', 'flow_capability_count']],
            ['average_catalog_score', 's', ['summary', 'average_catalog_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-integration-readiness-status' => ['mandateRegistry', 'enterpriseCompanyIntegrationReadinessStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['integration_ready_companies', 's', ['summary', 'integration_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['ready_flow_integrations', 's', ['summary', 'ready_flow_integration_count']],
            ['flow_integrations', 's', ['summary', 'flow_integration_count']],
            ['average_integration_score', 's', ['summary', 'average_integration_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-domain-workload-agent-template-status' => ['mandateRegistry', 'enterpriseDomainWorkloadAgentTemplateStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_companies', 's', ['summary', 'ready_company_count']],
            ['templates', 's', ['summary', 'template_count']],
            ['ready_templates', 's', ['summary', 'ready_template_count']],
            ['skills', 's', ['summary', 'skill_count']],
            ['subagents', 's', ['summary', 'subagent_count']],
            ['distribution_packages', 's', ['summary', 'distribution_package_ready_count']],
            ['rollout_plans', 's', ['summary', 'rollout_plan_ready_count']],
            ['tool_permission_matrices', 's', ['summary', 'tool_permission_matrix_ready_count']],
            ['surface_bindings', 's', ['summary', 'execution_surface_binding_ready_count']],
            ['fixture_smoke_contracts', 's', ['summary', 'fixture_smoke_contract_ready_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-domain-solution-pack-status' => ['mandateRegistry', 'enterpriseCompanyDomainSolutionPackStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['solution_pack_ready_companies', 's', ['summary', 'domain_solution_pack_ready_company_count']],
            ['ready_flow_solution_packs', 's', ['summary', 'ready_flow_solution_pack_count']],
            ['domain_sources', 's', ['summary', 'domain_source_count']],
            ['solution_modules', 's', ['summary', 'solution_module_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-agent-operations-pack-status' => ['mandateRegistry', 'enterpriseCompanyAgentOperationsPackStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['agent_ops_ready_companies', 's', ['summary', 'agent_operations_pack_ready_company_count']],
            ['ready_flow_agent_ops', 's', ['summary', 'ready_flow_agent_operations_pack_count']],
            ['skills', 's', ['summary', 'skill_count']],
            ['subagents', 's', ['summary', 'subagent_count']],
            ['adapter_envelopes', 's', ['summary', 'adapter_envelope_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-agent-workforce-runtime-register' => ['mandateRegistry', 'enterpriseCompanyAgentWorkforceRuntimeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_agent_runtimes', 's', ['summary', 'expected_agent_workforce_runtime_count']],
            ['registered_agent_runtimes', 's', ['summary', 'registered_agent_workforce_runtime_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-agent-workforce-runtime-status' => ['mandateRegistry', 'enterpriseCompanyAgentWorkforceRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_companies', 's', ['summary', 'agent_workforce_runtime_ready_company_count']],
            ['ready_agent_runtimes', 's', ['summary', 'ready_agent_workforce_runtime_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-domain-operating-model-certification-status' => ['mandateRegistry', 'enterpriseCompanyDomainOperatingModelCertificationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['certified_companies', 's', ['summary', 'certified_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['average_certification_score', 's', ['summary', 'average_certification_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-domain-tool-execution-readiness-status' => ['mandateRegistry', 'enterpriseCompanyDomainToolExecutionReadinessStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['tool_execution_ready_companies', 's', ['summary', 'tool_execution_ready_company_count']],
            ['ready_flow_tool_execution', 's', ['summary', 'ready_flow_tool_execution_count']],
            ['flow_tool_execution', 's', ['summary', 'flow_tool_execution_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-flow-tool-execution-ledger-status' => ['mandateRegistry', 'enterpriseCompanyFlowToolExecutionLedgerStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ledger_ready_companies', 's', ['summary', 'flow_tool_execution_ledger_ready_company_count']],
            ['ready_ledger_records', 's', ['summary', 'ready_ledger_record_count']],
            ['ledger_records', 's', ['summary', 'ledger_record_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-flow-tool-execution-runtime-register' => ['mandateRegistry', 'enterpriseCompanyFlowToolExecutionRuntimeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_runs', 's', ['summary', 'registered_run_count']],
            ['expected_runs', 's', ['summary', 'expected_ledger_record_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-flow-tool-execution-runtime-status' => ['mandateRegistry', 'enterpriseCompanyFlowToolExecutionRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['runtime_ready_companies', 's', ['summary', 'persisted_runtime_ready_company_count']],
            ['ready_persisted_runs', 's', ['summary', 'ready_persisted_run_count']],
            ['persisted_runs', 's', ['summary', 'persisted_run_count']],
            ['expected_runs', 's', ['summary', 'expected_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-domain-adapter-execution-envelope-register' => ['mandateRegistry', 'enterpriseCompanyDomainAdapterExecutionEnvelopeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_envelopes', 's', ['summary', 'registered_envelope_count']],
            ['expected_connectors', 's', ['summary', 'expected_connector_readiness_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-domain-adapter-execution-envelope-status' => ['mandateRegistry', 'enterpriseCompanyDomainAdapterExecutionEnvelopeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['adapter_ready_companies', 's', ['summary', 'adapter_envelope_ready_company_count']],
            ['ready_envelopes', 's', ['summary', 'ready_persisted_envelope_count']],
            ['persisted_envelopes', 's', ['summary', 'persisted_envelope_count']],
            ['expected_envelopes', 's', ['summary', 'expected_envelope_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-operational-execution-loop-status' => ['mandateRegistry', 'enterpriseCompanyOperationalExecutionLoopStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['loop_ready_companies', 's', ['summary', 'operational_execution_loop_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['queue_attempted', 's', ['summary', 'queue_attempted_count']],
            ['runbooks_green', 's', ['summary', 'runbook_operations_green_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-work-product-acceptance-evidence-status' => ['mandateRegistry', 'enterpriseCompanyWorkProductAcceptanceEvidenceStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['acceptance_ready_companies', 's', ['summary', 'work_product_acceptance_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['acceptance_contracts', 's', ['summary', 'acceptance_contract_count']],
            ['handoff_packets', 's', ['summary', 'handoff_packet_count']],
            ['external_delivery_allowed', 'bc', ['policy', 'external_delivery_allowed'], false],
        ]],
        'enterprise-company-work-product-runtime-register' => ['mandateRegistry', 'enterpriseCompanyWorkProductRuntimeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_work_product_runs', 's', ['summary', 'registered_work_product_run_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['external_delivery_allowed', 'bc', ['policy', 'external_delivery_allowed'], false],
        ]],
        'enterprise-company-work-product-runtime-status' => ['mandateRegistry', 'enterpriseCompanyWorkProductRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['runtime_ready_companies', 's', ['summary', 'work_product_runtime_ready_company_count']],
            ['ready_work_product_runs', 's', ['summary', 'ready_persisted_work_product_run_count']],
            ['persisted_work_product_runs', 's', ['summary', 'persisted_work_product_run_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['external_delivery_allowed', 'bc', ['policy', 'external_delivery_allowed'], false],
        ]],
        'enterprise-company-operating-blueprint-runtime-register' => ['mandateRegistry', 'enterpriseCompanyOperatingBlueprintRuntimeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_blueprint_runs', 's', ['summary', 'registered_blueprint_run_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-operating-blueprint-runtime-status' => ['mandateRegistry', 'enterpriseCompanyOperatingBlueprintRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['runtime_ready_companies', 's', ['summary', 'operating_blueprint_runtime_ready_company_count']],
            ['ready_blueprint_runs', 's', ['summary', 'ready_persisted_blueprint_run_count']],
            ['persisted_blueprint_runs', 's', ['summary', 'persisted_blueprint_run_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-business-runtime-persistence-register' => ['mandateRegistry', 'enterpriseCompanyBusinessRuntimePersistenceRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['business_runtime_layers', 's', ['summary', 'business_runtime_layer_count']],
            ['registered_business_runtime_runs', 's', ['summary', 'registered_business_runtime_run_count']],
            ['expected_business_runtime_runs', 's', ['summary', 'expected_business_runtime_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-business-runtime-persistence-status' => ['mandateRegistry', 'enterpriseCompanyBusinessRuntimePersistenceStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['business_runtime_layers', 's', ['summary', 'business_runtime_layer_count']],
            ['runtime_ready_companies', 's', ['summary', 'business_runtime_persistence_ready_company_count']],
            ['ready_business_runtime_runs', 's', ['summary', 'ready_persisted_business_runtime_run_count']],
            ['persisted_business_runtime_runs', 's', ['summary', 'persisted_business_runtime_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-capability-runtime-mesh-register' => ['mandateRegistry', 'enterpriseCompanyCapabilityRuntimeMeshRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['capabilities', 's', ['summary', 'capability_count']],
            ['registered_capability_runs', 's', ['summary', 'registered_capability_runtime_run_count']],
            ['expected_capability_runs', 's', ['summary', 'expected_capability_runtime_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-capability-runtime-mesh-status' => ['mandateRegistry', 'enterpriseCompanyCapabilityRuntimeMeshStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['capabilities', 's', ['summary', 'capability_count']],
            ['runtime_ready_companies', 's', ['summary', 'capability_runtime_mesh_ready_company_count']],
            ['ready_capability_runs', 's', ['summary', 'ready_capability_runtime_run_count']],
            ['persisted_capability_runs', 's', ['summary', 'persisted_capability_runtime_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-supervised-connector-execution-register' => ['mandateRegistry', 'enterpriseCompanySupervisedConnectorExecutionRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_connector_runs', 's', ['summary', 'registered_supervised_connector_execution_run_count']],
            ['expected_connector_runs', 's', ['summary', 'expected_supervised_connector_execution_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-supervised-connector-execution-status' => ['mandateRegistry', 'enterpriseCompanySupervisedConnectorExecutionStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['runtime_ready_companies', 's', ['summary', 'supervised_connector_execution_ready_company_count']],
            ['ready_connector_runs', 's', ['summary', 'ready_supervised_connector_execution_run_count']],
            ['persisted_connector_runs', 's', ['summary', 'persisted_supervised_connector_execution_run_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-external-tool-activation-work-order-register' => ['mandateRegistry', 'enterpriseCompanyExternalToolActivationWorkOrderRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_work_orders', 's', ['summary', 'registered_external_tool_activation_work_order_count']],
            ['expected_work_orders', 's', ['summary', 'expected_external_tool_activation_work_order_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-external-tool-activation-work-order-status' => ['mandateRegistry', 'enterpriseCompanyExternalToolActivationWorkOrderStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['work_order_ready_companies', 's', ['summary', 'external_tool_activation_work_orders_ready_company_count']],
            ['ready_work_orders', 's', ['summary', 'ready_external_tool_activation_work_order_count']],
            ['persisted_work_orders', 's', ['summary', 'persisted_external_tool_activation_work_order_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-external-tool-activation-packet-register' => ['mandateRegistry', 'enterpriseCompanyExternalToolActivationPacketRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_packets', 's', ['summary', 'registered_external_tool_activation_packet_count']],
            ['expected_packets', 's', ['summary', 'expected_external_tool_activation_packet_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-external-tool-activation-packet-status' => ['mandateRegistry', 'enterpriseCompanyExternalToolActivationPacketStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['packet_ready_companies', 's', ['summary', 'external_tool_activation_packets_ready_company_count']],
            ['ready_packets', 's', ['summary', 'ready_external_tool_activation_packet_count']],
            ['persisted_packets', 's', ['summary', 'persisted_external_tool_activation_packet_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-vertical-tool-operating-runtime-register' => ['mandateRegistry', 'enterpriseCompanyVerticalToolOperatingRuntimeRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_vertical_tool_runtimes', 's', ['summary', 'registered_vertical_tool_runtime_count']],
            ['expected_vertical_tool_runtimes', 's', ['summary', 'expected_vertical_tool_runtime_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-vertical-tool-operating-runtime-status' => ['mandateRegistry', 'enterpriseCompanyVerticalToolOperatingRuntimeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['vertical_tool_runtime_ready_companies', 's', ['summary', 'vertical_tool_operating_runtime_ready_company_count']],
            ['ready_vertical_tool_runtimes', 's', ['summary', 'ready_vertical_tool_runtime_count']],
            ['persisted_vertical_tool_runtimes', 's', ['summary', 'persisted_vertical_tool_runtime_count']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-company-business-execution-control-plane-register' => ['mandateRegistry', 'enterpriseCompanyBusinessExecutionControlPlaneRegister', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['registered_business_control_planes', 's', ['summary', 'registered_business_control_plane_count']],
            ['expected_business_control_planes', 's', ['summary', 'expected_business_control_plane_count']],
            ['real_money_movement_allowed', 'bc', ['policy', 'real_money_movement_allowed'], false],
        ]],
        'enterprise-company-business-execution-control-plane-status' => ['mandateRegistry', 'enterpriseCompanyBusinessExecutionControlPlaneStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['business_control_plane_ready_companies', 's', ['summary', 'business_execution_control_plane_ready_company_count']],
            ['ready_business_control_planes', 's', ['summary', 'ready_business_control_plane_count']],
            ['persisted_business_control_planes', 's', ['summary', 'persisted_business_control_plane_count']],
            ['customer_commitment_allowed', 'bc', ['policy', 'customer_commitment_allowed'], false],
        ]],
        'enterprise-company-commercial-service-catalog-status' => ['mandateRegistry', 'enterpriseCompanyCommercialServiceCatalogStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['commercial_catalog_ready_companies', 's', ['summary', 'commercial_service_catalog_ready_company_count']],
            ['ready_commercial_flows', 's', ['summary', 'ready_commercial_flow_count']],
            ['service_offers', 's', ['summary', 'service_offer_count']],
            ['pricing_packages', 's', ['summary', 'pricing_package_count']],
            ['sla_contracts', 's', ['summary', 'sla_success_contract_count']],
            ['external_revenue_claim_allowed', 'bc', ['policy', 'external_revenue_claim_allowed'], false],
        ]],
        'enterprise-company-revenue-delivery-operating-mesh-status' => ['mandateRegistry', 'enterpriseCompanyRevenueDeliveryOperatingMeshStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['mesh_ready_companies', 's', ['summary', 'revenue_delivery_operating_mesh_ready_company_count']],
            ['ready_flow_threads', 's', ['summary', 'ready_flow_thread_count']],
            ['operating_systems', 's', ['summary', 'operating_system_count']],
            ['connector_maps', 's', ['summary', 'connector_map_count']],
            ['external_billing_allowed', 'bc', ['policy', 'external_billing_allowed'], false],
        ]],
        'enterprise-company-org-operating-model-status' => ['mandateRegistry', 'enterpriseCompanyOrgOperatingModelStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['org_ready_companies', 's', ['summary', 'org_operating_model_ready_company_count']],
            ['ready_flow_org_records', 's', ['summary', 'ready_flow_org_record_count']],
            ['flow_staffing', 's', ['summary', 'flow_staffing_count']],
            ['vendor_due_diligence', 's', ['summary', 'vendor_due_diligence_count']],
            ['audit_evidence_requirements', 's', ['summary', 'audit_evidence_requirement_count']],
            ['external_procurement_allowed', 'bc', ['policy', 'external_procurement_allowed'], false],
        ]],
        'enterprise-company-customer-delivery-lifecycle-status' => ['mandateRegistry', 'enterpriseCompanyCustomerDeliveryLifecycleStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['lifecycle_ready_companies', 's', ['summary', 'customer_delivery_lifecycle_ready_company_count']],
            ['ready_flow_lifecycles', 's', ['summary', 'ready_flow_lifecycle_count']],
            ['account_health_risks', 's', ['summary', 'account_health_risk_count']],
            ['billing_controls', 's', ['summary', 'billing_ledger_control_count']],
            ['external_customer_commitment_allowed', 'bc', ['policy', 'external_customer_commitment_allowed'], false],
        ]],
        'enterprise-company-quality-compliance-lifecycle-status' => ['mandateRegistry', 'enterpriseCompanyQualityComplianceLifecycleStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['quality_ready_companies', 's', ['summary', 'quality_compliance_lifecycle_ready_company_count']],
            ['ready_flow_quality_records', 's', ['summary', 'ready_flow_quality_count']],
            ['replay_matrices', 's', ['summary', 'replay_matrix_count']],
            ['audit_evidence_requirements', 's', ['summary', 'audit_evidence_requirement_count']],
            ['external_benchmark_claim_allowed', 'bc', ['policy', 'external_benchmark_claim_allowed'], false],
        ]],
        'enterprise-company-production-readiness-certification-status' => ['mandateRegistry', 'enterpriseCompanyProductionReadinessCertificationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['production_ready_companies', 's', ['summary', 'production_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['average_production_readiness_score', 's', ['summary', 'average_production_readiness_score']],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
            ['external_launch_allowed', 'bc', ['policy', 'external_launch_allowed'], false],
        ]],
        'enterprise-company-operating-evidence-bundle-status' => ['mandateRegistry', 'enterpriseCompanyOperatingEvidenceBundleStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['bundle_ready_companies', 's', ['summary', 'operating_evidence_bundle_ready_company_count']],
            ['ready_gates', 's', ['summary', 'ready_gate_count']],
            ['gates', 's', ['summary', 'required_gate_count']],
            ['flow_evidence_records', 's', ['summary', 'flow_evidence_record_count']],
            ['average_bundle_score', 's', ['summary', 'average_bundle_score']],
            ['external_launch_allowed', 'bc', ['policy', 'external_launch_allowed'], false],
        ]],
        'enterprise-shadow-readiness' => ['fixtureSuite', 'shadowReadiness', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['shadow_ready', 's', ['summary', 'shadow_ready_company_count']],
            ['flows', 's', ['summary', 'flow_count']],
        ]],
        'enterprise-supervised-activation-plan' => ['fixtureSuite', 'supervisedActivationPlan', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready_for_mandate', 's', ['summary', 'ready_for_operator_mandate_company_count']],
            ['flows', 's', ['summary', 'flow_count']],
        ]],
        'enterprise-supervised-runtime' => ['fixtureSuite', 'supervisedRuntime', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['completed_flows', 's', ['summary', 'completed_flow_count']],
            ['completion_rate', 's', ['summary', 'completion_rate']],
        ]],
        'enterprise-connector-certification' => ['fixtureSuite', 'connectorCertificationSuite', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['certified_connectors', 's', ['summary', 'certified_connector_count']],
            ['certification_rate', 's', ['summary', 'certification_rate']],
        ]],
        'enterprise-external-action-mandates' => ['fixtureSuite', 'externalActionMandateSuite', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['prepared_packets', 's', ['summary', 'prepared_packet_count']],
            ['auto_execute_allowed', 'b', ['summary', 'auto_execute_allowed']],
        ]],
        'enterprise-external-action-register' => ['mandateRegistry', 'register', [['company', 'nullable'], ['flow', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['registered', 's', ['summary', 'registered_count']],
            ['companies', 's', ['summary', 'company_count']],
            ['auto_execute_allowed_count', 's', ['summary', 'auto_execute_allowed_count']],
        ]],
        'enterprise-external-action-preflight' => ['mandateRegistry', 'preflight', [['mandate-hash', 'string']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['external_execution_allowed', 'b', ['external_execution_allowed']],
            ['mandate_packet_hash', 's', ['mandate_packet_hash']],
        ]],
        'enterprise-external-action-request-approval' => ['mandateRegistry', 'requestApproval', [['mandate-hash', 'string']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['approval_count', 'c', ['approval_count'], 0],
            ['external_execution_allowed', 'b', ['external_execution_allowed']],
        ]],
        'enterprise-external-action-approval-status' => ['mandateRegistry', 'approvalStatus', [['mandate-hash', 'string']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['approval_count', 'c', ['approval_count'], 0],
            ['external_execution_allowed', 'b', ['external_execution_allowed']],
        ]],
        'enterprise-control-tower' => ['mandateRegistry', 'controlTower', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['expected_flows', 's', ['summary', 'expected_flow_count']],
            ['registered_mandates', 's', ['summary', 'registered_mandate_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-activation-cockpit' => ['mandateRegistry', 'activationCockpit', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['blocked_flows', 's', ['summary', 'blocked_flow_count']],
            ['external_execution_allowed', 'b', ['activation_policy', 'external_execution_allowed']],
        ]],
        'enterprise-premium-activation-status' => ['mandateRegistry', 'premiumActivationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['premium_ready', 's', ['summary', 'premium_ready_company_count']],
            ['wait_days_required_max', 's', ['summary', 'wait_days_required_max']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-provider-workbench-status' => ['mandateRegistry', 'providerWorkbenchStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['provider_contracts', 's', ['summary', 'provider_contract_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-agent-repository-adoption-status' => ['mandateRegistry', 'agentRepositoryAdoptionStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['repository_intake', 's', ['summary', 'repository_intake_count']],
            ['flow_epics', 's', ['summary', 'flow_epic_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-agent-repository-operating-catalog-status' => ['mandateRegistry', 'agentRepositoryOperatingCatalogStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['framework_profiles', 's', ['summary', 'framework_profile_count']],
            ['mcp_security_profiles', 's', ['summary', 'mcp_security_profile_count']],
            ['flow_runtime_maps', 's', ['summary', 'flow_runtime_map_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-domain-data-fabric-status' => ['mandateRegistry', 'domainDataFabricStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['sources', 's', ['summary', 'source_count']],
            ['data_products', 's', ['summary', 'data_product_count']],
            ['flow_workbenches', 's', ['summary', 'flow_workbench_count']],
            ['external_data_mutation_allowed', 'b', ['policy', 'external_data_mutation_allowed']],
        ]],
        'enterprise-domain-data-connector-operating-status' => ['mandateRegistry', 'domainDataConnectorOperatingStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['source_data_rooms', 's', ['summary', 'source_data_room_count']],
            ['data_products', 's', ['summary', 'domain_data_product_count']],
            ['permission_profiles', 's', ['summary', 'connector_permission_profile_count']],
            ['flow_contracts', 's', ['summary', 'flow_data_connector_contract_count']],
            ['external_data_mutation_allowed', 'b', ['policy', 'external_data_mutation_allowed']],
        ]],
        'enterprise-flow-live-read-connector-probe-status' => ['mandateRegistry', 'flowLiveReadConnectorProbeStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['probe_profiles', 's', ['summary', 'connector_probe_profile_count']],
            ['probe_contracts', 's', ['summary', 'flow_live_read_probe_contract_count']],
            ['evidence_matrices', 's', ['summary', 'flow_probe_evidence_matrix_count']],
            ['external_mutation_allowed', 'b', ['policy', 'external_mutation_allowed']],
        ]],
        'enterprise-external-research-adoption-status' => ['mandateRegistry', 'externalResearchAdoptionStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['sources', 's', ['summary', 'source_basis_count']],
            ['framework_repositories', 's', ['summary', 'official_framework_repository_count']],
            ['domain_repositories', 's', ['summary', 'domain_repository_candidate_count']],
            ['flow_matrices', 's', ['summary', 'flow_adoption_matrix_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-benchmark-replay-status' => ['mandateRegistry', 'flowBenchmarkReplayStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['offline_datasets', 's', ['summary', 'offline_dataset_contract_count']],
            ['trace_rubrics', 's', ['summary', 'trace_grading_rubric_count']],
            ['adversarial_cases', 's', ['summary', 'adversarial_regression_case_count']],
            ['replay_matrices', 's', ['summary', 'replay_comparison_matrix_count']],
            ['external_benchmark_execution_allowed', 'b', ['policy', 'external_benchmark_execution_allowed']],
        ]],
        'enterprise-connector-certification-preflight-status' => ['mandateRegistry', 'connectorCertificationPreflightStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['connectors', 's', ['summary', 'connector_count']],
            ['adapter_contracts', 's', ['summary', 'adapter_contract_count']],
            ['sandbox_probes', 's', ['summary', 'sandbox_probe_count']],
            ['cutover_matrices', 's', ['summary', 'flow_cutover_matrix_count']],
            ['external_connector_cutover_allowed', 'b', ['policy', 'external_connector_cutover_allowed']],
        ]],
        'enterprise-domain-agent-toolchain-certification-status' => ['mandateRegistry', 'domainAgentToolchainCertificationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['tool_contracts', 's', ['summary', 'certified_tool_contract_count']],
            ['toolkit_certifications', 's', ['summary', 'toolkit_certification_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-industry-solution-ecosystem-status' => ['mandateRegistry', 'industrySolutionEcosystemStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['providers', 's', ['summary', 'ecosystem_provider_count']],
            ['workload_packs', 's', ['summary', 'flow_workload_pack_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-business-operating-backbone-status' => ['mandateRegistry', 'businessOperatingBackboneStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['ready_components', 's', ['summary', 'ready_component_count']],
            ['required_components', 's', ['summary', 'required_component_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-production-connector-preflight-status' => ['mandateRegistry', 'productionConnectorPreflightStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['connector_preflight_contracts', 's', ['summary', 'connector_preflight_contract_count']],
            ['flow_cutovers', 's', ['summary', 'flow_cutover_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-quality-research-status' => ['mandateRegistry', 'flowQualityResearchStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['offline_datasets', 's', ['summary', 'offline_dataset_count']],
            ['tooling_benchmarks', 's', ['summary', 'tooling_benchmark_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-vertical-solution-suite-status' => ['mandateRegistry', 'verticalSolutionSuiteStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['solution_suites', 's', ['summary', 'solution_suite_count']],
            ['flow_kits', 's', ['summary', 'flow_solution_kit_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-domain-business-execution-mesh-status' => ['mandateRegistry', 'domainBusinessExecutionMeshStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['execution_cells', 's', ['summary', 'execution_cell_count']],
            ['service_lanes', 's', ['summary', 'service_lane_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-operating-package-status' => ['mandateRegistry', 'flowOperatingPackageStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['flow_packages', 's', ['summary', 'flow_package_count']],
            ['replay_contracts', 's', ['summary', 'replay_contract_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-company-command-center-status' => ['mandateRegistry', 'companyCommandCenterStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['flow_cards', 's', ['summary', 'flow_command_card_count']],
            ['connector_panels', 's', ['summary', 'connector_workbench_panel_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-operational-dress-rehearsal-status' => ['mandateRegistry', 'operationalDressRehearsalStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['ready', 's', ['summary', 'ready_company_count']],
            ['rehearsal_runbooks', 's', ['summary', 'flow_rehearsal_runbook_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-real-external-execution-readiness-dossier' => ['mandateRegistry', 'realExternalExecutionReadinessDossier', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['manual_candidates', 's', ['summary', 'manual_handoff_candidate_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-real-external-execution-handoff-pack' => ['mandateRegistry', 'realExternalExecutionHandoffPack', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['handoff_packs', 's', ['summary', 'handoff_pack_ready_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-supervised-external-execution-packet-status' => ['mandateRegistry', 'supervisedExternalExecutionPacketStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['ready_packets', 's', ['summary', 'packet_ready_count']],
            ['external_worker_enabled', 'b', ['policy', 'external_worker_enabled']],
        ]],
        'enterprise-external-worker-preflight-status' => ['mandateRegistry', 'externalWorkerPreflightStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['worker_preflights', 's', ['summary', 'worker_preflight_ready_count']],
            ['dispatch_enabled', 'b', ['policy', 'external_worker_dispatch_enabled']],
        ]],
        'enterprise-external-worker-dispatch-plan-status' => ['mandateRegistry', 'externalWorkerDispatchPlanStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['dispatch_plans', 's', ['summary', 'dispatch_plan_ready_count']],
            ['dispatch_enabled', 'b', ['policy', 'external_worker_dispatch_enabled']],
        ]],
        'enterprise-external-launch-control-status' => ['mandateRegistry', 'externalLaunchControlStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['launch_controls', 's', ['summary', 'launch_control_ready_count']],
            ['launch_enabled', 'b', ['policy', 'launch_enabled']],
        ]],
        'enterprise-external-receipt-binding-status' => ['mandateRegistry', 'externalReceiptBindingStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['receipt_slots', 's', ['summary', 'receipt_slot_count']],
            ['bound_receipts', 's', ['summary', 'bound_receipt_count']],
        ]],
        'enterprise-external-supervised-cutover-dossier-status' => ['mandateRegistry', 'externalSupervisedCutoverDossierStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['cutover_dossiers', 's', ['summary', 'cutover_dossier_ready_count']],
            ['cutover_enabled', 's', ['summary', 'supervised_cutover_enabled_count']],
        ]],
        'enterprise-external-supervised-cutover-work-order-status' => ['mandateRegistry', 'externalSupervisedCutoverWorkOrderStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['work_orders', 's', ['summary', 'work_order_ready_count']],
            ['work_items', 's', ['summary', 'work_item_count']],
        ]],
        'enterprise-external-supervised-cutover-work-order-register' => ['mandateRegistry', 'registerExternalSupervisedCutoverWorkOrders', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['work_orders', 's', ['summary', 'work_order_count']],
            ['work_items', 's', ['summary', 'work_item_count']],
            ['executable_items', 's', ['summary', 'executable_item_count']],
        ]],
        'enterprise-external-supervised-cutover-work-order-persisted-status' => ['mandateRegistry', 'externalSupervisedCutoverWorkOrderPersistedStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['work_orders', 's', ['summary', 'work_order_count']],
            ['work_items', 's', ['summary', 'work_item_count']],
            ['pending_items', 's', ['summary', 'pending_work_item_count']],
        ]],
        'enterprise-external-supervised-cutover-work-item-bind-receipt' => ['mandateRegistry', 'bindExternalSupervisedCutoverWorkItemReceipt', [['work-item', 'raw'], ['receipt-hash', 'raw'], ['receipt-source', 'raw'], ['operator', 'raw'], ['note', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['bound_receipts', 'c', ['summary', 'bound_receipt_count'], 0],
            ['pending_items', 'c', ['summary', 'pending_work_item_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-promotion-status' => ['mandateRegistry', 'externalSupervisedCutoverPromotionStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['work_orders', 's', ['summary', 'work_order_count']],
            ['promotion_ready', 's', ['summary', 'promotion_review_ready_count']],
            ['pending_items', 's', ['summary', 'pending_work_item_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-external-supervised-cutover-final-authority-bind-receipt' => ['mandateRegistry', 'bindExternalSupervisedCutoverFinalAuthorityReceipt', [['work-order', 'raw'], ['authority', 'raw'], ['receipt-hash', 'raw'], ['receipt-source', 'raw'], ['operator', 'raw'], ['note', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['final_authorities', 'c', ['summary', 'final_authority_binding_count'], 0],
            ['missing_authorities', 'c', ['summary', 'missing_final_authority_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-runtime-invocation-register' => ['mandateRegistry', 'registerExternalSupervisedCutoverRuntimeInvocation', [['work-order', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['invocations', 'c', ['summary', 'runtime_invocation_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-runtime-invocation-status' => ['mandateRegistry', 'externalSupervisedCutoverRuntimeInvocationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['invocations', 'c', ['summary', 'runtime_invocation_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-runtime-rehearsal-execute' => ['mandateRegistry', 'executeExternalSupervisedCutoverRuntimeRehearsal', [['invocation', 'raw'], ['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['execution_receipts', 'c', ['summary', 'execution_receipt_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-rehearsal-promotion-status' => ['mandateRegistry', 'externalSupervisedCutoverRehearsalPromotionStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['ready_packets', 'c', ['summary', 'manual_execution_packet_ready_count'], 0],
            ['blocked_packets', 'c', ['summary', 'manual_execution_packet_blocked_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-manual-handoff-register' => ['mandateRegistry', 'registerExternalSupervisedCutoverManualHandoff', [['work-order', 'raw'], ['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['handoff_packets', 'c', ['summary', 'manual_handoff_packet_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-manual-handoff-status' => ['mandateRegistry', 'externalSupervisedCutoverManualHandoffStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['handoff_packets', 'c', ['summary', 'manual_handoff_packet_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-manual-closeout-bind-receipt' => ['mandateRegistry', 'bindExternalSupervisedCutoverManualCloseoutReceipt', [['work-order', 'raw'], ['receipt-hash', 'raw'], ['receipt-source', 'raw'], ['operator', 'raw'], ['note', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['closeout_receipts', 'c', ['summary', 'manual_closeout_receipt_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-manual-closeout-status' => ['mandateRegistry', 'externalSupervisedCutoverManualCloseoutStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['closeout_receipts', 'c', ['summary', 'manual_closeout_receipt_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-portfolio-readiness-status' => ['mandateRegistry', 'externalSupervisedCutoverPortfolioReadinessStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['expected_flows', 'c', ['summary', 'expected_flow_count'], 0],
            ['complete_companies', 'c', ['summary', 'cutover_chain_complete_company_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-company-evidence-bundle-apply' => ['mandateRegistry', 'applyExternalSupervisedCutoverCompanyEvidenceBundle', [['company', 'nullable'], ['receipt-hash', 'raw'], ['receipt-source', 'raw'], ['operator', 'raw'], ['note', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['company', 'c', ['company_id'], ''],
            ['work_orders', 'c', ['summary', 'work_order_count'], 0],
            ['complete_companies', 'c', ['summary', 'cutover_chain_complete_company_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-external-supervised-cutover-portfolio-evidence-bundle-apply' => ['mandateRegistry', 'applyExternalSupervisedCutoverPortfolioEvidenceBundle', [['receipt-hash', 'raw'], ['receipt-source', 'raw'], ['operator', 'raw'], ['note', 'raw']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 'c', ['summary', 'company_count'], 0],
            ['expected_flows', 'c', ['summary', 'expected_flow_count'], 0],
            ['complete_companies', 'c', ['summary', 'cutover_chain_complete_company_count'], 0],
            ['external_execution_allowed', 'bc', ['policy', 'external_execution_allowed'], false],
        ]],
        'enterprise-activation-backlog-register' => ['mandateRegistry', 'registerActivationBacklog', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['work_packages', 's', ['summary', 'work_package_count']],
            ['external_execution_allowed', 'b', ['registry_policy', 'external_execution_enabled_by_registry']],
        ]],
        'enterprise-activation-backlog-status' => ['mandateRegistry', 'activationBacklogStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['work_packages', 's', ['summary', 'work_package_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-activation-backlog-run' => ['mandateRegistry', 'runActivationBacklog', [['company', 'nullable'], ['flow', 'nullable'], ['work-package', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['work_packages', 's', ['summary', 'work_package_count']],
            ['completed', 's', ['summary', 'completed_count']],
            ['blocked', 's', ['summary', 'blocked_count']],
            ['external_execution_allowed', 'b', ['run_policy', 'external_execution_allowed']],
        ]],
        'enterprise-connector-activation-register' => ['mandateRegistry', 'registerConnectorActivations', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['connector_activations', 's', ['summary', 'connector_activation_count']],
            ['external_execution_allowed', 'b', ['registry_policy', 'external_execution_allowed']],
        ]],
        'enterprise-connector-activation-probe' => ['mandateRegistry', 'probeConnectorActivations', [['company', 'nullable'], ['flow', 'nullable'], ['connector', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['connector_activations', 's', ['summary', 'connector_activation_count']],
            ['probe_green', 's', ['summary', 'probe_green_count']],
            ['external_execution_allowed', 'b', ['probe_policy', 'external_execution_allowed']],
        ]],
        'enterprise-connector-activation-status' => ['mandateRegistry', 'connectorActivationStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['connector_activations', 's', ['summary', 'connector_activation_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-live-read-connector-readiness-status' => ['mandateRegistry', 'liveReadConnectorReadinessStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['connectors', 's', ['summary', 'connector_readiness_count']],
            ['live_read_ready', 's', ['summary', 'live_read_ready_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-run-queue-register' => ['mandateRegistry', 'registerFlowRunQueue', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['durable_envelopes', 's', ['summary', 'durable_execution_envelope_bound_count']],
            ['hitl_checkpoints', 's', ['summary', 'human_in_loop_bound_count']],
            ['external_execution_allowed', 'b', ['queue_policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-run-queue-execute' => ['mandateRegistry', 'executeFlowRunQueue', [['company', 'nullable'], ['flow', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['flows', 's', ['summary', 'flow_count']],
            ['completed', 's', ['summary', 'completed_count']],
            ['dlq', 's', ['summary', 'dlq_count']],
            ['durable_envelopes', 's', ['summary', 'durable_execution_envelope_bound_count']],
            ['trace_receipts', 's', ['summary', 'trace_receipt_bound_count']],
            ['external_execution_allowed', 'b', ['execution_policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-run-queue-replay' => ['mandateRegistry', 'replayFlowRunQueue', [['company', 'nullable'], ['flow', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['flows', 's', ['summary', 'flow_count']],
            ['completed', 's', ['summary', 'completed_count']],
            ['dlq', 's', ['summary', 'dlq_count']],
            ['durable_envelopes', 's', ['summary', 'durable_execution_envelope_bound_count']],
            ['trace_receipts', 's', ['summary', 'trace_receipt_bound_count']],
            ['external_execution_allowed', 'b', ['replay_policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-run-queue-status' => ['mandateRegistry', 'flowRunQueueStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['durable_envelopes', 's', ['summary', 'durable_execution_envelope_bound_count']],
            ['hitl_checkpoints', 's', ['summary', 'human_in_loop_bound_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-operations-runbook-register' => ['mandateRegistry', 'registerFlowOperationsRunbooks', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['external_execution_allowed', 'b', ['registry_policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-operations-runbook-drill' => ['mandateRegistry', 'drillFlowOperationsRunbooks', [['company', 'nullable'], ['flow', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['flows', 's', ['summary', 'flow_count']],
            ['operations_green', 's', ['summary', 'operations_green_count']],
            ['external_execution_allowed', 'b', ['drill_policy', 'external_execution_allowed']],
        ]],
        'enterprise-flow-operations-runbook-status' => ['mandateRegistry', 'flowOperationsRunbookStatus', [['company', 'nullable']], [
            ['schema', 's', ['schema']],
            ['status', 's', ['status']],
            ['companies', 's', ['summary', 'company_count']],
            ['flows', 's', ['summary', 'flow_count']],
            ['external_execution_allowed', 'b', ['policy', 'external_execution_allowed']],
        ]],
    ];

    private function renderPayload(array $spec, object $service): int
    {
        $args = [];
        foreach ($spec[2] as [$option, $norm]) {
            $value = $this->option($option);
            $args[] = match ($norm) {
                'nullable' => is_string($value) && trim($value) !== '' ? trim($value) : null,
                'string' => is_string($value) ? trim($value) : '',
                'raw' => is_string($value) ? $value : null,
            };
        }

        $payload = $service->{$spec[1]}(...$args);
        $this->emit($payload, function () use ($payload, $spec): void {
            foreach ($spec[3] as $detail) {
                [$label, $form, $path] = $detail;
                $value = $payload;
                if ($form === 's' || $form === 'b') {
                    foreach ($path as $key) {
                        $value = $value[$key];
                    }
                } else {
                    foreach ($path as $key) {
                        if (! is_array($value) || ! array_key_exists($key, $value)) {
                            $value = null;
                            break;
                        }
                        $value = $value[$key];
                    }
                    $value ??= $detail[3];
                }
                $this->components->twoColumnDetail(
                    $label,
                    $form === 's' || $form === 'c' ? (string) $value : ($value ? 'true' : 'false'),
                );
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encodeOrEmptyObject($payload));

        return self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));

            return;
        }

        $human();
    }

}
