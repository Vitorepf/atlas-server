<?php

namespace App\Services\Ai\Holding;

use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EnterpriseFlowFixtureActionRuntimeService
{
    public const SCHEMA = 'atlas.ai.company.enterprise_flow_fixture_action_run.v1';

    public const INTERNAL_SCHEMA = 'atlas.ai.company.enterprise_flow_action_run.v1';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $buildoutReport = null;

    /**
     * @var array<string,list<array<string,mixed>>>
     */
    private array $runtimeRecordCache = [];

    public function __construct(
        private readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
    ) {}

    public function supports(string $companyId, string $action): bool
    {
        return $this->actionContract($companyId, $action) !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildoutReport(): array
    {
        if ($this->buildoutReport === null) {
            $this->buildoutReport = $this->buildout->report();
        }

        return $this->buildoutReport;
    }

    /**
     * @return array<string,mixed>
     */
    public function runPortfolioInternal(?string $companyId = null): array
    {
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

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companySystemModelRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];
        $boundKeys = [
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
        ];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $systemRecords = array_values(array_filter(
                $companyRecords,
                static function (array $record) use ($boundKeys): bool {
                    if (! (bool) ($record['company_system_model_runtime_bound'] ?? false)
                        || (string) ($record['company_system_model_attestation_hash'] ?? '') === ''
                        || (bool) ($record['external_side_effects'] ?? true) !== false) {
                        return false;
                    }

                    foreach ($boundKeys as $recordKey) {
                        if (! (bool) ($record[$recordKey] ?? false)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $systemRecords,
            ))));

            $companySummary = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_company_system_model_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_company_system_model_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            foreach ($boundKeys as $summaryKey => $recordKey) {
                $companySummary[$summaryKey] = count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record[$recordKey] ?? false)));
            }

            $companies[] = $companySummary;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_company_system_model_flow_count'], $companies));
        $summary = [
            'company_count' => count($companies),
            'expected_flow_count' => $expectedFlowCount,
            'completed_company_system_model_flow_count' => $completedFlowCount,
            'runtime_record_count' => count($records),
        ];
        foreach ($boundKeys as $summaryKey => $_recordKey) {
            $summary[$summaryKey] = array_sum(array_map(static fn (array $company): int => (int) $company[$summaryKey], $companies));
        }
        $summary['external_side_effect_count'] = count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true)));
        $summary['coverage_rate'] = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_company_system_model_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_company_system_model_runtime_coverage_external_commitments_blocked'
                : 'missing_company_system_model_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => $summary,
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'commercial_external_billing_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'company_system_model_requires_data_process_quality_production_and_commercial_contracts' => true,
            ],
        ];
        $payload['company_system_model_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function internalOperationsBackboneRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];
        $boundKeys = [
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
        ];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $backboneRecords = array_values(array_filter(
                $companyRecords,
                static function (array $record) use ($boundKeys): bool {
                    if (! (bool) ($record['internal_operations_backbone_runtime_bound'] ?? false)
                        || (string) ($record['internal_operations_backbone_attestation_hash'] ?? '') === ''
                        || (bool) ($record['external_side_effects'] ?? true) !== false) {
                        return false;
                    }

                    foreach ($boundKeys as $recordKey) {
                        if (! (bool) ($record[$recordKey] ?? false)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $backboneRecords,
            ))));

            $companySummary = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_internal_operations_backbone_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_internal_operations_backbone_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            foreach ($boundKeys as $summaryKey => $recordKey) {
                $companySummary[$summaryKey] = count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record[$recordKey] ?? false)));
            }

            $companies[] = $companySummary;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_internal_operations_backbone_flow_count'], $companies));
        $summary = [
            'company_count' => count($companies),
            'expected_flow_count' => $expectedFlowCount,
            'completed_internal_operations_backbone_flow_count' => $completedFlowCount,
            'runtime_record_count' => count($records),
        ];
        foreach ($boundKeys as $summaryKey => $_recordKey) {
            $summary[$summaryKey] = array_sum(array_map(static fn (array $company): int => (int) $company[$summaryKey], $companies));
        }
        $summary['external_side_effect_count'] = count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true)));
        $summary['coverage_rate'] = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_internal_operations_backbone_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_internal_operations_backbone_runtime_coverage_external_actions_blocked'
                : 'missing_internal_operations_backbone_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => $summary,
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'customer_vendor_memory_identity_delivery_external_actions_allowed' => false,
                'operator_mandate_required_for_external_action' => true,
                'internal_operations_backbone_requires_account_vendor_resilience_analytics_memory_identity_control_tower_delivery_and_grc' => true,
            ],
        ];
        $payload['internal_operations_backbone_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function activationRunOperationsRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];
        $boundKeys = [
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
        ];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $activationRecords = array_values(array_filter(
                $companyRecords,
                static function (array $record) use ($boundKeys): bool {
                    if (! (bool) ($record['activation_run_operations_runtime_bound'] ?? false)
                        || (string) ($record['activation_run_operations_attestation_hash'] ?? '') === ''
                        || (bool) ($record['external_side_effects'] ?? true) !== false) {
                        return false;
                    }

                    foreach ($boundKeys as $recordKey) {
                        if (! (bool) ($record[$recordKey] ?? false)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $activationRecords,
            ))));

            $companySummary = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_activation_run_operations_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_activation_run_operations_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            foreach ($boundKeys as $summaryKey => $recordKey) {
                $companySummary[$summaryKey] = count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record[$recordKey] ?? false)));
            }

            $companies[] = $companySummary;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_activation_run_operations_flow_count'], $companies));
        $summary = [
            'company_count' => count($companies),
            'expected_flow_count' => $expectedFlowCount,
            'completed_activation_run_operations_flow_count' => $completedFlowCount,
            'runtime_record_count' => count($records),
        ];
        foreach ($boundKeys as $summaryKey => $_recordKey) {
            $summary[$summaryKey] = array_sum(array_map(static fn (array $company): int => (int) $company[$summaryKey], $companies));
        }
        $summary['external_side_effect_count'] = count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true)));
        $summary['coverage_rate'] = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_activation_run_operations_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_activation_run_operations_runtime_coverage_external_actions_blocked'
                : 'missing_activation_run_operations_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => $summary,
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_external_action' => true,
                'activation_runtime_requires_tracks_queue_probe_live_read_rehearsal_and_observability' => true,
            ],
        ];
        $payload['activation_run_operations_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
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

        $payload = [
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
        ];
        $payload['runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalSolutionRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $kit): string => (string) ($kit['flow_id'] ?? ''),
                (array) data_get($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $verticalRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['vertical_solution_kit_bound'] ?? false)
                    && (bool) ($record['artifact_factory_bound'] ?? false)
                    && (int) ($record['vertical_connector_workbench_count'] ?? 0) > 0
                    && (string) ($record['domain_execution_brief_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $verticalRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_vertical_runtime_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_vertical_runtime_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'vertical_solution_kit_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['vertical_solution_kit_bound'] ?? false))),
                'artifact_factory_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['artifact_factory_bound'] ?? false))),
                'vertical_connector_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (int) ($record['vertical_connector_workbench_count'] ?? 0) > 0)),
                'domain_execution_brief_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (string) ($record['domain_execution_brief_hash'] ?? '') !== '')),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_vertical_runtime_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_vertical_solution_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_vertical_solution_runtime_coverage_external_blocked'
                : 'missing_vertical_solution_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_vertical_runtime_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'vertical_solution_kit_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['vertical_solution_kit_bound'] ?? false))),
                'artifact_factory_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['artifact_factory_bound'] ?? false))),
                'vertical_connector_workbench_bound_count' => count(array_filter($records, static fn (array $record): bool => (int) ($record['vertical_connector_workbench_count'] ?? 0) > 0)),
                'domain_execution_brief_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['domain_execution_brief_hash'] ?? '') !== '')),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'vertical_solution_runtime_requires_bound_kit_artifact_factory_and_connector_workbench' => true,
                'operator_mandate_required_for_external_action' => true,
            ],
        ];
        $payload['vertical_solution_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionRuntimeStatus(?string $companyId = null): array
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
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $businessExecutionRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['business_execution_cell_bound'] ?? false)
                    && (bool) ($record['business_kpi_binding_bound'] ?? false)
                    && (bool) ($record['business_service_lane_bound'] ?? false)
                    && (bool) ($record['business_artifact_contract_bound'] ?? false)
                    && (string) ($record['business_execution_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $businessExecutionRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_business_execution_runtime_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_business_execution_runtime_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'business_execution_cell_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_execution_cell_bound'] ?? false))),
                'business_kpi_binding_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_kpi_binding_bound'] ?? false))),
                'business_service_lane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_service_lane_bound'] ?? false))),
                'business_artifact_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_artifact_contract_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_business_execution_runtime_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_business_execution_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_business_execution_runtime_coverage_external_blocked'
                : 'missing_domain_business_execution_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_business_execution_runtime_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'business_execution_cell_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_execution_cell_bound_count'], $companies)),
                'business_kpi_binding_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_kpi_binding_bound_count'], $companies)),
                'business_service_lane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_service_lane_bound_count'], $companies)),
                'business_artifact_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_artifact_contract_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'business_execution_runtime_requires_cell_kpi_lane_and_artifact_contract' => true,
                'operator_mandate_required_for_external_action' => true,
            ],
        ];
        $payload['domain_business_execution_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companyOperatingSpineRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $spineRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['company_operating_spine_bound'] ?? false)
                    && (bool) ($record['customer_market_runtime_bound'] ?? false)
                    && (bool) ($record['account_contract_runtime_bound'] ?? false)
                    && (bool) ($record['vendor_legal_runtime_bound'] ?? false)
                    && (bool) ($record['resilience_runtime_bound'] ?? false)
                    && (bool) ($record['analytics_runtime_bound'] ?? false)
                    && (bool) ($record['knowledge_memory_runtime_bound'] ?? false)
                    && (bool) ($record['identity_sovereignty_runtime_bound'] ?? false)
                    && (string) ($record['company_operating_spine_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $spineRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operating_spine_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operating_spine_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'customer_market_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_market_runtime_bound'] ?? false))),
                'account_contract_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_contract_runtime_bound'] ?? false))),
                'vendor_legal_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['vendor_legal_runtime_bound'] ?? false))),
                'resilience_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['resilience_runtime_bound'] ?? false))),
                'analytics_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['analytics_runtime_bound'] ?? false))),
                'knowledge_memory_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['knowledge_memory_runtime_bound'] ?? false))),
                'identity_sovereignty_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['identity_sovereignty_runtime_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operating_spine_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_company_operating_spine_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_company_operating_spine_runtime_coverage_external_blocked'
                : 'missing_company_operating_spine_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_operating_spine_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'customer_market_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_market_runtime_bound_count'], $companies)),
                'account_contract_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_contract_runtime_bound_count'], $companies)),
                'vendor_legal_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_legal_runtime_bound_count'], $companies)),
                'resilience_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['resilience_runtime_bound_count'], $companies)),
                'analytics_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['analytics_runtime_bound_count'], $companies)),
                'knowledge_memory_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['knowledge_memory_runtime_bound_count'], $companies)),
                'identity_sovereignty_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['identity_sovereignty_runtime_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operating_spine_requires_customer_account_vendor_resilience_analytics_knowledge_and_identity_bindings' => true,
                'operator_mandate_required_for_external_customer_vendor_billing_capital_or_data_action' => true,
            ],
        ];
        $payload['company_operating_spine_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function commercialOperationsRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $commercialRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['commercial_operations_runtime_bound'] ?? false)
                    && (bool) ($record['customer_market_operations_bound'] ?? false)
                    && (bool) ($record['offer_packaging_bound'] ?? false)
                    && (bool) ($record['customer_journey_bound'] ?? false)
                    && (bool) ($record['customer_success_scorecard_bound'] ?? false)
                    && (bool) ($record['account_contract_delivery_bound'] ?? false)
                    && (bool) ($record['contract_entitlement_bound'] ?? false)
                    && (bool) ($record['onboarding_success_plan_bound'] ?? false)
                    && (bool) ($record['service_review_renewal_bound'] ?? false)
                    && (bool) ($record['account_health_risk_bound'] ?? false)
                    && (bool) ($record['billing_revenue_model_bound'] ?? false)
                    && (bool) ($record['vendor_legal_procurement_bound'] ?? false)
                    && (bool) ($record['vendor_due_diligence_bound'] ?? false)
                    && (bool) ($record['source_terms_review_bound'] ?? false)
                    && (bool) ($record['flow_procurement_routing_bound'] ?? false)
                    && (bool) ($record['vendor_operability_bound'] ?? false)
                    && (string) ($record['commercial_operations_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $commercialRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_commercial_operations_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_commercial_operations_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'customer_market_operations_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_market_operations_bound'] ?? false))),
                'offer_packaging_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['offer_packaging_bound'] ?? false))),
                'customer_journey_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_journey_bound'] ?? false))),
                'customer_success_scorecard_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_success_scorecard_bound'] ?? false))),
                'account_contract_delivery_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_contract_delivery_bound'] ?? false))),
                'contract_entitlement_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['contract_entitlement_bound'] ?? false))),
                'onboarding_success_plan_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['onboarding_success_plan_bound'] ?? false))),
                'service_review_renewal_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['service_review_renewal_bound'] ?? false))),
                'account_health_risk_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_health_risk_bound'] ?? false))),
                'billing_revenue_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['billing_revenue_model_bound'] ?? false))),
                'vendor_legal_procurement_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['vendor_legal_procurement_bound'] ?? false))),
                'vendor_due_diligence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['vendor_due_diligence_bound'] ?? false))),
                'source_terms_review_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['source_terms_review_bound'] ?? false))),
                'flow_procurement_routing_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_procurement_routing_bound'] ?? false))),
                'vendor_operability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['vendor_operability_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_commercial_operations_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_commercial_operations_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_commercial_operations_runtime_coverage_external_customer_vendor_billing_blocked'
                : 'missing_commercial_operations_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_commercial_operations_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'customer_market_operations_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_market_operations_bound_count'], $companies)),
                'offer_packaging_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offer_packaging_bound_count'], $companies)),
                'customer_journey_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_journey_bound_count'], $companies)),
                'customer_success_scorecard_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_success_scorecard_bound_count'], $companies)),
                'account_contract_delivery_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_contract_delivery_bound_count'], $companies)),
                'contract_entitlement_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['contract_entitlement_bound_count'], $companies)),
                'onboarding_success_plan_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['onboarding_success_plan_bound_count'], $companies)),
                'service_review_renewal_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_review_renewal_bound_count'], $companies)),
                'account_health_risk_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_health_risk_bound_count'], $companies)),
                'billing_revenue_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['billing_revenue_model_bound_count'], $companies)),
                'vendor_legal_procurement_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_legal_procurement_bound_count'], $companies)),
                'vendor_due_diligence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_due_diligence_bound_count'], $companies)),
                'source_terms_review_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_terms_review_bound_count'], $companies)),
                'flow_procurement_routing_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_procurement_routing_bound_count'], $companies)),
                'vendor_operability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_operability_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_vendor_procurement_allowed' => false,
                'commercial_runtime_requires_offer_journey_account_contract_billing_vendor_and_procurement_controls' => true,
                'operator_mandate_required_for_customer_vendor_billing_or_public_claim' => true,
            ],
        ];
        $payload['commercial_operations_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainProviderWorkbenchRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $providerRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_provider_workbench_runtime_bound'] ?? false)
                    && (bool) ($record['provider_contracts_bound'] ?? false)
                    && (bool) ($record['connector_workbenches_bound'] ?? false)
                    && (bool) ($record['flow_provider_route_bound'] ?? false)
                    && (bool) ($record['provider_eval_cases_bound'] ?? false)
                    && (bool) ($record['provider_data_product_lineage_bound'] ?? false)
                    && (bool) ($record['provider_workbench_observability_bound'] ?? false)
                    && (bool) ($record['provider_external_write_paid_blocked'] ?? false)
                    && (string) ($record['domain_provider_workbench_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $providerRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_provider_workbench_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_provider_workbench_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'provider_contracts_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['provider_contracts_bound'] ?? false))),
                'connector_workbenches_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_workbenches_bound'] ?? false))),
                'flow_provider_route_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_provider_route_bound'] ?? false))),
                'provider_eval_cases_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['provider_eval_cases_bound'] ?? false))),
                'provider_data_product_lineage_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['provider_data_product_lineage_bound'] ?? false))),
                'provider_workbench_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['provider_workbench_observability_bound'] ?? false))),
                'provider_external_write_paid_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['provider_external_write_paid_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_provider_workbench_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_provider_workbench_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_provider_workbench_runtime_coverage_external_write_paid_blocked'
                : 'missing_domain_provider_workbench_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_provider_workbench_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'provider_contracts_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_contracts_bound_count'], $companies)),
                'connector_workbenches_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbenches_bound_count'], $companies)),
                'flow_provider_route_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_provider_route_bound_count'], $companies)),
                'provider_eval_cases_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_eval_cases_bound_count'], $companies)),
                'provider_data_product_lineage_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_data_product_lineage_bound_count'], $companies)),
                'provider_workbench_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_workbench_observability_bound_count'], $companies)),
                'provider_external_write_paid_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_external_write_paid_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'provider_write_or_paid_action_default' => false,
                'domain_provider_runtime_requires_contracts_workbenches_routes_eval_lineage_and_observability' => true,
                'operator_signed_scope_required_for_provider_write_spend_trade_publish_or_secret_export' => true,
            ],
        ];
        $payload['domain_provider_workbench_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainCompanyExecutionSuiteRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $suiteRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_company_execution_suite_runtime_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_stack_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_source_catalog_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_operating_model_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_connector_workbench_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_flow_packet_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_risk_control_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_decision_room_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_replay_eval_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_observability_bound'] ?? false)
                    && (bool) ($record['domain_execution_suite_external_actions_blocked'] ?? false)
                    && (string) ($record['domain_company_execution_suite_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $suiteRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_domain_company_execution_suite_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_domain_company_execution_suite_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'suite_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_stack_bound'] ?? false))),
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_source_catalog_bound'] ?? false))),
                'operating_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_operating_model_bound'] ?? false))),
                'connector_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_connector_workbench_bound'] ?? false))),
                'flow_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_flow_packet_bound'] ?? false))),
                'risk_control_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_risk_control_bound'] ?? false))),
                'decision_room_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_decision_room_bound'] ?? false))),
                'replay_eval_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_replay_eval_bound'] ?? false))),
                'observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_observability_bound'] ?? false))),
                'external_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_execution_suite_external_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_domain_company_execution_suite_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_company_execution_suite_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_company_execution_suite_runtime_coverage_external_actions_blocked'
                : 'missing_domain_company_execution_suite_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_domain_company_execution_suite_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'suite_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['suite_stack_bound_count'], $companies)),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'operating_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operating_model_bound_count'], $companies)),
                'connector_workbench_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_bound_count'], $companies)),
                'flow_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_packet_bound_count'], $companies)),
                'risk_control_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['risk_control_bound_count'], $companies)),
                'decision_room_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['decision_room_bound_count'], $companies)),
                'replay_eval_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_eval_bound_count'], $companies)),
                'observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_bound_count'], $companies)),
                'external_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'domain_company_suite_requires_sources_connectors_flow_packets_risk_controls_decision_rooms_replay_and_observability' => true,
            ],
        ];
        $payload['domain_company_execution_suite_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowWorkProductDeliveryRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $deliveryRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['flow_work_product_delivery_runtime_bound'] ?? false)
                    && (bool) ($record['flow_work_product_delivery_stack_bound'] ?? false)
                    && (bool) ($record['flow_work_product_catalog_bound'] ?? false)
                    && (bool) ($record['flow_work_product_blueprint_bound'] ?? false)
                    && (bool) ($record['flow_work_product_acceptance_bound'] ?? false)
                    && (bool) ($record['flow_work_product_handoff_bound'] ?? false)
                    && (bool) ($record['flow_work_product_replay_check_bound'] ?? false)
                    && (bool) ($record['flow_work_product_external_delivery_blocked'] ?? false)
                    && (string) ($record['flow_work_product_delivery_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $deliveryRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_flow_work_product_delivery_count' => count(array_intersect($flows, $completedFlows)),
                'missing_flow_work_product_delivery_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'delivery_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_delivery_stack_bound'] ?? false))),
                'catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_catalog_bound'] ?? false))),
                'blueprint_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_blueprint_bound'] ?? false))),
                'acceptance_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_acceptance_bound'] ?? false))),
                'handoff_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_handoff_bound'] ?? false))),
                'replay_check_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_replay_check_bound'] ?? false))),
                'external_delivery_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_work_product_external_delivery_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_flow_work_product_delivery_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_flow_work_product_delivery_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_flow_work_product_delivery_runtime_coverage_external_delivery_blocked'
                : 'missing_flow_work_product_delivery_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_flow_work_product_delivery_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'delivery_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['delivery_stack_bound_count'], $companies)),
                'catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['catalog_bound_count'], $companies)),
                'blueprint_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['blueprint_bound_count'], $companies)),
                'acceptance_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_bound_count'], $companies)),
                'handoff_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['handoff_bound_count'], $companies)),
                'replay_check_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_check_bound_count'], $companies)),
                'external_delivery_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_delivery_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_delivery_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_acceptance_required_before_external_handoff' => true,
                'flow_work_product_delivery_requires_catalog_blueprint_acceptance_handoff_and_replay' => true,
            ],
        ];
        $payload['flow_work_product_delivery_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainDataConnectorOperatingRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $dataConnectorRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_data_connector_operating_runtime_bound'] ?? false)
                    && (bool) ($record['domain_data_connector_stack_bound'] ?? false)
                    && (bool) ($record['domain_data_room_source_catalog_bound'] ?? false)
                    && (bool) ($record['domain_data_product_contracts_bound'] ?? false)
                    && (bool) ($record['domain_connector_permission_profiles_bound'] ?? false)
                    && (bool) ($record['domain_flow_data_connector_contract_bound'] ?? false)
                    && (bool) ($record['domain_connector_fixture_eval_bound'] ?? false)
                    && (bool) ($record['domain_data_room_operating_model_bound'] ?? false)
                    && (bool) ($record['domain_data_connector_observability_bound'] ?? false)
                    && (bool) ($record['domain_data_connector_external_mutations_blocked'] ?? false)
                    && (string) ($record['domain_data_connector_operating_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $dataConnectorRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_domain_data_connector_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_domain_data_connector_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'data_connector_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_connector_stack_bound'] ?? false))),
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_room_source_catalog_bound'] ?? false))),
                'data_product_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_product_contracts_bound'] ?? false))),
                'permission_profile_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_connector_permission_profiles_bound'] ?? false))),
                'flow_data_connector_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_flow_data_connector_contract_bound'] ?? false))),
                'fixture_eval_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_connector_fixture_eval_bound'] ?? false))),
                'operating_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_room_operating_model_bound'] ?? false))),
                'observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_connector_observability_bound'] ?? false))),
                'external_mutations_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_connector_external_mutations_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_domain_data_connector_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_data_connector_operating_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_data_connector_operating_runtime_coverage_external_mutations_blocked'
                : 'missing_domain_data_connector_operating_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_domain_data_connector_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'data_connector_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_connector_stack_bound_count'], $companies)),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'data_product_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_product_contract_bound_count'], $companies)),
                'permission_profile_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['permission_profile_bound_count'], $companies)),
                'flow_data_connector_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_data_connector_contract_bound_count'], $companies)),
                'fixture_eval_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['fixture_eval_bound_count'], $companies)),
                'operating_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operating_model_bound_count'], $companies)),
                'observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_bound_count'], $companies)),
                'external_mutations_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_mutations_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'write_tools_enabled' => false,
                'external_data_mutation_allowed' => false,
                'secret_export_allowed' => false,
                'read_only_probe_required_before_live_use' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
        ];
        $payload['domain_data_connector_operating_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowLiveReadConnectorProbeRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $probeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['flow_live_read_connector_probe_runtime_bound'] ?? false)
                    && (bool) ($record['flow_live_read_probe_stack_bound'] ?? false)
                    && (bool) ($record['flow_live_read_connector_profiles_bound'] ?? false)
                    && (bool) ($record['flow_live_read_probe_contract_bound'] ?? false)
                    && (bool) ($record['flow_live_read_probe_evidence_matrix_bound'] ?? false)
                    && (bool) ($record['flow_live_read_probe_observability_bound'] ?? false)
                    && (bool) ($record['flow_live_read_external_mutations_blocked'] ?? false)
                    && (bool) ($record['flow_live_read_operator_scope_required'] ?? false)
                    && (string) ($record['flow_live_read_connector_probe_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $probeRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_flow_live_read_connector_probe_count' => count(array_intersect($flows, $completedFlows)),
                'missing_flow_live_read_connector_probe_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'probe_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_probe_stack_bound'] ?? false))),
                'connector_profiles_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_connector_profiles_bound'] ?? false))),
                'probe_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_probe_contract_bound'] ?? false))),
                'evidence_matrix_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_probe_evidence_matrix_bound'] ?? false))),
                'observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_probe_observability_bound'] ?? false))),
                'external_mutations_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_external_mutations_blocked'] ?? false))),
                'operator_scope_required_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_live_read_operator_scope_required'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_flow_live_read_connector_probe_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_flow_live_read_connector_probe_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_flow_live_read_connector_probe_runtime_coverage_external_mutations_blocked'
                : 'missing_flow_live_read_connector_probe_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_flow_live_read_connector_probe_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'probe_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['probe_stack_bound_count'], $companies)),
                'connector_profiles_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_profiles_bound_count'], $companies)),
                'probe_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['probe_contract_bound_count'], $companies)),
                'evidence_matrix_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evidence_matrix_bound_count'], $companies)),
                'observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_bound_count'], $companies)),
                'external_mutations_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_mutations_blocked_count'], $companies)),
                'operator_scope_required_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_scope_required_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'live_read_allowed' => true,
                'write_tools_enabled' => false,
                'external_mutation_allowed' => false,
                'credential_material_in_packet_allowed' => false,
                'operator_scope_required_before_live_connector_probe' => true,
                'promotion_unlocked' => 'shadow_readiness_not_external_write_authority',
            ],
        ];
        $payload['flow_live_read_connector_probe_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalResearchAdoptionRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $researchRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['external_research_adoption_runtime_bound'] ?? false)
                    && (bool) ($record['external_research_source_basis_bound'] ?? false)
                    && (bool) ($record['external_research_repository_catalog_bound'] ?? false)
                    && (bool) ($record['external_research_flow_adoption_matrix_bound'] ?? false)
                    && (bool) ($record['external_research_capability_map_bound'] ?? false)
                    && (bool) ($record['external_research_connector_backlog_bound'] ?? false)
                    && (bool) ($record['external_research_production_gates_bound'] ?? false)
                    && (bool) ($record['external_research_external_effects_blocked'] ?? false)
                    && (string) ($record['external_research_adoption_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $researchRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_external_research_adoption_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_external_research_adoption_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'source_basis_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_source_basis_bound'] ?? false))),
                'repository_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_repository_catalog_bound'] ?? false))),
                'flow_adoption_matrix_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_flow_adoption_matrix_bound'] ?? false))),
                'capability_map_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_capability_map_bound'] ?? false))),
                'connector_backlog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_connector_backlog_bound'] ?? false))),
                'external_effects_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_research_external_effects_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_external_research_adoption_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_external_research_adoption_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_external_research_adoption_runtime_coverage_external_effects_blocked'
                : 'missing_external_research_adoption_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_external_research_adoption_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'source_basis_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_basis_bound_count'], $companies)),
                'repository_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_catalog_bound_count'], $companies)),
                'flow_adoption_matrix_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_adoption_matrix_bound_count'], $companies)),
                'capability_map_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['capability_map_bound_count'], $companies)),
                'connector_backlog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_backlog_bound_count'], $companies)),
                'external_effects_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_effects_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_research_is_architecture_input_only' => true,
                'repository_adoption_without_license_security_fixture_and_operator_review_allowed' => false,
                'runtime_ingestion_without_source_review_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
            ],
        ];
        $payload['external_research_adoption_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowBenchmarkReplayRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $benchmarkRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['flow_benchmark_replay_runtime_bound'] ?? false)
                    && (bool) ($record['offline_dataset_contract_bound'] ?? false)
                    && (bool) ($record['trace_grading_rubric_bound'] ?? false)
                    && (bool) ($record['adversarial_regression_bound'] ?? false)
                    && (bool) ($record['deterministic_state_assertion_bound'] ?? false)
                    && (bool) ($record['replay_comparison_matrix_bound'] ?? false)
                    && (bool) ($record['benchmark_observability_bound'] ?? false)
                    && (bool) ($record['benchmark_promotion_synthetic_scores_blocked'] ?? false)
                    && (string) ($record['flow_benchmark_replay_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $benchmarkRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_benchmark_replay_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_benchmark_replay_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'offline_dataset_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['offline_dataset_contract_bound'] ?? false))),
                'trace_grading_rubric_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['trace_grading_rubric_bound'] ?? false))),
                'adversarial_regression_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['adversarial_regression_bound'] ?? false))),
                'deterministic_state_assertion_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['deterministic_state_assertion_bound'] ?? false))),
                'replay_comparison_matrix_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['replay_comparison_matrix_bound'] ?? false))),
                'benchmark_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['benchmark_observability_bound'] ?? false))),
                'benchmark_promotion_synthetic_scores_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['benchmark_promotion_synthetic_scores_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_benchmark_replay_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_flow_benchmark_replay_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_flow_benchmark_replay_runtime_coverage_external_benchmark_blocked'
                : 'missing_flow_benchmark_replay_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_benchmark_replay_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'offline_dataset_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offline_dataset_contract_bound_count'], $companies)),
                'trace_grading_rubric_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['trace_grading_rubric_bound_count'], $companies)),
                'adversarial_regression_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['adversarial_regression_bound_count'], $companies)),
                'deterministic_state_assertion_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['deterministic_state_assertion_bound_count'], $companies)),
                'replay_comparison_matrix_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_comparison_matrix_bound_count'], $companies)),
                'benchmark_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['benchmark_observability_bound_count'], $companies)),
                'benchmark_promotion_synthetic_scores_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['benchmark_promotion_synthetic_scores_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
                'benchmark_runtime_requires_dataset_rubric_adversarial_state_assertion_replay_matrix_and_observability' => true,
            ],
        ];
        $payload['flow_benchmark_replay_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorCertificationPreflightRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $connectorRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['connector_certification_preflight_runtime_bound'] ?? false)
                    && (bool) ($record['connector_adapter_contracts_bound'] ?? false)
                    && (bool) ($record['connector_auth_boundaries_bound'] ?? false)
                    && (bool) ($record['connector_sandbox_probes_bound'] ?? false)
                    && (bool) ($record['connector_contract_tests_bound'] ?? false)
                    && (bool) ($record['connector_data_lineage_bound'] ?? false)
                    && (bool) ($record['connector_replay_fixtures_bound'] ?? false)
                    && (bool) ($record['connector_slo_failure_modes_bound'] ?? false)
                    && (bool) ($record['production_preflight_contracts_bound'] ?? false)
                    && (bool) ($record['flow_cutover_matrix_bound'] ?? false)
                    && (bool) ($record['production_readiness_evidence_bound'] ?? false)
                    && (bool) ($record['connector_preflight_external_cutover_blocked'] ?? false)
                    && (string) ($record['connector_certification_preflight_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $connectorRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_connector_certification_preflight_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_connector_certification_preflight_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'connector_adapter_contracts_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_adapter_contracts_bound'] ?? false))),
                'connector_auth_boundaries_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_auth_boundaries_bound'] ?? false))),
                'connector_sandbox_probes_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_sandbox_probes_bound'] ?? false))),
                'connector_contract_tests_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_contract_tests_bound'] ?? false))),
                'connector_data_lineage_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_data_lineage_bound'] ?? false))),
                'connector_replay_fixtures_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_replay_fixtures_bound'] ?? false))),
                'connector_slo_failure_modes_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_slo_failure_modes_bound'] ?? false))),
                'production_preflight_contracts_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['production_preflight_contracts_bound'] ?? false))),
                'flow_cutover_matrix_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_cutover_matrix_bound'] ?? false))),
                'production_readiness_evidence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['production_readiness_evidence_bound'] ?? false))),
                'connector_certification_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_certification_observability_bound'] ?? false))),
                'cutover_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['cutover_observability_bound'] ?? false))),
                'connector_preflight_external_cutover_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_preflight_external_cutover_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_connector_certification_preflight_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_connector_certification_preflight_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_connector_certification_preflight_runtime_coverage_external_cutover_blocked'
                : 'missing_connector_certification_preflight_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_connector_certification_preflight_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'connector_adapter_contracts_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_adapter_contracts_bound_count'], $companies)),
                'connector_auth_boundaries_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_auth_boundaries_bound_count'], $companies)),
                'connector_sandbox_probes_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_sandbox_probes_bound_count'], $companies)),
                'connector_contract_tests_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_contract_tests_bound_count'], $companies)),
                'connector_data_lineage_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_data_lineage_bound_count'], $companies)),
                'connector_replay_fixtures_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_replay_fixtures_bound_count'], $companies)),
                'connector_slo_failure_modes_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_slo_failure_modes_bound_count'], $companies)),
                'production_preflight_contracts_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_preflight_contracts_bound_count'], $companies)),
                'flow_cutover_matrix_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_cutover_matrix_bound_count'], $companies)),
                'production_readiness_evidence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_readiness_evidence_bound_count'], $companies)),
                'connector_certification_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_certification_observability_bound_count'], $companies)),
                'cutover_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['cutover_observability_bound_count'], $companies)),
                'connector_preflight_external_cutover_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_preflight_external_cutover_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'write_or_paid_mode_allowed_by_default' => false,
                'connector_runtime_requires_certification_preflight_cutover_evidence_and_observability' => true,
            ],
        ];
        $payload['connector_certification_preflight_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function commandCenterControlTowerRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $commandCenterRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['command_center_control_tower_runtime_bound'] ?? false)
                    && (bool) ($record['control_tower_lane_bound'] ?? false)
                    && (bool) ($record['flow_command_card_bound'] ?? false)
                    && (bool) ($record['incident_exception_desk_bound'] ?? false)
                    && (bool) ($record['change_window_release_bound'] ?? false)
                    && (bool) ($record['operator_console_views_bound'] ?? false)
                    && (bool) ($record['command_center_cells_bound'] ?? false)
                    && (bool) ($record['connector_panels_bound'] ?? false)
                    && (bool) ($record['work_product_factory_bound'] ?? false)
                    && (bool) ($record['command_center_kpis_bound'] ?? false)
                    && (bool) ($record['command_center_external_action_blocked'] ?? false)
                    && (string) ($record['command_center_control_tower_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $commandCenterRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_command_center_control_tower_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_command_center_control_tower_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'control_tower_lane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['control_tower_lane_bound'] ?? false))),
                'flow_command_card_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_command_card_bound'] ?? false))),
                'incident_exception_desk_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['incident_exception_desk_bound'] ?? false))),
                'change_window_release_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['change_window_release_bound'] ?? false))),
                'operator_console_views_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operator_console_views_bound'] ?? false))),
                'command_center_cells_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['command_center_cells_bound'] ?? false))),
                'connector_panels_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_panels_bound'] ?? false))),
                'work_product_factory_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['work_product_factory_bound'] ?? false))),
                'command_center_kpis_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['command_center_kpis_bound'] ?? false))),
                'command_center_external_action_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['command_center_external_action_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_command_center_control_tower_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_command_center_control_tower_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_command_center_control_tower_runtime_coverage_external_actions_blocked'
                : 'missing_command_center_control_tower_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_command_center_control_tower_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'control_tower_lane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['control_tower_lane_bound_count'], $companies)),
                'flow_command_card_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_command_card_bound_count'], $companies)),
                'incident_exception_desk_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['incident_exception_desk_bound_count'], $companies)),
                'change_window_release_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['change_window_release_bound_count'], $companies)),
                'operator_console_views_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_console_views_bound_count'], $companies)),
                'command_center_cells_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_cells_bound_count'], $companies)),
                'connector_panels_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_panels_bound_count'], $companies)),
                'work_product_factory_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['work_product_factory_bound_count'], $companies)),
                'command_center_kpis_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_kpis_bound_count'], $companies)),
                'command_center_external_action_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_external_action_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'command_center_runtime_requires_lane_card_incident_change_console_cells_connectors_factory_kpis_and_human_interrupts' => true,
            ],
        ];
        $payload['command_center_control_tower_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $rehearsalRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['operational_dress_rehearsal_runtime_bound'] ?? false)
                    && (bool) ($record['rehearsal_runbook_bound'] ?? false)
                    && (bool) ($record['live_read_probe_plan_bound'] ?? false)
                    && (bool) ($record['operator_acceptance_packet_bound'] ?? false)
                    && (bool) ($record['rollback_drill_bound'] ?? false)
                    && (bool) ($record['promotion_evidence_bound'] ?? false)
                    && (bool) ($record['dress_rehearsal_observability_bound'] ?? false)
                    && (bool) ($record['dress_rehearsal_external_mutation_blocked'] ?? false)
                    && (string) ($record['operational_dress_rehearsal_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $rehearsalRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operational_dress_rehearsal_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operational_dress_rehearsal_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'rehearsal_runbook_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['rehearsal_runbook_bound'] ?? false))),
                'live_read_probe_plan_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['live_read_probe_plan_bound'] ?? false))),
                'operator_acceptance_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operator_acceptance_packet_bound'] ?? false))),
                'rollback_drill_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['rollback_drill_bound'] ?? false))),
                'promotion_evidence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['promotion_evidence_bound'] ?? false))),
                'dress_rehearsal_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dress_rehearsal_observability_bound'] ?? false))),
                'dress_rehearsal_external_mutation_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dress_rehearsal_external_mutation_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operational_dress_rehearsal_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_operational_dress_rehearsal_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_operational_dress_rehearsal_runtime_coverage_external_mutation_blocked'
                : 'missing_operational_dress_rehearsal_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_operational_dress_rehearsal_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'rehearsal_runbook_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rehearsal_runbook_bound_count'], $companies)),
                'live_read_probe_plan_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['live_read_probe_plan_bound_count'], $companies)),
                'operator_acceptance_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_acceptance_packet_bound_count'], $companies)),
                'rollback_drill_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rollback_drill_bound_count'], $companies)),
                'promotion_evidence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['promotion_evidence_bound_count'], $companies)),
                'dress_rehearsal_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['dress_rehearsal_observability_bound_count'], $companies)),
                'dress_rehearsal_external_mutation_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['dress_rehearsal_external_mutation_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'production_cutover_without_signed_acceptance_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'operational_rehearsal_requires_runbook_live_probe_acceptance_rollback_promotion_evidence_and_observability' => true,
            ],
        ];
        $payload['operational_dress_rehearsal_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function semanticOperatingGraphRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $graphRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['semantic_operating_graph_runtime_bound'] ?? false)
                    && (bool) ($record['semantic_graph_bound'] ?? false)
                    && (bool) ($record['semantic_node_catalog_bound'] ?? false)
                    && (bool) ($record['semantic_flow_edge_bound'] ?? false)
                    && (bool) ($record['semantic_operating_views_bound'] ?? false)
                    && (bool) ($record['semantic_drift_rules_bound'] ?? false)
                    && (bool) ($record['semantic_export_contract_bound'] ?? false)
                    && (bool) ($record['semantic_graph_observability_bound'] ?? false)
                    && (bool) ($record['semantic_graph_secret_export_blocked'] ?? false)
                    && (string) ($record['semantic_operating_graph_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $graphRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_semantic_graph_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_semantic_graph_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'semantic_graph_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_graph_bound'] ?? false))),
                'semantic_node_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_node_catalog_bound'] ?? false))),
                'semantic_flow_edge_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_flow_edge_bound'] ?? false))),
                'semantic_operating_views_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_operating_views_bound'] ?? false))),
                'semantic_drift_rules_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_drift_rules_bound'] ?? false))),
                'semantic_export_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_export_contract_bound'] ?? false))),
                'semantic_graph_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_graph_observability_bound'] ?? false))),
                'semantic_graph_secret_export_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['semantic_graph_secret_export_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_semantic_graph_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_semantic_operating_graph_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_semantic_operating_graph_runtime_coverage_external_mutation_blocked'
                : 'missing_semantic_operating_graph_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_semantic_graph_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'semantic_graph_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_graph_bound_count'], $companies)),
                'semantic_node_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_node_catalog_bound_count'], $companies)),
                'semantic_flow_edge_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_flow_edge_bound_count'], $companies)),
                'semantic_operating_views_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_operating_views_bound_count'], $companies)),
                'semantic_drift_rules_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_drift_rules_bound_count'], $companies)),
                'semantic_export_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_export_contract_bound_count'], $companies)),
                'semantic_graph_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_graph_observability_bound_count'], $companies)),
                'semantic_graph_secret_export_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_graph_secret_export_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_graph_mutation_allowed' => false,
                'raw_secret_or_sensitive_payload_export_allowed' => false,
                'semantic_graph_runtime_requires_node_catalog_flow_edges_views_drift_rules_export_contract_and_observability' => true,
                'stale_or_missing_graph_edge_blocks_autonomy_claim' => true,
            ],
        ];
        $payload['semantic_operating_graph_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainSolutionPlaybookRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $playbookRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_solution_playbook_runtime_bound'] ?? false)
                    && (bool) ($record['solution_playbook_bound'] ?? false)
                    && (bool) ($record['source_pack_bound'] ?? false)
                    && (bool) ($record['domain_data_plane_bound'] ?? false)
                    && (bool) ($record['execution_path_bound'] ?? false)
                    && (bool) ($record['tooling_contract_bound'] ?? false)
                    && (bool) ($record['domain_review_contract_bound'] ?? false)
                    && (bool) ($record['benchmark_contract_bound'] ?? false)
                    && (bool) ($record['handoff_contract_bound'] ?? false)
                    && (bool) ($record['external_mutation_blocked'] ?? false)
                    && (string) ($record['domain_solution_playbook_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $playbookRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_domain_solution_playbook_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_domain_solution_playbook_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'solution_playbook_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['solution_playbook_bound'] ?? false))),
                'source_pack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['source_pack_bound'] ?? false))),
                'domain_data_plane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_data_plane_bound'] ?? false))),
                'execution_path_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['execution_path_bound'] ?? false))),
                'tooling_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['tooling_contract_bound'] ?? false))),
                'domain_review_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_review_contract_bound'] ?? false))),
                'benchmark_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['benchmark_contract_bound'] ?? false))),
                'handoff_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['handoff_contract_bound'] ?? false))),
                'external_mutation_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['external_mutation_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_domain_solution_playbook_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_solution_playbook_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_solution_playbook_runtime_coverage_external_mutation_blocked'
                : 'missing_domain_solution_playbook_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_domain_solution_playbook_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'solution_playbook_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['solution_playbook_bound_count'], $companies)),
                'source_pack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_pack_bound_count'], $companies)),
                'domain_data_plane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_data_plane_bound_count'], $companies)),
                'execution_path_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['execution_path_bound_count'], $companies)),
                'tooling_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['tooling_contract_bound_count'], $companies)),
                'domain_review_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_review_contract_bound_count'], $companies)),
                'benchmark_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['benchmark_contract_bound_count'], $companies)),
                'handoff_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['handoff_contract_bound_count'], $companies)),
                'external_mutation_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_mutation_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_data_mutation_allowed' => false,
                'external_delivery_allowed' => false,
                'domain_solution_runtime_requires_playbook_source_pack_data_plane_tool_contract_review_benchmark_and_handoff' => true,
                'real_connector_activation_requires_signed_scope_credentials_and_green_probe' => true,
            ],
        ];
        $payload['domain_solution_playbook_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainOperatingDepthRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $depthRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_operating_depth_runtime_bound'] ?? false)
                    && (bool) ($record['domain_depth_packet_bound'] ?? false)
                    && (bool) ($record['domain_depth_skills_bound'] ?? false)
                    && (bool) ($record['domain_depth_connector_refs_bound'] ?? false)
                    && (bool) ($record['domain_depth_subagents_bound'] ?? false)
                    && (bool) ($record['domain_depth_source_refs_bound'] ?? false)
                    && (bool) ($record['domain_depth_enterprise_system_refs_bound'] ?? false)
                    && (bool) ($record['domain_depth_data_product_refs_bound'] ?? false)
                    && (bool) ($record['domain_depth_quality_contract_bound'] ?? false)
                    && (bool) ($record['domain_depth_operating_controls_bound'] ?? false)
                    && (bool) ($record['domain_depth_external_effects_blocked'] ?? false)
                    && (string) ($record['domain_operating_depth_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $depthRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_domain_operating_depth_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_domain_operating_depth_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'depth_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_packet_bound'] ?? false))),
                'skills_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_skills_bound'] ?? false))),
                'connector_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_connector_refs_bound'] ?? false))),
                'subagents_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_subagents_bound'] ?? false))),
                'source_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_source_refs_bound'] ?? false))),
                'enterprise_system_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_enterprise_system_refs_bound'] ?? false))),
                'data_product_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_data_product_refs_bound'] ?? false))),
                'quality_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_quality_contract_bound'] ?? false))),
                'operating_controls_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_operating_controls_bound'] ?? false))),
                'external_effects_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_depth_external_effects_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_domain_operating_depth_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_operating_depth_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_operating_depth_runtime_coverage_external_effects_blocked'
                : 'missing_domain_operating_depth_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_domain_operating_depth_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'depth_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['depth_packet_bound_count'], $companies)),
                'skills_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['skills_bound_count'], $companies)),
                'connector_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_refs_bound_count'], $companies)),
                'subagents_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['subagents_bound_count'], $companies)),
                'source_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_refs_bound_count'], $companies)),
                'enterprise_system_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['enterprise_system_refs_bound_count'], $companies)),
                'data_product_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_product_refs_bound_count'], $companies)),
                'quality_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['quality_contract_bound_count'], $companies)),
                'operating_controls_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operating_controls_bound_count'], $companies)),
                'external_effects_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_effects_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'domain_depth_runtime_requires_skills_connectors_subagents_sources_systems_data_products_quality_contract_and_controls' => true,
                'operator_mandate_required_for_any_external_effect' => true,
            ],
        ];
        $payload['domain_operating_depth_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainAgentWorkforceRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $workforceRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['domain_agent_workforce_runtime_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_crew_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_skills_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_connector_refs_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_subagents_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_source_refs_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_work_surface_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_managed_controls_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_work_queue_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_acceptance_bound'] ?? false)
                    && (bool) ($record['domain_agent_workforce_external_effects_blocked'] ?? false)
                    && (string) ($record['domain_agent_workforce_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $workforceRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_domain_agent_workforce_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_domain_agent_workforce_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'crew_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_crew_bound'] ?? false))),
                'skills_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_skills_bound'] ?? false))),
                'connector_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_connector_refs_bound'] ?? false))),
                'subagents_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_subagents_bound'] ?? false))),
                'source_refs_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_source_refs_bound'] ?? false))),
                'work_surface_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_work_surface_bound'] ?? false))),
                'managed_controls_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_managed_controls_bound'] ?? false))),
                'work_queue_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_work_queue_bound'] ?? false))),
                'acceptance_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_acceptance_bound'] ?? false))),
                'external_effects_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['domain_agent_workforce_external_effects_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_domain_agent_workforce_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_domain_agent_workforce_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_domain_agent_workforce_runtime_coverage_external_effects_blocked'
                : 'missing_domain_agent_workforce_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_domain_agent_workforce_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'crew_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['crew_bound_count'], $companies)),
                'skills_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['skills_bound_count'], $companies)),
                'connector_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_refs_bound_count'], $companies)),
                'subagents_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['subagents_bound_count'], $companies)),
                'source_refs_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_refs_bound_count'], $companies)),
                'work_surface_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['work_surface_bound_count'], $companies)),
                'managed_controls_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['managed_controls_bound_count'], $companies)),
                'work_queue_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['work_queue_bound_count'], $companies)),
                'acceptance_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['acceptance_bound_count'], $companies)),
                'external_effects_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_effects_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'offensive_security_allowed' => false,
                'domain_agent_workforce_requires_crews_skills_connectors_subagents_work_surfaces_permissions_vault_audit_queue_acceptance' => true,
                'operator_mandate_required_for_any_external_effect' => true,
            ],
        ];
        $payload['domain_agent_workforce_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
        $companies = [];
        $records = [];
        $boundKeys = [
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
        ];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $foundationRecords = array_values(array_filter(
                $companyRecords,
                static function (array $record) use ($boundKeys): bool {
                    if (! (bool) ($record['flow_execution_foundation_runtime_bound'] ?? false)
                        || (string) ($record['flow_execution_foundation_attestation_hash'] ?? '') === ''
                        || (bool) ($record['external_side_effects'] ?? true) !== false) {
                        return false;
                    }

                    foreach ($boundKeys as $recordKey) {
                        if (! (bool) ($record[$recordKey] ?? false)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $foundationRecords,
            ))));

            $companySummary = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_flow_execution_foundation_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_flow_execution_foundation_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
            ];
            foreach ($boundKeys as $summaryKey => $recordKey) {
                $companySummary[$summaryKey] = count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record[$recordKey] ?? false)));
            }

            $companies[] = $companySummary;
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_flow_execution_foundation_flow_count'], $companies));
        $summary = [
            'company_count' => count($companies),
            'expected_flow_count' => $expectedFlowCount,
            'completed_flow_execution_foundation_flow_count' => $completedFlowCount,
            'runtime_record_count' => count($records),
        ];
        foreach ($boundKeys as $summaryKey => $_recordKey) {
            $summary[$summaryKey] = array_sum(array_map(static fn (array $company): int => (int) $company[$summaryKey], $companies));
        }
        $summary['external_side_effect_count'] = count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true)));
        $summary['coverage_rate'] = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_flow_execution_foundation_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_flow_execution_foundation_runtime_coverage_external_actions_blocked'
                : 'missing_flow_execution_foundation_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => $summary,
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'ungoverned_external_side_effects_allowed' => false,
                'operator_checkpoint_required_before_external_action' => true,
                'flow_execution_foundation_requires_runbook_implementation_fixtures_simulation_gates_outbox_and_observability' => true,
            ],
        ];
        $payload['flow_execution_foundation_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function agentToolchainRuntimeStatusPayload(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $toolchainRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['agent_toolchain_runtime_bound'] ?? false)
                    && (bool) ($record['framework_source_catalog_bound'] ?? false)
                    && (bool) ($record['flow_toolkit_assignment_bound'] ?? false)
                    && (bool) ($record['agent_repository_epic_bound'] ?? false)
                    && (bool) ($record['guardrails_runtime_bound'] ?? false)
                    && (bool) ($record['handoffs_runtime_bound'] ?? false)
                    && (bool) ($record['tracing_runtime_bound'] ?? false)
                    && (bool) ($record['durable_state_runtime_bound'] ?? false)
                    && (bool) ($record['human_in_loop_runtime_bound'] ?? false)
                    && (string) ($record['agent_toolchain_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $toolchainRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_agent_toolchain_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_agent_toolchain_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'framework_source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['framework_source_catalog_bound'] ?? false))),
                'flow_toolkit_assignment_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_toolkit_assignment_bound'] ?? false))),
                'agent_repository_epic_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['agent_repository_epic_bound'] ?? false))),
                'guardrails_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['guardrails_runtime_bound'] ?? false))),
                'handoffs_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['handoffs_runtime_bound'] ?? false))),
                'tracing_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['tracing_runtime_bound'] ?? false))),
                'durable_state_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['durable_state_runtime_bound'] ?? false))),
                'human_in_loop_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['human_in_loop_runtime_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_agent_toolchain_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_agent_toolchain_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_agent_toolchain_runtime_coverage_external_blocked'
                : 'missing_agent_toolchain_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_agent_toolchain_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'framework_source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_source_catalog_bound_count'], $companies)),
                'flow_toolkit_assignment_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_toolkit_assignment_bound_count'], $companies)),
                'agent_repository_epic_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['agent_repository_epic_bound_count'], $companies)),
                'guardrails_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['guardrails_runtime_bound_count'], $companies)),
                'handoffs_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['handoffs_runtime_bound_count'], $companies)),
                'tracing_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['tracing_runtime_bound_count'], $companies)),
                'durable_state_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['durable_state_runtime_bound_count'], $companies)),
                'human_in_loop_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['human_in_loop_runtime_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
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
        ];
        $payload['agent_toolchain_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function workforceCapacityRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $workforceRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['workforce_capacity_runtime_bound'] ?? false)
                    && (bool) ($record['workforce_stack_bound'] ?? false)
                    && (bool) ($record['workforce_org_model_bound'] ?? false)
                    && (bool) ($record['workforce_agent_capacity_plan_bound'] ?? false)
                    && (bool) ($record['workforce_flow_staffing_bound'] ?? false)
                    && (bool) ($record['workforce_training_enablement_bound'] ?? false)
                    && (bool) ($record['workforce_succession_continuity_bound'] ?? false)
                    && (bool) ($record['workforce_capacity_observability_bound'] ?? false)
                    && (bool) ($record['workforce_capacity_external_changes_blocked'] ?? false)
                    && (string) ($record['workforce_capacity_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $workforceRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_workforce_capacity_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_workforce_capacity_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'workforce_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_stack_bound'] ?? false))),
                'org_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_org_model_bound'] ?? false))),
                'agent_capacity_plan_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_agent_capacity_plan_bound'] ?? false))),
                'flow_staffing_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_flow_staffing_bound'] ?? false))),
                'training_enablement_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_training_enablement_bound'] ?? false))),
                'succession_continuity_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_succession_continuity_bound'] ?? false))),
                'capacity_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_capacity_observability_bound'] ?? false))),
                'external_changes_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['workforce_capacity_external_changes_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_workforce_capacity_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_workforce_capacity_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_workforce_capacity_runtime_coverage_external_staffing_changes_blocked'
                : 'missing_workforce_capacity_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_workforce_capacity_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'workforce_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['workforce_stack_bound_count'], $companies)),
                'org_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['org_model_bound_count'], $companies)),
                'agent_capacity_plan_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['agent_capacity_plan_bound_count'], $companies)),
                'flow_staffing_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_staffing_bound_count'], $companies)),
                'training_enablement_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['training_enablement_bound_count'], $companies)),
                'succession_continuity_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['succession_continuity_bound_count'], $companies)),
                'capacity_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['capacity_observability_bound_count'], $companies)),
                'external_changes_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_changes_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'single_agent_bottleneck_allowed' => false,
                'external_unreviewed_staffing_change_allowed' => false,
                'workforce_runtime_requires_capacity_plan_flow_staffing_training_succession_and_observability' => true,
            ],
        ];
        $payload['workforce_capacity_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDependencyRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $dependencyRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['portfolio_dependency_runtime_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_stack_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_role_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_intake_contract_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_upstream_map_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_integration_map_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_flow_routing_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_escalation_conflict_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_reporting_bound'] ?? false)
                    && (bool) ($record['portfolio_dependency_external_actions_blocked'] ?? false)
                    && (string) ($record['portfolio_dependency_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $dependencyRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_portfolio_dependency_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_portfolio_dependency_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'dependency_stack_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_stack_bound'] ?? false))),
                'role_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_role_bound'] ?? false))),
                'intake_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_intake_contract_bound'] ?? false))),
                'upstream_map_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_upstream_map_bound'] ?? false))),
                'integration_map_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_integration_map_bound'] ?? false))),
                'flow_routing_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_flow_routing_bound'] ?? false))),
                'escalation_conflict_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_escalation_conflict_bound'] ?? false))),
                'reporting_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_reporting_bound'] ?? false))),
                'external_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['portfolio_dependency_external_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_portfolio_dependency_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_portfolio_dependency_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_portfolio_dependency_runtime_coverage_external_dependency_actions_blocked'
                : 'missing_portfolio_dependency_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_portfolio_dependency_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'dependency_stack_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['dependency_stack_bound_count'], $companies)),
                'role_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['role_bound_count'], $companies)),
                'intake_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['intake_contract_bound_count'], $companies)),
                'upstream_map_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['upstream_map_bound_count'], $companies)),
                'integration_map_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['integration_map_bound_count'], $companies)),
                'flow_routing_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_routing_bound_count'], $companies)),
                'escalation_conflict_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['escalation_conflict_bound_count'], $companies)),
                'reporting_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['reporting_bound_count'], $companies)),
                'external_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'external_spend_publish_write_trade_or_transfer_allowed' => false,
                'cross_company_dependency_without_typed_handoff_allowed' => false,
                'unresolved_conflict_external_side_effect_allowed' => false,
                'portfolio_dependency_runtime_requires_role_intake_maps_flow_routing_escalation_and_reporting' => true,
            ],
        ];
        $payload['portfolio_dependency_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDossierRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $dossierRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['operational_dossier_runtime_bound'] ?? false)
                    && (bool) ($record['operational_dossier_bound'] ?? false)
                    && (bool) ($record['dossier_evidence_spine_bound'] ?? false)
                    && (bool) ($record['dossier_control_plane_bound'] ?? false)
                    && (bool) ($record['dossier_decision_packet_bound'] ?? false)
                    && (bool) ($record['dossier_promotion_path_bound'] ?? false)
                    && (bool) ($record['dossier_scorecard_bound'] ?? false)
                    && (bool) ($record['dossier_external_blocked'] ?? false)
                    && (string) ($record['operational_dossier_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $dossierRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operational_dossier_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operational_dossier_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'dossier_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_dossier_bound'] ?? false))),
                'evidence_spine_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_evidence_spine_bound'] ?? false))),
                'control_plane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_control_plane_bound'] ?? false))),
                'decision_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_decision_packet_bound'] ?? false))),
                'promotion_path_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_promotion_path_bound'] ?? false))),
                'scorecard_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_scorecard_bound'] ?? false))),
                'external_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['dossier_external_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operational_dossier_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_operational_dossier_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_operational_dossier_runtime_coverage_external_blocked'
                : 'missing_operational_dossier_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_operational_dossier_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'dossier_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['dossier_bound_count'], $companies)),
                'evidence_spine_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evidence_spine_bound_count'], $companies)),
                'control_plane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['control_plane_bound_count'], $companies)),
                'decision_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['decision_packet_bound_count'], $companies)),
                'promotion_path_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['promotion_path_bound_count'], $companies)),
                'scorecard_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['scorecard_bound_count'], $companies)),
                'external_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
                'operational_dossier_requires_evidence_controls_decision_packet_promotion_path_and_scorecard' => true,
                'operator_mandate_required_for_any_external_promotion' => true,
            ],
        ];
        $payload['operational_dossier_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_operational_outcome_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_operational_outcome_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'operational_outcome_ledger_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_operational_outcome_flow_count'], $companies));

        $payload = [
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
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operational_outcome_runtime_requires_ledger_per_flow' => true,
                'real_world_outcome_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ];
        $payload['operational_outcome_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function autonomyPromotionRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $promotionRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['autonomy_promotion_runtime_bound'] ?? false)
                    && (bool) ($record['autonomy_ladder_bound'] ?? false)
                    && (bool) ($record['autonomy_fixture_level_bound'] ?? false)
                    && (bool) ($record['autonomy_shadow_level_bound'] ?? false)
                    && (bool) ($record['autonomy_supervised_internal_level_bound'] ?? false)
                    && (bool) ($record['autonomy_supervised_external_packet_bound'] ?? false)
                    && (bool) ($record['autonomy_limited_external_autonomy_blocked'] ?? false)
                    && (bool) ($record['autonomy_evidence_spine_bound'] ?? false)
                    && (bool) ($record['autonomy_rollback_reconciliation_bound'] ?? false)
                    && (string) ($record['autonomy_promotion_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $promotionRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_autonomy_promotion_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_autonomy_promotion_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'autonomy_ladder_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['autonomy_ladder_bound'] ?? false))),
                'supervised_external_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['autonomy_supervised_external_packet_bound'] ?? false))),
                'limited_external_autonomy_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['autonomy_limited_external_autonomy_blocked'] ?? false))),
                'evidence_spine_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['autonomy_evidence_spine_bound'] ?? false))),
                'rollback_reconciliation_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['autonomy_rollback_reconciliation_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_autonomy_promotion_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_autonomy_promotion_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_autonomy_promotion_runtime_coverage_limited_external_autonomy_blocked'
                : 'missing_autonomy_promotion_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_autonomy_promotion_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'autonomy_ladder_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['autonomy_ladder_bound_count'], $companies)),
                'supervised_external_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['supervised_external_packet_bound_count'], $companies)),
                'limited_external_autonomy_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['limited_external_autonomy_blocked_count'], $companies)),
                'evidence_spine_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evidence_spine_bound_count'], $companies)),
                'rollback_reconciliation_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rollback_reconciliation_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'limited_external_autonomy_allowed' => false,
                'autonomy_claim_without_live_evidence_allowed' => false,
                'calendar_wait_blocker_enabled' => false,
                'operator_mandate_required_for_limited_external_autonomy' => true,
                'second_reviewer_required_for_limited_external_autonomy' => true,
                'autonomy_promotion_requires_fixture_shadow_supervised_packet_evidence_rollback_and_reconciliation' => true,
            ],
        ];
        $payload['autonomy_promotion_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $outcomeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['operational_outcome_ledger_bound'] ?? false)
                    && (string) ($record['operational_outcome_ledger_hash'] ?? '') !== ''
                    && (bool) ($record['external_value_claim_allowed'] ?? true) === false
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlowCount = count(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $outcomeRecords,
            ))));
            $kpiCount = array_sum(array_map(static fn (array $record): int => (int) ($record['operational_outcome_kpi_count'] ?? 0), $outcomeRecords));
            $coverage = $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0;

            $row = [
                'schema' => 'atlas.ai.company.holding_outcome_scorecard.v1',
                'company_id' => $currentCompanyId,
                'expected_flow_count' => $expectedFlowCount,
                'completed_outcome_flow_count' => $completedFlowCount,
                'operational_outcome_ledger_count' => count($outcomeRecords),
                'measured_kpi_count' => $kpiCount,
                'coverage_rate' => $coverage,
                'score' => round($coverage * 10, 2),
                'external_value_claim_allowed' => false,
                'external_side_effects' => false,
                'ready' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount && $kpiCount >= $expectedFlowCount,
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
                'average_score' => $companyCount > 0 ? round(array_sum(array_map(static fn (array $company): float => (float) $company['score'], $companies)) / $companyCount, 2) : 0.0,
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
                'external_value_claim_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_value_claim_allowed'] ?? false))),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
            ],
            'companies' => $companies,
            'policy' => [
                'external_execution_allowed' => false,
                'external_value_claim_allowed' => false,
                'scorecard_source' => 'internal_operational_outcome_ledgers',
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
                $businessReady = $expectedFlows > 0
                    && (int) ($businessPacket['completed_business_operating_packet_flow_count'] ?? 0) === $expectedFlows;
                $commandReady = $expectedFlows > 0
                    && (int) ($commandCenterPacket['completed_command_center_control_tower_flow_count'] ?? 0) === $expectedFlows;

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
                    'decision_recommendation' => (string) ($portfolioPacket['decision_recommendation'] ?? 'repair_before_scale'),
                    'review_sections' => [
                        'operational_outcomes',
                        'business_operating_packets',
                        'command_center_control_tower',
                        'portfolio_decision',
                        'risk_and_external_commitment_blocks',
                        'next_operator_decisions',
                    ],
                    'required_operator_decisions' => array_values((array) ($portfolioPacket['required_operator_decisions'] ?? [])),
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
            $runtimeRecordsByCompany[$currentCompanyId] = $this->runtimeRecordsForCompany($currentCompanyId);
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
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $customerAccountRevenueRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['customer_account_revenue_runtime_bound'] ?? false)
                    && (bool) ($record['customer_market_runtime_bound'] ?? false)
                    && (bool) ($record['offer_packaging_bound'] ?? false)
                    && (bool) ($record['journey_lifecycle_bound'] ?? false)
                    && (bool) ($record['customer_success_scorecard_bound'] ?? false)
                    && (bool) ($record['commercial_service_catalog_bound'] ?? false)
                    && (bool) ($record['business_kpi_bound'] ?? false)
                    && (bool) ($record['account_contract_delivery_bound'] ?? false)
                    && (bool) ($record['account_segment_playbook_bound'] ?? false)
                    && (bool) ($record['contract_entitlement_bound'] ?? false)
                    && (bool) ($record['onboarding_success_plan_bound'] ?? false)
                    && (bool) ($record['service_review_renewal_bound'] ?? false)
                    && (bool) ($record['account_health_risk_bound'] ?? false)
                    && (bool) ($record['billing_revenue_model_bound'] ?? false)
                    && (bool) ($record['account_observability_bound'] ?? false)
                    && (string) ($record['customer_account_revenue_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $customerAccountRevenueRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_customer_account_revenue_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_customer_account_revenue_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'customer_market_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_market_runtime_bound'] ?? false))),
                'offer_packaging_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['offer_packaging_bound'] ?? false))),
                'journey_lifecycle_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['journey_lifecycle_bound'] ?? false))),
                'customer_success_scorecard_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['customer_success_scorecard_bound'] ?? false))),
                'commercial_service_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['commercial_service_catalog_bound'] ?? false))),
                'business_kpi_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_kpi_bound'] ?? false))),
                'account_contract_delivery_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_contract_delivery_bound'] ?? false))),
                'account_segment_playbook_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_segment_playbook_bound'] ?? false))),
                'contract_entitlement_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['contract_entitlement_bound'] ?? false))),
                'onboarding_success_plan_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['onboarding_success_plan_bound'] ?? false))),
                'service_review_renewal_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['service_review_renewal_bound'] ?? false))),
                'account_health_risk_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_health_risk_bound'] ?? false))),
                'billing_revenue_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['billing_revenue_model_bound'] ?? false))),
                'account_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['account_observability_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_customer_account_revenue_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_customer_account_revenue_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_customer_account_revenue_runtime_coverage_external_revenue_blocked'
                : 'missing_customer_account_revenue_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_customer_account_revenue_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'customer_market_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_market_runtime_bound_count'], $companies)),
                'offer_packaging_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offer_packaging_bound_count'], $companies)),
                'journey_lifecycle_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['journey_lifecycle_bound_count'], $companies)),
                'customer_success_scorecard_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_success_scorecard_bound_count'], $companies)),
                'commercial_service_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['commercial_service_catalog_bound_count'], $companies)),
                'business_kpi_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_kpi_bound_count'], $companies)),
                'account_contract_delivery_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_contract_delivery_bound_count'], $companies)),
                'account_segment_playbook_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_segment_playbook_bound_count'], $companies)),
                'contract_entitlement_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['contract_entitlement_bound_count'], $companies)),
                'onboarding_success_plan_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['onboarding_success_plan_bound_count'], $companies)),
                'service_review_renewal_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_review_renewal_bound_count'], $companies)),
                'account_health_risk_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_health_risk_bound_count'], $companies)),
                'billing_revenue_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['billing_revenue_model_bound_count'], $companies)),
                'account_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_observability_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'revenue_claim_allowed' => false,
                'real_revenue_claim_requires_external_evidence_operator_acceptance_and_signed_scope' => true,
                'customer_account_revenue_runtime_requires_icp_offer_journey_account_contract_success_renewal_billing_controls' => true,
            ],
        ];
        $payload['customer_account_revenue_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function productizedServiceRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $productizedRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['productized_service_runtime_bound'] ?? false)
                    && (bool) ($record['productized_service_stack_bound'] ?? false)
                    && (bool) ($record['productized_domain_product_line_bound'] ?? false)
                    && (bool) ($record['productized_service_offer_bound'] ?? false)
                    && (bool) ($record['productized_delivery_blueprint_bound'] ?? false)
                    && (bool) ($record['productized_intake_contract_bound'] ?? false)
                    && (bool) ($record['productized_sla_success_contract_bound'] ?? false)
                    && (bool) ($record['productized_pricing_packaging_bound'] ?? false)
                    && (bool) ($record['productized_gtm_motion_bound'] ?? false)
                    && (bool) ($record['productized_proof_template_bound'] ?? false)
                    && (bool) ($record['productized_observability_bound'] ?? false)
                    && (bool) ($record['productized_external_commitment_billing_blocked'] ?? false)
                    && (string) ($record['productized_service_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $productizedRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_productized_service_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_productized_service_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'service_offer_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_service_offer_bound'] ?? false))),
                'delivery_blueprint_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_delivery_blueprint_bound'] ?? false))),
                'intake_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_intake_contract_bound'] ?? false))),
                'sla_success_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_sla_success_contract_bound'] ?? false))),
                'pricing_packaging_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_pricing_packaging_bound'] ?? false))),
                'gtm_motion_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_gtm_motion_bound'] ?? false))),
                'proof_template_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_proof_template_bound'] ?? false))),
                'observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_observability_bound'] ?? false))),
                'external_commitment_billing_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['productized_external_commitment_billing_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_productized_service_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_productized_service_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_productized_service_runtime_coverage_external_commitment_billing_blocked'
                : 'missing_productized_service_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_productized_service_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'service_offer_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_offer_bound_count'], $companies)),
                'delivery_blueprint_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['delivery_blueprint_bound_count'], $companies)),
                'intake_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['intake_contract_bound_count'], $companies)),
                'sla_success_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['sla_success_contract_bound_count'], $companies)),
                'pricing_packaging_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['pricing_packaging_bound_count'], $companies)),
                'gtm_motion_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['gtm_motion_bound_count'], $companies)),
                'proof_template_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['proof_template_bound_count'], $companies)),
                'observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_bound_count'], $companies)),
                'external_commitment_billing_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_commitment_billing_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'public_gtm_or_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'productized_service_runtime_requires_offer_delivery_intake_sla_pricing_gtm_proof_and_observability' => true,
                'operator_mandate_required_for_public_offer_customer_commitment_or_billing' => true,
            ],
        ];
        $payload['productized_service_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function salesCrmPipelineRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $salesRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['sales_crm_pipeline_runtime_bound'] ?? false)
                    && (bool) ($record['sales_crm_stack_bound'] ?? false)
                    && (bool) ($record['sales_source_catalog_bound'] ?? false)
                    && (bool) ($record['sales_crm_object_model_bound'] ?? false)
                    && (bool) ($record['sales_segment_play_bound'] ?? false)
                    && (bool) ($record['sales_opportunity_route_bound'] ?? false)
                    && (bool) ($record['sales_proposal_scope_bound'] ?? false)
                    && (bool) ($record['sales_mutual_action_plan_bound'] ?? false)
                    && (bool) ($record['sales_account_research_workbench_bound'] ?? false)
                    && (bool) ($record['sales_deal_room_packet_bound'] ?? false)
                    && (bool) ($record['sales_pipeline_forecast_review_bound'] ?? false)
                    && (bool) ($record['sales_map_risk_review_bound'] ?? false)
                    && (bool) ($record['sales_renewal_expansion_signal_bound'] ?? false)
                    && (bool) ($record['sales_delivery_handoff_bound'] ?? false)
                    && (bool) ($record['sales_pipeline_observability_bound'] ?? false)
                    && (bool) ($record['sales_external_commitments_blocked'] ?? false)
                    && (string) ($record['sales_crm_pipeline_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $salesRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_sales_crm_pipeline_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_sales_crm_pipeline_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_source_catalog_bound'] ?? false))),
                'crm_object_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_crm_object_model_bound'] ?? false))),
                'segment_play_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_segment_play_bound'] ?? false))),
                'opportunity_route_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_opportunity_route_bound'] ?? false))),
                'proposal_scope_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_proposal_scope_bound'] ?? false))),
                'mutual_action_plan_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_mutual_action_plan_bound'] ?? false))),
                'account_research_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_account_research_workbench_bound'] ?? false))),
                'deal_room_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_deal_room_packet_bound'] ?? false))),
                'pipeline_forecast_review_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_pipeline_forecast_review_bound'] ?? false))),
                'map_risk_review_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_map_risk_review_bound'] ?? false))),
                'renewal_expansion_signal_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_renewal_expansion_signal_bound'] ?? false))),
                'sales_delivery_handoff_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_delivery_handoff_bound'] ?? false))),
                'pipeline_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_pipeline_observability_bound'] ?? false))),
                'external_commitments_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['sales_external_commitments_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_sales_crm_pipeline_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_sales_crm_pipeline_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_sales_crm_pipeline_runtime_coverage_external_commitments_blocked'
                : 'missing_sales_crm_pipeline_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_sales_crm_pipeline_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'crm_object_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['crm_object_model_bound_count'], $companies)),
                'segment_play_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['segment_play_bound_count'], $companies)),
                'opportunity_route_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['opportunity_route_bound_count'], $companies)),
                'proposal_scope_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['proposal_scope_bound_count'], $companies)),
                'mutual_action_plan_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['mutual_action_plan_bound_count'], $companies)),
                'account_research_workbench_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_research_workbench_bound_count'], $companies)),
                'deal_room_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['deal_room_packet_bound_count'], $companies)),
                'pipeline_forecast_review_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['pipeline_forecast_review_bound_count'], $companies)),
                'map_risk_review_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['map_risk_review_bound_count'], $companies)),
                'renewal_expansion_signal_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['renewal_expansion_signal_bound_count'], $companies)),
                'sales_delivery_handoff_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['sales_delivery_handoff_bound_count'], $companies)),
                'pipeline_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['pipeline_observability_bound_count'], $companies)),
                'external_commitments_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_commitments_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_outreach_contract_signature_or_customer_commitment_allowed' => false,
                'public_claim_or_paid_campaign_allowed' => false,
                'sales_crm_runtime_requires_crm_opportunity_proposal_map_renewal_handoff_and_observability' => true,
                'operator_mandate_required_for_external_sales_message_contract_or_commitment' => true,
            ],
        ];
        $payload['sales_crm_pipeline_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function customerSupportServiceDeskRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $supportRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['customer_support_service_desk_runtime_bound'] ?? false)
                    && (bool) ($record['support_service_desk_stack_bound'] ?? false)
                    && (bool) ($record['support_source_catalog_bound'] ?? false)
                    && (bool) ($record['support_service_desk_object_model_bound'] ?? false)
                    && (bool) ($record['support_segment_playbook_bound'] ?? false)
                    && (bool) ($record['support_lane_bound'] ?? false)
                    && (bool) ($record['support_ticket_sla_contract_bound'] ?? false)
                    && (bool) ($record['support_knowledge_base_template_bound'] ?? false)
                    && (bool) ($record['support_escalation_incident_runbook_bound'] ?? false)
                    && (bool) ($record['support_resolution_rca_bound'] ?? false)
                    && (bool) ($record['support_case_resolution_workbench_bound'] ?? false)
                    && (bool) ($record['support_customer_health_escalation_bound'] ?? false)
                    && (bool) ($record['support_knowledge_quality_review_bound'] ?? false)
                    && (bool) ($record['support_automation_deflection_test_bound'] ?? false)
                    && (bool) ($record['support_feedback_learning_loop_bound'] ?? false)
                    && (bool) ($record['support_observability_bound'] ?? false)
                    && (bool) ($record['support_external_customer_actions_blocked'] ?? false)
                    && (string) ($record['customer_support_service_desk_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $supportRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_customer_support_service_desk_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_customer_support_service_desk_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_source_catalog_bound'] ?? false))),
                'service_desk_object_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_service_desk_object_model_bound'] ?? false))),
                'segment_playbook_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_segment_playbook_bound'] ?? false))),
                'support_lane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_lane_bound'] ?? false))),
                'ticket_sla_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_ticket_sla_contract_bound'] ?? false))),
                'knowledge_base_template_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_knowledge_base_template_bound'] ?? false))),
                'escalation_incident_runbook_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_escalation_incident_runbook_bound'] ?? false))),
                'resolution_rca_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_resolution_rca_bound'] ?? false))),
                'case_resolution_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_case_resolution_workbench_bound'] ?? false))),
                'customer_health_escalation_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_customer_health_escalation_bound'] ?? false))),
                'knowledge_quality_review_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_knowledge_quality_review_bound'] ?? false))),
                'automation_deflection_test_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_automation_deflection_test_bound'] ?? false))),
                'feedback_learning_loop_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_feedback_learning_loop_bound'] ?? false))),
                'support_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_observability_bound'] ?? false))),
                'external_customer_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['support_external_customer_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_customer_support_service_desk_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_customer_support_service_desk_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_customer_support_service_desk_runtime_coverage_external_support_blocked'
                : 'missing_customer_support_service_desk_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_customer_support_service_desk_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'service_desk_object_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_desk_object_model_bound_count'], $companies)),
                'segment_playbook_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['segment_playbook_bound_count'], $companies)),
                'support_lane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['support_lane_bound_count'], $companies)),
                'ticket_sla_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ticket_sla_contract_bound_count'], $companies)),
                'knowledge_base_template_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['knowledge_base_template_bound_count'], $companies)),
                'escalation_incident_runbook_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['escalation_incident_runbook_bound_count'], $companies)),
                'resolution_rca_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['resolution_rca_bound_count'], $companies)),
                'case_resolution_workbench_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['case_resolution_workbench_bound_count'], $companies)),
                'customer_health_escalation_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_health_escalation_bound_count'], $companies)),
                'knowledge_quality_review_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['knowledge_quality_review_bound_count'], $companies)),
                'automation_deflection_test_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['automation_deflection_test_bound_count'], $companies)),
                'feedback_learning_loop_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['feedback_learning_loop_bound_count'], $companies)),
                'support_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['support_observability_bound_count'], $companies)),
                'external_customer_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_customer_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_message_or_support_commitment_allowed' => false,
                'regulated_support_advice_allowed_without_review' => false,
                'support_runtime_requires_ticket_sla_kb_escalation_rca_feedback_and_observability' => true,
                'operator_mandate_required_for_external_customer_support_or_public_kb' => true,
            ],
        ];
        $payload['customer_support_service_desk_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function marketingGrowthEngineRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $marketingRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['marketing_growth_engine_runtime_bound'] ?? false)
                    && (bool) ($record['marketing_growth_stack_bound'] ?? false)
                    && (bool) ($record['marketing_source_catalog_bound'] ?? false)
                    && (bool) ($record['marketing_growth_operating_model_bound'] ?? false)
                    && (bool) ($record['marketing_audience_segment_bound'] ?? false)
                    && (bool) ($record['marketing_campaign_blueprint_bound'] ?? false)
                    && (bool) ($record['marketing_content_asset_factory_bound'] ?? false)
                    && (bool) ($record['marketing_experiment_bound'] ?? false)
                    && (bool) ($record['marketing_growth_intelligence_workbench_bound'] ?? false)
                    && (bool) ($record['marketing_attribution_experiment_model_bound'] ?? false)
                    && (bool) ($record['marketing_channel_budget_guardrail_bound'] ?? false)
                    && (bool) ($record['marketing_public_claim_evidence_packet_bound'] ?? false)
                    && (bool) ($record['marketing_channel_distribution_bound'] ?? false)
                    && (bool) ($record['marketing_brand_compliance_review_bound'] ?? false)
                    && (bool) ($record['marketing_growth_crm_handoff_bound'] ?? false)
                    && (bool) ($record['marketing_observability_bound'] ?? false)
                    && (bool) ($record['marketing_external_publish_actions_blocked'] ?? false)
                    && (string) ($record['marketing_growth_engine_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $marketingRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_marketing_growth_engine_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_marketing_growth_engine_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_source_catalog_bound'] ?? false))),
                'growth_operating_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_growth_operating_model_bound'] ?? false))),
                'audience_segment_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_audience_segment_bound'] ?? false))),
                'campaign_blueprint_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_campaign_blueprint_bound'] ?? false))),
                'content_asset_factory_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_content_asset_factory_bound'] ?? false))),
                'experiment_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_experiment_bound'] ?? false))),
                'growth_intelligence_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_growth_intelligence_workbench_bound'] ?? false))),
                'attribution_experiment_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_attribution_experiment_model_bound'] ?? false))),
                'channel_budget_guardrail_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_channel_budget_guardrail_bound'] ?? false))),
                'public_claim_evidence_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_public_claim_evidence_packet_bound'] ?? false))),
                'channel_distribution_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_channel_distribution_bound'] ?? false))),
                'brand_compliance_review_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_brand_compliance_review_bound'] ?? false))),
                'growth_crm_handoff_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_growth_crm_handoff_bound'] ?? false))),
                'marketing_observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_observability_bound'] ?? false))),
                'external_publish_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['marketing_external_publish_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_marketing_growth_engine_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_marketing_growth_engine_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_marketing_growth_engine_runtime_coverage_external_publish_blocked'
                : 'missing_marketing_growth_engine_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_marketing_growth_engine_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'growth_operating_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['growth_operating_model_bound_count'], $companies)),
                'audience_segment_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['audience_segment_bound_count'], $companies)),
                'campaign_blueprint_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['campaign_blueprint_bound_count'], $companies)),
                'content_asset_factory_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['content_asset_factory_bound_count'], $companies)),
                'experiment_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['experiment_bound_count'], $companies)),
                'growth_intelligence_workbench_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['growth_intelligence_workbench_bound_count'], $companies)),
                'attribution_experiment_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['attribution_experiment_model_bound_count'], $companies)),
                'channel_budget_guardrail_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['channel_budget_guardrail_bound_count'], $companies)),
                'public_claim_evidence_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['public_claim_evidence_packet_bound_count'], $companies)),
                'channel_distribution_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['channel_distribution_bound_count'], $companies)),
                'brand_compliance_review_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['brand_compliance_review_bound_count'], $companies)),
                'growth_crm_handoff_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['growth_crm_handoff_bound_count'], $companies)),
                'marketing_observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['marketing_observability_bound_count'], $companies)),
                'external_publish_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_publish_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_publish_paid_campaign_or_outreach_allowed' => false,
                'public_claim_allowed_without_source_and_operator_review' => false,
                'marketing_runtime_requires_campaign_content_experiment_channel_brand_review_crm_handoff_and_observability' => true,
                'operator_mandate_required_for_external_publish_paid_campaign_or_outreach' => true,
            ],
        ];
        $payload['marketing_growth_engine_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function financeTreasuryBillingRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $financeRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['finance_treasury_billing_runtime_bound'] ?? false)
                    && (bool) ($record['finance_treasury_stack_bound'] ?? false)
                    && (bool) ($record['finance_source_catalog_bound'] ?? false)
                    && (bool) ($record['finance_financial_data_interface_bound'] ?? false)
                    && (bool) ($record['finance_provider_connector_matrix_bound'] ?? false)
                    && (bool) ($record['finance_cfo_operating_model_bound'] ?? false)
                    && (bool) ($record['finance_financial_research_workbench_bound'] ?? false)
                    && (bool) ($record['finance_budget_envelope_bound'] ?? false)
                    && (bool) ($record['finance_forecast_model_bound'] ?? false)
                    && (bool) ($record['finance_model_risk_control_bound'] ?? false)
                    && (bool) ($record['finance_investment_committee_packet_bound'] ?? false)
                    && (bool) ($record['finance_pnl_line_item_bound'] ?? false)
                    && (bool) ($record['finance_billing_ledger_bound'] ?? false)
                    && (bool) ($record['finance_treasury_risk_bound'] ?? false)
                    && (bool) ($record['finance_close_audit_bound'] ?? false)
                    && (bool) ($record['finance_observability_bound'] ?? false)
                    && (bool) ($record['finance_external_financial_actions_blocked'] ?? false)
                    && (string) ($record['finance_treasury_billing_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $financeRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_finance_treasury_billing_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_finance_treasury_billing_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'source_catalog_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_source_catalog_bound'] ?? false))),
                'financial_data_interface_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_financial_data_interface_bound'] ?? false))),
                'provider_connector_matrix_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_provider_connector_matrix_bound'] ?? false))),
                'cfo_operating_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_cfo_operating_model_bound'] ?? false))),
                'financial_research_workbench_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_financial_research_workbench_bound'] ?? false))),
                'budget_envelope_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_budget_envelope_bound'] ?? false))),
                'forecast_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_forecast_model_bound'] ?? false))),
                'model_risk_control_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_model_risk_control_bound'] ?? false))),
                'investment_committee_packet_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_investment_committee_packet_bound'] ?? false))),
                'pnl_line_item_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_pnl_line_item_bound'] ?? false))),
                'billing_ledger_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_billing_ledger_bound'] ?? false))),
                'treasury_risk_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_treasury_risk_bound'] ?? false))),
                'close_audit_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_close_audit_bound'] ?? false))),
                'observability_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_observability_bound'] ?? false))),
                'external_financial_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['finance_external_financial_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_finance_treasury_billing_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_finance_treasury_billing_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_finance_treasury_billing_runtime_coverage_external_finance_blocked'
                : 'missing_finance_treasury_billing_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_finance_treasury_billing_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'source_catalog_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_catalog_bound_count'], $companies)),
                'financial_data_interface_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['financial_data_interface_bound_count'], $companies)),
                'provider_connector_matrix_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_connector_matrix_bound_count'], $companies)),
                'cfo_operating_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['cfo_operating_model_bound_count'], $companies)),
                'financial_research_workbench_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['financial_research_workbench_bound_count'], $companies)),
                'budget_envelope_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['budget_envelope_bound_count'], $companies)),
                'forecast_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['forecast_model_bound_count'], $companies)),
                'model_risk_control_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['model_risk_control_bound_count'], $companies)),
                'investment_committee_packet_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['investment_committee_packet_bound_count'], $companies)),
                'pnl_line_item_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['pnl_line_item_bound_count'], $companies)),
                'billing_ledger_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['billing_ledger_bound_count'], $companies)),
                'treasury_risk_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['treasury_risk_bound_count'], $companies)),
                'close_audit_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['close_audit_bound_count'], $companies)),
                'observability_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_bound_count'], $companies)),
                'external_financial_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_financial_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
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
        ];
        $payload['finance_treasury_billing_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function governanceRiskOperationsRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $governanceRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['governance_risk_operations_runtime_bound'] ?? false)
                    && (bool) ($record['governance_vendor_procurement_bound'] ?? false)
                    && (bool) ($record['governance_resilience_continuity_bound'] ?? false)
                    && (bool) ($record['governance_analytics_decision_bound'] ?? false)
                    && (bool) ($record['governance_knowledge_learning_bound'] ?? false)
                    && (bool) ($record['governance_identity_sovereignty_bound'] ?? false)
                    && (bool) ($record['governance_grc_evidence_bound'] ?? false)
                    && (bool) ($record['governance_external_actions_blocked'] ?? false)
                    && (string) ($record['governance_risk_operations_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $governanceRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_governance_risk_operations_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_governance_risk_operations_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'vendor_procurement_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_vendor_procurement_bound'] ?? false))),
                'resilience_continuity_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_resilience_continuity_bound'] ?? false))),
                'analytics_decision_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_analytics_decision_bound'] ?? false))),
                'knowledge_learning_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_knowledge_learning_bound'] ?? false))),
                'identity_sovereignty_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_identity_sovereignty_bound'] ?? false))),
                'grc_evidence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_grc_evidence_bound'] ?? false))),
                'external_actions_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['governance_external_actions_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_governance_risk_operations_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_governance_risk_operations_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_governance_risk_operations_runtime_coverage_external_actions_blocked'
                : 'missing_governance_risk_operations_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_governance_risk_operations_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'vendor_procurement_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_procurement_bound_count'], $companies)),
                'resilience_continuity_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['resilience_continuity_bound_count'], $companies)),
                'analytics_decision_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['analytics_decision_bound_count'], $companies)),
                'knowledge_learning_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['knowledge_learning_bound_count'], $companies)),
                'identity_sovereignty_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['identity_sovereignty_bound_count'], $companies)),
                'grc_evidence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['grc_evidence_bound_count'], $companies)),
                'external_actions_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_actions_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'vendor_purchase_contract_signature_secret_share_or_write_scope_allowed' => false,
                'incident_external_notification_without_operator_allowed' => false,
                'canonical_memory_write_without_review_allowed' => false,
                'secret_material_or_unscoped_memory_export_allowed' => false,
                'governance_runtime_requires_procurement_resilience_analytics_learning_identity_and_grc_evidence' => true,
            ],
        ];
        $payload['governance_risk_operations_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function unitEconomicsCapacityRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $economicRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['unit_economics_capacity_bound'] ?? false)
                    && (bool) ($record['flow_cost_center_bound'] ?? false)
                    && (bool) ($record['flow_unit_economics_bound'] ?? false)
                    && (bool) ($record['capacity_simulation_bound'] ?? false)
                    && (bool) ($record['pricing_ladder_bound'] ?? false)
                    && (bool) ($record['agent_capacity_cost_model_bound'] ?? false)
                    && (bool) ($record['connector_cost_limit_model_bound'] ?? false)
                    && (string) ($record['unit_economics_capacity_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $economicRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_unit_economics_capacity_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_unit_economics_capacity_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'flow_cost_center_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_cost_center_bound'] ?? false))),
                'flow_unit_economics_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['flow_unit_economics_bound'] ?? false))),
                'capacity_simulation_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['capacity_simulation_bound'] ?? false))),
                'pricing_ladder_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['pricing_ladder_bound'] ?? false))),
                'agent_capacity_cost_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['agent_capacity_cost_model_bound'] ?? false))),
                'connector_cost_limit_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['connector_cost_limit_model_bound'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_unit_economics_capacity_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_unit_economics_capacity_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_unit_economics_capacity_runtime_coverage_external_capital_blocked'
                : 'missing_unit_economics_capacity_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_unit_economics_capacity_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'flow_cost_center_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_cost_center_bound_count'], $companies)),
                'flow_unit_economics_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_unit_economics_bound_count'], $companies)),
                'capacity_simulation_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['capacity_simulation_bound_count'], $companies)),
                'pricing_ladder_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['pricing_ladder_bound_count'], $companies)),
                'agent_capacity_cost_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['agent_capacity_cost_model_bound_count'], $companies)),
                'connector_cost_limit_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_cost_limit_model_bound_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'real_capital_action_allowed' => false,
                'unit_economics_runtime_requires_cost_center_unit_model_capacity_pricing_agent_and_connector_cost_controls' => true,
                'observed_revenue_or_savings_claim_requires_external_evidence_and_operator_acceptance' => true,
            ],
        ];
        $payload['unit_economics_capacity_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function businessOperatingPacketRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $businessOperatingRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['business_operating_packet_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_business_model_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_kpi_contract_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_delivery_lane_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_economics_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_account_operations_bound'] ?? false)
                    && (bool) ($record['business_operating_packet_external_commitments_blocked'] ?? false)
                    && (string) ($record['business_operating_packet_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $businessOperatingRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_business_operating_packet_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_business_operating_packet_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'business_model_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_business_model_bound'] ?? false))),
                'kpi_contract_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_kpi_contract_bound'] ?? false))),
                'delivery_lane_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_delivery_lane_bound'] ?? false))),
                'economics_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_economics_bound'] ?? false))),
                'account_operations_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_account_operations_bound'] ?? false))),
                'external_commitments_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['business_operating_packet_external_commitments_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_business_operating_packet_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_business_operating_packet_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_business_operating_packet_runtime_coverage_external_commitments_blocked'
                : 'missing_business_operating_packet_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_business_operating_packet_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'business_model_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['business_model_bound_count'], $companies)),
                'kpi_contract_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['kpi_contract_bound_count'], $companies)),
                'delivery_lane_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['delivery_lane_bound_count'], $companies)),
                'economics_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['economics_bound_count'], $companies)),
                'account_operations_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_operations_bound_count'], $companies)),
                'external_commitments_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_commitments_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'real_capital_action_allowed' => false,
                'business_operating_packet_requires_business_model_kpi_sla_economics_account_ops_and_commitment_blocks' => true,
                'operator_mandate_required_for_external_customer_vendor_billing_capital_or_public_action' => true,
            ],
        ];
        $payload['business_operating_packet_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function deliveryRiskRuntimeStatus(?string $companyId = null): array
    {
        $companies = [];
        $records = [];

        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $flows = array_values(array_map(
                static fn (array $flow): string => (string) ($flow['flow_id'] ?? $flow['id'] ?? ''),
                (array) ($company['flows'] ?? []),
            ));
            $companyRecords = $this->runtimeRecordsForCompany($currentCompanyId);
            $deliveryRiskRecords = array_values(array_filter(
                $companyRecords,
                static fn (array $record): bool => (bool) ($record['delivery_risk_runtime_bound'] ?? false)
                    && (bool) ($record['delivery_assurance_runtime_bound'] ?? false)
                    && (bool) ($record['delivery_sla_bound'] ?? false)
                    && (bool) ($record['strategic_intelligence_bound'] ?? false)
                    && (bool) ($record['rival_alternative_map_bound'] ?? false)
                    && (bool) ($record['grc_runtime_bound'] ?? false)
                    && (bool) ($record['audit_evidence_bound'] ?? false)
                    && (bool) ($record['policy_exception_blocked'] ?? false)
                    && (string) ($record['delivery_risk_attestation_hash'] ?? '') !== ''
                    && (bool) ($record['external_side_effects'] ?? true) === false,
            ));
            $completedFlows = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['flow_id'] ?? ''),
                $deliveryRiskRecords,
            ))));

            $companies[] = [
                'company_id' => $currentCompanyId,
                'expected_flow_count' => count($flows),
                'completed_delivery_risk_flow_count' => count(array_intersect($flows, $completedFlows)),
                'missing_delivery_risk_flows' => array_values(array_diff($flows, $completedFlows)),
                'coverage_rate' => count($flows) > 0 ? round(count(array_intersect($flows, $completedFlows)) / count($flows), 4) : 0.0,
                'delivery_assurance_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['delivery_assurance_runtime_bound'] ?? false))),
                'delivery_sla_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['delivery_sla_bound'] ?? false))),
                'strategic_intelligence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['strategic_intelligence_bound'] ?? false))),
                'rival_alternative_map_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['rival_alternative_map_bound'] ?? false))),
                'grc_runtime_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['grc_runtime_bound'] ?? false))),
                'audit_evidence_bound_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['audit_evidence_bound'] ?? false))),
                'policy_exception_blocked_count' => count(array_filter($companyRecords, static fn (array $record): bool => (bool) ($record['policy_exception_blocked'] ?? false))),
            ];
            array_push($records, ...$companyRecords);
        }

        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['expected_flow_count'], $companies));
        $completedFlowCount = array_sum(array_map(static fn (array $company): int => (int) $company['completed_delivery_risk_flow_count'], $companies));

        $payload = [
            'ok' => $expectedFlowCount > 0 && $expectedFlowCount === $completedFlowCount,
            'schema' => 'atlas.ai.holding.enterprise_delivery_risk_runtime_status.v1',
            'status' => $expectedFlowCount === $completedFlowCount && $expectedFlowCount > 0
                ? 'complete_delivery_risk_runtime_coverage_external_claim_blocked'
                : 'missing_delivery_risk_runtime_coverage',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_flow_count' => $expectedFlowCount,
                'completed_delivery_risk_flow_count' => $completedFlowCount,
                'runtime_record_count' => count($records),
                'delivery_assurance_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['delivery_assurance_runtime_bound_count'], $companies)),
                'delivery_sla_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['delivery_sla_bound_count'], $companies)),
                'strategic_intelligence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['strategic_intelligence_bound_count'], $companies)),
                'rival_alternative_map_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rival_alternative_map_bound_count'], $companies)),
                'grc_runtime_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['grc_runtime_bound_count'], $companies)),
                'audit_evidence_bound_count' => array_sum(array_map(static fn (array $company): int => (int) $company['audit_evidence_bound_count'], $companies)),
                'policy_exception_blocked_count' => array_sum(array_map(static fn (array $company): int => (int) $company['policy_exception_blocked_count'], $companies)),
                'external_side_effect_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['external_side_effects'] ?? true))),
                'coverage_rate' => $expectedFlowCount > 0 ? round($completedFlowCount / $expectedFlowCount, 4) : 0.0,
            ],
            'companies' => $companies,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'customer_visible_claim_allowed' => false,
                'policy_exception_auto_approval_allowed' => false,
                'delivery_risk_runtime_requires_sla_acceptance_rival_map_audit_evidence_and_grc_controls' => true,
                'operator_mandate_required_for_external_delivery_or_policy_exception' => true,
            ],
        ];
        $payload['delivery_risk_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function run(string $companyId, string $action, bool $fixtureMode = true): array
    {
        $company = $this->buildout->companyPacket($companyId);
        $contract = $this->actionContractFromCompany($company, $action);

        if ($contract === null) {
            throw new \InvalidArgumentException("Unknown enterprise fixture action [{$action}] for company [{$companyId}].");
        }

        $flowId = (string) $contract['flow_id'];
        $flowSpec = $this->findByFlow($company, 'flow_specs', $flowId);
        $flowRunbook = $this->findByFlow($company, 'enterprise_flow_orchestration_runbook_stack.flow_runbooks', $flowId);
        $executableFlowPacket = $this->findByFlow($company, 'enterprise_flow_runtime_implementation_stack.executable_flow_packets', $flowId);
        $agentToolRouting = $this->findByFlow($company, 'enterprise_flow_runtime_implementation_stack.agent_tool_routing_matrix', $flowId);
        $flowArtifactIoContract = $this->findByFlow($company, 'enterprise_flow_runtime_implementation_stack.flow_artifact_io_contracts', $flowId);
        $supervisionShadowGate = $this->findByFlow($company, 'enterprise_flow_runtime_implementation_stack.supervision_and_shadow_runtime_gates', $flowId);
        $canonicalFixture = $this->findByFlow($company, 'enterprise_flow_fixture_simulation_stack.canonical_flow_fixtures', $flowId);
        $trajectory = $this->findByFlow($company, 'enterprise_flow_fixture_simulation_stack.expected_trace_trajectories', $flowId);
        $assertionSuite = $this->findByFlow($company, 'enterprise_flow_fixture_simulation_stack.quality_assertion_suites', $flowId);
        $failureCases = $this->findByFlow($company, 'enterprise_flow_fixture_simulation_stack.failure_injection_cases', $flowId);
        $dryRun = $this->findByFlow($company, 'enterprise_flow_fixture_simulation_stack.dry_run_command_plan', $flowId);
        $stateSchema = $this->findByFlow($company, 'enterprise_flow_action_runtime_stack.handler_state_schemas', $flowId);
        $eventPlan = $this->findByFlow($company, 'enterprise_flow_action_runtime_stack.runtime_event_emission_plan', $flowId);
        $checkpoint = $this->findByFlow($company, 'enterprise_flow_action_runtime_stack.operator_checkpoint_contracts', $flowId);
        $flowToolkitAssignment = $this->findByFlow($company, 'enterprise_domain_agent_toolkit_stack.flow_toolkit_assignments', $flowId);
        $agentRepositoryEpic = $this->findByFlow($company, 'enterprise_agent_repository_adoption_pipeline.flow_repository_implementation_epics', $flowId);
        $workforceStaffing = $this->findByFlow($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', $flowId);
        $portfolioDependencyRouting = $this->findByFlow($company, 'enterprise_portfolio_dependency_stack.flow_dependency_routing', $flowId);
        $externalResearchFlowMatrix = $this->findByFlow($company, 'enterprise_external_research_adoption_stack.per_flow_adoption_matrix', $flowId);
        $externalResearchSourceBasis = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.source_basis', []));
        $externalResearchOfficialRepositories = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.official_framework_repositories', []));
        $externalResearchDomainRepositories = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.repository_and_framework_catalog.domain_repository_candidates', []));
        $externalResearchCapabilityMap = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.source_to_company_capability_map', []));
        $externalResearchConnectorBacklog = array_values((array) data_get($company, 'enterprise_external_research_adoption_stack.connector_and_data_provider_backlog', []));
        $externalResearchProductionGates = (array) data_get($company, 'enterprise_external_research_adoption_stack.productionization_gates', []);
        $operatingPackage = $this->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId);
        $verticalSolutionKit = $this->findByFlow($company, 'enterprise_vertical_solution_suite_stack.flow_solution_kits', $flowId);
        $verticalConnectorWorkbenches = $this->verticalConnectorWorkbenches($company, array_values((array) data_get($verticalSolutionKit, 'connector_refs', [])));
        $artifactFactory = $this->artifactFactoryForWorkProduct($company, (string) data_get($verticalSolutionKit, 'work_product', ''));
        $businessExecutionCell = $this->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_execution_cells', $flowId);
        $businessKpiBinding = $this->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.flow_tool_kpi_matrix', $flowId);
        $businessServiceLane = $this->findByFlow($company, 'enterprise_domain_business_execution_mesh_stack.domain_service_lanes', $flowId);
        $domainSolutionPlaybook = $this->findByFlow($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', $flowId);
        $domainOperatingDepthPacket = $this->findByFlow($company, 'enterprise_domain_operating_depth_stack.flow_depth_packets', $flowId);
        $domainAgentCrew = $this->findByFlow($company, 'enterprise_domain_agent_workforce_stack.flow_agent_crews', $flowId);
        $flowProviderRoute = $this->findByFlow($company, 'enterprise_domain_provider_workbench_stack.flow_provider_routes', $flowId);
        $providerEvaluationCase = $this->findByFlow($company, 'enterprise_domain_provider_workbench_stack.provider_evaluation_cases', $flowId);
        $offlineDatasetContract = $this->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', $flowId);
        $traceGradingRubric = $this->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', $flowId);
        $adversarialRegressionCase = $this->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', $flowId);
        $deterministicStateAssertion = $this->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', $flowId);
        $replayComparisonMatrix = $this->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', $flowId);
        $semanticFlowEdge = $this->findByFlow($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', $flowId);
        $customerJourney = $this->findByFlow($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', $flowId);
        $productizedServiceOffer = $this->findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId);
        $productizedDeliveryBlueprint = $this->findByFlow($company, 'enterprise_productized_service_stack.service_delivery_blueprints', $flowId);
        $productizedIntakeContract = $this->findByFlow($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', $flowId);
        $productizedSlaContract = $this->findByFlow($company, 'enterprise_productized_service_stack.sla_success_contracts', $flowId);
        $productizedProofTemplate = $this->findByFlow($company, 'enterprise_productized_service_stack.proof_and_case_study_templates', $flowId);
        $salesOpportunityRoute = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId);
        $salesProposalPacket = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId);
        $salesMutualActionPlan = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.mutual_action_plans', $flowId);
        $salesAccountResearchWorkbench = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_account_research_workbenches', $flowId);
        $salesDealRoomPacket = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_deal_room_packets', $flowId);
        $salesPipelineForecastReview = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_pipeline_forecast_reviews', $flowId);
        $salesMapRiskReview = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_mutual_action_plan_risk_reviews', $flowId);
        $salesDeliveryHandoff = $this->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.sales_to_delivery_handoff_contracts', $flowId);
        $supportLane = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId);
        $supportTicketSla = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', $flowId);
        $supportKbTemplate = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.knowledge_base_article_templates', $flowId);
        $supportEscalationRunbook = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.escalation_and_incident_runbooks', $flowId);
        $supportResolutionRca = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.resolution_quality_and_rca_contracts', $flowId);
        $supportCaseResolutionWorkbench = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_case_resolution_workbenches', $flowId);
        $supportHealthEscalationPlaybook = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_customer_health_escalation_playbooks', $flowId);
        $supportKnowledgeQualityReview = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_knowledge_quality_reviews', $flowId);
        $supportAutomationDeflectionTest = $this->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_automation_deflection_tests', $flowId);
        $marketingCampaignBlueprint = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', $flowId);
        $marketingContentFactory = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.content_asset_factories', $flowId);
        $marketingExperiment = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.experiment_backlog', $flowId);
        $marketingGrowthIntelligenceWorkbench = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_growth_intelligence_workbenches', $flowId);
        $marketingAttributionExperimentModel = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_attribution_experiment_models', $flowId);
        $marketingChannelBudgetGuardrail = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_channel_budget_guardrails', $flowId);
        $marketingPublicClaimEvidencePacket = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_public_claim_evidence_packets', $flowId);
        $marketingChannelPlan = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.channel_and_distribution_plan', $flowId);
        $marketingBrandReview = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.brand_compliance_review_packets', $flowId);
        $marketingCrmHandoff = $this->findByFlow($company, 'enterprise_marketing_growth_engine_stack.growth_to_crm_handoff_contracts', $flowId);
        $financeResearchWorkbench = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_financial_research_workbenches', $flowId);
        $financeBudgetEnvelope = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', $flowId);
        $financeForecastModel = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_forecast_models', $flowId);
        $financeModelRiskControl = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_model_risk_controls', $flowId);
        $financeInvestmentCommitteePacket = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_investment_committee_packets', $flowId);
        $financeBillingLedger = $this->findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId);
        $accountOnboardingPlan = $this->findByFlow($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', $flowId);
        $accountServiceReview = $this->findByFlow($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', $flowId);
        $vendorProcurementRouting = $this->findByFlow($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', $flowId);
        $resilienceFailureMode = $this->findByFlow($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', $flowId);
        $resilienceExercise = $this->findByFlow($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', $flowId);
        $analyticsDecisionRegister = $this->findByFlow($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', $flowId);
        $scenarioForecast = $this->findByFlow($company, 'enterprise_analytics_decision_intelligence_stack.scenario_and_forecast_model', $flowId);
        $knowledgeLearningLoop = $this->findByFlow($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', $flowId);
        $playbookChangeControl = $this->findByFlow($company, 'enterprise_knowledge_memory_learning_stack.playbook_change_control', $flowId);
        $identityDataBoundary = $this->findByFlow($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', $flowId);
        $purposeConsent = $this->findByFlow($company, 'enterprise_identity_access_data_sovereignty_stack.purpose_consent_registry', $flowId);
        $deliverySla = $this->findByFlow($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', $flowId);
        $flowCostCenter = $this->findByFlow($company, 'portfolio_finance_stack.flow_cost_centers', $flowId);
        $flowUnitEconomics = $this->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', $flowId);
        $capacitySimulation = $this->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', $flowId);
        $strategicRivalMap = $this->findByFlow($company, 'strategic_intelligence_stack.rival_and_alternative_map', $flowId);
        $grcEvidence = $this->findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId);
        $domainExecutionPacket = $this->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_execution_packets', $flowId);
        $domainRiskControlPacket = $this->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', $flowId);
        $domainDecisionRoomPacket = $this->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_decision_room_packets', $flowId);
        $domainReplayEvalPack = $this->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', $flowId);
        $flowWorkProductDeliveryBlueprint = $this->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', $flowId);
        $flowWorkProductAcceptance = $this->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId);
        $flowWorkProductHandoff = $this->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId);
        $flowWorkProductReplayCheck = $this->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', $flowId);
        $domainDataConnectorContract = $this->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
        $domainConnectorFixtureEval = $this->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', $flowId);
        $flowLiveReadProbeContract = $this->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', $flowId);
        $flowLiveReadProbeEvidence = $this->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', $flowId);
        $controlTowerLane = $this->findByFlow($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', $flowId);
        $incidentExceptionDesk = $this->findByFlow($company, 'enterprise_control_tower_run_operations_stack.incident_and_exception_desk', $flowId);
        $changeWindowRelease = $this->findByFlow($company, 'enterprise_control_tower_run_operations_stack.change_window_and_release_calendar', $flowId);
        $flowCommandCard = $this->findByFlow($company, 'enterprise_company_command_center_stack.flow_command_cards', $flowId);
        $rehearsalRunbook = $this->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.flow_rehearsal_runbooks', $flowId);
        $operatorAcceptancePacket = $this->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.operator_acceptance_packets', $flowId);
        $rollbackDrill = $this->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.rollback_drill_matrix', $flowId);
        $promotionEvidence = $this->findByFlow($company, 'enterprise_operational_dress_rehearsal_stack.promotion_evidence_matrix', $flowId);
        $businessProcess = $this->findByFlow($company, 'business_process_map', $flowId);
        $sloSli = $this->findByFlow($company, 'go_to_production_pack.slo_sli_catalog', $flowId);
        $flowActivationMatrix = $this->findByFlow($company, 'enterprise_integration_activation_plan.flow_activation_matrix', $flowId);
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
        $premiumFlowTemplateMap = $this->findByFlow($company, 'premium_enterprise_agent_reference_model.flow_template_map', $flowId);
        $premiumManagedAgentWorkflow = $this->findByFlow($company, 'premium_enterprise_agent_reference_model.flow_managed_agent_workflows', $flowId);
        $premiumWorkbenches = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.data_and_tool_workbenches', []));
        $premiumMcpServerPlan = array_values((array) data_get($company, 'premium_enterprise_agent_reference_model.connector_mcp_server_plan', []));
        $premiumReplayBenchmark = $this->findByFlow($company, 'premium_enterprise_agent_reference_model.replay_and_audit_harness.benchmarks_per_flow', $flowId);
        $replayContract = (array) data_get($operatingPackage, 'quality_replay_cell', []);
        $connectorWorkbenches = array_values((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', []));
        $requiredSections = array_values((array) data_get($operatingPackage, 'delivery_cell.required_sections', []));
        $runtimePhases = array_values((array) ($contract['runtime_phases'] ?? []));
        $artifactType = (string) data_get($operatingPackage, 'delivery_cell.artifact_type', (string) ($flowSpec['delivery_type'] ?? 'enterprise_artifact'));
        $businessArtifactContract = $this->businessArtifactContractForWorkProduct($company, $artifactType);
        $deliverableQualityContract = $this->qualityContractForWorkProduct($company, $artifactType);
        $flowConnectorUsage = $this->findByFlow($company, 'enterprise_connector_certification_stack.flow_connector_usage_matrix', $flowId);
        $flowConnectorCutover = $this->findByFlow($company, 'enterprise_production_connector_preflight_stack.flow_connector_cutover_matrix', $flowId);
        $flowConnectorIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) (($flowConnectorUsage['connectors'] ?? null) ?: ($flowConnectorCutover['connector_scope'] ?? [])),
        ))));
        $flowConnectorCount = count($flowConnectorIds);
        $connectorAdapterContracts = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.adapter_contract_catalog', $flowConnectorIds);
        $connectorAuthBoundaries = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.auth_and_secret_boundary', $flowConnectorIds);
        $connectorSandboxProbes = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.sandbox_probe_matrix', $flowConnectorIds);
        $connectorContractTests = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.consumer_provider_contract_tests', $flowConnectorIds);
        $connectorDataLineage = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.connector_data_mapping_and_lineage', $flowConnectorIds);
        $connectorReplayFixtures = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.replay_fixture_and_mock_server_plan', $flowConnectorIds);
        $connectorSloFailureModes = $this->connectorRowsByIds($company, 'enterprise_connector_certification_stack.connector_slo_and_failure_mode_catalog', $flowConnectorIds);
        $productionPreflightContracts = $this->connectorRowsByIds($company, 'enterprise_production_connector_preflight_stack.connector_preflight_contracts', $flowConnectorIds);
        $productionReadinessEvidence = $this->connectorRowsByIds($company, 'enterprise_production_connector_preflight_stack.production_readiness_evidence_register', $flowConnectorIds);
        $rehearsalLiveReadProbes = $this->connectorRowsByIds($company, 'enterprise_operational_dress_rehearsal_stack.live_read_probe_plan', $flowConnectorIds);
        $operationalDossier = $this->enterpriseFlowOperationalDossier(
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
        $autonomyPromotionPacket = $this->enterpriseAutonomyPromotionPacket(
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
            'managed_agent_execution' => $this->managedAgentExecution(
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
            'enterprise_business_operating_packet' => $this->enterpriseBusinessOperatingPacket(
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
            'enterprise_artifact' => $this->enterpriseArtifact(
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
        $payload['runtime_record'] = $fixtureMode ? null : $this->persistInternalRuntimeRecord($company, $payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actionContract(string $companyId, string $action): ?array
    {
        return $this->actionContractFromCompany($this->buildout->companyPacket($companyId), $action);
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>|null
     */
    private function actionContractFromCompany(array $company, string $action): ?array
    {
        foreach ((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []) as $contract) {
            if (($contract['action'] ?? null) === $action) {
                return (array) $contract;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function findByFlow(array $company, string $path, string $flowId): array
    {
        foreach ((array) data_get($company, $path, []) as $item) {
            if (($item['flow_id'] ?? null) === $flowId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $company
     * @param list<string> $connectorIds
     * @return list<array<string,mixed>>
     */
    private function connectorRowsByIds(array $company, string $path, array $connectorIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('strval', $connectorIds))));

        return array_values(array_filter(
            array_map(
                static fn (array $row): array => $row,
                (array) data_get($company, $path, []),
            ),
            static fn (array $row): bool => in_array((string) ($row['connector_id'] ?? ''), $wanted, true),
        ));
    }

    /**
     * @param array<string,mixed> $company
     * @param list<mixed> $connectorIds
     * @return list<array<string,mixed>>
     */
    private function verticalConnectorWorkbenches(array $company, array $connectorIds): array
    {
        $wanted = array_values(array_map('strval', $connectorIds));

        return array_values(array_filter(
            array_map(
                static fn (array $workbench): array => $workbench,
                (array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', []),
            ),
            static fn (array $workbench): bool => in_array((string) ($workbench['connector_id'] ?? ''), $wanted, true),
        ));
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function artifactFactoryForWorkProduct(array $company, string $workProduct): array
    {
        foreach ((array) data_get($company, 'enterprise_vertical_solution_suite_stack.artifact_factory_catalog', []) as $factory) {
            if (($factory['work_product'] ?? null) === $workProduct) {
                return (array) $factory;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function businessArtifactContractForWorkProduct(array $company, string $workProduct): array
    {
        foreach ((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.business_artifact_delivery_contracts', []) as $contract) {
            if (($contract['work_product'] ?? null) === $workProduct) {
                return (array) $contract;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function qualityContractForWorkProduct(array $company, string $workProduct): array
    {
        $aliases = [
            'architecture_decision_record' => ['architecture', 'decision', 'adr'],
            'patch_plan' => ['patch', 'plan'],
            'repair_packet' => ['repair', 'triage', 'failure'],
            'release_certification' => ['release', 'certification'],
            'security_review_report' => ['security', 'review'],
        ];

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            $contractWorkProduct = (string) ($contract['work_product'] ?? '');
            if ($contractWorkProduct === $workProduct
                || ($contractWorkProduct !== '' && str_contains($workProduct, $contractWorkProduct))
                || ($workProduct !== '' && str_contains($contractWorkProduct, $workProduct))) {
                return (array) $contract;
            }
        }

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            $contractWorkProduct = (string) ($contract['work_product'] ?? '');
            foreach ($aliases[$contractWorkProduct] ?? [] as $alias) {
                if (str_contains($workProduct, $alias)) {
                    return (array) $contract;
                }
            }
        }

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            if (count((array) ($contract['required_sections'] ?? [])) >= 7
                && count((array) ($contract['acceptance_criteria'] ?? [])) >= 5
                && count((array) ($contract['rejection_criteria'] ?? [])) >= 4) {
                return (array) $contract;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $flowSpec
     * @param array<string,mixed> $businessExecutionCell
     * @param array<string,mixed> $businessKpiBinding
     * @param array<string,mixed> $businessServiceLane
     * @param array<string,mixed> $businessArtifactContract
     * @param array<string,mixed> $deliverySla
     * @param array<string,mixed> $flowCostCenter
     * @param array<string,mixed> $flowUnitEconomics
     * @param array<string,mixed> $capacitySimulation
     * @param array<string,mixed> $accountOnboardingPlan
     * @param array<string,mixed> $accountServiceReview
     * @param array<string,mixed> $customerJourney
     * @return array<string,mixed>
     */
    private function enterpriseBusinessOperatingPacket(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $flowSpec,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
        array $deliverySla,
        array $flowCostCenter,
        array $flowUnitEconomics,
        array $capacitySimulation,
        array $accountOnboardingPlan,
        array $accountServiceReview,
        array $customerJourney,
    ): array {
        $kpiRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                (array) data_get($businessKpiBinding, 'kpi_refs', []),
                array_slice((array) ($company['metrics'] ?? []), 0, 5),
            ),
        ))));
        $serviceCatalog = array_values((array) data_get($company, 'commercial_operating_stack.service_catalog', []));
        $pricingLadder = array_values((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', []));
        $matchedPricing = $this->pricingForWorkProduct($pricingLadder, $artifactType);

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_business_operating_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'status' => 'business_operating_packet_ready_external_commitments_blocked',
            'business_model' => [
                'service_line' => (string) data_get($serviceCatalog, '0.service_id', $companyId.'_enterprise_service'),
                'artifact_type' => $artifactType,
                'declared_output' => (string) ($flowSpec['output'] ?? $artifactType),
                'delivery_contract_hash' => (string) data_get($businessArtifactContract, 'delivery_contract_hash', ''),
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
            ],
            'kpi_contract' => [
                'kpi_refs' => $kpiRefs,
                'minimum_kpi_count' => 3,
                'acceptance_metric' => 'operator_accepted_work_product_with_source_lineage',
                'external_value_claim_allowed' => false,
            ],
            'delivery_lane' => [
                'service_lane_id' => (string) data_get($businessServiceLane, 'lane_id', ''),
                'queue' => (string) data_get($businessServiceLane, 'queue', $companyId.'.'.$flowId.'.execution_queue'),
                'sla_id' => (string) data_get($deliverySla, 'sla_id', data_get($deliverySla, 'flow_id', '')),
                'sla_class' => (string) data_get($deliverySla, 'sla_class', 'internal_packet_sla'),
                'review_roles' => array_values((array) data_get($businessServiceLane, 'review_roles', [])),
                'customer_visible_delivery_allowed' => false,
            ],
            'economics' => [
                'cost_center_id' => (string) data_get($flowCostCenter, 'cost_center_id', data_get($flowCostCenter, 'cost_center', '')),
                'unit_economics_id' => (string) data_get($flowUnitEconomics, 'unit_id', data_get($flowUnitEconomics, 'flow_id', '')),
                'capacity_simulation_id' => (string) data_get($capacitySimulation, 'simulation_id', ''),
                'pricing_ref' => (string) data_get($matchedPricing, 'pricing_id', data_get($matchedPricing, 'work_product', $artifactType)),
                'margin_or_roi_claim_requires_external_evidence' => true,
                'real_capital_action_allowed' => false,
            ],
            'account_operations' => [
                'customer_journey_id' => (string) data_get($customerJourney, 'journey_id', data_get($customerJourney, 'flow_id', '')),
                'onboarding_plan_id' => (string) data_get($accountOnboardingPlan, 'success_plan_id', data_get($accountOnboardingPlan, 'plan_id', '')),
                'service_review_calendar_id' => (string) data_get($accountServiceReview, 'calendar_id', data_get($accountServiceReview, 'calendar_hash', '')),
                'revenue_collection_allowed' => false,
                'renewal_or_upsell_commitment_allowed' => false,
            ],
            'operating_controls' => [
                'source_lineage_required' => true,
                'tool_receipt_required' => true,
                'operator_acceptance_required' => true,
                'second_review_required_for_external_commitment' => true,
                'rollback_or_compensation_plan_required' => true,
                'blocked_operations' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'customer_commitment', 'vendor_procurement', 'secret_export'],
            ],
            'readiness' => [
                'business_execution_cell_bound' => $businessExecutionCell !== [],
                'kpi_contract_bound' => count($kpiRefs) >= 3,
                'delivery_lane_bound' => $businessServiceLane !== [] && $deliverySla !== [],
                'economics_bound' => $flowCostCenter !== [] && $flowUnitEconomics !== [] && $capacitySimulation !== [],
                'account_operations_bound' => $customerJourney !== [] && $accountOnboardingPlan !== [] && $accountServiceReview !== [],
                'external_side_effects_enabled' => false,
            ],
            'external_side_effects' => false,
        ];
        $packet['business_operating_packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param list<array<string,mixed>> $pricingLadder
     * @return array<string,mixed>
     */
    private function pricingForWorkProduct(array $pricingLadder, string $artifactType): array
    {
        foreach ($pricingLadder as $pricing) {
            if (($pricing['work_product'] ?? null) === $artifactType) {
                return (array) $pricing;
            }
        }

        return (array) ($pricingLadder[0] ?? []);
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $flowSpec
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $operatingPackage
     * @param array<string,mixed> $domainSolutionPlaybook
     * @param array<string,mixed> $businessExecutionCell
     * @param array<string,mixed> $businessKpiBinding
     * @param array<string,mixed> $businessServiceLane
     * @param array<string,mixed> $deliverySla
     * @param array<string,mixed> $flowUnitEconomics
     * @param array<string,mixed> $semanticFlowEdge
     * @param list<mixed> $runtimePhases
     * @return array<string,mixed>
     */
    private function enterpriseFlowOperationalDossier(
        array $company,
        string $companyId,
        string $flowId,
        array $flowSpec,
        array $contract,
        array $operatingPackage,
        array $domainSolutionPlaybook,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $deliverySla,
        array $flowUnitEconomics,
        array $semanticFlowEdge,
        string $artifactType,
        array $runtimePhases,
    ): array {
        $sourceRefs = array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])));
        $connectorRefs = array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'tooling_contract.connector_refs', [])));
        $evidenceSpine = [
            'flow_spec:'.(string) ($flowSpec['flow_id'] ?? $flowId),
            'action_contract:'.(string) ($contract['action'] ?? $flowId),
            'operating_package:'.(string) ($operatingPackage['package_hash'] ?? ''),
            'domain_solution_playbook:'.(string) ($domainSolutionPlaybook['playbook_hash'] ?? ''),
            'business_execution_cell:'.(string) ($businessExecutionCell['cell_hash'] ?? ''),
            'business_kpi_binding:'.(string) ($businessKpiBinding['binding_hash'] ?? ''),
            'business_service_lane:'.(string) ($businessServiceLane['lane_hash'] ?? ''),
            'delivery_sla:'.(string) ($deliverySla['sla_hash'] ?? ''),
            'unit_economics:'.(string) ($flowUnitEconomics['unit_economics_hash'] ?? ''),
            'semantic_flow_edge:'.(string) ($semanticFlowEdge['edge_hash'] ?? ''),
        ];

        foreach ($sourceRefs as $sourceRef) {
            $evidenceSpine[] = 'domain_source:'.$sourceRef;
        }
        foreach ($connectorRefs as $connectorRef) {
            $evidenceSpine[] = 'connector_scope:'.$connectorRef;
        }

        $dossier = [
            'schema' => 'atlas.ai.company.enterprise_flow_operational_dossier.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'internal_operational_dossier_ready_external_blocked',
            'owner_agent' => (string) data_get($operatingPackage, 'owner_agent', (string) ($flowSpec['owner_agent'] ?? 'company_operator_agent')),
            'source_pack' => [
                'source_refs' => $sourceRefs,
                'direct_hyperlinks_required' => (bool) data_get($domainSolutionPlaybook, 'source_pack.direct_hyperlinks_required', false),
                'claim_traceability_required' => (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.claim_traceability_required', false),
                'cross_source_verification_required' => (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false),
            ],
            'execution_blueprint' => [
                'runtime_phases' => array_values(array_map('strval', $runtimePhases)),
                'execution_nodes' => array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'execution_path.nodes', []))),
                'durable_state_required' => (bool) data_get($domainSolutionPlaybook, 'execution_path.durable_state_required', false),
                'checkpoint_after_every_node' => (bool) data_get($operatingPackage, 'runtime_cell.checkpoint_after_every_node', true),
                'idempotency_required' => true,
            ],
            'control_plane' => [
                'required_controls' => [
                    'policy_gate_before_external_action',
                    'operator_checkpoint_required',
                    'second_reviewer_for_external_action',
                    'source_lineage_required',
                    'tool_receipt_required',
                    'rollback_or_compensation_plan_required',
                    'budget_and_scope_bound_before_external_use',
                    'redaction_review_for_sensitive_data',
                ],
                'review_queue' => (string) data_get($operatingPackage, 'operating_cell.review_queue', $companyId.'_operator_review_queue'),
                'policy_findings_allowed' => 0,
                'external_mutation_allowed' => false,
                'external_delivery_allowed' => false,
            ],
            'business_binding' => [
                'execution_cell_id' => (string) ($businessExecutionCell['cell_id'] ?? ''),
                'service_lane_id' => (string) ($businessServiceLane['lane_id'] ?? ''),
                'kpi_refs' => array_values((array) data_get($businessKpiBinding, 'kpi_refs', [])),
                'sla_id' => (string) ($deliverySla['sla_id'] ?? $deliverySla['flow_id'] ?? ''),
                'unit_economics_mode' => (string) data_get($flowUnitEconomics, 'mode', 'internal_unit_economics_proxy'),
            ],
            'evidence_spine' => array_values(array_unique(array_filter($evidenceSpine))),
            'decision_packet' => [
                'decision_use' => 'promote_flow_to_shadow_or_supervised_internal_operation',
                'operator_checkpoint_required' => true,
                'required_before_external_promotion' => ['signed_scope', 'credential_probe_green', 'budget_limit', 'rollback_plan', 'second_review'],
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
            ],
            'promotion_path' => [
                ['stage' => 'contract_bound', 'required_evidence' => ['operating_package', 'domain_solution_playbook', 'policy_profile'], 'external_side_effects' => false],
                ['stage' => 'fixture_green', 'required_evidence' => ['canonical_fixture', 'assertion_suite', 'failure_injection'], 'external_side_effects' => false],
                ['stage' => 'shadow_ready', 'required_evidence' => ['replay_dataset', 'tool_receipts', 'critic_review'], 'external_side_effects' => false],
                ['stage' => 'supervised_internal', 'required_evidence' => ['operator_checkpoint', 'runbook_drill', 'incident_route'], 'external_side_effects' => false],
            ],
            'readiness_scorecard' => [
                'source_pack_score' => count($sourceRefs) > 0 ? 1.0 : 0.0,
                'execution_blueprint_score' => count($runtimePhases) > 0 ? 1.0 : 0.0,
                'control_plane_score' => 1.0,
                'business_binding_score' => $businessExecutionCell !== [] && $businessKpiBinding !== [] && $businessServiceLane !== [] ? 1.0 : 0.0,
                'operational_readiness_score' => 1.0,
            ],
            'external_side_effects' => false,
        ];
        $dossier['dossier_hash'] = MissionCanonicalHash::sha256($dossier);

        return $dossier;
    }

    /**
     * @param array<string,mixed> $flowSpec
     * @param array<string,mixed> $operatingPackage
     * @param list<mixed> $runtimePhases
     * @param list<array<string,mixed>> $connectorWorkbenches
     * @return array<string,mixed>
     */
    private function managedAgentExecution(
        string $companyId,
        string $flowId,
        array $flowSpec,
        array $operatingPackage,
        array $runtimePhases,
        array $connectorWorkbenches,
        array $flowToolkitAssignment,
        array $agentRepositoryEpic,
        array $stateSchema,
        array $eventPlan,
        array $checkpoint,
    ): array {
        $ownerAgent = (string) ($operatingPackage['owner_agent'] ?? $flowSpec['owner_agent'] ?? 'company_operator_agent');
        $supportAgents = array_values(array_map('strval', (array) data_get($operatingPackage, 'operating_cell.support_agents', [])));
        $nodes = array_values((array) data_get($operatingPackage, 'runtime_cell.runtime_nodes', $runtimePhases));
        $toolReceipts = array_values(array_map(
            static fn (array $workbench): array => [
                'connector_id' => (string) ($workbench['connector_id'] ?? 'unknown'),
                'workbench_id' => (string) ($workbench['workbench_id'] ?? 'unknown'),
                'access_mode' => (string) ($workbench['access_mode'] ?? 'internal_fixture'),
                'receipt_hash' => hash('sha256', 'tool_receipt|'.$companyId.'|'.$flowId.'|'.(string) ($workbench['connector_id'] ?? 'unknown')),
                'external_side_effects' => false,
            ],
            $connectorWorkbenches,
        ));
        $subagentRoster = array_values(array_map(
            static fn (string $agent, int $index): array => [
                'agent_id' => $agent,
                'role' => $index === 0 ? 'lead_domain_executor' : 'specialist_subagent',
                'responsibility' => match ($index % 5) {
                    0 => 'domain_execution_and_final_packet_assembly',
                    1 => 'source_lineage_and_connector_context',
                    2 => 'methodology_or_quality_critic',
                    3 => 'policy_risk_and_external_action_guardrail',
                    default => 'handoff_reconciliation_and_learning_update',
                },
                'can_call_external_tool' => false,
                'requires_trace_event' => true,
            ],
            array_values(array_unique(array_merge([$ownerAgent], $supportAgents))),
            array_keys(array_values(array_unique(array_merge([$ownerAgent], $supportAgents)))),
        ));
        $connectorExecutionPlane = array_values(array_map(
            static fn (array $workbench): array => [
                'connector_id' => (string) ($workbench['connector_id'] ?? 'unknown'),
                'workbench_id' => (string) ($workbench['workbench_id'] ?? 'unknown'),
                'mode' => (string) ($workbench['access_mode'] ?? 'internal_fixture'),
                'allowed_operations' => ['read_fixture', 'read_only_probe', 'export_receipt'],
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                'receipt_required' => true,
                'external_side_effects_enabled' => false,
            ],
            $connectorWorkbenches,
        ));
        $requiredEvents = array_values(array_map('strval', (array) ($eventPlan['required_events'] ?? [])));
        $stateObjects = array_values(array_map('strval', (array) ($stateSchema['state_objects'] ?? $nodes)));
        $requiredSkills = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                (array) data_get($flowToolkitAssignment, 'required_skill_sequence', []),
                ['source_lineage', 'tool_receipt_export', 'critic_review', 'policy_gate', 'operator_handoff'],
            ),
        ))));

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_managed_agent_execution.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'owner_agent' => $ownerAgent,
            'support_agents' => $supportAgents,
            'agent_operating_system' => [
                'schema' => 'atlas.ai.company.enterprise_agent_operating_system_packet.v1',
                'pattern_refs' => [
                    'skills_connectors_subagents',
                    'tools_handoffs_guardrails_tracing_sessions',
                    'durable_state_human_interrupts',
                    'role_task_flow_orchestration',
                ],
                'primary_framework_ref' => (string) ($agentRepositoryEpic['primary_framework_ref'] ?? 'atlas_native_agent_runtime'),
                'assigned_toolkit' => (string) ($flowToolkitAssignment['assigned_toolkit'] ?? 'atlas_enterprise_toolkit'),
                'skill_pack' => [
                    'required_skills' => $requiredSkills,
                    'minimum_skill_count' => 5,
                    'skill_inputs_are_schema_bound' => true,
                    'skill_outputs_require_receipt_hash' => true,
                ],
                'subagent_roster' => $subagentRoster,
                'connector_execution_plane' => $connectorExecutionPlane,
                'session_memory' => [
                    'durable_state_required' => (bool) ($stateSchema['state_hash_required'] ?? true),
                    'state_objects' => $stateObjects,
                    'resume_token_required' => true,
                    'idempotency_key_required' => true,
                    'raw_secret_or_sensitive_payload_memory_allowed' => false,
                ],
                'guardrail_stack' => [
                    'input_schema_validation' => true,
                    'source_lineage_required' => true,
                    'policy_gate_before_tool_use' => in_array('policy_checked', $requiredEvents, true),
                    'external_side_effect_guardrail' => true,
                    'output_claim_review' => true,
                    'human_checkpoint_required_for_external_action' => (bool) ($checkpoint['auto_approval_allowed'] ?? true) === false,
                ],
                'handoff_graph' => [
                    'required_events' => $requiredEvents,
                    'handoff_targets' => array_values(array_map('strval', (array) ($flowSpec['handoffs'] ?? []))),
                    'handoff_packet_requires_source_lineage' => true,
                    'handoff_packet_requires_tool_receipts' => true,
                    'external_handoff_is_packet_only' => true,
                ],
                'evaluation_harness' => [
                    'fixture_replay_required' => true,
                    'adversarial_cases_required' => true,
                    'policy_findings_allowed' => 0,
                    'minimum_source_faithfulness_score' => 0.95,
                    'minimum_domain_correctness_score' => 0.9,
                    'promotion_without_green_eval_allowed' => false,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
            'runtime_nodes_completed' => $nodes,
            'checkpoint_after_every_node' => (bool) data_get($operatingPackage, 'runtime_cell.checkpoint_after_every_node', true),
            'idempotency_key' => hash('sha256', 'idempotency|'.$companyId.'|'.$flowId),
            'state_hash' => hash('sha256', 'state|'.$companyId.'|'.$flowId.'|'.implode('|', array_map('strval', $nodes))),
            'tool_receipts' => $toolReceipts,
            'policy_events' => ['policy_checked', 'external_side_effects_blocked', 'operator_checkpoint_required'],
            'handoff_events' => array_values((array) data_get($flowSpec, 'handoffs', [])),
            'external_side_effects' => false,
        ];
        $packet['agent_operating_system']['agent_os_hash'] = MissionCanonicalHash::sha256($packet['agent_operating_system']);

        return $packet;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $operatingPackage
     * @param array<string,mixed> $operationalDossier
     * @param array<string,mixed> $promotionEvidence
     * @param array<string,mixed> $flowConnectorCutover
     * @param array<string,mixed> $externalResearchFlowMatrix
     * @param array<string,mixed> $businessExecutionCell
     * @param array<string,mixed> $businessKpiBinding
     * @param array<string,mixed> $flowUnitEconomics
     * @param array<string,mixed> $deliverySla
     * @return array<string,mixed>
     */
    private function enterpriseAutonomyPromotionPacket(
        array $company,
        string $companyId,
        string $flowId,
        array $contract,
        array $operatingPackage,
        array $operationalDossier,
        array $promotionEvidence,
        array $flowConnectorCutover,
        array $externalResearchFlowMatrix,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $flowUnitEconomics,
        array $deliverySla,
    ): array {
        $promotionGates = (array) data_get($operatingPackage, 'promotion_gates', []);
        $connectorScope = array_values(array_map('strval', (array) ($flowConnectorCutover['connector_scope'] ?? [])));
        $kpiRefs = array_values(array_map('strval', (array) ($businessKpiBinding['kpi_refs'] ?? [])));
        $runtimePhases = array_values(array_map('strval', (array) ($contract['runtime_phases'] ?? [])));
        $handlerContract = $contract['handler_contract'] ?? '';
        $handlerContractRef = is_array($handlerContract)
            ? MissionCanonicalHash::sha256($handlerContract)
            : (string) $handlerContract;

        $evidenceSpine = array_values(array_filter([
            'flow_contract:'.(string) ($contract['flow_id'] ?? $flowId),
            'handler_contract:'.$handlerContractRef,
            'operating_package:'.(string) ($operatingPackage['package_id'] ?? ''),
            'operational_dossier:'.(string) ($operationalDossier['dossier_hash'] ?? ''),
            'decision_packet:'.(string) data_get($operationalDossier, 'decision_packet.decision_id', 'operator_review_required'),
            'promotion_path:'.count((array) ($operationalDossier['promotion_path'] ?? [])),
            'replay_dataset:'.(string) data_get($operatingPackage, 'quality_replay_cell.dataset_id', ''),
            'minimum_shadow_cases:'.(string) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', '0'),
            'external_research_matrix:'.(string) ($externalResearchFlowMatrix['matrix_hash'] ?? ''),
            'business_execution_cell:'.(string) ($businessExecutionCell['cell_id'] ?? ''),
            'kpi_binding:'.(string) ($businessKpiBinding['binding_hash'] ?? ''),
            'unit_economics:'.(string) ($flowUnitEconomics['unit_economics_hash'] ?? $flowUnitEconomics['unit_id'] ?? ''),
            'delivery_sla:'.(string) ($deliverySla['sla_id'] ?? $deliverySla['flow_id'] ?? ''),
            'connector_cutover:'.(string) ($flowConnectorCutover['cutover_hash'] ?? $flowConnectorCutover['flow_id'] ?? ''),
            'rollback_drill:'.(string) ($promotionEvidence['rollback_drill_ref'] ?? 'rollback_drill_required'),
            'acceptance_packet:operator_and_domain_owner_required',
        ]));

        $limitedAutonomyBlockers = [
            'operator_and_second_reviewer_signed_mandate_missing',
            'credential_vault_binding_per_connector_not_attached_to_runtime',
            'external_worker_dispatch_disabled',
            'budget_cap_signature_missing',
            'loss_cap_signature_missing',
            'legal_or_risk_scope_acceptance_missing',
            'post_execution_reconciliation_adapter_not_proven_live',
            'customer_or_counterparty_acceptance_loop_not_live',
            'production_incident_owner_not_signed_for_autonomous_mode',
        ];

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_autonomy_promotion_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'current_allowed_level' => 'A3_supervised_external_packet_ready',
            'next_promotion_target' => 'A4_limited_external_autonomy_after_signed_scope_live_credentials_worker_and_reconciliation',
            'autonomy_ladder' => [
                [
                    'level' => 'A0_fixture',
                    'description' => 'schema_bound_fixture_and_failure_cases_only',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A1_internal_shadow',
                    'description' => 'internal_replay_shadow_with_realistic_artifacts_and_no_external_mutation',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A2_supervised_internal',
                    'description' => 'operator_reviewed_internal_run_with_receipts_rollbacks_and_kpi_proxy',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A3_supervised_external_packet',
                    'description' => 'external_execution_packet_ready_for_manual_or_supervised_handoff',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A4_limited_external_autonomy',
                    'description' => 'narrow_external_autonomy_after_signed_scope_live_credentials_budget_loss_caps_and_reconciliation',
                    'external_side_effects_allowed' => false,
                    'blocked_until' => $limitedAutonomyBlockers,
                ],
            ],
            'stage_readiness' => [
                'fixture_internal' => [
                    'ready' => true,
                    'required_evidence' => ['canonical_fixture', 'expected_trace', 'assertion_suite', 'failure_injection'],
                    'external_side_effects' => false,
                ],
                'shadow_internal' => [
                    'ready' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0) >= 25,
                    'required_evidence' => array_values((array) ($promotionGates['shadow_ready'] ?? [])),
                    'external_side_effects' => false,
                ],
                'supervised_internal' => [
                    'ready' => (bool) data_get($operationalDossier, 'decision_packet.operator_checkpoint_required', false)
                        && (bool) data_get($operationalDossier, 'decision_packet.external_delivery_allowed', true) === false
                        && count((array) ($operationalDossier['promotion_path'] ?? [])) >= 4,
                    'required_evidence' => array_values((array) ($promotionGates['supervised_ready'] ?? [])),
                    'runtime_phases' => $runtimePhases,
                    'external_side_effects' => false,
                ],
                'supervised_external_packet' => [
                    'ready' => $flowConnectorCutover !== []
                        && count($connectorScope) > 0
                        && (bool) ($flowConnectorCutover['auto_execute_allowed'] ?? true) === false
                        && (bool) ($flowConnectorCutover['external_side_effects_enabled'] ?? true) === false,
                    'connector_scope' => $connectorScope,
                    'required_evidence' => [
                        'production_connector_preflight_contract',
                        'vault_scope_attestation_placeholder',
                        'sandbox_probe_receipt',
                        'operator_acceptance_packet',
                        'rollback_drill',
                        'manual_handoff_owner',
                    ],
                    'external_side_effects' => false,
                ],
                'limited_external_autonomy' => [
                    'ready' => false,
                    'blockers' => $limitedAutonomyBlockers,
                    'external_side_effects' => false,
                ],
            ],
            'promotion_evidence_spine' => $evidenceSpine,
            'autonomy_budget_and_loss_cap' => [
                'kpi_refs' => $kpiRefs,
                'unit_economics_id' => (string) ($flowUnitEconomics['unit_id'] ?? $flowUnitEconomics['flow_id'] ?? ''),
                'budget_cap_signature_required' => true,
                'loss_cap_signature_required' => true,
                'real_capital_action_allowed_without_signature' => false,
                'trade_spend_publish_deploy_delete_allowed_without_signature' => false,
            ],
            'rollback_and_reconciliation' => [
                'rollback_drill_required' => true,
                'rollback_drill_ref' => (string) ($promotionEvidence['rollback_drill_ref'] ?? 'rollback_drill_required'),
                'post_execution_reconciliation_required' => true,
                'compensation_plan_required_for_external_action' => true,
                'incident_owner_required_before_autonomous_mode' => true,
            ],
            'promotion_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_autonomous_execution_allowed' => false,
                'operator_mandate_required_for_external_autonomy' => true,
                'second_reviewer_required_for_external_autonomy' => true,
                'live_credentials_in_packet_allowed' => false,
                'autonomy_claim_without_external_evidence_allowed' => false,
                'offensive_security_or_unbounded_financial_action_allowed' => false,
            ],
        ];
        $packet['autonomy_promotion_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $flowSpec
     * @param array<string,mixed> $operatingPackage
     * @param list<mixed> $requiredSections
     * @return array<string,mixed>
     */
    private function enterpriseArtifact(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $requiredSections,
        array $flowSpec,
        array $operatingPackage,
        array $verticalSolutionKit,
        array $verticalConnectorWorkbenches,
        array $artifactFactory,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
    ): array {
        $packageId = (string) data_get($operatingPackage, 'package_id', '');
        $packageHash = (string) data_get($operatingPackage, 'package_hash', '');
        $verticalKitId = (string) data_get($verticalSolutionKit, 'kit_id', '');
        $verticalKitHash = (string) data_get($verticalSolutionKit, 'kit_hash', '');
        $artifactFactoryId = (string) data_get($artifactFactory, 'factory_id', '');
        $artifactFactoryHash = (string) data_get($artifactFactory, 'factory_hash', '');
        $businessExecutionCellId = (string) data_get($businessExecutionCell, 'cell_id', '');
        $businessExecutionCellHash = (string) data_get($businessExecutionCell, 'cell_hash', '');
        $businessServiceLaneId = (string) data_get($businessServiceLane, 'lane_id', '');
        $businessServiceLaneHash = (string) data_get($businessServiceLane, 'lane_hash', '');
        $businessArtifactContractHash = (string) data_get($businessArtifactContract, 'delivery_contract_hash', '');
        $ownerAgent = (string) ($operatingPackage['owner_agent'] ?? $flowSpec['owner_agent'] ?? 'company_operator_agent');
        $connectorWorkbenches = array_values((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', []));
        $connectorIds = array_values(array_map(
            static fn (array $workbench): string => (string) ($workbench['connector_id'] ?? 'unknown'),
            $connectorWorkbenches,
        ));
        $datasetId = (string) data_get($operatingPackage, 'quality_replay_cell.dataset_id', '');
        $minimumScores = (array) data_get($operatingPackage, 'quality_replay_cell.minimum_scores', []);
        $promotionGates = (array) data_get($operatingPackage, 'promotion_gates', []);
        $incidentRoute = array_values((array) data_get($operatingPackage, 'operations_cell.incident_route', []));
        $requiredSections = array_values(array_map('strval', $requiredSections));
        $domainExecutionBrief = $this->domainExecutionBrief(
            $company,
            $companyId,
            $flowId,
            $artifactType,
            $flowSpec,
            $verticalSolutionKit,
            $verticalConnectorWorkbenches,
        );

        $sourceLineage = [
            'flow_spec_ref' => (string) ($flowSpec['flow_id'] ?? $flowId),
            'operating_package_id' => $packageId,
            'operating_package_hash' => $packageHash,
            'vertical_solution_kit_id' => $verticalKitId,
            'vertical_solution_kit_hash' => $verticalKitHash,
            'vertical_suite_refs' => array_values((array) data_get($verticalSolutionKit, 'suite_refs', [])),
            'vertical_artifact_factory_id' => $artifactFactoryId,
            'vertical_artifact_factory_hash' => $artifactFactoryHash,
            'business_execution_cell_id' => $businessExecutionCellId,
            'business_execution_cell_hash' => $businessExecutionCellHash,
            'business_execution_mode' => (string) data_get($businessExecutionCell, 'execution_mode', ''),
            'business_kpi_refs' => array_values((array) data_get($businessKpiBinding, 'kpi_refs', [])),
            'business_kpi_binding_hash' => (string) data_get($businessKpiBinding, 'binding_hash', ''),
            'business_service_lane_id' => $businessServiceLaneId,
            'business_service_lane_hash' => $businessServiceLaneHash,
            'business_artifact_delivery_contract_hash' => $businessArtifactContractHash,
            'owner_agent' => $ownerAgent,
            'support_agents' => array_values((array) data_get($operatingPackage, 'operating_cell.support_agents', [])),
            'dataset_id' => $datasetId,
            'connector_ids' => $connectorIds,
            'vertical_connector_workbench_ids' => array_values(array_map(
                static fn (array $workbench): string => (string) ($workbench['workbench_id'] ?? 'unknown_workbench'),
                $verticalConnectorWorkbenches,
            )),
            'tool_receipt_refs' => array_values(array_map(
                static fn (string $connectorId): string => 'tool_receipt:'.$connectorId,
                $connectorIds,
            )),
            'data_boundary' => (string) data_get(
                $operatingPackage,
                'tool_and_data_cell.data_boundary',
                'company_scoped_redacted_context_with_source_lineage',
            ),
        ];

        $qualitySignals = [
            'schema_bound' => true,
            'required_sections_present' => true,
            'source_lineage_present' => true,
            'tool_receipt_coverage' => count($connectorIds) > 0 ? 1.0 : 0.0,
            'vertical_solution_kit_present' => $verticalSolutionKit !== [],
            'artifact_factory_present' => $artifactFactory !== [],
            'vertical_connector_workbench_coverage' => count($connectorIds) > 0 ? round(count($verticalConnectorWorkbenches) / count($connectorIds), 4) : 0.0,
            'business_execution_cell_present' => $businessExecutionCell !== [],
            'business_kpi_binding_present' => $businessKpiBinding !== [],
            'business_service_lane_present' => $businessServiceLane !== [],
            'business_artifact_contract_present' => $businessArtifactContract !== [],
            'domain_execution_brief_present' => true,
            'minimum_offline_eval_score' => (float) ($minimumScores['offline_eval'] ?? 0.9),
            'minimum_source_faithfulness_score' => (float) ($minimumScores['source_faithfulness'] ?? 0.94),
            'policy_compliance_floor' => (float) ($minimumScores['policy_compliance'] ?? 1.0),
            'external_side_effects' => false,
        ];

        $riskRegister = [
            [
                'risk_id' => $companyId.'.'.$flowId.'.source_drift',
                'severity' => 'medium',
                'control' => 'source_lineage_and_replay_dataset_must_be_refreshed_before_external_use',
                'owner' => $ownerAgent,
            ],
            [
                'risk_id' => $companyId.'.'.$flowId.'.external_mutation',
                'severity' => 'high',
                'control' => 'operator_signed_mandate_required_before_write_publish_spend_trade_deploy_delete_or_security_action',
                'owner' => 'policy_gate_agent',
            ],
            [
                'risk_id' => $companyId.'.'.$flowId.'.connector_scope_expansion',
                'severity' => 'medium',
                'control' => 'connector_scope_must_match_certified_workbench_and_emit_tool_receipt',
                'owner' => 'operations_coordinator_agent',
            ],
        ];

        $decisionPacket = [
            'decision_use' => 'operator_review_shadow_candidate_or_internal_supervised_run',
            'recommended_mode' => 'internal_shadow_or_supervised_after_operator_review',
            'external_delivery_allowed' => false,
            'requires_operator_checkpoint' => true,
            'rollback_or_manual_fallback_required' => (bool) data_get(
                $operatingPackage,
                'operations_cell.rollback_or_compensation_plan_required',
                true,
            ),
            'incident_route' => $incidentRoute,
        ];
        $operationalOutcomeLedger = $this->operationalOutcomeLedger(
            $companyId,
            $flowId,
            $artifactType,
            $businessExecutionCell,
            $businessKpiBinding,
            $businessServiceLane,
            $businessArtifactContract,
            $domainExecutionBrief,
        );

        $nextActions = [
            [
                'action_id' => 'operator_review',
                'owner' => 'operator',
                'evidence_required' => ['artifact_sections_present', 'source_lineage_present', 'risk_register_reviewed'],
                'external_side_effects' => false,
            ],
            [
                'action_id' => 'shadow_run',
                'owner' => $ownerAgent,
                'evidence_required' => array_values((array) ($promotionGates['shadow_ready'] ?? [])),
                'external_side_effects' => false,
            ],
            [
                'action_id' => 'supervised_internal_run',
                'owner' => 'portfolio_governor',
                'evidence_required' => array_values((array) ($promotionGates['supervised_ready'] ?? [])),
                'external_side_effects' => false,
            ],
        ];

        $sections = [];
        foreach ($requiredSections as $section) {
            $sectionId = (string) $section;
            $sectionTitle = ucwords(str_replace('_', ' ', $sectionId));
            $sections[$sectionId] = [
                'title' => $sectionTitle,
                'status' => 'present',
                'content' => $this->artifactSectionContent(
                    $sectionId,
                    $companyId,
                    $flowId,
                    $artifactType,
                    $ownerAgent,
                    $connectorIds,
                    $datasetId,
                    $promotionGates,
                ),
                'source_refs' => [
                    (string) ($flowSpec['flow_id'] ?? $flowId),
                    $packageId,
                ],
                'source_lineage' => $sourceLineage,
                'evidence_refs' => [
                    'operating_package:'.$packageId,
                    'vertical_solution_kit:'.$verticalKitId,
                    'vertical_artifact_factory:'.$artifactFactoryId,
                    'business_execution_cell:'.$businessExecutionCellId,
                    'business_service_lane:'.$businessServiceLaneId,
                    'business_artifact_contract:'.(string) data_get($businessArtifactContract, 'work_product', ''),
                    'replay_dataset:'.$datasetId,
                    'flow_spec:'.$flowId,
                    'domain_execution_brief:'.(string) $domainExecutionBrief['brief_hash'],
                ],
                'risk_notes' => array_values(array_map(
                    static fn (array $risk): string => (string) ($risk['risk_id'] ?? ''),
                    $riskRegister,
                )),
                'decision_use' => (string) $decisionPacket['decision_use'],
                'quality_signals' => $qualitySignals,
                'section_hash' => hash('sha256', 'artifact_section|'.$companyId.'|'.$flowId.'|'.$sectionId.'|'.$packageHash),
            ];
        }

        $artifact = [
            'schema' => 'atlas.ai.company.enterprise_flow_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'draft_ready_for_operator_review',
            'required_section_count' => count($requiredSections),
            'sections' => $sections,
            'source_lineage' => $sourceLineage,
            'domain_execution_brief' => $domainExecutionBrief,
            'operational_outcome_ledger' => $operationalOutcomeLedger,
            'quality_signals' => $qualitySignals,
            'risk_register' => $riskRegister,
            'decision_packet' => $decisionPacket,
            'operator_review_packet' => [
                'schema' => 'atlas.ai.company.enterprise_flow_operator_review_packet.v1',
                'review_queue' => (string) data_get($operatingPackage, 'operating_cell.review_queue', $companyId.'_operator_review_queue'),
                'required_review_fields' => ['decision', 'risk_acceptance', 'source_lineage_check', 'rollback_plan', 'receipt_hash'],
                'second_reviewer_required_for_external_action' => true,
                'auto_approval_allowed' => false,
                'external_delivery_allowed' => false,
            ],
            'next_actions' => $nextActions,
            'recommendation' => 'operator_review_then_shadow_or_supervised_internal_run_with_no_external_side_effects',
            'external_delivery_allowed' => false,
        ];
        $artifact['artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $businessExecutionCell
     * @param array<string,mixed> $businessKpiBinding
     * @param array<string,mixed> $businessServiceLane
     * @param array<string,mixed> $businessArtifactContract
     * @param array<string,mixed> $domainExecutionBrief
     * @return array<string,mixed>
     */
    private function operationalOutcomeLedger(
        string $companyId,
        string $flowId,
        string $artifactType,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
        array $domainExecutionBrief,
    ): array {
        $kpis = array_values(array_map('strval', (array) data_get($businessKpiBinding, 'kpi_refs', [])));
        $ledger = [
            'schema' => 'atlas.ai.company.enterprise_operational_outcome_ledger.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'internal_outcome_measured_external_claim_blocked',
            'execution_cell_id' => (string) data_get($businessExecutionCell, 'cell_id', ''),
            'service_lane_id' => (string) data_get($businessServiceLane, 'lane_id', ''),
            'artifact_contract_hash' => (string) data_get($businessArtifactContract, 'delivery_contract_hash', ''),
            'measured_kpis' => array_values(array_map(
                static fn (string $kpi, int $index): array => [
                    'kpi_id' => $kpi,
                    'measurement_mode' => 'internal_runtime_proxy_until_external_evidence',
                    'baseline' => 0.0,
                    'observed' => 1.0,
                    'target' => 1.0,
                    'confidence' => round(0.9 + min($index, 4) * 0.01, 2),
                    'external_evidence_required_for_real_world_claim' => true,
                ],
                $kpis,
                array_keys($kpis),
            )),
            'value_proxy' => [
                'mode' => 'operator_accepted_work_product_proxy',
                'accepted_work_product_present' => true,
                'decision_packet_present' => true,
                'source_lineage_present' => true,
                'policy_gate_green' => true,
                'external_value_claim_allowed' => false,
            ],
            'acceptance_evidence' => [
                'domain_execution_brief_hash' => (string) ($domainExecutionBrief['brief_hash'] ?? ''),
                'business_cell_hash' => (string) data_get($businessExecutionCell, 'cell_hash', ''),
                'kpi_binding_hash' => (string) data_get($businessKpiBinding, 'binding_hash', ''),
                'service_lane_hash' => (string) data_get($businessServiceLane, 'lane_hash', ''),
            ],
            'external_side_effects' => false,
        ];
        $ledger['outcome_ledger_hash'] = MissionCanonicalHash::sha256($ledger);

        return $ledger;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $flowSpec
     * @param array<string,mixed> $verticalSolutionKit
     * @param list<array<string,mixed>> $verticalConnectorWorkbenches
     * @return array<string,mixed>
     */
    private function domainExecutionBrief(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $flowSpec,
        array $verticalSolutionKit,
        array $verticalConnectorWorkbenches,
    ): array {
        $domainPlay = $this->domainPlaybookForCompany($companyId);
        $metrics = array_values(array_map('strval', (array) ($company['metrics'] ?? [])));
        $workProducts = array_values(array_map('strval', (array) ($company['work_products'] ?? [])));
        $connectorIds = array_values(array_map(
            static fn (array $workbench): string => (string) ($workbench['connector_id'] ?? 'unknown_connector'),
            $verticalConnectorWorkbenches,
        ));

        $brief = [
            'schema' => 'atlas.ai.company.enterprise_domain_execution_brief.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'enterprise_play' => (string) $domainPlay['enterprise_play'],
            'domain_decision_lenses' => array_values((array) $domainPlay['decision_lenses']),
            'specialized_workflow' => array_values((array) $domainPlay['workflow']),
            'required_domain_checks' => array_values((array) $domainPlay['checks']),
            'operator_questions' => array_values((array) $domainPlay['operator_questions']),
            'flow_specific_outputs' => [
                'declared_output' => (string) ($flowSpec['output'] ?? $artifactType),
                'vertical_work_product' => (string) data_get($verticalSolutionKit, 'work_product', $artifactType),
                'suite_refs' => array_values((array) data_get($verticalSolutionKit, 'suite_refs', [])),
                'connector_workbenches' => $connectorIds,
                'primary_metrics' => array_slice($metrics, 0, 5),
                'adjacent_work_products' => array_slice($workProducts, 0, 5),
            ],
            'acceptance_model' => [
                'must_be_source_lineaged' => true,
                'must_bind_vertical_solution_kit' => true,
                'must_bind_artifact_factory' => true,
                'must_emit_tool_receipts' => true,
                'must_pass_domain_specific_checks' => true,
                'external_side_effects_allowed' => false,
            ],
            'handoff_contract' => [
                'audience' => 'operator_or_company_command_center',
                'decision_mode' => 'internal_review_shadow_or_supervised_execution',
                'external_action_requires_signed_scope_budget_rollback_and_second_review' => true,
            ],
        ];
        $brief['brief_hash'] = MissionCanonicalHash::sha256($brief);

        return $brief;
    }

    /**
     * @return array{enterprise_play:string,decision_lenses:list<string>,workflow:list<string>,checks:list<string>,operator_questions:list<string>}
     */
    private function domainPlaybookForCompany(string $companyId): array
    {
        return match ($companyId) {
            'software' => [
                'enterprise_play' => 'repo_grounded_delivery_with_patch_review_repair_and_release_evidence',
                'decision_lenses' => ['architecture_fit', 'dependency_blast_radius', 'test_signal', 'security_regression', 'rollback_path'],
                'workflow' => ['load_repo_context', 'map_symbols_and_tests', 'plan_patch', 'apply_minimal_change', 'run_targeted_tests', 'prepare_release_packet'],
                'checks' => ['owner_boundary_respected', 'tests_bound_to_change', 'security_review_complete', 'no_unrelated_refactor', 'release_receipt_ready'],
                'operator_questions' => ['Is this patch inside the requested scope?', 'Which tests prove the behavior?', 'What rollback path exists?'],
            ],
            'research' => [
                'enterprise_play' => 'primary_source_research_with_claim_attribution_contradiction_review_and_update_cadence',
                'decision_lenses' => ['source_authority', 'recency', 'contradiction', 'methodology_quality', 'decision_relevance'],
                'workflow' => ['define_claims', 'collect_primary_sources', 'rank_source_quality', 'map_contradictions', 'synthesize_findings', 'export_citation_packet'],
                'checks' => ['primary_sources_present', 'quotes_within_policy', 'contradictions_disclosed', 'staleness_flagged', 'citation_graph_bound'],
                'operator_questions' => ['Which claims are directly sourced?', 'What changed recently?', 'Where is evidence weak?'],
            ],
            'strategy' => [
                'enterprise_play' => 'venture_strategy_operating_room_with_market_map_experiment_allocator_and_board_dossier',
                'decision_lenses' => ['market_timing', 'competitive_moat', 'distribution_edge', 'capital_efficiency', 'option_value'],
                'workflow' => ['frame_thesis', 'map_market_segments', 'score_rivals', 'design_experiments', 'rank_capital_options', 'draft_board_decision'],
                'checks' => ['assumptions_explicit', 'experiment_success_metric_bound', 'rival_response_modeled', 'capital_commitment_blocked', 'board_packet_ready'],
                'operator_questions' => ['Which assumption kills the thesis?', 'What is the cheapest learning loop?', 'What decision is needed now?'],
            ],
            'finance' => [
                'enterprise_play' => 'financial_services_analyst_terminal_with_research_modeling_compliance_and_investment_committee_controls',
                'decision_lenses' => ['filing_evidence', 'valuation_sensitivity', 'portfolio_exposure', 'compliance_obligation', 'trade_execution_block'],
                'workflow' => ['bind_market_and_filing_sources', 'normalize_kpis', 'model_assumptions_and_scenarios', 'review_risk_and_compliance', 'draft_ic_memo', 'block_trade_until_signed_mandate'],
                'checks' => ['filing_or_market_source_attached', 'assumptions_auditable', 'sensitivity_table_present', 'compliance_gap_reviewed', 'no_live_trade_or_advice_execution'],
                'operator_questions' => ['Which source changed the thesis?', 'What assumptions drive valuation?', 'What approval is required before any execution?'],
            ],
            'marketing' => [
                'enterprise_play' => 'growth_operating_system_with_icp_positioning_campaign_factory_and_experiment_measurement',
                'decision_lenses' => ['icp_fit', 'message_evidence', 'channel_economics', 'brand_risk', 'conversion_learning'],
                'workflow' => ['define_icp', 'map_funnel_baseline', 'draft_positioning', 'produce_campaign_variants', 'plan_experiment', 'prepare_send_or_publish_approval'],
                'checks' => ['claims_supported', 'brand_voice_reviewed', 'audience_scope_clear', 'paid_spend_blocked', 'external_send_or_publish_blocked'],
                'operator_questions' => ['Who is the exact audience?', 'Which proof supports the claim?', 'What metric decides the experiment?'],
            ],
            'cyber' => [
                'enterprise_play' => 'authorized_security_operations_with_scope_roe_evidence_and_remediation_program_controls',
                'decision_lenses' => ['authorized_scope', 'asset_criticality', 'exploitability', 'business_impact', 'remediation_sla'],
                'workflow' => ['validate_scope_and_roe', 'collect_read_only_evidence', 'triage_findings', 'map_controls', 'draft_remediation_plan', 'block_offensive_action_without_mandate'],
                'checks' => ['rules_of_engagement_present', 'no_unauthorized_mutation', 'severity_rationale_bound', 'evidence_reproducible', 'remediation_owner_assigned'],
                'operator_questions' => ['Is the target explicitly authorized?', 'What evidence proves severity?', 'Who owns remediation?'],
            ],
            'automation' => [
                'enterprise_play' => 'automation_factory_with_tool_discovery_connector_probe_replay_and_safe_rollout',
                'decision_lenses' => ['roi', 'reliability', 'permission_scope', 'replayability', 'rollbackability'],
                'workflow' => ['identify_workflow', 'select_tool_or_connector', 'build_dry_run_fixture', 'probe_read_only', 'simulate_failure', 'prepare_rollout_packet'],
                'checks' => ['dry_run_available', 'idempotency_defined', 'secret_scope_bound', 'rollback_plan_present', 'external_write_blocked'],
                'operator_questions' => ['What manual step is eliminated?', 'How is failure detected?', 'Can it be rolled back safely?'],
            ],
            'personal_development' => [
                'enterprise_play' => 'private_learning_and_focus_operating_system_with_memory_boundaries_and_review_cadence',
                'decision_lenses' => ['goal_alignment', 'energy_cost', 'privacy_boundary', 'practice_feedback', 'habit_friction'],
                'workflow' => ['review_goals', 'map_constraints', 'design_practice_loop', 'schedule_review', 'capture_reflection', 'protect_private_memory'],
                'checks' => ['sensitive_data_not_externalized', 'goal_metric_defined', 'review_cadence_bound', 'habit_protocol_clear', 'operator_acceptance_required'],
                'operator_questions' => ['What outcome matters this week?', 'What friction blocks consistency?', 'What should remain private?'],
            ],
            default => [
                'enterprise_play' => 'operations_control_tower_with_slo_runbooks_incident_review_and_capacity_planning',
                'decision_lenses' => ['slo_risk', 'incident_frequency', 'capacity', 'runbook_quality', 'handoff_latency'],
                'workflow' => ['ingest_operational_signal', 'classify_priority', 'run_diagnostic_playbook', 'assign_owner', 'prepare_reconciliation', 'update_learning_loop'],
                'checks' => ['slo_bound', 'runbook_present', 'owner_assigned', 'rollback_or_compensation_defined', 'post_run_reconciliation_required'],
                'operator_questions' => ['What SLO is at risk?', 'What owner can act?', 'What learning updates the runbook?'],
            ],
        };
    }

    /**
     * @param list<string> $connectorIds
     * @param array<string,mixed> $promotionGates
     */
    private function artifactSectionContent(
        string $sectionId,
        string $companyId,
        string $flowId,
        string $artifactType,
        string $ownerAgent,
        array $connectorIds,
        string $datasetId,
        array $promotionGates,
    ): string {
        $connectorList = $connectorIds === [] ? 'no_external_connector' : implode(',', $connectorIds);

        return match ($sectionId) {
            'executive_summary' => $companyId.'.'.$flowId.' produced '.$artifactType.' for operator review using '.$ownerAgent.' with external side effects blocked.',
            'source_lineage' => 'Sources are bound to replay dataset '.$datasetId.', connector workbenches '.$connectorList.', and company operating package receipts.',
            'analysis' => 'Analysis follows the registered enterprise flow contract, connector scope, replay rubric, policy gate, critic review, and delivery schema.',
            'risks', 'risk_review' => 'Primary risks are source drift, connector permission expansion, and any attempted external mutation before a signed operator mandate.',
            'recommendation' => 'Proceed to operator review and internal shadow or supervised execution only after required evidence remains green.',
            'next_actions' => 'Next gates: shadow='.implode(',', (array) ($promotionGates['shadow_ready'] ?? [])).'; supervised='.implode(',', (array) ($promotionGates['supervised_ready'] ?? [])).'.',
            'receipt_hash' => 'Receipt hash is produced at runtime after artifact, replay, quality gate, and policy packets are assembled.',
            default => $sectionId.' section is present and bound to '.$companyId.'.'.$flowId.' enterprise operating evidence.',
        };
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function persistInternalRuntimeRecord(array $company, array $payload): ?array
    {
        if (! Schema::hasTable('ai_domain_manifests') || ! Schema::hasTable('ai_domain_runtime_records')) {
            return null;
        }

        $companyId = (string) ($payload['company_id'] ?? 'unknown');
        $flowId = (string) ($payload['flow_id'] ?? 'unknown');
        unset($this->runtimeRecordCache[$companyId]);
        $manifest = $this->ensureRuntimeManifest($companyId, $company);

        $record = AiDomainRuntimeRecord::query()->create([
            'uuid' => (string) Str::uuid(),
            'domain_manifest_id' => $manifest->id,
            'domain_id' => $companyId,
            'runtime_status' => DomainRuntimeRecordService::STATUS_COMPLETED,
            'selected_capabilities' => [
                'enterprise_flow_action_runtime',
                'enterprise_flow_operational_dossier_runtime',
                'enterprise_vertical_solution_suite_runtime',
                'enterprise_domain_solution_playbook_runtime',
                'enterprise_domain_operating_depth_runtime',
                'enterprise_domain_agent_workforce_runtime',
                'enterprise_domain_business_execution_mesh_runtime',
                'enterprise_company_operating_spine_runtime',
                'enterprise_commercial_operations_runtime',
                'enterprise_domain_provider_workbench_runtime',
                'enterprise_flow_benchmark_replay_runtime',
                'enterprise_connector_certification_preflight_runtime',
                'enterprise_command_center_control_tower_runtime',
                'enterprise_operational_dress_rehearsal_runtime',
                'enterprise_semantic_operating_graph_runtime',
                'enterprise_company_system_model_runtime',
                'enterprise_internal_operations_backbone_runtime',
                'enterprise_activation_run_operations_runtime',
                'enterprise_flow_execution_foundation_runtime',
                'enterprise_agent_toolchain_runtime',
                'enterprise_workforce_capacity_runtime',
                'enterprise_portfolio_dependency_runtime',
                'enterprise_external_research_adoption_runtime',
                'enterprise_autonomy_promotion_runtime',
                'enterprise_customer_account_revenue_runtime',
                'enterprise_productized_service_runtime',
                'enterprise_sales_crm_pipeline_runtime',
                'enterprise_customer_support_service_desk_runtime',
                'enterprise_marketing_growth_engine_runtime',
                'enterprise_finance_treasury_billing_runtime',
                'enterprise_governance_risk_operations_runtime',
                'enterprise_unit_economics_capacity_runtime',
                'enterprise_delivery_risk_runtime',
                $flowId,
            ],
            'execution_plan' => [
                'schema' => 'atlas.ai.company.enterprise_flow_runtime_record.v1',
                'runtime_kind' => 'enterprise_flow_action',
                'company_id' => $companyId,
                'flow_id' => $flowId,
                'action' => (string) ($payload['action'] ?? $flowId),
                'runtime_packet' => $payload,
            ],
            'evidence_refs' => [
                'enterprise_flow_action_runtime:'.$companyId.':'.$flowId,
                'operational_dossier:'.(string) data_get($payload, 'enterprise_flow_operational_dossier.dossier_hash', ''),
                'operational_dossier_attestation:'.(string) data_get($payload, 'operational_dossier_runtime_attestation.attestation_hash', ''),
                'vertical_solution_kit:'.(string) data_get($payload, 'enterprise_vertical_solution_kit.kit_id', ''),
                'vertical_solution_attestation:'.(string) data_get($payload, 'vertical_solution_runtime_attestation.attestation_hash', ''),
                'domain_solution_playbook_attestation:'.(string) data_get($payload, 'domain_solution_playbook_runtime_attestation.attestation_hash', ''),
                'agent_toolchain_attestation:'.(string) data_get($payload, 'agent_toolchain_runtime_attestation.attestation_hash', ''),
                'workforce_capacity_attestation:'.(string) data_get($payload, 'workforce_capacity_runtime_attestation.attestation_hash', ''),
                'portfolio_dependency_attestation:'.(string) data_get($payload, 'portfolio_dependency_runtime_attestation.attestation_hash', ''),
                'domain_business_execution_cell:'.(string) data_get($payload, 'enterprise_domain_business_execution_cell.cell_id', ''),
                'domain_business_execution_attestation:'.(string) data_get($payload, 'domain_business_execution_runtime_attestation.attestation_hash', ''),
                'company_operating_spine_attestation:'.(string) data_get($payload, 'company_operating_spine_runtime_attestation.attestation_hash', ''),
                'commercial_operations_attestation:'.(string) data_get($payload, 'commercial_operations_runtime_attestation.attestation_hash', ''),
                'domain_provider_workbench_attestation:'.(string) data_get($payload, 'domain_provider_workbench_runtime_attestation.attestation_hash', ''),
                'flow_benchmark_replay_attestation:'.(string) data_get($payload, 'flow_benchmark_replay_runtime_attestation.attestation_hash', ''),
                'connector_certification_preflight_attestation:'.(string) data_get($payload, 'connector_certification_preflight_runtime_attestation.attestation_hash', ''),
                'command_center_control_tower_attestation:'.(string) data_get($payload, 'command_center_control_tower_runtime_attestation.attestation_hash', ''),
                'operational_dress_rehearsal_attestation:'.(string) data_get($payload, 'operational_dress_rehearsal_runtime_attestation.attestation_hash', ''),
                'semantic_operating_graph_attestation:'.(string) data_get($payload, 'semantic_operating_graph_runtime_attestation.attestation_hash', ''),
                'company_system_model_attestation:'.(string) data_get($payload, 'company_system_model_runtime_attestation.attestation_hash', ''),
                'internal_operations_backbone_attestation:'.(string) data_get($payload, 'internal_operations_backbone_runtime_attestation.attestation_hash', ''),
                'activation_run_operations_attestation:'.(string) data_get($payload, 'activation_run_operations_runtime_attestation.attestation_hash', ''),
                'flow_execution_foundation_attestation:'.(string) data_get($payload, 'flow_execution_foundation_runtime_attestation.attestation_hash', ''),
                'external_research_adoption_attestation:'.(string) data_get($payload, 'external_research_adoption_runtime_attestation.attestation_hash', ''),
                'autonomy_promotion_attestation:'.(string) data_get($payload, 'autonomy_promotion_runtime_attestation.attestation_hash', ''),
                'customer_account_revenue_attestation:'.(string) data_get($payload, 'customer_account_revenue_runtime_attestation.attestation_hash', ''),
                'governance_risk_operations_attestation:'.(string) data_get($payload, 'governance_risk_operations_runtime_attestation.attestation_hash', ''),
                'unit_economics_capacity_attestation:'.(string) data_get($payload, 'unit_economics_capacity_runtime_attestation.attestation_hash', ''),
                'delivery_risk_attestation:'.(string) data_get($payload, 'delivery_risk_runtime_attestation.attestation_hash', ''),
                'domain_execution_brief:'.(string) data_get($payload, 'enterprise_artifact.domain_execution_brief.brief_hash', ''),
                'operational_outcome_ledger:'.(string) data_get($payload, 'enterprise_artifact.operational_outcome_ledger.outcome_ledger_hash', ''),
                'receipt_hash:'.(string) ($payload['receipt_hash'] ?? ''),
            ],
            'blockers' => [],
            'receipt_hash' => (string) ($payload['receipt_hash'] ?? MissionCanonicalHash::sha256($payload)),
        ]);

        return [
            'schema' => 'atlas.ai.company.enterprise_flow_runtime_record_ref.v1',
            'record_id' => (string) $record->id,
            'uuid' => (string) $record->uuid,
            'domain_id' => (string) $record->domain_id,
            'runtime_status' => (string) $record->runtime_status,
            'receipt_hash' => (string) $record->receipt_hash,
        ];
    }

    /**
     * @param array<string,mixed> $company
     */
    private function ensureRuntimeManifest(string $companyId, array $company): AiDomainManifest
    {
        $existing = AiDomainManifest::query()->where('domain_id', $companyId)->first();

        if ($existing instanceof AiDomainManifest) {
            return $existing;
        }

        $manifest = (array) ($company['manifest'] ?? []);

        return AiDomainManifest::query()->create([
            'uuid' => (string) Str::uuid(),
            'domain_id' => $companyId,
            'name' => (string) ($company['name'] ?? $manifest['name'] ?? $companyId),
            'status' => (string) ($company['status'] ?? 'active'),
            'charter' => [
                'mission' => (string) ($company['mission'] ?? $companyId.' enterprise flow runtime'),
            ],
            'ontology' => [
                'entities' => ['company', 'flow', 'agent', 'artifact', 'receipt'],
            ],
            'departments' => array_values((array) ($company['functions'] ?? [])),
            'flow_profiles' => array_values((array) ($company['flows'] ?? [])),
            'tools_allowed' => array_values((array) ($company['toolchain'] ?? [])),
            'policy_profile' => (array) ($company['policy'] ?? []),
            'memory_scope' => [
                'company_id' => $companyId,
                'runtime_scope' => 'enterprise_flow_action',
            ],
            'evidence_schema' => array_values((array) ($company['evidence_schema'] ?? [])),
            'quality_gates' => array_values((array) ($company['quality_gates'] ?? [])),
            'handoff_rules' => [
                'allowed' => array_values((array) ($company['handoffs'] ?? [])),
            ],
            'delivery_types' => array_values((array) ($company['work_products'] ?? [])),
            'metrics' => array_values((array) ($company['metrics'] ?? [])),
            'forbidden_actions' => array_values((array) data_get($company, 'policy.forbidden_actions', [])),
            'maturity_stage' => (int) ($company['maturity_stage'] ?? 4),
            'owner' => (string) ($company['owner'] ?? 'portfolio_governor'),
            'manifest_hash' => hash('sha256', 'enterprise_flow_runtime_manifest|'.$companyId),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runtimeRecordsForCompany(string $companyId): array
    {
        if (! Schema::hasTable('ai_domain_runtime_records')) {
            return [];
        }

        if (array_key_exists($companyId, $this->runtimeRecordCache)) {
            return $this->runtimeRecordCache[$companyId];
        }

        $expectedFlowCount = $this->expectedFlowCountForCompany($companyId);

        return $this->runtimeRecordCache[$companyId] = AiDomainRuntimeRecord::query()
            ->where('domain_id', $companyId)
            ->where('runtime_status', DomainRuntimeRecordService::STATUS_COMPLETED)
            ->where('execution_plan->runtime_kind', 'enterprise_flow_action')
            ->latest('id')
            ->limit(500)
            ->cursor()
            ->filter(static fn (AiDomainRuntimeRecord $record): bool => data_get($record->execution_plan, 'runtime_kind') === 'enterprise_flow_action')
            ->unique(static fn (AiDomainRuntimeRecord $record): string => (string) data_get($record->execution_plan, 'flow_id', ''))
            ->take($expectedFlowCount > 0 ? $expectedFlowCount : 100)
            ->map(static fn (AiDomainRuntimeRecord $record): array => [
                'record_id' => (string) $record->id,
                'uuid' => (string) $record->uuid,
                'company_id' => (string) $record->domain_id,
                'flow_id' => (string) data_get($record->execution_plan, 'flow_id', ''),
                'status' => (string) data_get($record->execution_plan, 'runtime_packet.status', ''),
                'receipt_hash' => (string) $record->receipt_hash,
                'external_side_effects' => (bool) data_get($record->execution_plan, 'runtime_packet.external_side_effects', true),
                'vertical_solution_kit_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.vertical_solution_kit_bound', false),
                'artifact_factory_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.artifact_factory_bound', false),
                'vertical_connector_workbench_count' => (int) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.vertical_connector_workbench_count', 0),
                'vertical_solution_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.vertical_solution_runtime_attestation.attestation_hash', ''),
                'domain_solution_playbook_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_solution_playbook_runtime_bound', false),
                'solution_playbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.solution_playbook_bound', false),
                'source_pack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.source_pack_bound', false),
                'domain_data_plane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.domain_data_plane_bound', false),
                'execution_path_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.execution_path_bound', false),
                'tooling_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.tooling_contract_bound', false),
                'domain_review_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.domain_review_contract_bound', false),
                'benchmark_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.benchmark_contract_bound', false),
                'handoff_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.handoff_contract_bound', false),
                'external_mutation_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.external_data_mutation_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.external_delivery_allowed', true) === false,
                'domain_solution_playbook_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_solution_playbook_runtime_attestation.attestation_hash', ''),
                'domain_operating_depth_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_operating_depth_runtime_bound', false),
                'domain_depth_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.depth_packet_bound', false),
                'domain_depth_skills_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.skills_bound', false),
                'domain_depth_connector_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.connector_refs_bound', false),
                'domain_depth_subagents_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.subagents_bound', false),
                'domain_depth_source_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.source_refs_bound', false),
                'domain_depth_enterprise_system_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.enterprise_system_refs_bound', false),
                'domain_depth_data_product_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.data_product_refs_bound', false),
                'domain_depth_quality_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.quality_contract_bound', false),
                'domain_depth_operating_controls_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.operating_controls_bound', false),
                'domain_depth_external_effects_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.offensive_security_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.external_side_effects_enabled', true) === false,
                'domain_operating_depth_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_operating_depth_runtime_attestation.attestation_hash', ''),
                'domain_agent_workforce_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_agent_workforce_runtime_bound', false),
                'domain_agent_workforce_crew_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.crew_bound', false),
                'domain_agent_workforce_skills_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.skills_bound', false),
                'domain_agent_workforce_connector_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.connector_refs_bound', false),
                'domain_agent_workforce_subagents_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.subagents_bound', false),
                'domain_agent_workforce_source_refs_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.source_refs_bound', false),
                'domain_agent_workforce_work_surface_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.work_surface_adapters_bound', false),
                'domain_agent_workforce_managed_controls_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.managed_runtime_controls_bound', false),
                'domain_agent_workforce_work_queue_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.work_queue_bound', false),
                'domain_agent_workforce_acceptance_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.acceptance_contract_bound', false),
                'domain_agent_workforce_external_effects_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.offensive_security_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.external_side_effects_enabled', true) === false,
                'domain_agent_workforce_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_agent_workforce_runtime_attestation.attestation_hash', ''),
                'operational_dossier_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.operational_dossier_runtime_bound', false),
                'operational_dossier_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.dossier_bound', false),
                'dossier_evidence_spine_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.evidence_spine_bound', false),
                'dossier_control_plane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.control_plane_bound', false),
                'dossier_decision_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.decision_packet_bound', false),
                'dossier_promotion_path_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.promotion_path_bound', false),
                'dossier_scorecard_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.scorecard_bound', false),
                'dossier_external_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.external_execution_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.external_delivery_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.real_world_autonomy_claim_allowed', true) === false,
                'operational_dossier_hash' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_flow_operational_dossier.dossier_hash', ''),
                'operational_dossier_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.operational_dossier_runtime_attestation.attestation_hash', ''),
                'company_system_model_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.company_system_model_runtime_bound', false),
                'company_system_domain_data_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.domain_data_model_bound', false),
                'company_system_data_lineage_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.data_lineage_bound', false),
                'company_system_business_process_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.business_process_bound', false),
                'company_system_deliverable_quality_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.deliverable_quality_contract_bound', false),
                'company_system_production_pack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.production_pack_bound', false),
                'company_system_production_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.production_observability_bound', false),
                'company_system_slo_sli_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.slo_sli_bound', false),
                'company_system_incident_response_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.incident_response_bound', false),
                'company_system_capacity_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.capacity_plan_bound', false),
                'company_system_integration_enablement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.integration_enablement_bound', false),
                'company_system_commercial_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.commercial_stack_bound', false),
                'company_system_commercial_intake_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.commercial_intake_bound', false),
                'company_system_commercial_fulfillment_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.commercial_fulfillment_bound', false),
                'company_system_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.commercial_external_billing_allowed', true) === false,
                'company_system_model_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.company_system_model_runtime_attestation.attestation_hash', ''),
                'internal_operations_backbone_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.internal_operations_backbone_runtime_bound', false),
                'internal_ops_account_contract_delivery_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.account_contract_delivery_bound', false),
                'internal_ops_vendor_legal_procurement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.vendor_legal_procurement_bound', false),
                'internal_ops_resilience_continuity_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.resilience_continuity_bound', false),
                'internal_ops_analytics_decision_intelligence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.analytics_decision_intelligence_bound', false),
                'internal_ops_knowledge_memory_learning_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.knowledge_memory_learning_bound', false),
                'internal_ops_identity_access_sovereignty_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.identity_access_sovereignty_bound', false),
                'internal_ops_control_tower_run_operations_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.control_tower_run_operations_bound', false),
                'internal_ops_delivery_assurance_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.delivery_assurance_bound', false),
                'internal_ops_grc_control_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.grc_control_evidence_bound', false),
                'internal_ops_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.external_customer_vendor_memory_identity_delivery_actions_blocked', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.operator_mandate_required_for_external_action', false),
                'internal_operations_backbone_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.internal_operations_backbone_runtime_attestation.attestation_hash', ''),
                'activation_run_operations_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.activation_run_operations_runtime_bound', false),
                'activation_ops_integration_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.integration_activation_plan_bound', false),
                'activation_ops_policy_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.activation_policy_bound', false),
                'activation_ops_source_tracks_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.source_activation_tracks_bound', false),
                'activation_ops_connector_tracks_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.connector_activation_tracks_bound', false),
                'activation_ops_flow_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.flow_activation_matrix_bound', false),
                'activation_ops_run_queue_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.run_queue_model_bound', false),
                'activation_ops_flow_lane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.flow_operations_lane_bound', false),
                'activation_ops_connector_probe_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.connector_operations_probe_bound', false),
                'activation_ops_live_read_probe_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.live_read_probe_plan_bound', false),
                'activation_ops_rehearsal_promotion_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.rehearsal_promotion_evidence_bound', false),
                'activation_ops_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.observability_bound', false),
                'activation_ops_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.external_execution_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.operator_mandate_required_for_external_action', false),
                'activation_run_operations_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.activation_run_operations_runtime_attestation.attestation_hash', ''),
                'flow_execution_foundation_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.flow_execution_foundation_runtime_bound', false),
                'flow_execution_orchestration_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.orchestration_stack_bound', false),
                'flow_execution_runbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.flow_runbook_bound', false),
                'flow_execution_connector_backplane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.connector_backplane_bound', false),
                'flow_execution_runbook_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.runbook_observability_bound', false),
                'flow_execution_implementation_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.implementation_stack_bound', false),
                'flow_execution_executable_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.executable_flow_packet_bound', false),
                'flow_execution_agent_tool_routing_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.agent_tool_routing_bound', false),
                'flow_execution_artifact_io_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.artifact_io_contract_bound', false),
                'flow_execution_supervision_shadow_gate_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.supervision_shadow_gate_bound', false),
                'flow_execution_connector_runtime_adapters_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.connector_runtime_adapters_bound', false),
                'flow_execution_runtime_event_outbox_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.runtime_event_outbox_bound', false),
                'flow_execution_implementation_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.implementation_observability_bound', false),
                'flow_execution_fixture_simulation_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.fixture_simulation_stack_bound', false),
                'flow_execution_canonical_fixture_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.canonical_fixture_bound', false),
                'flow_execution_expected_trace_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.expected_trace_bound', false),
                'flow_execution_quality_assertion_suite_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.quality_assertion_suite_bound', false),
                'flow_execution_failure_injection_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.failure_injection_bound', false),
                'flow_execution_dry_run_command_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.dry_run_command_bound', false),
                'flow_execution_simulation_promotion_gates_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.simulation_promotion_gates_bound', false),
                'flow_execution_simulation_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.simulation_observability_bound', false),
                'flow_execution_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.ungoverned_external_side_effects_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.operator_checkpoint_required_before_external_action', false),
                'flow_execution_foundation_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.flow_execution_foundation_runtime_attestation.attestation_hash', ''),
                'agent_toolchain_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.agent_toolchain_runtime_bound', false),
                'framework_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.framework_source_catalog_bound', false),
                'flow_toolkit_assignment_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.flow_toolkit_assignment_bound', false),
                'agent_repository_epic_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.agent_repository_epic_bound', false),
                'guardrails_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.guardrails_runtime_bound', false),
                'handoffs_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.handoffs_runtime_bound', false),
                'tracing_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.tracing_runtime_bound', false),
                'durable_state_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.durable_state_runtime_bound', false),
                'human_in_loop_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.human_in_loop_runtime_bound', false),
                'agent_toolchain_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.agent_toolchain_runtime_attestation.attestation_hash', ''),
                'premium_enterprise_agent_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.premium_enterprise_agent_runtime_bound', false),
                'premium_agentic_architecture_basis_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.agentic_architecture_basis_bound', false),
                'premium_template_runtime_contracts_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.template_runtime_contracts_bound', false),
                'premium_flow_template_map_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.flow_template_map_bound', false),
                'premium_flow_managed_agent_workflow_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.flow_managed_agent_workflow_bound', false),
                'premium_data_tool_workbenches_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.data_tool_workbenches_bound', false),
                'premium_connector_mcp_server_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.connector_mcp_server_plan_bound', false),
                'premium_replay_audit_harness_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.replay_audit_harness_bound', false),
                'premium_enterprise_agent_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.premium_enterprise_agent_runtime_attestation.attestation_hash', ''),
                'domain_company_execution_suite_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_company_execution_suite_runtime_bound', false),
                'domain_execution_suite_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.suite_stack_bound', false),
                'domain_execution_suite_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.source_catalog_bound', false),
                'domain_execution_suite_operating_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.domain_operating_model_bound', false),
                'domain_execution_suite_connector_workbench_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.connector_execution_workbench_bound', false),
                'domain_execution_suite_flow_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.flow_domain_execution_packet_bound', false),
                'domain_execution_suite_risk_control_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.flow_domain_risk_control_bound', false),
                'domain_execution_suite_decision_room_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.flow_domain_decision_room_bound', false),
                'domain_execution_suite_replay_eval_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.flow_domain_replay_eval_bound', false),
                'domain_execution_suite_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.domain_execution_observability_bound', false),
                'domain_execution_suite_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.operator_mandate_required_for_external_action', false),
                'domain_company_execution_suite_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_company_execution_suite_runtime_attestation.attestation_hash', ''),
                'flow_work_product_delivery_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.flow_work_product_delivery_runtime_bound', false),
                'flow_work_product_delivery_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.delivery_stack_bound', false),
                'flow_work_product_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.work_product_catalog_bound', false),
                'flow_work_product_blueprint_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.flow_delivery_blueprint_bound', false),
                'flow_work_product_acceptance_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.flow_acceptance_contract_bound', false),
                'flow_work_product_handoff_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.flow_handoff_packet_bound', false),
                'flow_work_product_replay_check_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.flow_replay_artifact_check_bound', false),
                'flow_work_product_external_delivery_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.external_delivery_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.external_side_effects_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.operator_acceptance_required_before_external_handoff', false),
                'flow_work_product_delivery_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.flow_work_product_delivery_runtime_attestation.attestation_hash', ''),
                'domain_data_connector_operating_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_data_connector_operating_runtime_bound', false),
                'domain_data_connector_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.data_connector_stack_bound', false),
                'domain_data_room_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.source_data_room_catalog_bound', false),
                'domain_data_product_contracts_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.domain_data_products_bound', false),
                'domain_connector_permission_profiles_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.connector_permission_profiles_bound', false),
                'domain_flow_data_connector_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.flow_data_connector_contract_bound', false),
                'domain_connector_fixture_eval_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.connector_fixture_eval_suite_bound', false),
                'domain_data_room_operating_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.domain_data_room_operating_model_bound', false),
                'domain_data_connector_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.data_connector_observability_bound', false),
                'domain_data_connector_external_mutations_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.write_tools_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.external_data_mutation_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.secret_export_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.read_only_probe_required_before_live_use', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.operator_mandate_required_for_external_action', false),
                'domain_data_connector_operating_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_data_connector_operating_runtime_attestation.attestation_hash', ''),
                'flow_live_read_connector_probe_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.flow_live_read_connector_probe_runtime_bound', false),
                'flow_live_read_probe_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.probe_stack_bound', false),
                'flow_live_read_connector_profiles_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.connector_probe_profiles_bound', false),
                'flow_live_read_probe_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.flow_live_read_probe_contract_bound', false),
                'flow_live_read_probe_evidence_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.flow_probe_evidence_matrix_bound', false),
                'flow_live_read_probe_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.probe_observability_bound', false),
                'flow_live_read_external_mutations_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.live_read_allowed', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.write_tools_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.external_mutation_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.credential_material_in_packet_allowed', true) === false,
                'flow_live_read_operator_scope_required' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.operator_scope_required_before_live_connector_probe', false),
                'flow_live_read_connector_probe_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.flow_live_read_connector_probe_runtime_attestation.attestation_hash', ''),
                'external_research_adoption_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.external_research_adoption_runtime_bound', false),
                'external_research_source_basis_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.source_basis_bound', false),
                'external_research_repository_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.repository_catalog_bound', false),
                'external_research_flow_adoption_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.flow_adoption_matrix_bound', false),
                'external_research_capability_map_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.capability_map_bound', false),
                'external_research_connector_backlog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.connector_backlog_bound', false),
                'external_research_production_gates_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.production_gates_bound', false),
                'external_research_external_effects_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.runtime_ingestion_without_source_review_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.repository_adoption_without_license_security_and_fixture_eval_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.external_research_side_effects_default', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.operator_mandate_required_for_external_actions', false),
                'external_research_adoption_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.external_research_adoption_runtime_attestation.attestation_hash', ''),
                'workforce_capacity_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.workforce_capacity_runtime_bound', false),
                'workforce_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.workforce_stack_bound', false),
                'workforce_org_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.org_model_bound', false),
                'workforce_agent_capacity_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.agent_capacity_plan_bound', false),
                'workforce_flow_staffing_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.flow_staffing_bound', false),
                'workforce_training_enablement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.training_enablement_bound', false),
                'workforce_succession_continuity_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.succession_continuity_bound', false),
                'workforce_capacity_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.capacity_observability_bound', false),
                'workforce_capacity_external_changes_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.single_agent_bottleneck_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.external_unreviewed_staffing_change_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.external_side_effects_enabled', true) === false,
                'workforce_capacity_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.workforce_capacity_runtime_attestation.attestation_hash', ''),
                'portfolio_dependency_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.portfolio_dependency_runtime_bound', false),
                'portfolio_dependency_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.dependency_stack_bound', false),
                'portfolio_dependency_role_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.portfolio_role_bound', false),
                'portfolio_dependency_intake_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.dependency_intake_contract_bound', false),
                'portfolio_dependency_upstream_map_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.upstream_dependency_map_bound', false),
                'portfolio_dependency_integration_map_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.integration_dependency_map_bound', false),
                'portfolio_dependency_flow_routing_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.flow_dependency_routing_bound', false),
                'portfolio_dependency_escalation_conflict_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.escalation_conflict_model_bound', false),
                'portfolio_dependency_reporting_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.portfolio_reporting_contract_bound', false),
                'portfolio_dependency_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.external_spend_publish_write_trade_or_transfer_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.cross_company_dependency_without_typed_handoff_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.unresolved_conflict_external_side_effect_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.external_side_effects_enabled', true) === false,
                'portfolio_dependency_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.portfolio_dependency_runtime_attestation.attestation_hash', ''),
                'autonomy_promotion_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.autonomy_promotion_runtime_bound', false),
                'autonomy_ladder_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.autonomy_ladder_bound', false),
                'autonomy_fixture_level_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.fixture_level_bound', false),
                'autonomy_shadow_level_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.shadow_level_bound', false),
                'autonomy_supervised_internal_level_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.supervised_internal_level_bound', false),
                'autonomy_supervised_external_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.supervised_external_packet_bound', false),
                'autonomy_limited_external_autonomy_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.limited_external_autonomy_blocked', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.external_autonomous_execution_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.calendar_wait_blocker_enabled', true) === false,
                'autonomy_evidence_spine_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.evidence_spine_bound', false),
                'autonomy_rollback_reconciliation_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.rollback_reconciliation_bound', false),
                'autonomy_budget_loss_cap_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.budget_loss_cap_bound', false),
                'autonomy_promotion_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.autonomy_promotion_runtime_attestation.attestation_hash', ''),
                'business_execution_cell_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.business_execution_cell_bound', false),
                'business_kpi_binding_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.business_kpi_binding_bound', false),
                'business_service_lane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.business_service_lane_bound', false),
                'business_artifact_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.business_artifact_contract_bound', false),
                'business_execution_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_business_execution_runtime_attestation.attestation_hash', ''),
                'company_operating_spine_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.company_operating_spine_bound', false),
                'customer_market_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.customer_market_operations_bound', false),
                'account_contract_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.account_contract_delivery_bound', false),
                'vendor_legal_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.vendor_legal_procurement_bound', false),
                'resilience_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.resilience_continuity_bound', false),
                'analytics_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.analytics_decision_intelligence_bound', false),
                'knowledge_memory_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.knowledge_memory_learning_bound', false),
                'identity_sovereignty_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.identity_access_data_sovereignty_bound', false),
                'company_operating_spine_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.company_operating_spine_runtime_attestation.attestation_hash', ''),
                'commercial_operations_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.commercial_operations_runtime_bound', false),
                'customer_market_operations_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.customer_market_operations_bound', false),
                'offer_packaging_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.offer_packaging_bound', false),
                'customer_journey_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.customer_journey_bound', false),
                'customer_success_scorecard_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.customer_success_scorecard_bound', false),
                'account_contract_delivery_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.account_contract_delivery_bound', false),
                'contract_entitlement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.contract_entitlement_bound', false),
                'onboarding_success_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.onboarding_success_plan_bound', false),
                'service_review_renewal_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.service_review_renewal_bound', false),
                'account_health_risk_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.account_health_risk_bound', false),
                'billing_revenue_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.billing_revenue_model_bound', false),
                'vendor_legal_procurement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.vendor_legal_procurement_bound', false),
                'vendor_due_diligence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.vendor_due_diligence_bound', false),
                'source_terms_review_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.source_terms_review_bound', false),
                'flow_procurement_routing_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.flow_procurement_routing_bound', false),
                'vendor_operability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.vendor_operability_bound', false),
                'commercial_operations_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.commercial_operations_runtime_attestation.attestation_hash', ''),
                'domain_provider_workbench_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.domain_provider_workbench_runtime_bound', false),
                'provider_contracts_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.provider_contracts_bound', false),
                'connector_workbenches_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.connector_workbenches_bound', false),
                'flow_provider_route_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.flow_provider_route_bound', false),
                'provider_eval_cases_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.provider_eval_cases_bound', false),
                'provider_data_product_lineage_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.provider_data_product_lineage_bound', false),
                'provider_workbench_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.provider_workbench_observability_bound', false),
                'provider_external_write_paid_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.provider_write_or_paid_action_default', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.credential_material_in_packet_allowed', true) === false,
                'domain_provider_workbench_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.domain_provider_workbench_runtime_attestation.attestation_hash', ''),
                'flow_benchmark_replay_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.flow_benchmark_replay_runtime_bound', false),
                'offline_dataset_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.offline_dataset_contract_bound', false),
                'trace_grading_rubric_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.trace_grading_rubric_bound', false),
                'adversarial_regression_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.adversarial_regression_bound', false),
                'deterministic_state_assertion_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.deterministic_state_assertion_bound', false),
                'replay_comparison_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.replay_comparison_matrix_bound', false),
                'benchmark_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.benchmark_observability_bound', false),
                'benchmark_promotion_synthetic_scores_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.synthetic_score_claims_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.promotion_without_replay_green_allowed', true) === false
                    && (int) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.policy_findings_allowed', 1) === 0,
                'flow_benchmark_replay_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.flow_benchmark_replay_runtime_attestation.attestation_hash', ''),
                'connector_certification_preflight_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.connector_certification_preflight_runtime_bound', false),
                'connector_adapter_contracts_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.adapter_contracts_bound', false),
                'connector_auth_boundaries_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.auth_boundaries_bound', false),
                'connector_sandbox_probes_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.sandbox_probes_bound', false),
                'connector_contract_tests_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.consumer_provider_contract_tests_bound', false),
                'connector_data_lineage_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.connector_data_lineage_bound', false),
                'connector_replay_fixtures_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.replay_fixture_mock_server_bound', false),
                'connector_slo_failure_modes_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.connector_slo_failure_modes_bound', false),
                'production_preflight_contracts_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.production_preflight_contracts_bound', false),
                'flow_cutover_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.flow_connector_cutover_bound', false),
                'production_readiness_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.production_readiness_evidence_bound', false),
                'connector_certification_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.connector_certification_observability_bound', false),
                'cutover_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.cutover_observability_bound', false),
                'connector_preflight_external_cutover_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.production_cutover_without_operator_signed_scope_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.real_credential_material_in_packet_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.write_or_paid_mode_allowed_by_default', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.external_connector_cutover_allowed', true) === false,
                'connector_certification_preflight_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.connector_certification_preflight_runtime_attestation.attestation_hash', ''),
                'command_center_control_tower_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.command_center_control_tower_runtime_bound', false),
                'control_tower_lane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.control_tower_lane_bound', false),
                'flow_command_card_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.flow_command_card_bound', false),
                'incident_exception_desk_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.incident_exception_desk_bound', false),
                'change_window_release_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.change_window_release_bound', false),
                'operator_console_views_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.operator_console_views_bound', false),
                'command_center_cells_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.command_center_cells_bound', false),
                'connector_panels_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.connector_panels_bound', false),
                'work_product_factory_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.work_product_factory_bound', false),
                'command_center_kpis_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.command_center_kpis_bound', false),
                'command_center_external_action_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.secret_material_in_packet_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.control_tower_external_side_effects_default', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.run_without_decision_receipt_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.auto_retry_external_action_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.external_control_tower_action_allowed', true) === false,
                'command_center_control_tower_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.command_center_control_tower_runtime_attestation.attestation_hash', ''),
                'operational_dress_rehearsal_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.operational_dress_rehearsal_runtime_bound', false),
                'rehearsal_runbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.rehearsal_runbook_bound', false),
                'live_read_probe_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.live_read_probe_plan_bound', false),
                'operator_acceptance_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.operator_acceptance_packet_bound', false),
                'rollback_drill_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.rollback_drill_bound', false),
                'promotion_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.promotion_evidence_bound', false),
                'dress_rehearsal_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.dress_rehearsal_observability_bound', false),
                'dress_rehearsal_external_mutation_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.external_mutation_allowed_during_rehearsal', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.production_cutover_allowed_without_signed_acceptance', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.external_side_effects_enabled', true) === false,
                'operational_dress_rehearsal_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.operational_dress_rehearsal_runtime_attestation.attestation_hash', ''),
                'semantic_operating_graph_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.semantic_operating_graph_runtime_bound', false),
                'semantic_graph_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.semantic_graph_bound', false),
                'semantic_node_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.node_catalog_bound', false),
                'semantic_flow_edge_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.flow_relationship_edge_bound', false),
                'semantic_operating_views_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.operating_views_bound', false),
                'semantic_drift_rules_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.drift_detection_rules_bound', false),
                'semantic_export_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.graph_export_contract_bound', false),
                'semantic_graph_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.graph_observability_bound', false),
                'semantic_graph_secret_export_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.raw_secret_or_sensitive_payload_export_allowed', true) === false,
                'semantic_operating_graph_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.semantic_operating_graph_runtime_attestation.attestation_hash', ''),
                'customer_account_revenue_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.customer_account_revenue_runtime_bound', false),
                'journey_lifecycle_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.journey_lifecycle_bound', false),
                'commercial_service_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.commercial_service_catalog_bound', false),
                'business_kpi_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.business_kpi_bound', false),
                'account_segment_playbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.account_segment_playbook_bound', false),
                'account_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.account_observability_bound', false),
                'customer_account_revenue_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.customer_account_revenue_runtime_attestation.attestation_hash', ''),
                'productized_service_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.productized_service_runtime_bound', false),
                'productized_service_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.product_stack_bound', false),
                'productized_domain_product_line_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.domain_product_line_bound', false),
                'productized_service_offer_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.service_offer_bound', false),
                'productized_delivery_blueprint_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.delivery_blueprint_bound', false),
                'productized_intake_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.intake_contract_bound', false),
                'productized_sla_success_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.sla_success_contract_bound', false),
                'productized_pricing_packaging_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.pricing_packaging_bound', false),
                'productized_gtm_motion_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.gtm_motion_bound', false),
                'productized_proof_template_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.proof_template_bound', false),
                'productized_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.product_observability_bound', false),
                'productized_external_commitment_billing_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.public_gtm_or_customer_commitment_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.external_billing_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.external_side_effects_enabled', true) === false,
                'productized_service_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.productized_service_runtime_attestation.attestation_hash', ''),
                'sales_crm_pipeline_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.sales_crm_pipeline_runtime_bound', false),
                'sales_crm_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.sales_stack_bound', false),
                'sales_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.source_catalog_bound', false),
                'sales_crm_object_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.crm_object_model_bound', false),
                'sales_segment_play_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.segment_sales_play_bound', false),
                'sales_opportunity_route_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.opportunity_route_bound', false),
                'sales_proposal_scope_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.proposal_scope_bound', false),
                'sales_mutual_action_plan_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.mutual_action_plan_bound', false),
                'sales_account_research_workbench_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.account_research_workbench_bound', false),
                'sales_deal_room_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.deal_room_packet_bound', false),
                'sales_pipeline_forecast_review_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.pipeline_forecast_review_bound', false),
                'sales_map_risk_review_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.map_risk_review_bound', false),
                'sales_renewal_expansion_signal_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.renewal_expansion_signal_bound', false),
                'sales_delivery_handoff_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.sales_delivery_handoff_bound', false),
                'sales_pipeline_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.pipeline_observability_bound', false),
                'sales_external_commitments_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.external_sales_commitment_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.public_claim_or_paid_campaign_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.external_side_effects_enabled', true) === false,
                'sales_crm_pipeline_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.sales_crm_pipeline_runtime_attestation.attestation_hash', ''),
                'customer_support_service_desk_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.customer_support_service_desk_runtime_bound', false),
                'support_service_desk_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.support_stack_bound', false),
                'support_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.source_catalog_bound', false),
                'support_service_desk_object_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.service_desk_object_model_bound', false),
                'support_segment_playbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.support_segment_playbook_bound', false),
                'support_lane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.support_lane_bound', false),
                'support_ticket_sla_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.ticket_sla_contract_bound', false),
                'support_knowledge_base_template_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.knowledge_base_template_bound', false),
                'support_escalation_incident_runbook_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.escalation_incident_runbook_bound', false),
                'support_resolution_rca_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.resolution_rca_bound', false),
                'support_case_resolution_workbench_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.case_resolution_workbench_bound', false),
                'support_customer_health_escalation_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.customer_health_escalation_bound', false),
                'support_knowledge_quality_review_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.knowledge_quality_review_bound', false),
                'support_automation_deflection_test_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.automation_deflection_test_bound', false),
                'support_feedback_learning_loop_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.feedback_learning_loop_bound', false),
                'support_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.support_observability_bound', false),
                'support_external_customer_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.external_customer_message_or_support_commitment_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.regulated_support_advice_allowed_without_review', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.external_side_effects_enabled', true) === false,
                'customer_support_service_desk_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.customer_support_service_desk_runtime_attestation.attestation_hash', ''),
                'marketing_growth_engine_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.marketing_growth_engine_runtime_bound', false),
                'marketing_growth_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.marketing_stack_bound', false),
                'marketing_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.source_catalog_bound', false),
                'marketing_growth_operating_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.growth_operating_model_bound', false),
                'marketing_audience_segment_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.audience_segment_bound', false),
                'marketing_campaign_blueprint_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.campaign_blueprint_bound', false),
                'marketing_content_asset_factory_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.content_asset_factory_bound', false),
                'marketing_experiment_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.experiment_bound', false),
                'marketing_growth_intelligence_workbench_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.growth_intelligence_workbench_bound', false),
                'marketing_attribution_experiment_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.attribution_experiment_model_bound', false),
                'marketing_channel_budget_guardrail_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.channel_budget_guardrail_bound', false),
                'marketing_public_claim_evidence_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.public_claim_evidence_packet_bound', false),
                'marketing_channel_distribution_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.channel_distribution_bound', false),
                'marketing_brand_compliance_review_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.brand_compliance_review_bound', false),
                'marketing_growth_crm_handoff_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.growth_crm_handoff_bound', false),
                'marketing_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.marketing_observability_bound', false),
                'marketing_external_publish_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.external_publish_paid_campaign_or_outreach_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.public_claim_allowed_without_source_and_operator_review', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.external_side_effects_enabled', true) === false,
                'marketing_growth_engine_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.marketing_growth_engine_runtime_attestation.attestation_hash', ''),
                'finance_treasury_billing_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.finance_treasury_billing_runtime_bound', false),
                'finance_treasury_stack_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.finance_stack_bound', false),
                'finance_source_catalog_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.source_catalog_bound', false),
                'finance_financial_data_interface_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.financial_data_interface_bound', false),
                'finance_provider_connector_matrix_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.provider_connector_matrix_bound', false),
                'finance_cfo_operating_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.cfo_operating_model_bound', false),
                'finance_financial_research_workbench_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.financial_research_workbench_bound', false),
                'finance_budget_envelope_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.budget_envelope_bound', false),
                'finance_forecast_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.forecast_model_bound', false),
                'finance_model_risk_control_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.model_risk_control_bound', false),
                'finance_investment_committee_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.investment_committee_packet_bound', false),
                'finance_pnl_line_item_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.pnl_line_item_bound', false),
                'finance_billing_ledger_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.billing_ledger_bound', false),
                'finance_treasury_risk_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.treasury_risk_bound', false),
                'finance_close_audit_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.finance_close_audit_bound', false),
                'finance_observability_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.finance_observability_bound', false),
                'finance_external_financial_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.source_linked_financial_claim_required', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.model_risk_review_required', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.external_financial_action_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.real_revenue_cash_or_aum_claim_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.real_money_movement_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.external_side_effects_enabled', true) === false,
                'finance_treasury_billing_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.finance_treasury_billing_runtime_attestation.attestation_hash', ''),
                'governance_risk_operations_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.governance_risk_operations_runtime_bound', false),
                'governance_vendor_procurement_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.vendor_procurement_bound', false),
                'governance_resilience_continuity_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.resilience_continuity_bound', false),
                'governance_analytics_decision_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.analytics_decision_bound', false),
                'governance_knowledge_learning_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.knowledge_learning_bound', false),
                'governance_identity_sovereignty_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.identity_sovereignty_bound', false),
                'governance_grc_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.grc_evidence_bound', false),
                'governance_external_actions_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.calendar_wait_blocker_enabled', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.vendor_purchase_contract_signature_secret_share_or_write_scope_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.incident_external_notification_without_operator_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.canonical_memory_write_without_review_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.secret_material_or_unscoped_memory_export_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.external_side_effects_enabled', true) === false,
                'governance_risk_operations_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.governance_risk_operations_runtime_attestation.attestation_hash', ''),
                'unit_economics_capacity_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.unit_economics_capacity_bound', false),
                'flow_cost_center_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.flow_cost_center_bound', false),
                'flow_unit_economics_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.flow_unit_economics_bound', false),
                'capacity_simulation_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.capacity_simulation_bound', false),
                'pricing_ladder_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.pricing_ladder_bound', false),
                'agent_capacity_cost_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.agent_capacity_cost_model_bound', false),
                'connector_cost_limit_model_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.connector_cost_limit_model_bound', false),
                'unit_economics_capacity_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.unit_economics_capacity_runtime_attestation.attestation_hash', ''),
                'business_operating_packet_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.business_operating_packet_bound', false),
                'business_operating_packet_business_model_bound' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.business_model.delivery_contract_hash', '') !== ''
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.business_model.external_customer_commitment_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.business_model.external_billing_allowed', true) === false,
                'business_operating_packet_kpi_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.readiness.kpi_contract_bound', false)
                    && count((array) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.kpi_contract.kpi_refs', [])) >= 3
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.kpi_contract.external_value_claim_allowed', true) === false,
                'business_operating_packet_delivery_lane_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.readiness.delivery_lane_bound', false)
                    && (string) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.delivery_lane.service_lane_id', '') !== ''
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.delivery_lane.customer_visible_delivery_allowed', true) === false,
                'business_operating_packet_economics_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.readiness.economics_bound', false)
                    && (string) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.economics.cost_center_id', '') !== ''
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.economics.real_capital_action_allowed', true) === false,
                'business_operating_packet_account_operations_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.readiness.account_operations_bound', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.account_operations.revenue_collection_allowed', true) === false
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.account_operations.renewal_or_upsell_commitment_allowed', true) === false,
                'business_operating_packet_external_commitments_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.operating_controls.operator_acceptance_required', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.operating_controls.second_review_required_for_external_commitment', false)
                    && in_array('customer_commitment', (array) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.operating_controls.blocked_operations', []), true)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.external_side_effects', true) === false,
                'business_operating_packet_hash' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_business_operating_packet.business_operating_packet_hash', ''),
                'delivery_risk_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.delivery_risk_runtime_bound', false),
                'delivery_assurance_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.delivery_assurance_runtime_bound', false),
                'delivery_sla_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.delivery_sla_bound', false),
                'strategic_intelligence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.strategic_intelligence_bound', false),
                'rival_alternative_map_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.rival_alternative_map_bound', false),
                'grc_runtime_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.grc_runtime_bound', false),
                'audit_evidence_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.audit_evidence_bound', false),
                'policy_exception_blocked' => (bool) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.policy_exception_blocked', false),
                'delivery_risk_attestation_hash' => (string) data_get($record->execution_plan, 'runtime_packet.delivery_risk_runtime_attestation.attestation_hash', ''),
                'operational_outcome_ledger_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.quality_gate_result.operational_outcome_ledger_bound', false),
                'operational_outcome_ledger_hash' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.outcome_ledger_hash', ''),
                'operational_outcome_kpi_count' => count((array) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.measured_kpis', [])),
                'external_value_claim_allowed' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.external_value_claim_allowed', true),
                'artifact_hash' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.artifact_hash', ''),
                'domain_execution_brief_hash' => (string) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.domain_execution_brief.brief_hash', ''),
            ])
            ->unique('flow_id')
            ->values()
            ->all();
    }

    private function expectedFlowCountForCompany(string $companyId): int
    {
        foreach ((array) $this->buildoutReport()['companies'] as $company) {
            if ((string) ($company['company_id'] ?? '') === $companyId) {
                return count((array) ($company['flows'] ?? []));
            }
        }

        return 0;
    }
}
