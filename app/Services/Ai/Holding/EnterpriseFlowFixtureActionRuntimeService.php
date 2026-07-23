<?php

namespace App\Services\Ai\Holding;

use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureBuilders;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureRuntimeRecords;
use App\Services\Ai\Holding\EnterpriseFlowFixture\EnterpriseFlowFixtureSupport;
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

    public function __construct(
        private readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
    ) {
        $this->builders = new EnterpriseFlowFixtureBuilders();
        $this->records = new EnterpriseFlowFixtureRuntimeRecords($this);
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
    private function runtimeStatusFor(array $spec, ?string $companyId): array
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


    /**
     * @return array<string,mixed>
     */
    public function runPortfolioInternal(?string $companyId = null): array
    {
        $this->clearRuntimeRecordCache();

        $records = [];
        $companies = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $companyRecords = [];
            foreach ((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []) as $action) {
                $run = $this->run($currentCompanyId, (string) ($action['action'] ?? ''), false);
                $companyRecords[] = [
                    'company_id' => $currentCompanyId,
                    'flow_id' => (string) ($run['flow_id'] ?? ''),
                    'status' => (string) ($run['status'] ?? ''),
                    'runtime_record_uuid' => (string) data_get($run, 'runtime_record.uuid', ''),
                    'receipt_hash' => (string) ($run['receipt_hash'] ?? ''),
                    'external_side_effects' => (bool) ($run['external_side_effects'] ?? true),
                ];
            }

            $companies[] = [
                'company_id' => $currentCompanyId,
                'flow_count' => count($companyRecords),
                'completed_flow_count' => count(array_filter(
                    $companyRecords,
                    static fn (array $record): bool => ($record['status'] ?? null) === 'internal_flow_completed_external_blocked',
                )),
                'runtime_record_bound_count' => count(array_filter(
                    $companyRecords,
                    static fn (array $record): bool => (string) ($record['runtime_record_uuid'] ?? '') !== '',
                )),
            ];
            array_push($records, ...$companyRecords);
            unset($this->runtimeRecordCache[$currentCompanyId]);
        }
        if ($companyId === null) {
            $this->runtimeRecordCache = [];
        }

        $payload = [
            'ok' => $records !== [] && count($records) === count(array_filter(
                $records,
                static fn (array $record): bool => ($record['status'] ?? null) === 'internal_flow_completed_external_blocked',
            )),
            'schema' => 'atlas.ai.holding.enterprise_flow_action_runtime_run.v1',
            'status' => $records === [] ? 'empty_flow_action_runtime_scope' : 'completed_internal_flow_action_runtime_external_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'flow_count' => count($records),
                'completed_flow_count' => count(array_filter(
                    $records,
                    static fn (array $record): bool => ($record['status'] ?? null) === 'internal_flow_completed_external_blocked',
                )),
                'runtime_record_bound_count' => count(array_filter(
                    $records,
                    static fn (array $record): bool => (string) ($record['runtime_record_uuid'] ?? '') !== '',
                )),
                'external_side_effect_count' => count(array_filter(
                    $records,
                    static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true),
                )),
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'mode' => 'internal_enterprise_flow_action_runtime',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
            ],
        ];
        $payload['runtime_run_hash'] = MissionCanonicalHash::sha256($payload);
        $this->clearRuntimeRecordCache();

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companySystemModelRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_company_system_model_runtime_status.v1',
            'status_complete' => 'complete_company_system_model_runtime_coverage_external_commitments_blocked',
            'status_missing' => 'missing_company_system_model_runtime_coverage',
            'hash_key' => 'company_system_model_runtime_status_hash',
            'completed_key' => 'completed_company_system_model_flow_count',
            'missing_key' => 'missing_company_system_model_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'company_system_model_runtime_bound',
                ['hash', 'company_system_model_attestation_hash'],
                ['internal', 'external_side_effects'],
                'company_system_domain_data_model_bound',
                'company_system_data_lineage_bound',
                'company_system_business_process_bound',
                'company_system_deliverable_quality_bound',
                'company_system_production_pack_bound',
                'company_system_production_observability_bound',
                'company_system_slo_sli_bound',
                'company_system_incident_response_bound',
                'company_system_capacity_plan_bound',
                'company_system_integration_enablement_bound',
                'company_system_commercial_stack_bound',
                'company_system_commercial_intake_bound',
                'company_system_commercial_fulfillment_bound',
                'company_system_external_actions_blocked',
            ],
            'counts' => [
                'domain_data_model_bound_count' => 'company_system_domain_data_model_bound',
                'data_lineage_bound_count' => 'company_system_data_lineage_bound',
                'business_process_bound_count' => 'company_system_business_process_bound',
                'deliverable_quality_bound_count' => 'company_system_deliverable_quality_bound',
                'production_pack_bound_count' => 'company_system_production_pack_bound',
                'production_observability_bound_count' => 'company_system_production_observability_bound',
                'slo_sli_bound_count' => 'company_system_slo_sli_bound',
                'incident_response_bound_count' => 'company_system_incident_response_bound',
                'capacity_plan_bound_count' => 'company_system_capacity_plan_bound',
                'integration_enablement_bound_count' => 'company_system_integration_enablement_bound',
                'commercial_stack_bound_count' => 'company_system_commercial_stack_bound',
                'commercial_intake_bound_count' => 'company_system_commercial_intake_bound',
                'commercial_fulfillment_bound_count' => 'company_system_commercial_fulfillment_bound',
                'external_actions_blocked_count' => 'company_system_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'commercial_external_billing_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'company_system_model_requires_data_process_quality_production_and_commercial_contracts' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function internalOperationsBackboneRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_internal_operations_backbone_runtime_status.v1',
            'status_complete' => 'complete_internal_operations_backbone_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_internal_operations_backbone_runtime_coverage',
            'hash_key' => 'internal_operations_backbone_runtime_status_hash',
            'completed_key' => 'completed_internal_operations_backbone_flow_count',
            'missing_key' => 'missing_internal_operations_backbone_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'internal_operations_backbone_runtime_bound',
                ['hash', 'internal_operations_backbone_attestation_hash'],
                ['internal', 'external_side_effects'],
                'internal_ops_account_contract_delivery_bound',
                'internal_ops_vendor_legal_procurement_bound',
                'internal_ops_resilience_continuity_bound',
                'internal_ops_analytics_decision_intelligence_bound',
                'internal_ops_knowledge_memory_learning_bound',
                'internal_ops_identity_access_sovereignty_bound',
                'internal_ops_control_tower_run_operations_bound',
                'internal_ops_delivery_assurance_bound',
                'internal_ops_grc_control_evidence_bound',
                'internal_ops_external_actions_blocked',
            ],
            'counts' => [
                'account_contract_delivery_bound_count' => 'internal_ops_account_contract_delivery_bound',
                'vendor_legal_procurement_bound_count' => 'internal_ops_vendor_legal_procurement_bound',
                'resilience_continuity_bound_count' => 'internal_ops_resilience_continuity_bound',
                'analytics_decision_intelligence_bound_count' => 'internal_ops_analytics_decision_intelligence_bound',
                'knowledge_memory_learning_bound_count' => 'internal_ops_knowledge_memory_learning_bound',
                'identity_access_sovereignty_bound_count' => 'internal_ops_identity_access_sovereignty_bound',
                'control_tower_run_operations_bound_count' => 'internal_ops_control_tower_run_operations_bound',
                'delivery_assurance_bound_count' => 'internal_ops_delivery_assurance_bound',
                'grc_control_evidence_bound_count' => 'internal_ops_grc_control_evidence_bound',
                'external_actions_blocked_count' => 'internal_ops_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'customer_vendor_memory_identity_delivery_external_actions_allowed' => false,
                'operator_mandate_required_for_external_action' => true,
                'internal_operations_backbone_requires_account_vendor_resilience_analytics_memory_identity_control_tower_delivery_and_grc' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function activationRunOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_activation_run_operations_runtime_status.v1',
            'status_complete' => 'complete_activation_run_operations_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_activation_run_operations_runtime_coverage',
            'hash_key' => 'activation_run_operations_runtime_status_hash',
            'completed_key' => 'completed_activation_run_operations_flow_count',
            'missing_key' => 'missing_activation_run_operations_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'activation_run_operations_runtime_bound',
                ['hash', 'activation_run_operations_attestation_hash'],
                ['internal', 'external_side_effects'],
                'activation_ops_integration_plan_bound',
                'activation_ops_policy_bound',
                'activation_ops_source_tracks_bound',
                'activation_ops_connector_tracks_bound',
                'activation_ops_flow_matrix_bound',
                'activation_ops_run_queue_model_bound',
                'activation_ops_flow_lane_bound',
                'activation_ops_connector_probe_bound',
                'activation_ops_live_read_probe_bound',
                'activation_ops_rehearsal_promotion_evidence_bound',
                'activation_ops_observability_bound',
                'activation_ops_external_actions_blocked',
            ],
            'counts' => [
                'integration_activation_plan_bound_count' => 'activation_ops_integration_plan_bound',
                'activation_policy_bound_count' => 'activation_ops_policy_bound',
                'source_activation_tracks_bound_count' => 'activation_ops_source_tracks_bound',
                'connector_activation_tracks_bound_count' => 'activation_ops_connector_tracks_bound',
                'flow_activation_matrix_bound_count' => 'activation_ops_flow_matrix_bound',
                'run_queue_model_bound_count' => 'activation_ops_run_queue_model_bound',
                'flow_operations_lane_bound_count' => 'activation_ops_flow_lane_bound',
                'connector_operations_probe_bound_count' => 'activation_ops_connector_probe_bound',
                'live_read_probe_plan_bound_count' => 'activation_ops_live_read_probe_bound',
                'rehearsal_promotion_evidence_bound_count' => 'activation_ops_rehearsal_promotion_evidence_bound',
                'observability_bound_count' => 'activation_ops_observability_bound',
                'external_actions_blocked_count' => 'activation_ops_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
                'activation_runtime_requires_tracks_queue_probe_live_read_rehearsal_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $action): string => (string) ($action['flow_id'] ?? $action['action'] ?? ''),
                (array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []),
            ));
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $companyRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'flow_count' => count($flows),
                'completed_runtime_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_runtime_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_runtime_flow_count'], $companies));

        return AtlasEnvelope::seal([
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_flow_action_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_internal_flow_action_runtime_coverage'
                : 'missing_internal_flow_action_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_runtime_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ], 'runtime_status_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalSolutionRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_vertical_solution_runtime_status.v1',
            'status_complete' => 'complete_vertical_solution_runtime_coverage_external_blocked',
            'status_missing' => 'missing_vertical_solution_runtime_coverage',
            'hash_key' => 'vertical_solution_runtime_status_hash',
            'completed_key' => 'completed_vertical_runtime_flow_count',
            'missing_key' => 'missing_vertical_runtime_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $kit): string => (string) ($kit['flow_id'] ?? ''),
                (array) data_get($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', []),
            )),
            'gate' => [
                'vertical_solution_kit_bound',
                'artifact_factory_bound',
                ['count', 'vertical_connector_workbench_count'],
                ['hash', 'domain_execution_brief_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'vertical_solution_kit_bound_count' => 'vertical_solution_kit_bound',
                'artifact_factory_bound_count' => 'artifact_factory_bound',
                'vertical_connector_workbench_bound_count' => ['count', 'vertical_connector_workbench_count'],
                'domain_execution_brief_bound_count' => ['hash', 'domain_execution_brief_hash'],
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'vertical_solution_runtime_requires_bound_kit_artifact_factory_and_connector_workbench' => true,
                'operator_mandate_required_for_external_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_business_execution_runtime_status.v1',
            'status_complete' => 'complete_domain_business_execution_runtime_coverage_external_blocked',
            'status_missing' => 'missing_domain_business_execution_runtime_coverage',
            'hash_key' => 'domain_business_execution_runtime_status_hash',
            'completed_key' => 'completed_business_execution_runtime_flow_count',
            'missing_key' => 'missing_business_execution_runtime_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $cell): string => (string) ($cell['flow_id'] ?? ''),
                (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []),
            )),
            'gate' => [
                'business_execution_cell_bound',
                'business_kpi_binding_bound',
                'business_service_lane_bound',
                'business_artifact_contract_bound',
                ['hash', 'business_execution_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'business_execution_cell_bound_count' => 'business_execution_cell_bound',
                'business_kpi_binding_bound_count' => 'business_kpi_binding_bound',
                'business_service_lane_bound_count' => 'business_service_lane_bound',
                'business_artifact_contract_bound_count' => 'business_artifact_contract_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'business_execution_runtime_requires_cell_kpi_lane_and_artifact_contract' => true,
                'operator_mandate_required_for_external_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function companyOperatingSpineRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_company_operating_spine_runtime_status.v1',
            'status_complete' => 'complete_company_operating_spine_runtime_coverage_external_blocked',
            'status_missing' => 'missing_company_operating_spine_runtime_coverage',
            'hash_key' => 'company_operating_spine_runtime_status_hash',
            'completed_key' => 'completed_operating_spine_flow_count',
            'missing_key' => 'missing_operating_spine_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'company_operating_spine_bound',
                'customer_market_runtime_bound',
                'account_contract_runtime_bound',
                'vendor_legal_runtime_bound',
                'resilience_runtime_bound',
                'analytics_runtime_bound',
                'knowledge_memory_runtime_bound',
                'identity_sovereignty_runtime_bound',
                ['hash', 'company_operating_spine_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'customer_market_runtime_bound_count' => 'customer_market_runtime_bound',
                'account_contract_runtime_bound_count' => 'account_contract_runtime_bound',
                'vendor_legal_runtime_bound_count' => 'vendor_legal_runtime_bound',
                'resilience_runtime_bound_count' => 'resilience_runtime_bound',
                'analytics_runtime_bound_count' => 'analytics_runtime_bound',
                'knowledge_memory_runtime_bound_count' => 'knowledge_memory_runtime_bound',
                'identity_sovereignty_runtime_bound_count' => 'identity_sovereignty_runtime_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operating_spine_requires_customer_account_vendor_resilience_analytics_knowledge_and_identity_bindings' => true,
                'operator_mandate_required_for_external_customer_vendor_billing_capital_or_data_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function commercialOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_commercial_operations_runtime_status.v1',
            'status_complete' => 'complete_commercial_operations_runtime_coverage_external_customer_vendor_billing_blocked',
            'status_missing' => 'missing_commercial_operations_runtime_coverage',
            'hash_key' => 'commercial_operations_runtime_status_hash',
            'completed_key' => 'completed_commercial_operations_flow_count',
            'missing_key' => 'missing_commercial_operations_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'commercial_operations_runtime_bound',
                'customer_market_operations_bound',
                'offer_packaging_bound',
                'customer_journey_bound',
                'customer_success_scorecard_bound',
                'account_contract_delivery_bound',
                'contract_entitlement_bound',
                'onboarding_success_plan_bound',
                'service_review_renewal_bound',
                'account_health_risk_bound',
                'billing_revenue_model_bound',
                'vendor_legal_procurement_bound',
                'vendor_due_diligence_bound',
                'source_terms_review_bound',
                'flow_procurement_routing_bound',
                'vendor_operability_bound',
                ['hash', 'commercial_operations_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'customer_market_operations_bound_count' => 'customer_market_operations_bound',
                'offer_packaging_bound_count' => 'offer_packaging_bound',
                'customer_journey_bound_count' => 'customer_journey_bound',
                'customer_success_scorecard_bound_count' => 'customer_success_scorecard_bound',
                'account_contract_delivery_bound_count' => 'account_contract_delivery_bound',
                'contract_entitlement_bound_count' => 'contract_entitlement_bound',
                'onboarding_success_plan_bound_count' => 'onboarding_success_plan_bound',
                'service_review_renewal_bound_count' => 'service_review_renewal_bound',
                'account_health_risk_bound_count' => 'account_health_risk_bound',
                'billing_revenue_model_bound_count' => 'billing_revenue_model_bound',
                'vendor_legal_procurement_bound_count' => 'vendor_legal_procurement_bound',
                'vendor_due_diligence_bound_count' => 'vendor_due_diligence_bound',
                'source_terms_review_bound_count' => 'source_terms_review_bound',
                'flow_procurement_routing_bound_count' => 'flow_procurement_routing_bound',
                'vendor_operability_bound_count' => 'vendor_operability_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_vendor_procurement_allowed' => false,
                'commercial_runtime_requires_offer_journey_account_contract_billing_vendor_and_procurement_controls' => true,
                'operator_mandate_required_for_customer_vendor_billing_or_public_claim' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainProviderWorkbenchRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_provider_workbench_runtime_status.v1',
            'status_complete' => 'complete_domain_provider_workbench_runtime_coverage_external_write_paid_blocked',
            'status_missing' => 'missing_domain_provider_workbench_runtime_coverage',
            'hash_key' => 'domain_provider_workbench_runtime_status_hash',
            'completed_key' => 'completed_provider_workbench_flow_count',
            'missing_key' => 'missing_provider_workbench_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_provider_workbench_runtime_bound',
                'provider_contracts_bound',
                'connector_workbenches_bound',
                'flow_provider_route_bound',
                'provider_eval_cases_bound',
                'provider_data_product_lineage_bound',
                'provider_workbench_observability_bound',
                'provider_external_write_paid_blocked',
                ['hash', 'domain_provider_workbench_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'provider_contracts_bound_count' => 'provider_contracts_bound',
                'connector_workbenches_bound_count' => 'connector_workbenches_bound',
                'flow_provider_route_bound_count' => 'flow_provider_route_bound',
                'provider_eval_cases_bound_count' => 'provider_eval_cases_bound',
                'provider_data_product_lineage_bound_count' => 'provider_data_product_lineage_bound',
                'provider_workbench_observability_bound_count' => 'provider_workbench_observability_bound',
                'provider_external_write_paid_blocked_count' => 'provider_external_write_paid_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'provider_write_or_paid_action_default' => false,
                'domain_provider_runtime_requires_contracts_workbenches_routes_eval_lineage_and_observability' => true,
                'operator_signed_scope_required_for_provider_write_spend_trade_publish_or_secret_export' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainCompanyExecutionSuiteRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_company_execution_suite_runtime_status.v1',
            'status_complete' => 'complete_domain_company_execution_suite_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_domain_company_execution_suite_runtime_coverage',
            'hash_key' => 'domain_company_execution_suite_runtime_status_hash',
            'completed_key' => 'completed_domain_company_execution_suite_flow_count',
            'missing_key' => 'missing_domain_company_execution_suite_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_company_execution_suite_runtime_bound',
                'domain_execution_suite_stack_bound',
                'domain_execution_suite_source_catalog_bound',
                'domain_execution_suite_operating_model_bound',
                'domain_execution_suite_connector_workbench_bound',
                'domain_execution_suite_flow_packet_bound',
                'domain_execution_suite_risk_control_bound',
                'domain_execution_suite_decision_room_bound',
                'domain_execution_suite_replay_eval_bound',
                'domain_execution_suite_observability_bound',
                'domain_execution_suite_external_actions_blocked',
                ['hash', 'domain_company_execution_suite_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'suite_stack_bound_count' => 'domain_execution_suite_stack_bound',
                'source_catalog_bound_count' => 'domain_execution_suite_source_catalog_bound',
                'operating_model_bound_count' => 'domain_execution_suite_operating_model_bound',
                'connector_workbench_bound_count' => 'domain_execution_suite_connector_workbench_bound',
                'flow_packet_bound_count' => 'domain_execution_suite_flow_packet_bound',
                'risk_control_bound_count' => 'domain_execution_suite_risk_control_bound',
                'decision_room_bound_count' => 'domain_execution_suite_decision_room_bound',
                'replay_eval_bound_count' => 'domain_execution_suite_replay_eval_bound',
                'observability_bound_count' => 'domain_execution_suite_observability_bound',
                'external_actions_blocked_count' => 'domain_execution_suite_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'domain_company_suite_requires_sources_connectors_flow_packets_risk_controls_decision_rooms_replay_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function flowWorkProductDeliveryRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_flow_work_product_delivery_runtime_status.v1',
            'status_complete' => 'complete_flow_work_product_delivery_runtime_coverage_external_delivery_blocked',
            'status_missing' => 'missing_flow_work_product_delivery_runtime_coverage',
            'hash_key' => 'flow_work_product_delivery_runtime_status_hash',
            'completed_key' => 'completed_flow_work_product_delivery_count',
            'missing_key' => 'missing_flow_work_product_delivery_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'flow_work_product_delivery_runtime_bound',
                'flow_work_product_delivery_stack_bound',
                'flow_work_product_catalog_bound',
                'flow_work_product_blueprint_bound',
                'flow_work_product_acceptance_bound',
                'flow_work_product_handoff_bound',
                'flow_work_product_replay_check_bound',
                'flow_work_product_external_delivery_blocked',
                ['hash', 'flow_work_product_delivery_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'delivery_stack_bound_count' => 'flow_work_product_delivery_stack_bound',
                'catalog_bound_count' => 'flow_work_product_catalog_bound',
                'blueprint_bound_count' => 'flow_work_product_blueprint_bound',
                'acceptance_bound_count' => 'flow_work_product_acceptance_bound',
                'handoff_bound_count' => 'flow_work_product_handoff_bound',
                'replay_check_bound_count' => 'flow_work_product_replay_check_bound',
                'external_delivery_blocked_count' => 'flow_work_product_external_delivery_blocked',
            ],
            'policy' => [
                'external_delivery_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_acceptance_required_before_external_handoff' => true,
                'flow_work_product_delivery_requires_catalog_blueprint_acceptance_handoff_and_replay' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainDataConnectorOperatingRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_data_connector_operating_runtime_status.v1',
            'status_complete' => 'complete_domain_data_connector_operating_runtime_coverage_external_mutations_blocked',
            'status_missing' => 'missing_domain_data_connector_operating_runtime_coverage',
            'hash_key' => 'domain_data_connector_operating_runtime_status_hash',
            'completed_key' => 'completed_domain_data_connector_flow_count',
            'missing_key' => 'missing_domain_data_connector_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_data_connector_operating_runtime_bound',
                'domain_data_connector_stack_bound',
                'domain_data_room_source_catalog_bound',
                'domain_data_product_contracts_bound',
                'domain_connector_permission_profiles_bound',
                'domain_flow_data_connector_contract_bound',
                'domain_connector_fixture_eval_bound',
                'domain_data_room_operating_model_bound',
                'domain_data_connector_observability_bound',
                'domain_data_connector_external_mutations_blocked',
                ['hash', 'domain_data_connector_operating_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'data_connector_stack_bound_count' => 'domain_data_connector_stack_bound',
                'source_catalog_bound_count' => 'domain_data_room_source_catalog_bound',
                'data_product_contract_bound_count' => 'domain_data_product_contracts_bound',
                'permission_profile_bound_count' => 'domain_connector_permission_profiles_bound',
                'flow_data_connector_contract_bound_count' => 'domain_flow_data_connector_contract_bound',
                'fixture_eval_bound_count' => 'domain_connector_fixture_eval_bound',
                'operating_model_bound_count' => 'domain_data_room_operating_model_bound',
                'observability_bound_count' => 'domain_data_connector_observability_bound',
                'external_mutations_blocked_count' => 'domain_data_connector_external_mutations_blocked',
            ],
            'policy' => [
                'write_tools_enabled' => false,
                'external_data_mutation_allowed' => false,
                'secret_export_allowed' => false,
                'read_only_probe_required_before_live_use' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function flowLiveReadConnectorProbeRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_flow_live_read_connector_probe_runtime_status.v1',
            'status_complete' => 'complete_flow_live_read_connector_probe_runtime_coverage_external_mutations_blocked',
            'status_missing' => 'missing_flow_live_read_connector_probe_runtime_coverage',
            'hash_key' => 'flow_live_read_connector_probe_runtime_status_hash',
            'completed_key' => 'completed_flow_live_read_connector_probe_count',
            'missing_key' => 'missing_flow_live_read_connector_probe_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'flow_live_read_connector_probe_runtime_bound',
                'flow_live_read_probe_stack_bound',
                'flow_live_read_connector_profiles_bound',
                'flow_live_read_probe_contract_bound',
                'flow_live_read_probe_evidence_matrix_bound',
                'flow_live_read_probe_observability_bound',
                'flow_live_read_external_mutations_blocked',
                'flow_live_read_operator_scope_required',
                ['hash', 'flow_live_read_connector_probe_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'probe_stack_bound_count' => 'flow_live_read_probe_stack_bound',
                'connector_profiles_bound_count' => 'flow_live_read_connector_profiles_bound',
                'probe_contract_bound_count' => 'flow_live_read_probe_contract_bound',
                'evidence_matrix_bound_count' => 'flow_live_read_probe_evidence_matrix_bound',
                'observability_bound_count' => 'flow_live_read_probe_observability_bound',
                'external_mutations_blocked_count' => 'flow_live_read_external_mutations_blocked',
                'operator_scope_required_count' => 'flow_live_read_operator_scope_required',
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'live_read_allowed' => true,
                'write_tools_enabled' => false,
                'external_mutation_allowed' => false,
                'credential_material_in_packet_allowed' => false,
                'operator_scope_required_before_live_connector_probe' => true,
                'promotion_unlocked' => 'shadow_readiness_not_external_write_authority',
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function externalResearchAdoptionRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_external_research_adoption_runtime_status.v1',
            'status_complete' => 'complete_external_research_adoption_runtime_coverage_external_effects_blocked',
            'status_missing' => 'missing_external_research_adoption_runtime_coverage',
            'hash_key' => 'external_research_adoption_runtime_status_hash',
            'completed_key' => 'completed_external_research_adoption_flow_count',
            'missing_key' => 'missing_external_research_adoption_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'external_research_adoption_runtime_bound',
                'external_research_source_basis_bound',
                'external_research_repository_catalog_bound',
                'external_research_flow_adoption_matrix_bound',
                'external_research_capability_map_bound',
                'external_research_connector_backlog_bound',
                'external_research_production_gates_bound',
                'external_research_external_effects_blocked',
                ['hash', 'external_research_adoption_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_basis_bound_count' => 'external_research_source_basis_bound',
                'repository_catalog_bound_count' => 'external_research_repository_catalog_bound',
                'flow_adoption_matrix_bound_count' => 'external_research_flow_adoption_matrix_bound',
                'capability_map_bound_count' => 'external_research_capability_map_bound',
                'connector_backlog_bound_count' => 'external_research_connector_backlog_bound',
                'external_effects_blocked_count' => 'external_research_external_effects_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_research_is_architecture_input_only' => true,
                'repository_adoption_without_license_security_fixture_and_operator_review_allowed' => false,
                'runtime_ingestion_without_source_review_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function flowBenchmarkReplayRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_flow_benchmark_replay_runtime_status.v1',
            'status_complete' => 'complete_flow_benchmark_replay_runtime_coverage_external_benchmark_blocked',
            'status_missing' => 'missing_flow_benchmark_replay_runtime_coverage',
            'hash_key' => 'flow_benchmark_replay_runtime_status_hash',
            'completed_key' => 'completed_benchmark_replay_flow_count',
            'missing_key' => 'missing_benchmark_replay_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'flow_benchmark_replay_runtime_bound',
                'offline_dataset_contract_bound',
                'trace_grading_rubric_bound',
                'adversarial_regression_bound',
                'deterministic_state_assertion_bound',
                'replay_comparison_matrix_bound',
                'benchmark_observability_bound',
                'benchmark_promotion_synthetic_scores_blocked',
                ['hash', 'flow_benchmark_replay_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'offline_dataset_contract_bound_count' => 'offline_dataset_contract_bound',
                'trace_grading_rubric_bound_count' => 'trace_grading_rubric_bound',
                'adversarial_regression_bound_count' => 'adversarial_regression_bound',
                'deterministic_state_assertion_bound_count' => 'deterministic_state_assertion_bound',
                'replay_comparison_matrix_bound_count' => 'replay_comparison_matrix_bound',
                'benchmark_observability_bound_count' => 'benchmark_observability_bound',
                'benchmark_promotion_synthetic_scores_blocked_count' => 'benchmark_promotion_synthetic_scores_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
                'benchmark_runtime_requires_dataset_rubric_adversarial_state_assertion_replay_matrix_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorCertificationPreflightRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_connector_certification_preflight_runtime_status.v1',
            'status_complete' => 'complete_connector_certification_preflight_runtime_coverage_external_cutover_blocked',
            'status_missing' => 'missing_connector_certification_preflight_runtime_coverage',
            'hash_key' => 'connector_certification_preflight_runtime_status_hash',
            'completed_key' => 'completed_connector_certification_preflight_flow_count',
            'missing_key' => 'missing_connector_certification_preflight_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'connector_certification_preflight_runtime_bound',
                'connector_adapter_contracts_bound',
                'connector_auth_boundaries_bound',
                'connector_sandbox_probes_bound',
                'connector_contract_tests_bound',
                'connector_data_lineage_bound',
                'connector_replay_fixtures_bound',
                'connector_slo_failure_modes_bound',
                'production_preflight_contracts_bound',
                'flow_cutover_matrix_bound',
                'production_readiness_evidence_bound',
                'connector_preflight_external_cutover_blocked',
                ['hash', 'connector_certification_preflight_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'connector_adapter_contracts_bound_count' => 'connector_adapter_contracts_bound',
                'connector_auth_boundaries_bound_count' => 'connector_auth_boundaries_bound',
                'connector_sandbox_probes_bound_count' => 'connector_sandbox_probes_bound',
                'connector_contract_tests_bound_count' => 'connector_contract_tests_bound',
                'connector_data_lineage_bound_count' => 'connector_data_lineage_bound',
                'connector_replay_fixtures_bound_count' => 'connector_replay_fixtures_bound',
                'connector_slo_failure_modes_bound_count' => 'connector_slo_failure_modes_bound',
                'production_preflight_contracts_bound_count' => 'production_preflight_contracts_bound',
                'flow_cutover_matrix_bound_count' => 'flow_cutover_matrix_bound',
                'production_readiness_evidence_bound_count' => 'production_readiness_evidence_bound',
                'connector_certification_observability_bound_count' => 'connector_certification_observability_bound',
                'cutover_observability_bound_count' => 'cutover_observability_bound',
                'connector_preflight_external_cutover_blocked_count' => 'connector_preflight_external_cutover_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'write_or_paid_mode_allowed_by_default' => false,
                'connector_runtime_requires_certification_preflight_cutover_evidence_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function commandCenterControlTowerRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_command_center_control_tower_runtime_status.v1',
            'status_complete' => 'complete_command_center_control_tower_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_command_center_control_tower_runtime_coverage',
            'hash_key' => 'command_center_control_tower_runtime_status_hash',
            'completed_key' => 'completed_command_center_control_tower_flow_count',
            'missing_key' => 'missing_command_center_control_tower_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'command_center_control_tower_runtime_bound',
                'control_tower_lane_bound',
                'flow_command_card_bound',
                'incident_exception_desk_bound',
                'change_window_release_bound',
                'operator_console_views_bound',
                'command_center_cells_bound',
                'connector_panels_bound',
                'work_product_factory_bound',
                'command_center_kpis_bound',
                'command_center_external_action_blocked',
                ['hash', 'command_center_control_tower_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'control_tower_lane_bound_count' => 'control_tower_lane_bound',
                'flow_command_card_bound_count' => 'flow_command_card_bound',
                'incident_exception_desk_bound_count' => 'incident_exception_desk_bound',
                'change_window_release_bound_count' => 'change_window_release_bound',
                'operator_console_views_bound_count' => 'operator_console_views_bound',
                'command_center_cells_bound_count' => 'command_center_cells_bound',
                'connector_panels_bound_count' => 'connector_panels_bound',
                'work_product_factory_bound_count' => 'work_product_factory_bound',
                'command_center_kpis_bound_count' => 'command_center_kpis_bound',
                'command_center_external_action_blocked_count' => 'command_center_external_action_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'command_center_runtime_requires_lane_card_incident_change_console_cells_connectors_factory_kpis_and_human_interrupts' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_operational_dress_rehearsal_runtime_status.v1',
            'status_complete' => 'complete_operational_dress_rehearsal_runtime_coverage_external_mutation_blocked',
            'status_missing' => 'missing_operational_dress_rehearsal_runtime_coverage',
            'hash_key' => 'operational_dress_rehearsal_runtime_status_hash',
            'completed_key' => 'completed_operational_dress_rehearsal_flow_count',
            'missing_key' => 'missing_operational_dress_rehearsal_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'operational_dress_rehearsal_runtime_bound',
                'rehearsal_runbook_bound',
                'live_read_probe_plan_bound',
                'operator_acceptance_packet_bound',
                'rollback_drill_bound',
                'promotion_evidence_bound',
                'dress_rehearsal_observability_bound',
                'dress_rehearsal_external_mutation_blocked',
                ['hash', 'operational_dress_rehearsal_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'rehearsal_runbook_bound_count' => 'rehearsal_runbook_bound',
                'live_read_probe_plan_bound_count' => 'live_read_probe_plan_bound',
                'operator_acceptance_packet_bound_count' => 'operator_acceptance_packet_bound',
                'rollback_drill_bound_count' => 'rollback_drill_bound',
                'promotion_evidence_bound_count' => 'promotion_evidence_bound',
                'dress_rehearsal_observability_bound_count' => 'dress_rehearsal_observability_bound',
                'dress_rehearsal_external_mutation_blocked_count' => 'dress_rehearsal_external_mutation_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'production_cutover_without_signed_acceptance_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'operational_rehearsal_requires_runbook_live_probe_acceptance_rollback_promotion_evidence_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function semanticOperatingGraphRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_semantic_operating_graph_runtime_status.v1',
            'status_complete' => 'complete_semantic_operating_graph_runtime_coverage_external_mutation_blocked',
            'status_missing' => 'missing_semantic_operating_graph_runtime_coverage',
            'hash_key' => 'semantic_operating_graph_runtime_status_hash',
            'completed_key' => 'completed_semantic_graph_flow_count',
            'missing_key' => 'missing_semantic_graph_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'semantic_operating_graph_runtime_bound',
                'semantic_graph_bound',
                'semantic_node_catalog_bound',
                'semantic_flow_edge_bound',
                'semantic_operating_views_bound',
                'semantic_drift_rules_bound',
                'semantic_export_contract_bound',
                'semantic_graph_observability_bound',
                'semantic_graph_secret_export_blocked',
                ['hash', 'semantic_operating_graph_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'semantic_graph_bound_count' => 'semantic_graph_bound',
                'semantic_node_catalog_bound_count' => 'semantic_node_catalog_bound',
                'semantic_flow_edge_bound_count' => 'semantic_flow_edge_bound',
                'semantic_operating_views_bound_count' => 'semantic_operating_views_bound',
                'semantic_drift_rules_bound_count' => 'semantic_drift_rules_bound',
                'semantic_export_contract_bound_count' => 'semantic_export_contract_bound',
                'semantic_graph_observability_bound_count' => 'semantic_graph_observability_bound',
                'semantic_graph_secret_export_blocked_count' => 'semantic_graph_secret_export_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_graph_mutation_allowed' => false,
                'raw_secret_or_sensitive_payload_export_allowed' => false,
                'semantic_graph_runtime_requires_node_catalog_flow_edges_views_drift_rules_export_contract_and_observability' => true,
                'stale_or_missing_graph_edge_blocks_autonomy_claim' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainSolutionPlaybookRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_solution_playbook_runtime_status.v1',
            'status_complete' => 'complete_domain_solution_playbook_runtime_coverage_external_mutation_blocked',
            'status_missing' => 'missing_domain_solution_playbook_runtime_coverage',
            'hash_key' => 'domain_solution_playbook_runtime_status_hash',
            'completed_key' => 'completed_domain_solution_playbook_flow_count',
            'missing_key' => 'missing_domain_solution_playbook_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_solution_playbook_runtime_bound',
                'solution_playbook_bound',
                'source_pack_bound',
                'domain_data_plane_bound',
                'execution_path_bound',
                'tooling_contract_bound',
                'domain_review_contract_bound',
                'benchmark_contract_bound',
                'handoff_contract_bound',
                'external_mutation_blocked',
                ['hash', 'domain_solution_playbook_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'solution_playbook_bound_count' => 'solution_playbook_bound',
                'source_pack_bound_count' => 'source_pack_bound',
                'domain_data_plane_bound_count' => 'domain_data_plane_bound',
                'execution_path_bound_count' => 'execution_path_bound',
                'tooling_contract_bound_count' => 'tooling_contract_bound',
                'domain_review_contract_bound_count' => 'domain_review_contract_bound',
                'benchmark_contract_bound_count' => 'benchmark_contract_bound',
                'handoff_contract_bound_count' => 'handoff_contract_bound',
                'external_mutation_blocked_count' => 'external_mutation_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_data_mutation_allowed' => false,
                'external_delivery_allowed' => false,
                'domain_solution_runtime_requires_playbook_source_pack_data_plane_tool_contract_review_benchmark_and_handoff' => true,
                'real_connector_activation_requires_signed_scope_credentials_and_green_probe' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainOperatingDepthRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_operating_depth_runtime_status.v1',
            'status_complete' => 'complete_domain_operating_depth_runtime_coverage_external_effects_blocked',
            'status_missing' => 'missing_domain_operating_depth_runtime_coverage',
            'hash_key' => 'domain_operating_depth_runtime_status_hash',
            'completed_key' => 'completed_domain_operating_depth_flow_count',
            'missing_key' => 'missing_domain_operating_depth_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_operating_depth_runtime_bound',
                'domain_depth_packet_bound',
                'domain_depth_skills_bound',
                'domain_depth_connector_refs_bound',
                'domain_depth_subagents_bound',
                'domain_depth_source_refs_bound',
                'domain_depth_enterprise_system_refs_bound',
                'domain_depth_data_product_refs_bound',
                'domain_depth_quality_contract_bound',
                'domain_depth_operating_controls_bound',
                'domain_depth_external_effects_blocked',
                ['hash', 'domain_operating_depth_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'depth_packet_bound_count' => 'domain_depth_packet_bound',
                'skills_bound_count' => 'domain_depth_skills_bound',
                'connector_refs_bound_count' => 'domain_depth_connector_refs_bound',
                'subagents_bound_count' => 'domain_depth_subagents_bound',
                'source_refs_bound_count' => 'domain_depth_source_refs_bound',
                'enterprise_system_refs_bound_count' => 'domain_depth_enterprise_system_refs_bound',
                'data_product_refs_bound_count' => 'domain_depth_data_product_refs_bound',
                'quality_contract_bound_count' => 'domain_depth_quality_contract_bound',
                'operating_controls_bound_count' => 'domain_depth_operating_controls_bound',
                'external_effects_blocked_count' => 'domain_depth_external_effects_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'domain_depth_runtime_requires_skills_connectors_subagents_sources_systems_data_products_quality_contract_and_controls' => true,
                'operator_mandate_required_for_any_external_effect' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function domainAgentWorkforceRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_domain_agent_workforce_runtime_status.v1',
            'status_complete' => 'complete_domain_agent_workforce_runtime_coverage_external_effects_blocked',
            'status_missing' => 'missing_domain_agent_workforce_runtime_coverage',
            'hash_key' => 'domain_agent_workforce_runtime_status_hash',
            'completed_key' => 'completed_domain_agent_workforce_flow_count',
            'missing_key' => 'missing_domain_agent_workforce_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'domain_agent_workforce_runtime_bound',
                'domain_agent_workforce_crew_bound',
                'domain_agent_workforce_skills_bound',
                'domain_agent_workforce_connector_refs_bound',
                'domain_agent_workforce_subagents_bound',
                'domain_agent_workforce_source_refs_bound',
                'domain_agent_workforce_work_surface_bound',
                'domain_agent_workforce_managed_controls_bound',
                'domain_agent_workforce_work_queue_bound',
                'domain_agent_workforce_acceptance_bound',
                'domain_agent_workforce_external_effects_blocked',
                ['hash', 'domain_agent_workforce_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'crew_bound_count' => 'domain_agent_workforce_crew_bound',
                'skills_bound_count' => 'domain_agent_workforce_skills_bound',
                'connector_refs_bound_count' => 'domain_agent_workforce_connector_refs_bound',
                'subagents_bound_count' => 'domain_agent_workforce_subagents_bound',
                'source_refs_bound_count' => 'domain_agent_workforce_source_refs_bound',
                'work_surface_bound_count' => 'domain_agent_workforce_work_surface_bound',
                'managed_controls_bound_count' => 'domain_agent_workforce_managed_controls_bound',
                'work_queue_bound_count' => 'domain_agent_workforce_work_queue_bound',
                'acceptance_bound_count' => 'domain_agent_workforce_acceptance_bound',
                'external_effects_blocked_count' => 'domain_agent_workforce_external_effects_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'domain_agent_workforce_requires_crews_skills_connectors_subagents_work_surfaces_permissions_vault_audit_queue_acceptance' => true,
                'operator_mandate_required_for_any_external_effect' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function agentToolchainRuntimeStatus(?string $companyId = null): array
    {
        return $this->agentToolchainRuntimeStatusPayload($companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function flowExecutionFoundationRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_flow_execution_foundation_runtime_status.v1',
            'status_complete' => 'complete_flow_execution_foundation_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_flow_execution_foundation_runtime_coverage',
            'hash_key' => 'flow_execution_foundation_runtime_status_hash',
            'completed_key' => 'completed_flow_execution_foundation_flow_count',
            'missing_key' => 'missing_flow_execution_foundation_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'flow_execution_foundation_runtime_bound',
                ['hash', 'flow_execution_foundation_attestation_hash'],
                ['internal', 'external_side_effects'],
                'flow_execution_orchestration_stack_bound',
                'flow_execution_runbook_bound',
                'flow_execution_connector_backplane_bound',
                'flow_execution_runbook_observability_bound',
                'flow_execution_implementation_stack_bound',
                'flow_execution_executable_packet_bound',
                'flow_execution_agent_tool_routing_bound',
                'flow_execution_artifact_io_contract_bound',
                'flow_execution_supervision_shadow_gate_bound',
                'flow_execution_connector_runtime_adapters_bound',
                'flow_execution_runtime_event_outbox_bound',
                'flow_execution_implementation_observability_bound',
                'flow_execution_fixture_simulation_stack_bound',
                'flow_execution_canonical_fixture_bound',
                'flow_execution_expected_trace_bound',
                'flow_execution_quality_assertion_suite_bound',
                'flow_execution_failure_injection_bound',
                'flow_execution_dry_run_command_bound',
                'flow_execution_simulation_promotion_gates_bound',
                'flow_execution_simulation_observability_bound',
                'flow_execution_external_actions_blocked',
            ],
            'counts' => [
                'orchestration_stack_bound_count' => 'flow_execution_orchestration_stack_bound',
                'runbook_bound_count' => 'flow_execution_runbook_bound',
                'connector_backplane_bound_count' => 'flow_execution_connector_backplane_bound',
                'runbook_observability_bound_count' => 'flow_execution_runbook_observability_bound',
                'implementation_stack_bound_count' => 'flow_execution_implementation_stack_bound',
                'executable_packet_bound_count' => 'flow_execution_executable_packet_bound',
                'agent_tool_routing_bound_count' => 'flow_execution_agent_tool_routing_bound',
                'artifact_io_contract_bound_count' => 'flow_execution_artifact_io_contract_bound',
                'supervision_shadow_gate_bound_count' => 'flow_execution_supervision_shadow_gate_bound',
                'connector_runtime_adapters_bound_count' => 'flow_execution_connector_runtime_adapters_bound',
                'runtime_event_outbox_bound_count' => 'flow_execution_runtime_event_outbox_bound',
                'implementation_observability_bound_count' => 'flow_execution_implementation_observability_bound',
                'fixture_simulation_stack_bound_count' => 'flow_execution_fixture_simulation_stack_bound',
                'canonical_fixture_bound_count' => 'flow_execution_canonical_fixture_bound',
                'expected_trace_bound_count' => 'flow_execution_expected_trace_bound',
                'quality_assertion_suite_bound_count' => 'flow_execution_quality_assertion_suite_bound',
                'failure_injection_bound_count' => 'flow_execution_failure_injection_bound',
                'dry_run_command_bound_count' => 'flow_execution_dry_run_command_bound',
                'simulation_promotion_gates_bound_count' => 'flow_execution_simulation_promotion_gates_bound',
                'simulation_observability_bound_count' => 'flow_execution_simulation_observability_bound',
                'external_actions_blocked_count' => 'flow_execution_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'ungoverned_external_side_effects_allowed' => false,
                'operator_checkpoint_required_before_external_action' => true,
                'flow_execution_foundation_requires_runbook_implementation_fixtures_simulation_gates_outbox_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    private function agentToolchainRuntimeStatusPayload(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_agent_toolchain_runtime_status.v1',
            'status_complete' => 'complete_agent_toolchain_runtime_coverage_external_blocked',
            'status_missing' => 'missing_agent_toolchain_runtime_coverage',
            'hash_key' => 'agent_toolchain_runtime_status_hash',
            'completed_key' => 'completed_agent_toolchain_flow_count',
            'missing_key' => 'missing_agent_toolchain_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'agent_toolchain_runtime_bound',
                'framework_source_catalog_bound',
                'flow_toolkit_assignment_bound',
                'agent_repository_epic_bound',
                'guardrails_runtime_bound',
                'handoffs_runtime_bound',
                'tracing_runtime_bound',
                'durable_state_runtime_bound',
                'human_in_loop_runtime_bound',
                ['hash', 'agent_toolchain_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'framework_source_catalog_bound_count' => 'framework_source_catalog_bound',
                'flow_toolkit_assignment_bound_count' => 'flow_toolkit_assignment_bound',
                'agent_repository_epic_bound_count' => 'agent_repository_epic_bound',
                'guardrails_runtime_bound_count' => 'guardrails_runtime_bound',
                'handoffs_runtime_bound_count' => 'handoffs_runtime_bound',
                'tracing_runtime_bound_count' => 'tracing_runtime_bound',
                'durable_state_runtime_bound_count' => 'durable_state_runtime_bound',
                'human_in_loop_runtime_bound_count' => 'human_in_loop_runtime_bound',
            ],
            'policy' => [
                'reference_patterns' => [
                    'openai_agents_sdk_tools_handoffs_guardrails_tracing',
                    'crewai_role_tools_memory_knowledge_collaboration',
                    'langgraph_durable_state_and_human_in_loop',
                    'mcp_connector_workbench',
                    'opentelemetry_style_traceability',
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'runtime_use_requires_local_contract_tests_version_pins_guardrails_handoffs_tracing_and_human_checkpoints' => true,
                'operator_mandate_required_for_external_tool_side_effect' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function workforceCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_workforce_capacity_runtime_status.v1',
            'status_complete' => 'complete_workforce_capacity_runtime_coverage_external_staffing_changes_blocked',
            'status_missing' => 'missing_workforce_capacity_runtime_coverage',
            'hash_key' => 'workforce_capacity_runtime_status_hash',
            'completed_key' => 'completed_workforce_capacity_flow_count',
            'missing_key' => 'missing_workforce_capacity_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'workforce_capacity_runtime_bound',
                'workforce_stack_bound',
                'workforce_org_model_bound',
                'workforce_agent_capacity_plan_bound',
                'workforce_flow_staffing_bound',
                'workforce_training_enablement_bound',
                'workforce_succession_continuity_bound',
                'workforce_capacity_observability_bound',
                'workforce_capacity_external_changes_blocked',
                ['hash', 'workforce_capacity_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'workforce_stack_bound_count' => 'workforce_stack_bound',
                'org_model_bound_count' => 'workforce_org_model_bound',
                'agent_capacity_plan_bound_count' => 'workforce_agent_capacity_plan_bound',
                'flow_staffing_bound_count' => 'workforce_flow_staffing_bound',
                'training_enablement_bound_count' => 'workforce_training_enablement_bound',
                'succession_continuity_bound_count' => 'workforce_succession_continuity_bound',
                'capacity_observability_bound_count' => 'workforce_capacity_observability_bound',
                'external_changes_blocked_count' => 'workforce_capacity_external_changes_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'single_agent_bottleneck_allowed' => false,
                'external_unreviewed_staffing_change_allowed' => false,
                'workforce_runtime_requires_capacity_plan_flow_staffing_training_succession_and_observability' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDependencyRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_portfolio_dependency_runtime_status.v1',
            'status_complete' => 'complete_portfolio_dependency_runtime_coverage_external_dependency_actions_blocked',
            'status_missing' => 'missing_portfolio_dependency_runtime_coverage',
            'hash_key' => 'portfolio_dependency_runtime_status_hash',
            'completed_key' => 'completed_portfolio_dependency_flow_count',
            'missing_key' => 'missing_portfolio_dependency_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'portfolio_dependency_runtime_bound',
                'portfolio_dependency_stack_bound',
                'portfolio_dependency_role_bound',
                'portfolio_dependency_intake_contract_bound',
                'portfolio_dependency_upstream_map_bound',
                'portfolio_dependency_integration_map_bound',
                'portfolio_dependency_flow_routing_bound',
                'portfolio_dependency_escalation_conflict_bound',
                'portfolio_dependency_reporting_bound',
                'portfolio_dependency_external_actions_blocked',
                ['hash', 'portfolio_dependency_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'dependency_stack_bound_count' => 'portfolio_dependency_stack_bound',
                'role_bound_count' => 'portfolio_dependency_role_bound',
                'intake_contract_bound_count' => 'portfolio_dependency_intake_contract_bound',
                'upstream_map_bound_count' => 'portfolio_dependency_upstream_map_bound',
                'integration_map_bound_count' => 'portfolio_dependency_integration_map_bound',
                'flow_routing_bound_count' => 'portfolio_dependency_flow_routing_bound',
                'escalation_conflict_bound_count' => 'portfolio_dependency_escalation_conflict_bound',
                'reporting_bound_count' => 'portfolio_dependency_reporting_bound',
                'external_actions_blocked_count' => 'portfolio_dependency_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'external_spend_publish_write_trade_or_transfer_allowed' => false,
                'cross_company_dependency_without_typed_handoff_allowed' => false,
                'unresolved_conflict_external_side_effect_allowed' => false,
                'portfolio_dependency_runtime_requires_role_intake_maps_flow_routing_escalation_and_reporting' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDossierRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_operational_dossier_runtime_status.v1',
            'status_complete' => 'complete_operational_dossier_runtime_coverage_external_blocked',
            'status_missing' => 'missing_operational_dossier_runtime_coverage',
            'hash_key' => 'operational_dossier_runtime_status_hash',
            'completed_key' => 'completed_operational_dossier_flow_count',
            'missing_key' => 'missing_operational_dossier_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'operational_dossier_runtime_bound',
                'operational_dossier_bound',
                'dossier_evidence_spine_bound',
                'dossier_control_plane_bound',
                'dossier_decision_packet_bound',
                'dossier_promotion_path_bound',
                'dossier_scorecard_bound',
                'dossier_external_blocked',
                ['hash', 'operational_dossier_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'dossier_bound_count' => 'operational_dossier_bound',
                'evidence_spine_bound_count' => 'dossier_evidence_spine_bound',
                'control_plane_bound_count' => 'dossier_control_plane_bound',
                'decision_packet_bound_count' => 'dossier_decision_packet_bound',
                'promotion_path_bound_count' => 'dossier_promotion_path_bound',
                'scorecard_bound_count' => 'dossier_scorecard_bound',
                'external_blocked_count' => 'dossier_external_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
                'operational_dossier_requires_evidence_controls_decision_packet_promotion_path_and_scorecard' => true,
                'operator_mandate_required_for_any_external_promotion' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalOutcomeRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $cell): string => (string) ($cell['flow_id'] ?? ''),
                (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []),
            ));
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (bool) ($record['operational_outcome_value_proxy_bound'] ?? false)
                    && (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)
                    && (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)
                    && (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)
                    && (int) ($record['operational_outcome_evidence_ref_count'] ?? 0) >= 4
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));

            $row = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operational_outcome_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operational_outcome_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'operational_outcome_ledger_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false))),
                'value_proxy_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_value_proxy_bound'] ?? false))),
                'acceptance_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false))),
                'risk_scorecard_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false))),
                'next_cycle_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_next_cycle_bound'] ?? false))),
                'evidence_ref_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operational_outcome_evidence_ref_count'] ?? 0), $companyRecords)),
            ];
            $row['operational_outcome_runtime_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operational_outcome_flow_count'], $companies));

        return AtlasEnvelope::seal([
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_operational_outcome_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_operational_outcome_runtime_coverage_external_blocked'
                : 'missing_operational_outcome_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_operational_outcome_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'operational_outcome_ledger_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operational_outcome_ledger_bound_count'], $companies)),
                'value_proxy_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['value_proxy_bound_count'], $companies)),
                'acceptance_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_bound_count'], $companies)),
                'risk_scorecard_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['risk_scorecard_bound_count'], $companies)),
                'next_cycle_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['next_cycle_bound_count'], $companies)),
                'evidence_ref_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evidence_ref_count'], $companies)),
                'external_value_claim_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_value_claim_allowed'] ?? false))),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operational_outcome_runtime_requires_ledger_value_proxy_acceptance_risk_next_cycle_and_evidence_refs_per_flow' => true,
                'real_world_outcome_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ], 'operational_outcome_runtime_status_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function autonomyPromotionRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_autonomy_promotion_runtime_status.v1',
            'status_complete' => 'complete_autonomy_promotion_runtime_coverage_limited_external_autonomy_blocked',
            'status_missing' => 'missing_autonomy_promotion_runtime_coverage',
            'hash_key' => 'autonomy_promotion_runtime_status_hash',
            'completed_key' => 'completed_autonomy_promotion_flow_count',
            'missing_key' => 'missing_autonomy_promotion_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'autonomy_promotion_runtime_bound',
                'autonomy_ladder_bound',
                'autonomy_fixture_level_bound',
                'autonomy_shadow_level_bound',
                'autonomy_supervised_internal_level_bound',
                'autonomy_supervised_external_packet_bound',
                'autonomy_limited_external_autonomy_blocked',
                'autonomy_evidence_spine_bound',
                'autonomy_rollback_reconciliation_bound',
                ['hash', 'autonomy_promotion_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'autonomy_ladder_bound_count' => 'autonomy_ladder_bound',
                'supervised_external_packet_bound_count' => 'autonomy_supervised_external_packet_bound',
                'limited_external_autonomy_blocked_count' => 'autonomy_limited_external_autonomy_blocked',
                'evidence_spine_bound_count' => 'autonomy_evidence_spine_bound',
                'rollback_reconciliation_bound_count' => 'autonomy_rollback_reconciliation_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'limited_external_autonomy_allowed' => false,
                'autonomy_claim_without_live_evidence_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_limited_external_autonomy' => true,
                'second_reviewer_required_for_limited_external_autonomy' => true,
                'autonomy_promotion_requires_fixture_shadow_supervised_packet_evidence_rollback_and_reconciliation' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function holdingOutcomeScorecardStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $expectedFlowCount = count((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []));
            $companyRecords = $this->records->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['operational_outcome_value_proxy_bound'] ?? false)
                    && (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)
                    && (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)
                    && (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)
                    && (bool) ($record['external_value_claim_allowed'] ?? true) === false
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlowCount = count(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));
            $kpiCount = array_sum(array_map(static fn (array $record): int => (int) ($record['operational_outcome_kpi_count'] ?? 0), $outcomeRecords));
            $acceptanceContractCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_acceptance_contract_bound'] ?? false)));
            $riskScorecardCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_risk_scorecard_bound'] ?? false)));
            $nextCycleCount = count(array_filter($outcomeRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_next_cycle_bound'] ?? false)));
            $coverage = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

            $row = [
                'schema' => 'atlas.ai.company.holding_outcome_scorecard.v1',
                'company_id' => $currentCompanyId,
                'expected_flow_count' => $expectedFlowCount,
                'completed_outcome_flow_count' => $completedFlowCount,
                'operational_outcome_ledger_count' => count($outcomeRecords),
                'measured_kpi_count' => $kpiCount,
                'acceptance_contract_count' => $acceptanceContractCount,
                'risk_scorecard_count' => $riskScorecardCount,
                'next_cycle_count' => $nextCycleCount,
                'coverage_rate' => $coverage,
                'score' => round($coverage * 10, 2),
                'external_value_claim_allowed' => false,
                'external_side_effects' => false,
                'ready' => $expectedFlowCount > 0
                    && $expectedFlowCount === $completedFlowCount
                    && $kpiCount >= $expectedFlowCount
                    && $acceptanceContractCount >= $expectedFlowCount
                    && $riskScorecardCount >= $expectedFlowCount
                    && $nextCycleCount >= $expectedFlowCount,
                'missing_flows' => array_values(array_diff(
                    array_values(array_map(
                        static fn (array $cell): string => (string) ($cell['flow_id'] ?? ''),
                        (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', []),
                    )),
                    array_values(array_map(static fn (array $record): string => (string) ($record['flow_id'] ?? ''), $outcomeRecords)),
                )),
            ];
            $row['scorecard_hash'] = MissionCanonicalHash::sha256($row);

            $companies[] = $row;
            array_push($records, ...$companyRecords);
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['ready'] ?? false)));
        $companyCount = count($companies);
        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_outcome_flow_count'], $companies));

        $payload = [
            'ok' => $companyCount > 0 && $readyCompanyCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_holding_outcome_scorecard_status.v1',
            'status' => $companyCount > 0 && $readyCompanyCount === $companyCount
                ? 'holding_outcome_scorecard_ready_external_claim_blocked'
                : 'holding_outcome_scorecard_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_company_count' => $readyCompanyCount,
                'expected_flow_count' => $expectedFlowCount,
                'completed_outcome_flow_count' => $completedFlowCount,
                'operational_outcome_ledger_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operational_outcome_ledger_count'], $companies)),
                'measured_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['measured_kpi_count'], $companies)),
                'acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_count'], $companies)),
                'risk_scorecard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['risk_scorecard_count'], $companies)),
                'next_cycle_count' => array_sum(array_map(static fn (array $company): int => (int) $company['next_cycle_count'], $companies)),
                'average_score' => $companyCount > 0 ? round(array_sum(array_map(static fn (array $company): float => (float) $company['score'], $companies)) / $companyCount, 2) : 0.0,
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
                'external_value_claim_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_value_claim_allowed'] ?? false))),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
            ],
            'companies' => $companies,
            'policy' => [
                'external_execution_allowed' => false,
                'external_value_claim_allowed' => false,
                'scorecard_source' => 'internal_operational_outcome_ledgers_with_acceptance_risk_and_next_cycle_evidence',
                'real_business_value_claim_requires_external_evidence_operator_acceptance_and_signed_scope' => true,
            ],
        ];
        $payload['holding_outcome_scorecard_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDecisionPacketStatus(?string $companyId = null): array
    {
        $scorecards = $this->holdingOutcomeScorecardStatus($companyId);
        $companyPackets = array_values(array_map(
            static function (array $company): array {
                $score = (float) ($company['score'] ?? 0.0);
                $expected = (int) ($company['expected_flow_count'] ?? 0);
                $completed = (int) ($company['completed_outcome_flow_count'] ?? 0);
                $measuredKpis = (int) ($company['measured_kpi_count'] ?? 0);

                $packet = [
                    'schema' => 'atlas.ai.company.portfolio_decision_packet.v1',
                    'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                    'scorecard_hash' => (string) ($company['scorecard_hash'] ?? ''),
                    'score' => $score,
                    'decision_recommendation' => $score >= 9.0 ? 'scale_internal_supervised_capacity' : 'repair_before_scale',
                    'allocation_class' => $score >= 9.0 ? 'increase_internal_priority' : 'hold_and_repair',
                    'expected_flow_count' => $expected,
                    'completed_outcome_flow_count' => $completed,
                    'measured_kpi_count' => $measuredKpis,
                    'required_operator_decisions' => [
                        'approve_or_reject_internal_capacity_shift',
                        'approve_shadow_or_supervised_external_probe_scope',
                        'confirm_no_real_capital_commitment_without_signed_packet',
                    ],
                    'blocked_without_signed_packet' => ['real_capital_commitment', 'external_spend', 'customer_commitment', 'vendor_procurement', 'public_claim'],
                    'external_execution_allowed' => false,
                    'real_capital_action_allowed' => false,
                    'ready' => (bool) ($company['ready'] ?? false)
                        && $score >= 9.0
                        && $expected > 0
                        && $expected === $completed
                        && $measuredKpis >= $expected
                        && (bool) ($company['external_value_claim_allowed'] ?? true) === false
                        && (bool) ($company['external_side_effects'] ?? true) === false,
                ];
                $packet['decision_packet_hash'] = MissionCanonicalHash::sha256($packet);

                return $packet;
            },
            (array) ($scorecards['companies'] ?? []),
        ));

        $readyPacketCount = count(array_filter($companyPackets, static fn (array $packet): bool => (bool) ($packet['ready'] ?? false)));
        $companyCount = count($companyPackets);

        $payload = [
            'ok' => (bool) ($scorecards['ok'] ?? false) && $companyCount > 0 && $readyPacketCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_portfolio_decision_packet_status.v1',
            'status' => (bool) ($scorecards['ok'] ?? false) && $companyCount > 0 && $readyPacketCount === $companyCount
                ? 'portfolio_decision_packet_ready_external_capital_blocked'
                : 'portfolio_decision_packet_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_decision_packet_count' => $readyPacketCount,
                'scale_internal_supervised_capacity_count' => count(array_filter(
                    $companyPackets,
                    static fn (array $packet): bool => ($packet['decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity',
                )),
                'blocked_real_capital_action_count' => count(array_filter(
                    $companyPackets,
                    static fn (array $packet): bool => (bool) ($packet['real_capital_action_allowed'] ?? true) === false,
                )),
                'average_score' => (float) data_get($scorecards, 'summary.average_score', 0.0),
                'external_value_claim_count' => (int) data_get($scorecards, 'summary.external_value_claim_count', 0),
                'external_side_effect_count' => (int) data_get($scorecards, 'summary.external_side_effect_count', 0),
            ],
            'scorecard_status_hash' => (string) ($scorecards['holding_outcome_scorecard_status_hash'] ?? ''),
            'companies' => $companyPackets,
            'policy' => [
                'external_execution_allowed' => false,
                'real_capital_action_allowed' => false,
                'operator_signed_packet_required_for_external_spend_or_capital' => true,
                'decision_packet_source' => 'holding_outcome_scorecard_status',
            ],
        ];
        $payload['portfolio_decision_packet_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companyBoardOperatingReviewStatus(?string $companyId = null): array
    {
        $scorecards = $this->holdingOutcomeScorecardStatus($companyId);
        $portfolio = $this->portfolioDecisionPacketStatus($companyId);
        $businessPackets = $this->businessOperatingPacketRuntimeStatus($companyId);
        $commandCenter = $this->commandCenterControlTowerRuntimeStatus($companyId);

        $portfolioByCompany = [];
        foreach ((array) ($portfolio['companies'] ?? []) as $company) {
            $portfolioByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $businessByCompany = [];
        foreach ((array) ($businessPackets['companies'] ?? []) as $company) {
            $businessByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $commandCenterByCompany = [];
        foreach ((array) ($commandCenter['companies'] ?? []) as $company) {
            $commandCenterByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }

        $companies = array_values(array_map(
            static function (array $scorecard) use ($portfolioByCompany, $businessByCompany, $commandCenterByCompany): array {
                $companyId = (string) ($scorecard['company_id'] ?? 'unknown');
                $portfolioPacket = (array) ($portfolioByCompany[$companyId] ?? []);
                $businessPacket = (array) ($businessByCompany[$companyId] ?? []);
                $commandCenterPacket = (array) ($commandCenterByCompany[$companyId] ?? []);
                $expectedFlows = (int) ($scorecard['expected_flow_count'] ?? 0);
                $completedFlows = (int) ($scorecard['completed_outcome_flow_count'] ?? 0);
                $acceptanceContractCount = (int) ($scorecard['acceptance_contract_count'] ?? 0);
                $riskScorecardCount = (int) ($scorecard['risk_scorecard_count'] ?? 0);
                $nextCycleCount = (int) ($scorecard['next_cycle_count'] ?? 0);
                $requiredOperatorDecisions = array_values((array) ($portfolioPacket['required_operator_decisions'] ?? []));
                $businessReady = $expectedFlows > 0
                    && (int) ($businessPacket['completed_business_operating_packet_flow_count'] ?? 0) === $expectedFlows;
                $commandReady = $expectedFlows > 0
                    && (int) ($commandCenterPacket['completed_command_center_control_tower_flow_count'] ?? 0) === $expectedFlows;
                $improvementBacklogReady = $expectedFlows > 0
                    && $acceptanceContractCount >= $expectedFlows
                    && $riskScorecardCount >= $expectedFlows
                    && $nextCycleCount >= $expectedFlows
                    && count($requiredOperatorDecisions) >= 3;

                $row = [
                    'schema' => 'atlas.ai.company.board_operating_review_packet.v1',
                    'company_id' => $companyId,
                    'scorecard_hash' => (string) ($scorecard['scorecard_hash'] ?? ''),
                    'portfolio_decision_packet_hash' => (string) ($portfolioPacket['decision_packet_hash'] ?? ''),
                    'score' => (float) ($scorecard['score'] ?? 0.0),
                    'expected_flow_count' => $expectedFlows,
                    'completed_outcome_flow_count' => $completedFlows,
                    'business_operating_packet_flow_count' => (int) ($businessPacket['completed_business_operating_packet_flow_count'] ?? 0),
                    'command_center_flow_count' => (int) ($commandCenterPacket['completed_command_center_control_tower_flow_count'] ?? 0),
                    'measured_kpi_count' => (int) ($scorecard['measured_kpi_count'] ?? 0),
                    'acceptance_contract_count' => $acceptanceContractCount,
                    'risk_scorecard_count' => $riskScorecardCount,
                    'next_cycle_count' => $nextCycleCount,
                    'decision_recommendation' => (string) ($portfolioPacket['decision_recommendation'] ?? 'repair_before_scale'),
                    'review_sections' => [
                        'operational_outcomes',
                        'business_operating_packets',
                        'command_center_control_tower',
                        'portfolio_decision',
                        'continuous_improvement_backlog',
                        'risk_and_external_commitment_blocks',
                        'next_operator_decisions',
                    ],
                    'required_operator_decisions' => $requiredOperatorDecisions,
                    'required_operator_decision_count' => count($requiredOperatorDecisions),
                    'continuous_improvement_backlog' => [
                        'acceptance_followup_count' => $acceptanceContractCount,
                        'risk_remediation_count' => $riskScorecardCount,
                        'next_cycle_action_count' => $nextCycleCount,
                        'board_decision_action_count' => count($requiredOperatorDecisions),
                        'bound_to_outcome_scorecard' => $improvementBacklogReady,
                        'external_commitments_allowed' => false,
                    ],
                    'board_review_gates' => [
                        'scorecard_ready' => (bool) ($scorecard['ready'] ?? false),
                        'portfolio_decision_ready' => (bool) ($portfolioPacket['ready'] ?? false),
                        'business_operating_packet_ready' => $businessReady,
                        'command_center_ready' => $commandReady,
                        'continuous_improvement_backlog_ready' => $improvementBacklogReady,
                        'external_value_claim_blocked' => (bool) ($scorecard['external_value_claim_allowed'] ?? true) === false,
                        'external_side_effects_blocked' => (bool) ($scorecard['external_side_effects'] ?? true) === false,
                    ],
                    'external_commitment_controls' => [
                        'external_execution_allowed' => false,
                        'external_customer_commitment_allowed' => false,
                        'external_billing_allowed' => false,
                        'real_capital_action_allowed' => false,
                        'public_claim_allowed' => false,
                    ],
                    'ready' => (bool) ($scorecard['ready'] ?? false)
                        && (bool) ($portfolioPacket['ready'] ?? false)
                        && $businessReady
                        && $commandReady
                        && $improvementBacklogReady
                        && (bool) ($scorecard['external_value_claim_allowed'] ?? true) === false
                        && (bool) ($scorecard['external_side_effects'] ?? true) === false,
                ];
                $row['board_operating_review_hash'] = MissionCanonicalHash::sha256($row);

                return $row;
            },
            (array) ($scorecards['companies'] ?? []),
        ));

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['ready'] ?? false)));
        $companyCount = count($companies);

        $payload = [
            'ok' => $companyCount > 0 && $readyCompanyCount === $companyCount,
            'schema' => 'atlas.ai.holding.enterprise_company_board_operating_review_status.v1',
            'status' => $companyCount > 0 && $readyCompanyCount === $companyCount
                ? 'company_board_operating_reviews_ready_external_commitments_blocked'
                : 'company_board_operating_reviews_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'ready_company_count' => $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies)),
                'completed_outcome_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['completed_outcome_flow_count'], $companies)),
                'business_operating_packet_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_operating_packet_flow_count'], $companies)),
                'command_center_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_flow_count'], $companies)),
                'measured_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['measured_kpi_count'], $companies)),
                'acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_contract_count'], $companies)),
                'risk_remediation_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.risk_remediation_count', 0), $companies)),
                'next_cycle_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.next_cycle_action_count', 0), $companies)),
                'board_decision_action_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'continuous_improvement_backlog.board_decision_action_count', 0), $companies)),
                'scale_internal_supervised_capacity_count' => count(array_filter(
                    $companies,
                    static fn (array $company): bool => ($company['decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity',
                )),
                'external_commitment_allowed_count' => 0,
                'external_side_effect_count' => 0,
            ],
            'source_hashes' => [
                'holding_outcome_scorecard_status_hash' => $scorecards['holding_outcome_scorecard_status_hash'] ?? null,
                'portfolio_decision_packet_status_hash' => $portfolio['portfolio_decision_packet_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPackets['business_operating_packet_runtime_status_hash'] ?? null,
                'command_center_control_tower_runtime_status_hash' => $commandCenter['command_center_control_tower_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'real_capital_action_allowed' => false,
                'board_review_is_internal_governance_not_external_authority' => true,
                'operator_signed_mandate_required_for_any_external_commitment' => true,
            ],
        ];
        $payload['company_board_operating_review_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function crossCompanyHandoffRuntimeStatus(?string $companyId = null): array
    {
        $report = $this->buildoutReport();
        $contracts = array_values(array_filter(
            (array) data_get($report, 'cross_company_fabric.handoff_contracts', []),
            static fn (array $contract): bool => $companyId === null
                || (string) ($contract['source_company'] ?? '') === $companyId
                || (string) ($contract['target_company'] ?? '') === $companyId,
        ));
        $companyIds = array_values(array_map(
            static fn (array $company): string => (string) ($company['company_id'] ?? 'unknown'),
            (array) ($report['companies'] ?? []),
        ));
        $runtimeRecordsByCompany = [];
        foreach ($companyIds as $currentCompanyId) {
            $runtimeRecordsByCompany[$currentCompanyId] = $this->records->runtimeRecordsForCompany($currentCompanyId);
        }

        $packets = [];
        foreach ($contracts as $contract) {
            $sourceCompany = (string) ($contract['source_company'] ?? 'unknown');
            $targetCompany = (string) ($contract['target_company'] ?? 'unknown');
            $sourceRecords = (array) ($runtimeRecordsByCompany[$sourceCompany] ?? []);
            $targetRecords = (array) ($runtimeRecordsByCompany[$targetCompany] ?? []);
            $sourceRuntimeReady = $sourceRecords !== [] && count(array_filter(
                $sourceRecords,
                static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            )) === count($sourceRecords);
            $targetAcceptanceBound = in_array($targetCompany, $companyIds, true)
                && $targetRecords !== []
                && count(array_filter(
                    $targetRecords,
                    static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== ''
                        && (bool) ($record['external_side_effects'] ?? true) === false,
                )) === count($targetRecords);

            $packet = [
                'schema' => 'atlas.ai.company.cross_company_handoff_runtime_packet.v1',
                'source_company' => $sourceCompany,
                'target_company' => $targetCompany,
                'contract' => (string) ($contract['contract'] ?? $sourceCompany.'->'.$targetCompany),
                'contract_hash' => (string) ($contract['contract_hash'] ?? ''),
                'source_runtime_record_count' => count($sourceRecords),
                'target_runtime_record_count' => count($targetRecords),
                'source_runtime_ready' => $sourceRuntimeReady,
                'target_acceptance_bound' => $targetAcceptanceBound,
                'typed_context_bound' => (bool) ($contract['typed_context_required'] ?? false),
                'evidence_refs_bound' => (bool) ($contract['evidence_refs_required'] ?? false)
                    && count(array_filter($sourceRecords, static fn (array $record): bool => (string) ($record['receipt_hash'] ?? '') !== '')) > 0,
                'acceptance_required' => (bool) ($contract['acceptance_required'] ?? true),
                'acceptance_status' => $targetAcceptanceBound ? 'accepted_internal_handoff_packet' : 'missing_target_runtime_acceptance',
                'source_receipt_refs' => array_values(array_slice(array_filter(array_map(
                    static fn (array $record): string => (string) ($record['receipt_hash'] ?? ''),
                    $sourceRecords,
                )), 0, 5)),
                'target_receipt_refs' => array_values(array_slice(array_filter(array_map(
                    static fn (array $record): string => (string) ($record['receipt_hash'] ?? ''),
                    $targetRecords,
                )), 0, 5)),
                'external_side_effects' => false,
                'external_execution_allowed' => false,
            ];
            $packet['ready'] = $sourceRuntimeReady
                && $targetAcceptanceBound
                && (bool) $packet['typed_context_bound']
                && (bool) $packet['evidence_refs_bound']
                && (bool) ($contract['external_side_effects'] ?? true) === false;
            $packet['handoff_runtime_packet_hash'] = MissionCanonicalHash::sha256($packet);

            $packets[] = $packet;
        }

        $readyPacketCount = count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['ready'] ?? false)));
        $contractCount = count($contracts);

        $payload = [
            'ok' => $contractCount > 0 && $readyPacketCount === $contractCount,
            'schema' => 'atlas.ai.holding.enterprise_cross_company_handoff_runtime_status.v1',
            'status' => $contractCount > 0 && $readyPacketCount === $contractCount
                ? 'cross_company_handoff_runtime_ready_external_blocked'
                : 'cross_company_handoff_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'handoff_contract_count' => $contractCount,
                'ready_handoff_runtime_packet_count' => $readyPacketCount,
                'source_runtime_ready_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['source_runtime_ready'] ?? false))),
                'target_acceptance_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['target_acceptance_bound'] ?? false))),
                'typed_context_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['typed_context_bound'] ?? false))),
                'evidence_refs_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['evidence_refs_bound'] ?? false))),
                'external_side_effect_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['external_side_effects'] ?? true))),
                'coverage_rate' => $contractCount > 0 ? round($readyPacketCount / $contractCount, 4) : 0.0,
            ],
            'handoff_runtime_packets' => $packets,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'handoff_requires_source_runtime_target_acceptance_typed_context_and_evidence_refs' => true,
                'manual_or_external_handoff_requires_operator_signed_scope' => true,
            ],
        ];
        $payload['cross_company_handoff_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function customerAccountRevenueRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_customer_account_revenue_runtime_status.v1',
            'status_complete' => 'complete_customer_account_revenue_runtime_coverage_external_revenue_blocked',
            'status_missing' => 'missing_customer_account_revenue_runtime_coverage',
            'hash_key' => 'customer_account_revenue_runtime_status_hash',
            'completed_key' => 'completed_customer_account_revenue_flow_count',
            'missing_key' => 'missing_customer_account_revenue_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'customer_account_revenue_runtime_bound',
                'customer_market_runtime_bound',
                'offer_packaging_bound',
                'journey_lifecycle_bound',
                'customer_success_scorecard_bound',
                'commercial_service_catalog_bound',
                'business_kpi_bound',
                'account_contract_delivery_bound',
                'account_segment_playbook_bound',
                'contract_entitlement_bound',
                'onboarding_success_plan_bound',
                'service_review_renewal_bound',
                'account_health_risk_bound',
                'billing_revenue_model_bound',
                'account_observability_bound',
                ['hash', 'customer_account_revenue_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'customer_market_runtime_bound_count' => 'customer_market_runtime_bound',
                'offer_packaging_bound_count' => 'offer_packaging_bound',
                'journey_lifecycle_bound_count' => 'journey_lifecycle_bound',
                'customer_success_scorecard_bound_count' => 'customer_success_scorecard_bound',
                'commercial_service_catalog_bound_count' => 'commercial_service_catalog_bound',
                'business_kpi_bound_count' => 'business_kpi_bound',
                'account_contract_delivery_bound_count' => 'account_contract_delivery_bound',
                'account_segment_playbook_bound_count' => 'account_segment_playbook_bound',
                'contract_entitlement_bound_count' => 'contract_entitlement_bound',
                'onboarding_success_plan_bound_count' => 'onboarding_success_plan_bound',
                'service_review_renewal_bound_count' => 'service_review_renewal_bound',
                'account_health_risk_bound_count' => 'account_health_risk_bound',
                'billing_revenue_model_bound_count' => 'billing_revenue_model_bound',
                'account_observability_bound_count' => 'account_observability_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'revenue_claim_allowed' => false,
                'real_revenue_claim_requires_external_evidence_operator_acceptance_and_signed_scope' => true,
                'customer_account_revenue_runtime_requires_icp_offer_journey_account_contract_success_renewal_billing_controls' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function productizedServiceRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_productized_service_runtime_status.v1',
            'status_complete' => 'complete_productized_service_runtime_coverage_external_commitment_billing_blocked',
            'status_missing' => 'missing_productized_service_runtime_coverage',
            'hash_key' => 'productized_service_runtime_status_hash',
            'completed_key' => 'completed_productized_service_flow_count',
            'missing_key' => 'missing_productized_service_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'productized_service_runtime_bound',
                'productized_service_stack_bound',
                'productized_domain_product_line_bound',
                'productized_service_offer_bound',
                'productized_delivery_blueprint_bound',
                'productized_intake_contract_bound',
                'productized_sla_success_contract_bound',
                'productized_pricing_packaging_bound',
                'productized_gtm_motion_bound',
                'productized_proof_template_bound',
                'productized_observability_bound',
                'productized_external_commitment_billing_blocked',
                ['hash', 'productized_service_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'service_offer_bound_count' => 'productized_service_offer_bound',
                'delivery_blueprint_bound_count' => 'productized_delivery_blueprint_bound',
                'intake_contract_bound_count' => 'productized_intake_contract_bound',
                'sla_success_contract_bound_count' => 'productized_sla_success_contract_bound',
                'pricing_packaging_bound_count' => 'productized_pricing_packaging_bound',
                'gtm_motion_bound_count' => 'productized_gtm_motion_bound',
                'proof_template_bound_count' => 'productized_proof_template_bound',
                'observability_bound_count' => 'productized_observability_bound',
                'external_commitment_billing_blocked_count' => 'productized_external_commitment_billing_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'public_gtm_or_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'productized_service_runtime_requires_offer_delivery_intake_sla_pricing_gtm_proof_and_observability' => true,
                'operator_mandate_required_for_public_offer_customer_commitment_or_billing' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function salesCrmPipelineRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_sales_crm_pipeline_runtime_status.v1',
            'status_complete' => 'complete_sales_crm_pipeline_runtime_coverage_external_commitments_blocked',
            'status_missing' => 'missing_sales_crm_pipeline_runtime_coverage',
            'hash_key' => 'sales_crm_pipeline_runtime_status_hash',
            'completed_key' => 'completed_sales_crm_pipeline_flow_count',
            'missing_key' => 'missing_sales_crm_pipeline_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'sales_crm_pipeline_runtime_bound',
                'sales_crm_stack_bound',
                'sales_source_catalog_bound',
                'sales_crm_object_model_bound',
                'sales_segment_play_bound',
                'sales_opportunity_route_bound',
                'sales_proposal_scope_bound',
                'sales_mutual_action_plan_bound',
                'sales_account_research_workbench_bound',
                'sales_deal_room_packet_bound',
                'sales_pipeline_forecast_review_bound',
                'sales_map_risk_review_bound',
                'sales_renewal_expansion_signal_bound',
                'sales_delivery_handoff_bound',
                'sales_pipeline_observability_bound',
                'sales_external_commitments_blocked',
                ['hash', 'sales_crm_pipeline_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'sales_source_catalog_bound',
                'crm_object_model_bound_count' => 'sales_crm_object_model_bound',
                'segment_play_bound_count' => 'sales_segment_play_bound',
                'opportunity_route_bound_count' => 'sales_opportunity_route_bound',
                'proposal_scope_bound_count' => 'sales_proposal_scope_bound',
                'mutual_action_plan_bound_count' => 'sales_mutual_action_plan_bound',
                'account_research_workbench_bound_count' => 'sales_account_research_workbench_bound',
                'deal_room_packet_bound_count' => 'sales_deal_room_packet_bound',
                'pipeline_forecast_review_bound_count' => 'sales_pipeline_forecast_review_bound',
                'map_risk_review_bound_count' => 'sales_map_risk_review_bound',
                'renewal_expansion_signal_bound_count' => 'sales_renewal_expansion_signal_bound',
                'sales_delivery_handoff_bound_count' => 'sales_delivery_handoff_bound',
                'pipeline_observability_bound_count' => 'sales_pipeline_observability_bound',
                'external_commitments_blocked_count' => 'sales_external_commitments_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_outreach_contract_signature_or_customer_commitment_allowed' => false,
                'public_claim_or_paid_campaign_allowed' => false,
                'sales_crm_runtime_requires_crm_opportunity_proposal_map_renewal_handoff_and_observability' => true,
                'operator_mandate_required_for_external_sales_message_contract_or_commitment' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function customerSupportServiceDeskRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_customer_support_service_desk_runtime_status.v1',
            'status_complete' => 'complete_customer_support_service_desk_runtime_coverage_external_support_blocked',
            'status_missing' => 'missing_customer_support_service_desk_runtime_coverage',
            'hash_key' => 'customer_support_service_desk_runtime_status_hash',
            'completed_key' => 'completed_customer_support_service_desk_flow_count',
            'missing_key' => 'missing_customer_support_service_desk_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'customer_support_service_desk_runtime_bound',
                'support_service_desk_stack_bound',
                'support_source_catalog_bound',
                'support_service_desk_object_model_bound',
                'support_segment_playbook_bound',
                'support_lane_bound',
                'support_ticket_sla_contract_bound',
                'support_knowledge_base_template_bound',
                'support_escalation_incident_runbook_bound',
                'support_resolution_rca_bound',
                'support_case_resolution_workbench_bound',
                'support_customer_health_escalation_bound',
                'support_knowledge_quality_review_bound',
                'support_automation_deflection_test_bound',
                'support_feedback_learning_loop_bound',
                'support_observability_bound',
                'support_external_customer_actions_blocked',
                ['hash', 'customer_support_service_desk_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'support_source_catalog_bound',
                'service_desk_object_model_bound_count' => 'support_service_desk_object_model_bound',
                'segment_playbook_bound_count' => 'support_segment_playbook_bound',
                'support_lane_bound_count' => 'support_lane_bound',
                'ticket_sla_contract_bound_count' => 'support_ticket_sla_contract_bound',
                'knowledge_base_template_bound_count' => 'support_knowledge_base_template_bound',
                'escalation_incident_runbook_bound_count' => 'support_escalation_incident_runbook_bound',
                'resolution_rca_bound_count' => 'support_resolution_rca_bound',
                'case_resolution_workbench_bound_count' => 'support_case_resolution_workbench_bound',
                'customer_health_escalation_bound_count' => 'support_customer_health_escalation_bound',
                'knowledge_quality_review_bound_count' => 'support_knowledge_quality_review_bound',
                'automation_deflection_test_bound_count' => 'support_automation_deflection_test_bound',
                'feedback_learning_loop_bound_count' => 'support_feedback_learning_loop_bound',
                'support_observability_bound_count' => 'support_observability_bound',
                'external_customer_actions_blocked_count' => 'support_external_customer_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_message_or_support_commitment_allowed' => false,
                'regulated_support_advice_allowed_without_review' => false,
                'support_runtime_requires_ticket_sla_kb_escalation_rca_feedback_and_observability' => true,
                'operator_mandate_required_for_external_customer_support_or_public_kb' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function marketingGrowthEngineRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_marketing_growth_engine_runtime_status.v1',
            'status_complete' => 'complete_marketing_growth_engine_runtime_coverage_external_publish_blocked',
            'status_missing' => 'missing_marketing_growth_engine_runtime_coverage',
            'hash_key' => 'marketing_growth_engine_runtime_status_hash',
            'completed_key' => 'completed_marketing_growth_engine_flow_count',
            'missing_key' => 'missing_marketing_growth_engine_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'marketing_growth_engine_runtime_bound',
                'marketing_growth_stack_bound',
                'marketing_source_catalog_bound',
                'marketing_growth_operating_model_bound',
                'marketing_audience_segment_bound',
                'marketing_campaign_blueprint_bound',
                'marketing_content_asset_factory_bound',
                'marketing_experiment_bound',
                'marketing_growth_intelligence_workbench_bound',
                'marketing_attribution_experiment_model_bound',
                'marketing_channel_budget_guardrail_bound',
                'marketing_public_claim_evidence_packet_bound',
                'marketing_channel_distribution_bound',
                'marketing_brand_compliance_review_bound',
                'marketing_growth_crm_handoff_bound',
                'marketing_observability_bound',
                'marketing_external_publish_actions_blocked',
                ['hash', 'marketing_growth_engine_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'marketing_source_catalog_bound',
                'growth_operating_model_bound_count' => 'marketing_growth_operating_model_bound',
                'audience_segment_bound_count' => 'marketing_audience_segment_bound',
                'campaign_blueprint_bound_count' => 'marketing_campaign_blueprint_bound',
                'content_asset_factory_bound_count' => 'marketing_content_asset_factory_bound',
                'experiment_bound_count' => 'marketing_experiment_bound',
                'growth_intelligence_workbench_bound_count' => 'marketing_growth_intelligence_workbench_bound',
                'attribution_experiment_model_bound_count' => 'marketing_attribution_experiment_model_bound',
                'channel_budget_guardrail_bound_count' => 'marketing_channel_budget_guardrail_bound',
                'public_claim_evidence_packet_bound_count' => 'marketing_public_claim_evidence_packet_bound',
                'channel_distribution_bound_count' => 'marketing_channel_distribution_bound',
                'brand_compliance_review_bound_count' => 'marketing_brand_compliance_review_bound',
                'growth_crm_handoff_bound_count' => 'marketing_growth_crm_handoff_bound',
                'marketing_observability_bound_count' => 'marketing_observability_bound',
                'external_publish_actions_blocked_count' => 'marketing_external_publish_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_publish_paid_campaign_or_outreach_allowed' => false,
                'public_claim_allowed_without_source_and_operator_review' => false,
                'marketing_runtime_requires_campaign_content_experiment_channel_brand_review_crm_handoff_and_observability' => true,
                'operator_mandate_required_for_external_publish_paid_campaign_or_outreach' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function financeTreasuryBillingRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_finance_treasury_billing_runtime_status.v1',
            'status_complete' => 'complete_finance_treasury_billing_runtime_coverage_external_finance_blocked',
            'status_missing' => 'missing_finance_treasury_billing_runtime_coverage',
            'hash_key' => 'finance_treasury_billing_runtime_status_hash',
            'completed_key' => 'completed_finance_treasury_billing_flow_count',
            'missing_key' => 'missing_finance_treasury_billing_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'finance_treasury_billing_runtime_bound',
                'finance_treasury_stack_bound',
                'finance_source_catalog_bound',
                'finance_financial_data_interface_bound',
                'finance_provider_connector_matrix_bound',
                'finance_cfo_operating_model_bound',
                'finance_financial_research_workbench_bound',
                'finance_budget_envelope_bound',
                'finance_forecast_model_bound',
                'finance_model_risk_control_bound',
                'finance_investment_committee_packet_bound',
                'finance_pnl_line_item_bound',
                'finance_billing_ledger_bound',
                'finance_treasury_risk_bound',
                'finance_close_audit_bound',
                'finance_observability_bound',
                'finance_external_financial_actions_blocked',
                ['hash', 'finance_treasury_billing_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'source_catalog_bound_count' => 'finance_source_catalog_bound',
                'financial_data_interface_bound_count' => 'finance_financial_data_interface_bound',
                'provider_connector_matrix_bound_count' => 'finance_provider_connector_matrix_bound',
                'cfo_operating_model_bound_count' => 'finance_cfo_operating_model_bound',
                'financial_research_workbench_bound_count' => 'finance_financial_research_workbench_bound',
                'budget_envelope_bound_count' => 'finance_budget_envelope_bound',
                'forecast_model_bound_count' => 'finance_forecast_model_bound',
                'model_risk_control_bound_count' => 'finance_model_risk_control_bound',
                'investment_committee_packet_bound_count' => 'finance_investment_committee_packet_bound',
                'pnl_line_item_bound_count' => 'finance_pnl_line_item_bound',
                'billing_ledger_bound_count' => 'finance_billing_ledger_bound',
                'treasury_risk_bound_count' => 'finance_treasury_risk_bound',
                'close_audit_bound_count' => 'finance_close_audit_bound',
                'observability_bound_count' => 'finance_observability_bound',
                'external_financial_actions_blocked_count' => 'finance_external_financial_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_invoice_payment_collection_capital_transfer_or_trade_allowed' => false,
                'real_revenue_cash_or_aum_claim_allowed' => false,
                'source_linked_financial_claim_required' => true,
                'model_risk_review_required_for_investment_or_capital_recommendation' => true,
                'finance_treasury_runtime_requires_budget_forecast_pnl_billing_treasury_close_and_observability' => true,
                'finance_treasury_runtime_requires_source_linked_data_interface_connectors_research_workbench_model_risk_and_committee_packets' => true,
                'operator_mandate_required_for_external_billing_capital_vendor_spend_or_trade' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function governanceRiskOperationsRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_governance_risk_operations_runtime_status.v1',
            'status_complete' => 'complete_governance_risk_operations_runtime_coverage_external_actions_blocked',
            'status_missing' => 'missing_governance_risk_operations_runtime_coverage',
            'hash_key' => 'governance_risk_operations_runtime_status_hash',
            'completed_key' => 'completed_governance_risk_operations_flow_count',
            'missing_key' => 'missing_governance_risk_operations_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'governance_risk_operations_runtime_bound',
                'governance_vendor_procurement_bound',
                'governance_resilience_continuity_bound',
                'governance_analytics_decision_bound',
                'governance_knowledge_learning_bound',
                'governance_identity_sovereignty_bound',
                'governance_grc_evidence_bound',
                'governance_external_actions_blocked',
                ['hash', 'governance_risk_operations_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'vendor_procurement_bound_count' => 'governance_vendor_procurement_bound',
                'resilience_continuity_bound_count' => 'governance_resilience_continuity_bound',
                'analytics_decision_bound_count' => 'governance_analytics_decision_bound',
                'knowledge_learning_bound_count' => 'governance_knowledge_learning_bound',
                'identity_sovereignty_bound_count' => 'governance_identity_sovereignty_bound',
                'grc_evidence_bound_count' => 'governance_grc_evidence_bound',
                'external_actions_blocked_count' => 'governance_external_actions_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'vendor_purchase_contract_signature_secret_share_or_write_scope_allowed' => false,
                'incident_external_notification_without_operator_allowed' => false,
                'canonical_memory_write_without_review_allowed' => false,
                'secret_material_or_unscoped_memory_export_allowed' => false,
                'governance_runtime_requires_procurement_resilience_analytics_learning_identity_and_grc_evidence' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function unitEconomicsCapacityRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_unit_economics_capacity_runtime_status.v1',
            'status_complete' => 'complete_unit_economics_capacity_runtime_coverage_external_capital_blocked',
            'status_missing' => 'missing_unit_economics_capacity_runtime_coverage',
            'hash_key' => 'unit_economics_capacity_runtime_status_hash',
            'completed_key' => 'completed_unit_economics_capacity_flow_count',
            'missing_key' => 'missing_unit_economics_capacity_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'unit_economics_capacity_bound',
                'flow_cost_center_bound',
                'flow_unit_economics_bound',
                'capacity_simulation_bound',
                'pricing_ladder_bound',
                'agent_capacity_cost_model_bound',
                'connector_cost_limit_model_bound',
                ['hash', 'unit_economics_capacity_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'flow_cost_center_bound_count' => 'flow_cost_center_bound',
                'flow_unit_economics_bound_count' => 'flow_unit_economics_bound',
                'capacity_simulation_bound_count' => 'capacity_simulation_bound',
                'pricing_ladder_bound_count' => 'pricing_ladder_bound',
                'agent_capacity_cost_model_bound_count' => 'agent_capacity_cost_model_bound',
                'connector_cost_limit_model_bound_count' => 'connector_cost_limit_model_bound',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'real_capital_action_allowed' => false,
                'unit_economics_runtime_requires_cost_center_unit_model_capacity_pricing_agent_and_connector_cost_controls' => true,
                'observed_revenue_or_savings_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function businessOperatingPacketRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_business_operating_packet_runtime_status.v1',
            'status_complete' => 'complete_business_operating_packet_runtime_coverage_external_commitments_blocked',
            'status_missing' => 'missing_business_operating_packet_runtime_coverage',
            'hash_key' => 'business_operating_packet_runtime_status_hash',
            'completed_key' => 'completed_business_operating_packet_flow_count',
            'missing_key' => 'missing_business_operating_packet_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'business_operating_packet_bound',
                'business_operating_packet_business_model_bound',
                'business_operating_packet_kpi_contract_bound',
                'business_operating_packet_delivery_lane_bound',
                'business_operating_packet_economics_bound',
                'business_operating_packet_account_operations_bound',
                'business_operating_packet_external_commitments_blocked',
                ['hash', 'business_operating_packet_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'business_model_bound_count' => 'business_operating_packet_business_model_bound',
                'kpi_contract_bound_count' => 'business_operating_packet_kpi_contract_bound',
                'delivery_lane_bound_count' => 'business_operating_packet_delivery_lane_bound',
                'economics_bound_count' => 'business_operating_packet_economics_bound',
                'account_operations_bound_count' => 'business_operating_packet_account_operations_bound',
                'external_commitments_blocked_count' => 'business_operating_packet_external_commitments_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'real_capital_action_allowed' => false,
                'business_operating_packet_requires_business_model_kpi_sla_economics_account_ops_and_commitment_blocks' => true,
                'operator_mandate_required_for_external_customer_vendor_billing_capital_or_public_action' => true,
            ],
        ], $companyId);
    }

    /**
     * @return array<string,mixed>
     */
    public function deliveryRiskRuntimeStatus(?string $companyId = null): array
    {
        return $this->runtimeStatusFor([
            'schema' => 'atlas.ai.holding.enterprise_delivery_risk_runtime_status.v1',
            'status_complete' => 'complete_delivery_risk_runtime_coverage_external_claim_blocked',
            'status_missing' => 'missing_delivery_risk_runtime_coverage',
            'hash_key' => 'delivery_risk_runtime_status_hash',
            'completed_key' => 'completed_delivery_risk_flow_count',
            'missing_key' => 'missing_delivery_risk_flows',
            'flows' => static fn (array $company): array => array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            )),
            'gate' => [
                'delivery_risk_runtime_bound',
                'delivery_assurance_runtime_bound',
                'delivery_sla_bound',
                'strategic_intelligence_bound',
                'rival_alternative_map_bound',
                'grc_runtime_bound',
                'audit_evidence_bound',
                'policy_exception_blocked',
                ['hash', 'delivery_risk_attestation_hash'],
                ['internal', 'external_side_effects'],
            ],
            'counts' => [
                'delivery_assurance_runtime_bound_count' => 'delivery_assurance_runtime_bound',
                'delivery_sla_bound_count' => 'delivery_sla_bound',
                'strategic_intelligence_bound_count' => 'strategic_intelligence_bound',
                'rival_alternative_map_bound_count' => 'rival_alternative_map_bound',
                'grc_runtime_bound_count' => 'grc_runtime_bound',
                'audit_evidence_bound_count' => 'audit_evidence_bound',
                'policy_exception_blocked_count' => 'policy_exception_blocked',
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'customer_visible_claim_allowed' => false,
                'policy_exception_auto_approval_allowed' => false,
                'delivery_risk_runtime_requires_sla_acceptance_rival_map_audit_evidence_and_grc_controls' => true,
                'operator_mandate_required_for_external_delivery_or_policy_exception' => true,
            ],
        ], $companyId);
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
            'company_system_model_runtime_attestation' => [
                'schema' => 'atlas.ai.company.company_system_model_runtime_attestation.v1',
                'domain_data_model_bound' => (string) data_get($company, 'domain_data_model.data_model_hash', '') !== ''
                    && count((array) data_get($company, 'domain_data_model.entities', [])) >= 4
                    && count(array_filter(
                        (array) data_get($company, 'domain_data_model.entities', []),
                        static fn (array $entity): bool => (string) ($entity['primary_key'] ?? '') !== ''
                            && count((array) ($entity['required_fields'] ?? [])) >= 5,
                    )) === count((array) data_get($company, 'domain_data_model.entities', [])),
                'data_lineage_bound' => (bool) data_get($company, 'domain_data_model.retention_and_lineage.source_refs_required', false)
                    && (bool) data_get($company, 'domain_data_model.retention_and_lineage.state_hash_required', false)
                    && count((array) data_get($company, 'domain_data_model.retention_and_lineage.lineage_links', [])) >= 4,
                'business_process_bound' => $businessProcess !== []
                    && count((array) data_get($businessProcess, 'swimlanes', [])) >= 4
                    && count((array) data_get($businessProcess, 'states', [])) >= 8
                    && count((array) data_get($businessProcess, 'controls', [])) >= 5
                    && count((array) data_get($businessProcess, 'outputs', [])) >= 3,
                'deliverable_quality_contract_bound' => $deliverableQualityContract !== []
                    && count((array) data_get($deliverableQualityContract, 'required_sections', [])) >= 7
                    && count((array) data_get($deliverableQualityContract, 'acceptance_criteria', [])) >= 5
                    && count((array) data_get($deliverableQualityContract, 'rejection_criteria', [])) >= 4
                    && (float) data_get($deliverableQualityContract, 'quality_score_floor', 0.0) >= 0.86,
                'production_pack_bound' => (string) data_get($company, 'go_to_production_pack.production_readiness_hash', '') !== ''
                    && count((array) data_get($company, 'go_to_production_pack.environment_model.stages', [])) >= 4
                    && count((array) data_get($company, 'go_to_production_pack.environment_model.promotion_requires', [])) >= 4
                    && (bool) data_get($company, 'go_to_production_pack.environment_model.external_side_effects_default', true) === false,
                'production_observability_bound' => count((array) data_get($company, 'go_to_production_pack.observability.required_signals', [])) >= 7
                    && count((array) data_get($company, 'go_to_production_pack.observability.dashboards', [])) >= 1
                    && count((array) data_get($company, 'go_to_production_pack.observability.alert_routes', [])) >= 3,
                'slo_sli_bound' => $sloSli !== []
                    && (float) data_get($sloSli, 'target.routine_success_rate', data_get($sloSli, 'targets.routine_success_rate', 0.0)) >= 0.95
                    && (float) data_get($sloSli, 'target.minimum_quality_score', data_get($sloSli, 'targets.minimum_quality_score', 0.0)) >= 0.86
                    && (int) data_get($sloSli, 'target.policy_findings_allowed', data_get($sloSli, 'targets.policy_findings_allowed', 1)) === 0,
                'incident_response_bound' => count((array) data_get($company, 'go_to_production_pack.incident_response.severity_levels', [])) >= 4
                    && count((array) data_get($company, 'go_to_production_pack.incident_response.escalation_chain', [])) >= 3
                    && count((array) data_get($company, 'go_to_production_pack.incident_response.required_artifacts', [])) >= 5,
                'capacity_plan_bound' => count((array) data_get($company, 'go_to_production_pack.capacity_plan.named_agents', [])) >= 4
                    && (int) data_get($company, 'go_to_production_pack.capacity_plan.parallel_flow_limit', 0) >= 2
                    && (bool) data_get($company, 'go_to_production_pack.capacity_plan.human_checkpoint_capacity_required', false),
                'integration_enablement_bound' => count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])) >= $connectorCount
                    && count(array_filter(
                        (array) data_get($company, 'go_to_production_pack.integration_enablement_plan', []),
                        static fn (array $plan): bool => (string) ($plan['status'] ?? '') === 'contract_ready'
                            && count((array) (($plan['activation_steps'] ?? null) ?: ($plan['enablement_steps'] ?? []))) >= 5
                            && (bool) ($plan['external_side_effects_enabled'] ?? true) === false,
                    )) === count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])),
                'commercial_stack_bound' => (string) data_get($company, 'commercial_operating_stack.commercial_hash', '') !== ''
                    && count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5
                    && count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4,
                'commercial_intake_bound' => count((array) data_get($company, 'commercial_operating_stack.work_intake_model.required_intake_fields', [])) >= 6
                    && (bool) data_get($company, 'commercial_operating_stack.work_intake_model.reject_when_missing_policy_profile', false),
                'commercial_fulfillment_bound' => count((array) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.stages', [])) >= 7
                    && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.quality_gate_before_delivery', false)
                    && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.handoff_packet_required_for_cross_company_delivery', data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.handoff_packet_required', false))
                    && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.receipt_required_for_delivery', data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.receipt_required', false)),
                'commercial_external_billing_allowed' => (bool) data_get($company, 'commercial_operating_stack.pricing_and_cost_model.external_billing_enabled', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'company_system_model_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'domain_data_model.data_model_hash', '').'|'.(string) ($businessProcess['process_hash'] ?? '').'|'.(string) ($deliverableQualityContract['contract_hash'] ?? '').'|'.(string) data_get($company, 'go_to_production_pack.production_readiness_hash', '').'|'.(string) data_get($company, 'commercial_operating_stack.commercial_hash', '')),
            ],
            'internal_operations_backbone_runtime_attestation' => [
                'schema' => 'atlas.ai.company.internal_operations_backbone_runtime_attestation.v1',
                'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== ''
                    && $accountOnboardingPlan !== []
                    && count((array) data_get($accountOnboardingPlan, 'milestones', [])) >= 5
                    && count((array) data_get($accountOnboardingPlan, 'required_artifacts', [])) >= 5
                    && $accountServiceReview !== []
                    && count((array) data_get($accountServiceReview, 'required_review_sections', [])) >= 5
                    && (bool) data_get($accountServiceReview, 'renewal_action_allowed', true) === false,
                'vendor_legal_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                    && $vendorProcurementRouting !== []
                    && count((array) data_get($vendorProcurementRouting, 'procurement_review_required_when', [])) >= 5
                    && count((array) data_get($vendorProcurementRouting, 'blocked_actions', [])) >= 4
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= $connectorCount
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= $connectorCount,
                'resilience_continuity_bound' => (string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '') !== ''
                    && $resilienceFailureMode !== []
                    && count((array) data_get($resilienceFailureMode, 'failure_modes', [])) >= 5
                    && count((array) data_get($resilienceFailureMode, 'recovery_evidence_required', [])) >= 4
                    && $resilienceExercise !== []
                    && count((array) data_get($resilienceExercise, 'success_criteria', [])) >= 3
                    && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false,
                'analytics_decision_intelligence_bound' => (string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '') !== ''
                    && $analyticsDecisionRegister !== []
                    && count((array) data_get($analyticsDecisionRegister, 'required_decision_fields', [])) >= 6
                    && (bool) data_get($analyticsDecisionRegister, 'action_register_required', false)
                    && $scenarioForecast !== []
                    && count((array) data_get($scenarioForecast, 'scenario_set', [])) >= 4
                    && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.external_action_from_dashboard_allowed', true) === false,
                'knowledge_memory_learning_bound' => (string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '') !== ''
                    && $knowledgeLearningLoop !== []
                    && count((array) data_get($knowledgeLearningLoop, 'learning_inputs', [])) >= 5
                    && count((array) data_get($knowledgeLearningLoop, 'learning_outputs', [])) >= 4
                    && $playbookChangeControl !== []
                    && count((array) data_get($playbookChangeControl, 'required_diff_sections', [])) >= 6
                    && (bool) data_get($playbookChangeControl, 'auto_apply_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false,
                'identity_access_sovereignty_bound' => (string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '') !== ''
                    && $identityDataBoundary !== []
                    && count((array) data_get($identityDataBoundary, 'egress_controls', [])) >= 4
                    && count((array) data_get($identityDataBoundary, 'blocked_data_classes_without_operator', [])) >= 2
                    && $purposeConsent !== []
                    && count((array) data_get($purposeConsent, 'consent_or_authority_required_when', [])) >= 4
                    && (bool) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.privilege_escalation_auto_allowed', true) === false,
                'control_tower_run_operations_bound' => (string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '') !== ''
                    && $controlTowerLane !== []
                    && count((array) data_get($controlTowerLane, 'intake_states', [])) >= 7
                    && count((array) data_get($controlTowerLane, 'required_run_artifacts', [])) >= 6
                    && $incidentExceptionDesk !== []
                    && count((array) data_get($incidentExceptionDesk, 'exception_types', [])) >= 6
                    && $changeWindowRelease !== []
                    && (bool) data_get($changeWindowRelease, 'rollback_required', false)
                    && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
                'delivery_assurance_bound' => (string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '') !== ''
                    && $deliverySla !== []
                    && (float) data_get($deliverySla, 'quality_target.minimum_score', 0.0) >= 0.86
                    && (int) data_get($deliverySla, 'quality_target.policy_findings_allowed', 1) === 0
                    && (bool) data_get($deliverySla, 'quality_target.receipt_required', false)
                    && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
                'grc_control_evidence_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== ''
                    && $grcEvidence !== []
                    && count((array) data_get($grcEvidence, 'required_evidence', [])) >= 6
                    && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false)
                    && (bool) data_get($company, 'enterprise_grc_stack.privacy_and_security.security_review_required_for_new_connector', false),
                'external_customer_vendor_memory_identity_delivery_actions_blocked' => true,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
                'attestation_hash' => hash('sha256', 'internal_operations_backbone_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '').'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '').'|'.(string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '').'|'.(string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '').'|'.(string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '').'|'.(string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '').'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
            ],
            'activation_run_operations_runtime_attestation' => [
                'schema' => 'atlas.ai.company.activation_run_operations_runtime_attestation.v1',
                'integration_activation_plan_bound' => (string) data_get($company, 'enterprise_integration_activation_plan.activation_hash', '') !== ''
                    || (string) data_get($company, 'enterprise_integration_activation_plan.schema', '') === 'atlas.ai.company.enterprise_integration_activation_plan.v1',
                'activation_policy_bound' => (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.buildout_blocked_by_observed_history_window', true) === false
                    && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.external_write_blocked_until_operator_mandate', false)
                    && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.kill_switch_required', false)
                    && count((array) data_get($company, 'enterprise_integration_activation_plan.activation_policy.promotion_sequence', [])) >= 4,
                'source_activation_tracks_bound' => count($sourceActivationTracks) >= 5
                    && count(array_filter($sourceActivationTracks, static fn (array $track): bool => count((array) ($track['activation_steps'] ?? [])) >= 6
                        && count((array) ($track['required_evidence'] ?? [])) >= 5
                        && (bool) ($track['external_side_effects_enabled'] ?? true) === false)) === count($sourceActivationTracks),
                'connector_activation_tracks_bound' => count($connectorActivationTracks) >= $connectorCount
                    && count(array_filter($connectorActivationTracks, static fn (array $track): bool => count((array) ($track['activation_steps'] ?? [])) >= 6
                        && (bool) data_get($track, 'health_check_contract.must_return_receipt', false)
                        && (bool) data_get($track, 'health_check_contract.must_not_mutate_external_state', false)
                        && count((array) ($track['shadow_mode_ready_when'] ?? [])) >= 4
                        && (bool) ($track['external_side_effects_enabled'] ?? true) === false)) === count($connectorActivationTracks),
                'flow_activation_matrix_bound' => $flowActivationMatrix !== []
                    && count((array) data_get($flowActivationMatrix, 'required_activation_evidence', [])) >= 6
                    && count((array) data_get($flowActivationMatrix, 'shadow_mode_entry_criteria', [])) >= 3
                    && count((array) data_get($flowActivationMatrix, 'supervised_production_entry_criteria', [])) >= 3,
                'run_queue_model_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.priority_classes', [])) >= 5
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                    && (string) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.dead_letter_queue', '') !== '',
                'flow_operations_lane_bound' => $controlTowerLane !== []
                    && count((array) data_get($controlTowerLane, 'required_run_artifacts', [])) >= 6
                    && count((array) data_get($controlTowerLane, 'blocked_until', [])) >= 4,
                'connector_operations_probe_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= $connectorCount
                    && count(array_filter(
                        (array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', []),
                        static fn (array $probe): bool => count((array) ($probe['health_checks'] ?? [])) >= 5
                            && (bool) ($probe['auto_remediation_allowed'] ?? true) === false,
                    )) === count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])),
                'live_read_probe_plan_bound' => $flowConnectorCount > 0
                    && count($rehearsalLiveReadProbes) >= $flowConnectorCount
                    && count(array_filter($rehearsalLiveReadProbes, static fn (array $probe): bool => count((array) ($probe['required_before_supervised_production'] ?? [])) >= 6
                        && (bool) ($probe['mutation_allowed'] ?? true) === false)) === count($rehearsalLiveReadProbes),
                'rehearsal_promotion_evidence_bound' => $rehearsalRunbook !== []
                    && $operatorAcceptancePacket !== []
                    && $rollbackDrill !== []
                    && $promotionEvidence !== []
                    && count((array) data_get($promotionEvidence, 'must_have_green', [])) >= 6
                    && (int) data_get($promotionEvidence, 'calendar_wait_days_required', 30) === 0
                    && (bool) data_get($promotionEvidence, 'external_side_effects_enabled', true) === false,
                'observability_bound' => count((array) data_get($company, 'enterprise_integration_activation_plan.activation_observability.required_signals', [])) >= 6
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.required_metrics', [])) >= 6
                    && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
                'attestation_hash' => hash('sha256', 'activation_run_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($flowActivationMatrix['matrix_hash'] ?? '').'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '')),
            ],
            'flow_execution_foundation_runtime_attestation' => [
                'schema' => 'atlas.ai.company.flow_execution_foundation_runtime_attestation.v1',
                'orchestration_stack_bound' => (string) data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash', '') !== '',
                'flow_runbook_bound' => $flowRunbook !== []
                    && count((array) data_get($flowRunbook, 'intake_packet.required_fields', [])) >= 6
                    && count((array) data_get($flowRunbook, 'agent_graph.specialists', [])) >= 1
                    && (bool) data_get($flowRunbook, 'agent_graph.handoff_packet_required', false)
                    && count((array) data_get($flowRunbook, 'checkpoint_lattice.checkpoints', [])) >= 7
                    && count((array) data_get($flowRunbook, 'evaluation_and_acceptance.acceptance_artifacts', [])) >= 5
                    && (bool) data_get($flowRunbook, 'handoff_and_delivery.external_delivery_requires_operator_approval', false),
                'connector_backplane_bound' => count($connectorBackplane) >= $connectorCount
                    && count(array_filter($connectorBackplane, static fn (array $connector): bool => (bool) ($connector['health_probe_required_before_run'] ?? false)
                        && (bool) ($connector['receipt_export_required'] ?? false))) === count($connectorBackplane),
                'runbook_observability_bound' => count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.runbook_observability.required_metrics', [])) >= 5,
                'implementation_stack_bound' => (string) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_hash', '') !== '',
                'executable_flow_packet_bound' => $executableFlowPacket !== []
                    && (bool) data_get($executableFlowPacket, 'execution_graph.resume_token_required', false)
                    && (bool) data_get($executableFlowPacket, 'execution_graph.idempotency_required', false)
                    && count((array) data_get($executableFlowPacket, 'execution_graph.human_interrupt_points', [])) >= 3
                    && (bool) data_get($executableFlowPacket, 'runtime_state_contract.state_hash_required', false)
                    && (bool) data_get($executableFlowPacket, 'runtime_state_contract.replayable_without_external_mutation', false),
                'agent_tool_routing_bound' => $agentToolRouting !== []
                    && count((array) data_get($agentToolRouting, 'routing_rules', [])) >= 3
                    && count((array) data_get($agentToolRouting, 'blocked_routes', [])) >= 4,
                'artifact_io_contract_bound' => $flowArtifactIoContract !== []
                    && count((array) data_get($flowArtifactIoContract, 'required_lineage', [])) >= 6
                    && (bool) data_get($flowArtifactIoContract, 'redaction_before_provider_payload', false),
                'supervision_shadow_gate_bound' => $supervisionShadowGate !== []
                    && count((array) data_get($supervisionShadowGate, 'contract_stage_requires', [])) >= 3
                    && count((array) data_get($supervisionShadowGate, 'shadow_stage_requires', [])) >= 4
                    && count((array) data_get($supervisionShadowGate, 'supervised_stage_requires', [])) >= 4
                    && count((array) data_get($supervisionShadowGate, 'blocked_until_production_acceptance', [])) >= 3,
                'connector_runtime_adapters_bound' => count($connectorRuntimeAdapters) >= $connectorCount
                    && count(array_filter($connectorRuntimeAdapters, static fn (array $adapter): bool => count((array) ($adapter['supported_operations'] ?? [])) >= 4
                        && count((array) ($adapter['blocked_operations'] ?? [])) >= 7
                        && count((array) ($adapter['adapter_requirements'] ?? [])) >= 5)) === count($connectorRuntimeAdapters),
                'runtime_event_outbox_bound' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.runtime_event_and_outbox_contract.outbox_required_for', [])) >= 7
                    && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.runtime_event_and_outbox_contract.replay_key_fields', [])) >= 5,
                'implementation_observability_bound' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_observability.required_metrics', [])) >= 6,
                'fixture_simulation_stack_bound' => (string) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_stack_hash', '') !== '',
                'canonical_fixture_bound' => $canonicalFixture !== []
                    && count((array) data_get($canonicalFixture, 'input_packet.acceptance_criteria', [])) >= 4
                    && count((array) data_get($canonicalFixture, 'expected_output.required_sections', [])) >= 6,
                'expected_trace_bound' => $trajectory !== []
                    && count((array) data_get($trajectory, 'expected_nodes', [])) >= 10
                    && count((array) data_get($trajectory, 'required_trace_fields', [])) >= 7
                    && count((array) data_get($trajectory, 'must_not_include', [])) >= 4,
                'quality_assertion_suite_bound' => $assertionSuite !== []
                    && count((array) data_get($assertionSuite, 'assertions', [])) >= 6,
                'failure_injection_bound' => $failureCases !== []
                    && count((array) data_get($failureCases, 'cases', [])) >= 5
                    && count((array) data_get($failureCases, 'must_not_do', [])) >= 4,
                'dry_run_command_bound' => $dryRun !== []
                    && (int) data_get($dryRun, 'expected_exit_code', 1) === 0
                    && count((array) data_get($dryRun, 'required_output_keys', [])) >= 3,
                'simulation_promotion_gates_bound' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.contract_ready_requires', [])) >= 3
                    && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.shadow_ready_requires', [])) >= 4
                    && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.supervised_ready_requires', [])) >= 3
                    && (bool) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.autonomy_claim_requires_current_operational_evidence', false),
                'simulation_observability_bound' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_observability.required_metrics', [])) >= 6,
                'calendar_wait_blocker_enabled' => false,
                'external_side_effects_enabled' => false,
                'ungoverned_external_side_effects_allowed' => false,
                'operator_checkpoint_required_before_external_action' => true,
                'attestation_hash' => hash('sha256', 'flow_execution_foundation_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash', '').'|'.(string) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_hash', '').'|'.(string) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_stack_hash', '')),
            ],
            'agent_toolchain_runtime_attestation' => [
                'schema' => 'atlas.ai.company.agent_toolchain_runtime_attestation.v1',
                'framework_source_catalog_bound' => count($frameworkSourceCatalog) >= 9,
                'framework_source_catalog_count' => count($frameworkSourceCatalog),
                'repository_watchlist_bound' => count($agentFrameworkWatchlist) >= 8,
                'framework_watchlist_count' => count($agentFrameworkWatchlist),
                'framework_scorecard_bound' => count($frameworkScorecards) >= 8,
                'framework_scorecard_count' => count($frameworkScorecards),
                'flow_toolkit_assignment_bound' => $flowToolkitAssignment !== [],
                'assigned_toolkit' => (string) ($flowToolkitAssignment['assigned_toolkit'] ?? ''),
                'agent_repository_epic_bound' => $agentRepositoryEpic !== [],
                'primary_framework_ref' => (string) ($agentRepositoryEpic['primary_framework_ref'] ?? ''),
                'version_pin_plan_bound' => count($versionPinPlan) >= 8,
                'version_pin_count' => count($versionPinPlan),
                'local_contract_tests_required' => true,
                'guardrails_runtime_bound' => $eventPlan !== [] && in_array('policy_checked', (array) ($eventPlan['required_events'] ?? []), true),
                'handoffs_runtime_bound' => data_get($company, 'cross_company_fabric') !== null
                    || in_array('handoff_packet_production', (array) data_get($flowToolkitAssignment, 'required_skill_sequence', []), true)
                    || in_array('action_completed_or_blocked', (array) ($eventPlan['required_events'] ?? []), true),
                'tracing_runtime_bound' => $eventPlan !== [] && (string) ($eventPlan['event_schema'] ?? '') !== '',
                'durable_state_runtime_bound' => $stateSchema !== [] && (bool) ($stateSchema['state_hash_required'] ?? false),
                'human_in_loop_runtime_bound' => $checkpoint !== [] && (bool) ($checkpoint['auto_approval_allowed'] ?? true) === false,
                'mcp_connector_workbench_bound' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', [])) >= count((array) ($company['connectors'] ?? [])),
                'memory_knowledge_bound' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5,
                'calendar_wait_blocker_enabled' => false,
                'external_tool_side_effect_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'agent_toolchain_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($agentRepositoryEpic['epic_hash'] ?? '')),
            ],
            'premium_enterprise_agent_runtime_attestation' => [
                'schema' => 'atlas.ai.company.premium_enterprise_agent_runtime_attestation.v1',
                'premium_model_bound' => (string) data_get($company, 'premium_enterprise_agent_reference_model.premium_model_hash', '') !== '',
                'agentic_architecture_basis_bound' => count((array) ($premiumAgenticArchitecture['agent_runtime_patterns'] ?? [])) >= 6
                    && count((array) ($premiumAgenticArchitecture['required_runtime_properties'] ?? [])) >= 9,
                'managed_agent_templates_bound' => count($premiumTemplates) >= 10,
                'template_runtime_contracts_bound' => count($premiumTemplateRuntimeContracts) >= count($premiumTemplates)
                    && count(array_filter($premiumTemplateRuntimeContracts, static fn (array $contract): bool => count((array) ($contract['required_capabilities'] ?? [])) >= 7
                        && count((array) ($contract['required_runtime_events'] ?? [])) >= 7
                        && count((array) ($contract['forbidden_without_operator'] ?? [])) >= 8)) === count($premiumTemplateRuntimeContracts),
                'flow_template_map_bound' => $premiumFlowTemplateMap !== []
                    && count((array) ($premiumFlowTemplateMap['supporting_template_ids'] ?? [])) >= 3
                    && count((array) ($premiumFlowTemplateMap['required_artifacts'] ?? [])) >= 5
                    && (bool) ($premiumFlowTemplateMap['external_side_effects'] ?? true) === false,
                'flow_managed_agent_workflow_bound' => $premiumManagedAgentWorkflow !== []
                    && count((array) ($premiumManagedAgentWorkflow['workflow_nodes'] ?? [])) >= 10
                    && count((array) ($premiumManagedAgentWorkflow['handoff_contracts'] ?? [])) >= 5
                    && count((array) ($premiumManagedAgentWorkflow['guardrails'] ?? [])) >= 5
                    && (bool) data_get($premiumManagedAgentWorkflow, 'durable_state.state_hash_required', false)
                    && (bool) ($premiumManagedAgentWorkflow['trace_export_required'] ?? false),
                'data_tool_workbenches_bound' => count($premiumWorkbenches) >= count((array) ($company['connectors'] ?? []))
                    && count(array_filter($premiumWorkbenches, static fn (array $workbench): bool => count((array) ($workbench['required_capabilities'] ?? [])) >= 5
                        && count((array) ($workbench['blocked_capabilities_without_operator'] ?? [])) >= 6)) === count($premiumWorkbenches),
                'connector_mcp_server_plan_bound' => count($premiumMcpServerPlan) >= count((array) ($company['connectors'] ?? []))
                    && count(array_filter($premiumMcpServerPlan, static fn (array $plan): bool => count((array) ($plan['required_tools'] ?? [])) >= 5
                        && count((array) ($plan['required_security_reviews'] ?? [])) >= 5
                        && (bool) ($plan['write_tools_enabled'] ?? true) === false)) === count($premiumMcpServerPlan),
                'replay_audit_harness_bound' => $premiumReplayBenchmark !== []
                    && (int) ($premiumReplayBenchmark['minimum_case_count_before_shadow'] ?? 0) >= 25
                    && count((array) ($premiumReplayBenchmark['required_scores'] ?? [])) >= 7
                    && (bool) ($premiumReplayBenchmark['replay_required_before_promotion'] ?? false),
                'calendar_wait_removed' => (int) data_get($company, 'premium_enterprise_agent_reference_model.accelerated_activation_contract.buildout_wait_days_required', 30) === 0,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
                'attestation_hash' => hash('sha256', 'premium_enterprise_agent_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'premium_enterprise_agent_reference_model.premium_model_hash', '')),
            ],
            'domain_company_execution_suite_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_company_execution_suite_runtime_attestation.v1',
                'suite_stack_bound' => (string) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_suite_hash', '') !== '',
                'source_catalog_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])) >= 7,
                'domain_operating_model_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.operating_roles', [])) >= 6
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.required_controls', [])) >= 7
                    && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.blocked_external_actions', [])) >= 9,
                'connector_execution_workbench_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])) >= count((array) ($company['connectors'] ?? []))
                    && count(array_filter(
                        (array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', []),
                        static fn (array $workbench): bool => count((array) ($workbench['supported_modes'] ?? [])) >= 5
                            && count((array) ($workbench['required_receipts'] ?? [])) >= 5
                            && count((array) ($workbench['blocked_operations_without_operator'] ?? [])) >= 9
                            && (bool) ($workbench['external_side_effects_enabled'] ?? true) === false,
                    )) === count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])),
                'flow_domain_execution_packet_bound' => $domainExecutionPacket !== []
                    && count((array) data_get($domainExecutionPacket, 'execution_stages', [])) >= 8
                    && count((array) data_get($domainExecutionPacket, 'required_artifacts', [])) >= 6
                    && count((array) data_get($domainExecutionPacket, 'quality_gates', [])) >= 5
                    && (bool) data_get($domainExecutionPacket, 'external_execution_allowed', true) === false,
                'flow_domain_risk_control_bound' => $domainRiskControlPacket !== []
                    && count((array) data_get($domainRiskControlPacket, 'control_frameworks', [])) >= 5
                    && count((array) data_get($domainRiskControlPacket, 'risk_checks', [])) >= 6
                    && count((array) data_get($domainRiskControlPacket, 'required_evidence', [])) >= 6
                    && (bool) data_get($domainRiskControlPacket, 'external_exception_acceptance_allowed', true) === false,
                'flow_domain_decision_room_bound' => $domainDecisionRoomPacket !== []
                    && count((array) data_get($domainDecisionRoomPacket, 'decision_types', [])) >= 4
                    && count((array) data_get($domainDecisionRoomPacket, 'required_sections', [])) >= 7
                    && (bool) data_get($domainDecisionRoomPacket, 'auto_decision_allowed', true) === false,
                'flow_domain_replay_eval_bound' => $domainReplayEvalPack !== []
                    && count((array) data_get($domainReplayEvalPack, 'case_types', [])) >= 7
                    && (int) data_get($domainReplayEvalPack, 'minimum_cases', 0) >= 25
                    && count((array) data_get($domainReplayEvalPack, 'required_scores', [])) >= 6
                    && (bool) data_get($domainReplayEvalPack, 'promotion_requires_green_replay', false),
                'domain_execution_observability_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true),
                'external_side_effects_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.external_side_effects_enabled', true),
                'operator_mandate_required_for_external_action' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
                'attestation_hash' => hash('sha256', 'domain_company_execution_suite_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_suite_hash', '')),
            ],
            'flow_work_product_delivery_runtime_attestation' => [
                'schema' => 'atlas.ai.company.flow_work_product_delivery_runtime_attestation.v1',
                'delivery_stack_bound' => (string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', '') !== '',
                'work_product_catalog_bound' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= count((array) ($company['work_products'] ?? [])),
                'flow_delivery_blueprint_bound' => $flowWorkProductDeliveryBlueprint !== []
                    && count((array) data_get($flowWorkProductDeliveryBlueprint, 'input_contract.required_inputs', [])) >= 6
                    && count((array) data_get($flowWorkProductDeliveryBlueprint, 'artifact_sections', [])) >= 8
                    && count((array) data_get($flowWorkProductDeliveryBlueprint, 'production_grade_requirements', [])) >= 7
                    && (bool) data_get($flowWorkProductDeliveryBlueprint, 'external_delivery_allowed', true) === false,
                'flow_acceptance_contract_bound' => $flowWorkProductAcceptance !== []
                    && count((array) data_get($flowWorkProductAcceptance, 'acceptance_tests', [])) >= 7
                    && count((array) data_get($flowWorkProductAcceptance, 'quality_floor', [])) >= 4
                    && count((array) data_get($flowWorkProductAcceptance, 'required_reviewers', [])) >= 3
                    && count((array) data_get($flowWorkProductAcceptance, 'failure_modes', [])) >= 6
                    && (bool) data_get($flowWorkProductAcceptance, 'auto_accept_allowed', true) === false,
                'flow_handoff_packet_bound' => $flowWorkProductHandoff !== []
                    && count((array) data_get($flowWorkProductHandoff, 'required_evidence', [])) >= 7
                    && count((array) data_get($flowWorkProductHandoff, 'handoff_targets', [])) >= 3
                    && (bool) data_get($flowWorkProductHandoff, 'atlas_external_send_allowed', true) === false,
                'flow_replay_artifact_check_bound' => $flowWorkProductReplayCheck !== []
                    && (int) data_get($flowWorkProductReplayCheck, 'minimum_replay_cases', 0) >= 25
                    && count((array) data_get($flowWorkProductReplayCheck, 'artifact_regression_checks', [])) >= 6
                    && count((array) data_get($flowWorkProductReplayCheck, 'adversarial_cases', [])) >= 5
                    && (bool) data_get($flowWorkProductReplayCheck, 'promotion_requires_green_replay', false),
                'delivery_observability_bound' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true),
                'external_delivery_allowed' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true),
                'external_side_effects_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_side_effects_enabled', true),
                'operator_acceptance_required_before_external_handoff' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.operator_acceptance_required_before_external_handoff', false),
                'attestation_hash' => hash('sha256', 'flow_work_product_delivery_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', '')),
            ],
            'domain_data_connector_operating_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_data_connector_operating_runtime_attestation.v1',
                'data_connector_stack_bound' => (string) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash', '') !== '',
                'source_data_room_catalog_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])) >= 7,
                'domain_data_products_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])) >= count((array) ($company['work_products'] ?? [])),
                'connector_permission_profiles_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= count((array) ($company['connectors'] ?? [])),
                'flow_data_connector_contract_bound' => $domainDataConnectorContract !== []
                    && count((array) data_get($domainDataConnectorContract, 'required_connectors', [])) >= 1
                    && count((array) data_get($domainDataConnectorContract, 'required_source_ids', [])) >= 1
                    && count((array) data_get($domainDataConnectorContract, 'required_data_products', [])) >= 3
                    && count((array) data_get($domainDataConnectorContract, 'data_contract_gates', [])) >= 6
                    && (int) data_get($domainDataConnectorContract, 'minimum_fixture_cases', 0) >= 25
                    && (bool) data_get($domainDataConnectorContract, 'external_mutation_allowed', true) === false,
                'connector_fixture_eval_suite_bound' => $domainConnectorFixtureEval !== []
                    && count((array) data_get($domainConnectorFixtureEval, 'case_mix', [])) >= 8
                    && (int) data_get($domainConnectorFixtureEval, 'minimum_case_count', 0) >= 25
                    && count((array) data_get($domainConnectorFixtureEval, 'required_scores', [])) >= 6
                    && (bool) data_get($domainConnectorFixtureEval, 'promotion_requires_green_eval', false),
                'domain_data_room_operating_model_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.required_controls', [])) >= 7
                    && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.blocked_external_actions', [])) >= 9,
                'data_connector_observability_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.calendar_wait_blocker_enabled', true),
                'write_tools_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true),
                'external_data_mutation_allowed' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true),
                'secret_export_allowed' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.secret_export_allowed', true),
                'read_only_probe_required_before_live_use' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.read_only_probe_required_before_live_use', false),
                'operator_mandate_required_for_external_action' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
                'attestation_hash' => hash('sha256', 'domain_data_connector_operating_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash', '')),
            ],
            'flow_live_read_connector_probe_runtime_attestation' => [
                'schema' => 'atlas.ai.company.flow_live_read_connector_probe_runtime_attestation.v1',
                'probe_stack_bound' => (string) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash', '') !== '',
                'connector_probe_profiles_bound' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= count((array) ($company['connectors'] ?? [])),
                'flow_live_read_probe_contract_bound' => $flowLiveReadProbeContract !== []
                    && count((array) data_get($flowLiveReadProbeContract, 'required_pre_probe_evidence', [])) >= 6
                    && count((array) data_get($flowLiveReadProbeContract, 'required_probe_outputs', [])) >= 7
                    && count((array) data_get($flowLiveReadProbeContract, 'failure_handling', [])) >= 5
                    && (int) data_get($flowLiveReadProbeContract, 'minimum_probe_cases', 0) >= 12
                    && (bool) data_get($flowLiveReadProbeContract, 'external_mutation_allowed', true) === false,
                'flow_probe_evidence_matrix_bound' => $flowLiveReadProbeEvidence !== []
                    && count((array) data_get($flowLiveReadProbeEvidence, 'required_green_evidence', [])) >= 8
                    && (bool) data_get($flowLiveReadProbeEvidence, 'promotion_requires_operator_acceptance', false)
                    && (bool) data_get($flowLiveReadProbeEvidence, 'external_execution_authority_granted', true) === false,
                'probe_observability_bound' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true),
                'live_read_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.live_read_allowed', false),
                'write_tools_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true),
                'external_mutation_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true),
                'credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.credential_material_in_packet_allowed', true),
                'operator_scope_required_before_live_connector_probe' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.operator_scope_required_before_live_connector_probe', false),
                'attestation_hash' => hash('sha256', 'flow_live_read_connector_probe_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash', '')),
            ],
            'external_research_adoption_runtime_attestation' => [
                'schema' => 'atlas.ai.company.external_research_adoption_runtime_attestation.v1',
                'research_stack_bound' => (string) data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash', '') !== '',
                'source_basis_bound' => count($externalResearchSourceBasis) >= 12,
                'source_basis_count' => count($externalResearchSourceBasis),
                'source_links_required' => count(array_filter(
                    $externalResearchSourceBasis,
                    static fn (array $source): bool => (bool) ($source['source_links_required'] ?? false),
                )) === count($externalResearchSourceBasis),
                'official_framework_repository_count' => count($externalResearchOfficialRepositories),
                'domain_repository_candidate_count' => count($externalResearchDomainRepositories),
                'repository_catalog_bound' => count($externalResearchOfficialRepositories) >= 8 && count($externalResearchDomainRepositories) >= 3,
                'flow_adoption_matrix_bound' => $externalResearchFlowMatrix !== []
                    && count((array) ($externalResearchFlowMatrix['source_refs'] ?? [])) >= 5
                    && count((array) ($externalResearchFlowMatrix['repository_refs'] ?? [])) >= 5
                    && count((array) ($externalResearchFlowMatrix['domain_repository_refs'] ?? [])) >= 3
                    && count((array) ($externalResearchFlowMatrix['adoption_gates'] ?? [])) >= 5
                    && (bool) ($externalResearchFlowMatrix['external_side_effects_enabled'] ?? true) === false,
                'flow_source_ref_count' => count((array) ($externalResearchFlowMatrix['source_refs'] ?? [])),
                'flow_repository_ref_count' => count((array) ($externalResearchFlowMatrix['repository_refs'] ?? [])),
                'flow_domain_repository_ref_count' => count((array) ($externalResearchFlowMatrix['domain_repository_refs'] ?? [])),
                'capability_map_bound' => count($externalResearchCapabilityMap) >= 12
                    && count(array_filter(
                        $externalResearchCapabilityMap,
                        static fn (array $capability): bool => (bool) ($capability['external_side_effects_enabled'] ?? true) === false
                            && count((array) ($capability['review_artifacts_required'] ?? [])) >= 5,
                    )) === count($externalResearchCapabilityMap),
                'capability_map_count' => count($externalResearchCapabilityMap),
                'connector_backlog_bound' => count($externalResearchConnectorBacklog) >= max(1, count((array) ($externalResearchFlowMatrix['connector_candidates'] ?? [])))
                    && count(array_filter(
                        $externalResearchConnectorBacklog,
                        static fn (array $connector): bool => (bool) ($connector['external_side_effects_enabled'] ?? true) === false
                            && count((array) ($connector['required_artifacts'] ?? [])) >= 5,
                    )) === count($externalResearchConnectorBacklog),
                'connector_backlog_count' => count($externalResearchConnectorBacklog),
                'production_gates_bound' => count((array) ($externalResearchProductionGates['contract_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['fixture_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['shadow_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['supervised_ready'] ?? [])) >= 3
                    && count((array) ($externalResearchProductionGates['autonomy_claim_ready'] ?? [])) >= 3,
                'runtime_ingestion_without_source_review_allowed' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.runtime_ingestion_without_source_review_allowed', true),
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true),
                'external_research_side_effects_default' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.external_side_effects_default', true),
                'operator_mandate_required_for_external_actions' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.calendar_wait_blocker_enabled', true),
                'attestation_hash' => hash('sha256', 'external_research_adoption_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash', '').'|'.(string) ($externalResearchFlowMatrix['matrix_hash'] ?? '')),
            ],
            'enterprise_flow_operating_package' => $operatingPackage,
            'enterprise_flow_operational_dossier' => $operationalDossier,
            'enterprise_autonomy_promotion_packet' => $autonomyPromotionPacket,
            'workforce_capacity_runtime_attestation' => [
                'schema' => 'atlas.ai.company.workforce_capacity_runtime_attestation.v1',
                'workforce_stack_bound' => (string) data_get($company, 'enterprise_workforce_capacity_stack.workforce_hash', '') !== '',
                'org_model_bound' => (string) data_get($company, 'enterprise_workforce_capacity_stack.org_model.coverage_model', '') === 'manager_specialists_independent_review_operator_checkpoint',
                'manager_agent' => (string) data_get($company, 'enterprise_workforce_capacity_stack.org_model.manager_agent', ''),
                'agent_capacity_plan_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])) >= 4,
                'agent_capacity_plan_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])),
                'flow_staffing_bound' => $workforceStaffing !== []
                    && (bool) data_get($workforceStaffing, 'operator_checkpoint_required', false)
                    && (string) data_get($workforceStaffing, 'minimum_staffing_state', '') === 'primary_backup_reviewer_defined',
                'primary_agent' => (string) data_get($workforceStaffing, 'primary_agent', ''),
                'backup_agent' => (string) data_get($workforceStaffing, 'backup_agent', ''),
                'reviewer' => (string) data_get($workforceStaffing, 'reviewer', ''),
                'training_enablement_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])) >= 4,
                'training_plan_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])),
                'succession_continuity_bound' => (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.single_agent_bottleneck_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.manual_operator_fallback_required', false)
                    && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.backup_assignment_required_for_every_flow', false),
                'capacity_observability_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.capacity_observability.required_metrics', [])) >= 5,
                'capacity_observability_metric_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.capacity_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => false,
                'single_agent_bottleneck_allowed' => (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.single_agent_bottleneck_allowed', true),
                'external_unreviewed_staffing_change_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'workforce_capacity_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_workforce_capacity_stack.workforce_hash', '').'|'.(string) data_get($workforceStaffing, 'staffing_hash', '')),
            ],
            'portfolio_dependency_runtime_attestation' => [
                'schema' => 'atlas.ai.company.portfolio_dependency_runtime_attestation.v1',
                'dependency_stack_bound' => (string) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_hash', '') !== '',
                'portfolio_role_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.decision_scope', [])) >= 4
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.blocked_scope', [])) >= 4,
                'dependency_intake_contract_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_intake_contract.required_fields', [])) >= 7
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_intake_contract.reject_when_missing', [])) >= 4,
                'upstream_dependency_map_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])) >= 1,
                'upstream_dependency_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])),
                'integration_dependency_map_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])) >= 1,
                'integration_dependency_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])),
                'flow_dependency_routing_bound' => $portfolioDependencyRouting !== []
                    && (bool) data_get($portfolioDependencyRouting, 'requires_dependency_check_before_execution', false)
                    && count((array) data_get($portfolioDependencyRouting, 'requires_portfolio_review_when', [])) >= 4,
                'dependency_priority' => (string) data_get($portfolioDependencyRouting, 'default_portfolio_priority', ''),
                'escalation_conflict_model_bound' => (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.conflict_packet_required', false)
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.decision_receipt_required', false)
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.external_side_effects_blocked_until_resolved', false),
                'portfolio_reporting_contract_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_sections', [])) >= 6
                    && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_metrics', [])) >= 4
                    && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.receipt_hash_required', false),
                'calendar_wait_blocker_enabled' => false,
                'external_spend_publish_write_trade_or_transfer_allowed' => false,
                'cross_company_dependency_without_typed_handoff_allowed' => false,
                'unresolved_conflict_external_side_effect_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'portfolio_dependency_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_hash', '').'|'.(string) data_get($portfolioDependencyRouting, 'routing_hash', '')),
            ],
            'autonomy_promotion_runtime_attestation' => [
                'schema' => 'atlas.ai.company.autonomy_promotion_runtime_attestation.v1',
                'autonomy_ladder_bound' => count((array) ($autonomyPromotionPacket['autonomy_ladder'] ?? [])) >= 5,
                'autonomy_level_count' => count((array) ($autonomyPromotionPacket['autonomy_ladder'] ?? [])),
                'fixture_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.fixture_internal.ready', false),
                'shadow_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.shadow_internal.ready', false),
                'supervised_internal_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_internal.ready', false),
                'supervised_external_packet_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_external_packet.ready', false),
                'limited_external_autonomy_blocked' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.ready', true) === false
                    && count((array) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.blockers', [])) >= 8,
                'current_allowed_level' => (string) data_get($autonomyPromotionPacket, 'current_allowed_level', ''),
                'next_promotion_target' => (string) data_get($autonomyPromotionPacket, 'next_promotion_target', ''),
                'evidence_spine_bound' => count((array) data_get($autonomyPromotionPacket, 'promotion_evidence_spine', [])) >= 14,
                'evidence_ref_count' => count((array) data_get($autonomyPromotionPacket, 'promotion_evidence_spine', [])),
                'rollback_reconciliation_bound' => (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.rollback_drill_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.post_execution_reconciliation_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.compensation_plan_required_for_external_action', false),
                'budget_loss_cap_bound' => (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.budget_cap_signature_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.loss_cap_signature_required', false)
                    && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.real_capital_action_allowed_without_signature', true) === false,
                'operator_mandate_required_for_external_autonomy' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.operator_mandate_required_for_external_autonomy', false),
                'second_reviewer_required_for_external_autonomy' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.second_reviewer_required_for_external_autonomy', false),
                'calendar_wait_blocker_enabled' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.calendar_wait_blocker_enabled', true),
                'external_autonomous_execution_allowed' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.external_autonomous_execution_allowed', true),
                'attestation_hash' => (string) data_get($autonomyPromotionPacket, 'autonomy_promotion_hash', ''),
            ],
            'enterprise_vertical_solution_kit' => $verticalSolutionKit,
            'enterprise_domain_business_execution_cell' => $businessExecutionCell,
            'enterprise_domain_operating_depth_packet' => $domainOperatingDepthPacket,
            'enterprise_domain_agent_crew' => $domainAgentCrew,
            'operational_dossier_runtime_attestation' => [
                'schema' => 'atlas.ai.company.enterprise_operational_dossier_runtime_attestation.v1',
                'dossier_bound' => $operationalDossier !== [],
                'evidence_spine_bound' => count((array) ($operationalDossier['evidence_spine'] ?? [])) >= 10,
                'evidence_spine_count' => count((array) ($operationalDossier['evidence_spine'] ?? [])),
                'control_plane_bound' => count((array) data_get($operationalDossier, 'control_plane.required_controls', [])) >= 8,
                'control_count' => count((array) data_get($operationalDossier, 'control_plane.required_controls', [])),
                'decision_packet_bound' => (bool) data_get($operationalDossier, 'decision_packet.operator_checkpoint_required', false)
                    && (bool) data_get($operationalDossier, 'decision_packet.external_delivery_allowed', true) === false,
                'promotion_path_bound' => count((array) ($operationalDossier['promotion_path'] ?? [])) >= 4,
                'promotion_stage_count' => count((array) ($operationalDossier['promotion_path'] ?? [])),
                'scorecard_bound' => (float) data_get($operationalDossier, 'readiness_scorecard.operational_readiness_score', 0.0) >= 0.95,
                'operational_readiness_score' => (float) data_get($operationalDossier, 'readiness_scorecard.operational_readiness_score', 0.0),
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
                'attestation_hash' => hash('sha256', 'operational_dossier_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($operationalDossier['dossier_hash'] ?? '')),
            ],
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
            'commercial_operations_runtime_attestation' => [
                'schema' => 'atlas.ai.company.commercial_operations_runtime_attestation.v1',
                'customer_market_operations_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== '',
                'offer_packaging_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5,
                'offer_packaging_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
                'customer_journey_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= count((array) ($company['flows'] ?? [])),
                'customer_journey_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])),
                'customer_success_scorecard_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4,
                'customer_success_metric_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])),
                'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== '',
                'contract_entitlement_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5,
                'contract_entitlement_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
                'onboarding_success_plan_bound' => $accountOnboardingPlan !== [],
                'onboarding_success_plan_id' => (string) ($accountOnboardingPlan['plan_id'] ?? $accountOnboardingPlan['flow_id'] ?? ''),
                'service_review_renewal_bound' => $accountServiceReview !== [],
                'service_review_renewal_id' => (string) ($accountServiceReview['calendar_id'] ?? $accountServiceReview['flow_id'] ?? ''),
                'account_health_risk_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? [])),
                'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
                'billing_revenue_model_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', '') !== ''
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false,
                'billing_mode' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', ''),
                'vendor_legal_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== '',
                'vendor_due_diligence_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) ($company['connectors'] ?? [])),
                'vendor_due_diligence_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
                'source_terms_review_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5,
                'source_terms_review_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])),
                'flow_procurement_routing_bound' => $vendorProcurementRouting !== [],
                'flow_procurement_route_id' => (string) ($vendorProcurementRouting['route_id'] ?? $vendorProcurementRouting['flow_id'] ?? ''),
                'vendor_operability_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) ($company['connectors'] ?? [])),
                'vendor_operability_scorecard_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])),
                'calendar_wait_blocker_enabled' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_vendor_procurement_allowed' => false,
                'public_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'commercial_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '').'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '')),
            ],
            'domain_provider_workbench_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_provider_workbench_runtime_attestation.v1',
                'provider_contracts_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])) >= 5,
                'provider_contract_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])),
                'connector_workbenches_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])) >= count((array) ($company['connectors'] ?? [])),
                'connector_workbench_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])),
                'flow_provider_route_bound' => $flowProviderRoute !== [],
                'flow_provider_route_id' => (string) ($flowProviderRoute['route_hash'] ?? $flowProviderRoute['flow_id'] ?? ''),
                'flow_required_connector_count' => count((array) ($flowProviderRoute['required_connectors'] ?? [])),
                'primary_provider_count' => count((array) ($flowProviderRoute['primary_provider_ids'] ?? [])),
                'route_stage_count' => count((array) ($flowProviderRoute['route_stages'] ?? [])),
                'route_receipt_count' => count((array) ($flowProviderRoute['required_receipts'] ?? [])),
                'provider_eval_cases_bound' => $providerEvaluationCase !== []
                    && (int) ($providerEvaluationCase['minimum_cases_before_shadow'] ?? 0) >= 15
                    && count((array) ($providerEvaluationCase['case_types'] ?? [])) >= 6,
                'provider_eval_case_pack_id' => (string) ($providerEvaluationCase['case_pack_id'] ?? ''),
                'provider_eval_case_type_count' => count((array) ($providerEvaluationCase['case_types'] ?? [])),
                'provider_data_product_lineage_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])) >= 5,
                'provider_lineage_contract_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])),
                'provider_workbench_observability_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])) >= 6,
                'provider_workbench_metric_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])),
                'source_claims_require_provider_lineage' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.source_claims_require_provider_lineage', false),
                'credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.credential_material_in_packet_allowed', true),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.calendar_wait_blocker_enabled', true),
                'provider_write_or_paid_action_default' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.provider_write_or_paid_action_default', true),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'domain_provider_workbench_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_hash', '').'|'.(string) ($flowProviderRoute['route_hash'] ?? '')),
            ],
            'flow_benchmark_replay_runtime_attestation' => [
                'schema' => 'atlas.ai.company.flow_benchmark_replay_runtime_attestation.v1',
                'offline_dataset_contract_bound' => $offlineDatasetContract !== []
                    && (int) ($offlineDatasetContract['minimum_examples'] ?? 0) >= 10
                    && count((array) ($offlineDatasetContract['required_fields'] ?? [])) >= 5,
                'dataset_id' => (string) ($offlineDatasetContract['dataset_id'] ?? ''),
                'minimum_examples' => (int) ($offlineDatasetContract['minimum_examples'] ?? 0),
                'dataset_required_field_count' => count((array) ($offlineDatasetContract['required_fields'] ?? [])),
                'trace_grading_rubric_bound' => $traceGradingRubric !== []
                    && count((array) ($traceGradingRubric['graded_trace_components'] ?? [])) >= 6
                    && count((array) ($traceGradingRubric['score_keys'] ?? [])) >= 6
                    && (float) ($traceGradingRubric['minimum_score'] ?? 0.0) >= 0.86,
                'trace_grading_rubric_id' => (string) ($traceGradingRubric['rubric_id'] ?? ''),
                'trace_component_count' => count((array) ($traceGradingRubric['graded_trace_components'] ?? [])),
                'score_key_count' => count((array) ($traceGradingRubric['score_keys'] ?? [])),
                'adversarial_regression_bound' => $adversarialRegressionCase !== []
                    && count((array) ($adversarialRegressionCase['case_types'] ?? [])) >= 6
                    && in_array('perform_external_side_effect', (array) ($adversarialRegressionCase['must_not_do'] ?? []), true),
                'adversarial_case_suite' => (string) ($adversarialRegressionCase['case_suite'] ?? ''),
                'adversarial_case_type_count' => count((array) ($adversarialRegressionCase['case_types'] ?? [])),
                'deterministic_state_assertion_bound' => $deterministicStateAssertion !== []
                    && count((array) ($deterministicStateAssertion['state_objects'] ?? [])) >= 5
                    && count((array) ($deterministicStateAssertion['assertions'] ?? [])) >= 4,
                'state_assertion_suite' => (string) ($deterministicStateAssertion['assertion_suite'] ?? ''),
                'state_object_count' => count((array) ($deterministicStateAssertion['state_objects'] ?? [])),
                'state_assertion_count' => count((array) ($deterministicStateAssertion['assertions'] ?? [])),
                'replay_comparison_matrix_bound' => $replayComparisonMatrix !== []
                    && count((array) ($replayComparisonMatrix['replay_modes'] ?? [])) >= 4
                    && count((array) ($replayComparisonMatrix['comparison_dimensions'] ?? [])) >= 7
                    && count((array) ($replayComparisonMatrix['required_artifacts'] ?? [])) >= 6,
                'replay_mode_count' => count((array) ($replayComparisonMatrix['replay_modes'] ?? [])),
                'comparison_dimension_count' => count((array) ($replayComparisonMatrix['comparison_dimensions'] ?? [])),
                'benchmark_observability_bound' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])) >= 5,
                'benchmark_observability_metric_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])),
                'minimum_offline_eval_score' => (float) data_get($company, 'enterprise_flow_benchmark_replay_stack.promotion_quality_gates.minimum_offline_eval_score', 0.0),
                'policy_findings_allowed' => (int) data_get($company, 'enterprise_flow_benchmark_replay_stack.promotion_quality_gates.policy_findings_allowed', 1),
                'synthetic_score_claims_allowed' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.synthetic_score_claims_allowed', true),
                'promotion_without_replay_green_allowed' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.promotion_without_replay_green_allowed', true),
                'external_model_or_paid_benchmark_requires_operator_approval' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.external_model_or_paid_benchmark_requires_operator_approval', false),
                'external_benchmark_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'flow_benchmark_replay_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_replay_hash', '').'|'.(string) ($replayComparisonMatrix['replay_hash'] ?? '')),
            ],
            'connector_certification_preflight_runtime_attestation' => [
                'schema' => 'atlas.ai.company.connector_certification_preflight_runtime_attestation.v1',
                'flow_connector_usage_bound' => $flowConnectorUsage !== [] && $flowConnectorCount > 0,
                'flow_connector_cutover_bound' => $flowConnectorCutover !== []
                    && (bool) ($flowConnectorCutover['manual_handoff_packet_required'] ?? false)
                    && (bool) ($flowConnectorCutover['auto_execute_allowed'] ?? true) === false
                    && (bool) ($flowConnectorCutover['external_side_effects_enabled'] ?? true) === false,
                'flow_connector_count' => $flowConnectorCount,
                'flow_connector_ids' => $flowConnectorIds,
                'adapter_contracts_bound' => $flowConnectorCount > 0
                    && count($connectorAdapterContracts) === $flowConnectorCount
                    && count(array_filter($connectorAdapterContracts, static fn (array $row): bool => count((array) ($row['required_contract_fields'] ?? [])) >= 6 && (bool) ($row['schema_validation_required'] ?? false))) === $flowConnectorCount,
                'auth_boundaries_bound' => $flowConnectorCount > 0
                    && count($connectorAuthBoundaries) === $flowConnectorCount
                    && count(array_filter($connectorAuthBoundaries, static fn (array $row): bool => (string) ($row['credential_binding'] ?? '') === 'vault_reference_only' && (bool) ($row['secret_material_in_packet_allowed'] ?? true) === false)) === $flowConnectorCount,
                'sandbox_probes_bound' => $flowConnectorCount > 0
                    && count($connectorSandboxProbes) === $flowConnectorCount
                    && count(array_filter($connectorSandboxProbes, static fn (array $row): bool => count((array) ($row['probe_modes'] ?? [])) >= 5 && count((array) ($row['success_criteria'] ?? [])) >= 5)) === $flowConnectorCount,
                'consumer_provider_contract_tests_bound' => $flowConnectorCount > 0
                    && count($connectorContractTests) === $flowConnectorCount
                    && count(array_filter($connectorContractTests, static fn (array $row): bool => count((array) ($row['consumer_assumptions'] ?? [])) >= 4 && count((array) ($row['provider_verification'] ?? [])) >= 4)) === $flowConnectorCount,
                'connector_data_lineage_bound' => $flowConnectorCount > 0
                    && count($connectorDataLineage) === $flowConnectorCount
                    && count(array_filter($connectorDataLineage, static fn (array $row): bool => count((array) ($row['lineage_required'] ?? [])) >= 5 && (bool) ($row['redaction_required_before_provider_payload'] ?? false))) === $flowConnectorCount,
                'replay_fixture_mock_server_bound' => $flowConnectorCount > 0
                    && count($connectorReplayFixtures) === $flowConnectorCount
                    && count(array_filter($connectorReplayFixtures, static fn (array $row): bool => count((array) ($row['fixture_requirements'] ?? [])) >= 5 && (bool) ($row['required_for_offline_eval'] ?? false))) === $flowConnectorCount,
                'connector_slo_failure_modes_bound' => $flowConnectorCount > 0
                    && count($connectorSloFailureModes) === $flowConnectorCount
                    && count(array_filter($connectorSloFailureModes, static fn (array $row): bool => count((array) ($row['failure_modes'] ?? [])) >= 6 && (float) data_get($row, 'slo.schema_match_rate', 0.0) >= 1.0)) === $flowConnectorCount,
                'production_preflight_contracts_bound' => $flowConnectorCount > 0
                    && count($productionPreflightContracts) === $flowConnectorCount
                    && count(array_filter($productionPreflightContracts, static fn (array $row): bool => (bool) data_get($row, 'credential_vault_binding.credential_material_in_packet_allowed', true) === false && (bool) data_get($row, 'live_data_readiness.live_mutation_allowed', true) === false)) === $flowConnectorCount,
                'production_readiness_evidence_bound' => $flowConnectorCount > 0
                    && count($productionReadinessEvidence) === $flowConnectorCount
                    && count(array_filter($productionReadinessEvidence, static fn (array $row): bool => count((array) ($row['required_evidence'] ?? [])) >= 8 && (bool) ($row['external_side_effects_enabled'] ?? true) === false)) === $flowConnectorCount,
                'connector_certification_observability_bound' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])) >= 5,
                'connector_certification_metric_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])),
                'cutover_observability_bound' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])) >= 6,
                'cutover_observability_metric_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.calendar_wait_blocker_enabled', true),
                'production_cutover_without_operator_signed_scope_allowed' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.production_cutover_without_operator_signed_scope_allowed', true),
                'real_credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.real_credential_material_in_packet_allowed', true),
                'write_or_paid_mode_allowed_by_default' => (bool) data_get($company, 'enterprise_connector_certification_stack.certification_policy.write_or_paid_mode_allowed_by_default', true),
                'external_connector_cutover_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'connector_certification_preflight_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_connector_certification_stack.connector_certification_hash', '').'|'.(string) data_get($company, 'enterprise_production_connector_preflight_stack.production_connector_preflight_hash', '').'|'.(string) ($flowConnectorCutover['cutover_hash'] ?? '')),
            ],
            'command_center_control_tower_runtime_attestation' => [
                'schema' => 'atlas.ai.company.command_center_control_tower_runtime_attestation.v1',
                'control_tower_bound' => (string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '') !== '',
                'control_tower_lane_bound' => $controlTowerLane !== []
                    && count((array) ($controlTowerLane['intake_states'] ?? [])) >= 7
                    && count((array) ($controlTowerLane['required_run_artifacts'] ?? [])) >= 6,
                'control_tower_lane_id' => (string) ($controlTowerLane['lane_id'] ?? ''),
                'run_queue_model_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                    && (string) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.dead_letter_queue', '') !== '',
                'cadence_scheduler_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= count((array) ($company['cadences'] ?? [])),
                'incident_exception_desk_bound' => $incidentExceptionDesk !== []
                    && count((array) ($incidentExceptionDesk['exception_types'] ?? [])) >= 6
                    && count((array) ($incidentExceptionDesk['resolution_states'] ?? [])) >= 5,
                'change_window_release_bound' => $changeWindowRelease !== []
                    && (bool) ($changeWindowRelease['rollback_required'] ?? false)
                    && count((array) ($changeWindowRelease['required_approvals'] ?? [])) >= 3,
                'connector_probe_plan_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= count((array) ($company['connectors'] ?? [])),
                'dashboard_operations_map_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.dashboard_operations_map', [])) >= 3,
                'human_interrupt_bound' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.human_interrupt_and_escalation_model.handoff_packet_required', false)
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.human_interrupt_and_escalation_model.interrupt_points', [])) >= 5,
                'run_observability_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.required_metrics', [])) >= 6
                    && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.trace_fields', [])) >= 8,
                'flow_command_card_bound' => $flowCommandCard !== []
                    && count((array) ($flowCommandCard['quality_gates'] ?? [])) >= 5
                    && count((array) ($flowCommandCard['required_receipts'] ?? [])) >= 5
                    && (bool) ($flowCommandCard['external_execution_allowed'] ?? true) === false,
                'flow_command_card_hash' => (string) ($flowCommandCard['card_hash'] ?? ''),
                'command_center_bound' => (string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', '') !== '',
                'command_center_cells_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.operating_cells', [])) >= 6,
                'connector_panels_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])) >= count((array) ($company['connectors'] ?? [])),
                'operator_console_views_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.operator_console_views', [])) >= 4,
                'work_product_factory_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.work_product_factory_map', [])) >= 5,
                'command_center_kpis_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.command_center_kpis', [])) >= count((array) ($company['metrics'] ?? [])),
                'escalation_pause_bound' => (bool) data_get($company, 'enterprise_company_command_center_stack.escalation_and_pause_protocol.auto_resume_external_action_allowed', true) === false
                    && count((array) data_get($company, 'enterprise_company_command_center_stack.escalation_and_pause_protocol.pause_triggers', [])) >= 6,
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.calendar_wait_blocker_enabled', true),
                'external_write_spend_trade_publish_deploy_delete_allowed' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true),
                'secret_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.secret_material_in_packet_allowed', true),
                'control_tower_external_side_effects_default' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true),
                'run_without_decision_receipt_allowed' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.run_without_decision_receipt_allowed', true),
                'auto_retry_external_action_allowed' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.auto_retry_external_action_allowed', true),
                'external_control_tower_action_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'command_center_control_tower_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', '').'|'.(string) ($flowCommandCard['card_hash'] ?? '')),
            ],
            'operational_dress_rehearsal_runtime_attestation' => [
                'schema' => 'atlas.ai.company.operational_dress_rehearsal_runtime_attestation.v1',
                'dress_rehearsal_stack_bound' => (string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '') !== '',
                'rehearsal_runbook_bound' => $rehearsalRunbook !== []
                    && count((array) ($rehearsalRunbook['staging_sequence'] ?? [])) >= 7
                    && count((array) ($rehearsalRunbook['acceptance_criteria'] ?? [])) >= 6
                    && in_array('external_write', (array) ($rehearsalRunbook['blocked_during_rehearsal'] ?? []), true),
                'rehearsal_runbook_hash' => (string) ($rehearsalRunbook['runbook_hash'] ?? ''),
                'flow_connector_count' => $flowConnectorCount,
                'live_read_probe_plan_bound' => $flowConnectorCount > 0
                    && count($rehearsalLiveReadProbes) === $flowConnectorCount
                    && count(array_filter($rehearsalLiveReadProbes, static fn (array $row): bool => (bool) ($row['mutation_allowed'] ?? true) === false && count((array) ($row['required_before_supervised_production'] ?? [])) >= 6)) === $flowConnectorCount,
                'operator_acceptance_packet_bound' => $operatorAcceptancePacket !== []
                    && count((array) ($operatorAcceptancePacket['required_signatures'] ?? [])) >= 2
                    && count((array) ($operatorAcceptancePacket['required_artifacts'] ?? [])) >= 6
                    && (bool) ($operatorAcceptancePacket['auto_accept_allowed'] ?? true) === false
                    && (bool) ($operatorAcceptancePacket['external_execution_enabled_by_packet'] ?? true) === false,
                'operator_acceptance_hash' => (string) ($operatorAcceptancePacket['acceptance_hash'] ?? ''),
                'rollback_drill_bound' => $rollbackDrill !== []
                    && count((array) ($rollbackDrill['drill_steps'] ?? [])) >= 5
                    && count((array) ($rollbackDrill['success_criteria'] ?? [])) >= 4
                    && (bool) ($rollbackDrill['required_before_any_external_mutation'] ?? false),
                'rollback_drill_hash' => (string) ($rollbackDrill['rollback_hash'] ?? ''),
                'promotion_evidence_bound' => $promotionEvidence !== []
                    && count((array) ($promotionEvidence['must_have_green'] ?? [])) >= 6
                    && (int) ($promotionEvidence['calendar_wait_days_required'] ?? 30) === 0
                    && (bool) ($promotionEvidence['external_side_effects_enabled'] ?? true) === false,
                'promotion_evidence_hash' => (string) ($promotionEvidence['promotion_hash'] ?? ''),
                'dress_rehearsal_observability_bound' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7
                    && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.alert_on', [])) >= 5,
                'dress_rehearsal_metric_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.calendar_wait_blocker_enabled', true),
                'external_mutation_allowed_during_rehearsal' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.external_mutation_allowed_during_rehearsal', true),
                'production_cutover_allowed_without_signed_acceptance' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.production_cutover_allowed_without_signed_acceptance', true),
                'operator_and_domain_owner_acceptance_required' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.operator_and_domain_owner_acceptance_required', false),
                'second_reviewer_required_for_sensitive_scope' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.second_reviewer_required_for_spend_trade_publish_security_or_delete_scope', false),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'operational_dress_rehearsal_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '').'|'.(string) ($rehearsalRunbook['runbook_hash'] ?? '').'|'.(string) ($operatorAcceptancePacket['acceptance_hash'] ?? '').'|'.(string) ($rollbackDrill['rollback_hash'] ?? '').'|'.(string) ($promotionEvidence['promotion_hash'] ?? '')),
            ],
            'semantic_operating_graph_runtime_attestation' => [
                'schema' => 'atlas.ai.company.semantic_operating_graph_runtime_attestation.v1',
                'semantic_graph_bound' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', '') !== '',
                'node_catalog_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= (
                    count((array) ($company['functions'] ?? []))
                    + count((array) ($company['agent_roles'] ?? []))
                    + count((array) ($company['flows'] ?? []))
                    + count((array) ($company['connectors'] ?? []))
                    + count((array) ($company['metrics'] ?? []))
                    + count((array) ($company['work_products'] ?? []))
                ),
                'node_catalog_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])),
                'flow_relationship_edge_bound' => $semanticFlowEdge !== [],
                'flow_relationship_edge_id' => (string) ($semanticFlowEdge['edge_id'] ?? $semanticFlowEdge['flow_id'] ?? ''),
                'edge_type_count' => count((array) ($semanticFlowEdge['edge_types'] ?? [])),
                'operating_views_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4,
                'operating_view_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])),
                'drift_detection_rules_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])) >= 5,
                'drift_detection_rule_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])),
                'graph_export_contract_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.required_fields', [])) >= 6
                    && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.operator_visualization_ready', false),
                'graph_export_format_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.formats', [])),
                'graph_observability_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_observability.required_metrics', [])) >= 5,
                'graph_observability_metric_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_observability.required_metrics', [])),
                'graph_mutation_mode' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.graph_mutation_mode', ''),
                'stale_or_missing_edge_blocks_autonomy_claim' => (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.stale_or_missing_edge_blocks_autonomy_claim', false),
                'raw_secret_or_sensitive_payload_export_allowed' => (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.raw_secret_or_sensitive_payload_export_allowed', true),
                'external_graph_mutation_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'semantic_operating_graph_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', '').'|'.(string) ($semanticFlowEdge['edge_hash'] ?? '')),
            ],
            'customer_account_revenue_runtime_attestation' => [
                'schema' => 'atlas.ai.company.customer_account_revenue_runtime_attestation.v1',
                'customer_market_runtime_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== '',
                'customer_segment_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_and_stakeholder_model.stakeholder_segments', [])),
                'positioning_claim_review_required' => (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_positioning_system.claim_review_required_before_external_use', false),
                'offer_packaging_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5,
                'offer_packaging_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
                'journey_lifecycle_bound' => $customerJourney !== [],
                'journey_lifecycle_id' => (string) ($customerJourney['journey_hash'] ?? $customerJourney['flow_id'] ?? ''),
                'customer_success_scorecard_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4,
                'customer_success_metric_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])),
                'commercial_service_catalog_bound' => count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5,
                'commercial_service_count' => count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])),
                'business_kpi_bound' => count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4,
                'business_kpi_count' => count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])),
                'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== '',
                'account_segment_playbook_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4,
                'account_segment_playbook_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])),
                'contract_entitlement_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5,
                'contract_entitlement_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
                'onboarding_success_plan_bound' => $accountOnboardingPlan !== [],
                'onboarding_success_plan_id' => (string) ($accountOnboardingPlan['success_plan_id'] ?? $accountOnboardingPlan['flow_id'] ?? ''),
                'service_review_renewal_bound' => $accountServiceReview !== [],
                'service_review_renewal_id' => (string) ($accountServiceReview['calendar_hash'] ?? $accountServiceReview['flow_id'] ?? ''),
                'account_health_risk_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? [])),
                'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
                'billing_revenue_model_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', '') !== ''
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false,
                'account_observability_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])) >= 6,
                'account_observability_metric_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])),
                'voice_of_customer_loop_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.voice_of_customer_and_feedback_loop.required_links', [])) >= 4,
                'growth_retention_model_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.growth_and_retention_operating_model.allowed_actions', [])) >= 4,
                'calendar_wait_blocker_enabled' => false,
                'revenue_claim_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_payment_collection_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'customer_account_revenue_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '')),
            ],
            'enterprise_productized_service_offer' => $productizedServiceOffer,
            'productized_service_runtime_attestation' => [
                'schema' => 'atlas.ai.company.productized_service_runtime_attestation.v1',
                'product_stack_bound' => (string) data_get($company, 'enterprise_productized_service_stack.productized_service_hash', '') !== '',
                'domain_product_line_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])) >= 5,
                'domain_product_line_count' => count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])),
                'service_offer_bound' => $productizedServiceOffer !== [],
                'service_offer_id' => (string) data_get($productizedServiceOffer, 'offer_id', ''),
                'delivery_blueprint_bound' => $productizedDeliveryBlueprint !== []
                    && count((array) data_get($productizedDeliveryBlueprint, 'delivery_nodes', [])) >= 9
                    && count((array) data_get($productizedDeliveryBlueprint, 'required_evidence', [])) >= 8,
                'intake_contract_bound' => $productizedIntakeContract !== []
                    && count((array) data_get($productizedIntakeContract, 'required_fields', [])) >= 8
                    && count((array) data_get($productizedIntakeContract, 'qualification_rules', [])) >= 4,
                'sla_success_contract_bound' => $productizedSlaContract !== []
                    && (float) data_get($productizedSlaContract, 'quality_floor', 0.0) >= 0.9
                    && (float) data_get($productizedSlaContract, 'source_faithfulness_floor', 0.0) >= 0.95
                    && count((array) data_get($productizedSlaContract, 'success_metrics', [])) >= 1,
                'pricing_packaging_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])) >= 5,
                'pricing_package_count' => count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])),
                'gtm_motion_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])) >= 5,
                'gtm_motion_count' => count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])),
                'proof_template_bound' => $productizedProofTemplate !== []
                    && count((array) data_get($productizedProofTemplate, 'required_sections', [])) >= 7
                    && (bool) data_get($productizedProofTemplate, 'external_publication_allowed', true) === false,
                'product_observability_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])) >= 10,
                'product_observability_metric_count' => count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.calendar_wait_blocker_enabled', true),
                'public_gtm_or_customer_commitment_allowed' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true),
                'external_billing_allowed' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'productized_service_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($productizedServiceOffer, 'offer_hash', '')),
            ],
            'sales_crm_pipeline_runtime_attestation' => [
                'schema' => 'atlas.ai.company.sales_crm_pipeline_runtime_attestation.v1',
                'sales_stack_bound' => (string) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_crm_pipeline_hash', '') !== '',
                'source_catalog_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])) >= 5,
                'crm_object_model_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.objects', [])) >= 8
                    && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.required_links', [])) >= 6
                    && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.raw_external_contact_export_allowed', true) === false,
                'segment_sales_play_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.segment_sales_plays', [])) >= 4,
                'opportunity_route_bound' => $salesOpportunityRoute !== []
                    && count((array) data_get($salesOpportunityRoute, 'stage_sequence', [])) >= 7
                    && count((array) data_get($salesOpportunityRoute, 'exit_criteria', [])) >= 4
                    && (bool) data_get($salesOpportunityRoute, 'external_commitment_allowed', true) === false,
                'opportunity_id' => (string) data_get($salesOpportunityRoute, 'opportunity_id', ''),
                'proposal_scope_bound' => $salesProposalPacket !== []
                    && count((array) data_get($salesProposalPacket, 'required_sections', [])) >= 8
                    && (bool) data_get($salesProposalPacket, 'signature_allowed', true) === false,
                'proposal_id' => (string) data_get($salesProposalPacket, 'proposal_id', ''),
                'mutual_action_plan_bound' => $salesMutualActionPlan !== []
                    && count((array) data_get($salesMutualActionPlan, 'milestones', [])) >= 6
                    && (bool) data_get($salesMutualActionPlan, 'external_customer_binding_allowed', true) === false,
                'mutual_action_plan_id' => (string) data_get($salesMutualActionPlan, 'map_id', ''),
                'account_research_workbench_bound' => $salesAccountResearchWorkbench !== []
                    && count((array) data_get($salesAccountResearchWorkbench, 'research_inputs', [])) >= 7
                    && count((array) data_get($salesAccountResearchWorkbench, 'required_outputs', [])) >= 6
                    && (bool) data_get($salesAccountResearchWorkbench, 'external_enrichment_allowed', true) === false,
                'deal_room_packet_bound' => $salesDealRoomPacket !== []
                    && count((array) data_get($salesDealRoomPacket, 'required_tabs', [])) >= 8
                    && count((array) data_get($salesDealRoomPacket, 'approval_gates', [])) >= 5
                    && (bool) data_get($salesDealRoomPacket, 'external_customer_room_allowed', true) === false,
                'pipeline_forecast_review_bound' => $salesPipelineForecastReview !== []
                    && count((array) data_get($salesPipelineForecastReview, 'forecast_dimensions', [])) >= 6
                    && count((array) data_get($salesPipelineForecastReview, 'required_evidence', [])) >= 5
                    && (bool) data_get($salesPipelineForecastReview, 'external_revenue_commitment_allowed', true) === false,
                'map_risk_review_bound' => $salesMapRiskReview !== []
                    && count((array) data_get($salesMapRiskReview, 'risk_checks', [])) >= 6
                    && count((array) data_get($salesMapRiskReview, 'repair_actions', [])) >= 5
                    && (bool) data_get($salesMapRiskReview, 'operator_review_required', false),
                'renewal_expansion_signal_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])) >= count((array) ($company['metrics'] ?? [])),
                'renewal_expansion_signal_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])),
                'sales_delivery_handoff_bound' => $salesDeliveryHandoff !== []
                    && count((array) data_get($salesDeliveryHandoff, 'required_artifacts', [])) >= 6
                    && count((array) data_get($salesDeliveryHandoff, 'delivery_acceptance_gate', [])) >= 5,
                'handoff_id' => (string) data_get($salesDeliveryHandoff, 'handoff_hash', ''),
                'pipeline_observability_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])) >= 13,
                'pipeline_observability_metric_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.calendar_wait_blocker_enabled', true),
                'external_sales_commitment_allowed' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true),
                'public_claim_or_paid_campaign_allowed' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.public_claim_or_paid_campaign_allowed', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'sales_crm_pipeline_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_crm_pipeline_hash', '')),
            ],
            'customer_support_service_desk_runtime_attestation' => [
                'schema' => 'atlas.ai.company.customer_support_service_desk_runtime_attestation.v1',
                'support_stack_bound' => (string) data_get($company, 'enterprise_customer_support_service_desk_stack.support_service_desk_hash', '') !== '',
                'source_catalog_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])) >= 5,
                'service_desk_object_model_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.objects', [])) >= 9
                    && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.required_links', [])) >= 7
                    && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.raw_external_customer_message_export_allowed', true) === false,
                'support_segment_playbook_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_segment_playbooks', [])) >= 4,
                'support_lane_bound' => $supportLane !== []
                    && count((array) data_get($supportLane, 'ticket_types', [])) >= 6
                    && (bool) data_get($supportLane, 'external_customer_response_allowed', true) === false,
                'support_lane_id' => (string) data_get($supportLane, 'lane_id', ''),
                'ticket_sla_contract_bound' => $supportTicketSla !== []
                    && count((array) data_get($supportTicketSla, 'required_fields', [])) >= 8
                    && count((array) data_get($supportTicketSla, 'sla_targets', [])) >= 3
                    && (bool) data_get($supportTicketSla, 'auto_external_response_allowed', true) === false,
                'ticket_sla_id' => (string) data_get($supportTicketSla, 'sla_hash', ''),
                'knowledge_base_template_bound' => $supportKbTemplate !== []
                    && count((array) data_get($supportKbTemplate, 'required_sections', [])) >= 8
                    && (bool) data_get($supportKbTemplate, 'public_publish_allowed', true) === false,
                'knowledge_base_template_id' => (string) data_get($supportKbTemplate, 'template_id', ''),
                'escalation_incident_runbook_bound' => $supportEscalationRunbook !== []
                    && count((array) data_get($supportEscalationRunbook, 'escalation_levels', [])) >= 4
                    && count((array) data_get($supportEscalationRunbook, 'incident_steps', [])) >= 7
                    && (bool) data_get($supportEscalationRunbook, 'external_notification_allowed', true) === false,
                'resolution_rca_bound' => $supportResolutionRca !== []
                    && count((array) data_get($supportResolutionRca, 'resolution_evidence', [])) >= 6
                    && count((array) data_get($supportResolutionRca, 'rca_required_for', [])) >= 5
                    && (bool) data_get($supportResolutionRca, 'auto_close_external_ticket_allowed', true) === false,
                'case_resolution_workbench_bound' => $supportCaseResolutionWorkbench !== []
                    && count((array) data_get($supportCaseResolutionWorkbench, 'case_inputs', [])) >= 7
                    && count((array) data_get($supportCaseResolutionWorkbench, 'resolution_outputs', [])) >= 6
                    && (bool) data_get($supportCaseResolutionWorkbench, 'external_customer_response_allowed', true) === false,
                'customer_health_escalation_bound' => $supportHealthEscalationPlaybook !== []
                    && count((array) data_get($supportHealthEscalationPlaybook, 'health_signals', [])) >= 6
                    && count((array) data_get($supportHealthEscalationPlaybook, 'escalation_actions', [])) >= 5
                    && (bool) data_get($supportHealthEscalationPlaybook, 'external_service_commitment_allowed', true) === false,
                'knowledge_quality_review_bound' => $supportKnowledgeQualityReview !== []
                    && count((array) data_get($supportKnowledgeQualityReview, 'quality_checks', [])) >= 7
                    && (float) data_get($supportKnowledgeQualityReview, 'minimum_quality_score', 0.0) >= 0.9
                    && (bool) data_get($supportKnowledgeQualityReview, 'public_publish_allowed', true) === false,
                'automation_deflection_test_bound' => $supportAutomationDeflectionTest !== []
                    && count((array) data_get($supportAutomationDeflectionTest, 'test_cases', [])) >= 6
                    && count((array) data_get($supportAutomationDeflectionTest, 'success_criteria', [])) >= 5
                    && (bool) data_get($supportAutomationDeflectionTest, 'auto_deflect_external_ticket_allowed', true) === false,
                'feedback_learning_loop_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])) >= count((array) ($company['metrics'] ?? [])),
                'feedback_learning_loop_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])),
                'support_observability_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])) >= 13,
                'support_observability_metric_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.calendar_wait_blocker_enabled', true),
                'external_customer_message_or_support_commitment_allowed' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true),
                'regulated_support_advice_allowed_without_review' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.regulated_support_advice_allowed_without_review', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'customer_support_service_desk_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_customer_support_service_desk_stack.support_service_desk_hash', '')),
            ],
            'marketing_growth_engine_runtime_attestation' => [
                'schema' => 'atlas.ai.company.marketing_growth_engine_runtime_attestation.v1',
                'marketing_stack_bound' => (string) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_growth_engine_hash', '') !== '',
                'source_catalog_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])) >= 5,
                'growth_operating_model_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.operating_roles', [])) >= 6
                    && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.required_controls', [])) >= 6,
                'audience_segment_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.audience_segment_map', [])) >= 4,
                'campaign_blueprint_bound' => $marketingCampaignBlueprint !== []
                    && count((array) data_get($marketingCampaignBlueprint, 'channels', [])) >= 5
                    && (bool) data_get($marketingCampaignBlueprint, 'external_launch_allowed', true) === false,
                'campaign_id' => (string) data_get($marketingCampaignBlueprint, 'campaign_id', ''),
                'content_asset_factory_bound' => $marketingContentFactory !== []
                    && count((array) data_get($marketingContentFactory, 'asset_types', [])) >= 6
                    && count((array) data_get($marketingContentFactory, 'required_evidence', [])) >= 5
                    && (bool) data_get($marketingContentFactory, 'public_publish_allowed', true) === false,
                'content_factory_id' => (string) data_get($marketingContentFactory, 'factory_id', ''),
                'experiment_bound' => $marketingExperiment !== []
                    && count((array) data_get($marketingExperiment, 'variants', [])) >= 3
                    && count((array) data_get($marketingExperiment, 'success_metrics', [])) >= 4
                    && (bool) data_get($marketingExperiment, 'external_traffic_allowed', true) === false,
                'experiment_id' => (string) data_get($marketingExperiment, 'experiment_id', ''),
                'growth_intelligence_workbench_bound' => $marketingGrowthIntelligenceWorkbench !== []
                    && count((array) data_get($marketingGrowthIntelligenceWorkbench, 'signals', [])) >= 6
                    && count((array) data_get($marketingGrowthIntelligenceWorkbench, 'outputs', [])) >= 6
                    && (bool) data_get($marketingGrowthIntelligenceWorkbench, 'external_scrape_or_publish_allowed', true) === false,
                'attribution_experiment_model_bound' => $marketingAttributionExperimentModel !== []
                    && count((array) data_get($marketingAttributionExperimentModel, 'touchpoints', [])) >= 6
                    && count((array) data_get($marketingAttributionExperimentModel, 'measurement_plan', [])) >= 5
                    && (bool) data_get($marketingAttributionExperimentModel, 'external_tracking_pixel_allowed', true) === false,
                'channel_budget_guardrail_bound' => $marketingChannelBudgetGuardrail !== []
                    && count((array) data_get($marketingChannelBudgetGuardrail, 'allowed_spend_modes', [])) >= 3
                    && count((array) data_get($marketingChannelBudgetGuardrail, 'blocked_spend_actions', [])) >= 5
                    && (bool) data_get($marketingChannelBudgetGuardrail, 'external_spend_allowed', true) === false,
                'public_claim_evidence_packet_bound' => $marketingPublicClaimEvidencePacket !== []
                    && count((array) data_get($marketingPublicClaimEvidencePacket, 'required_evidence', [])) >= 7
                    && count((array) data_get($marketingPublicClaimEvidencePacket, 'blocked_claim_types', [])) >= 5
                    && (bool) data_get($marketingPublicClaimEvidencePacket, 'public_claim_allowed', true) === false,
                'channel_distribution_bound' => $marketingChannelPlan !== []
                    && count((array) data_get($marketingChannelPlan, 'allowed_internal_channels', [])) >= 5
                    && count((array) data_get($marketingChannelPlan, 'blocked_external_channels', [])) >= 5,
                'brand_compliance_review_bound' => $marketingBrandReview !== []
                    && count((array) data_get($marketingBrandReview, 'required_checks', [])) >= 6
                    && (bool) data_get($marketingBrandReview, 'auto_approve_external_publish_allowed', true) === false,
                'growth_crm_handoff_bound' => $marketingCrmHandoff !== []
                    && count((array) data_get($marketingCrmHandoff, 'required_artifacts', [])) >= 6
                    && count((array) data_get($marketingCrmHandoff, 'handoff_gate', [])) >= 5
                    && (bool) data_get($marketingCrmHandoff, 'external_lead_handoff_allowed', true) === false,
                'marketing_observability_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])) >= 14,
                'marketing_observability_metric_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.calendar_wait_blocker_enabled', true),
                'external_publish_paid_campaign_or_outreach_allowed' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.external_publish_paid_campaign_or_outreach_allowed', true),
                'public_claim_allowed_without_source_and_operator_review' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.public_claim_allowed_without_source_and_operator_review', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'marketing_growth_engine_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_growth_engine_hash', '')),
            ],
            'finance_treasury_billing_runtime_attestation' => [
                'schema' => 'atlas.ai.company.finance_treasury_billing_runtime_attestation.v1',
                'finance_stack_bound' => (string) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_treasury_billing_hash', '') !== '',
                'source_catalog_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])) >= 5,
                'source_catalog_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
                'financial_data_interface_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])) >= 7
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.direct_source_link_required', false)
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.cross_source_reconciliation_required', false)
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.claim_without_source_link_allowed', true) === false,
                'financial_data_interface_connector_class_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])),
                'provider_connector_matrix_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', []))
                    && count(array_filter((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', []), static fn (array $connector): bool => (bool) ($connector['direct_source_link_required'] ?? false) && (bool) ($connector['write_or_trade_scope_allowed'] ?? true) === false)) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
                'provider_connector_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])),
                'cfo_operating_model_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.operating_roles', [])) >= 9
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.required_controls', [])) >= 8,
                'financial_research_workbench_bound' => $financeResearchWorkbench !== []
                    && count((array) data_get($financeResearchWorkbench, 'source_panes', [])) >= 6
                    && count((array) data_get($financeResearchWorkbench, 'verification_steps', [])) >= 5
                    && (bool) data_get($financeResearchWorkbench, 'claim_without_source_allowed', true) === false,
                'financial_research_workbench_id' => (string) data_get($financeResearchWorkbench, 'workbench_hash', ''),
                'budget_envelope_bound' => $financeBudgetEnvelope !== []
                    && count((array) data_get($financeBudgetEnvelope, 'approval_thresholds', [])) >= 3
                    && (bool) data_get($financeBudgetEnvelope, 'external_spend_allowed', true) === false,
                'budget_envelope_id' => (string) data_get($financeBudgetEnvelope, 'budget_hash', ''),
                'forecast_model_bound' => $financeForecastModel !== []
                    && count((array) data_get($financeForecastModel, 'drivers', [])) >= 5
                    && count((array) data_get($financeForecastModel, 'scenario_set', [])) >= 4,
                'forecast_model_id' => (string) data_get($financeForecastModel, 'forecast_hash', ''),
                'model_risk_control_bound' => $financeModelRiskControl !== []
                    && count((array) data_get($financeModelRiskControl, 'model_artifacts', [])) >= 5
                    && count((array) data_get($financeModelRiskControl, 'review_gates', [])) >= 5
                    && (bool) data_get($financeModelRiskControl, 'audit_trail_required', false)
                    && (bool) data_get($financeModelRiskControl, 'investment_recommendation_allowed_without_review', true) === false,
                'model_risk_control_id' => (string) data_get($financeModelRiskControl, 'model_risk_hash', ''),
                'investment_committee_packet_bound' => $financeInvestmentCommitteePacket !== []
                    && count((array) data_get($financeInvestmentCommitteePacket, 'packet_sections', [])) >= 7
                    && count((array) data_get($financeInvestmentCommitteePacket, 'required_approvals', [])) >= 4
                    && (bool) data_get($financeInvestmentCommitteePacket, 'external_pitch_or_trade_allowed', true) === false,
                'investment_committee_packet_id' => (string) data_get($financeInvestmentCommitteePacket, 'packet_hash', ''),
                'pnl_line_item_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])) >= 5,
                'pnl_line_item_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])),
                'billing_ledger_bound' => $financeBillingLedger !== []
                    && count((array) data_get($financeBillingLedger, 'ledger_artifacts', [])) >= 5
                    && count((array) data_get($financeBillingLedger, 'reconciliation_steps', [])) >= 4
                    && (bool) data_get($financeBillingLedger, 'external_invoice_allowed', true) === false
                    && (bool) data_get($financeBillingLedger, 'payment_collection_allowed', true) === false,
                'billing_ledger_id' => (string) data_get($financeBillingLedger, 'ledger_hash', ''),
                'treasury_risk_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.capital_actions_blocked', [])) >= 6
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.loss_cap_signature_required', false)
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true) === false,
                'finance_close_audit_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.close_packet_sections', [])) >= 9
                    && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.evidence_required', [])) >= 9
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.audit_trail_required', false),
                'finance_observability_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])) >= 14,
                'finance_observability_metric_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.calendar_wait_blocker_enabled', true),
                'source_linked_financial_claim_required' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.source_linked_financial_claim_required', false),
                'model_risk_review_required' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.model_risk_review_required_for_investment_or_capital_recommendation', false),
                'external_financial_action_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true),
                'real_revenue_cash_or_aum_claim_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.real_revenue_cash_or_aum_claim_allowed', true),
                'real_money_movement_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true),
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'finance_treasury_billing_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_treasury_billing_hash', '')),
            ],
            'governance_risk_operations_runtime_attestation' => [
                'schema' => 'atlas.ai.company.governance_risk_operations_runtime_attestation.v1',
                'vendor_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                    && $vendorProcurementRouting !== []
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                    && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) ($company['connectors'] ?? [])),
                'procurement_route_id' => (string) ($vendorProcurementRouting['route_id'] ?? $vendorProcurementRouting['flow_id'] ?? ''),
                'resilience_continuity_bound' => (string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '') !== ''
                    && $resilienceFailureMode !== []
                    && $resilienceExercise !== []
                    && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_resilience_continuity_stack.resilience_observability.required_metrics', [])) >= 5,
                'resilience_exercise_id' => (string) ($resilienceExercise['exercise_id'] ?? ''),
                'analytics_decision_bound' => (string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '') !== ''
                    && $analyticsDecisionRegister !== []
                    && $scenarioForecast !== []
                    && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= count((array) ($company['metrics'] ?? []))
                    && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                    && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.work_product_analytics_map', [])) >= 5,
                'decision_packet_id' => (string) ($analyticsDecisionRegister['decision_packet'] ?? $analyticsDecisionRegister['flow_id'] ?? ''),
                'knowledge_learning_bound' => (string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '') !== ''
                    && $knowledgeLearningLoop !== []
                    && $playbookChangeControl !== []
                    && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                    && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.learning_observability.required_metrics', [])) >= 5,
                'learning_loop_id' => (string) ($knowledgeLearningLoop['learning_loop_hash'] ?? $knowledgeLearningLoop['flow_id'] ?? ''),
                'identity_sovereignty_bound' => (string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '') !== ''
                    && $identityDataBoundary !== []
                    && $purposeConsent !== []
                    && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                    && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= count((array) ($company['connectors'] ?? []))
                    && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_observability.required_metrics', [])) >= 5,
                'data_boundary_id' => (string) ($identityDataBoundary['boundary_hash'] ?? $identityDataBoundary['flow_id'] ?? ''),
                'grc_evidence_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== ''
                    && $grcEvidence !== []
                    && count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 6
                    && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= count((array) ($company['connectors'] ?? []))
                    && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
                'audit_evidence_id' => (string) ($grcEvidence['audit_hash'] ?? $grcEvidence['flow_id'] ?? ''),
                'calendar_wait_blocker_enabled' => false,
                'vendor_purchase_contract_signature_secret_share_or_write_scope_allowed' => false,
                'incident_external_notification_without_operator_allowed' => false,
                'canonical_memory_write_without_review_allowed' => false,
                'secret_material_or_unscoped_memory_export_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'governance_risk_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '').'|'.(string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
            ],
            'unit_economics_capacity_runtime_attestation' => [
                'schema' => 'atlas.ai.company.unit_economics_capacity_runtime_attestation.v1',
                'flow_cost_center_bound' => $flowCostCenter !== [],
                'cost_center_id' => (string) ($flowCostCenter['cost_center_id'] ?? $flowCostCenter['flow_id'] ?? ''),
                'flow_unit_economics_bound' => $flowUnitEconomics !== [],
                'unit_economics_id' => (string) ($flowUnitEconomics['unit_id'] ?? $flowUnitEconomics['flow_id'] ?? ''),
                'capacity_simulation_bound' => $capacitySimulation !== [],
                'capacity_simulation_id' => (string) ($capacitySimulation['simulation_id'] ?? $capacitySimulation['flow_id'] ?? ''),
                'pricing_ladder_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])) >= 5,
                'pricing_ladder_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])),
                'agent_capacity_cost_model_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])) >= 4,
                'agent_capacity_cost_model_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])),
                'connector_cost_limit_model_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= count((array) ($company['connectors'] ?? [])),
                'connector_cost_limit_model_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])),
                'decision_inputs_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_decision_protocol.required_inputs', [])) >= 5,
                'observability_metric_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_capacity_observability.required_metrics', [])),
                'calendar_wait_blocker_enabled' => false,
                'real_capital_action_allowed' => false,
                'external_revenue_or_savings_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'unit_economics_capacity_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_capacity_hash', '')),
            ],
            'delivery_risk_runtime_attestation' => [
                'schema' => 'atlas.ai.company.delivery_risk_runtime_attestation.v1',
                'delivery_assurance_runtime_bound' => (string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '') !== '',
                'work_product_delivery_contract_count' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])),
                'delivery_sla_bound' => $deliverySla !== [],
                'delivery_sla_class' => (string) ($deliverySla['sla_class'] ?? ''),
                'delivery_acceptance_tests_bound' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts.0.acceptance_tests', [])) >= 4,
                'delivery_risk_controls_bound' => (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_delivery_requires_operator_approval', false)
                    && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.customer_visible_claims_require_source_refs', false),
                'strategic_intelligence_bound' => (string) data_get($company, 'strategic_intelligence_stack.intelligence_hash', '') !== '',
                'rival_alternative_map_bound' => $strategicRivalMap !== [],
                'rival_count' => count((array) ($strategicRivalMap['rivals'] ?? [])),
                'benchmark_evidence_required' => (bool) data_get($company, 'strategic_intelligence_stack.competitive_benchmark_model.synthetic_scores_allowed', true) === false,
                'grc_runtime_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== '',
                'vendor_tool_risk_count' => count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])),
                'audit_evidence_bound' => $grcEvidence !== [],
                'audit_required_evidence_count' => count((array) ($grcEvidence['required_evidence'] ?? [])),
                'policy_exception_blocked' => (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
                'redaction_required_for_sensitive_output' => (bool) data_get($company, 'enterprise_grc_stack.data_classification.regulated_or_sensitive_requires_redaction', false),
                'calendar_wait_blocker_enabled' => false,
                'external_delivery_allowed' => false,
                'customer_visible_claim_allowed' => false,
                'policy_exception_auto_approval_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'delivery_risk_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
            ],
            'domain_business_execution_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_business_execution_runtime_attestation.v1',
                'execution_cell_bound' => $businessExecutionCell !== [],
                'execution_cell_id' => (string) ($businessExecutionCell['cell_id'] ?? ''),
                'execution_mode' => (string) ($businessExecutionCell['execution_mode'] ?? ''),
                'kpi_binding_bound' => $businessKpiBinding !== [],
                'kpi_ref_count' => count((array) ($businessKpiBinding['kpi_refs'] ?? [])),
                'service_lane_bound' => $businessServiceLane !== [],
                'service_lane_id' => (string) ($businessServiceLane['lane_id'] ?? ''),
                'artifact_delivery_contract_bound' => $businessArtifactContract !== [],
                'artifact_contract_work_product' => (string) ($businessArtifactContract['work_product'] ?? ''),
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'attestation_hash' => hash('sha256', 'domain_business_execution_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($businessExecutionCell['cell_hash'] ?? '')),
            ],
            'domain_solution_playbook_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_solution_playbook_runtime_attestation.v1',
                'solution_playbook_bound' => $domainSolutionPlaybook !== [],
                'playbook_id' => (string) ($domainSolutionPlaybook['playbook_id'] ?? ''),
                'source_pack_bound' => count((array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])) >= 1
                    && (bool) data_get($domainSolutionPlaybook, 'source_pack.direct_hyperlinks_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'source_pack.freshness_check_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'source_pack.source_disagreement_register_required', false),
                'source_ref_count' => count((array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])),
                'domain_data_plane_bound' => (string) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.data_plane_hash', '') !== ''
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.direct_source_hyperlinks_required', false)
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false)
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.claim_to_source_traceability_required', false)
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.external_data_mutation_allowed', true) === false,
                'data_plane_layer_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.layers', [])),
                'execution_path_bound' => count((array) data_get($domainSolutionPlaybook, 'execution_path.nodes', [])) >= 8
                    && (bool) data_get($domainSolutionPlaybook, 'execution_path.durable_state_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'execution_path.resume_token_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'execution_path.idempotency_key_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'execution_path.operator_interrupt_supported', false),
                'execution_node_count' => count((array) data_get($domainSolutionPlaybook, 'execution_path.nodes', [])),
                'tooling_contract_bound' => (bool) data_get($domainSolutionPlaybook, 'tooling_contract.mcp_or_api_adapter_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.sandbox_or_fixture_mode_required_before_live_read', false)
                    && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.write_spend_trade_publish_deploy_delete_blocked_without_signed_scope', false)
                    && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.tool_receipt_required', false),
                'tool_connector_ref_count' => count((array) data_get($domainSolutionPlaybook, 'tooling_contract.connector_refs', [])),
                'domain_review_contract_bound' => (string) data_get($domainSolutionPlaybook, 'domain_review_contract.reviewer', '') !== ''
                    && (float) data_get($domainSolutionPlaybook, 'domain_review_contract.domain_correctness_score_required', 0.0) >= 0.9
                    && (float) data_get($domainSolutionPlaybook, 'domain_review_contract.source_faithfulness_score_required', 0.0) >= 0.95
                    && (int) data_get($domainSolutionPlaybook, 'domain_review_contract.policy_findings_allowed', 1) === 0
                    && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.second_reviewer_required_for_external_action', false),
                'review_mode_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.review_modes', [])),
                'benchmark_contract_bound' => (int) data_get($domainSolutionPlaybook, 'benchmark_contract.fixture_cases_required', 0) >= 25
                    && (int) data_get($domainSolutionPlaybook, 'benchmark_contract.shadow_replays_required', 0) >= 5
                    && (int) data_get($domainSolutionPlaybook, 'benchmark_contract.adversarial_cases_required', 0) >= 5
                    && (bool) data_get($domainSolutionPlaybook, 'benchmark_contract.regression_pack_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'benchmark_contract.synthetic_score_claims_allowed', true) === false,
                'handoff_contract_bound' => count((array) data_get($domainSolutionPlaybook, 'handoff_contract.required_evidence', [])) >= 6
                    && (bool) data_get($domainSolutionPlaybook, 'handoff_contract.target_acceptance_required', false)
                    && (bool) data_get($domainSolutionPlaybook, 'handoff_contract.external_delivery_requires_operator_mandate', false),
                'handoff_required_evidence_count' => count((array) data_get($domainSolutionPlaybook, 'handoff_contract.required_evidence', [])),
                'external_data_mutation_allowed' => false,
                'external_delivery_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'domain_solution_playbook_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($domainSolutionPlaybook['playbook_hash'] ?? '')),
            ],
            'domain_operating_depth_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_operating_depth_runtime_attestation.v1',
                'depth_packet_bound' => $domainOperatingDepthPacket !== [],
                'skills_bound' => count((array) data_get($domainOperatingDepthPacket, 'skills', [])) >= 6,
                'skill_count' => count((array) data_get($domainOperatingDepthPacket, 'skills', [])),
                'connector_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'connector_refs', [])) >= 1,
                'connector_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'connector_refs', [])),
                'subagents_bound' => count((array) data_get($domainOperatingDepthPacket, 'subagents', [])) >= 4,
                'subagent_count' => count((array) data_get($domainOperatingDepthPacket, 'subagents', [])),
                'source_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'source_refs', [])) >= 1,
                'source_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'source_refs', [])),
                'enterprise_system_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'enterprise_system_refs', [])) >= 1,
                'enterprise_system_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'enterprise_system_refs', [])),
                'data_product_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'data_product_refs', [])) >= 1,
                'data_product_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'data_product_refs', [])),
                'quality_contract_bound' => (int) data_get($domainOperatingDepthPacket, 'quality_contract.minimum_fixture_cases', 0) >= 25
                    && (int) data_get($domainOperatingDepthPacket, 'quality_contract.minimum_shadow_replays', 0) >= 5
                    && (float) data_get($domainOperatingDepthPacket, 'quality_contract.source_faithfulness_floor', 0.0) >= 0.95
                    && (float) data_get($domainOperatingDepthPacket, 'quality_contract.domain_correctness_floor', 0.0) >= 0.9
                    && (int) data_get($domainOperatingDepthPacket, 'quality_contract.policy_findings_allowed', 1) === 0,
                'operating_controls_bound' => (bool) data_get($domainOperatingDepthPacket, 'operating_controls.tool_receipts_required', false)
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.second_reviewer_required_for_external_action', false)
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.customer_visible_claims_require_source_refs', false)
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.offensive_security_allowed', true) === false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'external_side_effects_enabled' => false,
                'attestation_hash' => hash('sha256', 'domain_operating_depth_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($domainOperatingDepthPacket, 'packet_hash', '')),
            ],
            'domain_agent_workforce_runtime_attestation' => [
                'schema' => 'atlas.ai.company.domain_agent_workforce_runtime_attestation.v1',
                'crew_bound' => $domainAgentCrew !== [],
                'crew_id' => (string) data_get($domainAgentCrew, 'crew_id', ''),
                'skills_bound' => count((array) data_get($domainAgentCrew, 'skills', [])) >= 7,
                'skill_count' => count((array) data_get($domainAgentCrew, 'skills', [])),
                'connector_refs_bound' => count((array) data_get($domainAgentCrew, 'connector_refs', [])) >= 1,
                'connector_ref_count' => count((array) data_get($domainAgentCrew, 'connector_refs', [])),
                'subagents_bound' => count((array) data_get($domainAgentCrew, 'subagents', [])) >= 5,
                'subagent_count' => count((array) data_get($domainAgentCrew, 'subagents', [])),
                'source_refs_bound' => count((array) data_get($domainAgentCrew, 'source_refs', [])) >= 1,
                'source_ref_count' => count((array) data_get($domainAgentCrew, 'source_refs', [])),
                'work_surface_adapters_bound' => count((array) data_get($domainAgentCrew, 'work_surface_adapters', [])) >= 5,
                'work_surface_adapter_count' => count((array) data_get($domainAgentCrew, 'work_surface_adapters', [])),
                'managed_runtime_controls_bound' => (bool) data_get($domainAgentCrew, 'managed_runtime_controls.long_running_session', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.resume_token_required', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.per_tool_permissions', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.credential_vault_ref_only', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.full_audit_log', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.tool_call_receipts_required', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.human_interrupt_before_sensitive_tool', false)
                    && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.external_side_effects_enabled', true) === false,
                'work_queue_bound' => count((array) data_get($domainAgentCrew, 'work_queue_contract.states', [])) >= 8
                    && (bool) data_get($domainAgentCrew, 'work_queue_contract.dead_letter_required', false)
                    && (bool) data_get($domainAgentCrew, 'work_queue_contract.idempotency_key_required', false),
                'acceptance_contract_bound' => (int) data_get($domainAgentCrew, 'acceptance_contract.minimum_fixture_cases', 0) >= 25
                    && (int) data_get($domainAgentCrew, 'acceptance_contract.minimum_shadow_replays', 0) >= 5
                    && (float) data_get($domainAgentCrew, 'acceptance_contract.source_faithfulness_floor', 0.0) >= 0.95
                    && (float) data_get($domainAgentCrew, 'acceptance_contract.domain_correctness_floor', 0.0) >= 0.9
                    && (int) data_get($domainAgentCrew, 'acceptance_contract.policy_findings_allowed', 1) === 0
                    && (bool) data_get($domainAgentCrew, 'acceptance_contract.operator_acceptance_required', false),
                'external_side_effects_enabled' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'attestation_hash' => hash('sha256', 'domain_agent_workforce_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($domainAgentCrew, 'crew_hash', '')),
            ],
            'vertical_solution_runtime_attestation' => [
                'schema' => 'atlas.ai.company.vertical_solution_runtime_attestation.v1',
                'kit_present' => $verticalSolutionKit !== [],
                'kit_id' => (string) ($verticalSolutionKit['kit_id'] ?? ''),
                'suite_ref_count' => count((array) ($verticalSolutionKit['suite_refs'] ?? [])),
                'connector_workbench_count' => count($verticalConnectorWorkbenches),
                'artifact_factory_present' => $artifactFactory !== [],
                'artifact_factory_id' => (string) ($artifactFactory['factory_id'] ?? ''),
                'quality_floor' => (float) ($verticalSolutionKit['quality_floor'] ?? 0.0),
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'attestation_hash' => hash('sha256', 'vertical_solution_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($verticalSolutionKit['kit_hash'] ?? '')),
            ],
            'operating_package_attestation' => [
                'schema' => 'atlas.ai.company.enterprise_flow_operating_package_attestation.v1',
                'package_present' => $operatingPackage !== [],
                'package_id' => (string) ($operatingPackage['package_id'] ?? ''),
                'premium_template_id' => (string) ($operatingPackage['premium_template_id'] ?? ''),
                'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
                'workbench_binding_count' => count((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', [])),
                'runbook_drill_required_before_supervised_mode' => (bool) data_get($operatingPackage, 'operations_cell.runbook_drill_required_before_supervised_mode', false),
                'calendar_wait_blocker_enabled' => (int) ($operatingPackage['buildout_wait_days_required'] ?? 30) > 0,
                'external_execution_allowed' => false,
                'attestation_hash' => hash('sha256', 'operating_package_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($operatingPackage['package_hash'] ?? '')),
            ],
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
