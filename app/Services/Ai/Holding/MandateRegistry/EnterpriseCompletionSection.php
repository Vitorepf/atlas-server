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
class EnterpriseCompletionSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCompletionCertificationStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtime = $this->hub->flowActionRuntime->runtimeStatus($wantedCompany);
        $companySystemModelRuntime = $this->hub->flowActionRuntime->companySystemModelRuntimeStatus($wantedCompany);
        $internalOperationsBackboneRuntime = $this->hub->flowActionRuntime->internalOperationsBackboneRuntimeStatus($wantedCompany);
        $activationRunOperationsRuntime = $this->hub->flowActionRuntime->activationRunOperationsRuntimeStatus($wantedCompany);
        $domainCompanyExecutionSuiteRuntime = $this->hub->flowActionRuntime->domainCompanyExecutionSuiteRuntimeStatus($wantedCompany);
        $flowWorkProductDeliveryRuntime = $this->hub->flowActionRuntime->flowWorkProductDeliveryRuntimeStatus($wantedCompany);
        $boardReview = $this->hub->flowActionRuntime->companyBoardOperatingReviewStatus($wantedCompany);
        $dossier = $this->hub->realExecutionChain->realExternalExecutionReadinessDossier($wantedCompany);
        $handoffPack = $this->hub->realExecutionChain->realExternalExecutionHandoffPack($wantedCompany);
        $cutover = $this->hub->cutoverCloseout->externalSupervisedCutoverPortfolioReadinessStatus($wantedCompany);

        $runtimeByCompany = $this->hub->companyRowsById($runtime);
        $companySystemModelByCompany = $this->hub->companyRowsById($companySystemModelRuntime);
        $internalOperationsBackboneByCompany = $this->hub->companyRowsById($internalOperationsBackboneRuntime);
        $activationRunOperationsByCompany = $this->hub->companyRowsById($activationRunOperationsRuntime);
        $domainCompanyExecutionSuiteByCompany = $this->hub->companyRowsById($domainCompanyExecutionSuiteRuntime);
        $flowWorkProductDeliveryByCompany = $this->hub->companyRowsById($flowWorkProductDeliveryRuntime);
        $boardByCompany = $this->hub->companyRowsById($boardReview);
        $dossierByCompany = $this->hub->companyRowsById($dossier);
        $handoffByCompany = $this->hub->companyRowsById($handoffPack);
        $cutoverByCompany = $this->hub->companyRowsById($cutover);

        $companyRows = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $runtimeRow = (array) ($runtimeByCompany[$id] ?? []);
            $companySystemModelRow = (array) ($companySystemModelByCompany[$id] ?? []);
            $internalOperationsBackboneRow = (array) ($internalOperationsBackboneByCompany[$id] ?? []);
            $activationRunOperationsRow = (array) ($activationRunOperationsByCompany[$id] ?? []);
            $domainCompanyExecutionSuiteRow = (array) ($domainCompanyExecutionSuiteByCompany[$id] ?? []);
            $flowWorkProductDeliveryRow = (array) ($flowWorkProductDeliveryByCompany[$id] ?? []);
            $boardRow = (array) ($boardByCompany[$id] ?? []);
            $dossierRow = (array) ($dossierByCompany[$id] ?? []);
            $handoffRow = (array) ($handoffByCompany[$id] ?? []);
            $cutoverRow = (array) ($cutoverByCompany[$id] ?? []);
            $structuralRuntimeComplete = $expectedFlowCount > 0
                && count((array) ($company['flow_execution_contracts'] ?? [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_operating_system.runbooks', [])) >= $expectedFlowCount;
            $structuralCompanySystemComplete = count((array) data_get($company, 'enterprise_company_operating_blueprint_stack.flow_operating_blueprints', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_company_operating_blueprint_stack.artifact_assembly_lines', [])) >= $expectedFlowCount;
            $structuralInternalBackboneComplete = data_get($company, 'enterprise_operating_system.schema') === 'atlas.ai.company.enterprise_operating_system.v1'
                && count((array) data_get($company, 'enterprise_operating_system.sla_catalog', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_operating_system.risk_register', [])) >= min(4, $expectedFlowCount);
            $structuralActivationComplete = count((array) data_get($company, 'enterprise_integration_activation_plan.flow_activation_matrix', [])) >= $expectedFlowCount
                && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.external_write_blocked_until_operator_mandate', false);
            $structuralDomainSuiteComplete = count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_execution_packets', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', [])) >= $expectedFlowCount;
            $structuralDeliveryComplete = data_get($company, 'enterprise_flow_work_product_delivery_stack.schema') === 'atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1'
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])) >= $expectedFlowCount
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true) === false;
            $structuralBoardReady = data_get($company, 'enterprise_operating_system.governance_board.cadence') === 'weekly_operating_board'
                && data_get($company, 'enterprise_operating_system.governance_board.decision_rights.external_action') === 'operator_approval_required'
                && count((array) data_get($company, 'enterprise_operating_system.okr_scorecard', [])) >= 4;

            $runtimeComplete = $expectedFlowCount > 0
                && (int) ($runtimeRow['completed_runtime_flow_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($runtimeRow['missing_runtime_flows'] ?? [])) === 0
                || $structuralRuntimeComplete;
            $companySystemModelRuntimeComplete = $expectedFlowCount > 0
                && (int) ($companySystemModelRow['completed_company_system_model_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($companySystemModelRow['external_actions_blocked_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($companySystemModelRow['missing_company_system_model_flows'] ?? [])) === 0
                || $structuralCompanySystemComplete;
            $internalOperationsBackboneRuntimeComplete = $expectedFlowCount > 0
                && (int) ($internalOperationsBackboneRow['completed_internal_operations_backbone_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($internalOperationsBackboneRow['external_actions_blocked_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($internalOperationsBackboneRow['missing_internal_operations_backbone_flows'] ?? [])) === 0
                || $structuralInternalBackboneComplete;
            $activationRunOperationsRuntimeComplete = $expectedFlowCount > 0
                && (int) ($activationRunOperationsRow['completed_activation_run_operations_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($activationRunOperationsRow['external_actions_blocked_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($activationRunOperationsRow['missing_activation_run_operations_flows'] ?? [])) === 0
                || $structuralActivationComplete;
            $domainCompanyExecutionSuiteRuntimeComplete = $expectedFlowCount > 0
                && (int) ($domainCompanyExecutionSuiteRow['completed_domain_company_execution_suite_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($domainCompanyExecutionSuiteRow['external_actions_blocked_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($domainCompanyExecutionSuiteRow['missing_domain_company_execution_suite_flows'] ?? [])) === 0
                || $structuralDomainSuiteComplete;
            $flowWorkProductDeliveryRuntimeComplete = $expectedFlowCount > 0
                && (int) ($flowWorkProductDeliveryRow['completed_flow_work_product_delivery_count'] ?? 0) >= $expectedFlowCount
                && (int) ($flowWorkProductDeliveryRow['external_delivery_blocked_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($flowWorkProductDeliveryRow['missing_flow_work_product_delivery_flows'] ?? [])) === 0
                || $structuralDeliveryComplete;
            $boardReady = (bool) ($boardRow['ready'] ?? false)
                && (int) ($boardRow['completed_outcome_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($boardRow['business_operating_packet_flow_count'] ?? 0) >= $expectedFlowCount
                && (int) ($boardRow['command_center_flow_count'] ?? 0) >= $expectedFlowCount
                || $structuralBoardReady;
            $dossierReady = (bool) ($dossier['ok'] ?? false)
                && (bool) ($dossierRow['provider_workbench_ready'] ?? false)
                && (bool) ($dossierRow['agent_repository_adoption_ready'] ?? false)
                && (bool) ($dossierRow['industry_solution_ecosystem_ready'] ?? false)
                && (bool) ($dossierRow['business_operating_backbone_ready'] ?? false)
                && (bool) ($dossierRow['production_connector_preflight_ready'] ?? false)
                && (bool) ($dossierRow['flow_quality_research_ready'] ?? false)
                && (bool) ($dossierRow['vertical_solution_suite_ready'] ?? false)
                && (bool) ($dossierRow['domain_business_execution_mesh_ready'] ?? false)
                && (bool) ($dossierRow['flow_operating_package_ready'] ?? false)
                && (bool) ($dossierRow['company_command_center_ready'] ?? false)
                && (bool) ($dossierRow['operational_dress_rehearsal_ready'] ?? false);
            $handoffReady = (bool) ($handoffPack['ok'] ?? false)
                && (int) ($handoffRow['handoff_pack_ready_count'] ?? 0) >= $expectedFlowCount
                && (int) ($handoffRow['external_execution_allowed_count'] ?? 1) === 0;
            $cutoverComplete = (bool) ($cutoverRow['cutover_chain_complete'] ?? false)
                && (int) ($cutoverRow['manual_closeout_receipt_count'] ?? 0) >= $expectedFlowCount
                && count((array) ($cutoverRow['missing_capabilities'] ?? [])) === 0;

            $missing = [];
            if (! $runtimeComplete) {
                $missing[] = 'internal_flow_action_runtime_not_complete';
            }
            if (! $companySystemModelRuntimeComplete) {
                $missing[] = 'company_system_model_runtime_not_complete';
            }
            if (! $internalOperationsBackboneRuntimeComplete) {
                $missing[] = 'internal_operations_backbone_runtime_not_complete';
            }
            if (! $activationRunOperationsRuntimeComplete) {
                $missing[] = 'activation_run_operations_runtime_not_complete';
            }
            if (! $domainCompanyExecutionSuiteRuntimeComplete) {
                $missing[] = 'domain_company_execution_suite_runtime_not_complete';
            }
            if (! $flowWorkProductDeliveryRuntimeComplete) {
                $missing[] = 'flow_work_product_delivery_runtime_not_complete';
            }
            if (! $boardReady) {
                $missing[] = 'board_operating_review_or_internal_outcomes_not_ready';
            }
            if (! $dossierReady) {
                $missing[] = 'real_external_execution_readiness_dossier_not_ready';
            }
            if (! $handoffReady) {
                $missing[] = 'manual_handoff_pack_not_ready';
            }
            if (! $cutoverComplete) {
                $missing[] = 'supervised_cutover_receipt_chain_not_complete';
            }

            $ready = $missing === [];
            $row = [
                'schema' => 'atlas.ai.company.enterprise_completion_certification_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'internal_runtime_complete' => $runtimeComplete,
                'company_system_model_runtime_complete' => $companySystemModelRuntimeComplete,
                'internal_operations_backbone_runtime_complete' => $internalOperationsBackboneRuntimeComplete,
                'activation_run_operations_runtime_complete' => $activationRunOperationsRuntimeComplete,
                'domain_company_execution_suite_runtime_complete' => $domainCompanyExecutionSuiteRuntimeComplete,
                'flow_work_product_delivery_runtime_complete' => $flowWorkProductDeliveryRuntimeComplete,
                'board_operating_review_ready' => $boardReady,
                'real_external_execution_dossier_ready' => $dossierReady,
                'manual_handoff_pack_ready' => $handoffReady,
                'supervised_cutover_receipt_chain_complete' => $cutoverComplete,
                'completion_certified' => $ready,
                'runtime_coverage_snapshot' => [
                    'internal_flow_action_runtime_coverage_rate' => (float) ($runtimeRow['coverage_rate'] ?? 0.0),
                    'company_system_model_runtime_coverage_rate' => (float) ($companySystemModelRow['coverage_rate'] ?? 0.0),
                    'company_system_model_external_actions_blocked_count' => (int) ($companySystemModelRow['external_actions_blocked_count'] ?? 0),
                    'internal_operations_backbone_runtime_coverage_rate' => (float) ($internalOperationsBackboneRow['coverage_rate'] ?? 0.0),
                    'internal_operations_backbone_external_actions_blocked_count' => (int) ($internalOperationsBackboneRow['external_actions_blocked_count'] ?? 0),
                    'activation_run_operations_runtime_coverage_rate' => (float) ($activationRunOperationsRow['coverage_rate'] ?? 0.0),
                    'activation_run_operations_external_actions_blocked_count' => (int) ($activationRunOperationsRow['external_actions_blocked_count'] ?? 0),
                    'domain_company_execution_suite_runtime_coverage_rate' => (float) ($domainCompanyExecutionSuiteRow['coverage_rate'] ?? 0.0),
                    'domain_company_execution_suite_external_actions_blocked_count' => (int) ($domainCompanyExecutionSuiteRow['external_actions_blocked_count'] ?? 0),
                    'flow_work_product_delivery_runtime_coverage_rate' => (float) ($flowWorkProductDeliveryRow['coverage_rate'] ?? 0.0),
                    'flow_work_product_external_delivery_blocked_count' => (int) ($flowWorkProductDeliveryRow['external_delivery_blocked_count'] ?? 0),
                ],
                'dossier_gate_snapshot' => [
                    'provider_workbench_ready' => (bool) ($dossierRow['provider_workbench_ready'] ?? false),
                    'agent_repository_adoption_ready' => (bool) ($dossierRow['agent_repository_adoption_ready'] ?? false),
                    'industry_solution_ecosystem_ready' => (bool) ($dossierRow['industry_solution_ecosystem_ready'] ?? false),
                    'business_operating_backbone_ready' => (bool) ($dossierRow['business_operating_backbone_ready'] ?? false),
                    'production_connector_preflight_ready' => (bool) ($dossierRow['production_connector_preflight_ready'] ?? false),
                    'flow_quality_research_ready' => (bool) ($dossierRow['flow_quality_research_ready'] ?? false),
                    'vertical_solution_suite_ready' => (bool) ($dossierRow['vertical_solution_suite_ready'] ?? false),
                    'domain_business_execution_mesh_ready' => (bool) ($dossierRow['domain_business_execution_mesh_ready'] ?? false),
                    'flow_operating_package_ready' => (bool) ($dossierRow['flow_operating_package_ready'] ?? false),
                    'company_command_center_ready' => (bool) ($dossierRow['company_command_center_ready'] ?? false),
                    'operational_dress_rehearsal_ready' => (bool) ($dossierRow['operational_dress_rehearsal_ready'] ?? false),
                    'manual_handoff_candidate_count' => (int) ($dossierRow['manual_handoff_candidate_count'] ?? 0),
                    'blocked_flow_count' => (int) ($dossierRow['blocked_flow_count'] ?? 0),
                ],
                'operational_stage' => $ready
                    ? 'enterprise_company_completion_certified_external_autonomy_still_blocked'
                    : 'enterprise_company_completion_attention_required',
                'missing_capabilities' => $missing,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'evidence_hashes' => [
                    'runtime_status_hash' => $runtime['runtime_status_hash'] ?? null,
                    'company_system_model_runtime_status_hash' => $companySystemModelRuntime['company_system_model_runtime_status_hash'] ?? null,
                    'internal_operations_backbone_runtime_status_hash' => $internalOperationsBackboneRuntime['internal_operations_backbone_runtime_status_hash'] ?? null,
                    'activation_run_operations_runtime_status_hash' => $activationRunOperationsRuntime['activation_run_operations_runtime_status_hash'] ?? null,
                    'domain_company_execution_suite_runtime_status_hash' => $domainCompanyExecutionSuiteRuntime['domain_company_execution_suite_runtime_status_hash'] ?? null,
                    'flow_work_product_delivery_runtime_status_hash' => $flowWorkProductDeliveryRuntime['flow_work_product_delivery_runtime_status_hash'] ?? null,
                    'company_board_operating_review_status_hash' => $boardReview['company_board_operating_review_status_hash'] ?? null,
                    'real_external_execution_readiness_dossier_hash' => $dossier['real_external_execution_readiness_dossier_hash'] ?? null,
                    'real_external_execution_handoff_pack_hash' => $handoffPack['real_external_execution_handoff_pack_hash'] ?? null,
                    'external_supervised_cutover_portfolio_readiness_status_hash' => $cutover['external_supervised_cutover_portfolio_readiness_status_hash'] ?? null,
                    'company_dossier_hash' => $dossierRow['company_dossier_hash'] ?? null,
                    'company_handoff_pack_hash' => $handoffRow['company_handoff_pack_hash'] ?? null,
                    'portfolio_readiness_record_hash' => $cutoverRow['portfolio_readiness_record_hash'] ?? null,
                ],
            ];
            $row['completion_certification_record_hash'] = MissionCanonicalHash::sha256($row);
            $companyRows[] = $row;
        }

        $certifiedCount = count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['completion_certified'] ?? false)));
        $payload = [
            'ok' => $companyRows !== [] && $certifiedCount === count($companyRows),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_COMPLETION_CERTIFICATION_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $certifiedCount === count($companyRows)
                ? 'enterprise_company_completion_certified_external_autonomy_still_blocked'
                : 'enterprise_company_completion_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'completion_certified_company_count' => $certifiedCount,
                'attention_company_count' => count($companyRows) - $certifiedCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companyRows)),
                'internal_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['internal_runtime_complete'] ?? false))),
                'company_system_model_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['company_system_model_runtime_complete'] ?? false))),
                'internal_operations_backbone_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['internal_operations_backbone_runtime_complete'] ?? false))),
                'activation_run_operations_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['activation_run_operations_runtime_complete'] ?? false))),
                'domain_company_execution_suite_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['domain_company_execution_suite_runtime_complete'] ?? false))),
                'flow_work_product_delivery_runtime_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['flow_work_product_delivery_runtime_complete'] ?? false))),
                'board_review_ready_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['board_operating_review_ready'] ?? false))),
                'dossier_ready_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['real_external_execution_dossier_ready'] ?? false))),
                'handoff_pack_ready_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['manual_handoff_pack_ready'] ?? false))),
                'supervised_cutover_complete_company_count' => count(array_filter($companyRows, static fn (array $company): bool => (bool) ($company['supervised_cutover_receipt_chain_complete'] ?? false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'runtime_status_hash' => $runtime['runtime_status_hash'] ?? null,
                'company_system_model_runtime_status_hash' => $companySystemModelRuntime['company_system_model_runtime_status_hash'] ?? null,
                'internal_operations_backbone_runtime_status_hash' => $internalOperationsBackboneRuntime['internal_operations_backbone_runtime_status_hash'] ?? null,
                'activation_run_operations_runtime_status_hash' => $activationRunOperationsRuntime['activation_run_operations_runtime_status_hash'] ?? null,
                'domain_company_execution_suite_runtime_status_hash' => $domainCompanyExecutionSuiteRuntime['domain_company_execution_suite_runtime_status_hash'] ?? null,
                'flow_work_product_delivery_runtime_status_hash' => $flowWorkProductDeliveryRuntime['flow_work_product_delivery_runtime_status_hash'] ?? null,
                'company_board_operating_review_status_hash' => $boardReview['company_board_operating_review_status_hash'] ?? null,
                'real_external_execution_readiness_dossier_hash' => $dossier['real_external_execution_readiness_dossier_hash'] ?? null,
                'real_external_execution_handoff_pack_hash' => $handoffPack['real_external_execution_handoff_pack_hash'] ?? null,
                'external_supervised_cutover_portfolio_readiness_status_hash' => $cutover['external_supervised_cutover_portfolio_readiness_status_hash'] ?? null,
            ],
            'companies' => $companyRows,
            'policy' => [
                'completion_certification_is_not_execution_authority' => true,
                'claim_company_complete_without_flow_receipts_blocked' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'required_evidence_chains' => [
                    'internal_flow_runtime_receipts',
                    'company_system_model_runtime_status',
                    'internal_operations_backbone_runtime_status',
                    'activation_run_operations_runtime_status',
                    'domain_company_execution_suite_runtime_status',
                    'flow_work_product_delivery_runtime_status',
                    'operational_outcome_ledgers',
                    'board_operating_reviews',
                    'real_external_execution_dossiers',
                    'manual_handoff_packs',
                    'supervised_cutover_work_orders',
                    'runtime_rehearsal_packets',
                    'manual_handoff_packets',
                    'manual_closeout_receipts',
                ],
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt', 'claim_company_complete_without_flow_receipts'],
            ],
        ];
        $payload['enterprise_company_completion_certification_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseHoldingCompletionAuditStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $completion = $this->enterpriseCompanyCompletionCertificationStatus($wantedCompany);
        $production = $this->hub->enterpriseProductionEvidence->enterpriseCompanyProductionReadinessCertificationStatus($wantedCompany);
        $evidenceBundle = $this->hub->enterpriseProductionEvidence->enterpriseCompanyOperatingEvidenceBundleStatus($wantedCompany);
        $verticalDepth = $this->enterpriseVerticalOperationalDepthStatus($wantedCompany);
        $activeOperatingSystem = $this->hub->enterpriseOperatingCycle->enterpriseCompanyActiveOperatingSystemStatus($wantedCompany);
        $capabilityCatalog = $this->hub->enterpriseOperatingCycle->enterpriseCompanyCapabilityCatalogStatus($wantedCompany);
        $integrationReadiness = $this->hub->enterpriseOperatingCycle->enterpriseCompanyIntegrationReadinessStatus($wantedCompany);
        $domainToolExecution = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainToolExecutionReadinessStatus($wantedCompany);
        $repositoryAdoption = $this->hub->companyCockpit->agentRepositoryAdoptionStatus($wantedCompany);
        $repositoryCatalog = $this->hub->companyCockpit->agentRepositoryOperatingCatalogStatus($wantedCompany);
        $toolchainCertification = $this->hub->companyOperatingStatus->domainAgentToolchainCertificationStatus($wantedCompany);
        $externalResearch = $this->hub->connectorReadiness->externalResearchAdoptionStatus($wantedCompany);
        $benchmarkReplay = $this->hub->connectorReadiness->flowBenchmarkReplayStatus($wantedCompany);
        $connectorCertification = $this->hub->connectorReadiness->connectorCertificationPreflightStatus($wantedCompany);
        $businessOperatingPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $portfolioCutover = $this->hub->cutoverCloseout->externalSupervisedCutoverPortfolioReadinessStatus($wantedCompany);
        $runtimeInvocation = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationStatus($wantedCompany);

        $companyTarget = (int) data_get($completion, 'summary.company_count', 0);
        $flowTarget = (int) data_get($completion, 'summary.expected_flow_count', 0);
        $connectorTarget = max(1, (int) data_get($connectorCertification, 'summary.adapter_contract_count', 0));
        $runtimeGateTarget = max(1, (int) data_get($businessOperatingPacket, 'summary.flow_count', $flowTarget));

        $gate = static function (
            string $id,
            string $requirement,
            int|float $current,
            int|float $target,
            ?string $sourceHash = null,
            array $evidence = [],
        ): array {
            $ready = $target > 0 && $current >= $target;

            return [
                'id' => $id,
                'requirement' => $requirement,
                'status' => $ready ? 'proven' : 'missing_or_incomplete',
                'current' => $current,
                'target' => $target,
                'source_hash' => $sourceHash,
                'evidence' => $evidence,
            ];
        };

        $requirementGates = [
            $gate(
                'all_target_companies_completion_certified',
                'all target companies have completion certification across internal runtime, board review, handoff and supervised cutover',
                (int) data_get($completion, 'summary.completion_certified_company_count', 0),
                $companyTarget,
                (string) data_get($completion, 'enterprise_company_completion_certification_status_hash'),
            ),
            $gate(
                'all_target_companies_production_ready',
                'all target companies have production readiness certification while external launch remains blocked',
                (int) data_get($production, 'summary.production_ready_company_count', 0),
                $companyTarget,
                (string) data_get($production, 'enterprise_company_production_readiness_certification_status_hash'),
                [
                    'required_gate_count' => (int) data_get($production, 'summary.required_gate_count', 0),
                    'ready_gate_count' => (int) data_get($production, 'summary.ready_gate_count', 0),
                    'first_missing_gates' => array_slice((array) data_get($production, 'companies.0.missing_gates', []), 0, 12),
                ],
            ),
            $gate(
                'all_target_companies_operating_evidence_bundled',
                'all target companies have operating evidence bundles with source hash lineage',
                (int) data_get($evidenceBundle, 'summary.operating_evidence_bundle_ready_company_count', 0),
                $companyTarget,
                (string) data_get($evidenceBundle, 'enterprise_company_operating_evidence_bundle_status_hash'),
                [
                    'required_gate_count' => (int) data_get($evidenceBundle, 'summary.required_gate_count', 0),
                    'ready_gate_count' => (int) data_get($evidenceBundle, 'summary.ready_gate_count', 0),
                    'source_hash_lineage_count' => (int) data_get($evidenceBundle, 'summary.source_hash_lineage_count', 0),
                    'first_missing_gates' => array_slice((array) data_get($evidenceBundle, 'companies.0.missing_gates', []), 0, 12),
                ],
            ),
            $gate(
                'all_target_companies_vertical_depth_ready',
                'all companies have vertical operational depth, operating cycles, packages, command center and runbook depth',
                (int) data_get($verticalDepth, 'summary.operational_depth_ready_company_count', 0),
                $companyTarget,
                (string) data_get($verticalDepth, 'enterprise_vertical_operational_depth_status_hash'),
            ),
            $gate(
                'all_target_companies_active_operating_system_ready',
                'all companies have active operating-system gates ready',
                (int) data_get($activeOperatingSystem, 'summary.active_operating_system_ready_company_count', 0),
                $companyTarget,
                (string) data_get($activeOperatingSystem, 'enterprise_company_active_operating_system_status_hash'),
            ),
            $gate(
                'all_target_companies_capability_catalog_ready',
                'all companies have capability catalogs mapped to robust company flows',
                (int) data_get($capabilityCatalog, 'summary.catalog_ready_company_count', 0),
                $companyTarget,
                (string) data_get($capabilityCatalog, 'enterprise_company_capability_catalog_status_hash'),
                [
                    'ready_family_count' => (int) data_get($capabilityCatalog, 'summary.ready_family_count', 0),
                    'ready_flow_capability_count' => (int) data_get($capabilityCatalog, 'summary.ready_flow_capability_count', 0),
                ],
            ),
            $gate(
                'all_target_companies_integration_ready',
                'all companies have enterprise integration readiness gates ready',
                (int) data_get($integrationReadiness, 'summary.integration_ready_company_count', 0),
                $companyTarget,
                (string) data_get($integrationReadiness, 'enterprise_company_integration_readiness_status_hash'),
            ),
            $gate(
                'all_target_companies_domain_tool_execution_ready',
                'all companies have domain tool execution readiness for flow-level internal operation',
                (int) data_get($domainToolExecution, 'summary.tool_execution_ready_company_count', 0),
                $companyTarget,
                (string) data_get($domainToolExecution, 'enterprise_company_domain_tool_execution_readiness_status_hash'),
                [
                    'ready_flow_tool_execution_count' => (int) data_get($domainToolExecution, 'summary.ready_flow_tool_execution_count', 0),
                    'flow_tool_execution_count' => (int) data_get($domainToolExecution, 'summary.flow_tool_execution_count', 0),
                ],
            ),
            $gate(
                'all_flows_have_business_operating_packets',
                'all flows have business operating packets with external commitments blocked',
                (int) data_get($businessOperatingPacket, 'summary.completed_business_operating_packet_flow_count', 0),
                $runtimeGateTarget,
                (string) data_get($businessOperatingPacket, 'business_operating_packet_runtime_status_hash'),
                [
                    'external_commitments_blocked_count' => (int) data_get($businessOperatingPacket, 'summary.external_commitments_blocked_count', 0),
                ],
            ),
            $gate(
                'all_flows_have_repository_adoption_controls',
                'all flows are mapped to researched agent repositories, permission manifests and replay recipes',
                (int) data_get($repositoryAdoption, 'summary.flow_repository_adoption_matrix_count', 0),
                $flowTarget,
                (string) data_get($repositoryAdoption, 'agent_repository_adoption_status_hash'),
                [
                    'ready_company_count' => (int) data_get($repositoryAdoption, 'summary.ready_company_count', 0),
                    'tool_permission_manifest_count' => (int) data_get($repositoryAdoption, 'summary.tool_permission_manifest_count', 0),
                    'eval_replay_recipe_count' => (int) data_get($repositoryAdoption, 'summary.eval_replay_recipe_count', 0),
                ],
            ),
            $gate(
                'agent_repository_operating_catalog_ready',
                'the operating catalog covers agent frameworks, MCP security profiles and flow mappings',
                (int) data_get($repositoryCatalog, 'summary.flow_runtime_map_count', 0),
                $flowTarget,
                (string) data_get($repositoryCatalog, 'agent_repository_operating_catalog_status_hash'),
                [
                    'ready_company_count' => (int) data_get($repositoryCatalog, 'summary.ready_company_count', 0),
                    'framework_profile_count' => (int) data_get($repositoryCatalog, 'summary.framework_profile_count', 0),
                    'mcp_security_profile_count' => (int) data_get($repositoryCatalog, 'summary.mcp_security_profile_count', 0),
                ],
            ),
            $gate(
                'domain_agent_toolchains_certified',
                'domain agent toolchains are certified for connectors, runtime boundaries and operator acceptance',
                (int) data_get($toolchainCertification, 'summary.certified_tool_contract_count', 0),
                $connectorTarget,
                (string) data_get($toolchainCertification, 'domain_agent_toolchain_certification_status_hash'),
                [
                    'ready_company_count' => (int) data_get($toolchainCertification, 'summary.ready_company_count', 0),
                ],
            ),
            $gate(
                'external_research_adoption_bound_to_all_flows',
                'external research and repository adoption is bound to every flow before promotion',
                (int) data_get($externalResearch, 'summary.ready_flow_adoption_matrix_count', 0),
                $flowTarget,
                (string) data_get($externalResearch, 'external_research_adoption_status_hash'),
                [
                    'source_basis_count' => (int) data_get($externalResearch, 'summary.source_basis_count', 0),
                    'framework_repository_count' => (int) data_get($externalResearch, 'summary.official_framework_repository_count', 0),
                    'domain_repository_count' => (int) data_get($externalResearch, 'summary.domain_repository_candidate_count', 0),
                ],
            ),
            $gate(
                'all_flows_have_benchmark_replay',
                'all flows have offline datasets, trace rubrics, adversarial cases and state assertions',
                (int) data_get($benchmarkReplay, 'summary.ready_offline_dataset_contract_count', 0),
                $flowTarget,
                (string) data_get($benchmarkReplay, 'flow_benchmark_replay_status_hash'),
                [
                    'trace_rubric_count' => (int) data_get($benchmarkReplay, 'summary.ready_trace_grading_rubric_count', 0),
                    'adversarial_case_count' => (int) data_get($benchmarkReplay, 'summary.ready_adversarial_regression_case_count', 0),
                    'state_assertion_count' => (int) data_get($benchmarkReplay, 'summary.ready_deterministic_state_assertion_count', 0),
                ],
            ),
            $gate(
                'connectors_have_certification_preflight',
                'connector certification covers adapter contracts, auth boundaries, sandbox probes and production cutover matrices',
                (int) data_get($connectorCertification, 'summary.adapter_contract_count', 0),
                $connectorTarget,
                (string) data_get($connectorCertification, 'connector_certification_preflight_status_hash'),
                [
                    'auth_boundary_count' => (int) data_get($connectorCertification, 'summary.auth_boundary_count', 0),
                    'sandbox_probe_count' => (int) data_get($connectorCertification, 'summary.sandbox_probe_count', 0),
                    'production_contract_count' => (int) data_get($connectorCertification, 'summary.production_contract_count', 0),
                ],
            ),
            $gate(
                'cutover_chain_complete_for_all_flows',
                'all flows have supervised cutover work order, runtime invocation, manual packet and closeout receipt chain',
                (int) data_get($portfolioCutover, 'summary.manual_closeout_receipt_count', 0),
                $flowTarget,
                (string) data_get($portfolioCutover, 'external_supervised_cutover_portfolio_readiness_status_hash'),
                [
                    'runtime_invocation_flow_count' => (int) data_get($portfolioCutover, 'summary.runtime_invocation_flow_count', 0),
                    'manual_handoff_packet_count' => (int) data_get($portfolioCutover, 'summary.manual_handoff_packet_count', 0),
                    'evidence_quality_ready_company_count' => (int) data_get($portfolioCutover, 'summary.evidence_quality_ready_company_count', 0),
                ],
            ),
            $gate(
                'repository_toolchain_evidence_bound_to_runtime_invocations',
                'runtime invocations carry repository/toolchain evidence for every flow',
                (int) data_get($runtimeInvocation, 'summary.repository_toolchain_evidence_bound_count', 0),
                $flowTarget,
                (string) data_get($runtimeInvocation, 'external_supervised_cutover_runtime_invocation_status_hash'),
                [
                    'runtime_invocation_count' => (int) data_get($runtimeInvocation, 'summary.runtime_invocation_count', 0),
                ],
            ),
        ];

        $provenCount = count(array_filter($requirementGates, static fn (array $gate): bool => ($gate['status'] ?? null) === 'proven'));
        $missing = array_values(array_map(
            static fn (array $gate): string => (string) $gate['id'],
            array_filter($requirementGates, static fn (array $gate): bool => ($gate['status'] ?? null) !== 'proven'),
        ));
        $externalExecutionAllowed = (int) data_get($completion, 'summary.external_execution_allowed_count', 0)
            + (int) data_get($production, 'summary.external_execution_allowed_count', 0)
            + (int) data_get($evidenceBundle, 'summary.external_execution_allowed_count', 0)
            + (int) data_get($portfolioCutover, 'summary.external_execution_allowed_count', 0)
            + (int) data_get($runtimeInvocation, 'summary.external_execution_allowed_count', 0);
        $externalSideEffectsEnabled = (int) data_get($completion, 'summary.external_side_effects_enabled_count', 0)
            + (int) data_get($production, 'summary.external_side_effects_enabled_count', 0)
            + (int) data_get($evidenceBundle, 'summary.external_side_effects_enabled_count', 0)
            + (int) data_get($portfolioCutover, 'summary.external_side_effects_enabled_count', 0)
            + (int) data_get($runtimeInvocation, 'summary.external_side_effects_enabled_count', 0);

        $ready = $requirementGates !== []
            && $provenCount === count($requirementGates)
            && $externalExecutionAllowed === 0
            && $externalSideEffectsEnabled === 0;
        $companyRows = array_map(
            static fn (array $company): array => [
                'schema' => 'atlas.ai.company.enterprise_holding_completion_audit_record.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'expected_flow_count' => (int) ($company['expected_flow_count'] ?? 0),
                'completion_certified' => (bool) ($company['completion_certified'] ?? false),
                'real_external_execution_dossier_ready' => (bool) ($company['real_external_execution_dossier_ready'] ?? false),
                'manual_handoff_pack_ready' => (bool) ($company['manual_handoff_pack_ready'] ?? false),
                'supervised_cutover_receipt_chain_complete' => (bool) ($company['supervised_cutover_receipt_chain_complete'] ?? false),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'completion_certification_record_hash' => $company['completion_certification_record_hash'] ?? null,
            ],
            (array) ($completion['companies'] ?? []),
        );

        $payload = [
            'ok' => $ready,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_HOLDING_COMPLETION_AUDIT_STATUS_SCHEMA,
            'status' => $ready
                ? 'enterprise_holding_completion_audit_green_external_autonomy_still_blocked'
                : 'enterprise_holding_completion_audit_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyTarget,
                'expected_flow_count' => $flowTarget,
                'requirement_gate_count' => count($requirementGates),
                'proven_requirement_gate_count' => $provenCount,
                'missing_requirement_gate_count' => count($missing),
                'completion_certified_company_count' => (int) data_get($completion, 'summary.completion_certified_company_count', 0),
                'production_ready_company_count' => (int) data_get($production, 'summary.production_ready_company_count', 0),
                'operating_evidence_bundle_ready_company_count' => (int) data_get($evidenceBundle, 'summary.operating_evidence_bundle_ready_company_count', 0),
                'repository_toolchain_evidence_bound_count' => (int) data_get($runtimeInvocation, 'summary.repository_toolchain_evidence_bound_count', 0),
                'manual_closeout_receipt_count' => (int) data_get($portfolioCutover, 'summary.manual_closeout_receipt_count', 0),
                'external_execution_allowed_count' => $externalExecutionAllowed,
                'external_side_effects_enabled_count' => $externalSideEffectsEnabled,
            ],
            'requirement_gates' => $requirementGates,
            'missing_requirement_gates' => $missing,
            'companies' => $companyRows,
            'source_hashes' => [
                'completion_certification' => $completion['enterprise_company_completion_certification_status_hash'] ?? null,
                'production_readiness' => $production['enterprise_company_production_readiness_certification_status_hash'] ?? null,
                'operating_evidence_bundle' => $evidenceBundle['enterprise_company_operating_evidence_bundle_status_hash'] ?? null,
                'vertical_operational_depth' => $verticalDepth['enterprise_vertical_operational_depth_status_hash'] ?? null,
                'active_operating_system' => $activeOperatingSystem['enterprise_company_active_operating_system_status_hash'] ?? null,
                'capability_catalog' => $capabilityCatalog['enterprise_company_capability_catalog_status_hash'] ?? null,
                'integration_readiness' => $integrationReadiness['enterprise_company_integration_readiness_status_hash'] ?? null,
                'domain_tool_execution' => $domainToolExecution['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
                'repository_adoption' => $repositoryAdoption['agent_repository_adoption_status_hash'] ?? null,
                'repository_catalog' => $repositoryCatalog['agent_repository_operating_catalog_status_hash'] ?? null,
                'toolchain_certification' => $toolchainCertification['domain_agent_toolchain_certification_status_hash'] ?? null,
                'external_research' => $externalResearch['external_research_adoption_status_hash'] ?? null,
                'benchmark_replay' => $benchmarkReplay['flow_benchmark_replay_status_hash'] ?? null,
                'connector_certification' => $connectorCertification['connector_certification_preflight_status_hash'] ?? null,
                'business_operating_packet' => $businessOperatingPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'portfolio_cutover' => $portfolioCutover['external_supervised_cutover_portfolio_readiness_status_hash'] ?? null,
                'runtime_invocation' => $runtimeInvocation['external_supervised_cutover_runtime_invocation_status_hash'] ?? null,
            ],
            'policy' => [
                'completion_audit_is_not_execution_authority' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_any_external_effect' => true,
                'claim_complete_without_requirement_gate_evidence_blocked' => true,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'spend_or_trade_without_signed_mandate', 'claim_external_result_without_receipt'],
            ],
        ];
        $payload['enterprise_holding_completion_audit_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseVerticalOperationalDepthStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $premium = $this->hub->companyCockpit->premiumActivationStatus($wantedCompany);
        $provider = $this->hub->companyCockpit->providerWorkbenchStatus($wantedCompany);
        $repository = $this->hub->companyCockpit->agentRepositoryAdoptionStatus($wantedCompany);
        $industry = $this->hub->companyOperatingStatus->industrySolutionEcosystemStatus($wantedCompany);
        $backbone = $this->hub->companyOperatingStatus->businessOperatingBackboneStatus($wantedCompany);
        $production = $this->hub->companyOperatingStatus->productionConnectorPreflightStatus($wantedCompany);
        $quality = $this->hub->companyOperatingStatus->flowQualityResearchStatus($wantedCompany);
        $vertical = $this->hub->companyOperatingStatus->verticalSolutionSuiteStatus($wantedCompany);
        $mesh = $this->hub->companyOperatingStatus->domainBusinessExecutionMeshStatus($wantedCompany);
        $packages = $this->hub->companyOperatingStatus->flowOperatingPackageStatus($wantedCompany);
        $commandCenter = $this->hub->companyOperatingStatus->companyCommandCenterStatus($wantedCompany);
        $rehearsal = $this->hub->companyOperatingStatus->operationalDressRehearsalStatus($wantedCompany);
        $connector = $this->hub->activationBacklog->connectorActivationStatus($wantedCompany);
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($wantedCompany);
        $queue = $this->hub->flowRunQueue->flowRunQueueStatus($wantedCompany);
        $runbooks = $this->hub->flowRunQueue->flowOperationsRunbookStatus($wantedCompany);

        $premiumByCompany = $this->hub->companyRowsById($premium);
        $providerByCompany = $this->hub->companyRowsById($provider);
        $repositoryByCompany = $this->hub->companyRowsById($repository);
        $industryByCompany = $this->hub->companyRowsById($industry);
        $backboneByCompany = $this->hub->companyRowsById($backbone);
        $productionByCompany = $this->hub->companyRowsById($production);
        $qualityByCompany = $this->hub->companyRowsById($quality);
        $verticalByCompany = $this->hub->companyRowsById($vertical);
        $meshByCompany = $this->hub->companyRowsById($mesh);
        $packageByCompany = $this->hub->companyRowsById($packages);
        $commandByCompany = $this->hub->companyRowsById($commandCenter);
        $rehearsalByCompany = $this->hub->companyRowsById($rehearsal);
        $connectorByCompany = $this->hub->companyRowsById($connector);
        $liveReadByCompany = $this->hub->companyRowsById($liveRead);
        $queueByCompany = $this->hub->companyRowsById($queue);
        $runbookByCompany = $this->hub->companyRowsById($runbooks);
        $queueRecordsByCompany = [];
        foreach ((array) ($queue['records'] ?? []) as $record) {
            $queueRecordsByCompany[(string) ($record['company_id'] ?? 'unknown')][] = (array) $record;
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $connectorCount = count((array) ($company['connectors'] ?? []));

            $premiumRow = (array) ($premiumByCompany[$id] ?? []);
            $providerRow = (array) ($providerByCompany[$id] ?? []);
            $repositoryRow = (array) ($repositoryByCompany[$id] ?? []);
            $industryRow = (array) ($industryByCompany[$id] ?? []);
            $backboneRow = (array) ($backboneByCompany[$id] ?? []);
            $productionRow = (array) ($productionByCompany[$id] ?? []);
            $qualityRow = (array) ($qualityByCompany[$id] ?? []);
            $verticalRow = (array) ($verticalByCompany[$id] ?? []);
            $meshRow = (array) ($meshByCompany[$id] ?? []);
            $packageRow = (array) ($packageByCompany[$id] ?? []);
            $commandRow = (array) ($commandByCompany[$id] ?? []);
            $rehearsalRow = (array) ($rehearsalByCompany[$id] ?? []);
            $connectorRow = (array) ($connectorByCompany[$id] ?? []);
            $liveReadRow = (array) ($liveReadByCompany[$id] ?? []);
            $queueRow = (array) ($queueByCompany[$id] ?? []);
            $runbookRow = (array) ($runbookByCompany[$id] ?? []);
            $queueRecords = array_values((array) ($queueRecordsByCompany[$id] ?? []));
            $queueAttemptedFlowCount = count(array_unique(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                array_filter($queueRecords, static fn (array $record): bool => (int) ($record['execution_receipt_count'] ?? 0) > 0),
            )));
            $queueConnectorFailureCount = count(array_filter(
                $queueRecords,
                static fn (array $record): bool => ($record['dlq_reason'] ?? null) === 'connector_activation_probe_not_green',
            ));

            $gates = [
                'premium_activation' => (bool) data_get($premiumRow, 'premium_readiness.ready', false),
                'provider_workbench' => (bool) ($providerRow['ready'] ?? false),
                'agent_repository_adoption' => (bool) ($repositoryRow['ready'] ?? false),
                'industry_solution_ecosystem' => (bool) ($industryRow['ready'] ?? false),
                'business_operating_backbone' => (bool) ($backboneRow['ready'] ?? false),
                'production_connector_preflight' => (bool) ($productionRow['ready'] ?? false),
                'flow_quality_research' => (bool) ($qualityRow['ready'] ?? false),
                'vertical_solution_suite' => (bool) ($verticalRow['ready'] ?? false),
                'domain_business_execution_mesh' => (bool) ($meshRow['ready'] ?? false),
                'flow_operating_package' => (bool) ($packageRow['ready'] ?? false),
                'company_command_center' => (bool) ($commandRow['ready'] ?? false),
                'operational_dress_rehearsal' => (bool) ($rehearsalRow['ready'] ?? false),
                'connector_activation_probe' => (int) ($connectorRow['activated_internal_count'] ?? 0) > 0
                    && (int) ($connectorRow['connector_activation_count'] ?? 0) >= max(1, $connectorCount),
                'live_read_connector_readiness' => (int) ($liveReadRow['connector_readiness_count'] ?? 0) > 0
                    && (int) ($liveReadRow['live_read_ready_count'] ?? 0) === (int) ($liveReadRow['connector_readiness_count'] ?? -1),
                'flow_run_queue_execution' => $queueAttemptedFlowCount >= $expectedFlowCount
                    && $queueConnectorFailureCount === 0,
                'flow_operations_runbook_drill' => (int) ($runbookRow['operations_green_count'] ?? 0) >= $expectedFlowCount,
            ];

            $readyGateCount = count(array_filter($gates));
            $gateCount = count($gates);
            $missing = array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready)));

            $solutionDepth = $this->hub->depthAxisReady($gates, [
                'provider_workbench',
                'agent_repository_adoption',
                'industry_solution_ecosystem',
                'vertical_solution_suite',
                'domain_business_execution_mesh',
            ]);
            $operatingDepth = $this->hub->depthAxisReady($gates, [
                'business_operating_backbone',
                'flow_operating_package',
                'company_command_center',
                'operational_dress_rehearsal',
                'flow_operations_runbook_drill',
            ]);
            $productionDepth = $this->hub->depthAxisReady($gates, [
                'production_connector_preflight',
                'connector_activation_probe',
                'live_read_connector_readiness',
                'flow_run_queue_execution',
            ]);
            $qualityGovernanceDepth = $this->hub->depthAxisReady($gates, [
                'premium_activation',
                'flow_quality_research',
            ]);

            $row = [
                'schema' => 'atlas.ai.company.enterprise_vertical_operational_depth_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'connector_count' => $connectorCount,
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => $gateCount,
                'depth_score' => $gateCount > 0 ? round($readyGateCount / $gateCount, 4) : 0.0,
                'depth_grade' => $readyGateCount === $gateCount
                    ? 'target_9_internal_operating_depth_external_autonomy_blocked'
                    : 'operational_depth_attention_required',
                'depth_axes' => [
                    'solution_depth_ready' => $solutionDepth,
                    'operating_depth_ready' => $operatingDepth,
                    'production_depth_ready' => $productionDepth,
                    'quality_governance_depth_ready' => $qualityGovernanceDepth,
                ],
                'gates' => $gates,
                'missing_gates' => $missing,
                'flow_artifact_counts' => [
                    'flow_solution_kit_count' => (int) ($verticalRow['flow_solution_kit_count'] ?? 0),
                    'flow_package_count' => (int) ($packageRow['flow_package_count'] ?? 0),
                    'flow_execution_cell_count' => (int) ($meshRow['execution_cell_count'] ?? 0),
                    'flow_command_card_count' => (int) ($commandRow['flow_command_card_count'] ?? 0),
                    'flow_rehearsal_runbook_count' => (int) ($rehearsalRow['flow_rehearsal_runbook_count'] ?? 0),
                    'flow_queue_attempted_count' => $queueAttemptedFlowCount,
                    'flow_queue_completed_count' => (int) ($queueRow['completed_count'] ?? 0),
                    'flow_queue_governance_blocked_count' => count(array_filter(
                        $queueRecords,
                        static fn (array $record): bool => ($record['dlq_reason'] ?? null) === 'blocked_work_packages_require_operator_or_owner_resolution',
                    )),
                    'operations_green_count' => (int) ($runbookRow['operations_green_count'] ?? 0),
                ],
                'tooling_counts' => [
                    'provider_contract_count' => (int) ($providerRow['provider_contract_count'] ?? 0),
                    'repository_intake_count' => (int) ($repositoryRow['repository_intake_count'] ?? 0),
                    'connector_workbench_count' => (int) ($verticalRow['connector_workbench_count'] ?? 0),
                    'tooling_benchmark_count' => (int) ($qualityRow['tooling_benchmark_count'] ?? 0),
                    'connector_activation_count' => (int) ($connectorRow['connector_activation_count'] ?? 0),
                    'live_read_ready_count' => (int) ($liveReadRow['live_read_ready_count'] ?? 0),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['vertical_operational_depth_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => ($company['ready_gate_count'] ?? 0) === ($company['required_gate_count'] ?? -1)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_VERTICAL_OPERATIONAL_DEPTH_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_vertical_operational_depth_ready_external_autonomy_still_blocked'
                : 'enterprise_vertical_operational_depth_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'operational_depth_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_depth_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['depth_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'solution_depth_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'depth_axes.solution_depth_ready', false))),
                'operating_depth_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'depth_axes.operating_depth_ready', false))),
                'production_depth_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'depth_axes.production_depth_ready', false))),
                'quality_governance_depth_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'depth_axes.quality_governance_depth_ready', false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'premium_activation_status_hash' => $premium['premium_activation_status_hash'] ?? null,
                'provider_workbench_status_hash' => $provider['provider_workbench_status_hash'] ?? null,
                'agent_repository_adoption_status_hash' => $repository['agent_repository_adoption_status_hash'] ?? null,
                'industry_solution_ecosystem_status_hash' => $industry['industry_solution_ecosystem_status_hash'] ?? null,
                'business_operating_backbone_status_hash' => $backbone['business_operating_backbone_status_hash'] ?? null,
                'production_connector_preflight_status_hash' => $production['production_connector_preflight_status_hash'] ?? null,
                'flow_quality_research_status_hash' => $quality['flow_quality_research_status_hash'] ?? null,
                'vertical_solution_suite_status_hash' => $vertical['vertical_solution_suite_status_hash'] ?? null,
                'domain_business_execution_mesh_status_hash' => $mesh['domain_business_execution_mesh_status_hash'] ?? null,
                'flow_operating_package_status_hash' => $packages['flow_operating_package_status_hash'] ?? null,
                'company_command_center_status_hash' => $commandCenter['company_command_center_status_hash'] ?? null,
                'operational_dress_rehearsal_status_hash' => $rehearsal['operational_dress_rehearsal_status_hash'] ?? null,
                'connector_activation_status_hash' => $connector['connector_activation_status_hash'] ?? null,
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
                'flow_run_queue_status_hash' => $queue['flow_run_queue_status_hash'] ?? null,
                'flow_operations_runbook_status_hash' => $runbooks['flow_operations_runbook_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'vertical_operational_depth_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'claim_enterprise_company_without_depth_gates_blocked' => true,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['auto_launch', 'external_write_without_decision_receipt', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_security', 'secret_export'],
            ],
        ];
        $payload['enterprise_vertical_operational_depth_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperationalExecutionLoopStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $activationBacklog = $this->hub->activationBacklog->activationBacklogStatus($wantedCompany);
        $connectorActivation = $this->hub->activationBacklog->connectorActivationStatus($wantedCompany);
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($wantedCompany);
        $flowQueue = $this->hub->flowRunQueue->flowRunQueueStatus($wantedCompany);
        $runbooks = $this->hub->flowRunQueue->flowOperationsRunbookStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $operationalOutcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);

        $activationByCompany = $this->hub->companyRowsById($activationBacklog);
        $connectorByCompany = $this->hub->companyRowsById($connectorActivation);
        $liveReadByCompany = $this->hub->companyRowsById($liveRead);
        $queueByCompany = $this->hub->companyRowsById($flowQueue);
        $runbookByCompany = $this->hub->companyRowsById($runbooks);
        $businessPacketByCompany = $this->hub->companyRowsById($businessPacket);
        $operationalOutcomeByCompany = $this->hub->companyRowsById($operationalOutcome);
        $queueRecordCountsByCompany = [];
        foreach ((array) ($flowQueue['records'] ?? []) as $record) {
            $recordCompanyId = (string) ($record['company_id'] ?? 'unknown');
            $lastQueueReceipt = (array) ($record['last_queue_receipt'] ?? []);
            $lastExecutionReceipt = (array) ($record['last_execution_receipt'] ?? []);
            $durableEnvelopeHash = (string) ($lastQueueReceipt['durable_execution_envelope_hash'] ?? $lastExecutionReceipt['durable_execution_envelope_hash'] ?? '');
            $queueRecordCountsByCompany[$recordCompanyId]['operating_package_bound_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['operating_package_bound_count'] ?? 0) + ((string) ($record['operating_package_hash'] ?? '') !== '' ? 1 : 0);
            $queueRecordCountsByCompany[$recordCompanyId]['durable_execution_envelope_bound_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['durable_execution_envelope_bound_count'] ?? 0) + (strlen($durableEnvelopeHash) === 64 ? 1 : 0);
            $queueRecordCountsByCompany[$recordCompanyId]['checkpoint_resume_bound_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['checkpoint_resume_bound_count'] ?? 0) + ((bool) ($lastQueueReceipt['checkpoint_resume_bound'] ?? $lastExecutionReceipt['checkpoint_resume_bound'] ?? false) ? 1 : 0);
            $queueRecordCountsByCompany[$recordCompanyId]['human_in_loop_bound_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['human_in_loop_bound_count'] ?? 0) + ((bool) ($lastQueueReceipt['human_in_loop_bound'] ?? $lastExecutionReceipt['human_in_loop_bound'] ?? false) ? 1 : 0);
            $queueRecordCountsByCompany[$recordCompanyId]['trace_receipt_bound_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['trace_receipt_bound_count'] ?? 0) + ((bool) ($lastQueueReceipt['trace_receipt_bound'] ?? $lastExecutionReceipt['trace_receipt_bound'] ?? false) ? 1 : 0);
            $queueRecordCountsByCompany[$recordCompanyId]['external_side_effects_enabled_count'] = (int) ($queueRecordCountsByCompany[$recordCompanyId]['external_side_effects_enabled_count'] ?? 0) + ((bool) ($record['external_side_effects_enabled'] ?? false) ? 1 : 0);
        }
        $runbookRecordCountsByCompany = [];
        foreach ((array) ($runbooks['records'] ?? []) as $record) {
            $recordCompanyId = (string) ($record['company_id'] ?? 'unknown');
            $runbookRecordCountsByCompany[$recordCompanyId]['operating_package_bound_count'] = (int) ($runbookRecordCountsByCompany[$recordCompanyId]['operating_package_bound_count'] ?? 0) + ((string) ($record['operating_package_hash'] ?? '') !== '' ? 1 : 0);
            $runbookRecordCountsByCompany[$recordCompanyId]['replay_contract_bound_count'] = (int) ($runbookRecordCountsByCompany[$recordCompanyId]['replay_contract_bound_count'] ?? 0) + ((bool) ($record['replay_contract_bound'] ?? false) ? 1 : 0);
            $runbookRecordCountsByCompany[$recordCompanyId]['external_side_effects_enabled_count'] = (int) ($runbookRecordCountsByCompany[$recordCompanyId]['external_side_effects_enabled_count'] ?? 0) + ((bool) ($record['external_side_effects_enabled'] ?? false) ? 1 : 0);
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $connectorCount = count((array) ($company['connectors'] ?? []));

            $activationRow = (array) ($activationByCompany[$id] ?? []);
            $connectorRow = (array) ($connectorByCompany[$id] ?? []);
            $liveReadRow = (array) ($liveReadByCompany[$id] ?? []);
            $queueRow = (array) ($queueByCompany[$id] ?? []);
            $runbookRow = (array) ($runbookByCompany[$id] ?? []);
            $queueRecordCounts = (array) ($queueRecordCountsByCompany[$id] ?? []);
            $runbookRecordCounts = (array) ($runbookRecordCountsByCompany[$id] ?? []);
            $businessPacketRow = (array) ($businessPacketByCompany[$id] ?? []);
            $operationalOutcomeRow = (array) ($operationalOutcomeByCompany[$id] ?? []);

            $queueFlowCount = (int) ($queueRow['flow_count'] ?? 0);
            $queueCompletedCount = (int) ($queueRow['completed_count'] ?? 0);
            $queueDlqCount = (int) ($queueRow['dlq_count'] ?? 0);
            $queueAttemptedCount = $queueCompletedCount + $queueDlqCount;
            $structuralBusinessPacketReady = count((array) data_get($company, 'enterprise_flow_operating_packages.flow_packages', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'business_process_map', [])) >= $expectedFlowCount;
            $structuralOperationalOutcomeReady = count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $expectedFlowCount;

            $gates = [
                'activation_backlog_bound' => $expectedFlowCount > 0
                    && (int) ($activationRow['flow_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($activationRow['work_package_count'] ?? 0) >= $expectedFlowCount,
                'connector_activation_green' => $connectorCount > 0
                    && (int) ($connectorRow['connector_activation_count'] ?? 0) >= $connectorCount
                    && (int) ($connectorRow['activated_internal_count'] ?? 0) >= $connectorCount,
                'live_read_connector_ready' => $connectorCount > 0
                    && (int) ($liveReadRow['connector_readiness_count'] ?? 0) >= $connectorCount
                    && (int) ($liveReadRow['live_read_ready_count'] ?? 0) === (int) ($liveReadRow['connector_readiness_count'] ?? -1),
                'flow_run_queue_durable' => $expectedFlowCount > 0
                    && $queueFlowCount >= $expectedFlowCount
                    && (int) ($queueRecordCounts['durable_execution_envelope_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($queueRecordCounts['checkpoint_resume_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($queueRecordCounts['human_in_loop_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($queueRecordCounts['trace_receipt_bound_count'] ?? 0) >= $expectedFlowCount,
                'flow_queue_execution_accounted' => $expectedFlowCount > 0
                    && ($queueAttemptedCount >= $expectedFlowCount || (int) ($queueRecordCounts['operating_package_bound_count'] ?? 0) >= $expectedFlowCount),
                'flow_operations_runbooks_green' => $expectedFlowCount > 0
                    && (int) ($runbookRow['operations_green_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($runbookRecordCounts['operating_package_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($runbookRecordCounts['replay_contract_bound_count'] ?? 0) >= $expectedFlowCount,
                'business_operating_packet_runtime_ready' => $this->hub->runtimeCoverageRowReady($businessPacketRow, 'completed_business_operating_packet_flow_count')
                    || $structuralBusinessPacketReady,
                'operational_outcome_runtime_ready' => $this->hub->runtimeCoverageRowReady($operationalOutcomeRow, 'completed_operational_outcome_flow_count')
                    || $structuralOperationalOutcomeReady,
                'external_effects_blocked' => (int) ($queueRow['external_execution_allowed_count'] ?? 0) === 0
                    && (int) ($queueRecordCounts['external_side_effects_enabled_count'] ?? 0) === 0
                    && (int) ($runbookRow['external_execution_allowed_count'] ?? 0) === 0
                    && (int) ($runbookRecordCounts['external_side_effects_enabled_count'] ?? 0) === 0
                    && ! (bool) ($businessPacketRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($operationalOutcomeRow['external_execution_allowed'] ?? false),
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operational_execution_loop_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'connector_count' => $connectorCount,
                'operational_execution_loop_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'loop_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'loop_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_company_operational_execution_loop_ready_external_effects_blocked'
                    : 'company_operational_execution_loop_attention_required',
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'loop_counts' => [
                    'activation_flow_count' => (int) ($activationRow['flow_count'] ?? 0),
                    'activation_work_package_count' => (int) ($activationRow['work_package_count'] ?? 0),
                    'connector_activation_count' => (int) ($connectorRow['connector_activation_count'] ?? 0),
                    'activated_internal_count' => (int) ($connectorRow['activated_internal_count'] ?? 0),
                    'live_read_ready_count' => (int) ($liveReadRow['live_read_ready_count'] ?? 0),
                    'queue_flow_count' => $queueFlowCount,
                    'queue_completed_count' => $queueCompletedCount,
                    'queue_dlq_count' => $queueDlqCount,
                    'queue_attempted_count' => $queueAttemptedCount,
                    'runbook_operations_green_count' => (int) ($runbookRow['operations_green_count'] ?? 0),
                    'business_packet_completed_count' => (int) ($businessPacketRow['completed_business_operating_packet_flow_count'] ?? 0),
                    'operational_outcome_completed_count' => (int) ($operationalOutcomeRow['completed_operational_outcome_flow_count'] ?? 0),
                ],
                'source_hashes' => [
                    'activation_backlog_status_hash' => $activationBacklog['activation_backlog_status_hash'] ?? null,
                    'connector_activation_status_hash' => $connectorActivation['connector_activation_status_hash'] ?? null,
                    'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
                    'flow_run_queue_status_hash' => $flowQueue['flow_run_queue_status_hash'] ?? null,
                    'flow_operations_runbook_status_hash' => $runbooks['flow_operations_runbook_status_hash'] ?? null,
                    'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                    'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_operational_execution_loop_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['operational_execution_loop_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATIONAL_EXECUTION_LOOP_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operational_execution_loops_ready_external_effects_blocked'
                : 'enterprise_company_operational_execution_loop_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'operational_execution_loop_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_loop_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['loop_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'queue_attempted_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'loop_counts.queue_attempted_count', 0), $companies)),
                'runbook_operations_green_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'loop_counts.runbook_operations_green_count', 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'activation_backlog_status_hash' => $activationBacklog['activation_backlog_status_hash'] ?? null,
                'connector_activation_status_hash' => $connectorActivation['connector_activation_status_hash'] ?? null,
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
                'flow_run_queue_status_hash' => $flowQueue['flow_run_queue_status_hash'] ?? null,
                'flow_operations_runbook_status_hash' => $runbooks['flow_operations_runbook_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'operational_execution_loop_is_not_external_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'required_loop_segments' => ['activation_backlog', 'connector_activation', 'live_read_readiness', 'flow_run_queue', 'operations_runbooks', 'business_operating_packet_runtime', 'operational_outcome_runtime'],
                'blocked_operations' => ['auto_launch', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_operational_execution_loop_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyWorkProductAcceptanceEvidenceStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $deliveryRuntime = $this->hub->flowActionRuntime->flowWorkProductDeliveryRuntimeStatus($wantedCompany);
        $qualityResearch = $this->hub->companyOperatingStatus->flowQualityResearchStatus($wantedCompany);
        $commandCenter = $this->hub->companyOperatingStatus->companyCommandCenterStatus($wantedCompany);
        $domainOperatingModel = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainOperatingModelCertificationStatus($wantedCompany);
        $operationalLoop = $this->enterpriseCompanyOperationalExecutionLoopStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $operationalOutcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);

        $deliveryByCompany = $this->hub->companyRowsById($deliveryRuntime);
        $qualityByCompany = $this->hub->companyRowsById($qualityResearch);
        $commandByCompany = $this->hub->companyRowsById($commandCenter);
        $domainModelByCompany = $this->hub->companyRowsById($domainOperatingModel);
        $operationalLoopByCompany = $this->hub->companyRowsById($operationalLoop);
        $businessPacketByCompany = $this->hub->companyRowsById($businessPacket);
        $operationalOutcomeByCompany = $this->hub->companyRowsById($operationalOutcome);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $workProductCount = count((array) ($company['work_products'] ?? []));
            $metricCount = count((array) ($company['metrics'] ?? []));

            $deliveryRow = (array) ($deliveryByCompany[$id] ?? []);
            $qualityRow = (array) ($qualityByCompany[$id] ?? []);
            $commandRow = (array) ($commandByCompany[$id] ?? []);
            $domainModelRow = (array) ($domainModelByCompany[$id] ?? []);
            $operationalLoopRow = (array) ($operationalLoopByCompany[$id] ?? []);
            $businessPacketRow = (array) ($businessPacketByCompany[$id] ?? []);
            $operationalOutcomeRow = (array) ($operationalOutcomeByCompany[$id] ?? []);

            $structuralDeliveryReady = data_get($company, 'enterprise_flow_work_product_delivery_stack.schema') === 'atlas.ai.company.enterprise_flow_work_product_delivery_stack.v1'
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= $workProductCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])) >= ($metricCount + 6)
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true) === false
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.real_customer_send_allowed', true) === false
                && (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.operator_acceptance_required_before_external_handoff', false);
            $structuralBusinessPacketReady = count((array) data_get($company, 'enterprise_flow_operating_packages.flow_packages', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'business_process_map', [])) >= $expectedFlowCount;
            $structuralOperationalOutcomeReady = count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $expectedFlowCount;

            $gates = [
                'work_product_delivery_runtime_complete' => ($this->hub->runtimeCoverageRowReady($deliveryRow, 'completed_flow_work_product_delivery_count')
                    && (int) ($deliveryRow['acceptance_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($deliveryRow['handoff_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($deliveryRow['replay_check_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($deliveryRow['external_delivery_blocked_count'] ?? 0) >= $expectedFlowCount)
                    || $structuralDeliveryReady,
                'structural_delivery_contracts_complete' => $structuralDeliveryReady,
                'flow_quality_research_ready' => (bool) ($qualityRow['ready'] ?? false)
                    && (int) ($qualityRow['offline_dataset_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($qualityRow['trace_rubric_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($qualityRow['adversarial_case_count'] ?? 0) >= $expectedFlowCount,
                'command_center_work_product_factory_ready' => (bool) ($commandRow['ready'] ?? false)
                    && (int) ($commandRow['flow_command_card_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($commandRow['work_product_factory_count'] ?? 0) >= $expectedFlowCount
                    && (bool) data_get($commandRow, 'checks.work_product_factory_green', false),
                'domain_operating_model_acceptance_bound' => (bool) ($domainModelRow['domain_operating_model_certified'] ?? false)
                    && (int) data_get($domainModelRow, 'coverage_counts.workload_agent_template_count', 0) >= $expectedFlowCount
                    && (int) data_get($domainModelRow, 'coverage_counts.live_read_probe_contract_count', 0) >= $expectedFlowCount,
                'operational_loop_ready' => (bool) ($operationalLoopRow['operational_execution_loop_ready'] ?? false),
                'business_packet_delivery_contract_ready' => ($this->hub->runtimeCoverageRowReady($businessPacketRow, 'completed_business_operating_packet_flow_count')
                    && (int) ($businessPacketRow['delivery_lane_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($businessPacketRow['external_commitments_blocked_count'] ?? 0) >= $expectedFlowCount)
                    || $structuralBusinessPacketReady,
                'operational_outcome_acceptance_ready' => $this->hub->runtimeCoverageRowReady($operationalOutcomeRow, 'completed_operational_outcome_flow_count')
                    && (int) ($operationalOutcomeRow['operational_outcome_ledger_bound_count'] ?? 0) >= $expectedFlowCount
                    || $structuralOperationalOutcomeReady,
                'external_delivery_blocked' => (int) data_get($deliveryRuntime, 'summary.external_side_effect_count', 1) === 0
                    && ! (bool) ($deliveryRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($qualityRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($commandRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($businessPacketRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($operationalOutcomeRow['external_execution_allowed'] ?? false),
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_work_product_acceptance_evidence_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'work_product_count' => $workProductCount,
                'work_product_acceptance_evidence_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'acceptance_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'acceptance_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_work_product_acceptance_evidence_ready_external_delivery_blocked'
                    : 'work_product_acceptance_evidence_attention_required',
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'evidence_counts' => [
                    'work_product_catalog_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])),
                    'delivery_blueprint_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', [])),
                    'acceptance_contract_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', [])),
                    'handoff_packet_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', [])),
                    'replay_artifact_check_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])),
                    'completed_delivery_flow_count' => (int) ($deliveryRow['completed_flow_work_product_delivery_count'] ?? 0),
                    'runtime_acceptance_bound_count' => (int) ($deliveryRow['acceptance_bound_count'] ?? 0),
                    'runtime_handoff_bound_count' => (int) ($deliveryRow['handoff_bound_count'] ?? 0),
                    'runtime_replay_check_bound_count' => (int) ($deliveryRow['replay_check_bound_count'] ?? 0),
                    'quality_offline_dataset_count' => (int) ($qualityRow['offline_dataset_count'] ?? 0),
                    'quality_trace_rubric_count' => (int) ($qualityRow['trace_rubric_count'] ?? 0),
                    'quality_adversarial_case_count' => (int) ($qualityRow['adversarial_case_count'] ?? 0),
                ],
                'source_hashes' => [
                    'flow_work_product_delivery_runtime_status_hash' => $deliveryRuntime['flow_work_product_delivery_runtime_status_hash'] ?? null,
                    'flow_quality_research_status_hash' => $qualityResearch['flow_quality_research_status_hash'] ?? null,
                    'company_command_center_status_hash' => $commandCenter['company_command_center_status_hash'] ?? null,
                    'company_domain_operating_model_certification_record_hash' => $domainModelRow['company_domain_operating_model_certification_record_hash'] ?? null,
                    'company_operational_execution_loop_record_hash' => $operationalLoopRow['company_operational_execution_loop_record_hash'] ?? null,
                    'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                    'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
                    'delivery_stack_hash' => data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash'),
                ],
                'external_delivery_allowed' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_work_product_acceptance_evidence_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['work_product_acceptance_evidence_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_WORK_PRODUCT_ACCEPTANCE_EVIDENCE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_work_product_acceptance_evidence_ready_external_delivery_blocked'
                : 'enterprise_company_work_product_acceptance_evidence_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'work_product_acceptance_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'work_product_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['work_product_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_acceptance_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['acceptance_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'evidence_counts.acceptance_contract_count', 0), $companies)),
                'handoff_packet_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'evidence_counts.handoff_packet_count', 0), $companies)),
                'replay_artifact_check_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'evidence_counts.replay_artifact_check_count', 0), $companies)),
                'external_delivery_allowed_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'flow_work_product_delivery_runtime_status_hash' => $deliveryRuntime['flow_work_product_delivery_runtime_status_hash'] ?? null,
                'flow_quality_research_status_hash' => $qualityResearch['flow_quality_research_status_hash'] ?? null,
                'company_command_center_status_hash' => $commandCenter['company_command_center_status_hash'] ?? null,
                'enterprise_company_domain_operating_model_certification_status_hash' => $domainOperatingModel['enterprise_company_domain_operating_model_certification_status_hash'] ?? null,
                'enterprise_company_operational_execution_loop_status_hash' => $operationalLoop['enterprise_company_operational_execution_loop_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'work_product_acceptance_is_not_external_delivery_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_delivery_allowed' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_acceptance_required_before_external_handoff' => true,
                'required_evidence' => ['catalog', 'delivery_blueprint', 'acceptance_contract', 'handoff_packet', 'replay_artifact_check', 'quality_research', 'business_packet', 'operational_outcome'],
                'blocked_operations' => ['auto_send_to_customer', 'external_publish', 'unsupported_claim', 'skip_operator_acceptance', 'skip_source_lineage', 'spend', 'trade', 'deploy', 'delete'],
            ],
        ];
        $payload['enterprise_company_work_product_acceptance_evidence_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
