<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasEnvelope;

/**
 * *RuntimeStatus read-models extracted from EnterpriseFlowFixtureActionRuntimeService
 * (GOD-DEBULK split). Bodies verbatim; facade-owned + cross-reader calls
 * (buildoutReport/run/clearRuntimeRecordCache/runtimeStatusFor and the 4 cross-called
 * readers) route through $this->svc.
 */
class EnterpriseFlowFixtureRuntimeStatusReaders
{
    public function __construct(
        private readonly EnterpriseFlowFixtureActionRuntimeService $svc,
        private readonly EnterpriseFlowFixtureRuntimeRecords $records,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function runPortfolioInternal(?string $companyId = null): array
    {
        $this->svc->clearRuntimeRecordCache();

        $records = [];
        $companies = [];

        foreach ((array) $this->svc->buildoutReport()['companies'] as $company) {
            $currentCompanyId = (string) ($company['company_id'] ?? 'unknown');
            if ($companyId !== null && $companyId !== $currentCompanyId) {
                continue;
            }

            $companyRecords = [];
            foreach ((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []) as $action) {
                $run = $this->svc->run($currentCompanyId, (string) ($action['action'] ?? ''), false);
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
        $this->svc->clearRuntimeRecordCache();

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companySystemModelRuntimeStatus(?string $companyId = null): array
    {
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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

        foreach ((array) $this->svc->buildoutReport()['companies'] as $company) {
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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
        return $this->svc->runtimeStatusFor([
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

}
