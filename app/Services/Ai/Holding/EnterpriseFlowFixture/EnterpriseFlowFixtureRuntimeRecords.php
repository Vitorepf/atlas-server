<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Runtime-record persistence + read-model extracted from EnterpriseFlowFixtureActionRuntimeService
 * (GOD-DEBULK split). Owns the per-company record cache; reads the buildout report through the facade.
 */
class EnterpriseFlowFixtureRuntimeRecords
{
    /**
     * @var array<string,list<array<string,mixed>>>
     */
    private array $runtimeRecordCache = [];

    public function __construct(
        private readonly EnterpriseFlowFixtureActionRuntimeService $svc,
    ) {}

    public function clearRuntimeRecordCache(?string $companyId = null): void
    {
        if ($companyId !== null && trim($companyId) !== '') {
            unset($this->runtimeRecordCache[trim($companyId)]);

            return;
        }

        $this->runtimeRecordCache = [];
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    public function persistInternalRuntimeRecord(array $company, array $payload): ?array
    {
        if (! $this->runtimePersistenceTablesAvailable()) {
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
     * @param  array<string,mixed>  $company
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
    public function runtimeRecordsForCompany(string $companyId): array
    {
        if (! $this->runtimeRecordsTableAvailable()) {
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
                'operational_outcome_value_proxy_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.accepted_work_product_present', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.decision_packet_present', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.source_lineage_present', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.policy_gate_green', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.value_proxy.external_value_claim_allowed', true) === false,
                'operational_outcome_acceptance_contract_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.outcome_acceptance_contract.operator_acceptance_required_for_external_claim', false)
                    && (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.outcome_acceptance_contract.auto_accept_allowed', true) === false
                    && count((array) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.outcome_acceptance_contract.required_evidence', [])) >= 5,
                'operational_outcome_risk_scorecard_bound' => (bool) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.risk_adjusted_scorecard.external_commitment_risk_blocked', false)
                    && (int) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.risk_adjusted_scorecard.policy_exception_count', 1) === 0,
                'operational_outcome_next_cycle_bound' => count((array) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.next_cycle.next_actions', [])) >= 3
                    && count((array) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.next_cycle.blocked_external_actions', [])) >= 6,
                'operational_outcome_evidence_ref_count' => count(array_filter((array) data_get($record->execution_plan, 'runtime_packet.enterprise_artifact.operational_outcome_ledger.evidence_refs', []))),
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
        foreach ((array) $this->svc->buildoutReport()['companies'] as $company) {
            if ((string) ($company['company_id'] ?? '') === $companyId) {
                return count((array) ($company['flows'] ?? []));
            }
        }

        return 0;
    }

    private function runtimePersistenceTablesAvailable(): bool
    {
        return DatabaseTableAvailability::all(['ai_domain_manifests', 'ai_domain_runtime_records']);
    }

    private function runtimeRecordsTableAvailable(): bool
    {
        return DatabaseTableAvailability::has('ai_domain_runtime_records');
    }
}
