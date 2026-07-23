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
class EnterpriseToolExecutionSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyDomainOperatingModelCertificationStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $domainSolutionPackStatus = $this->hub->enterpriseAgentWorkforce->enterpriseCompanyDomainSolutionPackStatus($wantedCompany);
        $domainSolutionPackByCompany = $this->hub->companyRowsById($domainSolutionPackStatus);
        $companies = [];

        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $domainSolutionPackRow = (array) ($domainSolutionPackByCompany[$id] ?? []);
            $flows = array_values((array) ($company['flows'] ?? []));
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count($flows));
            $agentRoles = array_values((array) ($company['agent_roles'] ?? []));
            $connectors = array_values((array) ($company['connectors'] ?? []));
            $workProducts = array_values((array) ($company['work_products'] ?? []));
            $metrics = array_values((array) ($company['metrics'] ?? []));
            $cadences = array_values((array) ($company['cadences'] ?? []));
            $flowContracts = array_values((array) ($company['flow_execution_contracts'] ?? []));
            $flowPlaybooks = array_values((array) ($company['flow_playbooks'] ?? []));
            $runtimeBlueprints = array_values((array) ($company['flow_runtime_blueprints'] ?? []));
            $agentRegistry = array_values((array) ($company['enterprise_agent_registry'] ?? []));
            $toolkits = array_values((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.agent_toolkit_profiles', []));
            $flowToolkitAssignments = array_values((array) data_get($company, 'enterprise_domain_agent_toolkit_stack.flow_toolkit_assignments', []));
            $workloadAgentTemplates = array_values((array) data_get($company, 'enterprise_domain_workload_agent_template_stack.workload_agent_templates', []));
            $workloadTemplateCoverage = array_values((array) data_get($company, 'enterprise_domain_workload_agent_template_stack.flow_template_coverage_matrix', []));
            $externalResearchSources = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.source_basis', []));
            $officialFrameworks = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.official_framework_repositories', []));
            $domainRepositories = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.domain_repository_candidates', []));
            $flowAdoptionMatrix = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.per_flow_adoption_matrix', []));
            $domainSolutionPlaybooks = array_values((array) data_get($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', []));
            $verticalSolutionKits = array_values((array) data_get($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', []));
            $businessExecutionCells = array_values((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []));
            $providerRoutes = array_values((array) data_get($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', []));
            $dataConnectorContracts = array_values((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', []));
            $liveReadProbeContracts = array_values((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', []));
            $qualityContracts = array_values((array) ($company['deliverable_quality_contracts'] ?? []));
            $processes = array_values((array) ($company['business_process_map'] ?? []));
            $domainDataEntities = array_values((array) data_get($company, 'domain_data_model.entities', []));
            $productLines = array_values((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', []));
            $grcControls = array_values((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', []));
            $industryProviders = array_values((array) data_get($company, 'enterprise_industry_solution_ecosystem_stack.ecosystem_provider_catalog', []));
            $operatingPackages = array_values((array) data_get($company, 'enterprise_flow_operating_packages.flow_packages', []));
            $operatingSystemRunbooks = array_values((array) data_get($company, 'enterprise_operating_system.runbooks', []));

            $gates = [
                'domain_blueprint_has_enterprise_company_primitives' => $expectedFlowCount >= 5
                    && count($agentRoles) >= 4
                    && count($connectors) >= 5
                    && count($workProducts) >= 5
                    && count($metrics) >= 4
                    && count($cadences) >= 3,
                'flow_contracts_playbooks_and_runtime_blueprints_cover_domain' => $expectedFlowCount > 0
                    && count($flowContracts) >= $expectedFlowCount
                    && count($flowPlaybooks) >= $expectedFlowCount
                    && count($runtimeBlueprints) >= $expectedFlowCount,
                'domain_agent_workforce_and_toolkits_cover_flows' => count($agentRegistry) >= count($agentRoles)
                    && count($toolkits) >= count($agentRoles)
                    && count($flowToolkitAssignments) >= $expectedFlowCount,
                'domain_workload_agent_templates_cover_flows' => count($workloadAgentTemplates) >= $expectedFlowCount
                    && count($workloadTemplateCoverage) >= $expectedFlowCount
                    && count(array_filter($workloadAgentTemplates, static fn (array $template): bool => count((array) ($template['skills'] ?? [])) >= 8)) >= $expectedFlowCount
                    && count(array_filter($workloadAgentTemplates, static fn (array $template): bool => count((array) ($template['subagents'] ?? [])) >= 3)) >= $expectedFlowCount
                    && count(array_filter($workloadAgentTemplates, static fn (array $template): bool => (bool) data_get($template, 'approval_contract.operator_review_required', false))) >= $expectedFlowCount
                    && (bool) data_get($company, 'enterprise_domain_workload_agent_template_stack.template_policy.skills_are_trigger_loaded_not_always_on_context', false)
                    && (bool) data_get($company, 'enterprise_domain_workload_agent_template_stack.template_policy.audit_log_required_for_every_tool_call_and_decision', false)
                    && (bool) data_get($company, 'enterprise_domain_workload_agent_template_stack.template_policy.external_write_spend_trade_publish_deploy_delete_or_offensive_security_allowed', true) === false,
                'external_research_and_repository_basis_enterprise_ready' => count($externalResearchSources) >= 12
                    && count($officialFrameworks) >= 8
                    && count($domainRepositories) >= 3
                    && count($flowAdoptionMatrix) >= $expectedFlowCount,
                'domain_solution_pack_ready' => (bool) ($domainSolutionPackRow['domain_solution_pack_ready'] ?? false)
                    && (int) ($domainSolutionPackRow['ready_flow_solution_pack_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($domainSolutionPackRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($domainSolutionPackRow['external_side_effects_enabled'] ?? true),
                'domain_solution_suite_and_execution_mesh_cover_flows' => count($domainSolutionPlaybooks) >= $expectedFlowCount
                    && count($verticalSolutionKits) >= $expectedFlowCount
                    && count($businessExecutionCells) >= $expectedFlowCount,
                'provider_data_and_live_read_connector_model_cover_flows' => count($providerRoutes) >= $expectedFlowCount
                    && count($dataConnectorContracts) >= $expectedFlowCount
                    && count($liveReadProbeContracts) >= $expectedFlowCount,
                'business_process_quality_and_data_model_bound' => count($processes) >= $expectedFlowCount
                    && count($qualityContracts) >= min(5, max(1, count($workProducts)))
                    && count($domainDataEntities) >= 3,
                'productized_service_grc_and_industry_ecosystem_bound' => count($productLines) >= 3
                    && count($grcControls) >= 4
                    && count($industryProviders) >= 3,
                'operating_packages_and_runbooks_cover_flows' => count($operatingPackages) >= $expectedFlowCount
                    && count($operatingSystemRunbooks) >= $expectedFlowCount,
                'domain_model_blocks_external_effects_by_default' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.external_side_effects_default', true) === false
                    && (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.provider_write_or_paid_action_default', true) === false
                    && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false,
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_domain_operating_model_certification_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'domain_archetype' => $this->hub->enterpriseAgentWorkforce->domainOperatingModelArchetype($id),
                'domain_operating_model_certified' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'certification_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_enterprise_domain_operating_model_external_effects_blocked'
                    : 'domain_operating_model_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'certification_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'coverage_counts' => [
                    'agent_role_count' => count($agentRoles),
                    'connector_count' => count($connectors),
                    'work_product_count' => count($workProducts),
                    'metric_count' => count($metrics),
                    'cadence_count' => count($cadences),
                    'source_basis_count' => count($externalResearchSources),
                    'official_framework_repository_count' => count($officialFrameworks),
                    'domain_repository_candidate_count' => count($domainRepositories),
                    'flow_adoption_matrix_count' => count($flowAdoptionMatrix),
                    'workload_agent_template_count' => count($workloadAgentTemplates),
                    'workload_template_coverage_count' => count($workloadTemplateCoverage),
                    'workload_template_subagent_count' => array_sum(array_map(static fn (array $template): int => count((array) ($template['subagents'] ?? [])), $workloadAgentTemplates)),
                    'workload_template_skill_count' => array_sum(array_map(static fn (array $template): int => count((array) ($template['skills'] ?? [])), $workloadAgentTemplates)),
                    'domain_solution_playbook_count' => count($domainSolutionPlaybooks),
                    'vertical_solution_kit_count' => count($verticalSolutionKits),
                    'business_execution_cell_count' => count($businessExecutionCells),
                    'provider_route_count' => count($providerRoutes),
                    'data_connector_contract_count' => count($dataConnectorContracts),
                    'live_read_probe_contract_count' => count($liveReadProbeContracts),
                    'operating_package_count' => count($operatingPackages),
                    'runbook_count' => count($operatingSystemRunbooks),
                ],
                'source_hashes' => [
                    'company_receipt_hash' => $company['receipt_hash'] ?? null,
                    'external_research_adoption_hash' => data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash'),
                    'agent_repository_pipeline_hash' => data_get($company, 'enterprise_agent_repository_adoption_pipeline.pipeline_hash'),
                    'company_domain_solution_pack_record_hash' => $domainSolutionPackRow['company_domain_solution_pack_record_hash'] ?? null,
                    'domain_workload_agent_template_stack_hash' => data_get($company, 'enterprise_domain_workload_agent_template_stack.workload_template_stack_hash'),
                    'domain_solution_stack_hash' => data_get($company, 'enterprise_domain_solution_stack.domain_solution_hash'),
                    'vertical_solution_suite_hash' => data_get($company, 'enterprise_vertical_solution_suite_stack.suite_stack_hash'),
                    'domain_business_execution_mesh_hash' => data_get($company, 'enterprise_domain_business_execution_mesh_stack.execution_mesh_hash'),
                    'domain_provider_workbench_hash' => data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_hash'),
                    'domain_data_connector_operating_hash' => data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash'),
                    'flow_live_read_connector_probe_hash' => data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash'),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_domain_operating_model_certification_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $certifiedCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['domain_operating_model_certified'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $certifiedCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_OPERATING_MODEL_CERTIFICATION_STATUS_SCHEMA,
            'status' => $companies !== [] && $certifiedCompanyCount === count($companies)
                ? 'enterprise_company_domain_operating_models_certified_external_effects_blocked'
                : 'enterprise_company_domain_operating_models_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'certified_company_count' => $certifiedCompanyCount,
                'attention_company_count' => count($companies) - $certifiedCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'source_basis_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'coverage_counts.source_basis_count', 0), $companies)),
                'domain_repository_candidate_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'coverage_counts.domain_repository_candidate_count', 0), $companies)),
                'flow_adoption_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'coverage_counts.flow_adoption_matrix_count', 0), $companies)),
                'domain_solution_pack_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.domain_solution_pack_ready', false))),
                'average_certification_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['certification_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_domain_solution_pack_status_hash' => $domainSolutionPackStatus['enterprise_company_domain_solution_pack_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'domain_operating_model_certification_is_not_execution_authority' => true,
                'claude_financial_services_pattern_generalized_to_all_domains' => true,
                'skills_connectors_subagents_receipts_and_policy_gates_required' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_external_action', 'external_write', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'offensive_security'],
            ],
        ];
        $payload['enterprise_company_domain_operating_model_certification_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyDomainToolExecutionReadinessStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $toolchainCertification = $this->hub->companyOperatingStatus->domainAgentToolchainCertificationStatus($wantedCompany);
        $agentToolchainRuntime = $this->hub->flowActionRuntime->agentToolchainRuntimeStatus($wantedCompany);
        $dataConnectorRuntime = $this->hub->flowActionRuntime->domainDataConnectorOperatingRuntimeStatus($wantedCompany);
        $liveReadProbeRuntime = $this->hub->flowActionRuntime->flowLiveReadConnectorProbeRuntimeStatus($wantedCompany);
        $businessExecutionRuntime = $this->hub->flowActionRuntime->domainBusinessExecutionRuntimeStatus($wantedCompany);

        $toolchainByCompany = $this->hub->companyRowsById($toolchainCertification);
        $agentRuntimeByCompany = $this->hub->companyRowsById($agentToolchainRuntime);
        $dataConnectorByCompany = $this->hub->companyRowsById($dataConnectorRuntime);
        $liveReadByCompany = $this->hub->companyRowsById($liveReadProbeRuntime);
        $businessExecutionByCompany = $this->hub->companyRowsById($businessExecutionRuntime);

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
            $connectors = array_values((array) ($company['connectors'] ?? []));
            $connectorCount = count($connectors);
            $toolchainRow = (array) ($toolchainByCompany[$id] ?? []);
            $agentRuntimeRow = (array) ($agentRuntimeByCompany[$id] ?? []);
            $dataConnectorRow = (array) ($dataConnectorByCompany[$id] ?? []);
            $liveReadRow = (array) ($liveReadByCompany[$id] ?? []);
            $businessExecutionRow = (array) ($businessExecutionByCompany[$id] ?? []);
            $toolchainContracts = array_values((array) ($company['toolchain'] ?? []));
            $certifiedToolContracts = array_values((array) ($toolchainRow['certified_tool_contracts'] ?? []));

            $flowToolExecutionRecords = [];
            foreach ($flowIds as $flowId) {
                $runtimeBlueprint = $this->hub->findByFlow($company, 'flow_runtime_blueprints', $flowId);
                $workloadTemplate = $this->hub->findByFlow($company, 'enterprise_domain_workload_agent_template_stack.workload_agent_templates', $flowId);
                $distributionPackage = (array) ($workloadTemplate['distribution_package'] ?? []);
                $orchestrationRunbook = $this->hub->findByFlow($company, 'enterprise_flow_orchestration_runbook_stack.flow_runbooks', $flowId);
                $toolingBenchmark = $this->hub->findByFlow($company, 'enterprise_tooling_research_stack.per_flow_tooling_benchmark', $flowId);
                $toolKpiBinding = $this->hub->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', $flowId);
                $dataConnectorContract = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
                $connectorFixtureEvalSuite = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', $flowId);
                $liveReadProbeContract = $this->hub->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', $flowId);
                $liveReadEvidenceMatrix = $this->hub->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', $flowId);
                $permissionManifest = (array) data_get($distributionPackage, 'connector_permission_manifest', []);
                $toolPermissionMatrix = array_values((array) data_get($permissionManifest, 'tool_permission_matrix', []));
                $runtimePermissionMatrix = array_values((array) data_get($runtimeBlueprint, 'tool_permission_matrix', []));
                $runbookToolPlan = array_values((array) data_get($orchestrationRunbook, 'tool_plan', []));
                $auditManifest = (array) data_get($distributionPackage, 'audit_manifest', []);
                $connectorRefs = array_values((array) data_get($permissionManifest, 'connector_refs', []));

                $flowGates = [
                    'runtime_blueprint_tool_permissions_bound' => $runtimeBlueprint !== []
                        && count($runtimePermissionMatrix) >= count($connectorRefs)
                        && count(array_filter($runtimePermissionMatrix, static fn (array $permission): bool => (bool) ($permission['requires_receipt'] ?? false))) === count($runtimePermissionMatrix)
                        && count(array_filter($runtimePermissionMatrix, static fn (array $permission): bool => in_array('write', (array) ($permission['blocked_modes'] ?? []), true))) === count($runtimePermissionMatrix),
                    'workload_template_permission_manifest_bound' => $workloadTemplate !== []
                        && count($toolPermissionMatrix) >= count($connectorRefs)
                        && (bool) data_get($permissionManifest, 'write_spend_trade_publish_deploy_delete_allowed', true) === false
                        && (bool) data_get($permissionManifest, 'operator_scope_required_for_live_connector', false)
                        && strlen((string) data_get($permissionManifest, 'permission_matrix_hash', '')) === 64,
                    'orchestration_runbook_tool_plan_bound' => $orchestrationRunbook !== []
                        && count($runbookToolPlan) >= count($connectorRefs)
                        && count(array_filter($runbookToolPlan, static fn (array $tool): bool => (bool) ($tool['receipt_required'] ?? false))) === count($runbookToolPlan)
                        && count(array_filter($runbookToolPlan, static fn (array $tool): bool => in_array('write', (array) ($tool['blocked_without_operator_mandate'] ?? []), true))) === count($runbookToolPlan),
                    'tooling_benchmark_and_kpi_bound' => $toolingBenchmark !== []
                        && strlen((string) ($toolingBenchmark['benchmark_hash'] ?? '')) === 64
                        && $toolKpiBinding !== []
                        && strlen((string) ($toolKpiBinding['binding_hash'] ?? '')) === 64,
                    'data_and_live_read_controls_bound' => $dataConnectorContract !== []
                        && $connectorFixtureEvalSuite !== []
                        && $liveReadProbeContract !== []
                        && $liveReadEvidenceMatrix !== [],
                    'audit_and_receipt_contract_bound' => count((array) ($auditManifest['required_events'] ?? [])) >= 7
                        && (bool) ($auditManifest['receipt_required'] ?? false)
                        && (bool) ($auditManifest['replay_manifest_required'] ?? false)
                        && strlen((string) ($auditManifest['manifest_hash'] ?? '')) === 64,
                    'external_tool_effects_blocked' => (bool) data_get($permissionManifest, 'write_spend_trade_publish_deploy_delete_allowed', true) === false
                        && count(array_filter($toolPermissionMatrix, static fn (array $permission): bool => (bool) ($permission['write_spend_trade_publish_deploy_delete_allowed'] ?? true))) === 0
                        && (bool) data_get($runtimeBlueprint, 'promotion_controls.external_side_effects_default', true) === false,
                ];
                $readyFlowGateCount = count(array_filter($flowGates));
                $flowRow = [
                    'schema' => 'atlas.ai.company.domain_tool_execution_flow_readiness_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'connector_ref_count' => count($connectorRefs),
                    'tool_execution_ready' => $readyFlowGateCount === count($flowGates),
                    'ready_gate_count' => $readyFlowGateCount,
                    'required_gate_count' => count($flowGates),
                    'tool_execution_score' => count($flowGates) > 0 ? round($readyFlowGateCount / count($flowGates), 4) : 0.0,
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'tool_contract_hashes' => [
                        'runtime_hash' => (string) ($runtimeBlueprint['runtime_hash'] ?? ''),
                        'template_hash' => (string) ($workloadTemplate['template_hash'] ?? ''),
                        'runbook_hash' => (string) ($orchestrationRunbook['runbook_hash'] ?? ''),
                        'permission_matrix_hash' => (string) data_get($permissionManifest, 'permission_matrix_hash', ''),
                        'audit_manifest_hash' => (string) ($auditManifest['manifest_hash'] ?? ''),
                        'tooling_benchmark_hash' => (string) ($toolingBenchmark['benchmark_hash'] ?? ''),
                        'tool_kpi_binding_hash' => (string) ($toolKpiBinding['binding_hash'] ?? ''),
                    ],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $flowRow['domain_tool_execution_flow_readiness_record_hash'] = MissionCanonicalHash::sha256($flowRow);
                $flowToolExecutionRecords[] = $flowRow;
            }

            $readyFlowCount = count(array_filter($flowToolExecutionRecords, static fn (array $flow): bool => (bool) ($flow['tool_execution_ready'] ?? false)));
            $gates = [
                'domain_agent_toolchain_certified' => (bool) ($toolchainRow['ready'] ?? false),
                'certified_tool_contracts_cover_connectors' => $connectorCount > 0
                    && count($certifiedToolContracts) >= $connectorCount
                    && count($toolchainContracts) >= $connectorCount,
                'flow_tool_execution_records_cover_flows' => $expectedFlowCount > 0
                    && count($flowToolExecutionRecords) >= $expectedFlowCount
                    && $readyFlowCount === count($flowToolExecutionRecords),
                'agent_toolchain_runtime_or_structural_coverage_ready' => $this->hub->runtimeCoverageRowReady($agentRuntimeRow, 'completed_agent_toolchain_flow_count')
                    || ((int) ($toolchainRow['flow_toolkit_assignment_count'] ?? 0) >= $expectedFlowCount
                        && (int) ($toolchainRow['certified_tool_contract_count'] ?? 0) >= $connectorCount),
                'data_connector_runtime_or_structural_coverage_ready' => $this->hub->runtimeCoverageRowReady($dataConnectorRow, 'completed_domain_data_connector_flow_count')
                    || (count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= $connectorCount
                        && (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true) === false),
                'live_read_probe_runtime_or_structural_coverage_ready' => $this->hub->runtimeCoverageRowReady($liveReadRow, 'completed_flow_live_read_connector_probe_count')
                    || (count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])) >= $expectedFlowCount
                        && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])) >= $expectedFlowCount
                        && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false),
                'business_execution_tool_kpi_runtime_ready' => $this->hub->runtimeCoverageRowReady($businessExecutionRow, 'completed_business_execution_runtime_flow_count')
                    || count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', [])) >= $expectedFlowCount,
                'external_tool_effects_blocked' => count(array_filter($toolchainContracts, static fn (array $tool): bool => (bool) ($tool['external_side_effects'] ?? true))) === 0
                    && ! (bool) ($toolchainRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($agentRuntimeRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($dataConnectorRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($liveReadRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($businessExecutionRow['external_execution_allowed'] ?? false),
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_domain_tool_execution_readiness_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'connector_count' => $connectorCount,
                'tool_execution_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'tool_execution_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_domain_tool_execution_ready_external_effects_blocked'
                    : 'domain_tool_execution_readiness_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'ready_flow_tool_execution_count' => $readyFlowCount,
                'flow_tool_execution_count' => count($flowToolExecutionRecords),
                'tool_execution_score' => count($gates) + count($flowToolExecutionRecords) > 0
                    ? round(($readyGateCount + $readyFlowCount) / (count($gates) + count($flowToolExecutionRecords)), 4)
                    : 0.0,
                'tool_execution_counts' => [
                    'certified_tool_contract_count' => count($certifiedToolContracts),
                    'toolchain_contract_count' => count($toolchainContracts),
                    'runtime_completed_agent_toolchain_flow_count' => (int) ($agentRuntimeRow['completed_agent_toolchain_flow_count'] ?? 0),
                    'runtime_completed_data_connector_flow_count' => (int) ($dataConnectorRow['completed_domain_data_connector_flow_count'] ?? 0),
                    'runtime_completed_live_read_probe_flow_count' => (int) ($liveReadRow['completed_flow_live_read_connector_probe_count'] ?? 0),
                    'runtime_completed_business_execution_flow_count' => (int) ($businessExecutionRow['completed_business_execution_runtime_flow_count'] ?? 0),
                ],
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'flow_tool_execution_records' => $flowToolExecutionRecords,
                'source_hashes' => [
                    'domain_agent_toolchain_certification_record_hash' => $toolchainRow['domain_agent_toolchain_certification_record_hash'] ?? null,
                    'agent_toolchain_runtime_status_hash' => $agentToolchainRuntime['agent_toolchain_runtime_status_hash'] ?? null,
                    'domain_data_connector_operating_runtime_status_hash' => $dataConnectorRuntime['domain_data_connector_operating_runtime_status_hash'] ?? null,
                    'flow_live_read_connector_probe_runtime_status_hash' => $liveReadProbeRuntime['flow_live_read_connector_probe_runtime_status_hash'] ?? null,
                    'domain_business_execution_runtime_status_hash' => $businessExecutionRuntime['domain_business_execution_runtime_status_hash'] ?? null,
                    'orchestration_runbook_hash' => data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash'),
                    'workload_template_stack_hash' => data_get($company, 'enterprise_domain_workload_agent_template_stack.workload_template_stack_hash'),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_domain_tool_execution_readiness_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['tool_execution_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_TOOL_EXECUTION_READINESS_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_domain_tool_execution_ready_external_effects_blocked'
                : 'enterprise_company_domain_tool_execution_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'tool_execution_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'connector_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['connector_count'] ?? 0), $companies)),
                'flow_tool_execution_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_tool_execution_count'] ?? 0), $companies)),
                'ready_flow_tool_execution_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_tool_execution_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_tool_execution_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['tool_execution_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'domain_agent_toolchain_certification_status_hash' => $toolchainCertification['domain_agent_toolchain_certification_status_hash'] ?? null,
                'agent_toolchain_runtime_status_hash' => $agentToolchainRuntime['agent_toolchain_runtime_status_hash'] ?? null,
                'domain_data_connector_operating_runtime_status_hash' => $dataConnectorRuntime['domain_data_connector_operating_runtime_status_hash'] ?? null,
                'flow_live_read_connector_probe_runtime_status_hash' => $liveReadProbeRuntime['flow_live_read_connector_probe_runtime_status_hash'] ?? null,
                'domain_business_execution_runtime_status_hash' => $businessExecutionRuntime['domain_business_execution_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'domain_tool_execution_readiness_is_not_external_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'required_tool_evidence' => ['toolchain_certification', 'runtime_blueprint_permission_matrix', 'workload_permission_manifest', 'orchestration_tool_plan', 'tooling_benchmark', 'tool_kpi_binding', 'audit_manifest', 'receipt_contract'],
                'blocked_operations' => ['unreviewed_tool_install', 'stdio_shell_without_allowlist', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_domain_tool_execution_readiness_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyFlowToolExecutionLedgerStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $toolExecutionReadiness = $this->enterpriseCompanyDomainToolExecutionReadinessStatus($wantedCompany);
        $readinessByCompany = $this->hub->companyRowsById($toolExecutionReadiness);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $readinessRow = (array) ($readinessByCompany[$id] ?? []);
            $flowReadinessRecords = array_values((array) ($readinessRow['flow_tool_execution_records'] ?? []));

            $ledgerRecords = [];
            foreach ($flowReadinessRecords as $flowReadiness) {
                $flowId = (string) ($flowReadiness['flow_id'] ?? '');
                $toolContractHashes = (array) ($flowReadiness['tool_contract_hashes'] ?? []);
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'readiness_record_hash' => $flowReadiness['domain_tool_execution_flow_readiness_record_hash'] ?? null,
                    'tool_contract_hashes' => $toolContractHashes,
                    'mode' => 'governed_dry_run_no_external_effect',
                ];
                $decisionReceiptHash = MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']);
                $toolRunReceiptHash = MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'tool_run_receipt']);
                $auditTrailHash = MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']);
                $rollbackPlanHash = MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']);

                $ledgerGates = [
                    'flow_tool_execution_readiness_green' => (bool) ($flowReadiness['tool_execution_ready'] ?? false),
                    'decision_receipt_bound' => strlen($decisionReceiptHash) === 64,
                    'tool_run_receipt_bound' => strlen($toolRunReceiptHash) === 64,
                    'audit_trail_bound' => strlen($auditTrailHash) === 64,
                    'rollback_plan_bound' => strlen($rollbackPlanHash) === 64,
                    'idempotency_key_bound' => $flowId !== '',
                    'external_effects_blocked' => ! (bool) ($flowReadiness['external_execution_allowed'] ?? true)
                        && ! (bool) ($flowReadiness['external_side_effects_enabled'] ?? true),
                ];
                $readyLedgerGateCount = count(array_filter($ledgerGates));
                $ledgerRecord = [
                    'schema' => 'atlas.ai.company.flow_tool_execution_ledger_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'run_context_id' => $id.'.'.$flowId.'.tool_execution.governed_dry_run.v1',
                    'execution_mode' => 'governed_dry_run_no_external_effect',
                    'execution_status' => $readyLedgerGateCount === count($ledgerGates)
                        ? 'ledger_ready_external_effects_blocked'
                        : 'ledger_attention_required',
                    'ready' => $readyLedgerGateCount === count($ledgerGates),
                    'ready_gate_count' => $readyLedgerGateCount,
                    'required_gate_count' => count($ledgerGates),
                    'gates' => $ledgerGates,
                    'missing_gates' => array_values(array_keys(array_filter($ledgerGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => [
                        'decision_receipt_hash' => $decisionReceiptHash,
                        'tool_run_receipt_hash' => $toolRunReceiptHash,
                        'audit_trail_hash' => $auditTrailHash,
                        'rollback_plan_hash' => $rollbackPlanHash,
                        'readiness_record_hash' => (string) ($flowReadiness['domain_tool_execution_flow_readiness_record_hash'] ?? ''),
                    ],
                    'tool_contract_hashes' => $toolContractHashes,
                    'audit_events' => [
                        'TOOL_PLANNED',
                        'TOOL_APPROVED_BY_POLICY',
                        'TOOL_INVOKED_DRY_RUN',
                        'TOOL_NORMALIZED',
                        'TOOL_EVIDENCE_RECORDED',
                        'GATE_EVALUATED',
                        'EXTERNAL_EFFECT_BLOCKED',
                    ],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $ledgerRecord['flow_tool_execution_ledger_record_hash'] = MissionCanonicalHash::sha256($ledgerRecord);
                $ledgerRecords[] = $ledgerRecord;
            }

            $readyLedgerCount = count(array_filter($ledgerRecords, static fn (array $ledger): bool => (bool) ($ledger['ready'] ?? false)));
            $gates = [
                'domain_tool_execution_readiness_ready' => (bool) ($readinessRow['tool_execution_ready'] ?? false),
                'ledger_records_cover_flows' => $expectedFlowCount > 0
                    && count($ledgerRecords) >= $expectedFlowCount
                    && $readyLedgerCount === count($ledgerRecords),
                'receipt_chains_cover_ledgers' => count(array_filter($ledgerRecords, static fn (array $ledger): bool => strlen((string) data_get($ledger, 'receipt_chain.decision_receipt_hash', '')) === 64
                    && strlen((string) data_get($ledger, 'receipt_chain.tool_run_receipt_hash', '')) === 64
                    && strlen((string) data_get($ledger, 'receipt_chain.audit_trail_hash', '')) === 64
                    && strlen((string) data_get($ledger, 'receipt_chain.rollback_plan_hash', '')) === 64)) === count($ledgerRecords),
                'audit_events_cover_ledgers' => count(array_filter($ledgerRecords, static fn (array $ledger): bool => count((array) ($ledger['audit_events'] ?? [])) >= 7
                    && in_array('EXTERNAL_EFFECT_BLOCKED', (array) ($ledger['audit_events'] ?? []), true))) === count($ledgerRecords),
                'external_tool_effects_blocked' => count(array_filter($ledgerRecords, static fn (array $ledger): bool => (bool) ($ledger['external_execution_allowed'] ?? true)
                    || (bool) ($ledger['external_side_effects_enabled'] ?? true))) === 0
                    && ! (bool) ($readinessRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($readinessRow['external_side_effects_enabled'] ?? false),
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_flow_tool_execution_ledger_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'flow_tool_execution_ledger_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'ledger_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_flow_tool_execution_ledger_ready_external_effects_blocked'
                    : 'flow_tool_execution_ledger_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'ready_ledger_record_count' => $readyLedgerCount,
                'ledger_record_count' => count($ledgerRecords),
                'ledger_score' => count($gates) + count($ledgerRecords) > 0
                    ? round(($readyGateCount + $readyLedgerCount) / (count($gates) + count($ledgerRecords)), 4)
                    : 0.0,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'ledger_records' => $ledgerRecords,
                'source_hashes' => [
                    'company_domain_tool_execution_readiness_record_hash' => $readinessRow['company_domain_tool_execution_readiness_record_hash'] ?? null,
                    'enterprise_company_domain_tool_execution_readiness_status_hash' => $toolExecutionReadiness['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_flow_tool_execution_ledger_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['flow_tool_execution_ledger_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_FLOW_TOOL_EXECUTION_LEDGER_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_flow_tool_execution_ledgers_ready_external_effects_blocked'
                : 'enterprise_company_flow_tool_execution_ledgers_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'flow_tool_execution_ledger_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ledger_record_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ledger_record_count'] ?? 0), $companies)),
                'ready_ledger_record_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_ledger_record_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_ledger_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['ledger_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_domain_tool_execution_readiness_status_hash' => $toolExecutionReadiness['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'flow_tool_execution_ledger_is_not_external_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'required_ledger_evidence' => ['decision_receipt', 'tool_run_receipt', 'audit_trail', 'rollback_plan', 'idempotency_key', 'external_effect_block_event'],
                'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export', 'unreceipted_tool_invocation'],
            ],
        ];
        $payload['enterprise_company_flow_tool_execution_ledger_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyFlowToolExecutionRuntimeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $ledgerStatus = $this->enterpriseCompanyFlowToolExecutionLedgerStatus($wantedCompany);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_FLOW_TOOL_EXECUTION_RUNTIME_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'registered_run_count' => 0,
                    'expected_ledger_record_count' => (int) data_get($ledgerStatus, 'summary.ledger_record_count', 0),
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->persistedToolExecutionRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ((array) ($ledgerStatus['companies'] ?? []) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $registeredRuns = [];
            foreach ((array) ($company['ledger_records'] ?? []) as $ledgerRecord) {
                $runContextId = (string) ($ledgerRecord['run_context_id'] ?? '');
                if ($runContextId === '') {
                    continue;
                }

                $summary = [
                    'company_id' => $id,
                    'flow_id' => (string) ($ledgerRecord['flow_id'] ?? ''),
                    'execution_mode' => 'governed_dry_run_no_external_effect',
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'ledger_record_hash' => (string) ($ledgerRecord['flow_tool_execution_ledger_record_hash'] ?? ''),
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.persisted_flow_tool_execution_result.v1',
                    'status' => 'passed',
                    'summary' => $summary,
                    'findings' => [],
                    'blocking_failures' => [],
                    'receipt_chain' => (array) ($ledgerRecord['receipt_chain'] ?? []),
                    'tool_contract_hashes' => (array) ($ledgerRecord['tool_contract_hashes'] ?? []),
                    'audit_events' => (array) ($ledgerRecord['audit_events'] ?? []),
                ];
                $metadata = [
                    'source' => 'enterprise_company_flow_tool_execution_runtime_register',
                    'receipt_schema_version' => 'atlas.company_flow_tool_execution_runtime_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'decision_receipt_hash' => (string) data_get($ledgerRecord, 'receipt_chain.decision_receipt_hash', ''),
                    'tool_run_receipt_hash' => (string) data_get($ledgerRecord, 'receipt_chain.tool_run_receipt_hash', ''),
                    'audit_trail_hash' => (string) data_get($ledgerRecord, 'receipt_chain.audit_trail_hash', ''),
                    'rollback_plan_hash' => (string) data_get($ledgerRecord, 'receipt_chain.rollback_plan_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_flow_tool_execution_runtime.contract.v1',
                        'mode' => 'governed_dry_run_no_external_effect',
                        'run_context_type' => 'holding_company_flow_tool_execution',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'external_write_allowed' => false,
                        'runtime_policy_mutation_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_tool_runtime',
                        'run_context_type' => 'holding_company_flow_tool_execution',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_flow_tool_execution_dry_run',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => 'passed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'dry_run_allowed',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'ledger_record_hash' => $ledgerRecord['flow_tool_execution_ledger_record_hash'] ?? null,
                        ]),
                        'exit_code' => 0,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->persistedToolExecutionRuntimePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredRuns[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'flow_id' => (string) ($ledgerRecord['flow_id'] ?? ''),
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'tool_run_receipt_hash' => (string) data_get($run->metadata_json, 'tool_run_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_flow_tool_execution_runtime_register_record.v1',
                'company_id' => $id,
                'expected_ledger_record_count' => (int) ($company['ledger_record_count'] ?? 0),
                'registered_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_flow_tool_execution_runtime_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_run_count'] ?? 0), $companies));
        $expectedRunCount = (int) data_get($ledgerStatus, 'summary.ledger_record_count', 0);
        $payload = [
            'ok' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_FLOW_TOOL_EXECUTION_RUNTIME_REGISTER_SCHEMA,
            'status' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount
                ? 'enterprise_company_flow_tool_execution_runtime_registered_external_effects_blocked'
                : 'enterprise_company_flow_tool_execution_runtime_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'registered_run_count' => $registeredRunCount,
                'expected_ledger_record_count' => $expectedRunCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_flow_tool_execution_ledger_status_hash' => $ledgerStatus['enterprise_company_flow_tool_execution_ledger_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->persistedToolExecutionRuntimePolicy(),
        ];
        $payload['enterprise_company_flow_tool_execution_runtime_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyFlowToolExecutionRuntimeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $ledgerStatus = $this->enterpriseCompanyFlowToolExecutionLedgerStatus($wantedCompany);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_FLOW_TOOL_EXECUTION_RUNTIME_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'persisted_runtime_ready_company_count' => 0,
                    'expected_run_count' => (int) data_get($ledgerStatus, 'summary.ledger_record_count', 0),
                    'persisted_run_count' => 0,
                    'ready_persisted_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->persistedToolExecutionRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ((array) ($ledgerStatus['companies'] ?? []) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $runtimeRecords = [];
            foreach ((array) ($company['ledger_records'] ?? []) as $ledgerRecord) {
                $runContextId = (string) ($ledgerRecord['run_context_id'] ?? '');
                $run = $runContextId !== ''
                    ? AtlasToolRun::query()
                        ->where('surface', 'holding_company_tool_runtime')
                        ->where('run_context_type', 'holding_company_flow_tool_execution')
                        ->where('run_context_id', $runContextId)
                        ->latest('updated_at')
                        ->first()
                    : null;

                $recordGates = [
                    'persisted_tool_run_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_dry_run_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'dry_run_allowed',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.persisted_flow_tool_execution_result.v1',
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'tool_run_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $runtimeRecord = [
                    'schema' => 'atlas.ai.company.persisted_flow_tool_execution_runtime_record.v1',
                    'company_id' => $id,
                    'flow_id' => (string) ($ledgerRecord['flow_id'] ?? ''),
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                        'tool_run_receipt_hash' => (string) data_get($run->metadata_json, 'tool_run_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $runtimeRecord['persisted_flow_tool_execution_runtime_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                $runtimeRecords[] = $runtimeRecord;
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $gates = [
                'flow_tool_execution_ledger_ready' => (bool) ($company['flow_tool_execution_ledger_ready'] ?? false),
                'persisted_runtime_records_cover_ledgers' => (int) ($company['ledger_record_count'] ?? 0) > 0
                    && count($runtimeRecords) >= (int) ($company['ledger_record_count'] ?? 0)
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_flow_tool_execution_runtime_status_record.v1',
                'company_id' => $id,
                'expected_run_count' => (int) ($company['ledger_record_count'] ?? 0),
                'persisted_run_count' => count($runtimeRecords),
                'ready_persisted_run_count' => $readyRuntimeRecordCount,
                'persisted_runtime_ready' => (int) ($company['ledger_record_count'] ?? 0) > 0 && $readyGateCount === count($gates),
                'runtime_grade' => (int) ($company['ledger_record_count'] ?? 0) > 0 && $readyGateCount === count($gates)
                    ? 'target_9_persisted_flow_tool_execution_runtime_ready_external_effects_blocked'
                    : 'persisted_flow_tool_execution_runtime_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'source_hashes' => [
                    'company_flow_tool_execution_ledger_record_hash' => $company['company_flow_tool_execution_ledger_record_hash'] ?? null,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_flow_tool_execution_runtime_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['persisted_runtime_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_FLOW_TOOL_EXECUTION_RUNTIME_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_flow_tool_execution_runtime_ready_external_effects_blocked'
                : 'enterprise_company_flow_tool_execution_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'persisted_runtime_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_run_count'] ?? 0), $companies)),
                'persisted_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_run_count'] ?? 0), $companies)),
                'ready_persisted_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_persisted_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_flow_tool_execution_ledger_status_hash' => $ledgerStatus['enterprise_company_flow_tool_execution_ledger_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->persistedToolExecutionRuntimePolicy(),
        ];
        $payload['enterprise_company_flow_tool_execution_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyDomainAdapterExecutionEnvelopeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($wantedCompany);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_ADAPTER_EXECUTION_ENVELOPE_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'registered_envelope_count' => 0,
                    'expected_connector_readiness_count' => (int) data_get($liveRead, 'summary.connector_readiness_count', 0),
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->domainAdapterExecutionEnvelopePolicy(),
            ];
        }

        $recordsByCompany = collect((array) ($liveRead['records'] ?? []))->groupBy('company_id');
        $companies = [];
        foreach ($recordsByCompany as $company => $records) {
            $registeredEnvelopes = [];
            foreach ($records->values()->all() as $record) {
                $record = (array) $record;
                $companyId = (string) ($record['company_id'] ?? (string) $company);
                $flowId = (string) ($record['flow_id'] ?? 'unknown');
                $connectorId = (string) ($record['connector_id'] ?? 'unknown');
                $runContextId = $companyId.'.'.$flowId.'.'.$connectorId.'.adapter_execution_envelope.read_only.v1';
                $envelope = $this->domainAdapterExecutionEnvelope($record, $runContextId);
                $decisionReceiptHash = MissionCanonicalHash::sha256($envelope + ['receipt_type' => 'adapter_decision_receipt']);
                $adapterReceiptHash = MissionCanonicalHash::sha256($envelope + ['receipt_type' => 'adapter_envelope_receipt']);
                $auditTrailHash = MissionCanonicalHash::sha256($envelope + ['receipt_type' => 'adapter_audit_trail']);
                $rollbackPlanHash = MissionCanonicalHash::sha256($envelope + ['receipt_type' => 'adapter_rollback_plan']);
                $summary = [
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'connector_id' => $connectorId,
                    'adapter_mode' => 'read_only_live_probe_or_fixture',
                    'vault_scope_reference' => (string) ($record['vault_scope_reference'] ?? ''),
                    'schema_snapshot_hash' => (string) ($record['schema_snapshot_hash'] ?? ''),
                    'sample_payload_hash' => (string) ($record['sample_payload_hash'] ?? ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.domain_adapter_execution_envelope_result.v1',
                    'status' => 'passed',
                    'summary' => $summary,
                    'execution_envelope' => $envelope,
                    'receipt_chain' => [
                        'decision_receipt_hash' => $decisionReceiptHash,
                        'adapter_envelope_receipt_hash' => $adapterReceiptHash,
                        'audit_trail_hash' => $auditTrailHash,
                        'rollback_plan_hash' => $rollbackPlanHash,
                    ],
                    'findings' => [],
                    'blocking_failures' => [],
                    'audit_events' => [
                        'ADAPTER_ENVELOPE_PLANNED',
                        'LIVE_READ_CONTRACT_VERIFIED',
                        'VAULT_SCOPE_REFERENCED',
                        'READ_ONLY_ADAPTER_PROBE_BOUND',
                        'NORMALIZED_RESULT_BOUND',
                        'RECEIPT_CHAIN_RECORDED',
                        'EXTERNAL_MUTATION_BLOCKED',
                    ],
                ];
                $metadata = [
                    'source' => 'enterprise_company_domain_adapter_execution_envelope_register',
                    'receipt_schema_version' => 'atlas.company_domain_adapter_execution_envelope_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'decision_receipt_hash' => $decisionReceiptHash,
                    'adapter_envelope_receipt_hash' => $adapterReceiptHash,
                    'audit_trail_hash' => $auditTrailHash,
                    'rollback_plan_hash' => $rollbackPlanHash,
                    'live_read_readiness_hash' => (string) ($record['readiness_hash'] ?? ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'adapter_execution_contract' => [
                        'schema_version' => 'atlas.company_domain_adapter_execution_envelope.contract.v1',
                        'run_context_type' => 'holding_company_domain_adapter_execution_envelope',
                        'run_context_id' => $runContextId,
                        'adapter_mode' => 'read_only_live_probe_or_fixture',
                        'live_write_allowed' => false,
                        'credential_material_export_allowed' => false,
                        'external_mutation_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_adapter_runtime',
                        'run_context_type' => 'holding_company_domain_adapter_execution_envelope',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_domain_adapter_execution_envelope',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => 'passed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'read_only_adapter_probe_allowed',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'live_read_readiness_hash' => $record['readiness_hash'] ?? null,
                        ]),
                        'exit_code' => 0,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->domainAdapterExecutionEnvelopePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredEnvelopes[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'flow_id' => $flowId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'adapter_envelope_receipt_hash' => $adapterReceiptHash,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_domain_adapter_execution_envelope_register_record.v1',
                'company_id' => (string) $company,
                'expected_connector_readiness_count' => $records->count(),
                'registered_envelope_count' => count($registeredEnvelopes),
                'registered_envelopes' => $registeredEnvelopes,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_domain_adapter_execution_envelope_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredEnvelopeCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_envelope_count'] ?? 0), $companies));
        $expectedEnvelopeCount = (int) data_get($liveRead, 'summary.connector_readiness_count', 0);
        $payload = [
            'ok' => $expectedEnvelopeCount > 0 && $registeredEnvelopeCount >= $expectedEnvelopeCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_ADAPTER_EXECUTION_ENVELOPE_REGISTER_SCHEMA,
            'status' => $expectedEnvelopeCount > 0 && $registeredEnvelopeCount >= $expectedEnvelopeCount
                ? 'enterprise_company_domain_adapter_execution_envelopes_registered_external_effects_blocked'
                : 'enterprise_company_domain_adapter_execution_envelopes_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'registered_envelope_count' => $registeredEnvelopeCount,
                'expected_connector_readiness_count' => $expectedEnvelopeCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->domainAdapterExecutionEnvelopePolicy(),
        ];
        $payload['enterprise_company_domain_adapter_execution_envelope_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyDomainAdapterExecutionEnvelopeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($wantedCompany);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_ADAPTER_EXECUTION_ENVELOPE_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'adapter_envelope_ready_company_count' => 0,
                    'expected_envelope_count' => (int) data_get($liveRead, 'summary.connector_readiness_count', 0),
                    'persisted_envelope_count' => 0,
                    'ready_persisted_envelope_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->domainAdapterExecutionEnvelopePolicy(),
            ];
        }

        $companies = [];
        foreach (collect((array) ($liveRead['records'] ?? []))->groupBy('company_id') as $company => $records) {
            $runtimeRecords = [];
            foreach ($records->values()->all() as $record) {
                $record = (array) $record;
                $companyId = (string) ($record['company_id'] ?? (string) $company);
                $flowId = (string) ($record['flow_id'] ?? 'unknown');
                $connectorId = (string) ($record['connector_id'] ?? 'unknown');
                $runContextId = $companyId.'.'.$flowId.'.'.$connectorId.'.adapter_execution_envelope.read_only.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_adapter_runtime')
                    ->where('run_context_type', 'holding_company_domain_adapter_execution_envelope')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'live_read_connector_ready' => (bool) ($record['live_read_ready'] ?? false),
                    'persisted_adapter_envelope_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_read_only_adapter_probe_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'read_only_adapter_probe_allowed',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.domain_adapter_execution_envelope_result.v1',
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'adapter_envelope_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'adapter_scope_read_only' => $run instanceof AtlasToolRun
                        && (bool) data_get($run->normalized_result_json, 'execution_envelope.read_only_scope.live_write_allowed', true) === false
                        && (bool) data_get($run->normalized_result_json, 'execution_envelope.read_only_scope.credential_material_export_allowed', true) === false,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $runtimeRecord = [
                    'schema' => 'atlas.ai.company.persisted_domain_adapter_execution_envelope_record.v1',
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                        'adapter_envelope_receipt_hash' => (string) data_get($run->metadata_json, 'adapter_envelope_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $runtimeRecord['persisted_domain_adapter_execution_envelope_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                $runtimeRecords[] = $runtimeRecord;
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedCount = $records->count();
            $gates = [
                'live_read_connectors_ready' => $expectedCount > 0 && count(array_filter($records->values()->all(), static fn (array $record): bool => (bool) ($record['live_read_ready'] ?? false))) === $expectedCount,
                'persisted_adapter_envelopes_cover_connectors' => $expectedCount > 0
                    && count($runtimeRecords) >= $expectedCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_domain_adapter_execution_envelope_status_record.v1',
                'company_id' => (string) $company,
                'expected_envelope_count' => $expectedCount,
                'persisted_envelope_count' => count($runtimeRecords),
                'ready_persisted_envelope_count' => $readyRuntimeRecordCount,
                'adapter_envelope_ready' => $expectedCount > 0 && $readyGateCount === count($gates),
                'adapter_envelope_grade' => $expectedCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_domain_adapter_execution_envelopes_ready_external_effects_blocked'
                    : 'domain_adapter_execution_envelopes_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_domain_adapter_execution_envelope_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['adapter_envelope_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_DOMAIN_ADAPTER_EXECUTION_ENVELOPE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_domain_adapter_execution_envelopes_ready_external_effects_blocked'
                : 'enterprise_company_domain_adapter_execution_envelopes_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'adapter_envelope_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_envelope_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_envelope_count'] ?? 0), $companies)),
                'persisted_envelope_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_envelope_count'] ?? 0), $companies)),
                'ready_persisted_envelope_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_persisted_envelope_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->domainAdapterExecutionEnvelopePolicy(),
        ];
        $payload['enterprise_company_domain_adapter_execution_envelope_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function persistedToolExecutionRuntimePolicy(): array
    {
        return [
            'persisted_runtime_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'required_runtime_evidence' => ['atlas_tool_run', 'normalized_result', 'policy_decision', 'decision_receipt', 'tool_run_receipt', 'audit_trail', 'rollback_plan'],
            'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'secret_export', 'unreceipted_tool_invocation'],
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function domainAdapterExecutionEnvelope(array $record, string $runContextId): array
    {
        $companyId = (string) ($record['company_id'] ?? 'unknown');
        $flowId = (string) ($record['flow_id'] ?? 'unknown');
        $connectorId = (string) ($record['connector_id'] ?? 'unknown');
        $allowedOperations = array_values(array_intersect(
            ['schema_snapshot', 'read_only_ping', 'fixture_fetch', 'sample_payload_capture', 'receipt_export'],
            array_values((array) ($record['allowed_operations'] ?? [])),
        ));
        if ($allowedOperations === []) {
            $allowedOperations = ['schema_snapshot', 'read_only_ping', 'fixture_fetch', 'sample_payload_capture', 'receipt_export'];
        }

        $envelope = [
            'schema' => 'atlas.ai.company.domain_adapter_execution_envelope.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'connector_id' => $connectorId,
            'run_context_id' => $runContextId,
            'mode' => 'read_only_live_probe_or_fixture',
            'source_patterns' => [
                'anthropic_financial_services_unified_data_with_direct_source_links',
                'model_context_protocol_prebuilt_connector_boundary',
                'enterprise_agent_guardrails_handoffs_tracing',
                'durable_idempotent_adapter_execution',
            ],
            'idempotency' => [
                'key' => hash('sha256', 'domain_adapter_execution_envelope|'.$companyId.'|'.$flowId.'|'.$connectorId),
                'replay_safe' => true,
                'duplicate_external_effect_prevention_required' => true,
            ],
            'read_only_scope' => [
                'vault_scope_reference' => (string) ($record['vault_scope_reference'] ?? ''),
                'credential_material_export_allowed' => false,
                'live_write_allowed' => false,
                'allowed_operations' => $allowedOperations,
                'blocked_operations' => array_values(array_unique(array_merge(
                    array_values((array) ($record['blocked_operations'] ?? [])),
                    ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                ))),
            ],
            'source_verification' => [
                'direct_source_link_or_record_id_required' => true,
                'schema_snapshot_hash' => (string) ($record['schema_snapshot_hash'] ?? ''),
                'sample_payload_hash' => (string) ($record['sample_payload_hash'] ?? ''),
                'provider_lineage_hash' => (string) ($record['provider_lineage_hash'] ?? ''),
                'claim_without_source_reference_allowed' => false,
            ],
            'normalization_contract' => [
                'normalized_result_schema' => 'atlas.ai.company.domain_adapter_execution_envelope_result.v1',
                'empty_or_partial_payload_policy' => 'fail_closed_and_attach_finding',
                'pii_or_secret_redaction_required' => true,
                'normalization_hash' => hash('sha256', 'domain_adapter_normalization|'.$companyId.'|'.$flowId.'|'.$connectorId),
            ],
            'reconciliation_contract' => [
                'post_execution_reconciliation_required' => true,
                'receipt_export_required' => true,
                'audit_trail_required' => true,
                'rollback_or_compensation_plan_required' => true,
                'reconciliation_hash' => hash('sha256', 'domain_adapter_reconciliation|'.$companyId.'|'.$flowId.'|'.$connectorId),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $envelope['envelope_hash'] = MissionCanonicalHash::sha256($envelope);

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainAdapterExecutionEnvelopePolicy(): array
    {
        return [
            'adapter_execution_envelope_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'required_runtime_evidence' => ['atlas_tool_run', 'live_read_connector_readiness', 'vault_scope_reference', 'schema_snapshot', 'sample_payload', 'normalized_result', 'decision_receipt', 'adapter_envelope_receipt', 'audit_trail', 'rollback_plan'],
            'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export', 'credential_material_export', 'unreceipted_adapter_invocation'],
        ];
    }
}
