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
        {--action=readiness : readiness, observe-cycle, enterprise-operating-packet-status, enterprise-buildout, enterprise-consolidation-run, enterprise-fixture-suite, enterprise-flow-action-runtime-run, enterprise-flow-action-runtime-status, enterprise-vertical-solution-runtime-status, enterprise-domain-solution-playbook-runtime-status, enterprise-domain-operating-depth-runtime-status, enterprise-domain-agent-workforce-runtime-status, enterprise-operational-dossier-runtime-status, enterprise-autonomy-promotion-runtime-status, enterprise-domain-business-execution-runtime-status, enterprise-company-operating-spine-runtime-status, enterprise-commercial-operations-runtime-status, enterprise-domain-provider-workbench-runtime-status, enterprise-domain-company-execution-suite-runtime-status, enterprise-flow-work-product-delivery-runtime-status, enterprise-domain-data-connector-operating-runtime-status, enterprise-flow-live-read-connector-probe-runtime-status, enterprise-external-research-adoption-runtime-status, enterprise-flow-benchmark-replay-runtime-status, enterprise-connector-certification-preflight-runtime-status, enterprise-command-center-control-tower-runtime-status, enterprise-operational-dress-rehearsal-runtime-status, enterprise-semantic-operating-graph-runtime-status, enterprise-agent-toolchain-runtime-status, enterprise-workforce-capacity-runtime-status, enterprise-cross-company-handoff-runtime-status, enterprise-customer-account-revenue-runtime-status, enterprise-productized-service-runtime-status, enterprise-sales-crm-pipeline-runtime-status, enterprise-customer-support-service-desk-runtime-status, enterprise-marketing-growth-engine-runtime-status, enterprise-finance-treasury-billing-runtime-status, enterprise-governance-risk-operations-runtime-status, enterprise-unit-economics-capacity-runtime-status, enterprise-business-operating-packet-runtime-status, enterprise-delivery-risk-runtime-status, enterprise-operational-outcome-runtime-status, enterprise-holding-outcome-scorecard-status, enterprise-portfolio-decision-packet-status, enterprise-company-board-operating-review-status, enterprise-company-completion-certification-status, enterprise-vertical-operational-depth-status, enterprise-company-operating-cycle-status, enterprise-company-operating-cadence-status, enterprise-company-operating-scorecard-status, enterprise-company-active-operating-system-status, enterprise-company-capability-catalog-status, enterprise-company-integration-readiness-status, enterprise-domain-workload-agent-template-status, enterprise-company-domain-solution-pack-status, enterprise-company-agent-operations-pack-status, enterprise-company-domain-operating-model-certification-status, enterprise-company-domain-tool-execution-readiness-status, enterprise-company-flow-tool-execution-ledger-status, enterprise-company-flow-tool-execution-runtime-register, enterprise-company-flow-tool-execution-runtime-status, enterprise-company-domain-adapter-execution-envelope-register, enterprise-company-domain-adapter-execution-envelope-status, enterprise-company-operational-execution-loop-status, enterprise-company-work-product-acceptance-evidence-status, enterprise-company-work-product-runtime-register, enterprise-company-work-product-runtime-status, enterprise-company-operating-blueprint-runtime-register, enterprise-company-operating-blueprint-runtime-status, enterprise-company-business-runtime-persistence-register, enterprise-company-business-runtime-persistence-status, enterprise-company-commercial-service-catalog-status, enterprise-company-revenue-delivery-operating-mesh-status, enterprise-company-org-operating-model-status, enterprise-company-customer-delivery-lifecycle-status, enterprise-company-quality-compliance-lifecycle-status, enterprise-company-production-readiness-certification-status, enterprise-company-operating-evidence-bundle-status, enterprise-shadow-readiness, enterprise-supervised-activation-plan, enterprise-supervised-runtime, enterprise-connector-certification, enterprise-external-action-mandates, enterprise-external-action-register, enterprise-external-action-preflight, enterprise-external-action-request-approval, enterprise-external-action-approve, enterprise-external-action-reject, enterprise-external-action-approval-status, enterprise-control-tower, enterprise-activation-cockpit, enterprise-premium-activation-status, enterprise-provider-workbench-status, enterprise-agent-repository-adoption-status, enterprise-agent-repository-operating-catalog-status, enterprise-domain-agent-toolchain-certification-status, enterprise-industry-solution-ecosystem-status, enterprise-business-operating-backbone-status, enterprise-production-connector-preflight-status, enterprise-flow-quality-research-status, enterprise-vertical-solution-suite-status, enterprise-domain-business-execution-mesh-status, enterprise-flow-operating-package-status, enterprise-company-command-center-status, enterprise-operational-dress-rehearsal-status, enterprise-real-external-execution-readiness-dossier, enterprise-real-external-execution-handoff-pack, enterprise-supervised-external-execution-packet-status, enterprise-external-worker-preflight-status, enterprise-external-worker-dispatch-plan-status, enterprise-external-launch-control-status, enterprise-external-receipt-binding-status, enterprise-external-supervised-cutover-dossier-status, enterprise-external-supervised-cutover-work-order-status, enterprise-external-supervised-cutover-work-order-register, enterprise-external-supervised-cutover-work-order-persisted-status, enterprise-external-supervised-cutover-work-item-bind-receipt, enterprise-external-supervised-cutover-promotion-status, enterprise-external-supervised-cutover-final-authority-bind-receipt, enterprise-external-supervised-cutover-runtime-invocation-register, enterprise-external-supervised-cutover-runtime-invocation-status, enterprise-external-supervised-cutover-runtime-rehearsal-execute, enterprise-external-supervised-cutover-rehearsal-promotion-status, enterprise-external-supervised-cutover-manual-handoff-register, enterprise-external-supervised-cutover-manual-handoff-status, enterprise-external-supervised-cutover-manual-closeout-bind-receipt, enterprise-external-supervised-cutover-manual-closeout-status, enterprise-external-supervised-cutover-portfolio-readiness-status, enterprise-external-supervised-cutover-company-evidence-bundle-apply, enterprise-external-supervised-cutover-portfolio-evidence-bundle-apply, enterprise-activation-backlog-register, enterprise-activation-backlog-status, enterprise-activation-backlog-run, enterprise-connector-activation-register, enterprise-connector-activation-probe, enterprise-connector-activation-status, enterprise-live-read-connector-readiness-status, enterprise-flow-run-queue-register, enterprise-flow-run-queue-execute, enterprise-flow-run-queue-replay, enterprise-flow-run-queue-status, enterprise-flow-operations-runbook-register, enterprise-flow-operations-runbook-drill, enterprise-flow-operations-runbook-status}
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
        if ((int) ini_get('memory_limit') > 0) {
            ini_set('memory_limit', '512M');
        }

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
                'enterprise-domain-company-execution-suite-runtime-status' => $this->renderEnterpriseDomainCompanyExecutionSuiteRuntimeStatus($flowActionRuntime),
                'enterprise-flow-work-product-delivery-runtime-status' => $this->renderEnterpriseFlowWorkProductDeliveryRuntimeStatus($flowActionRuntime),
                'enterprise-domain-data-connector-operating-runtime-status' => $this->renderEnterpriseDomainDataConnectorOperatingRuntimeStatus($flowActionRuntime),
                'enterprise-flow-live-read-connector-probe-runtime-status' => $this->renderEnterpriseFlowLiveReadConnectorProbeRuntimeStatus($flowActionRuntime),
                'enterprise-external-research-adoption-runtime-status' => $this->renderEnterpriseExternalResearchAdoptionRuntimeStatus($flowActionRuntime),
                'enterprise-flow-benchmark-replay-runtime-status' => $this->renderEnterpriseFlowBenchmarkReplayRuntimeStatus($flowActionRuntime),
                'enterprise-connector-certification-preflight-runtime-status' => $this->renderEnterpriseConnectorCertificationPreflightRuntimeStatus($flowActionRuntime),
                'enterprise-command-center-control-tower-runtime-status' => $this->renderEnterpriseCommandCenterControlTowerRuntimeStatus($flowActionRuntime),
                'enterprise-operational-dress-rehearsal-runtime-status' => $this->renderEnterpriseOperationalDressRehearsalRuntimeStatus($flowActionRuntime),
                'enterprise-semantic-operating-graph-runtime-status' => $this->renderEnterpriseSemanticOperatingGraphRuntimeStatus($flowActionRuntime),
                'enterprise-company-system-model-runtime-status' => $this->renderEnterpriseCompanySystemModelRuntimeStatus($flowActionRuntime),
                'enterprise-internal-operations-backbone-runtime-status' => $this->renderEnterpriseInternalOperationsBackboneRuntimeStatus($flowActionRuntime),
                'enterprise-activation-run-operations-runtime-status' => $this->renderEnterpriseActivationRunOperationsRuntimeStatus($flowActionRuntime),
                'enterprise-flow-execution-foundation-runtime-status' => $this->renderEnterpriseFlowExecutionFoundationRuntimeStatus($flowActionRuntime),
                'enterprise-agent-toolchain-runtime-status' => $this->renderEnterpriseAgentToolchainRuntimeStatus($flowActionRuntime),
                'enterprise-workforce-capacity-runtime-status' => $this->renderEnterpriseWorkforceCapacityRuntimeStatus($flowActionRuntime),
                'enterprise-portfolio-dependency-runtime-status' => $this->renderEnterprisePortfolioDependencyRuntimeStatus($flowActionRuntime),
                'enterprise-cross-company-handoff-runtime-status' => $this->renderEnterpriseCrossCompanyHandoffRuntimeStatus($flowActionRuntime),
                'enterprise-customer-account-revenue-runtime-status' => $this->renderEnterpriseCustomerAccountRevenueRuntimeStatus($flowActionRuntime),
                'enterprise-productized-service-runtime-status' => $this->renderEnterpriseProductizedServiceRuntimeStatus($flowActionRuntime),
                'enterprise-sales-crm-pipeline-runtime-status' => $this->renderEnterpriseSalesCrmPipelineRuntimeStatus($flowActionRuntime),
                'enterprise-customer-support-service-desk-runtime-status' => $this->renderEnterpriseCustomerSupportServiceDeskRuntimeStatus($flowActionRuntime),
                'enterprise-marketing-growth-engine-runtime-status' => $this->renderEnterpriseMarketingGrowthEngineRuntimeStatus($flowActionRuntime),
                'enterprise-finance-treasury-billing-runtime-status' => $this->renderEnterpriseFinanceTreasuryBillingRuntimeStatus($flowActionRuntime),
                'enterprise-governance-risk-operations-runtime-status' => $this->renderEnterpriseGovernanceRiskOperationsRuntimeStatus($flowActionRuntime),
                'enterprise-unit-economics-capacity-runtime-status' => $this->renderEnterpriseUnitEconomicsCapacityRuntimeStatus($flowActionRuntime),
                'enterprise-business-operating-packet-runtime-status' => $this->renderEnterpriseBusinessOperatingPacketRuntimeStatus($flowActionRuntime),
                'enterprise-delivery-risk-runtime-status' => $this->renderEnterpriseDeliveryRiskRuntimeStatus($flowActionRuntime),
                'enterprise-operational-outcome-runtime-status' => $this->renderEnterpriseOperationalOutcomeRuntimeStatus($flowActionRuntime),
                'enterprise-holding-outcome-scorecard-status' => $this->renderEnterpriseHoldingOutcomeScorecardStatus($flowActionRuntime),
                'enterprise-portfolio-decision-packet-status' => $this->renderEnterprisePortfolioDecisionPacketStatus($flowActionRuntime),
                'enterprise-company-board-operating-review-status' => $this->renderEnterpriseCompanyBoardOperatingReviewStatus($flowActionRuntime),
                'enterprise-company-completion-certification-status' => $this->renderEnterpriseCompanyCompletionCertificationStatus($mandateRegistry),
                'enterprise-vertical-operational-depth-status' => $this->renderEnterpriseVerticalOperationalDepthStatus($mandateRegistry),
                'enterprise-company-operating-cycle-status' => $this->renderEnterpriseCompanyOperatingCycleStatus($mandateRegistry),
                'enterprise-company-operating-cadence-status' => $this->renderEnterpriseCompanyOperatingCadenceStatus($mandateRegistry),
                'enterprise-company-operating-scorecard-status' => $this->renderEnterpriseCompanyOperatingScorecardStatus($mandateRegistry),
                'enterprise-company-active-operating-system-status' => $this->renderEnterpriseCompanyActiveOperatingSystemStatus($mandateRegistry),
                'enterprise-company-capability-catalog-status' => $this->renderEnterpriseCompanyCapabilityCatalogStatus($mandateRegistry),
                'enterprise-company-integration-readiness-status' => $this->renderEnterpriseCompanyIntegrationReadinessStatus($mandateRegistry),
                'enterprise-domain-workload-agent-template-status' => $this->renderEnterpriseDomainWorkloadAgentTemplateStatus($mandateRegistry),
                'enterprise-company-domain-solution-pack-status' => $this->renderEnterpriseCompanyDomainSolutionPackStatus($mandateRegistry),
                'enterprise-company-agent-operations-pack-status' => $this->renderEnterpriseCompanyAgentOperationsPackStatus($mandateRegistry),
                'enterprise-company-domain-operating-model-certification-status' => $this->renderEnterpriseCompanyDomainOperatingModelCertificationStatus($mandateRegistry),
                'enterprise-company-domain-tool-execution-readiness-status' => $this->renderEnterpriseCompanyDomainToolExecutionReadinessStatus($mandateRegistry),
                'enterprise-company-flow-tool-execution-ledger-status' => $this->renderEnterpriseCompanyFlowToolExecutionLedgerStatus($mandateRegistry),
                'enterprise-company-flow-tool-execution-runtime-register' => $this->renderEnterpriseCompanyFlowToolExecutionRuntimeRegister($mandateRegistry),
                'enterprise-company-flow-tool-execution-runtime-status' => $this->renderEnterpriseCompanyFlowToolExecutionRuntimeStatus($mandateRegistry),
                'enterprise-company-domain-adapter-execution-envelope-register' => $this->renderEnterpriseCompanyDomainAdapterExecutionEnvelopeRegister($mandateRegistry),
                'enterprise-company-domain-adapter-execution-envelope-status' => $this->renderEnterpriseCompanyDomainAdapterExecutionEnvelopeStatus($mandateRegistry),
                'enterprise-company-operational-execution-loop-status' => $this->renderEnterpriseCompanyOperationalExecutionLoopStatus($mandateRegistry),
                'enterprise-company-work-product-acceptance-evidence-status' => $this->renderEnterpriseCompanyWorkProductAcceptanceEvidenceStatus($mandateRegistry),
                'enterprise-company-work-product-runtime-register' => $this->renderEnterpriseCompanyWorkProductRuntimeRegister($mandateRegistry),
                'enterprise-company-work-product-runtime-status' => $this->renderEnterpriseCompanyWorkProductRuntimeStatus($mandateRegistry),
                'enterprise-company-operating-blueprint-runtime-register' => $this->renderEnterpriseCompanyOperatingBlueprintRuntimeRegister($mandateRegistry),
                'enterprise-company-operating-blueprint-runtime-status' => $this->renderEnterpriseCompanyOperatingBlueprintRuntimeStatus($mandateRegistry),
                'enterprise-company-business-runtime-persistence-register' => $this->renderEnterpriseCompanyBusinessRuntimePersistenceRegister($mandateRegistry),
                'enterprise-company-business-runtime-persistence-status' => $this->renderEnterpriseCompanyBusinessRuntimePersistenceStatus($mandateRegistry),
                'enterprise-company-commercial-service-catalog-status' => $this->renderEnterpriseCompanyCommercialServiceCatalogStatus($mandateRegistry),
                'enterprise-company-revenue-delivery-operating-mesh-status' => $this->renderEnterpriseCompanyRevenueDeliveryOperatingMeshStatus($mandateRegistry),
                'enterprise-company-org-operating-model-status' => $this->renderEnterpriseCompanyOrgOperatingModelStatus($mandateRegistry),
                'enterprise-company-customer-delivery-lifecycle-status' => $this->renderEnterpriseCompanyCustomerDeliveryLifecycleStatus($mandateRegistry),
                'enterprise-company-quality-compliance-lifecycle-status' => $this->renderEnterpriseCompanyQualityComplianceLifecycleStatus($mandateRegistry),
                'enterprise-company-production-readiness-certification-status' => $this->renderEnterpriseCompanyProductionReadinessCertificationStatus($mandateRegistry),
                'enterprise-company-operating-evidence-bundle-status' => $this->renderEnterpriseCompanyOperatingEvidenceBundleStatus($mandateRegistry),
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
                'enterprise-agent-repository-operating-catalog-status' => $this->renderEnterpriseAgentRepositoryOperatingCatalogStatus($mandateRegistry),
                'enterprise-domain-agent-toolchain-certification-status' => $this->renderEnterpriseDomainAgentToolchainCertificationStatus($mandateRegistry),
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
                'enterprise-external-worker-dispatch-plan-status' => $this->renderEnterpriseExternalWorkerDispatchPlanStatus($mandateRegistry),
                'enterprise-external-launch-control-status' => $this->renderEnterpriseExternalLaunchControlStatus($mandateRegistry),
                'enterprise-external-receipt-binding-status' => $this->renderEnterpriseExternalReceiptBindingStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-dossier-status' => $this->renderEnterpriseExternalSupervisedCutoverDossierStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-work-order-status' => $this->renderEnterpriseExternalSupervisedCutoverWorkOrderStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-work-order-register' => $this->renderEnterpriseExternalSupervisedCutoverWorkOrderRegister($mandateRegistry),
                'enterprise-external-supervised-cutover-work-order-persisted-status' => $this->renderEnterpriseExternalSupervisedCutoverWorkOrderPersistedStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-work-item-bind-receipt' => $this->renderEnterpriseExternalSupervisedCutoverWorkItemBindReceipt($mandateRegistry),
                'enterprise-external-supervised-cutover-promotion-status' => $this->renderEnterpriseExternalSupervisedCutoverPromotionStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-final-authority-bind-receipt' => $this->renderEnterpriseExternalSupervisedCutoverFinalAuthorityBindReceipt($mandateRegistry),
                'enterprise-external-supervised-cutover-runtime-invocation-register' => $this->renderEnterpriseExternalSupervisedCutoverRuntimeInvocationRegister($mandateRegistry),
                'enterprise-external-supervised-cutover-runtime-invocation-status' => $this->renderEnterpriseExternalSupervisedCutoverRuntimeInvocationStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-runtime-rehearsal-execute' => $this->renderEnterpriseExternalSupervisedCutoverRuntimeRehearsalExecute($mandateRegistry),
                'enterprise-external-supervised-cutover-rehearsal-promotion-status' => $this->renderEnterpriseExternalSupervisedCutoverRehearsalPromotionStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-manual-handoff-register' => $this->renderEnterpriseExternalSupervisedCutoverManualHandoffRegister($mandateRegistry),
                'enterprise-external-supervised-cutover-manual-handoff-status' => $this->renderEnterpriseExternalSupervisedCutoverManualHandoffStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-manual-closeout-bind-receipt' => $this->renderEnterpriseExternalSupervisedCutoverManualCloseoutBindReceipt($mandateRegistry),
                'enterprise-external-supervised-cutover-manual-closeout-status' => $this->renderEnterpriseExternalSupervisedCutoverManualCloseoutStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-portfolio-readiness-status' => $this->renderEnterpriseExternalSupervisedCutoverPortfolioReadinessStatus($mandateRegistry),
                'enterprise-external-supervised-cutover-company-evidence-bundle-apply' => $this->renderEnterpriseExternalSupervisedCutoverCompanyEvidenceBundleApply($mandateRegistry),
                'enterprise-external-supervised-cutover-portfolio-evidence-bundle-apply' => $this->renderEnterpriseExternalSupervisedCutoverPortfolioEvidenceBundleApply($mandateRegistry),
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
        $steps['external_research_adoption_runtime_status'] = $flowActionRuntime->externalResearchAdoptionRuntimeStatus($companyId);
        $steps['flow_benchmark_replay_runtime_status'] = $flowActionRuntime->flowBenchmarkReplayRuntimeStatus($companyId);
        $steps['connector_certification_preflight_runtime_status'] = $flowActionRuntime->connectorCertificationPreflightRuntimeStatus($companyId);
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
        $steps['company_operational_execution_loop_status'] = $mandateRegistry->enterpriseCompanyOperationalExecutionLoopStatus($companyId);
        $steps['company_work_product_acceptance_evidence_status'] = $mandateRegistry->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($companyId);
        $steps['company_work_product_runtime_register'] = $mandateRegistry->enterpriseCompanyWorkProductRuntimeRegister($companyId);
        $steps['company_work_product_runtime_status'] = $mandateRegistry->enterpriseCompanyWorkProductRuntimeStatus($companyId);
        $steps['company_operating_blueprint_runtime_register'] = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeRegister($companyId);
        $steps['company_operating_blueprint_runtime_status'] = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeStatus($companyId);
        $steps['company_business_runtime_persistence_register'] = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceRegister($companyId);
        $steps['company_business_runtime_persistence_status'] = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceStatus($companyId);
        $steps['company_commercial_service_catalog_status'] = $mandateRegistry->enterpriseCompanyCommercialServiceCatalogStatus($companyId);
        $steps['company_revenue_delivery_operating_mesh_status'] = $mandateRegistry->enterpriseCompanyRevenueDeliveryOperatingMeshStatus($companyId);
        $steps['company_org_operating_model_status'] = $mandateRegistry->enterpriseCompanyOrgOperatingModelStatus($companyId);
        $steps['company_customer_delivery_lifecycle_status'] = $mandateRegistry->enterpriseCompanyCustomerDeliveryLifecycleStatus($companyId);
        $steps['company_quality_compliance_lifecycle_status'] = $mandateRegistry->enterpriseCompanyQualityComplianceLifecycleStatus($companyId);
        $steps['company_agent_operations_pack_status'] = $mandateRegistry->enterpriseCompanyAgentOperationsPackStatus($companyId);
        $steps['company_production_readiness_certification_status'] = $mandateRegistry->enterpriseCompanyProductionReadinessCertificationStatus($companyId);
        $steps['company_operating_evidence_bundle_status'] = $mandateRegistry->enterpriseCompanyOperatingEvidenceBundleStatus($companyId);
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
                'holding_outcome_scorecard_average_score' => (float) data_get($steps, 'holding_outcome_scorecard_status.summary.average_score', 0.0),
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
                'agent_repository_operating_catalog_ready_company_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.ready_company_count', 0),
                'agent_repository_operating_catalog_framework_profile_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.framework_profile_count', 0),
                'agent_repository_operating_catalog_mcp_security_profile_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.mcp_security_profile_count', 0),
                'agent_repository_operating_catalog_flow_map_count' => (int) data_get($steps, 'agent_repository_operating_catalog_status.summary.flow_runtime_map_count', 0),
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
                'flow_run_queue_durable_envelope_count' => (int) data_get($steps, 'flow_run_queue_status.summary.durable_execution_envelope_bound_count', 0),
                'flow_run_queue_checkpoint_resume_count' => (int) data_get($steps, 'flow_run_queue_status.summary.checkpoint_resume_bound_count', 0),
                'flow_run_queue_human_in_loop_count' => (int) data_get($steps, 'flow_run_queue_status.summary.human_in_loop_bound_count', 0),
                'flow_run_queue_trace_receipt_count' => (int) data_get($steps, 'flow_run_queue_status.summary.trace_receipt_bound_count', 0),
                'flow_run_queue_surface_binding_count' => (int) data_get($steps, 'flow_run_queue_status.summary.surface_binding_bound_count', 0),
                'flow_run_queue_fixture_smoke_count' => (int) data_get($steps, 'flow_run_queue_status.summary.fixture_smoke_bound_count', 0),
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
            'enterprise_domain_workload_agent_template_status_hash',
            'enterprise_company_domain_solution_pack_status_hash',
            'enterprise_company_agent_operations_pack_status_hash',
            'enterprise_company_domain_operating_model_certification_status_hash',
            'enterprise_company_operational_execution_loop_status_hash',
            'enterprise_company_work_product_acceptance_evidence_status_hash',
            'enterprise_company_work_product_runtime_status_hash',
            'enterprise_company_operating_blueprint_runtime_status_hash',
            'enterprise_company_business_runtime_persistence_status_hash',
            'enterprise_company_commercial_service_catalog_status_hash',
            'enterprise_company_revenue_delivery_operating_mesh_status_hash',
            'enterprise_company_org_operating_model_status_hash',
            'enterprise_company_customer_delivery_lifecycle_status_hash',
            'enterprise_company_quality_compliance_lifecycle_status_hash',
            'enterprise_company_production_readiness_certification_status_hash',
            'enterprise_company_flow_tool_execution_runtime_status_hash',
            'enterprise_company_domain_adapter_execution_envelope_status_hash',
            'flow_run_queue_registry_hash',
            'flow_run_queue_execution_hash',
            'flow_run_queue_status_hash',
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

    private function renderEnterpriseDomainCompanyExecutionSuiteRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainCompanyExecutionSuiteRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_domain_suite_flows', (string) $payload['summary']['completed_domain_company_execution_suite_flow_count']);
            $this->components->twoColumnDetail('external_actions_blocked', (string) $payload['summary']['external_actions_blocked_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowWorkProductDeliveryRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->flowWorkProductDeliveryRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_delivery_flows', (string) $payload['summary']['completed_flow_work_product_delivery_count']);
            $this->components->twoColumnDetail('external_delivery_blocked', (string) $payload['summary']['external_delivery_blocked_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainDataConnectorOperatingRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->domainDataConnectorOperatingRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_data_connector_flows', (string) $payload['summary']['completed_domain_data_connector_flow_count']);
            $this->components->twoColumnDetail('external_mutations_blocked', (string) $payload['summary']['external_mutations_blocked_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowLiveReadConnectorProbeRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->flowLiveReadConnectorProbeRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_probe_flows', (string) $payload['summary']['completed_flow_live_read_connector_probe_count']);
            $this->components->twoColumnDetail('external_mutations_blocked', (string) $payload['summary']['external_mutations_blocked_count']);
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

    private function renderEnterpriseCompanySystemModelRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->companySystemModelRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_company_system_model_flows', (string) $payload['summary']['completed_company_system_model_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseInternalOperationsBackboneRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->internalOperationsBackboneRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_internal_operations_backbone_flows', (string) $payload['summary']['completed_internal_operations_backbone_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseActivationRunOperationsRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->activationRunOperationsRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_activation_run_operations_flows', (string) $payload['summary']['completed_activation_run_operations_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFlowExecutionFoundationRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->flowExecutionFoundationRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_foundation_flows', (string) $payload['summary']['completed_flow_execution_foundation_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseWorkforceCapacityRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->workforceCapacityRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_workforce_flows', (string) $payload['summary']['completed_workforce_capacity_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterprisePortfolioDependencyRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->portfolioDependencyRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_dependency_flows', (string) $payload['summary']['completed_portfolio_dependency_flow_count']);
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

    private function renderEnterpriseProductizedServiceRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->productizedServiceRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_productized_service_flows', (string) $payload['summary']['completed_productized_service_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseSalesCrmPipelineRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->salesCrmPipelineRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_sales_crm_pipeline_flows', (string) $payload['summary']['completed_sales_crm_pipeline_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCustomerSupportServiceDeskRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->customerSupportServiceDeskRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_customer_support_service_desk_flows', (string) $payload['summary']['completed_customer_support_service_desk_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseMarketingGrowthEngineRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->marketingGrowthEngineRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_marketing_growth_engine_flows', (string) $payload['summary']['completed_marketing_growth_engine_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseFinanceTreasuryBillingRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->financeTreasuryBillingRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_finance_treasury_billing_flows', (string) $payload['summary']['completed_finance_treasury_billing_flow_count']);
            $this->components->twoColumnDetail('coverage_rate', (string) $payload['summary']['coverage_rate']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseGovernanceRiskOperationsRuntimeStatus(EnterpriseFlowFixtureActionRuntimeService $runtime): int
    {
        $company = $this->option('company');
        $payload = $runtime->governanceRiskOperationsRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('completed_governance_risk_operations_flows', (string) $payload['summary']['completed_governance_risk_operations_flow_count']);
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

    private function renderEnterpriseCompanyCompletionCertificationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyCompletionCertificationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('certified_companies', (string) $payload['summary']['completion_certified_company_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('cutover_complete', (string) $payload['summary']['supervised_cutover_complete_company_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseVerticalOperationalDepthStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseVerticalOperationalDepthStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('depth_ready_companies', (string) $payload['summary']['operational_depth_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('required_gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('average_depth_score', (string) $payload['summary']['average_depth_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingCycleStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingCycleStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('cycle_ready_companies', (string) $payload['summary']['operating_cycle_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_cycles', (string) $payload['summary']['ready_flow_cycle_count']);
            $this->components->twoColumnDetail('flow_cycles', (string) $payload['summary']['flow_cycle_count']);
            $this->components->twoColumnDetail('average_cycle_score', (string) $payload['summary']['average_operating_cycle_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingCadenceStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingCadenceStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('cadence_ready_companies', (string) $payload['summary']['cadence_ready_company_count']);
            $this->components->twoColumnDetail('ready_cadences', (string) $payload['summary']['ready_cadence_count']);
            $this->components->twoColumnDetail('cadences', (string) $payload['summary']['cadence_count']);
            $this->components->twoColumnDetail('average_cadence_score', (string) $payload['summary']['average_cadence_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingScorecardStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingScorecardStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('scorecard_ready_companies', (string) $payload['summary']['scorecard_ready_company_count']);
            $this->components->twoColumnDetail('average_internal_outcome_score', (string) $payload['summary']['average_internal_outcome_score']);
            $this->components->twoColumnDetail('average_scorecard_score', (string) $payload['summary']['average_scorecard_score']);
            $this->components->twoColumnDetail('external_revenue_claim_allowed', (string) $payload['summary']['external_revenue_claim_allowed_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyActiveOperatingSystemStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyActiveOperatingSystemStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('active_operating_system_ready_companies', (string) $payload['summary']['active_operating_system_ready_company_count']);
            $this->components->twoColumnDetail('average_active_operating_system_score', (string) $payload['summary']['average_active_operating_system_score']);
            $this->components->twoColumnDetail('average_internal_outcome_score', (string) $payload['summary']['average_internal_outcome_score']);
            $this->components->twoColumnDetail('external_autonomy_allowed', (string) $payload['summary']['external_autonomy_allowed_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyCapabilityCatalogStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyCapabilityCatalogStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('catalog_ready_companies', (string) $payload['summary']['catalog_ready_company_count']);
            $this->components->twoColumnDetail('ready_families', (string) $payload['summary']['ready_family_count']);
            $this->components->twoColumnDetail('families', (string) $payload['summary']['required_family_count']);
            $this->components->twoColumnDetail('ready_flow_capabilities', (string) $payload['summary']['ready_flow_capability_count']);
            $this->components->twoColumnDetail('flow_capabilities', (string) $payload['summary']['flow_capability_count']);
            $this->components->twoColumnDetail('average_catalog_score', (string) $payload['summary']['average_catalog_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyIntegrationReadinessStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyIntegrationReadinessStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('integration_ready_companies', (string) $payload['summary']['integration_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('ready_flow_integrations', (string) $payload['summary']['ready_flow_integration_count']);
            $this->components->twoColumnDetail('flow_integrations', (string) $payload['summary']['flow_integration_count']);
            $this->components->twoColumnDetail('average_integration_score', (string) $payload['summary']['average_integration_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainWorkloadAgentTemplateStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseDomainWorkloadAgentTemplateStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready_companies', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('templates', (string) $payload['summary']['template_count']);
            $this->components->twoColumnDetail('ready_templates', (string) $payload['summary']['ready_template_count']);
            $this->components->twoColumnDetail('skills', (string) $payload['summary']['skill_count']);
            $this->components->twoColumnDetail('subagents', (string) $payload['summary']['subagent_count']);
            $this->components->twoColumnDetail('distribution_packages', (string) $payload['summary']['distribution_package_ready_count']);
            $this->components->twoColumnDetail('rollout_plans', (string) $payload['summary']['rollout_plan_ready_count']);
            $this->components->twoColumnDetail('tool_permission_matrices', (string) $payload['summary']['tool_permission_matrix_ready_count']);
            $this->components->twoColumnDetail('surface_bindings', (string) $payload['summary']['execution_surface_binding_ready_count']);
            $this->components->twoColumnDetail('fixture_smoke_contracts', (string) $payload['summary']['fixture_smoke_contract_ready_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyDomainOperatingModelCertificationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyDomainOperatingModelCertificationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('certified_companies', (string) $payload['summary']['certified_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('average_certification_score', (string) $payload['summary']['average_certification_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperationalExecutionLoopStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperationalExecutionLoopStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('loop_ready_companies', (string) $payload['summary']['operational_execution_loop_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('queue_attempted', (string) $payload['summary']['queue_attempted_count']);
            $this->components->twoColumnDetail('runbooks_green', (string) $payload['summary']['runbook_operations_green_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyWorkProductAcceptanceEvidenceStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyWorkProductAcceptanceEvidenceStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('acceptance_ready_companies', (string) $payload['summary']['work_product_acceptance_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('acceptance_contracts', (string) $payload['summary']['acceptance_contract_count']);
            $this->components->twoColumnDetail('handoff_packets', (string) $payload['summary']['handoff_packet_count']);
            $this->components->twoColumnDetail('external_delivery_allowed', ($payload['policy']['external_delivery_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyWorkProductRuntimeRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyWorkProductRuntimeRegister(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('registered_work_product_runs', (string) $payload['summary']['registered_work_product_run_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('external_delivery_allowed', ($payload['policy']['external_delivery_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyWorkProductRuntimeStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyWorkProductRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('runtime_ready_companies', (string) $payload['summary']['work_product_runtime_ready_company_count']);
            $this->components->twoColumnDetail('ready_work_product_runs', (string) $payload['summary']['ready_persisted_work_product_run_count']);
            $this->components->twoColumnDetail('persisted_work_product_runs', (string) $payload['summary']['persisted_work_product_run_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('external_delivery_allowed', ($payload['policy']['external_delivery_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingBlueprintRuntimeRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeRegister(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('registered_blueprint_runs', (string) $payload['summary']['registered_blueprint_run_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingBlueprintRuntimeStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingBlueprintRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('runtime_ready_companies', (string) $payload['summary']['operating_blueprint_runtime_ready_company_count']);
            $this->components->twoColumnDetail('ready_blueprint_runs', (string) $payload['summary']['ready_persisted_blueprint_run_count']);
            $this->components->twoColumnDetail('persisted_blueprint_runs', (string) $payload['summary']['persisted_blueprint_run_count']);
            $this->components->twoColumnDetail('expected_flows', (string) $payload['summary']['expected_flow_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyBusinessRuntimePersistenceRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceRegister(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('business_runtime_layers', (string) $payload['summary']['business_runtime_layer_count']);
            $this->components->twoColumnDetail('registered_business_runtime_runs', (string) $payload['summary']['registered_business_runtime_run_count']);
            $this->components->twoColumnDetail('expected_business_runtime_runs', (string) $payload['summary']['expected_business_runtime_run_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyBusinessRuntimePersistenceStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyBusinessRuntimePersistenceStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('business_runtime_layers', (string) $payload['summary']['business_runtime_layer_count']);
            $this->components->twoColumnDetail('runtime_ready_companies', (string) $payload['summary']['business_runtime_persistence_ready_company_count']);
            $this->components->twoColumnDetail('ready_business_runtime_runs', (string) $payload['summary']['ready_persisted_business_runtime_run_count']);
            $this->components->twoColumnDetail('persisted_business_runtime_runs', (string) $payload['summary']['persisted_business_runtime_run_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyProductionReadinessCertificationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyProductionReadinessCertificationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('production_ready_companies', (string) $payload['summary']['production_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('average_production_readiness_score', (string) $payload['summary']['average_production_readiness_score']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
            $this->components->twoColumnDetail('external_launch_allowed', ($payload['policy']['external_launch_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyCommercialServiceCatalogStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyCommercialServiceCatalogStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('commercial_catalog_ready_companies', (string) $payload['summary']['commercial_service_catalog_ready_company_count']);
            $this->components->twoColumnDetail('ready_commercial_flows', (string) $payload['summary']['ready_commercial_flow_count']);
            $this->components->twoColumnDetail('service_offers', (string) $payload['summary']['service_offer_count']);
            $this->components->twoColumnDetail('pricing_packages', (string) $payload['summary']['pricing_package_count']);
            $this->components->twoColumnDetail('sla_contracts', (string) $payload['summary']['sla_success_contract_count']);
            $this->components->twoColumnDetail('external_revenue_claim_allowed', ($payload['policy']['external_revenue_claim_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyRevenueDeliveryOperatingMeshStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyRevenueDeliveryOperatingMeshStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('mesh_ready_companies', (string) $payload['summary']['revenue_delivery_operating_mesh_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_threads', (string) $payload['summary']['ready_flow_thread_count']);
            $this->components->twoColumnDetail('operating_systems', (string) $payload['summary']['operating_system_count']);
            $this->components->twoColumnDetail('connector_maps', (string) $payload['summary']['connector_map_count']);
            $this->components->twoColumnDetail('external_billing_allowed', ($payload['policy']['external_billing_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOrgOperatingModelStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOrgOperatingModelStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('org_ready_companies', (string) $payload['summary']['org_operating_model_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_org_records', (string) $payload['summary']['ready_flow_org_record_count']);
            $this->components->twoColumnDetail('flow_staffing', (string) $payload['summary']['flow_staffing_count']);
            $this->components->twoColumnDetail('vendor_due_diligence', (string) $payload['summary']['vendor_due_diligence_count']);
            $this->components->twoColumnDetail('audit_evidence_requirements', (string) $payload['summary']['audit_evidence_requirement_count']);
            $this->components->twoColumnDetail('external_procurement_allowed', ($payload['policy']['external_procurement_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyCustomerDeliveryLifecycleStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyCustomerDeliveryLifecycleStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('lifecycle_ready_companies', (string) $payload['summary']['customer_delivery_lifecycle_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_lifecycles', (string) $payload['summary']['ready_flow_lifecycle_count']);
            $this->components->twoColumnDetail('account_health_risks', (string) $payload['summary']['account_health_risk_count']);
            $this->components->twoColumnDetail('billing_controls', (string) $payload['summary']['billing_ledger_control_count']);
            $this->components->twoColumnDetail('external_customer_commitment_allowed', ($payload['policy']['external_customer_commitment_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyQualityComplianceLifecycleStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyQualityComplianceLifecycleStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('quality_ready_companies', (string) $payload['summary']['quality_compliance_lifecycle_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_quality_records', (string) $payload['summary']['ready_flow_quality_count']);
            $this->components->twoColumnDetail('replay_matrices', (string) $payload['summary']['replay_matrix_count']);
            $this->components->twoColumnDetail('audit_evidence_requirements', (string) $payload['summary']['audit_evidence_requirement_count']);
            $this->components->twoColumnDetail('external_benchmark_claim_allowed', ($payload['policy']['external_benchmark_claim_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyDomainSolutionPackStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyDomainSolutionPackStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('solution_pack_ready_companies', (string) $payload['summary']['domain_solution_pack_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_solution_packs', (string) $payload['summary']['ready_flow_solution_pack_count']);
            $this->components->twoColumnDetail('domain_sources', (string) $payload['summary']['domain_source_count']);
            $this->components->twoColumnDetail('solution_modules', (string) $payload['summary']['solution_module_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyAgentOperationsPackStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyAgentOperationsPackStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('agent_ops_ready_companies', (string) $payload['summary']['agent_operations_pack_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_agent_ops', (string) $payload['summary']['ready_flow_agent_operations_pack_count']);
            $this->components->twoColumnDetail('skills', (string) $payload['summary']['skill_count']);
            $this->components->twoColumnDetail('subagents', (string) $payload['summary']['subagent_count']);
            $this->components->twoColumnDetail('adapter_envelopes', (string) $payload['summary']['adapter_envelope_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyDomainToolExecutionReadinessStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyDomainToolExecutionReadinessStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('tool_execution_ready_companies', (string) $payload['summary']['tool_execution_ready_company_count']);
            $this->components->twoColumnDetail('ready_flow_tool_execution', (string) $payload['summary']['ready_flow_tool_execution_count']);
            $this->components->twoColumnDetail('flow_tool_execution', (string) $payload['summary']['flow_tool_execution_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyFlowToolExecutionLedgerStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyFlowToolExecutionLedgerStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ledger_ready_companies', (string) $payload['summary']['flow_tool_execution_ledger_ready_company_count']);
            $this->components->twoColumnDetail('ready_ledger_records', (string) $payload['summary']['ready_ledger_record_count']);
            $this->components->twoColumnDetail('ledger_records', (string) $payload['summary']['ledger_record_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyFlowToolExecutionRuntimeRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyFlowToolExecutionRuntimeRegister(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('registered_runs', (string) $payload['summary']['registered_run_count']);
            $this->components->twoColumnDetail('expected_runs', (string) $payload['summary']['expected_ledger_record_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyFlowToolExecutionRuntimeStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyFlowToolExecutionRuntimeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('runtime_ready_companies', (string) $payload['summary']['persisted_runtime_ready_company_count']);
            $this->components->twoColumnDetail('ready_persisted_runs', (string) $payload['summary']['ready_persisted_run_count']);
            $this->components->twoColumnDetail('persisted_runs', (string) $payload['summary']['persisted_run_count']);
            $this->components->twoColumnDetail('expected_runs', (string) $payload['summary']['expected_run_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyDomainAdapterExecutionEnvelopeRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyDomainAdapterExecutionEnvelopeRegister(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('registered_envelopes', (string) $payload['summary']['registered_envelope_count']);
            $this->components->twoColumnDetail('expected_connectors', (string) $payload['summary']['expected_connector_readiness_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyDomainAdapterExecutionEnvelopeStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyDomainAdapterExecutionEnvelopeStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('adapter_ready_companies', (string) $payload['summary']['adapter_envelope_ready_company_count']);
            $this->components->twoColumnDetail('ready_envelopes', (string) $payload['summary']['ready_persisted_envelope_count']);
            $this->components->twoColumnDetail('persisted_envelopes', (string) $payload['summary']['persisted_envelope_count']);
            $this->components->twoColumnDetail('expected_envelopes', (string) $payload['summary']['expected_envelope_count']);
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseCompanyOperatingEvidenceBundleStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->enterpriseCompanyOperatingEvidenceBundleStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('bundle_ready_companies', (string) $payload['summary']['operating_evidence_bundle_ready_company_count']);
            $this->components->twoColumnDetail('ready_gates', (string) $payload['summary']['ready_gate_count']);
            $this->components->twoColumnDetail('gates', (string) $payload['summary']['required_gate_count']);
            $this->components->twoColumnDetail('flow_evidence_records', (string) $payload['summary']['flow_evidence_record_count']);
            $this->components->twoColumnDetail('average_bundle_score', (string) $payload['summary']['average_bundle_score']);
            $this->components->twoColumnDetail('external_launch_allowed', ($payload['policy']['external_launch_allowed'] ?? false) ? 'true' : 'false');
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

    private function renderEnterpriseAgentRepositoryOperatingCatalogStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->agentRepositoryOperatingCatalogStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('framework_profiles', (string) $payload['summary']['framework_profile_count']);
            $this->components->twoColumnDetail('mcp_security_profiles', (string) $payload['summary']['mcp_security_profile_count']);
            $this->components->twoColumnDetail('flow_runtime_maps', (string) $payload['summary']['flow_runtime_map_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseDomainAgentToolchainCertificationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->domainAgentToolchainCertificationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready_company_count']);
            $this->components->twoColumnDetail('tool_contracts', (string) $payload['summary']['certified_tool_contract_count']);
            $this->components->twoColumnDetail('toolkit_certifications', (string) $payload['summary']['toolkit_certification_count']);
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

    private function renderEnterpriseExternalWorkerDispatchPlanStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalWorkerDispatchPlanStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('dispatch_plans', (string) $payload['summary']['dispatch_plan_ready_count']);
            $this->components->twoColumnDetail('dispatch_enabled', $payload['policy']['external_worker_dispatch_enabled'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalLaunchControlStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalLaunchControlStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('launch_controls', (string) $payload['summary']['launch_control_ready_count']);
            $this->components->twoColumnDetail('launch_enabled', $payload['policy']['launch_enabled'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalReceiptBindingStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalReceiptBindingStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('receipt_slots', (string) $payload['summary']['receipt_slot_count']);
            $this->components->twoColumnDetail('bound_receipts', (string) $payload['summary']['bound_receipt_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverDossierStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverDossierStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('cutover_dossiers', (string) $payload['summary']['cutover_dossier_ready_count']);
            $this->components->twoColumnDetail('cutover_enabled', (string) $payload['summary']['supervised_cutover_enabled_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverWorkOrderStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverWorkOrderStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) $payload['summary']['company_count']);
            $this->components->twoColumnDetail('flows', (string) $payload['summary']['flow_count']);
            $this->components->twoColumnDetail('work_orders', (string) $payload['summary']['work_order_ready_count']);
            $this->components->twoColumnDetail('work_items', (string) $payload['summary']['work_item_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverWorkOrderRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->registerExternalSupervisedCutoverWorkOrders(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('work_orders', (string) $payload['summary']['work_order_count']);
            $this->components->twoColumnDetail('work_items', (string) $payload['summary']['work_item_count']);
            $this->components->twoColumnDetail('executable_items', (string) $payload['summary']['executable_item_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverWorkOrderPersistedStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverWorkOrderPersistedStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('work_orders', (string) $payload['summary']['work_order_count']);
            $this->components->twoColumnDetail('work_items', (string) $payload['summary']['work_item_count']);
            $this->components->twoColumnDetail('pending_items', (string) $payload['summary']['pending_work_item_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverWorkItemBindReceipt(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $workItem = $this->option('work-item');
        $receiptHash = $this->option('receipt-hash');
        $receiptSource = $this->option('receipt-source');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->bindExternalSupervisedCutoverWorkItemReceipt(
            is_string($workItem) ? $workItem : null,
            is_string($receiptHash) ? $receiptHash : null,
            is_string($receiptSource) ? $receiptSource : null,
            is_string($operator) ? $operator : null,
            is_string($note) ? $note : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('bound_receipts', (string) ($payload['summary']['bound_receipt_count'] ?? 0));
            $this->components->twoColumnDetail('pending_items', (string) ($payload['summary']['pending_work_item_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverPromotionStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverPromotionStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('work_orders', (string) $payload['summary']['work_order_count']);
            $this->components->twoColumnDetail('promotion_ready', (string) $payload['summary']['promotion_review_ready_count']);
            $this->components->twoColumnDetail('pending_items', (string) $payload['summary']['pending_work_item_count']);
            $this->components->twoColumnDetail('external_execution_allowed', $payload['policy']['external_execution_allowed'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverFinalAuthorityBindReceipt(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $workOrder = $this->option('work-order');
        $authority = $this->option('authority');
        $receiptHash = $this->option('receipt-hash');
        $receiptSource = $this->option('receipt-source');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->bindExternalSupervisedCutoverFinalAuthorityReceipt(
            is_string($workOrder) ? $workOrder : null,
            is_string($authority) ? $authority : null,
            is_string($receiptHash) ? $receiptHash : null,
            is_string($receiptSource) ? $receiptSource : null,
            is_string($operator) ? $operator : null,
            is_string($note) ? $note : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('final_authorities', (string) ($payload['summary']['final_authority_binding_count'] ?? 0));
            $this->components->twoColumnDetail('missing_authorities', (string) ($payload['summary']['missing_final_authority_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverRuntimeInvocationRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $workOrder = $this->option('work-order');
        $payload = $mandateRegistry->registerExternalSupervisedCutoverRuntimeInvocation(is_string($workOrder) ? $workOrder : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('invocations', (string) ($payload['summary']['runtime_invocation_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverRuntimeInvocationStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverRuntimeInvocationStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('invocations', (string) ($payload['summary']['runtime_invocation_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverRuntimeRehearsalExecute(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $invocation = $this->option('invocation');
        $company = $this->option('company');
        $payload = $mandateRegistry->executeExternalSupervisedCutoverRuntimeRehearsal(
            is_string($invocation) ? $invocation : null,
            is_string($company) && trim($company) !== '' ? trim($company) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('execution_receipts', (string) ($payload['summary']['execution_receipt_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverRehearsalPromotionStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverRehearsalPromotionStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('ready_packets', (string) ($payload['summary']['manual_execution_packet_ready_count'] ?? 0));
            $this->components->twoColumnDetail('blocked_packets', (string) ($payload['summary']['manual_execution_packet_blocked_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverManualHandoffRegister(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $workOrder = $this->option('work-order');
        $company = $this->option('company');
        $payload = $mandateRegistry->registerExternalSupervisedCutoverManualHandoff(
            is_string($workOrder) ? $workOrder : null,
            is_string($company) && trim($company) !== '' ? trim($company) : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('handoff_packets', (string) ($payload['summary']['manual_handoff_packet_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverManualHandoffStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverManualHandoffStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('handoff_packets', (string) ($payload['summary']['manual_handoff_packet_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverManualCloseoutBindReceipt(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $workOrder = $this->option('work-order');
        $receiptHash = $this->option('receipt-hash');
        $receiptSource = $this->option('receipt-source');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->bindExternalSupervisedCutoverManualCloseoutReceipt(
            is_string($workOrder) ? $workOrder : null,
            is_string($receiptHash) ? $receiptHash : null,
            is_string($receiptSource) ? $receiptSource : null,
            is_string($operator) ? $operator : null,
            is_string($note) ? $note : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('closeout_receipts', (string) ($payload['summary']['manual_closeout_receipt_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverManualCloseoutStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverManualCloseoutStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('closeout_receipts', (string) ($payload['summary']['manual_closeout_receipt_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverPortfolioReadinessStatus(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $payload = $mandateRegistry->externalSupervisedCutoverPortfolioReadinessStatus(is_string($company) && trim($company) !== '' ? trim($company) : null);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('expected_flows', (string) ($payload['summary']['expected_flow_count'] ?? 0));
            $this->components->twoColumnDetail('complete_companies', (string) ($payload['summary']['cutover_chain_complete_company_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverCompanyEvidenceBundleApply(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $company = $this->option('company');
        $receiptHash = $this->option('receipt-hash');
        $receiptSource = $this->option('receipt-source');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->applyExternalSupervisedCutoverCompanyEvidenceBundle(
            is_string($company) && trim($company) !== '' ? trim($company) : null,
            is_string($receiptHash) ? $receiptHash : null,
            is_string($receiptSource) ? $receiptSource : null,
            is_string($operator) ? $operator : null,
            is_string($note) ? $note : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('company', (string) ($payload['company_id'] ?? ''));
            $this->components->twoColumnDetail('work_orders', (string) ($payload['summary']['work_order_count'] ?? 0));
            $this->components->twoColumnDetail('complete_companies', (string) ($payload['summary']['cutover_chain_complete_company_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderEnterpriseExternalSupervisedCutoverPortfolioEvidenceBundleApply(ExternalActionMandateRegistryService $mandateRegistry): int
    {
        $receiptHash = $this->option('receipt-hash');
        $receiptSource = $this->option('receipt-source');
        $operator = $this->option('operator');
        $note = $this->option('note');
        $payload = $mandateRegistry->applyExternalSupervisedCutoverPortfolioEvidenceBundle(
            is_string($receiptHash) ? $receiptHash : null,
            is_string($receiptSource) ? $receiptSource : null,
            is_string($operator) ? $operator : null,
            is_string($note) ? $note : null,
        );
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('companies', (string) ($payload['summary']['company_count'] ?? 0));
            $this->components->twoColumnDetail('expected_flows', (string) ($payload['summary']['expected_flow_count'] ?? 0));
            $this->components->twoColumnDetail('complete_companies', (string) ($payload['summary']['cutover_chain_complete_company_count'] ?? 0));
            $this->components->twoColumnDetail('external_execution_allowed', ($payload['policy']['external_execution_allowed'] ?? false) ? 'true' : 'false');
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
            $this->components->twoColumnDetail('durable_envelopes', (string) $payload['summary']['durable_execution_envelope_bound_count']);
            $this->components->twoColumnDetail('hitl_checkpoints', (string) $payload['summary']['human_in_loop_bound_count']);
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
            $this->components->twoColumnDetail('durable_envelopes', (string) $payload['summary']['durable_execution_envelope_bound_count']);
            $this->components->twoColumnDetail('trace_receipts', (string) $payload['summary']['trace_receipt_bound_count']);
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
            $this->components->twoColumnDetail('durable_envelopes', (string) $payload['summary']['durable_execution_envelope_bound_count']);
            $this->components->twoColumnDetail('hitl_checkpoints', (string) $payload['summary']['human_in_loop_bound_count']);
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
            $this->components->twoColumnDetail('durable_envelopes', (string) $payload['summary']['durable_execution_envelope_bound_count']);
            $this->components->twoColumnDetail('trace_receipts', (string) $payload['summary']['trace_receipt_bound_count']);
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
